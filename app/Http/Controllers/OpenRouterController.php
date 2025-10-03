<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Models\ChatbotMessage;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\ForexNew;
use App\Models\History;
use App\Models\TokenUsage;
use App\Models\User;
use App\Services\TokenService;
use Carbon\Carbon;

class OpenRouterController extends Controller
{

    /**
     * The token service instance.
     *
     * @var \App\Services\TokenService
     */
    protected $tokenService;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\TokenService  $tokenService
     * @return void
     */
    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Check if the OpenRouter API key is valid by making a lightweight call
     * 
     * @param string $apiKey API key to validate
     * @return array Status array with 'valid' boolean and 'message' string
     */
    protected function checkApiKeyStatus($apiKey)
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json'
                ])
                ->get('https://openrouter.ai/api/v1/models');
            
            if ($response->successful()) {
                return ['valid' => true, 'message' => 'API key is valid'];
            } else {
                $errorData = $response->json() ?: [];
                $errorMessage = isset($errorData['error']) ? $errorData['error'] : 'Unknown error';
                return ['valid' => false, 'message' => $errorMessage, 'status' => $response->status()];
            }
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => $e->getMessage(), 'exception' => true];
        }
    }
    /**
     * Check if a user has a valid subscription
     *
     * @param User|null $user
     * @return bool
     */
    protected function hasValidSubscription($user)
    {
        // If no user is authenticated, they can't have a subscription
        if (!$user) {
            return false;
        }
        
        // Get the user's subscription
        $subscription = $user->subscription;
        
        // No subscription found
        if (!$subscription) {
            return false;
        }
        
        // Check if subscription is active and not ended
        return $subscription->status === 'active' && 
               ($subscription->ends_at === null || $subscription->ends_at > now());
    }
    
    /**
     * Handle OpenRouter API errors with improved user feedback
     * 
     * @param \Illuminate\Http\Client\Response $response The HTTP response from OpenRouter
     * @param string $modelId The model ID that was requested
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleOpenRouterError($response, $modelId)
    {
        try {
            // Default error response in case anything goes wrong
            $defaultErrorResponse = [
                'error' => 'API request failed',
                'message' => 'The request to the AI service failed. Please try again later.'
            ];
            
            // Get status code with fallback
            $statusCode = 500;
            try {
                $statusCode = $response->status();
            } catch (\Exception $e) {
                \Log::error('Failed to get status code from response', ['exception' => $e->getMessage()]);
            }
            
            // Get error data with fallback
            $errorData = [];
            try {
                $errorData = $response->json() ?: [];
                if (empty($errorData)) {
                    $errorData = ['message' => 'API request failed with no error details'];
                }
            } catch (\Exception $e) {
                \Log::error('Failed to parse JSON from error response', ['exception' => $e->getMessage()]);
            }
            
            // Log detailed error information
            \Log::error('OpenRouter API request failed', [
                'status_code' => $statusCode,
                'error_data' => $errorData,
                'model_id' => $modelId
            ]);
            
            // Build error message
            $errorMessage = '';
            try {
                if (isset($errorData['error']) && is_array($errorData['error']) && isset($errorData['error']['message'])) {
                    $errorMessage = $errorData['error']['message'];
                } elseif (isset($errorData['message'])) {
                    $errorMessage = $errorData['message'];
                } elseif (isset($errorData['error']) && is_string($errorData['error'])) {
                    $errorMessage = $errorData['error'];
                } elseif (is_string($errorData)) {
                    $errorMessage = $errorData;
                }
            } catch (\Exception $e) {
                \Log::error('Failed to extract error message', ['exception' => $e->getMessage()]);
            }
            
            // Handle 404 model not found errors gracefully
            if ($statusCode === 404 && strpos($errorMessage, 'No endpoints found') !== false) {
                $fallbackModels = [
                    'openai/gpt-4o-mini',
                    'openai/gpt-3.5-turbo', 
                    'google/gemini-2.0-flash-001',
                    'anthropic/claude-3-haiku',
                    'qwen/qwen2.5-vl-72b-instruct:free'
                ];
                
                // Return standardized error with suggested models
                return response()->json([
                    'error' => 'Model not available', 
                    'message' => 'The requested AI model "' . $modelId . '" is not available through OpenRouter. Please try one of the suggested models instead.',
                    'suggested_models' => $fallbackModels
                ], 404);
            }
            
            // For all other errors, return a more informative error response
            return response()->json([
                'error' => 'API request failed', 
                'message' => $errorMessage ?: 'Unknown error occurred',
                'details' => $errorData
            ], $statusCode);
            
        } catch (\Exception $e) {
            // Absolute fallback if anything goes wrong in our error handler itself
            \Log::critical('Error in handleOpenRouterError method', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'model_id' => $modelId
            ]);
            
            return response()->json([
                'error' => 'Internal server error',
                'message' => 'An unexpected error occurred while processing your request.'
            ], 500);
        }
    }
    
    // --- Model Definitions ---
    private $availableModels = [
        [
            'id' => 'gpt-4o',
            'name' => 'GPT-4o',
            'description' => 'Advanced vision-language capabilities',
            'premium' => true,
            'creditCost' => 1.5,
            'beta' => false
        ],
        [
            'id' => 'gpt-4o-mini',
            'name' => 'GPT-4o Mini',
            'description' => 'Fast and efficient analysis',
            'premium' => true,
            'creditCost' => 1.0,
            'beta' => false
        ],
        [
            'id' => 'gpt-4-turbo',
            'name' => 'GPT-4 Turbo',
            'description' => 'Advanced reasoning and analysis',
            'premium' => true,
            'creditCost' => 1.2,
            'beta' => false
        ],
        [
            'id' => 'gpt-4-vision-preview',
            'name' => 'GPT-4 Vision',
            'description' => 'Advanced image analysis capabilities',
            'premium' => true,
            'creditCost' => 1.3,
            'beta' => false
        ],
        [
            'id' => 'gpt-3.5-turbo',
            'name' => 'GPT-3.5 Turbo',
            'description' => 'Fast and efficient text processing',
            'premium' => false,
            'creditCost' => 0.3,
            'beta' => false
        ]
    ];
    
    /**
     * Ordered list of fallback models for image analysis
     * These models are selected based on reliability, vision capabilities, and performance
     */
    private $fallbackModels = [
        'google/gemini-2.5-flash',
        'anthropic/claude-sonnet-4',
        'openai/gpt-5'
    ];
    
    /**
     * List of valid model patterns for OpenRouter
     * Used to validate model IDs before making API calls
     */
    private $validModelPatterns = [
        '/^openai\/[a-z0-9-]+$/',            // OpenAI models like openai/gpt-4
        '/^anthropic\/[a-z0-9-]+(-[0-9]+)?$/', // Anthropic models like anthropic/claude-3
        '/^google\/[a-z0-9-\.]+$/',         // Google models like google/gemini-2.5-flash
        '/^meta-llama\/[a-z0-9-]+$/',       // Meta models like meta-llama/llama-3
        '/^qwen\/[a-z0-9-\.:]+$/',          // Qwen models like qwen/qwen2.5-vl
        '/^mistral\/[a-z0-9-\.]+$/',        // Mistral models
        '/^nvidia\/[a-z0-9-\.]+$/',         // Nvidia models
        '/^deepseek\/[a-z0-9-:]+$/',         // Deepseek models
        '/^[a-zA-Z0-9-]+$/'                  // Simple models like gpt-4o (no provider prefix)
    ];

    private $modelBaseCosts = [
        'gpt-4o' => ['input' => 0.005, 'output' => 0.015],
        'gpt-4o-mini' => ['input' => 0.0025, 'output' => 0.0075],
        'o4-mini' => ['input' => 0.0025, 'output' => 0.0075], // Adding alias for o4-mini
        'gpt-4-turbo' => ['input' => 0.003, 'output' => 0.01],
        'gpt-4-vision-preview' => ['input' => 0.005, 'output' => 0.015],
        'gpt-3.5-turbo' => ['input' => 0.0005, 'output' => 0.0015]
    ];

    private $estimatedAnalysisTokens = ['input' => 1000, 'output' => 2000];
    private $estimatedChatTokens = ['input' => 500, 'output' => 1000];

    // --- Prompts (copy your full system/user prompt here) ---
    // private $singlesystemPrompt = <<<EOT
    //     You are an expert financial chart analyst. Your task is to identify key information from chart images and provide analysis in JSON format

    //     Immediately identify these two pieces of information:
    //     1. Symbol/Trading Pair: [exact trading pair visible in chart title/header]
    //     2. Timeframe: [exact timeframe visible in chart settings/header]

    //     If you cannot see either value clearly, write "Not Visible".

    //     ESSENTIAL MARKET DATA:
    //     - Current Price: [exact number]
    //     - Support Levels: [key price levels]
    //     - Resistance Levels: [key price levels]
    //     - Market_Structure: [Bullish/Bearish/Neutral/Ranging]
    //     - Volatility: [High/Medium/Low with brief quantification]

    //     TECHNICAL INDICATORS (only include visible ones):
    //     - RSI: [values, direction (Bullish/Bearish/Neutral)]
    //     - MACD: [values, direction (Bullish/Bearish/Neutral)]
    //     - Other Indicators: [brief description if visible]
    //      - Volume SMA (if volume bars/histogram present)
    //      - Moving Averages (only if numbered, e.g., MA 7, MA 25, MA 99)
    //      - Bollinger Bands
    //      - Stochastic Oscillator
    //      - Average True Range (ATR)
    //      - Relative Strength Index (RSI)
    //      - MACD (Moving Average Convergence Divergence)
    //      - Ichimoku Cloud
    //      - Fibonacci Retracement/Extension
    //      - ADX (Average Directional Index)
    //      - OBV (On Balance Volume)
    //      - Williams %R
    //      - Parabolic SAR
    //      - CCI (Commodity Channel Index)

    //     TRADING SIGNAL:
    //     - Action: [BUY/SELL/HOLD]
    //     - Entry Price: [specific level]
    //     - Stop Loss: [specific level]
    //     - Take Profit: [specific level]
    //     - Risk_Ratio: [calculated ratio]

    //     ANALYSIS CONFIDENCE:
    //     Calculate overall confidence (0-100%) based on:
    //     - Pattern Clarity (0-25%)
    //     - Technical Alignment (0-25%)
    //     - Volume Confirmation (0-25%)
    //     - Signal Reliability (0-25%)

    //     FINAL OUTPUT FORMAT:
    //     Return ONLY a valid JSON object without any additional text or explanations:

    //     {
    //         "Symbol": "string_or_null",
    //         "Timeframe": "string_or_null",
    //         "Current_Price": "float_or_null",
    //         "Key_Price_Levels": {
    //             "Support_Levels": ["float_or_null", "..."],
    //             "Resistance_Levels": ["float_or_null", "..."]
    //         },
    //         "Market_Structure": "string_or_null",
    //         "Volatility_Conditions": "string_or_null",
    //         "Price_Movement": "string_or_null",
    //         "Chart_Patterns": "string_or_null",
    //         "Key_Breakout_Breakdown_Levels": "string_or_null",
    //         "Volume_Confirmation": "string_or_null",
    //         "Trend_Strength_Assessment": "string_or_null",
    //         "INDICATORS": {
    //             "RSI_Indicator": {
    //                 "Current_Values": "string_or_null",
    //                 "Signal": "string_or_null",
    //                 "Analysis": "string_or_null"
    //             },
    //             "MACD_Indicator": {
    //                 "Current_Values": "string_or_null",
    //                 "Signal": "string_or_null",
    //                 "Analysis": "string_or_null"
    //             },
    //             "Other_Indicator": "string_or_null"
    //         },
    //         "Action": "string_or_null",
    //         "Entry_Price": "float_or_null",
    //         "Stop_Loss": "float_or_null",
    //         "Take_Profit": "float_or_null",
    //         "Risk_Ratio": "string_or_null",
    //         "Technical_Justification": "string_or_null",
    //         "Multiple_Timeframe_Context": "string_or_null",
    //         "Risk_Assessment": {
    //             "Position_Size_Calculation": "string_or_null",
    //             "Volatility_Consideration": "string_or_null",
    //             "Invalidation_Scenarios": "string_or_null",
    //             "Key_Risk_Levels": "string_or_null"
    //         },
    //         "Analysis_Confidence": {
    //             "Pattern_Clarity_Percent": "integer_0_to_25_or_null",
    //             "Technical_Alignment_Percent": "integer_0_to_25_or_null",
    //             "Volume_Confirmation_Percent": "integer_0_to_25_or_null",
    //             "Signal_Reliability_Percent": "integer_0_to_25_or_null",
    //             "Confidence_Level_Percent": "integer_0_to_100_or_null"
    //         },
            
    //     }

    //     For null values, use null (not strings). For arrays, use [] if empty
    //     EOT;

    

    // private $systemPrompt = <<<EOT
    //     You are a professional financial chart analyst. Your task is to analyze trading charts and return a well-structured JSON response.

    //     ### 1. Chart Identification (for each chart):
    //     - **Symbol/Trading Pair**: Extract exactly from chart title/header
    //     - **Timeframe**: Extract exactly from chart settings/header
    //     - If unclear, return "Not Visible"

    //     ### 2. Chart_Analysis:
    //     - Current Price
    //     - Support and Resistance Levels
    //     - Market_Structure: Write at least 50 words describing trend direction and key patterns
    //     - Technical_Justification: Write at least 50 words explaining technical reasoning
    //     - Key_Indicators: RSI, MACD, and others if visible

    //     ### 3. Multi_Timeframe_Context:
    //     - Combine all visible timeframes into a summarized market view

    //     ## CRITICAL INSTRUCTIONS:

    //     **ALWAYS analyze the chart image carefully to determine precise trading levels:**

    //     ### 1. ENTRY PRICE:
    //     - For BUY signals: Identify optimal entry levels near support or after pullbacks
    //     - For SELL signals: Identify optimal entry levels near resistance or after rallies
    //     - Use Current Price as Entry Price ONLY if no better entry level is visible

    //     ### 2. STOP LOSS:
    //     - For BUY signals: Place below nearest swing low, support level, or key moving average
    //     - For SELL signals: Place above nearest swing high, resistance level, or key moving average
    //     - Adjust based on volatility - wider stops for higher volatility

    //     ### 3. TAKE PROFIT:
    //     - For BUY signals: Target key resistance levels, previous highs, or extension levels
    //     - For SELL signals: Target key support levels, previous lows, or extension levels
    //     - Look for multiple timeframe confluence points for strongest targets

    //     ## RISK_RATIO CALCULATION - STEP BY STEP:

    //     **MANDATORY: Follow this exact calculation process for every trade analysis:**

    //     ### Step 1: Calculate Risk and Reward Amounts

    //     **For LONG (BUY) positions:**
    //     - Risk Amount = Entry Price - Stop Loss Price
    //     - Reward Amount = Take Profit Price - Entry Price

    //     CRITICAL INSTRUCTIONS:
    //     ALWAYS analyze the chart image carefully to determine precise trading levels:

    //     1. ENTRY PRICE: 
    //     - For BUY signals: Identify optimal entry levels near support or after pullbacks
    //     - For SELL signals: Identify optimal entry levels near resistance or after rallies
    //     - Use Current Price as Entry Price ONLY if no better entry level is visible

    //     2. STOP LOSS:
    //     - For BUY signals: Place below nearest swing low, support level, or key moving average
    //     - For SELL signals: Place above nearest swing high, resistance level, or key moving average
    //     - Adjust based on volatility - wider stops for higher volatility

    //     3. TAKE PROFIT:
    //     - For BUY signals: Target key resistance levels, previous highs, or extension levels
    //     - For SELL signals: Target key support levels, previous lows, or extension levels
    //     - Look for multiple timeframe confluence points for strongest targets

    //     4. TECHNICAL INDICATORS ANALYSIS

    //     **TECHNICAL INDICATORS RULES**
    //        - ONLY list indicators that are explicitly labeled in the chart
    //        - Common indicators to look for:
    //          • Volume SMA (if volume bars/histogram present)
    //          • Moving Averages (only if numbered, e.g., MA 7, MA 25, MA 99)
    //          • Bollinger Bands
    //          • Stochastic Oscillator
    //          • Average True Range (ATR)
    //          • Relative Strength Index (RSI)
    //          • MACD (Moving Average Convergence Divergence)
    //          • Ichimoku Cloud
    //          • Fibonacci Retracement/Extension
    //          • ADX (Average Directional Index)
    //          • OBV (On Balance Volume)
    //          • Williams %R
    //          • Parabolic SAR
    //          • CCI (Commodity Channel Index)
    //        - DO NOT mention or include:
    //          • RSI if not explicitly shown and labeled in the chart
    //          • MACD if not explicitly shown and labeled in the chart
    //          • Any indicators not clearly visible
    //        - If no technical indicators are visible in the chart:
    //          • COMPLETELY OMIT the entire "TECHNICAL INDICATORS" section
    //          • DO NOT include any text like "No technical indicators are visible" or similar
    //          • Skip directly to the Trading Signal section
    //        - Do not hallucinate indicator values - only report what is clearly visible in the image

    //     CALCULATING RISK_RATIO:
    //     After determining Entry, Stop Loss, and Take Profit levels from chart_analysis:

    //     1. For LONG (BUY) positions:
    //        Risk_Ratio = (Take Profit - Entry Price) / (Entry Price - Stop Loss)

    //     2. For SHORT (SELL) positions:
    //        Risk_Ratio = (Entry Price - Take Profit) / (Stop Loss - Entry Price)

    //     3. Processing steps:
    //        - Calculate using the exact prices from your analysis
    //        - Round to 2 decimal places for display
    //        - Ensure Risk_Ratio is always a positive number
    //        - If Stop Loss or Take Profit equals Entry Price, use null or "-" for Risk Ratio to avoid division by zero

    //     IMPORTANT - MINIMUM RISK/REWARD REQUIREMENT:
    //     - If the calculated Risk_Ratio is less than 2.0 (meaning less than twice reward for every unit of risk),
    //       you MUST change the Action to "HOLD" since the trade doesn't meet our minimum risk/reward criteria
    //     - Always explain in the Technical_Justification field when a potential trade was changed to HOLD due to insufficient risk/reward

    //     When "Action" is "HOLD" (either from initial analysis or due to insufficient risk/reward):

    //     If "Action" is "HOLD" (either initially analyzed as HOLD or changed to HOLD due to insufficient risk/reward ratio):
        
    //     IMPORTANT: ALWAYS provide values for Entry_Price, Stop_Loss, and Take_Profit based on your chart_analysis, 
    //     even when the action is HOLD. Calculate and include Risk_Ratio even if it's below 2.0.
        
    //     Include a field "Hold_Reason" in the response explaining why the action is HOLD
    //     (e.g., "Insufficient risk/reward ratio", "Unclear market direction", etc.)

    //     JSON OUTPUT FORMAT:
    //     YOU MUST RETURN YOUR ENTIRE RESPONSE AS A SINGLE VALID JSON OBJECT.
    //     DO NOT include any explanatory text, markdown formatting, code blocks, or any content outside the JSON structure.
    //     Your response MUST be parseable by JSON.parse() without any preprocessing.
    //     Return ONLY a valid JSON object in the following format:

    //     ```json
    //     {
    //         "Symbol": "string_or_null",
    //         "Timeframe": "string_or_null", 
    //         "Current_Price": float_or_null,
    //         "Key_Price_Levels": {
    //             "Support_Levels": ["float_or_null", "..."],
    //             "Resistance_Levels": ["float_or_null", "..."]
    //         },
    //         "Market_Structure": "string_or_null",
    //         "Volatility_Conditions": "string_or_null",
    //         "Price_Movement": "string_or_null", 
    //         "Chart_Patterns": "string_or_null",
    //         "Key_Breakout_Breakdown_Levels": "string_or_null",
    //         "Volume_Confirmation": "string_or_null",
    //         "Trend_Strength_Assessment": "string_or_null",
    //         "INDICATORS": {
    //             "RSI_Indicator": {
    //                 "Current_Values": "string_or_null",
    //                 "Signal": "string_or_null", 
    //                 "Analysis": "string_or_null"
    //             },
    //             "MACD_Indicator": {
    //                 "Current_Values": "string_or_null",
    //                 "Signal": "string_or_null",
    //                 "Analysis": "string_or_null"
    //             },
    //             "Other_Indicator": "string_or_null"
    //         },
    //         "Action": "BUY, SELL, or HOLD",
    //         "Entry_Price": float_or_dash,
    //         "Stop_Loss": float_or_dash, 
    //         "Take_Profit": float_or_dash,
    //         "Risk_Ratio": "string_or_dash",
    //         "Hold_Reason": "string_or_null", // Required when Action is HOLD
    //         "Technical_Justification": "string_or_null",
    //         "Multiple_Timeframe_Context": "string_or_null",
    //         "Risk_Assessment": {
    //             "Position_Size_Calculation": "string_or_null",
    //             "Volatility_Consideration": "string_or_null", 
    //             "Invalidation_Scenarios": "string_or_null",
    //             "Key_Risk_Levels": "string_or_null"
    //         },
    //         "Analysis_Confidence": {
    //             "Pattern_Clarity_Percent": "integer_0_to_100_or_null",
    //             "Technical_Alignment_Percent": "integer_0_to_100_or_null",
    //             "Volume_Confirmation_Percent": "integer_0_to_100_or_null",
    //             "Signal_Reliability_Percent": "integer_0_to_100_or_null",
    //             "Confidence_Level_Percent": "integer_0_to_100_or_null"
    //         },
    //         "Analysis_Confidences": [
    //             {
    //                 "Timeframe": "string_or_null",
    //                 "Pattern_Clarity_Percent": "integer_0_to_100_or_null", 
    //                 "Technical_Alignment_Percent": "integer_0_to_100_or_null",
    //                 "Volume_Confirmation_Percent": "integer_0_to_100_or_null",
    //                 "Signal_Reliability_Percent": "integer_0_to_100_or_null",
    //                 "Confidence_Level_Percent": "integer_0_to_100_or_null"
    //             }
    //         ],
    //         "Summary": string_or_null
    //     }
            
    //     ## SUMMARY FIELD INSTRUCTIONS:

    //     - Write in plain language for human readability
    //     - Include the most important findings: Symbol, Timeframe, Current Price, Action, Entry, Stop Loss, Take Profit, Risk Ratio, and a short reasoning
    //     - Support \n for line breaks so it can be easily sent to someone via chat or email  
    //     - Avoid overly technical jargon unless necessary
    //     - Keep it concise yet informative, ideally 4–8 lines

    //     ## MANDATORY VERIFICATION CHECKLIST:

    //     **Before providing your final JSON response, YOU MUST verify:**

    //     1. **Risk Calculation Check**: 
    //     - For BUY: Risk = Entry - Stop Loss
    //     - For SELL: Risk = Stop Loss - Entry

    //     2. **Reward Calculation Check**:
    //     - For BUY: Reward = Take Profit - Entry  
    //     - For SELL: Reward = Entry - Take Profit

    //     3. **Risk_Ratio Math Check**: 
    //     - Ratio Number = Reward Amount ÷ Risk Amount
    //     - Risk_Ratio = "1:" + Ratio Number (rounded to 1 decimal)

    //     4. **Minimum Threshold Check**: 
    //     - If Ratio Number < 1.5, Action MUST be "HOLD"
    //     - Include appropriate Hold_Reason

    //     5. **Price Logic Check**: 
    //     - Entry, Stop Loss, Take Profit are realistic based on chart
    //     - Stop Loss is in correct direction (below entry for BUY, above for SELL)

    //     6. **JSON Validity Check**: 
    //     - Response is valid JSON without formatting errors
    //     - All required fields are present

    //     **CRITICAL**: Double-check your risk_ratio calculation using the step-by-step process above. Many errors occur from incorrect risk/reward calculations.
    // EOT;


    //Septermber 8, 2025 Prompt Update Start    

//     private $singlesystemPrompt = <<<EOT
// You are an expert financial chart analyst. Your task is to identify key information from chart images and provide analysis in JSON format

// Immediately identify these two pieces of information:
// 1. Symbol/Trading Pair: [exact trading pair visible in chart title/header]
// 2. Timeframe: [exact timeframe visible in chart settings/header]

// If you cannot see either value clearly, write "Not Visible".

// ESSENTIAL MARKET DATA:
// - Current Price: [exact number]
// - Support Levels: [key price levels]
// - Resistance Levels: [key price levels]
// - Market_Structure: [Bullish/Bearish/Neutral/Ranging with detailed description]
// - Volatility: [High/Medium/Low with brief quantification]

// TECHNICAL INDICATORS (only include visible ones):
// - RSI: [values, direction (Bullish/Bearish/Neutral)]
// - MACD: [values, direction (Bullish/Bearish/Neutral)]
// - Other Indicators: [brief description if visible]
//  - Volume SMA (if volume bars/histogram present)
//  - Moving Averages (only if numbered, e.g., MA 7, MA 25, MA 99)
//  - Bollinger Bands
//  - Stochastic Oscillator
//  - Average True Range (ATR)
//  - Relative Strength Index (RSI)
//  - MACD (Moving Average Convergence Divergence)
//  - Ichimoku Cloud
//  - Fibonacci Retracement/Extension
//  - ADX (Average Directional Index)
//  - OBV (On Balance Volume)
//  - Williams %R
//  - Parabolic SAR
//  - CCI (Commodity Channel Index)

// CHART PATTERNS AND PRICE MOVEMENT:
// - Identify any visible chart patterns (e.g., Head and Shoulders, Double Top, Flag, etc.)
// - Describe the recent price movement and potential breakout/breakdown levels
// - Assess the strength of the current trend
// - Evaluate volume confirmation if visible

// TRADING SIGNAL:
// - Action: [BUY/SELL/HOLD]
// - Entry Price: [specific level] (For BUY/SELL → Current Price, For HOLD → suggested entry)
// - Stop Loss: [specific level]
// - Take Profit: [specific level]
// - Risk_Ratio: [calculated ratio]
// - If Action is HOLD, provide a Hold_Reason

// MULTI_TIMEFRAME_CONTEXT:
// - Provide analysis considering multiple timeframes if possible

// RISK ASSESSMENT:
// - Position Size Calculation (if visible)
// - Volatility Consideration
// - Invalidation Scenarios
// - Key Risk Levels

// ANALYSIS CONFIDENCE:
// Calculate overall confidence (0-100%) based on:
// - Pattern Clarity (0-100%)
// - Technical Alignment (0-100%)
// - Volume Confirmation (0-100%)
// - Signal Reliability (0-100%)

// FINAL OUTPUT FORMAT:
// Return ONLY a valid JSON object without any additional text or explanations:

// {
//     "Symbol": "string_or_null",
//     "Timeframe": "string_or_null",
//     "Current_Price": float_or_null,
//     "Key_Price_Levels": {
//         "Support_Levels": [float_or_null, ...],
//         "Resistance_Levels": [float_or_null, ...]
//     },
//     "Market_Structure": "string_or_null",
//     "Volatility_Conditions": "string_or_null",
//     "Price_Movement": "string_or_null",
//     "Chart_Patterns": "string_or_null",
//     "Key_Breakout_Breakdown_Levels": "string_or_null",
//     "Volume_Confirmation": "string_or_null",
//     "Trend_Strength_Assessment": "string_or_null",
//     "INDICATORS": {
//         "RSI_Indicator": {
//             "Current_Values": "string_or_null",
//             "Signal": "string_or_null",
//             "Analysis": "string_or_null"
//         },
//         "MACD_Indicator": {
//             "Current_Values": "string_or_null",
//             "Signal": "string_or_null",
//             "Analysis": "string_or_null"
//         },
//         "Other_Indicator": "string_or_null"
//     },
//     "Action": "BUY, SELL, or HOLD",
//     "Entry_Price": float_or_null,
//     "Stop_Loss": float_or_null,
//     "Take_Profit": float_or_null,
//     "Risk_Ratio": "string_or_null",
//     "Hold_Reason": "string_or_null",
//     "Technical_Justification": "string_or_null",
//     "Multiple_Timeframe_Context": "string_or_null",
//     "Risk_Assessment": {
//         "Position_Size_Calculation": "string_or_null",
//         "Volatility_Consideration": "string_or_null",
//         "Invalidation_Scenarios": "string_or_null",
//         "Key_Risk_Levels": "string_or_null"
//     },
//     "Analysis_Confidence": {
//         "Pattern_Clarity_Percent": integer_0_to_100_or_null,
//         "Technical_Alignment_Percent": integer_0_to_100_or_null,
//         "Volume_Confirmation_Percent": integer_0_to_100_or_null,
//         "Signal_Reliability_Percent": integer_0_to_100_or_null,
//         "Confidence_Level_Percent": integer_0_to_100_or_null
//     },
//     "Analysis_Confidences": [
//         {
//             "Timeframe": "string_or_null",
//             "Pattern_Clarity_Percent": integer_0_to_100_or_null,
//             "Technical_Alignment_Percent": integer_0_to_100_or_null,
//             "Volume_Confirmation_Percent": integer_0_to_100_or_null,
//             "Signal_Reliability_Percent": integer_0_to_100_or_null,
//             "Confidence_Level_Percent": integer_0_to_100_or_null
//         }
//     ],
//     "Summary": "string_or_null"
// }
// EOT;


// private $systemPrompt = <<<EOT
//     You are a professional financial chart analyst. Your task is to analyze trading charts and return a well-structured JSON response.

//     ### 1. Chart Identification (for each chart):
//     - **Symbol/Trading Pair**: Extract exactly from chart title/header
//     - **Timeframe**: Extract exactly from chart settings/header
//     - If unclear, return "Not Visible"

//     ### 2. Chart_Analysis:
//     - Current Price
//     - Support and Resistance Levels
//     - Market_Structure: Write at least 50 words describing trend direction and key patterns
//     - Technical_Justification: Write at least 50 words explaining technical reasoning
//     - Key_Indicators: RSI, MACD, and others if visible

//     ### 3. Multi_Timeframe_Context:
//     - Combine all visible timeframes into a summarized market view

//     ## CRITICAL INSTRUCTIONS:

//     **ENTRY PRICE RULES:**
//     - BUY/SELL → Entry Price must always equal the Current Price on the chart.
//     - HOLD → Entry Price may be suggested at a better future level (support/resistance) for a potential limit order idea.

//     ### 1. ENTRY PRICE:
//     - BUY/SELL: always use Current Price.
//     - HOLD: suggest an ideal entry level if market is unsuitable now.

//     ### 2. STOP LOSS:
//     - For BUY: below nearest swing low, support level, or key moving average
//     - For SELL: above nearest swing high, resistance level, or key moving average
//     - For HOLD: suggest Stop Loss relative to the better future entry idea
//     - Adjust for volatility (wider stops in high volatility)

//     ### 3. TAKE PROFIT:
//     - For BUY: target key resistance, previous highs, or extension levels
//     - For SELL: target key support, previous lows, or extension levels
//     - For HOLD: suggest Take Profit relative to the better future entry idea

//     ## RISK_RATIO CALCULATION:
//     - BUY: (TP – Entry) ÷ (Entry – SL)
//     - SELL: (Entry – TP) ÷ (SL – Entry)
//     - HOLD: calculate using suggested levels
//     - Round to 2 decimals, always positive
//     - If R:R < 1.5 → Action = HOLD with Hold_Reason = "Insufficient risk/reward ratio"

//     ## HOLD LOGIC:
//     - HOLD must always provide potential trade levels (Entry, SL, TP) even if not tradable now
//     - Hold_Reason examples:
//       • "Insufficient risk/reward ratio"
//       • "Unclear market direction"
//       • "Wait for pullback to support"
//       • "Wait for rally to resistance"

//     ## OUTPUT REQUIREMENTS:
//     - Must always return valid JSON
//     - All fields required
//     - BUY/SELL → Entry = Current Price
//     - HOLD → Entry can be suggested at better price

//     ## SUMMARY FIELD INSTRUCTIONS:
//     - Plain language, 4–8 lines
//     - Include Symbol, Timeframe, Current Price, Action, Entry, SL, TP, Risk Ratio
//     - Clearly explain if HOLD is due to waiting for a better entry

//     ## MANDATORY CHECKLIST:
//     1. Risk math correct
//     2. Entry/SL/TP realistic
//     3. Action matches logic
//     4. HOLD includes suggested entry/SL/TP + Hold_Reason
//     5. JSON valid for parsing

//     ## OVERALL ANALYSIS (MANDATORY)

//     After analyzing all charts individually, provide an **overall analysis** that synthesizes the findings into one final trade view.  

//     ### Requirements:
//     - **Action**: BUY, SELL, or HOLD (must align with majority chart direction; if conflicting, default to HOLD and explain briefly).
//     - **Stop Loss (SL)**: Suggest a level that balances risk across timeframes, based on nearest strong support/resistance.
//     - **Take Profit (TP)**: Suggest a realistic target that reflects confluence across multiple timeframes.
//     - **Risk Ratio (R:R)**: Calculate based on the chosen overall Entry, SL, and TP. Use the same formulas as per-chart analysis, rounded to 2 decimals.


//     FINAL OUTPUT FORMAT:
//     Return ONLY a valid JSON object without any additional text or explanations:

//     {
//         "Symbol": "string_or_null",
//         "Timeframe": "string_or_null",
//         "Current_Price": float_or_null,
//         "Key_Price_Levels": {
//             "Support_Levels": [float_or_null, ...],
//             "Resistance_Levels": [float_or_null, ...]
//         },
//         "Market_Structure": "string_or_null",
//         "Volatility_Conditions": "string_or_null",
//         "Price_Movement": "string_or_null",
//         "Chart_Patterns": "string_or_null",
//         "Key_Breakout_Breakdown_Levels": "string_or_null",
//         "Volume_Confirmation": "string_or_null",
//         "Trend_Strength_Assessment": "string_or_null",
//         "INDICATORS": {
//             "RSI_Indicator": {
//                 "Current_Values": "string_or_null",
//                 "Signal": "string_or_null",
//                 "Analysis": "string_or_null"
//             },
//             "MACD_Indicator": {
//                 "Current_Values": "string_or_null",
//                 "Signal": "string_or_null",
//                 "Analysis": "string_or_null"
//             },
//             "Other_Indicator": "string_or_null"
//         },
//         "Action": "BUY, SELL, or HOLD",
//         "Entry_Price": float_or_null,
//         "Stop_Loss": float_or_null,
//         "Take_Profit": float_or_null,
//         "Risk_Ratio": "string_or_null",
//         "Hold_Reason": "string_or_null",
//         "Technical_Justification": "string_or_null",
//         "Multiple_Timeframe_Context": "string_or_null",
//         "Risk_Assessment": {
//             "Position_Size_Calculation": "string_or_null",
//             "Volatility_Consideration": "string_or_null",
//             "Invalidation_Scenarios": "string_or_null",
//             "Key_Risk_Levels": "string_or_null"
//         },
//         "Analysis_Confidence": {
//             "Pattern_Clarity_Percent": integer_0_to_100_or_null,
//             "Technical_Alignment_Percent": integer_0_to_100_or_null,
//             "Volume_Confirmation_Percent": integer_0_to_100_or_null,
//             "Signal_Reliability_Percent": integer_0_to_100_or_null,
//             "Confidence_Level_Percent": integer_0_to_100_or_null
//         },
//         "Analysis_Confidences": [
//             {
//                 "Timeframe": "string_or_null",
//                 "Pattern_Clarity_Percent": integer_0_to_100_or_null,
//                 "Technical_Alignment_Percent": integer_0_to_100_or_null,
//                 "Volume_Confirmation_Percent": integer_0_to_100_or_null,
//                 "Signal_Reliability_Percent": integer_0_to_100_or_null,
//                 "Confidence_Level_Percent": integer_0_to_100_or_null
//             }
//         ],
//         "Summary": "string_or_null"
//     }

// EOT;


    //Septermber 8, 2025 Prompt Update End   


//Septermber 15, 2025 Prompt Update Start    

private $singlesystemPrompt = <<<EOT
You are an expert financial chart analyst. Your task is to identify key information from chart images and provide analysis in JSON format

Immediately identify these two pieces of information:
1. Symbol/Trading Pair: [exact trading pair visible in chart title/header]
2. Timeframe: [exact timeframe visible in chart settings/header]

If you cannot see either value clearly, write "Not Visible".

ESSENTIAL MARKET DATA:
- Current Price: [exact number]
- Support Levels: [key price levels]
- Resistance Levels: [key price levels]
- Market_Structure: [Bullish/Bearish/Neutral/Ranging with detailed description]
- Volatility: [High/Medium/Low with brief quantification]

TECHNICAL INDICATORS (only include visible ones):
- RSI: [values, direction (Bullish/Bearish/Neutral)]
- MACD: [values, direction (Bullish/Bearish/Neutral)]
- Other Indicators: [brief description if visible]
 - Volume SMA (if volume bars/histogram present)
 - Moving Averages (only if numbered, e.g., MA 7, MA 25, MA 99)
 - Bollinger Bands
 - Stochastic Oscillator
 - Average True Range (ATR)
 - Relative Strength Index (RSI)
 - MACD (Moving Average Convergence Divergence)
 - Ichimoku Cloud
 - Fibonacci Retracement/Extension
 - ADX (Average Directional Index)
 - OBV (On Balance Volume)
 - Williams %R
 - Parabolic SAR
 - CCI (Commodity Channel Index)

CHART PATTERNS AND PRICE MOVEMENT:
- Identify any visible chart patterns (e.g., Head and Shoulders, Double Top, Flag, etc.)
- Describe the recent price movement and potential breakout/breakdown levels
- Assess the strength of the current trend
- Evaluate volume confirmation if visible

TRADING SIGNAL:
- Action: [BUY/SELL/BUY LIMIT/SELL LIMIT]
- Entry Price:
  - For BUY/SELL → must equal Current Price (market execution)
  - For BUY LIMIT/SELL LIMIT → suggested entry at better level (limit order idea)
- Stop Loss: [specific level]
- Take Profit: [specific level]
- Risk_Ratio: [calculated ratio]
- If Action is BUY LIMIT or SELL LIMIT, provide a Hold_Reason (why a limit is preferred over trading now)

MULTI_TIMEFRAME_CONTEXT:
- Provide analysis considering multiple timeframes if possible

RISK ASSESSMENT:
- Position Size Calculation (if visible)
- Volatility Consideration
- Invalidation Scenarios
- Key Risk Levels

ANALYSIS CONFIDENCE:
**Scoring Method (MANDATORY):**
1) Assign each sub-score initially on a 0–25 scale using these anchors:
   - Pattern Clarity:
     +25: clear, well-formed pattern with strong confluence at key levels
     +15: pattern present but with one notable defect (overlaps, wicks, asymmetry)
     +5: messy/range-bound structure with weak readability
     0: no discernible pattern or contradictory across timeframes
   - Technical Alignment:
     +25: ≥3 independent signals align (e.g., trend + MA slope + RSI/MACD agreement)
     +15: 2 signals align, none contradict
     +5: only 1 weak signal or minor contradiction
     0: conflicting signals (bullish and bearish simultaneously)
   - Volume Confirmation:
     +25: breakout/impulse with volume ≥ +1.5σ above 20-bar average
     +15: rising volume into the move (≈ +0.5σ to +1.5σ)
     +5: flat or declining volume
     0: volume absent/not visible (when no volume data is visible, cap this category at ≤5)
   - Signal Reliability:
     +25: R:R ≥ 2.5 with SL beyond a well-tested level or strong structural invalidation
     +15: R:R 1.8–2.4 and acceptable SL context
     +5: R:R 1.5–1.7 or mediocre SL context
     0: R:R < 1.5, noisy context, or imminent event risk
2) Convert each sub-score to a percentage by multiplying ×4, producing:
   Pattern_Clarity_Percent, Technical_Alignment_Percent, Volume_Confirmation_Percent, Signal_Reliability_Percent (each 0–100).
3) Compute the final Confidence as the arithmetic mean of those four percentages:
   Confidence_Level_Percent = round( (Pattern_Clarity_Percent + Technical_Alignment_Percent + Volume_Confirmation_Percent + Signal_Reliability_Percent) / 4 ).
4) Do not introduce any extra fields in the JSON — only fill the existing keys.

FINAL OUTPUT FORMAT:
Return ONLY a valid JSON object without any additional text or explanations:

{
    "Symbol": "string_or_null",
    "Timeframe": "string_or_null",
    "Current_Price": float_or_null,
    "Key_Price_Levels": {
        "Support_Levels": [float_or_null, ...],
        "Resistance_Levels": [float_or_null, ...]
    },
    "Market_Structure": "string_or_null",
    "Volatility_Conditions": "string_or_null",
    "Price_Movement": "string_or_null",
    "Chart_Patterns": "string_or_null",
    "Key_Breakout_Breakdown_Levels": "string_or_null",
    "Volume_Confirmation": "string_or_null",
    "Trend_Strength_Assessment": "string_or_null",
    "INDICATORS": {
        "RSI_Indicator": {
            "Current_Values": "string_or_null",
            "Signal": "string_or_null",
            "Analysis": "string_or_null"
        },
        "MACD_Indicator": {
            "Current_Values": "string_or_null",
            "Signal": "string_or_null",
            "Analysis": "string_or_null"
        },
        "Other_Indicator": "string_or_null"
    },
    "Action": "string_or_null",
    "Entry_Price": float_or_null,
    "Stop_Loss": float_or_null,
    "Take_Profit": float_or_null,
    "Risk_Ratio": "string_or_null",
    "Hold_Reason": "string_or_null",
    "Technical_Justification": "string_or_null",
    "Multiple_Timeframe_Context": "string_or_null",
    "Risk_Assessment": {
        "Position_Size_Calculation": "string_or_null",
        "Volatility_Consideration": "string_or_null",
        "Invalidation_Scenarios": "string_or_null",
        "Key_Risk_Levels": "string_or_null"
    },
    "Analysis_Confidence": {
        "Pattern_Clarity_Percent": integer_0_to_100_or_null,
        "Technical_Alignment_Percent": integer_0_to_100_or_null,
        "Volume_Confirmation_Percent": integer_0_to_100_or_null,
        "Signal_Reliability_Percent": integer_0_to_100_or_null,
        "Confidence_Level_Percent": integer_0_to_100_or_null
    },
    "Analysis_Confidences": [
        {
            "Timeframe": "string_or_null",
            "Pattern_Clarity_Percent": integer_0_to_100_or_null,
            "Technical_Alignment_Percent": integer_0_to_100_or_null,
            "Volume_Confirmation_Percent": integer_0_to_100_or_null,
            "Signal_Reliability_Percent": integer_0_to_100_or_null,
            "Confidence_Level_Percent": integer_0_to_100_or_null
        }
    ],
    "Summary": "string_or_null"
}
EOT;


private $systemPrompt = <<<EOT
    You are a professional financial chart analyst. Your task is to analyze trading charts and return a well-structured JSON response.

    ### 1. Chart Identification (for each chart):
    - **Symbol/Trading Pair**: Extract exactly from chart title/header
    - **Timeframe**: Extract exactly from chart settings/header
    - If unclear, return "Not Visible"

    ### 2. Chart_Analysis:
    - Current Price
    - Support and Resistance Levels
    - Market_Structure: Write at least 50 words describing trend direction and key patterns
    - Technical_Justification: Write at least 50 words explaining technical reasoning
    - Key_Indicators: RSI, MACD, and others if visible

    ### 3. Multi_Timeframe_Context:
    - Combine all visible timeframes into a summarized market view

    ## CRITICAL INSTRUCTIONS:

    **ENTRY PRICE RULES:**
    - BUY/SELL → Entry Price must always equal the Current Price on the chart (market execution).
    - LIMIT ORDERS (when not tradable now) → If a better entry is identified:
        • Set Action = "BUY LIMIT" when the suggested entry is BELOW Current Price (support retest).
        • Set Action = "SELL LIMIT" when the suggested entry is ABOVE Current Price (resistance retest).

    ### 1. ENTRY PRICE:
    - BUY/SELL: always use Current Price.
    - BUY LIMIT/SELL LIMIT: suggest the ideal limit entry level away from current price.

    ### 2. STOP LOSS:
    - For BUY: below nearest swing low, support level, or key moving average
    - For SELL: above nearest swing high, resistance level, or key moving average
    - For BUY LIMIT/SELL LIMIT: suggest SL relative to the proposed entry idea
    - Adjust for volatility (wider stops in high volatility)

    ### 3. TAKE PROFIT:
    - For BUY: target key resistance, previous highs, or extension levels
    - For SELL: target key support, previous lows, or extension levels
    - For BUY LIMIT/SELL LIMIT: suggest TP relative to the proposed entry idea

    ## RISK_RATIO CALCULATION:
    - BUY: (TP – Entry) ÷ (Entry – SL)
    - SELL: (Entry – TP) ÷ (SL – Entry)
    - BUY LIMIT / SELL LIMIT: calculate using the suggested levels
    - Round to 2 decimals, always positive
    - If R:R < 1.5 at current price → do not force a market trade; consider a limit idea instead
    - If a limit idea is chosen, provide Hold_Reason explaining why a limit is preferred

    ## LIMIT ORDER LOGIC (replaces previous HOLD):
    - When current price does not offer a favorable setup:
        • If better entry is BELOW current price → Action = "BUY LIMIT"
        • If better entry is ABOVE current price → Action = "SELL LIMIT"
    - Always provide Entry_Price, Stop_Loss, Take_Profit, Risk_Ratio for the limit idea.
    - Hold_Reason examples:
      • "Insufficient risk/reward at current price; waiting for pullback to support"
      • "Insufficient risk/reward at current price; waiting for rally to resistance"
      • "Unclear direction; prefer fade at key level"

    ## OUTPUT REQUIREMENTS:
    - Must always return valid JSON
    - All fields required
    - BUY/SELL → Entry = Current Price
    - BUY LIMIT/SELL LIMIT → Entry = suggested better level
    - Keep the JSON keys exactly as specified (no extra fields)

    ## SUMMARY FIELD INSTRUCTIONS:
    - Plain language, 4–8 lines
    - Include Symbol, Timeframe, Current Price, Action, Entry, SL, TP, Risk Ratio
    - If Action is BUY LIMIT/SELL LIMIT, clearly state the rationale (Hold_Reason)

    ## MANDATORY CHECKLIST:
    1. Risk math correct
    2. Entry/SL/TP realistic
    3. Action matches logic (market vs limit)
    4. BUY LIMIT/SELL LIMIT includes Entry/SL/TP + Hold_Reason
    5. JSON valid for parsing

    ## ANALYSIS CONFIDENCE (MANDATORY SCORING METHOD)
    Apply the same scoring rubric and calculation as specified in the singlesystemPrompt:
    1) Score each of Pattern Clarity, Technical Alignment, Volume Confirmation, and Signal Reliability on a 0–25 scale using the same anchors.
    2) Convert each to a 0–100 percentage by multiplying ×4 and populate the corresponding *_Percent fields.
    3) Set Confidence_Level_Percent = round( (Pattern_Clarity_Percent + Technical_Alignment_Percent + Volume_Confirmation_Percent + Signal_Reliability_Percent) / 4 ).
    4) Do not introduce any extra fields beyond the existing JSON schema.

    ## OVERALL ANALYSIS (MANDATORY)

    After analyzing all charts individually, provide an **overall analysis** that synthesizes the findings into one final trade view.  

    ### Requirements:
    - **Action**: BUY, SELL, BUY LIMIT, or SELL LIMIT (align with majority evidence; if conflicting, prefer a limit idea and explain briefly).
    - **Stop Loss (SL)**: Suggest a level that balances risk across timeframes, based on nearest strong support/resistance.
    - **Take Profit (TP)**: Suggest a realistic target that reflects confluence across multiple timeframes.
    - **Risk Ratio (R:R)**: Calculate based on the chosen overall Entry, SL, and TP. Use the same formulas as per-chart analysis, rounded to 2 decimals.


    FINAL OUTPUT FORMAT:
    Return ONLY a valid JSON object without any additional text or explanations:

    {
        "Symbol": "string_or_null",
        "Timeframe": "string_or_null",
        "Current_Price": float_or_null,
        "Key_Price_Levels": {
            "Support_Levels": [float_or_null, ...],
            "Resistance_Levels": [float_or_null, ...]
        },
        "Market_Structure": "string_or_null",
        "Volatility_Conditions": "string_or_null",
        "Price_Movement": "string_or_null",
        "Chart_Patterns": "string_or_null",
        "Key_Breakout_Breakdown_Levels": "string_or_null",
        "Volume_Confirmation": "string_or_null",
        "Trend_Strength_Assessment": "string_or_null",
        "INDICATORS": {
            "RSI_Indicator": {
                "Current_Values": "string_or_null",
                "Signal": "string_or_null",
                "Analysis": "string_or_null"
            },
            "MACD_Indicator": {
                "Current_Values": "string_or_null",
                "Signal": "string_or_null",
                "Analysis": "string_or_null"
            },
            "Other_Indicator": "string_or_null"
        },
        "Action": "string_or_null",
        "Entry_Price": float_or_null,
        "Stop_Loss": float_or_null,
        "Take_Profit": float_or_null,
        "Risk_Ratio": "string_or_null",
        "Hold_Reason": "string_or_null",
        "Technical_Justification": "string_or_null",
        "Multiple_Timeframe_Context": "string_or_null",
        "Risk_Assessment": {
            "Position_Size_Calculation": "string_or_null",
            "Volatility_Consideration": "string_or_null",
            "Invalidation_Scenarios": "string_or_null",
            "Key_Risk_Levels": "string_or_null"
        },
        "Analysis_Confidence": {
            "Pattern_Clarity_Percent": integer_0_to_100_or_null,
            "Technical_Alignment_Percent": integer_0_to_100_or_null,
            "Volume_Confirmation_Percent": integer_0_to_100_or_null,
            "Signal_Reliability_Percent": integer_0_to_100_or_null,
            "Confidence_Level_Percent": integer_0_to_100_or_null
        },
        "Analysis_Confidences": [
            {
                "Timeframe": "string_or_null",
                "Pattern_Clarity_Percent": integer_0_to_100_or_null,
                "Technical_Alignment_Percent": integer_0_to_100_or_null,
                "Volume_Confirmation_Percent": integer_0_to_100_or_null,
                "Signal_Reliability_Percent": integer_0_to_100_or_null,
                "Confidence_Level_Percent": integer_0_to_100_or_null
            }
        ],
        "Summary": "string_or_null"
    }

EOT;

private $chsystemPrompt = <<<EOT
    你是一名专业的金融图表分析师。  
    你的任务是分析交易图表并返回一个**结构化的 JSON 响应**。

    -------------------------------------------------
    ### 1. 图表识别（每张图表）
    - **Symbol/Trading Pair（交易对/品种）**：从图表标题或页眉中精确提取。  
    - **Timeframe（时间周期）**：从图表设置或页眉中精确提取。  
    - 如果无法识别，则返回 `"Not Visible"`。

    -------------------------------------------------
    ### 2. Chart_Analysis（图表分析）
    - **Current Price（当前价格）**  
    - **Support and Resistance Levels（支撑和阻力水平）**  
    - **Market_Structure（市场结构）**：至少写 50 个字，描述趋势方向和关键形态。  
    - **Technical_Justification（技术依据）**：至少写 50 个字，解释技术逻辑。  
    - **Key_Indicators（关键指标）**：RSI、MACD 以及其他可见指标。  

    -------------------------------------------------
    ### 3. Multi_Timeframe_Context（多周期背景）
    - 综合所有可见的周期，总结市场观点。

    -------------------------------------------------
## 关键指令（CRITICAL INSTRUCTIONS）

**入场价格规则（ENTRY PRICE RULES）：**  
- BUY/SELL → 入场价格必须始终等于图表上的当前价格（市价单）。  
- LIMIT ORDERS（不可立即交易时）：  
  • 如果理想入场在当前价格下方 → Action = `"BUY LIMIT"`。  
  • 如果理想入场在当前价格上方 → Action = `"SELL LIMIT"`。

-------------------------------------------------
### 入场价格（Entry Price）
- BUY/SELL：始终使用当前价格。  
- BUY LIMIT/SELL LIMIT：建议一个更合适的挂单价格。  

### 止损（Stop Loss）
- BUY：放在最近的低点、支撑位或均线下方。  
- SELL：放在最近的高点、阻力位或均线上方。  
- BUY LIMIT/SELL LIMIT：根据建议的挂单价设定止损。  
- 遇到高波动时应放宽止损。  

### 止盈（Take Profit）
- BUY：目标为阻力、前高或延展位。  
- SELL：目标为支撑、前低或延展位。  
- BUY LIMIT/SELL LIMIT：建议相对合理的止盈位。  

-------------------------------------------------
## 风险回报比计算（Risk Ratio Calculation）
- BUY: (TP – Entry) ÷ (Entry – SL)  
- SELL: (Entry – TP) ÷ (SL – Entry)  
- LIMIT ORDERS：使用建议的价位计算  
- 保留两位小数，结果必须为正数  
- 如果 R:R < 1.5 → 不要强行市价单，应考虑 LIMIT 策略  
- 若选择 LIMIT → 必须给出 **Hold_Reason（持仓理由）**

-------------------------------------------------
## Limit Order 逻辑（取代 HOLD）
- 如果当前价格不具备良好交易条件：  
  • 低于当前价 → `"BUY LIMIT"`  
  • 高于当前价 → `"SELL LIMIT"`  
- 必须提供 Entry、SL、TP、Risk Ratio 和 Hold_Reason。  

Hold_Reason 示例：  
- "当前价格风险回报不足，等待回调至支撑位。"  
- "当前价格风险回报不足，等待反弹至阻力位。"  
- "方向不明确，等待关键水平反向操作。"  

-------------------------------------------------
## 输出要求（Output Requirements）
- 必须返回 **有效的 JSON**  
- 所有字段必填  
- JSON key 必须与 schema 一致（不能添加额外字段）  

-------------------------------------------------
## 总结字段（Summary）说明
- 使用简洁自然的语言，4–8 行。  
- 包含：Symbol、Timeframe、Current Price、Action、Entry、SL、TP、Risk Ratio。  
- 如果 Action 是 LIMIT，必须清楚说明原因（Hold_Reason）。  

-------------------------------------------------
## 必须检查清单（Mandatory Checklist）
1. 风险回报计算正确  
2. Entry/SL/TP 合理  
3. Action 与逻辑一致  
4. LIMIT 订单必须包含 Entry/SL/TP + Hold_Reason  
5. JSON 可被正常解析  

-------------------------------------------------
## 分析信心评分（Analysis Confidence）
- 与 singlesystemPrompt 使用同一评分规则  
- 对以下四项打分：Pattern Clarity、Technical Alignment、Volume Confirmation、Signal Reliability  
- 每项 0–25 分 → 转换为百分比（×4）  
- Confidence_Level_Percent = 四项平均值  
- 必须保持 JSON 字段一致  

-------------------------------------------------
## 总体分析（Overall Analysis）
- 综合所有图表，给出最终交易结论  
- 要求：  
  • Action = BUY、SELL、BUY LIMIT 或 SELL LIMIT  
  • SL = 多周期共振的强支撑/阻力附近  
  • TP = 多周期共振的合理目标  
  • Risk Ratio = 使用 Entry/SL/TP 计算，保留两位小数  

-------------------------------------------------
### 最终输出格式（Final Output Format）
只返回有效 JSON，schema 如下：

{
  "Symbol": "字符串或空值",
  "Timeframe": "字符串或空值",
  "Current_Price": "浮点数或空值"
,
  "Key_Price_Levels": {
    "Support_Levels": ["浮点数或空值"
, ...],
    "Resistance_Levels": ["浮点数或空值"
, ...]
  },
  "Market_Structure": "字符串或空值",
  "Volatility_Conditions": "字符串或空值",
  "Price_Movement": "字符串或空值",
  "Chart_Patterns": "字符串或空值",
  "Key_Breakout_Breakdown_Levels": "字符串或空值",
  "Volume_Confirmation": "字符串或空值",
  "Trend_Strength_Assessment": "字符串或空值",
  "INDICATORS": {
    "RSI_Indicator": {
      "Current_Values": "字符串或空值",
      "Signal": "字符串或空值",
      "Analysis": "字符串或空值"
    },
    "MACD_Indicator": {
      "Current_Values": "字符串或空值",
      "Signal": "字符串或空值",
      "Analysis": "字符串或空值"
    },
    "Other_Indicator": "字符串或空值"
  },
  "Action": "字符串或空值",
  "Entry_Price": "浮点数或空值"
,
  "Stop_Loss": "浮点数或空值"
,
  "Take_Profit": "浮点数或空值"
,
  "Risk_Ratio": "字符串或空值",
  "Hold_Reason": "字符串或空值",
  "Technical_Justification": "字符串或空值",
  "Multiple_Timeframe_Context": "字符串或空值",
  "Risk_Assessment": {
    "Position_Size_Calculation": "字符串或空值",
    "Volatility_Consideration": "字符串或空值",
    "Invalidation_Scenarios": "字符串或空值",
    "Key_Risk_Levels": "字符串或空值"
  },
  "Analysis_Confidence": {
    "Pattern_Clarity_Percent": "整数_0_到_100_或_空值",
    "Technical_Alignment_Percent": "整数_0_到_100_或_空值",
    "Volume_Confirmation_Percent": "整数_0_到_100_或_空值",
    "Signal_Reliability_Percent": "整数_0_到_100_或_空值",
    "Confidence_Level_Percent": "整数_0_到_100_或_空值"
  },
  "Analysis_Confidences": [
    {
      "Timeframe": "字符串或空值",
      "Pattern_Clarity_Percent": "整数_0_到_100_或_空值",
      "Technical_Alignment_Percent": "整数_0_到_100_或_空值",
      "Volume_Confirmation_Percent": "整数_0_到_100_或_空值",
      "Signal_Reliability_Percent": "整数_0_到_100_或_空值",
      "Confidence_Level_Percent": "整数_0_到_100_或_空值"
    }
  ],
  "Summary": "字符串或空值"
}

### 最终输出需以中文回复
EOT;

private $userContent = 'Please analyze this market chart and provide a comprehensive trading strategy analysis. Focus on price action, technical indicators, and potential trading opportunities. If any indicator is not clearly visible, mark it as "Not Visible". ';
private $chUserContent = '请分析此市场图表并提供全面的交易策略分析。请关注价格走势、技术指标和潜在的交易机会。如有任何指标不清晰，请标记为 "不可见"';

//Septermber 15, 2025 Prompt Update End    



    // --- Cost Calculation ---
    public function calculateCost($model, $inputTokens, $outputTokens, $usageMultiplier = 1, $profitMultiplier = 10)
    {
        // Check if we have pricing for this model
        if (!isset($this->modelBaseCosts[$model])) {
            return [
                'error' => 'Unknown model',
                'cost' => 0
            ];
        }

        // Calculate the base cost using per 1000 tokens pricing
        $inputCost = $this->modelBaseCosts[$model]['input'] * ($inputTokens / 1000);
        $outputCost = $this->modelBaseCosts[$model]['output'] * ($outputTokens / 1000);
        
        // Calculate the raw cost without profit margin
        $rawCost = $inputCost + $outputCost;
        
        // Apply profit multiplier to ensure 10x profit
        $totalCost = ($rawCost * $profitMultiplier) * $usageMultiplier;

        return [
            'inputCost' => round($inputCost, 6),
            'outputCost' => round($outputCost, 6),
            'rawCost' => round($rawCost, 6),
            'profitMultiplier' => $profitMultiplier,
            'totalCost' => round($totalCost, 6)
        ];
    }

    // --- Analyze Image Endpoint ---
    // --- Analyze Image Endpoint ---
    /**
     * Validate if a model ID follows valid OpenRouter model patterns
     * 
     * @param string $modelId The model ID to validate
     * @return bool True if model ID appears valid, false otherwise
     */
    protected function isValidModelId($modelId)
    {  
        if (empty($modelId)) {
            return false;
        }
        
        // Check against known valid patterns
        foreach ($this->validModelPatterns as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }
        
        // Check if it's one of our explicitly defined models
        foreach ($this->availableModels as $model) {
            if ($model['id'] === $modelId) {
                return true;
            }
        }
        
        return false;
    }
    
    public function analyzeImage(Request $request)
    {
        // set max to 5 minutes analyze
        ini_set('max_execution_time', 300);
        $imageDatas = [];
        $preview_from_chart_on_demand = $request->preview_from_chart_on_demand;
        $individualAnalysis = $request->input('individualAnalysis', true); // Default to true for individual analysis
    
        try {
            // Check if user has an active subscription
            $user = Auth::user();
            $userId = Auth::user()->id;
            
            $originalModelId = $request->input('modelId', 'qwen/qwen2.5-vl-72b-instruct:free');
            $modelId = $originalModelId;
            $customPrompt = $request->input('prompt', null);
            $imageUrls = [];
            
            // Validate the model ID before proceeding
            if (!$this->isValidModelId($modelId)) {
                Log::warning("Invalid model ID detected", [
                    'model_id' => $modelId,
                    'user_id' => $userId
                ]);
                
                // Check if this is already a fallback attempt to prevent infinite recursion
                $isFallbackAttempt = $request->input('is_fallback_attempt', false);
                $attemptCount = $request->input('fallback_attempt_count', 0);
                
                if ($attemptCount < count($this->fallbackModels)) {
                    // Try the next fallback model in sequence
                    $nextFallbackModel = $this->fallbackModels[$attemptCount];
                    Log::info("Using fallback model due to invalid model ID: {$nextFallbackModel}");
                    $fallbackRequest = clone $request;
                    $fallbackRequest->merge([
                        'modelId' => $nextFallbackModel,
                        'is_fallback_attempt' => true,
                        'fallback_attempt_count' => $attemptCount + 1,
                        'original_model_id' => $modelId
                    ]);
                    return $this->analyzeImage($fallbackRequest);
                } else {
                    // If all fallbacks have been tried, use a safe default
                    Log::info("All fallbacks attempted, using safe default: qwen/qwen2.5-vl-72b-instruct:free");
                    $modelId = 'qwen/qwen2.5-vl-72b-instruct:free';
                }
            }

            $inputTokens = $this->estimatedAnalysisTokens['input'];
            $outputTokens = $this->estimatedAnalysisTokens['output'];
            
            $tokenCost = 0;
            $addonCost = $preview_from_chart_on_demand == 'true' ? 1.5 : 1;
            $costCalculation = $this->calculateCost($modelId, $inputTokens, $outputTokens, $addonCost);
            
            // Handle the case when the model is unknown and calculateCost returns an error
            if (isset($costCalculation['error'])) {
                // Use a default cost if model is unknown
                $tokenCost = ceil(($inputTokens * 0.0005 + $outputTokens * 0.0015) * 667);
                Log::warning('Unknown model in cost calculation, using default pricing', [
                    'model' => $modelId,
                    'error' => $costCalculation['error']
                ]);
            } else {
                // if request->preview_from_chart_on_demand is true, add 50% more to tokenCost
                $tokenCost = ceil($costCalculation['totalCost'] * 667); // 667 tokens per dollar conversion
            }
            
            // Check if the user has sufficient tokens
            $tokenBalances = $this->tokenService->getUserTokens($userId);
            
            Log::info('Checking token balance for image analysis', [
                'user_id' => $userId,
                'subscription_token' => $tokenBalances['subscription_token'],
                'addons_token' => $tokenBalances['addons_token'],
                'tokens_required' => $tokenCost
            ]);
            
            // Check if there are sufficient tokens in the combined total of subscription_token and addons_token
            $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
            if ($totalAvailableTokens < $tokenCost) {
                return response()->json([
                    'error' => 'Insufficient tokens',
                    'message' => 'You do not have enough tokens to perform this analysis.',
                    'tokens_required' => $tokenCost,
                    'subscription_token' => $tokenBalances['subscription_token'],
                    'addons_token' => $tokenBalances['addons_token']
                ], 403);
            }

            // Detailed request debugging
            Log::info('Analyze Image Request Details', [
                'has_files' => $request->hasFile('images'),
                'content_type' => $request->header('Content-Type'),
                'method' => $request->method(),
                'all_files' => $request->allFiles(),
                'all_inputs' => array_keys($request->all()),
                'file_input_type' => gettype($request->file('images'))
            ]);
            
            // Handle both file uploads and base64 strings
            // Initialize variables
            $imageDatas = [];
            $processedImages = [];
            $rawImageProcessing = false;
            
            // Check for files in two ways to handle different client types
            if ($request->hasFile('images') || !empty($request->allFiles()['images']) || !empty($_FILES['images']['tmp_name'])) {
                Log::info('File upload detected');
                // This is file upload
                $files = $request->file('images');
                
                // Direct access to $_FILES as fallback if Laravel's file handling isn't working
                if (empty($files) && !empty($_FILES['images']['tmp_name'])) {
                    Log::info('Using direct $_FILES access as fallback');
                    
                    // Create a collection of UploadedFile objects manually
                    $files = [];
                    
                    // Handle both single file and array of files in $_FILES
                    if (is_array($_FILES['images']['tmp_name'])) {
                        // Multiple files
                        foreach ($_FILES['images']['tmp_name'] as $key => $path) {
                            if (!empty($path) && file_exists($path)) {
                                $files[] = new \Illuminate\Http\UploadedFile(
                                    $path,
                                    $_FILES['images']['name'][$key],
                                    $_FILES['images']['type'][$key],
                                    $_FILES['images']['error'][$key],
                                    true // Mark as test to avoid moving the file again
                                );
                            }
                        }
                    } else {
                        // Single file
                        if (!empty($_FILES['images']['tmp_name']) && file_exists($_FILES['images']['tmp_name'])) {
                            $files[] = new \Illuminate\Http\UploadedFile(
                                $_FILES['images']['tmp_name'],
                                $_FILES['images']['name'],
                                $_FILES['images']['type'],
                                $_FILES['images']['error'],
                                true // Mark as test to avoid moving the file again
                            );
                        }
                    }
                    
                    Log::info('Created ' . count($files) . ' UploadedFile objects from $_FILES');
                }
                
                // Handle both single file and array of files
                if (!is_array($files)) {
                    $files = [$files];
                    Log::info('Single file converted to array');
                }
                
                Log::info('Files to process', ['count' => count($files)]);
                
                // Final check - if files is still empty but we detected a file upload attempt
                if (empty($files)) {
                    // Last resort - check if raw content was uploaded under the name 'images'
                    $rawContent = $request->getContent();
                    
                    Log::info('No files detected in standard ways, checking raw content', [
                        'content_length' => strlen($rawContent),
                        'content_starts_with' => substr($rawContent, 0, 50)
                    ]);
                    
                    // Just create a base64 image from the raw content as a last resort
                    if (!empty($rawContent) && strlen($rawContent) > 1000) { // Assume any large content might be an image
                        Log::info('Treating raw request content as image data');
                        $imageDatas = ['data:image/png;base64,' . base64_encode($rawContent)];
                        $rawImageProcessing = true;
                    }
                    
                    if (!$rawImageProcessing) {
                        return response()->json([
                            'error' => 'No valid files found',
                            'message' => 'The system detected a file upload attempt but could not process any valid image files.',
                            'help' => 'Make sure your form field is named "images" (plural) and contains a valid image file.',
                            'detected_files' => array_keys($request->allFiles()),
                            'detected_inputs' => array_keys($request->all())
                        ], 400);
                    }
                }
                
                // Validate the files
                foreach ($files as $index => $file) {
                    if (!$file->isValid()) {
                        Log::error("Invalid file at index {$index}", [
                            'error' => $file->getError(),
                            'error_message' => $file->getErrorMessage()
                        ]);
                        return response()->json(['error' => 'Invalid image file', 'details' => $file->getErrorMessage()], 400);
                    }
                    
                    Log::info("File {$index} is valid", [
                        'name' => $file->getClientOriginalName(),
                        'size' => $file->getSize(),
                        'mime' => $file->getMimeType()
                    ]);
                }
                
                // Upload to Firebase Storage if user is authenticated
                if (auth()->check()) {
                    try {
                        // Generate unique filenames
                        $userId = Auth::user()->id;
                        
                        foreach ($files as $file) {
                            $filename = 'chart_analysis/' . $userId . '/' . time() . '_' . Str::random(20) . '.' . $file->getClientOriginalExtension();
                            
                            // Get Firebase Storage bucket name from config
                            $bucketName = config('firebase.storage.bucket', config('firebase.project_id', 'ai-crm-windsurf') . '.firebasestorage.app');
                        
                            // Create a storage reference
                            $storage = app('firebase.storage');
                            $defaultBucket = $storage->getBucket($bucketName);
                        
                            // Upload file to Firebase Storage
                            $object = $defaultBucket->upload(
                                file_get_contents($file->getRealPath()),
                                [
                                    'name' => $filename,
                                    'predefinedAcl' => 'publicRead'
                                ]
                            );
                            
                            // Get the public URL
                            $imageUrl = 'https://storage.googleapis.com/' . $bucketName . '/' . $filename;
                            $imageUrls[] = $imageUrl;
                            // Also keep base64 for API request
                            $imageData = 'data:' . $file->getMimeType() . ';base64,' . base64_encode(file_get_contents($file->path()));
                            $imageDatas[] = $imageData;
                            
                            Log::info('Image uploaded to Firebase Storage', [
                                'filename' => $filename,
                                'url' => $imageUrl
                            ]);
                            
                            Log::info('Processing uploaded image file', [
                                'filename' => $file->getClientOriginalName(),
                                'mime_type' => $file->getMimeType(),
                                'size' => $file->getSize(),
                                'storage' => !empty($imageUrl) ? 'firebase' : 'base64'
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Firebase Storage error: ' . $e->getMessage());
                        // Fall back to base64 if Firebase upload fails
                        foreach ($files as $k => $file) {
                            $imageData = 'data:' . $file->getMimeType() . ';base64,' . base64_encode(file_get_contents($file->path()));
                            $imageDatas[] = $imageData;
                        }
                    }
                } else {
                    // User not authenticated or Firebase upload failed, use base64
                    foreach ($files as $file) {
                        $imageData = 'data:' . $file->getMimeType() . ';base64,' . base64_encode(file_get_contents($file->path()));
                        $imageDatas[] = $imageData;
                        Log::info('Processing uploaded image file', [
                            'filename' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'size' => $file->getSize(),
                            'storage' => 'base64'
                        ]);
                    }
                }
            } else {
                // Expect base64 string(s)
                $images = $request->input('images');
                // Handle both single image and array of images
                if (is_array($images)) {
                    $imageDatas = $images;
                } else if (!empty($images)) {
                    $imageDatas = [$images];
                }
                
                // Validate image data
                if (empty($imageDatas)) {
                    return response()->json(['error' => 'No image data provided'], 400);
                }
            }

            // More detailed logging about image data before processing
            Log::info('Image data before processing', [
                'image_count' => count($imageDatas),
                'first_image_type' => is_string($imageDatas[0] ?? null) ? 'string' : (is_array($imageDatas[0] ?? null) ? 'array' : 'unknown'),
                'first_image_length' => is_string($imageDatas[0] ?? null) ? strlen($imageDatas[0]) : 0,
                'first_image_starts_with' => is_string($imageDatas[0] ?? null) ? substr($imageDatas[0], 0, 30) . '...' : 'n/a'
            ]);

            // Process base64 data for API request
            $processedImages = [];
            foreach ($imageDatas as $index => $imageData) {
                if (empty($imageData)) {
                    Log::warning("Empty image data at index {$index}");
                    continue; // Skip empty data
                }

                $dataUriPrefix = '';
                $base64Data = '';
                
                if (is_string($imageData) && preg_match('/^data:(image\/[a-zA-Z+]+);base64,(.+)$/', $imageData, $matches)) {
                    // Standard data URI format
                    $dataUriPrefix = "data:{$matches[1]};base64,";
                    $base64Data = $matches[2];
                    Log::info("Image {$index} detected as data URI format");
                } else if (is_string($imageData) && preg_match('/^([a-zA-Z0-9+\/=]+)$/', $imageData)) {
                    // Raw base64 without prefix
                    $base64Data = $imageData;
                    $dataUriPrefix = "data:image/png;base64,"; // Default assumption
                    Log::info("Image {$index} detected as raw base64");
                } else if (is_string($imageData)) {
                    // Unknown string format - try to salvage if possible
                    Log::warning("Image {$index} has unknown format, trying to extract base64 data");
                    
                    // Look for base64 data anywhere in the string
                    if (preg_match('/data:(image\/[a-zA-Z+]+);base64,([a-zA-Z0-9+\/=]+)/', $imageData, $matches)) {
                        $dataUriPrefix = "data:{$matches[1]};base64,";
                        $base64Data = $matches[2];
                        Log::info("Extracted base64 data from unknown format");
                    } else {
                        // Just try the whole string as base64 as a last resort
                        $base64Data = preg_replace('/[^a-zA-Z0-9+\/=]/', '', $imageData);
                        $dataUriPrefix = "data:image/png;base64,";
                        Log::warning("Using cleaned string as base64");
                    }
                } else {
                    Log::error("Image data at index {$index} is not a string", ['type' => gettype($imageData)]);
                    continue; // Skip non-string data
                }
                
                // Validate the base64 string
                $decodedData = base64_decode($base64Data, true);
                if ($decodedData === false || strlen($decodedData) < 100) { // Minimum size check
                    Log::error("Invalid base64 image data at index {$index}", [
                        'length' => strlen($imageData),
                        'decode_success' => $decodedData !== false,
                        'decoded_length' => $decodedData !== false ? strlen($decodedData) : 0
                    ]);
                    continue; // Skip invalid data instead of failing entirely
                }
                
                $processedImages[] = [
                    'prefix' => $dataUriPrefix,
                    'data' => $base64Data
                ];
                
                Log::info("Successfully processed image {$index}", [
                    'prefix_type' => $dataUriPrefix,
                    'data_length' => strlen($base64Data)
                ]);
            }
            
            // Ensure the image data has the proper format for the API
            // For OpenRouter with vision models, we need to use the correct format based on the model
            $userContent = [];
            
            // Add the text prompt
            $userContent[] = [
                'type' => 'text', 
                'text' => $request->language == 'ch' ? $this->chUserContent : $this->userContent
            ];

            
            // Add the images in the correct format based on the model
            if (strpos($modelId, 'google/gemini') !== false || 
                strpos($modelId, 'anthropic/claude') !== false) {
                // These models expect the image in inline_data format
                foreach ($processedImages as $image) {
                    $userContent[] = [
                        'type' => 'image',
                        'image' => [
                            'data' => $image['data']
                        ]
                    ];
                }
            } else if (strpos($modelId, 'meta-llama') !== false || strpos($modelId, 'qwen') !== false) {
                // Meta Llama and Qwen models require this format
                foreach ($processedImages as $image) {
                    $userContent[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image['prefix'] . $image['data']
                        ]
                    ];
                }
            } else if (strpos($modelId, 'gpt-4') !== false) {
                // Special handling for GPT-4 models including GPT-4o
                // GPT-4 vision models require a complete data URI
                Log::info('Using GPT-4 specific image format');
                foreach ($processedImages as $image) {
                    $userContent[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image['prefix'] . $image['data']
                        ]
                    ];
                }
                
                // Log what we're sending for debugging
                Log::info('GPT-4 image content structure', [
                    'content_count' => count($userContent),
                    'has_image_urls' => isset($userContent[1]) && isset($userContent[1]['type']) && $userContent[1]['type'] === 'image_url',
                    'first_content_type' => $userContent[0]['type'] ?? 'none'
                ]);
            } else {
                // Default format for other models (OpenAI compatible)
                foreach ($processedImages as $image) {
                    $userContent[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image['prefix'] . $image['data']
                        ]
                    ];
                }
            }
            
            // If multiple images are present, analyze individually and then combine
            // if (count($processedImages) > 1) {
            //     Log::info('Processing multiple images individually', [
            //         'image_count' => count($processedImages)
            //     ]);
            //     return $this->analyzeMultipleImagesIndividually($request, $processedImages, $modelId, $preview_from_chart_on_demand, $imageUrls);
            // }
            
            // Compose message structure for single image or combined analysis
            $messages = [
                [
                    'role' => 'system',
                    'content' => $request->language == 'ch' ? $this->chsystemPrompt : $this->systemPrompt
                ],
                [
                    'role' => 'user',
                    'content' => $userContent
                ]
            ];

            $apiKey = env('OPENROUTER_API_KEY');
            $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

            // Validate image data before sending request
            if (empty($processedImages)) {
                Log::error('No processed images available before API request', [
                    'model_id' => $modelId,
                    'original_image_count' => count($imageDatas),
                    'processed_image_count' => count($processedImages)
                ]);
                
                // More specific error message based on what happened
                if (empty($imageDatas)) {
                    return response()->json([
                        'error' => 'No image data was provided',
                        'hint' => 'Please ensure you are sending image data in the "images" field'
                    ], 400);
                } else {
                    return response()->json([
                        'error' => 'Failed to process image data', 
                        'hint' => 'The image data provided could not be processed. Please check that you are sending valid image data in the correct format.'
                    ], 400);
                }
            }

            // Add image data debug info
            foreach ($processedImages as $index => $image) {
                $prefix = $image['prefix'] ?? 'none';
                $dataLength = isset($image['data']) ? strlen($image['data']) : 0;
                
                Log::info("Image data validation for index {$index}", [
                    'has_prefix' => !empty($prefix),
                    'prefix_type' => $prefix,
                    'data_length' => $dataLength,
                    'data_sample' => $dataLength > 20 ? substr($image['data'], 0, 20) . '...' : 'empty',
                    'is_valid_base64' => base64_decode($image['data'], true) !== false
                ]);
            }

            // Log the request being sent with more details
            Log::info('Sending request to OpenRouter API', [
                'model_id' => $modelId,
                'image_count' => count($imageDatas),
                'processed_image_count' => count($processedImages),
                'has_custom_prompt' => !empty($customPrompt),
                'image_format' => strpos($modelId, 'google/gemini') !== false ? 'image/data' : 
                                (strpos($modelId, 'meta-llama') !== false ? 'image_url with data URI' : 'image_url'),
                'message_structure' => array_map(function($msg) {
                    $msgCopy = $msg;
                    if (isset($msgCopy['content']) && is_array($msgCopy['content'])) {
                        foreach ($msgCopy['content'] as $idx => $content) {
                            if (isset($content['image']) || isset($content['image_url'])) {
                                $msgCopy['content'][$idx] = '[IMAGE DATA REDACTED]';
                            }
                        }
                    }
                    return $msgCopy;
                }, $messages)
            ]);

            // Increase timeout to 120 seconds and add retry logic
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json',
                    'X-Title' => 'AI Market Analyst'
                ])
                ->retry(3, 5000) // Retry up to 3 times with 5 second delay between attempts
                ->post($apiUrl, [
                    'model' => $modelId,
                    // 'max_tokens' => 4000,
                    // 'temperature' => 0.7,
                    'messages' => $messages
                ]);

            if ($response->failed()) {
                // Use the centralized error handler for better consistency
                $errorResponse = $this->handleOpenRouterError($response, $modelId);
                
                // Additional logging specific to image analysis
                Log::error('OpenRouter image analysis API request failed', [
                    'status_code' => $response->status(),
                    'model_id' => $modelId,
                    'request_structure' => [
                        'message_count' => count($messages),
                        'image_format' => strpos($modelId, 'google/gemini') !== false ? 'inline_data' : 'image_url'
                    ]
                ]);
                
                // Implement model fallback system for 404 errors (model not available)
                if ($response->status() === 404) {
                    // Check if the current model is not already one of our fallback models
                    if (!in_array($modelId, $this->fallbackModels)) {
                        // Attempt to use our primary fallback models sequentially
                        foreach ($this->fallbackModels as $fallbackModel) {
                            Log::info("Attempting fallback to {$fallbackModel} model after 404 error");
                            
                            $fallbackResponse = Http::timeout(60)
                                ->withHeaders([
                                    'Authorization' => "Bearer $apiKey",
                                    'Content-Type' => 'application/json',
                                    'X-Title' => 'AI Market Analyst'
                                ])
                                ->retry(2, 3000)
                                ->post($apiUrl, [
                                    'model' => $fallbackModel,
                                    'max_tokens' => 4000,
                                    'temperature' => 0.7,
                                    'messages' => $messages
                                ]);
                                
                            if ($fallbackResponse->successful()) {
                                Log::info("Fallback to {$fallbackModel} model successful");
                                // Replace the current response object with the fallback response
                                $response = $fallbackResponse;
                                // Break out of the loop since we found a working model
                                break;
                            } else {
                                Log::error("Fallback to {$fallbackModel} model failed", [
                                    'status' => $fallbackResponse->status(),
                                    'error' => $fallbackResponse->json() ?: 'No error details'
                                ]);
                            }
                        }
                        
                        // Check if any fallback model was successful
                        if (!$response->successful()) {
                            return response()->json([
                                'error' => 'Image analysis failed',
                                'message' => 'The selected AI model and all fallback models are currently unavailable. Please try again later.',
                                'attempted_models' => array_merge([$modelId], $this->fallbackModels)
                            ], 503);
                        }
                    } else {
                        // This is already one of our fallback models and it failed
                        return $errorResponse;
                    }
                } else {
                    return $errorResponse;
                }
            }
            
            // Check if the response is empty (this happens sometimes with OpenRouter)
            if (empty($response->body())) {
                Log::error('OpenRouter returned empty response body', [
                    'status_code' => $response->status(),
                    'model_id' => $modelId,
                    'headers' => $response->headers()
                ]);
                
                // Use the defined fallback models
                $modelsToTry = $this->fallbackModels;
                
                // Don't try the same model that already failed
                $modelsToTry = array_filter($modelsToTry, function($model) use ($modelId) {
                    return $model !== $modelId;
                });
                
                Log::info('Starting fallback sequence for empty response', [
                    'original_model' => $modelId,
                    'fallback_models' => $modelsToTry
                ]);
                
                $fallbackSuccess = false;
                foreach ($modelsToTry as $fallbackModelId) {
                    Log::info("Attempting fallback to {$fallbackModelId} model due to empty response");
                    
                    // Update the request to use the fallback model
                    try {
                        $fallbackResponse = Http::timeout(90)
                            ->withHeaders([
                                'Authorization' => "Bearer $apiKey",
                                'Content-Type' => 'application/json',
                                'X-Title' => 'AI Market Analyst'
                            ])
                            ->retry(2, 3000)
                            ->post($apiUrl, [
                                'model' => $fallbackModelId,
                                'max_tokens' => 4000,
                                'temperature' => 0.7,
                                'messages' => $messages
                            ]);
                            
                        // Log detailed information about the response
                        Log::info("Fallback response from {$fallbackModelId}", [
                            'status' => $fallbackResponse->status(),
                            'has_body' => !empty($fallbackResponse->body()),
                            'body_length' => strlen($fallbackResponse->body()),
                            'headers' => $fallbackResponse->headers(),
                        ]);
                        
                        if ($fallbackResponse->successful() && !empty($fallbackResponse->body())) {
                            Log::info("Fallback to {$fallbackModelId} model successful");
                            // Replace the current response object with the fallback response
                            $response = $fallbackResponse;
                            $fallbackSuccess = true;
                            // Break out of the loop since we found a working model
                            break;
                        } else {
                            Log::warning("Fallback to {$fallbackModelId} failed or returned empty response", [
                                'status' => $fallbackResponse->status(),
                                'body_length' => strlen($fallbackResponse->body() ?? '')
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error("Exception during fallback to {$fallbackModelId}", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        // Continue to the next fallback model
                        continue;
                    }
                }
                
                // Log final fallback status
                Log::info('Completed fallback sequence for empty response', [
                    'success' => $fallbackSuccess,
                    'final_model' => $fallbackSuccess ? $fallbackModelId : 'none succeeded'
                ]);
                
                // If we still have an empty response after trying all fallback models
                if (empty($response->body())) {
                    Log::error('All fallback models failed or returned empty responses');
                    
                    // Check for OpenRouter API key issues
                    $apiKeyStatus = $this->checkApiKeyStatus($apiKey);
                    if (!$apiKeyStatus['valid']) {
                        Log::critical('OpenRouter API key validation failed', $apiKeyStatus);
                        return response()->json(['error' => 'OpenRouter API key validation failed: ' . $apiKeyStatus['message']], 403);
                    }
                    
                    return response()->json([
                        'error' => 'All AI models returned empty responses. This could be due to a temporary OpenRouter service issue. Please try again later.',
                        'fallback_models_tried' => implode(', ', $fallbackModels),
                        'original_model' => $modelId
                    ], 502);
                }
            }

            // Get the raw response body first for debugging
            $rawBody = $response->body();
            Log::info('OpenRouter raw response', [
                'model_id' => $modelId,
                'raw_response' => substr($rawBody, 0, 2000), // Log first 2000 chars to avoid huge logs
                'response_length' => strlen($rawBody),
                'response_status' => $response->status(),
                'response_headers' => $response->headers()
            ]);
            
            // Try parsing as JSON with more detailed error handling
            $data = null;
            try {
                // Check if response is empty first
                if (empty($rawBody)) {
                    Log::error('OpenRouter returned empty response body', [
                        'model_id' => $modelId,
                        'status_code' => $response->status(),
                        'headers' => $response->headers()
                    ]);
                    return response()->json(['error' => 'OpenRouter API returned an empty response. Please try again or use a different model.'], 500);
                }
                
                $data = json_decode($rawBody, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('JSON parse error', [
                        'error' => json_last_error_msg(),
                        'model_id' => $modelId,
                        'raw_response_sample' => substr($rawBody, 0, 500)
                    ]);
                    
                    // Try to sanitize and re-parse if there might be invalid UTF-8 characters
                    $sanitizedBody = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $rawBody);
                    if ($sanitizedBody !== $rawBody) {
                        Log::info('Attempting to parse sanitized JSON response');
                        $data = json_decode($sanitizedBody, true);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception parsing JSON', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'model_id' => $modelId
                ]);
            }
            
            // Log successful API response metadata
            Log::info('OpenRouter API request succeeded', [
                'model_id' => $modelId,
                'tokens' => $data['usage'] ?? 'Not provided',
                'response_time_ms' => $response->handlerStats()['total_time_us'] / 1000
            ]);
            
            $assistantReply = $data['choices'][0]['message']['content'] ?? null;
            \Log::info('Getting response from AI models : ' . json_encode($assistantReply));
            
            // Check for common error messages indicating image not seen
            if ($assistantReply !== null && (
                stripos($assistantReply, "can't analyze the market chart without seeing it") !== false ||
                stripos($assistantReply, "I don't see any chart") !== false ||
                stripos($assistantReply, "no image provided") !== false ||
                stripos($assistantReply, "cannot see any image") !== false ||
                stripos($assistantReply, "I need to see the chart") !== false
            )) {
                Log::error('AI responded indicating it cannot see the image', [
                    'model_id' => $modelId,
                    'response' => $assistantReply,
                    'image_count' => count($imageDatas),
                    'processed_image_count' => count($processedImages)
                ]);
                
                // Try fallback models when AI can't see the image
                // Check if the current model isn't already a fallback model
                if (!in_array($modelId, $this->fallbackModels)) {
                    Log::info('AI cannot see the image, attempting fallback models in sequence');
                    
                    // Try each fallback model in order until one works
                    // Use attempt count to track which fallback we're on
                    $attemptCount = $request->input('fallback_attempt_count', 0);
                    
                    // Only try the next fallback in sequence, not all of them
                    if ($attemptCount < count($this->fallbackModels)) {
                        $nextFallbackModel = $this->fallbackModels[$attemptCount];
                        Log::info("Trying fallback model {$nextFallbackModel} as image might not be visible to {$modelId}");
                        
                        try {
                            $fallbackRequest = clone $request;
                            $fallbackRequest->merge([
                                'modelId' => $nextFallbackModel,
                                'is_fallback_attempt' => true,
                                'fallback_attempt_count' => $attemptCount + 1,
                                'original_model_id' => $request->input('original_model_id', $modelId)
                            ]);
                            return $this->analyzeImage($fallbackRequest);
                        } catch (\Exception $e) {
                            Log::warning("Fallback to {$nextFallbackModel} failed", [
                                'error' => $e->getMessage()
                            ]);
                            
                            // If this fallback failed, try the next one in sequence
                            if ($attemptCount + 1 < count($this->fallbackModels)) {
                                $fallbackRequest->merge(['fallback_attempt_count' => $attemptCount + 2]);
                                return $this->analyzeImage($fallbackRequest);
                            }
                        }
                    }
                }
                
                return response()->json([
                    'error' => 'The AI could not process the image. The image may be corrupt, too low resolution, or in an unsupported format.',
                    'ai_message' => $assistantReply,
                    'recommendation' => 'Please try uploading a clearer image or use a different AI model.'
                ], 400);
            }
            
            // Enhanced error logging for missing assistant reply
            if ($assistantReply === null) {
                // Try to capture as much information as possible about the response structure
                $responseInfo = [
                    'model_id' => $modelId,
                    'data_null' => $data === null,
                    'response_status' => $response->status(),
                    'has_choices' => isset($data['choices']),
                    'choices_count' => isset($data['choices']) ? count($data['choices']) : 0,
                    'first_choice' => isset($data['choices'][0]) ? json_encode($data['choices'][0]) : 'not found',
                    'has_message' => isset($data['choices'][0]['message']),
                    'full_response_structure' => json_encode($data)
                ];
                
                // Additional check for specific structure variations
                if (isset($data['choices'][0]['message']) && is_array($data['choices'][0]['message'])) {
                    $responseInfo['message_keys'] = array_keys($data['choices'][0]['message']);
                }
                
                if (isset($data['choices'][0]['text'])) {
                    // Some APIs might use 'text' instead of 'message.content'
                    $assistantReply = $data['choices'][0]['text'];
                    Log::info('Found reply in alternate location: choices[0].text', [
                        'model_id' => $modelId,
                        'reply_length' => strlen($assistantReply)
                    ]);
                } elseif (isset($data['content'])) {
                    // Try another possible location
                    $assistantReply = $data['content'];
                    Log::info('Found reply in alternate location: content', [
                        'model_id' => $modelId,
                        'reply_length' => strlen($assistantReply)
                    ]);
                } else {
                    Log::error('No assistant reply found in API response', $responseInfo);
                    return response()->json([
                        'error' => 'No assistant reply found in API response.', 
                        'response_info' => $responseInfo
                    ], 500);
                }
            }
            
            // Attempt to decode the AI's JSON response
            $structuredResponse = null;
            $jsonError = '';
            
            \Log::info("Original response : ". json_encode($assistantReply));
            // Step 1: Try direct JSON decode first
            $structuredResponse = json_decode($assistantReply, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::info('Successfully parsed JSON response directly');
            } else {
                $jsonError = json_last_error_msg();
                
                // Step 2: Try to find and extract a JSON object in the response using regex
                if (preg_match('/\{.*\}/s', $assistantReply, $matches)) {
                    $potentialJson = $matches[0];
                    $structuredResponse = json_decode($potentialJson, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE) {
                        Log::info('Successfully extracted and parsed JSON object from response');
                    } else {
                        $jsonError = json_last_error_msg();
                        
                        // Step 3: Try more aggressive cleanup - remove markdown code block markers and other non-JSON text
                        $cleanedJson = preg_replace('/```(?:json)?\s*|```\s*$/', '', $assistantReply);
                        $cleanedJson = preg_replace('/[\s\S]*?(\{[\s\S]*\})[\s\S]*/', '$1', $cleanedJson);
                        
                        $structuredResponse = json_decode($cleanedJson, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            Log::info('Successfully parsed JSON after aggressive cleanup');
                        } else {
                            $jsonError = json_last_error_msg();
                        }
                    }
                }
            }
            
            // If JSON parsing still failed, fall back to the parseAnalysisResponse method
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('AI response was not valid JSON, falling back to text parsing', [
                    'json_decode_error' => json_last_error_msg(),
                    'raw_ai_reply_excerpt' => substr($assistantReply, 0, 500) . (strlen($assistantReply) > 500 ? '...' : ''),
                    'model_id' => $modelId
                ]);
                
                // Try to parse the response as text
                $structuredResponse = $this->parseAnalysisResponse($assistantReply);
                
                // If we still couldn't parse it, return an error
                if (empty($structuredResponse) || !isset($structuredResponse['Symbol'])) {
                    // Log detailed debugging information
                    Log::error('All parsing methods failed', [
                        'original_json_error' => $jsonError,
                        'response_excerpt' => substr($assistantReply, 0, 1000),
                        'model_id' => $modelId,
                        'structured_response' => $structuredResponse
                    ]);
                    
                    return response()->json([
                        'error' => 'Unable to parse AI response. The AI model did not return data in the expected format. Please try again with a different model or contact support if the issue persists.',
                        'json_decode_error_message' => $jsonError ?: 'No error',
                        'debug_info' => [
                            'model_used' => $modelId,
                            'response_length' => strlen($assistantReply),
                            'response_excerpt' => substr($assistantReply, 0, 200) . '...'
                        ]
                    ], 502); // 502 Bad Gateway
                }
            }

            // Save the analysis to history if the user is authenticated
            if (auth()->check()) {
                try {
                    \Log::info('Get Structure Response :'.json_encode($structuredResponse));
                    // $structureContent = [];
                    $timeframes = [];
                    // foreach($structuredResponse as $structure) {
                        // Generate a title based on the symbol and timeframe if available
                    $symbol = $structuredResponse['Symbol'] ?? $structuredResponse['symbol'] ?? 'Unknown';
                    $timeframe = $structuredResponse['Timeframe'] ?? $structuredResponse['timeframe'] ?? 'Chart';
                    $title = "$symbol $timeframe Analysis";
                    // // update correct risk ratio
                    // $entry = (float)(str_replace('$', '', $structure['Entry_Price'] ?? $structure['entry']));
                    // $stop = (float)(str_replace('$', '', $structure['Stop_Loss'] ?? $structure['stop_loss']));
                    // $profit = (float)(str_replace('$', '', $structure['Take_Profit'] ?? $structure['take_profit']));
                    // $riskAmount = (float)($entry - $stop);
                    // $rewardAmount = (float)($profit - $entry);
                    // $ratio = number_format(($rewardAmount / $riskAmount), 1, '.', "");
                    // $structure['Risk_Ratio'] = "1:$ratio";
                    // $structureContent[] = $structure;
                    // }
                    // Create the history record and capture the instance
                    $times = implode(',', $timeframes);
                    // Create a nicely formatted share_content with emojis and branding
                    $shareContent = $this->createShareableContent($structuredResponse);
                    $structuredResponse['share_content'] = $shareContent;
                    
                    $history = \App\Models\History::create([
                        'user_id' => auth()->id(),
                        'title' => "$symbol $times Analysis",
                        'type' => 'chart_analysis',
                        'model' => $modelId,
                        'content' => json_encode($structuredResponse),
                        'chart_urls' => !empty($imageUrls) ? $imageUrls : [],
                        'timestamp' => now(),
                    ]);
                } catch (\Exception $e) {
                    // Log the error but don't fail the request
                    \Log::error('Failed to save analysis to history', [
                        'error' => $e->getMessage(),
                        'user_id' => auth()->id()
                    ]);
                }
            }
            
            // Include both the analysis data and the history ID in the response
            $responseData = $structuredResponse;
            \Log::info('Before share content :'. json_encode($responseData));
            $shareContent = $this->createShareableContent($responseData);
            $responseData['share_content'] = $shareContent;
            $responseData['prompt'] = $request->language == 'ch' ? $this->chsystemPrompt : $this->systemPrompt;
                    
            \Log::info('Before forex news, get the symbol :'. json_encode($structuredResponse));
            $today = date('Y-m-d');
            
            $params = $structuredResponse['Symbol'] ?? $structuredResponse['symbol'] ?? 'Not Visible';
            // Handle cases where params might be "Not Visible" or invalid
            if ($params === "Not Visible" || empty($params)) {
                $searchCountries = ['USD']; // default fallback 
            } else {
                // Known fiat currencies (expand as needed)
                $currencies = ['USD','EUR','GBP','JPY','AUD','NZD','CAD','CHF','CNY','SGD','HKD'];
                $searchCountries = [];

                // Always check if param contains USD (special case for things like LINKUSD, BTCUSD, etc.)
                if (Str::contains($params, 'USD')) {
                    $searchCountries[] = 'USD';
                }

                // Check against known fiat currencies
                foreach ($currencies as $cur) {
                    if ($cur !== 'USD' && Str::contains($params, $cur)) {
                        $searchCountries[] = $cur;
                    }
                }

                // Fallback to USD if no match found
                if (empty($searchCountries)) {
                    $searchCountries = ['USD'];
                }
            }

            $news = ForexNew::where('impact', 'High')
                ->where('date', 'LIKE', "%$today%")
                ->whereIn('country', $searchCountries)
                ->get();
            
            $responseData['news'] = $news;
            
            // Add the history ID if available
            if (isset($history) && $history) {
                $responseData['history_id'] = $history->id;
            }
            
            // Get actual token counts from the API response if available
            $addonCost = $preview_from_chart_on_demand == 'true' ? 1.5 : 1;
            $actualInputTokens  = ($data['usage']['prompt_tokens'] ?? $inputTokens) * $addonCost;
            $actualOutputTokens = ($data['usage']['completion_tokens'] ?? $outputTokens) * $addonCost;
            
            // Recalculate token cost using actual token counts
            $actualCostCalculation = $this->calculateCost($modelId, $actualInputTokens, $actualOutputTokens, $addonCost);
            if (!isset($actualCostCalculation['error'])) {
                // Update the token cost using actual usage
                
                $actualTokenCost = ceil($actualCostCalculation['totalCost'] * 667); // 667 tokens per dollar
                
                if (abs($actualTokenCost - $tokenCost) > 5) {
                    Log::info('Updated token cost based on actual usage', [
                        'estimated_cost' => $tokenCost,
                        'actual_cost' => $actualTokenCost,
                        'difference' => $actualTokenCost - $tokenCost
                    ]);
                    // Update the token cost to use for deduction
                    $tokenCost = $actualTokenCost;
                }
            }
            
            Log::info('Using actual token counts from API response', [
                'input_tokens' => $actualInputTokens,
                'output_tokens' => $actualOutputTokens,
                'total' => $actualInputTokens + $actualOutputTokens,
                'token_cost' => $tokenCost
            ]);
            
            // Get latest token balances
            $tokenBalances = $this->tokenService->getUserTokens($userId);
            
            // Smart token deduction logic that can split across subscription and addon tokens if needed
            $deductionResult = false;
            
            if ($tokenBalances['subscription_token'] >= $tokenCost) {
                // If subscription tokens are enough, deduct from there
                $deductionResult = $this->tokenService->deductUserTokens(
                    $userId,
                    $tokenCost,
                    'image_analysis',
                    'subscription_token',
                    $modelId,
                    'vision',
                    $actualInputTokens,
                    $actualOutputTokens
                );
            } elseif ($tokenBalances['addons_token'] >= $tokenCost) {
                // If addon tokens are enough, deduct from there
                $deductionResult = $this->tokenService->deductUserTokens(
                    $userId,
                    $tokenCost,
                    'image_analysis',
                    'addons_token',
                    $modelId,
                    'vision',
                    $actualInputTokens,
                    $actualOutputTokens
                );
            } else {
                // Need to split the deduction across both token sources
                // First use all available subscription tokens
                $subscriptionDeduction = $this->tokenService->deductUserTokens(
                    $userId,
                    $tokenBalances['subscription_token'],
                    'image_analysis (partial)',
                    'subscription_token',
                    $modelId,
                    'vision',
                    intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                    intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
                );
                
                // Then deduct the remainder from addon tokens
                $remainingCost = $tokenCost - $tokenBalances['subscription_token'];
                $addonDeduction = $this->tokenService->deductUserTokens(
                    $userId,
                    $remainingCost,
                    'image_analysis (remainder)',
                    'addons_token',
                    $modelId,
                    'vision',
                    $actualInputTokens - intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                    $actualOutputTokens - intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
                );
                
                // Both deductions must succeed
                $deductionResult = $subscriptionDeduction && $addonDeduction;
                
                Log::info('Split token deduction for image analysis', [
                    'user_id' => $userId,
                    'total_cost' => $tokenCost,
                    'subscription_amount' => $tokenBalances['subscription_token'],
                    'addon_amount' => $remainingCost,
                    'success' => $deductionResult
                ]);
            }
            
            if (!$deductionResult) {
                Log::warning('Failed to deduct tokens for image analysis', [
                    'user_id' => $userId,
                    'tokens_to_deduct' => $tokenCost
                ]);
                // Continue despite failed deduction as we already processed the request
            } else {
                $updatedBalances = $this->tokenService->getUserTokens($userId);
                Log::info('Successfully deducted tokens for image analysis', [
                    'user_id' => $userId,
                    'deducted_tokens' => $tokenCost,
                    'remaining_subscription_tokens' => $updatedBalances['subscription_token'],
                    'remaining_addons_tokens' => $updatedBalances['addons_token']
                ]);
            }

            \Log::info('Before return, responseData :' . json_encode($responseData));
            
            return response()->json($responseData);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Specific handling for HTTP client exceptions
            \Log::error('HTTP Client Exception in analyzeImage', [
                'exception' => $e->getMessage(),
                'model_id' => $request->input('modelId', 'unknown'),
                'status_code' => $e->getCode(),
                'response' => method_exists($e, 'response') ? $e->response() : null
            ]);
            
            $statusCode = $e->getCode() ?: 500;
            $responseData = [];
            
            try {
                // Try to parse the response if available
                if (method_exists($e, 'response') && $e->response()) {
                    $responseData = json_decode($e->response()->body(), true) ?: [];
                }
            } catch (\Exception $jsonEx) {
                \Log::error('Failed to parse error response', ['exception' => $jsonEx->getMessage()]);
            }
            
            // Log the response data to help with debugging
            \Log::debug('OpenRouter API response data in error handler', [
                'status_code' => $statusCode,
                'response_data' => $responseData,
                'response_body' => method_exists($e, 'response') && $e->response() ? $e->response()->body() : null
            ]);
            
            // Check for invalid model ID (400 error) or other model-related issues
            $invalidModelError = false;
            $errorMessage = $e->getMessage();
            
            // Check for model errors in different response formats
            if ($statusCode === 400) {
                if (isset($responseData['error']['message']) && 
                    (strpos($responseData['error']['message'], 'not a valid model') !== false ||
                     strpos($responseData['error']['message'], 'invalid model') !== false ||
                     strpos($responseData['error']['message'], 'Unknown model') !== false)) {
                    $invalidModelError = true;
                }
            }
            
            // Try fallback models for invalid model errors
            if ($invalidModelError) {
                // Check if this is already a fallback attempt to prevent infinite recursion
                $attemptCount = $request->input('fallback_attempt_count', 0);
                $modelId = $request->input('modelId', 'unknown');
                $originalModelId = $request->input('original_model_id', $modelId);
                
                if ($attemptCount < count($this->fallbackModels)) {
                    // Try the next fallback model in sequence
                    $nextFallbackModel = $this->fallbackModels[$attemptCount];
                    $attemptNumber = $attemptCount + 1;
                    Log::info("Using fallback model #{$attemptNumber} ({$nextFallbackModel}) for invalid model error");
                    
                    $fallbackRequest = clone $request;
                    $fallbackRequest->merge([
                        'modelId' => $nextFallbackModel,
                        'is_fallback_attempt' => true,
                        'fallback_attempt_count' => $attemptCount + 1,
                        'original_model_id' => $originalModelId
                    ]);
                    
                    return $this->analyzeImage($fallbackRequest);
                } else {
                    // We've tried all fallbacks, return error with suggestions
                    Log::error("All fallback models failed for invalid model error", [
                        'original_model' => $originalModelId,
                        'attempted_models' => $this->fallbackModels
                    ]);
                    
                    return response()->json([
                        'error' => 'Invalid model',
                        'message' => 'The requested model ID "' . $originalModelId . '" is invalid and all fallback models also failed.',
                        'attempted_models' => array_merge([$originalModelId], $this->fallbackModels),
                        'suggested_models' => $this->fallbackModels,
                        'details' => $errorMessage
                    ], 400);
                }
            }
            
            // Specifically look for "No endpoints found" in any part of the error message or response
            $noEndpointsFound = false;
            
            if (strpos($errorMessage, 'No endpoints found') !== false) {
                $noEndpointsFound = true;
            }
            
            // Also check if it's in the response data structure
            if (isset($responseData['error']['message']) && 
                strpos($responseData['error']['message'], 'No endpoints found') !== false) {
                $noEndpointsFound = true;
            }
            
            // Handle 404 No endpoints found error specifically
            if ($statusCode === 404 && $noEndpointsFound) {
                $modelId = $request->input('modelId', 'unknown');
                $originalModelId = $request->input('original_model_id', $modelId);
                $attemptCount = $request->input('fallback_attempt_count', 0);
                
                if ($attemptCount < count($this->fallbackModels)) {
                    // Try the next fallback model in sequence
                    $nextFallbackModel = $this->fallbackModels[$attemptCount];
                    $attemptNumber = $attemptCount + 1;
                    Log::info("Using fallback model #{$attemptNumber} ({$nextFallbackModel}) for unavailable model: {$modelId}");
                    
                    $fallbackRequest = clone $request;
                    $fallbackRequest->merge([
                        'modelId' => $nextFallbackModel,
                        'is_fallback_attempt' => true,
                        'fallback_attempt_count' => $attemptCount + 1,
                        'original_model_id' => $originalModelId
                    ]);
                    
                    return $this->analyzeImage($fallbackRequest);
                } else {
                    // We've tried all fallbacks, return error with suggestions
                    Log::error("All fallback models failed for unavailable model error", [
                        'original_model' => $originalModelId,
                        'attempted_models' => $this->fallbackModels
                    ]);
                    
                    return response()->json([
                        'error' => 'Model not available',
                        'message' => 'The requested AI model "' . $originalModelId . '" and all fallback models are unavailable through OpenRouter. Please try again later.',
                        'attempted_models' => array_merge([$originalModelId], $this->fallbackModels),
                        'suggested_models' => $this->fallbackModels
                    ], 404);
                }
            }
            
            // Default error response for any other type of exception
            return response()->json([
                'error' => 'Request failed',
                'message' => 'There was an error processing your image analysis request. Please try again or use a different model.',
                'details' => $errorMessage,
                'error_data' => $responseData
            ], $statusCode ?: 500);
        } catch (\Exception $e) {
            // General exception handler for any other unexpected errors
            \Log::error('Unexpected exception in analyzeImage', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'model_id' => $request->input('modelId', 'unknown')
            ]);
            
            return response()->json([
                'error' => 'Service unavailable',
                'message' => 'An unexpected error occurred during image analysis. Please try again later.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process multiple images individually and combine the results
     *
     * @param Request $request The original request object
     * @param array $processedImages Array of processed image data
     * @param string $modelId The AI model to use
     * @param bool $preview_from_chart_on_demand Whether this is a preview
     * @param array $imageUrls Array of Firebase image URLs if available
     * @return \Illuminate\Http\JsonResponse
     */
    private function analyzeMultipleImagesIndividually($request, $processedImages, $modelId, $preview_from_chart_on_demand, $imageUrls = [])
    {
        $results = [];
        $allHistoryIds = [];
        $totalTokenCost = 0;
        $user = Auth::user();
        $userId = Auth::user()->id;
        
        // Pre-check total token cost to ensure user has enough tokens
        $inputTokens = $this->estimatedAnalysisTokens['input'];
        $outputTokens = $this->estimatedAnalysisTokens['output'];
        $addonCost = $preview_from_chart_on_demand == 'true' ? 1.5 : 1;
        $costCalculation = $this->calculateCost($modelId, $inputTokens, $outputTokens, $addonCost);
        
        // Calculate cost for all images
        if (isset($costCalculation['error'])) {
            $singleImageCost = ceil(($inputTokens * 0.0005 + $outputTokens * 0.0015) * 667);
        } else {
            $singleImageCost = ceil($costCalculation['totalCost'] * 667);
        }
        
        $totalCost = $singleImageCost * count($processedImages);
        
        // Check if user has enough tokens
        $tokenBalances = $this->tokenService->getUserTokens($userId);
        $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
        
        if ($totalAvailableTokens < $totalCost) {
            return response()->json([
                'error' => 'Insufficient tokens',
                'message' => 'You do not have enough tokens to analyze ' . count($processedImages) . ' images.',
                'tokens_required' => $totalCost,
                'tokens_available' => $totalAvailableTokens
            ], 403);
        }
        
        Log::info('Beginning individual analysis of ' . count($processedImages) . ' images');
        
        // Process each image individually
        foreach ($processedImages as $index => $image) {
            // Create a modified request with just this image
            $singleImageRequest = clone $request;
            $singleImageRequest->multipleImages = true; // Flag to prevent infinite recursion
            
            // Create single image content
            $userContent = [];
            $userContent[] = [
                'type' => 'text',
                'text' => 'Please analyze this market chart and provide a comprehensive trading strategy analysis. Focus on price action, technical indicators, and potential trading opportunities. If any indicator is not clearly visible, mark it as "Not Visible".'
            ];
            
            // Add the single image in the correct format based on model
            if (strpos($modelId, 'google/gemini') !== false || strpos($modelId, 'anthropic/claude') !== false) {
                $userContent[] = [
                    'type' => 'image',
                    'image' => [
                        'data' => $image['data']
                    ]
                ];
            } else {
                $userContent[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $image['prefix'] . $image['data']
                    ]
                ];
            }
            
            // Create message payload
            $messages = [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt
                ],
                [
                    'role' => 'user',
                    'content' => $userContent
                ]
            ];
            
            $apiKey = env('OPENROUTER_API_KEY');
            $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';
            
            Log::info("Processing image {$index} individually");
            
            try {
                // Make API request for this individual image
                $response = Http::timeout(90)
                    ->withHeaders([
                        'Authorization' => "Bearer $apiKey",
                        'Content-Type' => 'application/json',
                        'X-Title' => 'AI Market Analyst'
                    ])
                    ->retry(3, 5000)
                    ->post($apiUrl, [
                        'model' => $modelId,
                        'max_tokens' => 4000,
                        'temperature' => 0.7,
                        'messages' => $messages
                    ]);
                
                if ($response->successful()) {
                    $data = json_decode($response->body(), true);
                    $assistantReply = $data['choices'][0]['message']['content'] ?? null;
                    
                    // Parse the assistant reply
                    $structuredResponse = null;
                    $jsonError = '';
                    
                    // Try direct JSON decode first
                    $structuredResponse = json_decode($assistantReply, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        Log::info("Successfully parsed JSON response for image {$index}");
                        
                        // Normalize output structure if needed
                        if (isset($structuredResponse['Chart_Analysis'])) {
                            // If we have the current format, transform it to the desired format
                            $transformedResponse = $this->transformAnalysisFormat($structuredResponse);
                            $structuredResponse = $transformedResponse;
                        }
                    } else {
                        $jsonError = json_last_error_msg();
                        
                        // Try to extract JSON using regex
                        if (preg_match('/\{.*\}/s', $assistantReply, $matches)) {
                            $potentialJson = $matches[0];
                            $structuredResponse = json_decode($potentialJson, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE) {
                                Log::info("Successfully extracted and parsed JSON object from response for image {$index}");
                            } else {
                                $jsonError = json_last_error_msg();
                                
                                // Try more aggressive cleanup
                                $cleanedJson = preg_replace('/```(?:json)?\s*|```\s*$/', '', $assistantReply);
                                $cleanedJson = preg_replace('/[\s\S]*?(\{[\s\S]*\})[\s\S]*/', '$1', $cleanedJson);
                                
                                $structuredResponse = json_decode($cleanedJson, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    Log::info("Successfully parsed JSON after aggressive cleanup for image {$index}");
                                } else {
                                    $jsonError = json_last_error_msg();
                                    // Fall back to text parsing
                                    $structuredResponse = $this->parseAnalysisResponse($assistantReply);
                                }
                            }
                        } else {
                            // Fall back to text parsing
                            $structuredResponse = $this->parseAnalysisResponse($assistantReply);
                        }
                    }
                    
                    if ($structuredResponse && (isset($structuredResponse['Symbol']) || isset($structuredResponse['symbol']))) {
                        // Add image index to response
                        $structuredResponse['image_index'] = $index;
                        
                        // Save to history if authenticated
                        if (auth()->check()) {
                            try {
                                // Generate a title based on the symbol and timeframe
                                $symbol = $structuredResponse['Symbol'] ?? $structuredResponse['symbol'] ?? 'Unknown';
                                $timeframe = $structuredResponse['Timeframe'] ?? $structuredResponse['timeframe'] ?? 'Chart';
                                $title = "$symbol $timeframe Analysis (Image " . ($index + 1) . ")";
                                
                                // Create history record
                                $history = \App\Models\History::create([
                                    'user_id' => auth()->id(),
                                    'title' => $title,
                                    'type' => 'chart_analysis',
                                    'model' => $modelId,
                                    'content' => json_encode($structuredResponse),
                                    'chart_urls' => !empty($imageUrls[$index]) ? [$imageUrls[$index]] : [],
                                    'timestamp' => now(),
                                ]);
                                
                                // Add history ID to response
                                $structuredResponse['history_id'] = $history->id;
                                $allHistoryIds[] = $history->id;
                            } catch (\Exception $e) {
                                Log::error("Failed to save analysis to history for image {$index}", [
                                    'error' => $e->getMessage(),
                                    'user_id' => auth()->id()
                                ]);
                            }
                        }
                        
                        // Create shareable content
                        $structuredResponse['share_content'] = $this->createShareableContent($structuredResponse);
                        $results[] = $structuredResponse;
                        
                        // Track token cost
                        $actualInputTokens = ($data['usage']['prompt_tokens'] ?? $inputTokens) * $addonCost;
                        $actualOutputTokens = ($data['usage']['completion_tokens'] ?? $outputTokens) * $addonCost;
                        $actualCostCalculation = $this->calculateCost($modelId, $actualInputTokens, $actualOutputTokens, $addonCost);
                        
                        if (!isset($actualCostCalculation['error'])) {
                            $actualTokenCost = ceil($actualCostCalculation['totalCost'] * 667);
                            $totalTokenCost += $actualTokenCost;
                        } else {
                            $totalTokenCost += $singleImageCost;
                        }
                    } else {
                        Log::error("Failed to parse analysis response for image {$index}", [
                            'response_excerpt' => substr($assistantReply, 0, 200),
                            'structured_response' => $structuredResponse
                        ]);
                        
                        $results[] = [
                            'error' => 'Failed to analyze image ' . ($index + 1),
                            'image_index' => $index
                        ];
                        
                        // Still count the cost
                        $totalTokenCost += $singleImageCost;
                    }
                } else {
                    Log::error("API request failed for image {$index}", [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    
                    $results[] = [
                        'error' => 'API request failed for image ' . ($index + 1),
                        'status' => $response->status(),
                        'image_index' => $index
                    ];
                    
                    // Still count the cost
                    $totalTokenCost += $singleImageCost;
                }
            } catch (\Exception $e) {
                Log::error("Exception processing image {$index}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $results[] = [
                    'error' => 'Failed to process image ' . ($index + 1) . ': ' . $e->getMessage(),
                    'image_index' => $index
                ];
                
                // Still count the cost
                $totalTokenCost += $singleImageCost;
            }
        }
        
        // Deduct tokens
        if ($totalTokenCost > 0 && auth()->check()) {
            // Smart token deduction logic that can split across subscription and addon tokens if needed
            $tokenBalances = $this->tokenService->getUserTokens($userId);
            
            if ($tokenBalances['subscription_token'] >= $totalTokenCost) {
                // If subscription tokens are enough, deduct from there
                $this->tokenService->deductUserTokens(
                    $userId,
                    $totalTokenCost,
                    'multiple_image_analysis',
                    'subscription_token',
                    $modelId,
                    'vision',
                    $inputTokens * count($processedImages),
                    $outputTokens * count($processedImages)
                );
            } elseif ($tokenBalances['addons_token'] >= $totalTokenCost) {
                // If addon tokens are enough, deduct from there
                $this->tokenService->deductUserTokens(
                    $userId,
                    $totalTokenCost,
                    'multiple_image_analysis',
                    'addons_token',
                    $modelId,
                    'vision',
                    $inputTokens * count($processedImages),
                    $outputTokens * count($processedImages)
                );
            } else {
                // Need to split the deduction across both token sources
                $subscriptionDeduction = $this->tokenService->deductUserTokens(
                    $userId,
                    $tokenBalances['subscription_token'],
                    'multiple_image_analysis (partial)',
                    'subscription_token',
                    $modelId,
                    'vision',
                    intval($inputTokens * count($processedImages) * ($tokenBalances['subscription_token'] / $totalTokenCost)),
                    intval($outputTokens * count($processedImages) * ($tokenBalances['subscription_token'] / $totalTokenCost))
                );
                
                // Then deduct the remainder from addon tokens
                $remainingCost = $totalTokenCost - $tokenBalances['subscription_token'];
                $this->tokenService->deductUserTokens(
                    $userId,
                    $remainingCost,
                    'multiple_image_analysis (remainder)',
                    'addons_token',
                    $modelId,
                    'vision',
                    $inputTokens * count($processedImages) - intval($inputTokens * count($processedImages) * ($tokenBalances['subscription_token'] / $totalTokenCost)),
                    $outputTokens * count($processedImages) - intval($outputTokens * count($processedImages) * ($tokenBalances['subscription_token'] / $totalTokenCost))
                );
            }
            
            Log::info('Tokens deducted for multiple image analysis', [
                'total_cost' => $totalTokenCost,
                'image_count' => count($processedImages)
            ]);
        }
        
        // Build combined timeframe string across all analyses (e.g., "M15,H1")
        $combinedTimeframesArr = [];
        foreach ($results as $res) {
            $tf = $res['Timeframe'] ?? ($res['timeframe'] ?? null);
            if ($tf) {
                // Split if model returned already-combined values and normalize
                $parts = preg_split('/\s*,\s*/', strtoupper(trim($tf)));
                foreach ($parts as $p) {
                    if ($p !== '') {
                        $combinedTimeframesArr[] = $p;
                    }
                }
            }
        }
        $combinedTimeframes = implode(',', array_values(array_unique($combinedTimeframesArr)));

        // Optional second-pass: send individual analyses to the model to produce a single consolidated analysis
        $combinedAnalysis = null;
        try {
            if (!empty($results)) {
                $apiKey = env('OPENROUTER_API_KEY');
                $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

                $instruction = "You are an expert market analyst. You are given multiple per-image analyses (one per timeframe).\n\nTask:\n1) Combine them into ONE consolidated analysis that follows the exact same JSON schema we use for a single image result.\n2) Resolve conflicts conservatively and explain key rationale in Technical_Justification or Summary.\n3) Set the Timeframe field to include ALL unique timeframes (e.g., 'M15,H1').\n4) Only output a single JSON object, no prose.\n";

                $combinationPayload = [
                    'source_analyses' => $results,
                    'combined_timeframe' => $combinedTimeframes,
                ];

                $messages = [
                    [ 'role' => 'system', 'content' => $this->systemPrompt ],
                    [ 'role' => 'user', 'content' => [
                        [ 'type' => 'text', 'text' => $instruction ],
                        [ 'type' => 'text', 'text' => 'Here are the individual analyses as JSON:' ],
                        [ 'type' => 'text', 'text' => json_encode($combinationPayload) ],
                    ]],
                ];

                $response = Http::timeout(120)
                    ->withHeaders([
                        'Authorization' => "Bearer $apiKey",
                        'Content-Type' => 'application/json',
                        'X-Title' => 'AI Market Analyst'
                    ])
                    ->retry(2, 6000)
                    ->post($apiUrl, [
                        'model' => $modelId,
                        'max_tokens' => 4000,
                        'temperature' => 0.5,
                        'messages' => $messages
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $assistantReply = $data['choices'][0]['message']['content'] ?? null;
                    if ($assistantReply) {
                        // Prefer direct JSON
                        $combinedAnalysis = json_decode($assistantReply, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            // Try to extract JSON blob
                            if (preg_match('/\{[\s\S]*\}/', $assistantReply, $m)) {
                                $combinedAnalysis = json_decode($m[0], true);
                            }
                        }
                        // If still not JSON, attempt fallback parse, then transform if needed
                        if (!is_array($combinedAnalysis)) {
                            $combinedAnalysis = $this->parseAnalysisResponse($assistantReply);
                        }
                        if (is_array($combinedAnalysis) && isset($combinedAnalysis['Chart_Analysis'])) {
                            $combinedAnalysis = $this->transformAnalysisFormat($combinedAnalysis);
                        }
                        // Ensure timeframe includes all combined timeframes
                        if (is_array($combinedAnalysis)) {
                            $tfKey = isset($combinedAnalysis['Timeframe']) ? 'Timeframe' : (isset($combinedAnalysis['timeframe']) ? 'timeframe' : null);
                            if ($tfKey) {
                                $combinedAnalysis[$tfKey] = $combinedTimeframes ?: ($combinedAnalysis[$tfKey] ?? null);
                            } else {
                                $combinedAnalysis['Timeframe'] = $combinedTimeframes ?: null;
                            }
                            // Attach helpful share_content if missing
                            if (!isset($combinedAnalysis['share_content'])) {
                                $combinedAnalysis['share_content'] = $this->createShareableContent($combinedAnalysis);
                            }
                        }

                        // Track (approximate) extra token cost for the combination pass
                        $actualInputTokens = $data['usage']['prompt_tokens'] ?? $inputTokens;
                        $actualOutputTokens = $data['usage']['completion_tokens'] ?? $outputTokens;
                        $actualCostCalculation = $this->calculateCost($modelId, $actualInputTokens, $actualOutputTokens, $addonCost);
                        if (!isset($actualCostCalculation['error'])) {
                            $totalTokenCost += ceil($actualCostCalculation['totalCost'] * 667);
                        } else {
                            $totalTokenCost += $singleImageCost; // fallback
                        }
                    }
                } else {
                    Log::warning('Combination pass failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Exception during combination pass', [ 'error' => $e->getMessage() ]);
        }



        // Return combined results
        return response()->json([
            'analyses' => $results,
            'count' => count($results),
            'all_history_ids' => $allHistoryIds,
            'token_cost' => $totalTokenCost,
            'combined_timeframe' => $combinedTimeframes !== '' ? $combinedTimeframes : null,
            'combined_analysis' => $combinedAnalysis,
        ]);
    }
    
    /**
     * Transform the analysis data from the current format to the desired format
     * 
     * @param array $data The input data in the current format
     * @return array The transformed data in the desired format
     */
    private function transformAnalysisFormat($data)
    {
        // Initialize the transformed structure
        $transformed = [
            'Symbol' => isset($data['Chart_Analysis']['Chart_Identification']['Symbol/Trading_Pair']) 
                ? $data['Chart_Analysis']['Chart_Identification']['Symbol/Trading_Pair'] 
                : (isset($data['Chart_Analysis']['Symbol/Trading_Pair']) 
                    ? $data['Chart_Analysis']['Symbol/Trading_Pair'] 
                    : null),
            'Timeframe' => isset($data['Chart_Analysis']['Chart_Identification']['Timeframe']) 
                ? $data['Chart_Analysis']['Chart_Identification']['Timeframe'] 
                : (isset($data['Chart_Analysis']['Timeframe']) 
                    ? $data['Chart_Analysis']['Timeframe'] 
                    : null),
            'Current_Price' => isset($data['Chart_Analysis']['Price_Action']['Current_Price']) 
                ? (float)$data['Chart_Analysis']['Price_Action']['Current_Price'] 
                : (isset($data['Chart_Analysis']['Current_Price']) 
                    ? (float)$data['Chart_Analysis']['Current_Price'] 
                    : null),
            'Key_Price_Levels' => [
                'Support_Levels' => isset($data['Chart_Analysis']['Price_Action']['Support_Levels']) 
                    ? $data['Chart_Analysis']['Price_Action']['Support_Levels'] 
                    : (isset($data['Chart_Analysis']['Support_Levels']) 
                        ? $data['Chart_Analysis']['Support_Levels'] 
                        : (isset($data['Chart_Analysis']['Support_Resistance_Levels']['Support']) 
                            ? [$data['Chart_Analysis']['Support_Resistance_Levels']['Support']] 
                            : [])),
                'Resistance_Levels' => isset($data['Chart_Analysis']['Price_Action']['Resistance_Levels']) 
                    ? $data['Chart_Analysis']['Price_Action']['Resistance_Levels'] 
                    : (isset($data['Chart_Analysis']['Resistance_Levels']) 
                        ? $data['Chart_Analysis']['Resistance_Levels'] 
                        : (isset($data['Chart_Analysis']['Support_Resistance_Levels']['Resistance']) 
                            ? [$data['Chart_Analysis']['Support_Resistance_Levels']['Resistance']] 
                            : [])),
            ],
            'Market_Structure' => isset($data['Chart_Analysis']['Market_Structure']) 
                ? $data['Chart_Analysis']['Market_Structure'] 
                : null,
            'Volatility_Conditions' => "Volatility appears moderate, with price movements within a defined range.", // Default value
            'Price_Movement' => "The price has been moving with recent fluctuations.", // Default value
            'Chart_Patterns' => "No clear chart patterns are visible.", // Default value
            'Key_Breakout_Breakdown_Levels' => isset($data['Chart_Analysis']['Price_Action']['Resistance_Levels'][0]) && isset($data['Chart_Analysis']['Price_Action']['Support_Levels'][0]) 
                ? "Breakout above " . $data['Chart_Analysis']['Price_Action']['Resistance_Levels'][0] . " or breakdown below " . $data['Chart_Analysis']['Price_Action']['Support_Levels'][0] 
                : (isset($data['Chart_Analysis']['Support_Resistance_Levels']['Resistance']) && isset($data['Chart_Analysis']['Support_Resistance_Levels']['Support']) 
                    ? "Breakout above " . $data['Chart_Analysis']['Support_Resistance_Levels']['Resistance'] . " or breakdown below " . $data['Chart_Analysis']['Support_Resistance_Levels']['Support'] 
                    : "The key breakout level is at the nearest resistance level. A breakdown below the nearest support level would suggest a potential reversal of the trend."),
            'Volume_Confirmation' => "Volume confirmation is not visible in the chart.", // Default value
            'Trend_Strength_Assessment' => isset($data['Chart_Analysis']['Market_Structure']) 
                ? (strpos(strtolower($data['Chart_Analysis']['Market_Structure']), 'bullish') !== false 
                    ? "The trend strength is assessed as strong in the bullish direction." 
                    : (strpos(strtolower($data['Chart_Analysis']['Market_Structure']), 'bearish') !== false 
                        ? "The trend strength is assessed as strong in the bearish direction." 
                        : "The trend strength is neutral or ranging.")
                  ) 
                : "The trend strength is moderate.",
            'INDICATORS' => [
                'RSI_Indicator' => [
                    'Current_Values' => isset($data['Chart_Analysis']['Key_Indicators']['RSI']) 
                        ? (string)(is_numeric($data['Chart_Analysis']['Key_Indicators']['RSI']) 
                            ? $data['Chart_Analysis']['Key_Indicators']['RSI'] 
                            : $data['Chart_Analysis']['Key_Indicators']['RSI']) 
                        : "50.00",
                    'Signal' => isset($data['Chart_Analysis']['Key_Indicators']['RSI']) 
                        ? ((float)$data['Chart_Analysis']['Key_Indicators']['RSI'] > 70 
                            ? "Overbought" 
                            : ((float)$data['Chart_Analysis']['Key_Indicators']['RSI'] < 30 
                                ? "Oversold" 
                                : "Neutral")
                          ) 
                        : "Neutral",
                    'Analysis' => isset($data['Chart_Analysis']['Key_Indicators']['RSI']) 
                        ? "The RSI is at " . $data['Chart_Analysis']['Key_Indicators']['RSI'] . ", indicating the momentum is " . 
                          ((float)$data['Chart_Analysis']['Key_Indicators']['RSI'] > 70 
                            ? "overbought. This suggests a potential pullback." 
                            : ((float)$data['Chart_Analysis']['Key_Indicators']['RSI'] < 30 
                                ? "oversold. This suggests a potential bounce." 
                                : "neutral. This suggests the trend may continue.")) 
                        : "The RSI is in the neutral zone, indicating that the market is not overbought or oversold. This suggests that the trend may continue."
                ],
                'MACD_Indicator' => [
                    'Current_Values' => isset($data['Chart_Analysis']['Key_Indicators']['MACD']['Value']) 
                        ? (string)$data['Chart_Analysis']['Key_Indicators']['MACD']['Value'] 
                        : (isset($data['Chart_Analysis']['Key_Indicators']['MACD']) && is_string($data['Chart_Analysis']['Key_Indicators']['MACD']) 
                            ? $data['Chart_Analysis']['Key_Indicators']['MACD'] 
                            : "0.000100"),
                    'Signal' => isset($data['Chart_Analysis']['Technical_Justification']) && strpos(strtolower($data['Chart_Analysis']['Technical_Justification']), 'macd') !== false 
                        ? (strpos(strtolower($data['Chart_Analysis']['Technical_Justification']), 'bullish') !== false 
                            ? "Bullish" 
                            : (strpos(strtolower($data['Chart_Analysis']['Technical_Justification']), 'bearish') !== false 
                                ? "Bearish" 
                                : "Neutral")
                          ) 
                        : (isset($data['Chart_Analysis']['Market_Structure']) && strpos(strtolower($data['Chart_Analysis']['Market_Structure']), 'bullish') !== false 
                            ? "Bullish" 
                            : (isset($data['Chart_Analysis']['Market_Structure']) && strpos(strtolower($data['Chart_Analysis']['Market_Structure']), 'bearish') !== false 
                                ? "Bearish" 
                                : "Neutral")),
                    'Analysis' => isset($data['Chart_Analysis']['Technical_Justification']) && strpos(strtolower($data['Chart_Analysis']['Technical_Justification']), 'macd') !== false 
                        ? "The MACD indicates " . 
                          (strpos(strtolower($data['Chart_Analysis']['Technical_Justification']), 'bullish') !== false 
                            ? "a bullish trend. The histogram is positive, suggesting that the trend may continue." 
                            : (strpos(strtolower($data['Chart_Analysis']['Technical_Justification']), 'bearish') !== false 
                                ? "a bearish trend. The histogram is negative, suggesting that the downtrend may continue." 
                                : "a neutral trend. The histogram is close to the zero line, suggesting minimal momentum.")) 
                        : (isset($data['Chart_Analysis']['Market_Structure']) && strpos(strtolower($data['Chart_Analysis']['Market_Structure']), 'bullish') !== false 
                            ? "The MACD is above the signal line, indicating a bullish trend. The histogram is positive, suggesting that the trend may continue." 
                            : (isset($data['Chart_Analysis']['Market_Structure']) && strpos(strtolower($data['Chart_Analysis']['Market_Structure']), 'bearish') !== false 
                                ? "The MACD is below the signal line, indicating a bearish trend. The histogram is negative, suggesting that the downtrend may continue." 
                                : "The MACD shows minimal momentum, suggesting a neutral or ranging market condition.")),
                ],
                'Other_Indicator' => isset($data['Chart_Analysis']['Key_Indicators']['ADX']) || isset($data['Chart_Analysis']['Key_Indicators']['ATR']) || isset($data['Chart_Analysis']['Key_Indicators']['CCI']) 
                    ? "ADX: " . ($data['Chart_Analysis']['Key_Indicators']['ADX'] ?? "Not Visible") . 
                      ", ATR: " . ($data['Chart_Analysis']['Key_Indicators']['ATR'] ?? "Not Visible") . 
                      ", CCI: " . ($data['Chart_Analysis']['Key_Indicators']['CCI'] ?? "Not Visible") 
                    : "Not Visible",
            ],
            'Action' => isset($data['Trade_Strategy']['Action']) 
                ? $data['Trade_Strategy']['Action'] 
                : "HOLD",
            'Entry_Price' => isset($data['Trade_Strategy']['Entry_Price']) 
                ? (float)$data['Trade_Strategy']['Entry_Price'] 
                : (isset($data['Trade_Strategy']['Suggested_Entry']) 
                    ? (float)$data['Trade_Strategy']['Suggested_Entry'] 
                    : (isset($data['Chart_Analysis']['Current_Price']) 
                        ? (float)$data['Chart_Analysis']['Current_Price'] 
                        : null)),
            'Stop_Loss' => isset($data['Trade_Strategy']['Stop_Loss']) 
                ? (float)$data['Trade_Strategy']['Stop_Loss'] 
                : null,
            'Take_Profit' => isset($data['Trade_Strategy']['Take_Profit']) 
                ? (float)$data['Trade_Strategy']['Take_Profit'] 
                : null,
            'Risk_Ratio' => isset($data['Trade_Strategy']['Risk_Ratio']) 
                ? '1:' . $data['Trade_Strategy']['Risk_Ratio'] 
                : '1:1', // Default value
            'Hold_Reason' => isset($data['Trade_Strategy']['Hold_Reason']) 
                ? $data['Trade_Strategy']['Hold_Reason'] 
                : null,
            'Technical_Justification' => isset($data['Chart_Analysis']['Technical_Justification']) 
                ? $data['Chart_Analysis']['Technical_Justification'] 
                : (isset($data['Summary']) 
                    ? $data['Summary'] 
                    : null),
            'Multiple_Timeframe_Context' => isset($data['Multi_Timeframe_Context']) 
                ? (is_string($data['Multi_Timeframe_Context']) 
                    ? $data['Multi_Timeframe_Context'] 
                    : (isset($data['Multi_Timeframe_Context']['Summary']) 
                        ? $data['Multi_Timeframe_Context']['Summary'] 
                        : json_encode($data['Multi_Timeframe_Context']))) 
                : null,
            'Risk_Assessment' => [
                'Position_Size_Calculation' => "Not Visible", // Default value
                'Volatility_Consideration' => isset($data['Chart_Analysis']['Key_Indicators']['ATR']) 
                    ? "Volatility is " . 
                      (((float)$data['Chart_Analysis']['Key_Indicators']['ATR'] > 0.001) 
                        ? "high" 
                        : (((float)$data['Chart_Analysis']['Key_Indicators']['ATR'] > 0.0005) 
                            ? "moderate" 
                            : "low")) . 
                      " as measured by the ATR value of " . $data['Chart_Analysis']['Key_Indicators']['ATR'] . "." 
                    : "Volatility is moderate, with price movements within a defined range.",
                'Invalidation_Scenarios' => isset($data['Chart_Analysis']['Price_Action']['Support_Levels'][0]) 
                    ? "A breakdown below " . $data['Chart_Analysis']['Price_Action']['Support_Levels'][0] . " would suggest a potential reversal of the current market structure." 
                    : "A breakdown below the nearest support level would suggest a potential reversal.",
                'Key_Risk_Levels' => isset($data['Chart_Analysis']['Price_Action']['Support_Levels'][0]) 
                    ? "The key risk level is at " . $data['Chart_Analysis']['Price_Action']['Support_Levels'][0] . ", which is the nearest support level." 
                    : "The key risk levels are at the nearest support and resistance levels.",
            ],
            'Analysis_Confidence' => [
                'Pattern_Clarity_Percent' => 75, // Default value
                'Technical_Alignment_Percent' => 80, // Default value
                'Volume_Confirmation_Percent' => 0, // Default value
                'Signal_Reliability_Percent' => 70, // Default value
                'Confidence_Level_Percent' => 75, // Default value
            ],
            'Analysis_Confidences' => [
                [
                    'Timeframe' => isset($data['Chart_Analysis']['Chart_Identification']['Timeframe']) 
                        ? $data['Chart_Analysis']['Chart_Identification']['Timeframe'] 
                        : (isset($data['Chart_Analysis']['Timeframe']) 
                            ? $data['Chart_Analysis']['Timeframe'] 
                            : null),
                    'Pattern_Clarity_Percent' => 75, // Default value
                    'Technical_Alignment_Percent' => 80, // Default value
                    'Volume_Confirmation_Percent' => 0, // Default value
                    'Signal_Reliability_Percent' => 70, // Default value
                    'Confidence_Level_Percent' => 75, // Default value
                ],
            ],
            'Summary' => isset($data['Summary']) 
                ? $data['Summary'] 
                : null,
        ];
        
        // Generate a proper share_content with the correct symbol and timeframe
        $symbol = $transformed['Symbol'] ?? 'Unknown';
        $timeframe = $transformed['Timeframe'] ?? 'Unknown';
        $action = $transformed['Action'] ?? 'HOLD';
        
        $transformed['share_content'] = "📊 *MARKET ANALYSIS: $symbol $timeframe* 📊\n\n";
        
        switch (strtoupper($action)) {
            case 'BUY':
                $transformed['share_content'] .= "🟢 *SIGNAL:* BUY\n";
                break;
            case 'SELL':
                $transformed['share_content'] .= "🔴 *SIGNAL:* SELL\n";
                break;
            default:
                $transformed['share_content'] .= "⏹️ *SIGNAL:* HOLD\n";
        }
        
        // Add current price
        if (isset($transformed['Current_Price'])) {
            $transformed['share_content'] .= "\n💰 *Price:* " . $transformed['Current_Price'];
        }
        
        // Add footer
        $transformed['share_content'] .= "\n\n✨ *Powered by Decyphers* ✨\nYour AI Trading Assistant";

        return $transformed;
    }
    
    private function parseAnalysisResponse($responseText)
    {
        // 1. Decode HTML entities first
        $responseText = html_entity_decode($responseText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // 2. Remove all asterisks
        $responseText = str_replace('*', '', $responseText);
        // 3. Normalize all Unicode whitespace sequences to a single ASCII space, then trim the whole string
        $responseText = trim(preg_replace('/\s+/u', ' ', $responseText));

        $structured = [
            'symbol' => null,
            'timeframe' => null,
            'current_price' => 0.0, // Default to float 0.0
            'support_levels' => [],
            'resistance_levels' => [],
            'market_structure' => null,
            'volatility' => null,
            'action' => null,
            'entry_price' => 0.0,
            'stop_loss' => 0.0,
            'take_profit' => 0.0,
            'confidence' => null
        ];

        // Helper to robustly trim captured values (Unicode whitespace and control characters)
        $robustTrim = function($value) {
            if ($value === null) return null;
            // Trim Unicode whitespace (Z category) and control characters (C category)
            $trimmedValue = preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', $value);
            // Additionally, for safety, trim standard ASCII whitespace again
            return trim($trimmedValue);
        };

        $extract = function ($pattern, $text) use ($robustTrim) {
            if (preg_match($pattern, $text, $matches)) {
                return $robustTrim($matches[1]);
            }
            return null;
        };

        $extractList = function ($pattern, $text) use ($extract, $robustTrim) {
            // $extract already applies robustTrim to the whole matched list string
            $valueString = $extract($pattern, $text);
            if ($valueString !== null && $valueString !== '') { // Ensure valueString is not empty
                // Split by comma or one or more spaces (already normalized to single spaces).
                // PREG_SPLIT_NO_EMPTY ensures no empty elements from multiple delimiters.
                $items = preg_split('/[\s,]+/', $valueString, -1, PREG_SPLIT_NO_EMPTY);
                
                // Robustly trim each individual item after splitting
                return array_map(function($item) use ($robustTrim) {
                    return $robustTrim($item);
                }, $items);
            }
            return [];
        };

        $structured['symbol'] = $extract('/Symbol(?:\s*\/?\s*Asset)?\s*:\s*([\w\d\/.-]+)/iu', $responseText);
        $structured['timeframe'] = $extract('/Timeframe\s*:\s*([\w\d.-]+)/iu', $responseText);
        // $structured['current_price'] is initialized to 0.0
        $rawCurrentPrice = $extract('/Current_Price\s*:\s*([\d\.,]+)/iu', $responseText);
        if ($rawCurrentPrice !== null) {
            $structured['current_price'] = (float)str_replace(',', '.', $rawCurrentPrice);
        }
        $structured['support_levels'] = $extractList('/Support_Levels\s*:\s*([\d\.,\s]+)/iu', $responseText);
        $structured['resistance_levels'] = $extractList('/Resistance_Levels\s*:\s*([\d\.,\s]+)/iu', $responseText);
        $structured['market_structure'] = $extract('/Market_Structure\s*:\s*(.+?)(?=\s+-\s+\w[\w\s()]*:|\s+###|$)/iu', $responseText);
        $structured['volatility'] = $extract('/(?:Volatility_Conditions|Volatility)\s*:\s*(.+?)(?=\s+-\s+\w[\w\s()]*:|\s+###|$)/iu', $responseText);
        $structured['action'] = $extract('/(?:\d+\.\s*)?Action\s*:\s*(\w+)/iu', $responseText);
        // $structured['entry_price'] is initialized to 0.0
        $rawEntryPrice = $extract('/(?:\d+\.\s*)?Entry_Price\s*:\s*([\d\.]+)/iu', $responseText);
        if ($rawEntryPrice !== null) {
            $structured['entry_price'] = (float)str_replace(',', '.', $rawEntryPrice);
        }
        // $structured['stop_loss'] is initialized to 0.0
        $rawStopLoss = $extract('/(?:\d+\.\s*)?Stop_Loss\s*:\s*([\d\.]+)/iu', $responseText);
        if ($rawStopLoss !== null) {
            $structured['stop_loss'] = (float)str_replace(',', '.', $rawStopLoss);
        }
        // $structured['take_profit'] is initialized to 0.0
        $rawTakeProfit = $extract('/(?:\d+\.\s*)?Take_Profit\s*:\s*([\d\.]+)/iu', $responseText);
        if ($rawTakeProfit !== null) {
            $structured['take_profit'] = (float)str_replace(',', '.', $rawTakeProfit);
        }
        $structured['confidence'] = $extract('/(?:\d+\.\s*)?(?:Confidence Level\s*:\s*|ANALYSIS CONFIDENCE.*?Confidence Level\s*:\s*)\s*(\d+%?)/iu', $responseText);
        if ($structured['confidence']) {
            $structured['confidence'] = preg_replace('/[^\d]/', '', $structured['confidence']); // Keep only digits
            if ($structured['confidence'] !== '') {
                 $structured['confidence'] = (int)$structured['confidence'];
            }
        }

        $structured['risk_assessment'] = $extract('/(?:\d+\.\s*)?(?:Risk Assessment\s*:\s*|ANALYSIS CONFIDENCE.*?Confidence Level\s*:\s*)\s*(\d+%?)/iu', $responseText);
        if ($structured['risk_assessment']) {
            $structured['risk_assessment'] = preg_replace('/[^\d]/', '', $structured['risk_assessment']); // Keep only digits
            if ($structured['risk_assessment'] !== '') {
                 $structured['risk_assessment'] = (int)$structured['risk_assessment'];
            }
        }

        // Clean up extracted text for market_structure and volatility
        if ($structured['market_structure']) {
            $structured['market_structure'] = trim(str_replace(['-', '*'], '', strip_tags($structured['market_structure'])));
        }
        if ($structured['volatility']) {
            $structured['volatility'] = trim(str_replace(['-', '*'], '', strip_tags($structured['volatility'])));
        }

        return $structured;
    }

    // --- EA Code Generation Endpoint ---
    public function generateEA(Request $request)
    {
        // execution time to infinite
        ini_set('max_execution_time', 0);

        $user = Auth::user();
        $userId = Auth::user()->id;

        $modelId = $request->input('modelId', 'deepseek/deepseek-r1-0528:free');
        $messages = $request->input('messages', []);
        $imageData = $request->input('image', null);

        \Log::info('User Request EA Generation', [
            'modelId' => $modelId,
            'messages' => $messages,
            'user_id' => $user->id
        ]);

        $inputTokens = $this->estimatedAnalysisTokens['input'];
        $outputTokens = $this->estimatedAnalysisTokens['output'];
        
        $tokenCost = 0;
        $costCalculation = $this->calculateCost($modelId, $inputTokens, $outputTokens);
        
        // Handle the case when the model is unknown and calculateCost returns an error
        if (isset($costCalculation['error'])) {
            // Use a default cost if model is unknown
            $tokenCost = ceil(($inputTokens * 0.0005 + $outputTokens * 0.0015) * 667);
            Log::warning('Unknown model in cost calculation, using default pricing', [
                'model' => $modelId,
                'error' => $costCalculation['error']
            ]);
        } else {
            $tokenCost = ceil($costCalculation['totalCost'] * 667); // 667 tokens per dollar conversion
        }
        
        // Check if the user has sufficient tokens
        $tokenBalances = $this->tokenService->getUserTokens($userId);
        
        Log::info('Checking token balance for image analysis', [
            'user_id' => $userId,
            'subscription_token' => $tokenBalances['subscription_token'],
            'addons_token' => $tokenBalances['addons_token'],
            'tokens_required' => $tokenCost
        ]);
        
        // Check if there are sufficient tokens in the combined total of subscription_token and addons_token
        $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
        if ($totalAvailableTokens < $tokenCost) {
            return response()->json([
                'error' => 'Insufficient tokens',
                'message' => 'You do not have enough tokens to perform this analysis.',
                'tokens_required' => $tokenCost,
                'subscription_token' => $tokenBalances['subscription_token'],
                'addons_token' => $tokenBalances['addons_token']
            ], 403);
        }

        // If imageData is present, append to user message content
        if ($imageData) {
            foreach ($messages as &$msg) {
                if ($msg['role'] === 'user') {
                    // If content is an array, append image; otherwise, convert to array
                    if (is_array($msg['content'])) {
                        $msg['content'][] = [ 'type' => 'image_url', 'image_url' => [ 'url' => $imageData ] ];
                    } else {
                        $msg['content'] = [
                            [ 'type' => 'text', 'text' => $msg['content'] ],
                            [ 'type' => 'image_url', 'image_url' => [ 'url' => $imageData ] ]
                        ];
                    }
                }
            }
        }

        $apiKey = env('OPENROUTER_API_KEY');
        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

        // Increase timeout to 1 minutes and add retry logic
        $response = Http::timeout(300)
            ->withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
                'X-Title' => 'AI Market Analyst'
            ])
            ->retry(3, 5000) // Retry up to 3 times with 5 second delay between attempts
            ->post($apiUrl, [
                'model' => $modelId,
                'temperature' => 0.7,
                'messages' => $messages
            ]);

        if ($response->failed()) {
            return $this->handleOpenRouterError($response, $modelId);
        }

        $data = $response->json();
        // return ['data' => $data];
        $assistantReply = "";
        $content   = $data['choices'][0]['message']['content']   ?? '';
        $reasoning = $data['choices'][0]['message']['reasoning'] ?? '';

        $assistantReply = trim($content) !== ''
            ? $content                                 
            : (trim($reasoning) !== '' ? $reasoning : null);
        
        if ($assistantReply === null) {
            return response()->json(['error' => 'No assistant reply found in API response.'], 500);
        }
        
        // Save the EA code to history if the user is authenticated
        if (auth()->check()) {
            try {
                // Extract a title from the user's message if possible
                $title = 'EA Code Generation';
                $description = '';
                
                // Try to find the user message to extract a better title
                foreach ($messages as $msg) {
                    if ($msg['role'] === 'user') {
                        // Extract a title from the first few words of the user's message
                        $userContent = is_array($msg['content']) 
                            ? json_encode($msg['content']) 
                            : $msg['content'];
                        
                        // Get the first 50 characters for the title
                        $shortContent = substr($userContent, 0, 50);
                        if (strlen($userContent) > 50) {
                            $shortContent .= '...';
                        }
                        
                        $title = 'EA: ' . $shortContent;
                        $description = $userContent;
                        break;
                    }
                }
                
                // Create the history record
                $history = \App\Models\History::create([
                    'user_id' => auth()->id(),
                    'title' => $title,
                    'type' => 'ea_generation',
                    'model' => $modelId,
                    'content' => json_encode([
                        'code' => $assistantReply, 
                        'description' => $description
                    ]),
                    'chart_urls' => $imageData ? [$imageData] : [],
                    'timestamp' => now(),
                ]);

                // Get actual token counts from the API response if available
                $actualInputTokens = $data['usage']['prompt_tokens'] ?? $inputTokens;
                $actualOutputTokens = $data['usage']['completion_tokens'] ?? $outputTokens;
                
                // Recalculate token cost using actual token counts
                $actualCostCalculation = $this->calculateCost($modelId, $actualInputTokens, $actualOutputTokens);
                if (!isset($actualCostCalculation['error'])) {
                    // Update the token cost using actual usage
                    $actualTokenCost = ceil($actualCostCalculation['totalCost'] * 667); // 667 tokens per dollar
                    
                    if (abs($actualTokenCost - $tokenCost) > 5) {
                        Log::info('Updated token cost based on actual usage', [
                            'estimated_cost' => $tokenCost,
                            'actual_cost' => $actualTokenCost,
                            'difference' => $actualTokenCost - $tokenCost
                        ]);
                        // Update the token cost to use for deduction
                        $tokenCost = $actualTokenCost;
                    }
                }
                
                Log::info('Using actual token counts from API response', [
                    'input_tokens' => $actualInputTokens,
                    'output_tokens' => $actualOutputTokens,
                    'total' => $actualInputTokens + $actualOutputTokens,
                    'token_cost' => $tokenCost
                ]);
                
                // Get latest token balances
                $tokenBalances = $this->tokenService->getUserTokens($userId);
                
                // Smart token deduction logic that can split across subscription and addon tokens if needed
                $deductionResult = false;
                
                if ($tokenBalances['subscription_token'] >= $tokenCost) {
                    // If subscription tokens are enough, deduct from there
                    $deductionResult = $this->tokenService->deductUserTokens(
                        $userId,
                        $tokenCost,
                        'image_analysis',
                        'subscription_token',
                        $modelId,
                        'vision',
                        $actualInputTokens,
                        $actualOutputTokens
                    );
                } elseif ($tokenBalances['addons_token'] >= $tokenCost) {
                    // If addon tokens are enough, deduct from there
                    $deductionResult = $this->tokenService->deductUserTokens(
                        $userId,
                        $tokenCost,
                        'image_analysis',
                        'addons_token',
                        $modelId,
                        'vision',
                        $actualInputTokens,
                        $actualOutputTokens
                    );
                } else {
                    // Need to split the deduction across both token sources
                    // First use all available subscription tokens
                    $subscriptionDeduction = $this->tokenService->deductUserTokens(
                        $userId,
                        $tokenBalances['subscription_token'],
                        'image_analysis (partial)',
                        'subscription_token',
                        $modelId,
                        'vision',
                        intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                        intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
                    );
                    
                    // Then deduct the remainder from addon tokens
                    $remainingCost = $tokenCost - $tokenBalances['subscription_token'];
                    $addonDeduction = $this->tokenService->deductUserTokens(
                        $userId,
                        $remainingCost,
                        'image_analysis (remainder)',
                        'addons_token',
                        $modelId,
                        'vision',
                        $actualInputTokens - intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                        $actualOutputTokens - intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
                    );
                    
                    // Both deductions must succeed
                    $deductionResult = $subscriptionDeduction && $addonDeduction;
                    
                    Log::info('Split token deduction for image analysis', [
                        'user_id' => $userId,
                        'total_cost' => $tokenCost,
                        'subscription_amount' => $tokenBalances['subscription_token'],
                        'addon_amount' => $remainingCost,
                        'success' => $deductionResult
                    ]);
                }
                
                if (!$deductionResult) {
                    Log::warning('Failed to deduct tokens for image analysis', [
                        'user_id' => $userId,
                        'tokens_to_deduct' => $tokenCost
                    ]);
                    // Continue despite failed deduction as we already processed the request
                } else {
                    $updatedBalances = $this->tokenService->getUserTokens($userId);
                    Log::info('Successfully deducted tokens for image analysis', [
                        'user_id' => $userId,
                        'deducted_tokens' => $tokenCost,
                        'remaining_subscription_tokens' => $updatedBalances['subscription_token'],
                        'remaining_addons_tokens' => $updatedBalances['addons_token']
                    ]);
                }

                return response()->json([
                    'code' => $assistantReply,
                    'history_id' => $history->id,
                    'model' => $modelId,
                    'data' => $data,
                    // 'content' => $content,
                    // 'reasoning' => $reasoning,
                ]);
                
            } catch (\Exception $e) {
                // Log the error but don't fail the request
                \Log::error('Failed to save EA code to history', [
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id()
                ]);
                
                // Still return the EA code even if saving to history failed
                return response()->json(['code' => $assistantReply]);
            }
        }
        // If user is not authenticated, just return the EA code
        return response()->json(['code' => $assistantReply]);
    }

    // --- Chat Message Endpoint ---
    public function sendChatMessage(Request $request)
    {
        try {
            // Check if user has an active subscription
            $user = Auth::user();
            $userId = Auth::user()->id;

            if (!$this->hasValidSubscription($user)) {
                return response()->json([
                    'error' => 'Subscription required',
                    'message' => 'This feature requires an active subscription. Please upgrade your account to access AI chat features.',
                    'upgrade_url' => '/api/subscriptions/plans'
                ], 403);
            }
            
            $modelId = $request->input('modelId', "qwen/qwen2.5-vl-72b-instruct:free");
            $messages = $request->input('messages');
            $analysisType = $request->input('analysisType', 'Technical');
            $chartAnalysis = $request->input('chartAnalysis', null);
            $historyId = $request->input('history_id', null);

            $inputTokens = $this->estimatedAnalysisTokens['input'];
            $outputTokens = $this->estimatedAnalysisTokens['output'];
            
            $tokenCost = 0;
            $costCalculation = $this->calculateCost($modelId, $inputTokens, $outputTokens);
            
            // Handle the case when the model is unknown and calculateCost returns an error
            if (isset($costCalculation['error'])) {
                // Use a default cost if model is unknown
                $tokenCost = ceil(($inputTokens * 0.0005 + $outputTokens * 0.0015) * 667);
                Log::warning('Unknown model in cost calculation, using default pricing', [
                    'model' => $modelId,
                    'error' => $costCalculation['error']
                ]);
            } else {
                $tokenCost = ceil($costCalculation['totalCost'] * 667); // 667 tokens per dollar conversion
            }
            
            // Check if the user has sufficient tokens
            $tokenBalances = $this->tokenService->getUserTokens($userId);
            
            Log::info('Checking token balance for image analysis', [
                'user_id' => $userId,
                'subscription_token' => $tokenBalances['subscription_token'],
                'addons_token' => $tokenBalances['addons_token'],
                'tokens_required' => $tokenCost
            ]);
            
            // Check if there are sufficient tokens in the combined total of subscription_token and addons_token
            $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
            if ($totalAvailableTokens < $tokenCost) {
                return response()->json([
                    'error' => 'Insufficient tokens',
                    'message' => 'You do not have enough tokens to perform this analysis.',
                    'tokens_required' => $tokenCost,
                    'subscription_token' => $tokenBalances['subscription_token'],
                    'addons_token' => $tokenBalances['addons_token']
                ], 403);
            }

            // Debug log incoming payload
            \Log::info('Incoming sendChatMessage payload', [
                'modelId' => $modelId,
                'messages' => $messages,
                'analysisType' => $analysisType,
                'chartAnalysis' => $chartAnalysis,
                'history_id' => $historyId
            ]);

            // Defensive validation
            if (!is_array($messages) || empty($messages)) {
                \Log::warning('sendChatMessage: messages is not a non-empty array', ['messages' => $messages]);
                return response()->json(['error' => 'Messages must be a non-empty array.'], 400);
            }
            foreach ($messages as $msg) {
                if (!isset($msg['role'], $msg['content']) || !$msg['role'] || !$msg['content']) {
                    \Log::warning('sendChatMessage: invalid message object', ['msg' => $msg]);
                    return response()->json(['error' => 'Each message must have non-empty role and content.'], 400);
                }
            }

            // Compose message structure
            $payload = [
                'model' => $modelId,
                'max_tokens' => 4000,
                'temperature' => 0.7,
                'messages' => $messages,
                'analysisType' => $analysisType,
                'chartAnalysis' => $chartAnalysis
            ];

            $apiKey = env('OPENROUTER_API_KEY');
            $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

            // Increase timeout to 60 seconds and add retry logic
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json',
                    'X-Title' => 'AI Market Analyst'
                ])
                ->retry(3, 5000) // Retry up to 3 times with 5 second delay between attempts
                ->post($apiUrl, [
                    'model' => $modelId,
                    'max_tokens' => 4000,
                    'temperature' => 0.7,
                    'messages' => $messages
                ]);

            if ($response->failed()) {
                $errorResponse = $this->handleOpenRouterError($response, $modelId);
                if ($response->status() === 404 && $modelId !== 'qwen/qwen2.5-vl-72b-instruct:free') {
                    Log::info('Attempting fallback to qwen/qwen2.5-vl-72b-instruct:free model');
                    
                    // Update the request to use the fallback model
                    $fallbackModelId = 'qwen/qwen2.5-vl-72b-instruct:free';
                    $fallbackResponse = Http::timeout(60)
                        ->withHeaders([
                            'Authorization' => "Bearer $apiKey",
                            'Content-Type' => 'application/json',
                            'X-Title' => 'AI Market Analyst'
                        ])
                        ->retry(2, 3000)
                        ->post($apiUrl, [
                            'model' => $fallbackModelId,
                            'max_tokens' => 4000,
                            'temperature' => 0.7,
                            'messages' => $messages
                        ]);
                        
                    if ($fallbackResponse->successful()) {
                        Log::info('Fallback to Qwen model successful');
                        // Replace the current response object with the fallback response
                        $response = $fallbackResponse;
                    } else {
                        Log::error('Fallback to Qwen model also failed', [
                            'status' => $fallbackResponse->status(),
                            'error' => $fallbackResponse->json() ?: 'No error details'
                        ]);
                        return response()->json(['error' => 'Image analysis failed. The selected AI model is currently unavailable. Please try again later or select a different model.'], 503);
                    }
                } else {
                    return $errorResponse;
                }
            }

            $data = $response->json();
            $assistantReply = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : null;
            if ($assistantReply === null) {
                return response()->json(['error' => 'No assistant reply found in API response.'], 500);
            }
            
            // Save the chat messages to the database if the user is authenticated
            if (auth()->check()) {
                try {
                    // Get or create a chat session
                    $sessionId = $request->input('session_id');
                    $session = null;
                    
                    if ($sessionId) {
                        // Try to find an existing session
                        $session = \App\Models\ChatSession::where('id', $sessionId)
                            ->where('user_id', auth()->id())
                            ->first();
                    }
                    
                    if (!$session) {
                        // Create a new session
                        $session = \App\Models\ChatSession::create([
                            'user_id' => auth()->id(),
                            'status' => 'open',
                            'platform' => $request->header('User-Agent'),
                            'source' => 'web',
                            'started_at' => now(),
                            'last_message_at' => now(),
                        ]);
                    } else {
                        // Update the last_message_at timestamp
                        $session->update(['last_message_at' => now()]);
                    }
                    
                    // Save the user message
                    $userMessage = \App\Models\ChatMessage::create([
                        'chat_session_id' => $session->id,
                        'user_id' => auth()->id(),
                        'history_id' => $historyId, // Link to the history record if provided
                        'sender' => 'user',
                        'status' => 'sent',
                        'text' => is_array($messages[count($messages) - 1]['content']) 
                            ? json_encode($messages[count($messages) - 1]['content']) 
                            : $messages[count($messages) - 1]['content'],
                        'metadata' => [
                            'model' => $modelId,
                            'analysis_type' => $analysisType,
                            'chart_analysis' => $chartAnalysis
                        ],
                        'timestamp' => now(),
                    ]);
                
                // Save the assistant's reply
                $assistantMessage = \App\Models\ChatMessage::create([
                    'chat_session_id' => $session->id,
                    'user_id' => auth()->id(),
                    'history_id' => $historyId, // Link to the history record if provided
                    'sender' => 'assistant',
                    'status' => 'sent',
                    'text' => $assistantReply,
                    'metadata' => [
                        'model' => $modelId,
                        'token_usage' => $data['usage'] ?? null,
                        'analysis_type' => $analysisType
                    ],
                    'timestamp' => now(),
                ]);
            } catch (\Exception $e) {
                // Log the error but don't fail the request
                \Log::error('Failed to save chat messages to database: ' . $e->getMessage(), [
                    'user_id' => auth()->id(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Get actual token counts from the API response if available
        $actualInputTokens = $data['usage']['prompt_tokens'] ?? $inputTokens;
        $actualOutputTokens = $data['usage']['completion_tokens'] ?? $outputTokens;
        
        // Recalculate token cost using actual token counts
        $actualCostCalculation = $this->calculateCost($modelId, $actualInputTokens, $actualOutputTokens);
        if (!isset($actualCostCalculation['error'])) {
            // Update the token cost using actual usage
            $actualTokenCost = ceil($actualCostCalculation['totalCost'] * 667); // 667 tokens per dollar
            
            if (abs($actualTokenCost - $tokenCost) > 5) {
                Log::info('Updated token cost based on actual usage', [
                    'estimated_cost' => $tokenCost,
                    'actual_cost' => $actualTokenCost,
                    'difference' => $actualTokenCost - $tokenCost
                ]);
                // Update the token cost to use for deduction
                $tokenCost = $actualTokenCost;
            }
        }
        
        Log::info('Using actual token counts from API response', [
            'input_tokens' => $actualInputTokens,
            'output_tokens' => $actualOutputTokens,
            'total' => $actualInputTokens + $actualOutputTokens,
            'token_cost' => $tokenCost
        ]);
        
        // Get latest token balances
        $tokenBalances = $this->tokenService->getUserTokens($userId);
        
        // Smart token deduction logic that can split across subscription and addon tokens if needed
        $deductionResult = false;
        
        if ($tokenBalances['subscription_token'] >= $tokenCost) {
            // If subscription tokens are enough, deduct from there
            $deductionResult = $this->tokenService->deductUserTokens(
                $userId,
                $tokenCost,
                'image_analysis',
                'subscription_token',
                $modelId,
                'vision',
                $actualInputTokens,
                $actualOutputTokens
            );
        } elseif ($tokenBalances['addons_token'] >= $tokenCost) {
            // If addon tokens are enough, deduct from there
            $deductionResult = $this->tokenService->deductUserTokens(
                $userId,
                $tokenCost,
                'image_analysis',
                'addons_token',
                $modelId,
                'vision',
                $actualInputTokens,
                $actualOutputTokens
            );
        } else {
            // Need to split the deduction across both token sources
            // First use all available subscription tokens
            $subscriptionDeduction = $this->tokenService->deductUserTokens(
                $userId,
                $tokenBalances['subscription_token'],
                'image_analysis (partial)',
                'subscription_token',
                $modelId,
                'vision',
                intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
            );
            
            // Then deduct the remainder from addon tokens
            $remainingCost = $tokenCost - $tokenBalances['subscription_token'];
            $addonDeduction = $this->tokenService->deductUserTokens(
                $userId,
                $remainingCost,
                'image_analysis (remainder)',
                'addons_token',
                $modelId,
                'vision',
                $actualInputTokens - intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                $actualOutputTokens - intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
            );
            
            // Both deductions must succeed
            $deductionResult = $subscriptionDeduction && $addonDeduction;
            
            Log::info('Split token deduction for image analysis', [
                'user_id' => $userId,
                'total_cost' => $tokenCost,
                'subscription_amount' => $tokenBalances['subscription_token'],
                'addon_amount' => $remainingCost,
                'success' => $deductionResult
            ]);
        }
        
        if (!$deductionResult) {
            Log::warning('Failed to deduct tokens for image analysis', [
                'user_id' => $userId,
                'tokens_to_deduct' => $tokenCost
            ]);
            // Continue despite failed deduction as we already processed the request
        } else {
            $updatedBalances = $this->tokenService->getUserTokens($userId);
            Log::info('Successfully deducted tokens for image analysis', [
                'user_id' => $userId,
                'deducted_tokens' => $tokenCost,
                'remaining_subscription_tokens' => $updatedBalances['subscription_token'],
                'remaining_addons_tokens' => $updatedBalances['addons_token']
            ]);
        }
        
        // Return a consistent response format
        return response()->json([
            'assistant' => $assistantReply,
            'reply' => $assistantReply,
            'metadata' => [
                'model' => $modelId,
                'token_usage' => $data['usage'] ?? null,
            ],
            'session_id' => isset($session) ? $session->id : null
        ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Specific handling for HTTP client exceptions
            \Log::error('HTTP Client Exception in sendChatMessage', [
                'exception' => $e->getMessage(),
                'model_id' => $request->input('modelId', 'unknown'),
                'status_code' => $e->getCode(),
                'response' => method_exists($e, 'response') ? $e->response() : null
            ]);
            
            $statusCode = $e->getCode() ?: 500;
            $responseData = [];
            
            try {
                // Try to parse the response if available
                if (method_exists($e, 'response') && $e->response()) {
                    $responseData = json_decode($e->response()->body(), true) ?: [];
                }
            } catch (\Exception $jsonEx) {
                \Log::error('Failed to parse error response', ['exception' => $jsonEx->getMessage()]);
            }
            
            // Log the response data to help with debugging
            \Log::debug('OpenRouter API response data in error handler', [
                'status_code' => $statusCode,
                'response_data' => $responseData,
                'response_body' => method_exists($e, 'response') && $e->response() ? $e->response()->body() : null
            ]);
            
            // Get the error message from the exception or response
            $errorMessage = $e->getMessage();
            
            // Specifically look for "No endpoints found" in any part of the error message or response
            $noEndpointsFound = false;
            
            if (strpos($errorMessage, 'No endpoints found') !== false) {
                $noEndpointsFound = true;
            }
            
            // Also check if it's in the response data structure
            if (isset($responseData['error']['message']) && 
                strpos($responseData['error']['message'], 'No endpoints found') !== false) {
                $noEndpointsFound = true;
            }
            
            // Handle 404 No endpoints found error specifically
            if ($statusCode === 404 && $noEndpointsFound) {
                $modelId = $request->input('modelId', 'unknown');
                $fallbackModels = [
                    'openai/gpt-4o-mini',
                    'openai/gpt-3.5-turbo',
                    'google/gemini-2.0-flash-001',
                    'anthropic/claude-3-haiku'
                ];
                
                return response()->json([
                    'error' => 'Model not available',
                    'message' => 'The requested AI model "' . $modelId . '" is not available through OpenRouter. Please try one of the suggested models instead.',
                    'suggested_models' => $fallbackModels
                ], 404);
            }
            
            // Default error response for any other type of exception
            return response()->json([
                'error' => 'Request failed',
                'message' => 'There was an error processing your request. Please try again or use a different model.',
                'details' => $e->getMessage(),
                'error_data' => $responseData
            ], $statusCode ?: 500);
        } catch (\Exception $e) {
            // General exception handler for any other unexpected errors
            \Log::error('Unexpected exception in sendChatMessage', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'model_id' => $request->input('modelId', 'unknown')
            ]);
            
            return response()->json([
                'error' => 'Service unavailable',
                'message' => 'An unexpected error occurred. Please try again later.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    // -- Get chatbot history ---
    public function getChatbotHistory(Request $request) {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in'
            ]);
        }

        $data = ChatbotMessage::where('user_id', $user->id)->get();

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    // --- AI Chatbot function ---
    public function chatbot(Request $request) {
        ini_set('max_execution_time', '0');
        $user = Auth::user();
        $sender = $request->sender;
        $message = $request->message;
        $timestamp = $request->timestamp;
        $modelId = 'mistralai/mistral-small-3.2-24b-instruct:free';

        $user_data = json_encode(User::where('id', $user->id)->with(['role', 'purchases', 'referrer', 'referrals', 'referredBy', 'subscription', 'subscriptions'])->first());

        // get schema data
        $analyticsData = $this->getAnalyticsFromDatabase($user->id);
            
        $schema_info = json_encode($analyticsData['schema_info']);    
        $query_data = json_encode($analyticsData['data']);                 
        
        $userChatbotMessage = ChatbotMessage::create([
            'user_id' => $user->id,
            'sender' => $sender,
            'text' => $message,
            'metadata' => [
                'model' => $modelId,
            ],
            'timestamp' => date('Y-m-d H:i:s', strtotime($timestamp)),
        ]);

        $data = json_encode($this->getPreparedData());
        $messages = [
            [
                'role' => 'system', 
                'content' => "
                    You are a helpful AI assistant designed to answer user questions about a trading platform and its features. Your responses should be clear, concise, and informative.
                    Here is the user data:
                    $user_data
                    Here is the database data:
                    $data
                    Here is the database schema information:
                    $schema_info
                    And here is some pre-fetched data to help with common queries:
                    $query_data
        

                    Scope of Allowed Questions:
                    You can answer questions about:

                    The EA Generator: Explain what it is, how it works, its features, and how to use it.

                    The Chart_Analysis: Explain what it is, how it works, its features, and how to use it.

                    The Chart Discussion: Explain what it is, how it works, its features, and how to use it.

                    Token Usage: Explain how token usage works for image analysis, how tokens are consumed, and how users can get more tokens.

                    Account Management: Answer questions about account creation, password reset, and other basic account queries.
                    
                    System and Product Features: Explain various functionalities of the platform, such as different strategies, plans, and any changes in the product.
                    
                    User History Data – If the user asks about their past activity, token usage records, or analysis history, retrieve relevant details from $user or $data and present them clearly.
                    If the user asks about token usage, refer to total_tokens instead

                    Reply should not include ai models used

                    What You Should NOT Answer
                    Sensitive Questions – Never answer:

                    How many users are in the system.

                    API keys for the AI model.

                    Internal architecture or confidential data.

                    External Resources – Do not answer questions that require data from sources outside the given context.

                    Off-topic or Vague Questions – If unrelated to the product or unclear, politely say it’s out of scope or suggest contacting support.

                    Function Execution Requests – If the user asks you to actually run Chart_Analysis, Chart Discussion, or EA Generator, do not perform the task. Instead, reply:

                    “You can use this function directly in the [Chart_Analysis / Chart Discussion / EA Generator] page.”

                    AI Model Information – Do not mention or describe the AI models used.

                    Response Behavior
                    Known Questions – Give direct, clear answers using line breaks \n for readability.

                    Token Usage / History – Use total_tokens from $user_data and provide a clear breakdown if available.

                    Step-by-Step Guidance – Use numbered steps:

                    Step one

                    Step two

                    Unknown or Out-of-Scope – Reply with:
                    Sorry, I cannot provide that information.
                    That's beyond my scope. Please contact support for assistance.
                    Answer Format
                    Factual Query – Clear, concise, formatted for readability. Do not include any comment and explaination.

                    How-to Query – Step-by-step numbered list.

                    User-Specific Data – Use $user_data or $data for personalized answers.

                    End Note – Always append:

                    If you require support from a real user admin, please submit a ticket for your issue.

                    Chart Discussion Description
                    Chart Discussion is a helper that answers user questions about Chart_Analysis results. It is available only after Chart_Analysis has returned data.
                    It outputs the following data:

                    {
                        'Symbol': 'string_or_null',
                        'Timeframe': 'string_or_null', 
                        'Current_Price': 'float_or_null',
                        'Key_Price_Levels': {
                            'Support_Levels': ['float_or_null', '...'],
                            'Resistance_Levels': ['float_or_null', '...']
                        },
                        'Market_Structure': 'string_or_null',
                        'Volatility_Conditions': 'string_or_null',
                        'Price_Movement': 'string_or_null', 
                        'Chart_Patterns': 'string_or_null',
                        'Key_Breakout_Breakdown_Levels': 'string_or_null',
                        'Volume_Confirmation': 'string_or_null',
                        'Trend_Strength_Assessment': 'string_or_null',
                        'INDICATORS': {
                            'RSI_Indicator': {
                                'Current_Values': 'string_or_null',
                                'Signal': 'string_or_null', 
                                'Analysis': 'string_or_null'
                            },
                            'MACD_Indicator': {
                                'Current_Values': 'string_or_null',
                                'Signal': 'string_or_null',
                                'Analysis': 'string_or_null'
                            },
                            'Other_Indicator': 'string_or_null'
                        },
                        'Action': 'BUY, SELL, or HOLD',
                        'Entry_Price': 'float_or_dash',
                        'Stop_Loss': 'float_or_dash', 
                        'Take_Profit': 'float_or_dash',
                        'Risk_Ratio': 'string_or_dash',
                        'Hold_Reason': 'string_or_null',
                        'Technical_Justification': 'string_or_null',
                        'Multiple_Timeframe_Context': 'string_or_null',
                        'Risk_Assessment': {
                            'Position_Size_Calculation': 'string_or_null',
                            'Volatility_Consideration': 'string_or_null', 
                            'Invalidation_Scenarios': 'string_or_null',
                            'Key_Risk_Levels': 'string_or_null'
                        },
                        'Analysis_Confidence': {
                            'Pattern_Clarity_Percent': 'integer_0_to_100_or_null',
                            'Technical_Alignment_Percent': 'integer_0_to_100_or_null',
                            'Volume_Confirmation_Percent': 'integer_0_to_100_or_null',
                            'Signal_Reliability_Percent': 'integer_0_to_100_or_null',
                            'Confidence_Level_Percent': 'integer_0_to_100_or_null'
                        },
                        'Analysis_Confidences': [
                            {
                                'Timeframe': 'string_or_null',
                                'Pattern_Clarity_Percent': 'integer_0_to_100_or_null', 
                                'Technical_Alignment_Percent': 'integer_0_to_100_or_null',
                                'Volume_Confirmation_Percent': 'integer_0_to_100_or_null',
                                'Signal_Reliability_Percent': 'integer_0_to_100_or_null',
                                'Confidence_Level_Percent': 'integer_0_to_100_or_null'
                            }
                        ]
                    }
                    Chart Discussion Description:
                    Chart Discussion is a helpful assistant that lets the user ask questions about the data returned by Chart_Analysis.
                    Chart Discussion will only be available after Chart_Analysis returns data.
                "
            ],
            [
                'role' => 'user',
                'content' => $message
            ]
        ];

        $payload = [
            'model' => $modelId,
            // 'max_tokens' => 4000,
            // 'temperature' => 0.7,
            'messages' => $messages,
        ];

        $apiKey = env('OPENROUTER_API_KEY');
        $apiUrl = 'https://openrouter.ai/api/v1/chat/completions';

        $response = Http::timeout(600)
            ->withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
                'X-Title' => 'AI Chatbot'
            ])
            ->retry(3, 5000)
            ->post($apiUrl, $payload);
        if ($response->failed()) {
            $errorResponse = $this->handleOpenRouterError($response, $modelId);
            if ($response->status() === 404 && $modelId !== 'deepseek/deepseek-r1-0528-qwen3-8b:free') {
                Log::info('Attempting fallback to deepseek/deepseek-r1-0528-qwen3-8b:free model');
                
                // Update the request to use the fallback model
                $fallbackModelId = 'deepseek/deepseek-r1-0528-qwen3-8b:free';
                $fallbackResponse = Http::timeout(60)
                    ->withHeaders([
                        'Authorization' => "Bearer $apiKey",
                        'Content-Type' => 'application/json',
                        'X-Title' => 'AI Market Analyst'
                    ])
                    ->retry(2, 3000)
                    ->post($apiUrl, [
                        'model' => $fallbackModelId,
                        'max_tokens' => 4000,
                        'temperature' => 0.7,
                        'messages' => $messages
                    ]);
                    
                if ($fallbackResponse->successful()) {
                    Log::info('Fallback to Deepseek model successful');
                    // Replace the current response object with the fallback response
                    $response = $fallbackResponse;
                } else {
                    Log::error('Fallback to Deepseek model also failed', [
                        'status' => $fallbackResponse->status(),
                        'error' => $fallbackResponse->json() ?: 'No error details'
                    ]);
                    return response()->json(['error' => 'The selected AI model is currently unavailable. Please try again later or select a different model.'], 503);
                }
            } else {
                return $errorResponse;
            }
        }

        $data = $response->json();
        
        $assistantReply = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : null;
        if ($assistantReply === null) {
            return response()->json(['error' => 'No assistant reply found in API response.'], 500);
        }

        // save record to ChatbotMessage
        $aiResponseMessage = ChatbotMessage::create([
            'user_id' => $user->id,
            'sender' => 'ai',
            'text' => $assistantReply,
            'metadata' => [
                'model' => $modelId,
                'token_usage' => $data['usage'] ?? null,
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        
        return response()->json([
            'code' => $assistantReply,
            'data' => $data,
        ]);
    }

    // --- AI Educator ---
    public function aiEducator(Request $request) {
        
    }

    // --- Cost Calculation Endpoint ---
    public function calculateCostEndpoint(Request $request)
    {
        $model = $request->input('model', 'openai/gpt-4o-mini');
        $inputTokens = (int) $request->input('inputTokens', 1000);
        $outputTokens = (int) $request->input('outputTokens', 2000);

        $cost = $this->calculateCost($model, $inputTokens, $outputTokens);

        return response()->json([
            'model' => $model,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'cost' => $cost
        ]);
    }

    // --- Prepared data for chat AI ---
    private function getPreparedData() {
        $data = [];
        if (Schema::hasTable('token_usage')) {
            $data['token_usage'] = [
                'total_count' => DB::table('token_usage')->count(),
                'recent_usage' => DB::table('token_usage')
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->toArray()
            ];
            
            // Only try to aggregate by model if both columns exist
            $columns = DB::getSchemaBuilder()->getColumnListing('token_usage');
            if (in_array('model', $columns) && in_array('total_tokens', $columns)) {
                $data['token_usage']['usage_by_model'] = DB::table('token_usage')
                    ->select('model', DB::raw('SUM(total_tokens) as tokens_used'))
                    ->groupBy('model')
                    ->orderBy('tokens_used', 'desc')
                    ->get()
                    ->toArray();
            }
        } 

        if (Schema::hasTable('plans')) {
            $data['plans'] = DB::table('plans')->get();
        } 

        return $data;
    }

    /**
     * Get analytics data from database
     * 
     * @return array
     */
    private function getAnalyticsFromDatabase($user_id): array
    {
        try {
            // Log the start of database analytics collection
            \Log::info('Collecting analytics data from database');
            
            // Get database schema information
            $schemaInfo = $this->getDatabaseSchema();
            
            // Get common analytics data
            $analyticsData = [
                'schema_info' => $schemaInfo,
                'data' => $this->getCommonAnalyticsData($user_id)
            ];
            
            // Log the data we're sending to OpenRouter for debugging
            \Log::info('Analytics data retrieved from database', [
                'schema_count' => count($schemaInfo),
                'data_sections' => array_keys($analyticsData['data'])
            ]);
            
            return $analyticsData;
        } catch (\Exception $e) {
            \Log::error('Database analytics error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'error' => 'Could not retrieve database analytics data: ' . $e->getMessage(),
                'total_users' => 'unknown',
                'data_source' => 'error',
                'timestamp' => now()->toIso8601String()
            ];
        }
    }
    
    /**
     * Get the complete database schema information
     * 
     * @return array
     */
    private function getDatabaseSchema(): array
    {
        // Get all tables in the database using raw query instead of Schema::getAllTables()
        $tables = DB::select('SHOW TABLES');
        $schemaInfo = [];
        
        foreach ($tables as $table) {
            // Get the table name from the first property of the object
            $tableArray = (array)$table;
            $tableName = array_values($tableArray)[0];
            
            if ($tableName) {
                // Get columns for this table using DESCRIBE instead of Doctrine
                $columns = DB::select("DESCRIBE `{$tableName}`");
                $columnDetails = [];
                
                foreach ($columns as $column) {
                    $columnDetails[$column->Field] = [
                        'type' => $column->Type,
                        'nullable' => $column->Null === 'YES',
                        'key' => $column->Key,
                        'default' => $column->Default
                    ];
                }
                
                // Get record count
                $count = DB::table($tableName)->count();
                
                // Add to schema info
                $schemaInfo[$tableName] = [
                    'columns' => $columnDetails,
                    'record_count' => $count
                ];
            }
        }
        
        return $schemaInfo;
    }
    
    /**
     * Get common analytics data from various tables
     * 
     * @return array
     */
    private function getCommonAnalyticsData($user_id): array
    {
        $data = [];
        
        // Get histories data if table exists
        if (Schema::hasTable('histories')) {
            $data['histories'] = [
                'total_count' => DB::table('histories')->count(),
                'by_type' => DB::table('histories')
                    ->select('type', DB::raw('count(*) as count'))
                    ->where('user_id', $user_id)
                    ->groupBy('type')
                    ->get()
                    ->toArray(),
                'recent_entries' => DB::table('histories')
                    ->where('user_id', $user_id)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->toArray()
            ];
        }
        
        // Get token histories if table exists
        try {
            if (Schema::hasTable('token_histories')) {
                $data['token_histories'] = [
                    'total_count' => DB::table('token_histories')->count(),
                    'recent_activities' => DB::table('token_histories')
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray()
                ];
                
                // Only try to get usage by type if the 'type' column exists
                $columns = DB::getSchemaBuilder()->getColumnListing('token_histories');
                if (in_array('type', $columns) && in_array('tokens', $columns)) {
                    $data['token_histories']['usage_by_type'] = DB::table('token_histories')
                        ->select('type', DB::raw('SUM(tokens) as total_tokens'))
                        ->where('user_id', $user_id)
                        ->groupBy('type')
                        ->orderBy('total_tokens', 'desc')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting token_histories data: ' . $e->getMessage());
        }
        
        // Get token usage if table exists
        try {
            if (Schema::hasTable('token_usage')) {
                $data['token_usage'] = [
                    'total_count' => DB::table('token_usage')->count(),
                    'recent_usage' => DB::table('token_usage')
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray()
                ];
                
                // Only try to aggregate by model if both columns exist
                $columns = DB::getSchemaBuilder()->getColumnListing('token_usage');
                if (in_array('model', $columns) && in_array('total_tokens', $columns)) {
                    $data['token_usage']['usage_by_model'] = DB::table('token_usage')
                        ->select('model', DB::raw('SUM(total_tokens) as tokens_used'))
                        ->where('user_id', $user_id)
                        ->groupBy('model')
                        ->orderBy('tokens_used', 'desc')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting token_usage data: ' . $e->getMessage());
        }
        
        // Get referral statistics if table exists
        try {
            if (Schema::hasTable('referrals')) {
                $data['referrals'] = [
                    'total_count' => DB::table('referrals')->count(),
                    'recent_referrals' => DB::table('referrals')
                        ->where('referral_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray()
                ];
                
                // Only add user-related referral data if the columns exist in users table
                $userColumns = DB::getSchemaBuilder()->getColumnListing('users');
                if (in_array('referral_count', $userColumns) && in_array('referral_code', $userColumns)) {
                    $data['referrals']['top_referrers'] = User::where('referral_count', '>', 0)
                        ->orderBy('referral_count', 'desc')
                        ->select('id', 'name', 'email', 'referral_count', 'referral_code')
                        ->where('referral_id', $user_id)
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting referrals data: ' . $e->getMessage());
        }
        
        // Get histories data if table exists
        try {
            if (Schema::hasTable('histories')) {
                $data['histories'] = [
                    'total_count' => DB::table('histories')->count(),
                    'recent_activities' => DB::table('histories')
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray()
                ];
                
                // Check if the type column exists before using it
                $columns = DB::getSchemaBuilder()->getColumnListing('histories');
                if (in_array('type', $columns)) {
                    $data['histories']['by_type'] = DB::table('histories')
                        ->select('type', DB::raw('count(*) as count'))
                        ->where('user_id', $user_id)
                        ->groupBy('type')
                        ->get()
                        ->toArray();
                }
                
                // Check if user_id column exists before joining
                if (in_array('user_id', $columns)) {
                    $data['histories']['user_activity_counts'] = DB::table('histories')
                        ->join('users', 'histories.user_id', '=', 'users.id')
                        ->select('users.id', 'users.name', DB::raw('count(*) as activity_count'))
                        ->where('user_id', $user_id)
                        ->groupBy('users.id', 'users.name')
                        ->orderBy('activity_count', 'desc')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting histories data: ' . $e->getMessage());
        }
        
        // Get chat data if tables exist
        if (Schema::hasTable('chat_sessions') && Schema::hasTable('chat_messages')) {
            $data['chat'] = [
                'total_sessions' => DB::table('chat_sessions')->count(),
                'total_messages' => DB::table('chat_messages')->count(),
                'messages_by_sender' => DB::table('chat_messages')
                    ->select('sender', DB::raw('count(*) as count'))
                    ->where('user_id', $user_id)
                    ->groupBy('sender')
                    ->get()
                    ->toArray(),
                'recent_messages' => DB::table('chat_messages')
                    ->where('user_id', $user_id)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->toArray()
            ];
        }
        
        // Get payment/transaction data if table exists
        if (Schema::hasTable('transactions') || Schema::hasTable('payments')) {
            $tableName = Schema::hasTable('transactions') ? 'transactions' : 'payments';
            $data['payments'] = [
                'total_count' => DB::table($tableName)->count(),
                'total_amount' => DB::table($tableName)->sum('amount') ?? 0,
                'recent_transactions' => DB::table($tableName)
                    ->where('user_id', $user_id)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->toArray()
            ];
        }
        
        // Get email campaign data if table exists
        try {
            if (Schema::hasTable('email_campaigns')) {
                $data['email_campaigns'] = [
                    'total_count' => DB::table('email_campaigns')->count()
                ];
                
                // Check if created_at column exists
                $campaignColumns = DB::getSchemaBuilder()->getColumnListing('email_campaigns');
                if (in_array('created_at', $campaignColumns)) {
                    $data['email_campaigns']['recent_campaigns'] = DB::table('email_campaigns')
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray();
                }
                
                // Get campaign statistics if available
                if (Schema::hasTable('campaign_stats')) {
                    $statsColumns = DB::getSchemaBuilder()->getColumnListing('campaign_stats');
                    $data['email_campaigns']['stats'] = [];
                    
                    if (in_array('sent', $statsColumns)) {
                        $data['email_campaigns']['stats']['total_sent'] = DB::table('campaign_stats')->sum('sent') ?? 0;
                    }
                    
                    if (in_array('opened', $statsColumns)) {
                        $data['email_campaigns']['stats']['total_opened'] = DB::table('campaign_stats')->sum('opened') ?? 0;
                    }
                    
                    if (in_array('clicked', $statsColumns)) {
                        $data['email_campaigns']['stats']['total_clicked'] = DB::table('campaign_stats')->sum('clicked') ?? 0;
                    }
                }
                
                // Get email templates data
                if (Schema::hasTable('email_templates')) {
                    $data['email_templates'] = [
                        'total_count' => DB::table('email_templates')->count()
                    ];
                    
                    $templateColumns = DB::getSchemaBuilder()->getColumnListing('email_templates');
                    $selectColumns = ['id'];
                    if (in_array('name', $templateColumns)) $selectColumns[] = 'name';
                    if (in_array('subject', $templateColumns)) $selectColumns[] = 'subject';
                    
                    if (count($selectColumns) > 1) {
                        $data['email_templates']['templates_list'] = DB::table('email_templates')
                            ->select($selectColumns)
                            ->get()
                            ->toArray();
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting email campaign data: ' . $e->getMessage());
        }
        
        // Get support ticket data if table exists
        try {
            if (Schema::hasTable('support_tickets')) {
                $data['support_tickets'] = [
                    'total_count' => DB::table('support_tickets')->count()
                ];
                
                $ticketColumns = DB::getSchemaBuilder()->getColumnListing('support_tickets');
                
                if (in_array('status', $ticketColumns)) {
                    $data['support_tickets']['by_status'] = DB::table('support_tickets')
                        ->select('status', DB::raw('count(*) as count'))
                        ->where('user_id', $user_id)
                        ->groupBy('status')
                        ->get()
                        ->toArray();
                }
                
                if (in_array('created_at', $ticketColumns)) {
                    $data['support_tickets']['recent_tickets'] = DB::table('support_tickets')
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray();
                }
                
                if (Schema::hasTable('ticket_replies')) {
                    $data['support_tickets']['replies'] = [
                        'total_count' => DB::table('ticket_replies')->count()
                    ];
                    
                    $replyColumns = DB::getSchemaBuilder()->getColumnListing('ticket_replies');
                    if (in_array('ticket_id', $replyColumns)) {
                        $data['support_tickets']['replies']['avg_replies_per_ticket'] = DB::table('ticket_replies')
                            ->select(DB::raw('COUNT(*) / COUNT(DISTINCT ticket_id) as avg_replies'))
                            ->first()
                            ->avg_replies ?? 0;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting support ticket data: ' . $e->getMessage());
        }
        
        // Get audit logs if table exists
        try {
            if (Schema::hasTable('audit_logs')) {
                $data['audit_logs'] = [
                    'total_count' => DB::table('audit_logs')->count()
                ];
                
                $auditColumns = DB::getSchemaBuilder()->getColumnListing('audit_logs');
                if (in_array('created_at', $auditColumns)) {
                    $data['audit_logs']['recent_logs'] = DB::table('audit_logs')
                        ->where('user_id', $user_id)
                        ->orderBy('created_at', 'desc')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting audit logs data: ' . $e->getMessage());
        }
        
        // Get subscription data if table exists
        try {
            if (Schema::hasTable('subscriptions')) {
                $data['subscriptions'] = [
                    'total_count' => DB::table('subscriptions')->count()
                ];
                
                $subColumns = DB::getSchemaBuilder()->getColumnListing('subscriptions');
                
                if (in_array('ends_at', $subColumns)) {
                    $data['subscriptions']['active_count'] = DB::table('subscriptions')
                        ->whereNull('ends_at')
                        ->where('user_id', $user_id)
                        ->orWhere('ends_at', '>', now())
                        ->count();
                }
                
                if (in_array('name', $subColumns)) {
                    $data['subscriptions']['by_plan'] = DB::table('subscriptions')
                        ->select('name', DB::raw('count(*) as count'))
                        ->where('user_id', $user_id)
                        ->groupBy('name')
                        ->get()
                        ->toArray();
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error getting subscription data: ' . $e->getMessage());
        }
        
        // Get data from all remaining tables
        $additionalTableData = $this->getAllTableSamples();
        $data = array_merge($data, $additionalTableData);
        
        // Add system statistics
        try {
            $data['system'] = [
                'database_size' => $this->getDatabaseSize(),
                'table_count' => count(DB::select('SHOW TABLES')),
                'timestamp' => now()->toIso8601String()
            ];
        } catch (\Exception $e) {
            \Log::error('Error getting system statistics: ' . $e->getMessage());
        }
        
        return $data;
    }
    
    /**
     * Get sample data from all tables that haven't been explicitly handled
     * 
     * @return array
     */
    private function getAllTableSamples(): array
    {
        $data = [];
        $tables = DB::select('SHOW TABLES');
        $explicitlyHandledTables = [
            'users', 'referrals', 'histories', 'chat_sessions', 'chat_messages',
            'transactions', 'payments', 'email_campaigns', 'campaign_stats'
        ];
        
        // List of tables to ensure we include (even if they cause errors with standard approach)
        $criticalTables = [
            'token_usage', 'token_histories', 'audit_logs', 'support_tickets', 
            'ticket_replies', 'plans', 'subscriptions', 'purchases'
        ];
        
        // Log all tables found for debugging
        $allTableNames = [];
        foreach ($tables as $table) {
            $tableArray = (array)$table;
            $tableName = array_values($tableArray)[0];
            $allTableNames[] = $tableName;
        }
        \Log::info('Analytics: Found ' . count($allTableNames) . ' tables in database', ['tables' => $allTableNames]);
        
        foreach ($tables as $table) {
            // Get the table name
            $tableArray = (array)$table;
            $tableName = array_values($tableArray)[0];
            
            // Skip already handled tables
            if (in_array($tableName, $explicitlyHandledTables)) {
                continue;
            }
            
            try {
                \Log::info('Analytics: Processing table ' . $tableName);
                
                // Get basic stats and sample data
                $count = DB::table($tableName)->count();
                $data[$tableName] = ['total_count' => $count];
                
                // Get primary key for better ordering
                $primaryKey = 'id'; // default
                $columns = Schema::getColumnListing($tableName);
                
                if (in_array('id', $columns)) {
                    $primaryKey = 'id';
                } elseif (in_array('created_at', $columns)) {
                    $primaryKey = 'created_at';
                }
                
                // Try to get sample data
                if ($count > 0 && !in_array($tableName, ['migrations', 'password_reset_tokens', 'personal_access_tokens', 'failed_jobs'])) {
                    // Use try/catch for each specific operation to avoid total failure
                    try {
                        $query = DB::table($tableName)->limit(5);
                        
                        // Apply ordering if the column exists
                        if (in_array($primaryKey, $columns)) {
                            $query->orderBy($primaryKey, 'desc');
                        }
                        
                        $data[$tableName]['sample_data'] = $query->get()->toArray();
                    } catch (\Exception $e) {
                        $data[$tableName]['sample_data_error'] = 'Error getting sample: ' . $e->getMessage();
                    }
                    
                    // Get column statistics
                    $data[$tableName]['column_stats'] = [];
                    foreach ($columns as $column) {
                        try {
                            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                                continue; // Skip standard columns
                            }
                            
                            // Check if column is numeric or string
                            $columnType = Schema::getColumnType($tableName, $column);
                            
                            if (in_array($columnType, ['integer', 'bigint', 'float', 'double', 'decimal'])) {
                                // For numeric columns, get sum and average
                                $data[$tableName]['column_stats'][$column] = [
                                    'type' => $columnType,
                                    'sum' => DB::table($tableName)->sum($column),
                                    'avg' => DB::table($tableName)->avg($column)
                                ];
                            } elseif ($columnType == 'boolean') {
                                // For boolean columns, get true/false counts
                                $data[$tableName]['column_stats'][$column] = [
                                    'type' => $columnType,
                                    'true_count' => DB::table($tableName)->where($column, true)->count(),
                                    'false_count' => DB::table($tableName)->where($column, false)->count()
                                ];
                            }
                        } catch (\Exception $e) {
                            // Skip this column if there's an error
                        }
                    }
                }
                
                // For token-related tables, get additional statistics if possible
                if ((strpos($tableName, 'token') !== false) && Schema::hasColumn($tableName, 'amount')) {
                    try {
                        $data[$tableName]['total_amount'] = DB::table($tableName)->sum('amount') ?? 0;
                    } catch (\Exception $e) {
                        $data[$tableName]['token_stats_error'] = 'Error calculating token stats: ' . $e->getMessage();
                    }
                }
                
                // Special handling for token_usage table to get detailed feature analytics
                if ($tableName === 'token_usage' && Schema::hasTable('token_usage')) {
                    try {
                        // Detailed feature statistics from token_usage
                        $data['feature_usage'] = [
                            // Map each feature to its usage stats
                            'chart_analysis' => $this->getFeatureUsageStats('token_usage', 'image_analysis'),
                            'ea_generator' => $this->getFeatureUsageStats('token_usage', 'ea_generator'),
                            'chat' => $this->getFeatureUsageStats('token_usage', 'chat'),
                            'code_generation' => $this->getFeatureUsageStats('token_usage', 'code_generation')
                        ];
                        
                        // Add feature descriptions to help AI interpret the data
                        $data['feature_descriptions'] = [
                            'chart_analysis' => 'Analysis of financial charts and graphs using image processing AI',
                            'ea_generator' => 'Expert Advisor generation tool for automated trading strategies',
                            'chat' => 'Standard chat interaction with AI assistant',
                            'code_generation' => 'Trading code and script generation'
                        ];
                        
                        // Get user-specific feature usage
                        $data['user_feature_usage'] = $this->getUserFeatureUsage();
                        
                    } catch (\Exception $e) {
                        \Log::error('Analytics: Error processing token_usage feature stats: ' . $e->getMessage());
                        $data['feature_usage_error'] = 'Could not process feature usage stats: ' . $e->getMessage();
                    }
                }
                
                // For audit tables, categorize by type if possible
                if ((strpos($tableName, 'log') !== false || $tableName == 'audit_logs' || $tableName == 'histories') && 
                    Schema::hasColumn($tableName, 'type')) {
                    try {
                        $data[$tableName]['by_type'] = DB::table($tableName)
                            ->select('type', DB::raw('count(*) as count'))
                            ->groupBy('type')
                            ->get()
                            ->toArray();
                    } catch (\Exception $e) {
                        $data[$tableName]['type_stats_error'] = 'Error getting type stats: ' . $e->getMessage();
                    }
                }
                
            } catch (\Exception $e) {
                \Log::error('Analytics: Error processing table ' . $tableName . ': ' . $e->getMessage());
                // If there's an issue with this table, still include it in the output
                $data[$tableName] = [
                    'error' => 'Could not process table: ' . $e->getMessage(),
                    'total_count' => 'unknown'
                ];
            }
        }
        
        // Special handling for critical tables that might have been missed
        foreach ($criticalTables as $tableName) {
            if (!isset($data[$tableName]) && !in_array($tableName, $explicitlyHandledTables) && Schema::hasTable($tableName)) {
                try {
                    \Log::info('Analytics: Processing critical table ' . $tableName);
                    $count = DB::table($tableName)->count();
                    
                    $data[$tableName] = [
                        'total_count' => $count,
                        'is_critical' => true
                    ];
                    
                    // Get simple sample if possible
                    if ($count > 0) {
                        $data[$tableName]['sample_data'] = DB::table($tableName)->limit(5)->get()->toArray();
                    }
                } catch (\Exception $e) {
                    \Log::error('Analytics: Error processing critical table ' . $tableName . ': ' . $e->getMessage());
                    $data[$tableName] = [
                        'error' => 'Could not process critical table: ' . $e->getMessage(),
                        'is_critical' => true,
                        'total_count' => 'unknown'
                    ];
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Get detailed usage statistics for a specific feature from token usage data
     * 
     * @param string $table The table to query (usually token_usage)
     * @param string $feature The feature identifier to look for
     * @return array Feature usage statistics
     */
    private function getFeatureUsageStats(string $table, string $feature): array
    {
        $result = [
            'feature_name' => $feature,
            'total_usage_count' => 0,
            'total_tokens_used' => 0,
            'user_count' => 0,
            'daily_usage' => [],
            'average_tokens_per_use' => 0
        ];
        
        try {
            // Get basic stats
            $usageQuery = DB::table($table)->where('feature', $feature)->orWhere('feature', 'LIKE', "%{$feature}%");
            $result['total_usage_count'] = $usageQuery->count();
            
            if ($result['total_usage_count'] > 0) {
                // Get token counts
                if (Schema::hasColumn($table, 'total_tokens')) {
                    $result['total_tokens_used'] = $usageQuery->sum('total_tokens') ?? 0;
                    $result['average_tokens_per_use'] = $result['total_usage_count'] > 0 ? 
                        round($result['total_tokens_used'] / $result['total_usage_count'], 2) : 0;
                }
                
                // Count distinct users
                if (Schema::hasColumn($table, 'user_id')) {
                    $result['user_count'] = $usageQuery->distinct('user_id')->count('user_id');
                    
                    // Get top users for this feature
                    $result['top_users'] = DB::table($table)
                        ->where('feature', $feature)
                        ->orWhere('feature', 'LIKE', "%{$feature}%")
                        ->select('user_id', DB::raw('count(*) as usage_count'))
                        ->groupBy('user_id')
                        ->orderBy('usage_count', 'desc')
                        ->get()
                        ->toArray();
                }
                
                // Get daily usage stats
                if (Schema::hasColumn($table, 'created_at')) {
                    $result['daily_usage'] = DB::table($table)
                        ->where('feature', $feature)
                        ->orWhere('feature', 'LIKE', "%{$feature}%")
                        ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
                        ->groupBy('date')
                        ->orderBy('date', 'desc')
                        ->get()
                        ->toArray();
                }
                
                // Get recent usage examples
                $result['recent_usage'] = DB::table($table)
                    ->where('feature', $feature)
                    ->orWhere('feature', 'LIKE', "%{$feature}%")
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            \Log::error("Error getting feature usage stats for {$feature}: " . $e->getMessage());
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Get user-specific feature usage statistics
     * 
     * @return array User feature usage statistics
     */
    private function getUserFeatureUsage(): array
    {
        $result = [];
        
        try {
            // Get all users with token usage
            $users = DB::table('users')
                ->join('token_usage', 'users.id', '=', 'token_usage.user_id')
                ->select('users.id', 'users.name', 'users.email')
                ->distinct()
                ->get();
                
            foreach ($users as $user) {
                // Get this user's feature breakdown
                $features = DB::table('token_usage')
                    ->where('user_id', $user->id)
                    ->select('feature', DB::raw('count(*) as count'))
                    ->groupBy('feature')
                    ->get()
                    ->toArray();
                    
                // Map standard feature names
                $featureMap = [
                    'image_analysis' => 'chart_analysis',
                    'ea_generator' => 'ea_generator',
                    'chat' => 'chat',
                    'code_generation' => 'code_generation'
                ];
                
                // Process features for this user
                $userFeatures = [];
                foreach ($features as $feature) {
                    $featureName = $feature->feature;
                    $normalizedName = $featureName;
                    
                    // Map to standard name if possible
                    foreach ($featureMap as $pattern => $standardName) {
                        if (stripos($featureName, $pattern) !== false) {
                            $normalizedName = $standardName;
                            break;
                        }
                    }
                    
                    $userFeatures[$normalizedName] = $feature->count;
                }
                
                // Add to result
                $result[] = [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'features' => $userFeatures,
                    'total_usage' => DB::table('token_usage')->where('user_id', $user->id)->count()
                ];
            }
            
            // Sort by total usage (most active users first)
            usort($result, function($a, $b) {
                return $b['total_usage'] <=> $a['total_usage'];
            });
            
            // Limit to top 20 users
            $result = array_slice($result, 0, 20);
            
        } catch (\Exception $e) {
            \Log::error("Error getting user feature usage: " . $e->getMessage());
            $result = [
                'error' => $e->getMessage()
            ];
        }
        
        return $result;
    }
    
    /**
     * Filter out SQL queries from content
     * 
     * @param string $content
     * @return string
     */
    private function filterSqlContent($content)
    {
        if (empty($content)) {
            return $content;
        }
        
        // Filter out code blocks containing SQL
        $content = preg_replace_callback('/```sql\\s*([\\s\\S]*?)```/i', function ($matches) {
            return '[SQL code removed for security reasons]';
        }, $content);
        
        // Filter out inline SQL patterns
        $sqlPatterns = [
            // Common SQL statements
            '/\\b(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|GRANT)\\s+[\\w\\s\\*\\.,`"\\(\\)]+\\s+(FROM|INTO|TABLE|DATABASE)\\s+[\\w\\s\\.,`"]+/i',
            // WHERE clauses
            '/\\bWHERE\\s+[\\w\\s\\.,`"\\=\\<\\>\\!\\(\\)]+/i',
            // JOIN clauses
            '/\\b(INNER|LEFT|RIGHT|OUTER|CROSS)?\\s*JOIN\\s+[\\w\\s\\.,`"]+\\s+ON\\s+[\\w\\s\\.,`"\\=\\<\\>\\!\\(\\)]+/i',
            // GROUP BY, ORDER BY, HAVING
            '/\\b(GROUP BY|ORDER BY|HAVING)\\s+[\\w\\s\\.,`"\\(\\)]+/i',
            // UNION
            '/\\bUNION\\s+(ALL\\s+)?SELECT/i'
        ];
        
        foreach ($sqlPatterns as $pattern) {
            $content = preg_replace($pattern, '[SQL query removed for security reasons]', $content);
        }
        
        // Remove SQL hints like "here's the SQL query you would need"
        $content = preg_replace('/\\b(heres?\\s+the\\s+SQL(\\s+query)?\\s+you\\s+(would|could|might)\\s+(need|use|run|execute))([\\s\\S]*?)([\\.,\\n])/i', '[SQL reference removed for security reasons]$6', $content);
        
        return $content;
    }
    
    /**
     * Get the approximate database size
     * 
     * @return string
     */
    private function getDatabaseSize(): string
    {
        try {
            $dbName = config('database.connections.mysql.database');
            $result = DB::select("SELECT table_schema AS 'database', SUM(data_length + index_length) AS 'size' 
                                FROM information_schema.TABLES 
                                WHERE table_schema = ? 
                                GROUP BY table_schema", [$dbName]);
            
            if (count($result) > 0) {
                $bytes = $result[0]->size;
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $factor = floor((strlen($bytes) - 1) / 3);
                return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
            }
            
            return 'Unknown';
        } catch (\Exception $e) {
            return 'Error calculating size';
        }
    }
    
    /**
     * Create a shareable formatted content with emojis and branding
     *
     * @param array $analysisData The analysis data to format
     * @return string The formatted shareable content
     */
    private function createShareableContent($analysisData)
    {
        // Extract key information - use case-insensitive keys to handle variations
        $symbol = $analysisData['Symbol'] ?? $analysisData['symbol'] ?? 'Unknown Symbol';
        $timeframe = $analysisData['Timeframe'] ?? $analysisData['timeframe'] ?? 'Unknown Timeframe';
        $action = $analysisData['Action'] ?? $analysisData['action'] ?? 'HOLD';
        $currentPrice = $analysisData['Current_Price'] ?? $analysisData['current_price'] ?? null;
        $entryPrice = $analysisData['Entry_Price'] ?? $analysisData['entry_price'] ?? null;
        $stopLoss = $analysisData['Stop_Loss'] ?? $analysisData['stop_loss'] ?? null;
        $takeProfit = $analysisData['Take_Profit'] ?? $analysisData['take_profit'] ?? null;
        $riskRatio = $analysisData['Risk_Ratio'] ?? $analysisData['risk_ratio'] ?? null;
        $structure = $analysisData['Market_Structure'] ?? $analysisData['market_structure'] ?? null;
        
        // Format the content with emojis
        $content = "";
        
        // Header with symbol and timeframe
        $content .= "📊 *MARKET ANALYSIS: {$symbol} {$timeframe}* 📊\n\n";
        
        // Action recommendation with appropriate emoji
        $actionEmoji = '⏹️';
        if (strtoupper($action) == 'BUY') {
            $actionEmoji = '🟢';
        } elseif (strtoupper($action) == 'SELL') {
            $actionEmoji = '🔴';
        }
        $content .= "{$actionEmoji} *SIGNAL:* {$action}\n\n";
        
        // Price information
        if ($currentPrice !== null) {
            $content .= "💲 *Current Price:* {$currentPrice}\n";
        }
        
        if ($entryPrice !== null) {
            $content .= "🎯 *Entry:* {$entryPrice}\n";
        }
        
        if ($stopLoss !== null) {
            $content .= "🛑 *Stop Loss:* {$stopLoss}\n";
        }
        
        if ($takeProfit !== null) {
            $content .= "💰 *Take Profit:* {$takeProfit}\n";
        }
        
        if ($riskRatio !== null) {
            $content .= "⚖️ *Risk_Ratio:* {$riskRatio}\n";
        }
        
        $content .= "\n";
        
        // Market structure if available
        if ($structure !== null && !empty($structure)) {
            // Limit to a reasonable length for sharing
            $structure = strlen($structure) > 150 ? substr($structure, 0, 147) . '...' : $structure;
            $content .= "📈 *Market_Structure:* {$structure}\n\n";
        }
        
        // Add technical indicators section if available
        if (isset($analysisData['INDICATORS']) || isset($analysisData['indicators'])) {
            $indicators = $analysisData['INDICATORS'] ?? $analysisData['indicators'] ?? [];
            if (!empty($indicators)) {
                $content .= "📉 *INDICATORS* 📉\n";
                
                // RSI
                $rsiPath = ['RSI_Indicator', 'Signal'];
                $rsiValue = $this->getNestedValue($indicators, $rsiPath);
                if ($rsiValue) {
                    $content .= "📊 *RSI:* {$rsiValue}\n";
                }
                
                // MACD
                $macdPath = ['MACD_Indicator', 'Signal'];
                $macdValue = $this->getNestedValue($indicators, $macdPath);
                if ($macdValue) {
                    $content .= "📊 *MACD:* {$macdValue}\n";
                }
                
                $content .= "\n";
            }
        }
        
        // Add confidence if available
        $confidenceValue = null;
        if (isset($analysisData['Analysis_Confidence']['Confidence_Level_Percent'])) {
            $confidenceValue = $analysisData['Analysis_Confidence']['Confidence_Level_Percent'];
        } elseif (isset($analysisData['analysis_confidence']['confidence_level_percent'])) {
            $confidenceValue = $analysisData['analysis_confidence']['confidence_level_percent'];
        } elseif (isset($analysisData['confidence'])) {
            $confidenceValue = $analysisData['confidence'];
        }
        
        if ($confidenceValue !== null) {
            $content .= "🎥 *Analysis_Confidence:* {$confidenceValue}%\n\n";
        }
        
        // Add branding
        $content .= "✨ *Powered by Decyphers* ✨\n";
        $content .= "Your AI Trading Assistant";
        
        return $content;
    }
    
    /**
     * Helper function to safely get nested values from an array
     *
     * @param array $array The array to search in
     * @param array $path The path of keys to traverse
     * @return mixed The value found or null if not found
     */
    private function getNestedValue($array, $path)
    {
        // Check for both uppercase and lowercase variations
        foreach ($path as $key) {
            if (isset($array[$key])) {
                $array = $array[$key];
            } elseif (isset($array[strtoupper($key)])) {
                $array = $array[strtoupper($key)];
            } elseif (isset($array[strtolower($key)])) {
                $array = $array[strtolower($key)];
            } else {
                return null;
            }
        }
        return $array;
    }
}

