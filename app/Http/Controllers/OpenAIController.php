<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\History;
use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\TokenUsage;
use App\Services\TokenService;
use Carbon\Carbon;

class OpenAIController extends Controller
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
     * Check if the OpenAI API key is valid by making a lightweight call
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
                ->get('https://api.openai.com/v1/models');
            
            if ($response->successful()) {
                return ['valid' => true, 'message' => 'API key is valid'];
            } else {
                $errorData = $response->json() ?: [];
                $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'Unknown error';
                return ['valid' => false, 'message' => $errorMessage, 'status' => $response->status()];
            }
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => $e->getMessage(), 'exception' => true];
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

    // --- Cost Calculation ---
    public function calculateCost($model, $inputTokens, $outputTokens, $profitMultiplier = 10)
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
        $totalCost = $rawCost * $profitMultiplier;

        return [
            'inputCost' => round($inputCost, 6),
            'outputCost' => round($outputCost, 6),
            'rawCost' => round($rawCost, 6),
            'profitMultiplier' => $profitMultiplier,
            'totalCost' => round($totalCost, 6)
        ];
    }
    
    // --- Analyze Image Endpoint ---
    // public function analyzeImage(Request $request)
    // {
    //     // Initialize variables that will be used throughout the method
    //     $tokenCost = 0;
        
    //     // First check if the user is authenticated and has sufficient tokens
    //     if (auth()->check()) {
    //         $user = auth()->user();
    //         $userId = auth()->id();
            
    //         // Calculate the estimated token cost before processing the request
    //         $modelId = $request->input('model_id', 'gpt-4o'); // Default model
    //         $inputTokens = $this->estimatedAnalysisTokens['input'];
    //         $outputTokens = $this->estimatedAnalysisTokens['output'];
            
    //         $costCalculation = $this->calculateCost($modelId, $inputTokens, $outputTokens);
            
    //         // Handle the case when the model is unknown and calculateCost returns an error
    //         if (isset($costCalculation['error'])) {
    //             // Use a default cost if model is unknown
    //             $tokenCost = ceil(($inputTokens * 0.0005 + $outputTokens * 0.0015) * 667);
    //             Log::warning('Unknown model in cost calculation, using default pricing', [
    //                 'model' => $modelId,
    //                 'error' => $costCalculation['error']
    //             ]);
    //         } else {
    //             $tokenCost = ceil($costCalculation['totalCost'] * 667); // 667 tokens per dollar conversion
    //         }
            
    //         // Check if the user has sufficient tokens
    //         $tokenBalances = $this->tokenService->getUserTokens($userId);
            
    //         Log::info('Checking token balance for image analysis', [
    //             'user_id' => $userId,
    //             'subscription_token' => $tokenBalances['subscription_token'],
    //             'addons_token' => $tokenBalances['addons_token'],
    //             'tokens_required' => $tokenCost
    //         ]);
            
    //         // Check if there are sufficient tokens in the combined total of subscription_token and addons_token
    //         $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
    //         if ($totalAvailableTokens < $tokenCost) {
    //             return response()->json([
    //                 'error' => 'Insufficient tokens',
    //                 'message' => 'You do not have enough tokens to perform this analysis.',
    //                 'tokens_required' => $tokenCost,
    //                 'subscription_token' => $tokenBalances['subscription_token'],
    //                 'addons_token' => $tokenBalances['addons_token']
    //             ], 403);
    //         }
    //     } else {
    //         // For non-authenticated users, return an error
    //         return response()->json([
    //             'error' => 'Authentication required',
    //             'message' => 'You must be signed in to use this feature'
    //         ], 401);
    //     }
        
    //     // First check what kind of input we have (file or base64)
    //     if ($request->hasFile('image')) {
    //         // Validate file upload
    //         $request->validate([
    //             'image' => 'file|image|max:10240', // Max 10MB image file
    //         ]);
            
    //         // Get the uploaded image file
    //         $imageFile = $request->file('image');
            
    //         // Convert image to base64 for OpenAI API
    //         $imageExtension = $imageFile->getClientOriginalExtension();
    //         $base64Image = 'data:image/' . $imageExtension . ';base64,' . 
    //             base64_encode(file_get_contents($imageFile->getRealPath()));
    //     } 
    //     elseif ($request->has('image_base64')) {
    //         // Validate base64 string
    //         $request->validate([
    //             'image_base64' => 'required|string',
    //         ]);
            
    //         $base64Image = $request->input('image_base64');
            
    //         // Add data:image prefix if not present
    //         if (strpos($base64Image, 'data:image') !== 0) {
    //             $base64Image = 'data:image/jpeg;base64,' . $base64Image;
    //         }
    //     }
    //     else {
    //         return response()->json([
    //             'message' => 'No image provided',
    //             'errors' => [
    //                 'image' => ['Please provide either an image file upload or a base64-encoded image string']
    //             ]
    //         ], 422);
    //     }
        
    //     // Get the model ID with default
    //     $modelId = $request->input('model_id', 'gpt-4o'); // Default model - gpt-4o has vision capabilities
        
    //     // Get OpenAI API key from environment
    //     $apiKey = env('OPENAI_API_KEY');
    //     if (!$apiKey) {
    //         return response()->json(['error' => 'OpenAI API key not configured'], 500);
    //     }
        
    //     // Construct the system prompt for financial chart analysis
    //     $systemPrompt = "";
        
    //     // Load the system prompt from file or use a default one
    //     $promptFile = base_path('resources/prompts/openai_image_analysis.txt');
    //     if (file_exists($promptFile)) {
    //         $systemPrompt = file_get_contents($promptFile);
    //     } else {
    //         // Default system prompt if file doesn't exist
    //         $systemPrompt = "You are an expert financial analyst with extensive experience in interpreting financial charts and market data.\n\n"
    //             . "INSTRUCTIONS:\n"
    //             . "1. Analyze the financial chart image I'll provide.\n"
    //             . "2. Identify the trading instrument, timeframe, and chart type.\n"
    //             . "3. Evaluate current price action, trend direction, and key support/resistance levels.\n"
    //             . "4. Identify any significant chart patterns or indicators.\n"
    //             . "5. Assess overall market structure and volatility.\n"
    //             . "6. Provide a well-reasoned trading idea with entry points, stop loss, and take profit levels.\n"
    //             . "7. Include confidence level in your analysis.\n\n"
    //             . "IMPORTANT: Format your entire response as a single JSON object with the following structure:\n"
    //             . "{\n"
    //             . "  \"symbol\": \"EURUSD\",  // Trading instrument symbol\n"
    //             . "  \"timeframe\": \"1H\",  // Chart timeframe\n"
    //             . "  \"chart_type\": \"Candlestick\",  // Type of chart\n"
    //             . "  \"current_price\": 1.0876,  // Current price level\n"
    //             . "  \"trend_direction\": \"Bullish\",  // Overall trend direction\n"
    //             . "  \"support_levels\": [1.0820, 1.0780],  // Key support levels\n"
    //             . "  \"resistance_levels\": [1.0920, 1.0950],  // Key resistance levels\n"
    //             . "  \"patterns\": [\"Double Bottom\", \"Golden Cross\"],  // Identified patterns\n"
    //             . "  \"indicators\": [\"MACD Bullish Crossover\", \"RSI 58\"],  // Technical indicators\n"
    //             . "  \"market_structure\": \"Higher highs and higher lows forming on the 1H timeframe, indicating strengthening bullish momentum\",  // Market structure assessment\n"
    //             . "  \"volatility\": \"Moderate with decreasing ATR\",  // Volatility assessment\n"
    //             . "  \"trade_idea\": {\n"
    //             . "    \"action\": \"Buy\",  // Buy, Sell, or Hold\n"
    //             . "    \"entry_price\": 1.0880,  // Suggested entry price\n"
    //             . "    \"stop_loss\": 1.0820,  // Suggested stop loss level\n"
    //             . "    \"take_profit\": 1.0950,  // Suggested take profit level\n"
    //             . "    \"risk_reward_ratio\": 2.5  // Risk-to-reward ratio of the trade\n"
    //             . "  },\n"
    //             . "  \"confidence\": 75,  // Confidence level in percentage\n"
    //             . "  \"analysis_summary\": \"The EURUSD pair is showing strong bullish momentum on the 1H timeframe with a recent double bottom pattern completion. The price has broken above the key resistance at 1.0850 and is now retesting it as new support.\"  // Brief summary of analysis\n"
    //             . "}\n";
    //     }
        
    //     // Construct the message payload for the OpenAI API
    //     $payload = [
    //         'model' => $modelId,
    //         'messages' => [
    //             [
    //                 'role' => 'system',
    //                 'content' => $systemPrompt
    //             ],
    //             [
    //                 'role' => 'user',
    //                 'content' => [
    //                     [
    //                         'type' => 'text',
    //                         'text' => 'Please analyze this financial chart.'
    //                     ],
    //                     [
    //                         'type' => 'image_url',
    //                         'image_url' => [
    //                             'url' => $base64Image
    //                         ]
    //                     ]
    //                 ]
    //             ]
    //         ],
    //         'max_completion_tokens' => 2000
    //     ];
        
    //     try {
    //         // Try with primary model first
    //         $parsedContent = $this->tryImageAnalysis($modelId, $payload, $apiKey, $base64Image);
            
    //         // If we get an error back instead of parsed content, try fallback model
    //         if (is_array($parsedContent) && isset($parsedContent['error'])) {
    //             // Log the fallback attempt
    //             Log::warning('Primary model failed, trying fallback model', [
    //                 'primary_model' => $modelId,
    //                 'error' => $parsedContent['error'],
    //                 'fallback_model' => 'gpt-4-vision-preview' // Different fallback model
    //             ]);
                
    //             // Try with fallback model
    //             $fallbackPayload = $payload;
    //             $fallbackPayload['model'] = 'gpt-4o'; // Use gpt-4o as fallback - it has vision capabilities
    //             $parsedContent = $this->tryImageAnalysis('gpt-4o', $fallbackPayload, $apiKey, $base64Image);
                
    //             // If fallback also fails, return the error
    //             if (is_array($parsedContent) && isset($parsedContent['error'])) {
    //                 return response()->json([
    //                     'error' => 'Analysis failed with both primary and fallback models: ' . $parsedContent['error'],
    //                     'model_tried' => [$modelId, 'gpt-4o']
    //                 ], 500);
    //             }
    //         }
            
    //         // Record the request in history and deduct tokens
    //         // At this point, we've already verified the user is authenticated and has sufficient tokens
    //         $user = auth()->user();
            
    //         // Token calculation was already performed at the beginning
    //         // We already confirmed the user has sufficient tokens
    //         // Just reference the variables set at the beginning
    //         $userId = auth()->id();
    //         $tokenBalances = $this->tokenService->getUserTokens($userId);
            
    //         Log::info('Deducting tokens for image analysis', [
    //             'user_id' => auth()->id(),
    //             'model' => $modelId,
    //             'token_cost' => $tokenCost
    //         ]);
                
    //         // Ensure we have a valid user ID before attempting deduction
    //         $userId = auth()->id();
    //         if (!$userId) {
    //             Log::error('Cannot deduct tokens: No authenticated user ID available');
    //         } else {
    //             try {
    //                 // Explicitly retrieve user to verify existence
    //                 $user = \App\Models\User::find($userId);
                    
    //                 if (!$user) {
    //                     Log::error('Cannot deduct tokens: User not found', ['user_id' => $userId]);
    //                 } else {
    //                     // Get current token balances
    //                     $tokenBalances = $this->tokenService->getUserTokens($userId);
                        
    //                     Log::info('Attempting token deduction with valid user', [
    //                         'user_id' => $userId,
    //                         'subscription_token' => $tokenBalances['subscription_token'],
    //                         'addons_token' => $tokenBalances['addons_token'],
    //                         'tokens_to_deduct' => $tokenCost
    //                     ]);
                        
    //                     // Get actual token counts from the API response or use our estimated values
    //                     // For image analysis, we typically have input tokens (for the prompt) and output tokens (for the response)
    //                     $actualInputTokens = $inputTokens; // Default to our estimate
    //                     $actualOutputTokens = $outputTokens; // Default to our estimate
                        
    //                     // Try to extract actual token counts from OpenAI response if available
    //                     if (isset($parsedContent['usage']) && isset($parsedContent['usage']['prompt_tokens']) && isset($parsedContent['usage']['completion_tokens'])) {
    //                         $actualInputTokens = $parsedContent['usage']['prompt_tokens'];
    //                         $actualOutputTokens = $parsedContent['usage']['completion_tokens'];
                            
    //                         // Recalculate token cost using actual token counts
    //                         $actualCostCalculation = $this->calculateCost($modelId, $actualInputTokens, $actualOutputTokens);
    //                         if (!isset($actualCostCalculation['error'])) {
    //                             // Update the token cost using actual usage
    //                             $actualTokenCost = ceil($actualCostCalculation['totalCost'] * 667); // 667 tokens per dollar
                                
    //                             if (abs($actualTokenCost - $tokenCost) > 5) {
    //                                 Log::info('Updated token cost based on actual usage', [
    //                                     'estimated_cost' => $tokenCost,
    //                                     'actual_cost' => $actualTokenCost,
    //                                     'difference' => $actualTokenCost - $tokenCost
    //                                 ]);
    //                                 // Update the token cost to use for deduction
    //                                 $tokenCost = $actualTokenCost;
    //                             }
    //                         }
                            
    //                         Log::info('Using actual token counts from API response', [
    //                             'input_tokens' => $actualInputTokens,
    //                             'output_tokens' => $actualOutputTokens,
    //                             'total' => $actualInputTokens + $actualOutputTokens,
    //                             'token_cost' => $tokenCost
    //                         ]);
    //                     }
                        
    //                     // Ensure we have the latest token balances after potentially updating the token cost
    //                     if ($tokenBalances['subscription_token'] + $tokenBalances['addons_token'] < $tokenCost) {
    //                         // If the updated cost exceeds available tokens, log but proceed with maximum available
    //                         Log::warning('Updated token cost exceeds available balance', [
    //                             'user_id' => $userId,
    //                             'updated_cost' => $tokenCost,
    //                             'available_tokens' => $tokenBalances['subscription_token'] + $tokenBalances['addons_token']
    //                         ]);
    //                         // Use what's available instead of failing
    //                         $tokenCost = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
    //                     }
                        
    //                     // Smart token deduction logic that can split across subscription and addon tokens if needed
    //                     $deductionResult = false;
                        
    //                     if ($tokenBalances['subscription_token'] >= $tokenCost) {
    //                         // If subscription tokens are enough, deduct from there
    //                         $deductionResult = $this->tokenService->deductUserTokens(
    //                             $userId,
    //                             $tokenCost,
    //                             'image_analysis',
    //                             'subscription_token',
    //                             $modelId,
    //                             'vision',
    //                             $actualInputTokens,
    //                             $actualOutputTokens
    //                         );
    //                     } elseif ($tokenBalances['addons_token'] >= $tokenCost) {
    //                         // If addon tokens are enough, deduct from there
    //                         $deductionResult = $this->tokenService->deductUserTokens(
    //                             $userId,
    //                             $tokenCost,
    //                             'image_analysis',
    //                             'addons_token',
    //                             $modelId,
    //                             'vision',
    //                             $actualInputTokens,
    //                             $actualOutputTokens
    //                         );
    //                     } else {
    //                         // Need to split the deduction across both token sources
    //                         // First use all available subscription tokens
    //                         $subscriptionDeduction = $this->tokenService->deductUserTokens(
    //                             $userId,
    //                             $tokenBalances['subscription_token'],
    //                             'image_analysis (partial)',
    //                             'subscription_token',
    //                             $modelId,
    //                             'vision',
    //                             intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
    //                             intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
    //                         );
                            
    //                         // Then deduct the remainder from addon tokens
    //                         $remainingCost = $tokenCost - $tokenBalances['subscription_token'];
    //                         $addonDeduction = $this->tokenService->deductUserTokens(
    //                             $userId,
    //                             $remainingCost,
    //                             'image_analysis (remainder)',
    //                             'addons_token',
    //                             $modelId,
    //                             'vision',
    //                             $actualInputTokens - intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
    //                             $actualOutputTokens - intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
    //                         );
                            
    //                         // Both deductions must succeed
    //                         $deductionResult = $subscriptionDeduction && $addonDeduction;
                            
    //                         Log::info('Split token deduction for image analysis', [
    //                             'user_id' => $userId,
    //                             'total_cost' => $tokenCost,
    //                             'subscription_amount' => $tokenBalances['subscription_token'],
    //                             'addon_amount' => $remainingCost,
    //                             'success' => $deductionResult
    //                         ]);
    //                     }
                        
    //                     if (!$deductionResult) {
    //                         $updatedBalances = $this->tokenService->getUserTokens($userId);
    //                         Log::warning('Failed to deduct tokens for image analysis', [
    //                             'user_id' => $userId,
    //                             'tokens_to_deduct' => $tokenCost,
    //                             'available_subscription_tokens' => $updatedBalances['subscription_token'],
    //                             'available_addons_tokens' => $updatedBalances['addons_token']
    //                         ]);
    //                         // Continue despite failed deduction as we already processed the request
    //                     } else {
    //                         $updatedBalances = $this->tokenService->getUserTokens($userId);
    //                         Log::info('Successfully deducted tokens for image analysis', [
    //                             'user_id' => $userId,
    //                             'deducted_tokens' => $tokenCost,
    //                             'remaining_subscription_tokens' => $updatedBalances['subscription_token'],
    //                             'remaining_addons_tokens' => $updatedBalances['addons_token']
    //                         ]);
    //                     }
    //                 }
    //             } catch (\Exception $e) {
    //                 Log::error('Exception during token deduction', [
    //                     'error' => $e->getMessage(),
    //                     'trace' => $e->getTraceAsString()
    //                 ]);
    //             }
    //         }
            
    //         // Extract symbol and timeframe for the title if available
    //         $symbol = $parsedContent['symbol'] ?? 'Chart';
    //         $timeframe = $parsedContent['timeframe'] ?? '';
    //         $chartTitle = "{$symbol} {$timeframe} Analysis";
            
    //         // Store the request in history
    //         History::create([
    //             'user_id' => $user->id,
    //             'type' => 'image_analysis',
    //             'title' => $chartTitle,
    //             'content' => json_encode($parsedContent),
    //             'model' => $modelId,
    //             'provider' => 'openai'
    //         ]);
            
    //         return response()->json($parsedContent);
    //     } catch (\Exception $e) {
    //         // Log and return any exceptions
    //         Log::error('Exception calling OpenAI API', [
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);
            
    //         return response()->json(['error' => 'Error calling OpenAI API: ' . $e->getMessage()], 500);
    //     }
    // }
    
    /**
     * Helper method to try image analysis with a specific model
     * Returns either parsed content or an error array
     */
    private function tryImageAnalysis($modelId, $payload, $apiKey, $base64Image)
    {
        // Update model in payload if needed
        $payload['model'] = $modelId;
        
        try {
            // Make the API call to OpenAI
            $response = Http::timeout(300) // Increase timeout to 5 minutes
                ->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json'
                ])
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            // If API call is successful
            if ($response->successful()) {
                $data = $response->json();
                
                // Log the response
                Log::debug('OpenAI API response', ['response' => $data, 'model' => $modelId]);
                
                // Check if response is empty (0 bytes)
                $responseContent = $response->body();
                if (empty($responseContent)) {
                    Log::error('Received empty response body (0 bytes) from OpenAI API', ['model' => $modelId]);
                    return ['error' => 'Empty response body from API'];
                }
                
                // Check if we have a response
                if (!isset($data['choices']) || empty($data['choices'])) {
                    Log::error('No choices in OpenAI API response', ['response' => $data, 'model' => $modelId]);
                    return ['error' => 'No assistant reply found in API response'];
                }
                
                $content = $data['choices'][0]['message']['content'] ?? null;
                
                // Check if the response is empty
                if (empty($content)) {
                    Log::error('Empty response content from OpenAI', ['model' => $modelId]);
                    return ['error' => 'Empty response from AI'];
                }
                
                // Try to parse the response as JSON
                $parsedContent = json_decode($content, true);
                
                // If parsing fails, try to parse it using the parseAnalysisResponse method
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::warning('Failed to parse OpenAI response as JSON, trying regex parsing', ['model' => $modelId]);
                    $parsedContent = $this->parseAnalysisResponse($content);
                }
                
                return $parsedContent;
                
            } else {
                // If API call fails, log the error and return it
                $errorData = $response->json() ?: [];
                Log::error('OpenAI API error', ['error' => $errorData, 'model' => $modelId, 'status' => $response->status()]);
                
                $errorMessage = isset($errorData['error']['message']) 
                    ? $errorData['error']['message'] 
                    : 'Unknown error from OpenAI API';
                
                return ['error' => $errorMessage, 'status' => $response->status()];
            }
            
        } catch (\Exception $e) {
            // Log and return any exceptions
            Log::error('Exception calling OpenAI API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'model' => $modelId
            ]);
            
            return ['error' => 'Error calling OpenAI API: ' . $e->getMessage()];
        }
    }
    
    public function parseAnalysisResponse($responseText)
    {
        // Clean and normalize the text
        $text = html_entity_decode($responseText);
        $text = str_replace('*', '', $text); // Remove all asterisks
        $text = trim(preg_replace('/\\s+/u', ' ', $text)); // Normalize all whitespace
        
        // Helper function to trim values properly
        $robustTrim = function($str) {
            // Remove Unicode whitespace and control characters
            return trim(preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $str));
        };
        
        // Helper function to extract values using regex
        $extract = function($pattern, $subject) use ($robustTrim) {
            if (preg_match($pattern, $subject, $matches)) {
                return $robustTrim($matches[1]);
            }
            return null;
        };
        
        // Helper function to extract lists
        $extractList = function($pattern, $subject) use ($extract, $robustTrim) {
            $value = $extract($pattern, $subject);
            if ($value) {
                // Split by commas or spaces and trim each item
                $items = preg_split('/,|\\s+and\\s+/', $value);
                return array_map($robustTrim, $items);
            }
            return [];
        };
        
        // Extract all the fields
        $result = [
            'symbol' => $extract('/Symbol:?\\s*([\\w\\d\\/.-]+)/iu', $text),
            'timeframe' => $extract('/Timeframe:?\\s*([\\w\\d.-]+)/iu', $text),
            'trend_direction' => $extract('/Trend Direction:?\\s*(\\w+)/iu', $text),
            'current_price' => null,
            'market_structure' => $extract('/Market Structure:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'volatility' => $extract('/Volatility:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'support_levels' => $extractList('/Support Levels:?\\s*([\\d\\s,.]+)/iu', $text),
            'resistance_levels' => $extractList('/Resistance Levels:?\\s*([\\d\\s,.]+)/iu', $text),
            'key_indicators' => $extractList('/Key Indicators:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'pattern_recognition' => $extract('/Pattern Recognition:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'moving_averages' => $extract('/Moving Averages:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'oscillators' => $extract('/Oscillators:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'volume_analysis' => $extract('/Volume Analysis:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'action' => $extract('/(?:\\d+\\.\\s*)?Action:?\\s*(\\w+)/iu', $text),
            'entry_price' => null,
            'stop_loss' => null,
            'take_profit' => null,
            'risk_reward_ratio' => null,
            'confidence_level' => $extract('/Confidence Level:?\\s*(\\w+)/iu', $text),
            'key_risks' => $extractList('/Key Risks:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'market_sentiment' => $extract('/Market Sentiment:?\\s*(\\w+)/iu', $text),
            'significant_news' => $extract('/Significant News:?\\s*([^•#]+)(?=\\s+-\\s+\\w[\\w\\s()]*:|\\s+###)/iu', $text),
            'education_notes' => $extract('/Education Notes:?\\s*([^•#]+)(?=$|\\s+###)/iu', $text)
        ];
        
        // Process numerical values with special handling for decimal separator
        if (preg_match('/Current Price:?\\s*([\\d\\.,]+)/iu', $text, $matches)) {
            $price = str_replace(',', '.', $matches[1]);
            $result['current_price'] = (float) $price;
        }
        
        if (preg_match('/Entry Price:?\\s*([\\d\\.,]+)/iu', $text, $matches)) {
            $price = str_replace(',', '.', $matches[1]);
            $result['entry_price'] = (float) $price;
        }
        
        if (preg_match('/Stop Loss:?\\s*([\\d\\.,]+)/iu', $text, $matches)) {
            $price = str_replace(',', '.', $matches[1]);
            $result['stop_loss'] = (float) $price;
        }
        
        if (preg_match('/Take Profit:?\\s*([\\d\\.,]+)/iu', $text, $matches)) {
            $price = str_replace(',', '.', $matches[1]);
            $result['take_profit'] = (float) $price;
        }
        
        if (preg_match('/Risk-Reward Ratio:?\\s*([\\d\\.,]+)/iu', $text, $matches)) {
            $ratio = str_replace(',', '.', $matches[1]);
            $result['risk_reward_ratio'] = (float) $ratio;
        }
        
        // Convert support and resistance levels to numbers
        if (!empty($result['support_levels'])) {
            $result['support_levels'] = array_map(function($level) {
                $level = str_replace(',', '.', $level);
                return (float) $level;
            }, $result['support_levels']);
        }
        
        if (!empty($result['resistance_levels'])) {
            $result['resistance_levels'] = array_map(function($level) {
                $level = str_replace(',', '.', $level);
                return (float) $level;
            }, $result['resistance_levels']);
        }
        
        // Return the parsed response
        return $result;
    }

    // --- Chat Message Endpoint ---
    public function sendChatMessage(Request $request)
    {
        // Ensure the user is authenticated
        if (!auth()->check()) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        // Validate request
        $request->validate([
            'messages' => 'required|array',
            'model' => 'sometimes|string',
            'session_id' => 'sometimes|string',
            'history_id' => 'sometimes|string',
        ]);
        
        // Parse request data
        $messages = $request->input('messages');
        $modelId = $request->input('model', 'gpt-4o-mini'); // Default model
        $sessionId = $request->input('session_id');
        $historyId = $request->input('history_id');
        
        // Make sure messages array is properly formatted
        if (empty($messages)) {
            return response()->json(['error' => 'No messages provided'], 400);
        }
        
        // Calculate estimated tokens for pre-authorization
        $estimatedInputTokens = $this->estimatedChatTokens['input'];
        $estimatedOutputTokens = $this->estimatedChatTokens['output'];
        
        // Calculate estimated cost based on model
        $estimatedCostCalculation = $this->calculateCost($modelId, $estimatedInputTokens, $estimatedOutputTokens);
        
        // Handle unknown model with default pricing
        if (isset($estimatedCostCalculation['error'])) {
            $estimatedTokenCost = ceil(($estimatedInputTokens * 0.0005 + $estimatedOutputTokens * 0.0015) * 667);
            Log::warning('Unknown model in cost estimation, using default pricing', [
                'model' => $modelId,
                'error' => $estimatedCostCalculation['error']
            ]);
        } else {
            $estimatedTokenCost = ceil($estimatedCostCalculation['totalCost'] * 667); // 667 tokens per dollar
        }
        
        // Get user token balances
        $userId = auth()->id();
        $tokenBalances = $this->tokenService->getUserTokens($userId);
        $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
        
        // Pre-check if user has enough tokens
        if ($totalAvailableTokens < $estimatedTokenCost) {
            Log::warning('Insufficient tokens for chat message', [
                'user_id' => $userId, 
                'estimated_cost' => $estimatedTokenCost,
                'available_tokens' => $totalAvailableTokens
            ]);
            return response()->json([
                'error' => 'Insufficient tokens. Please purchase more tokens to continue.'
            ], 403);
        }
        
        Log::info('Pre-authorization for chat message', [
            'user_id' => $userId,
            'model' => $modelId,
            'estimated_input_tokens' => $estimatedInputTokens,
            'estimated_output_tokens' => $estimatedOutputTokens,
            'estimated_cost' => $estimatedTokenCost,
            'available_tokens' => $totalAvailableTokens
        ]);
        
        // Construct the request payload for OpenAI API
        $payload = [
            'model' => $modelId,
            'messages' => $messages,
            'max_completion_tokens' => 4000,
            'temperature' => 0.7
        ];
        
        // Get API key from environment
        $apiKey = env('OPENAI_API_KEY');
        
        // If no API key, return error
        if (!$apiKey) {
            Log::error('OpenAI API key not found');
            return response()->json(['error' => 'API key not configured'], 500);
        }
        
        try {
            // Make the API call to OpenAI
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json'
                ])
                ->post('https://api.openai.com/v1/chat/completions', $payload);
            
            // If API call is successful
            if ($response->successful()) {
                $data = $response->json();
                
                // Check if we have a response
                if (!isset($data['choices']) || empty($data['choices'])) {
                    Log::error('No choices in OpenAI API response', ['response' => $data]);
                    return response()->json(['error' => 'No assistant reply found in API response'], 500);
                }
                
                // Extract the assistant's reply
                $assistantReply = $data['choices'][0]['message']['content'] ?? null;
                
                if (empty($assistantReply)) {
                    Log::error('Empty response content from OpenAI');
                    return response()->json(['error' => 'Empty response from AI'], 500);
                }
                
                // User is already authenticated (checked earlier)
                $usage = $data['usage'] ?? null;
                if ($usage) {
                    // Use actual token counts from API response
                    $inputTokens = $usage['prompt_tokens'] ?? $this->estimatedChatTokens['input'];
                    $outputTokens = $usage['completion_tokens'] ?? $this->estimatedChatTokens['output'];
                    
                    // Recalculate cost based on actual token usage
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
                        $tokenCost = ceil($costCalculation['totalCost'] * 667); // Using 667 tokens per dollar as conversion
                    }
                    
                    // Get current token balances
                    $userId = auth()->id();
                    $tokenBalances = $this->tokenService->getUserTokens($userId);
                    $totalAvailableTokens = $tokenBalances['subscription_token'] + $tokenBalances['addons_token'];
                    
                    // Check if actual cost exceeds pre-authorization
                    if ($tokenCost > $estimatedTokenCost) {
                        Log::warning('Actual token cost exceeds pre-authorization estimate', [
                            'user_id' => $userId,
                            'estimated_cost' => $estimatedTokenCost,
                            'actual_cost' => $tokenCost,
                            'difference' => $tokenCost - $estimatedTokenCost
                        ]);
                    }
                    
                    // Ensure we have enough tokens to deduct
                    if ($totalAvailableTokens < $tokenCost) {
                        // If the cost exceeds available tokens, cap it to what's available
                        Log::warning('Token cost exceeds available balance for chat', [
                            'user_id' => $userId,
                            'cost' => $tokenCost,
                            'available_tokens' => $totalAvailableTokens
                        ]);
                        // Use what's available instead of failing
                        $tokenCost = $totalAvailableTokens;
                    }
                    
                    Log::info('Deducting tokens for chat message with actual usage', [
                        'user_id' => $userId,
                        'model' => $modelId,
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'total_tokens' => $inputTokens + $outputTokens,
                        'calculated_cost_dollars' => $costCalculation['totalCost'] ?? 0,
                        'token_cost' => $tokenCost,
                        'subscription_token_balance' => $tokenBalances['subscription_token'],
                        'addons_token_balance' => $tokenBalances['addons_token']
                    ]);
                    
                    // Smart token deduction logic
                    $deductionResult = false;
                    
                    if ($tokenBalances['subscription_token'] >= $tokenCost) {
                        // If subscription tokens are sufficient, deduct from there
                        $deductionResult = $this->tokenService->deductUserTokens(
                            $userId,
                            $tokenCost,
                            'chat_completion',
                            'subscription_token',
                            $modelId,
                            'text',
                            $inputTokens,
                            $outputTokens
                        );
                    } else if ($tokenBalances['addons_token'] >= $tokenCost) {
                        // If addon tokens are sufficient, deduct from there
                        $deductionResult = $this->tokenService->deductUserTokens(
                            $userId,
                            $tokenCost,
                            'chat_completion',
                            'addons_token',
                            $modelId,
                            'text',
                            $inputTokens,
                            $outputTokens
                        );
                    } else {
                        // Need to split the deduction across both token sources
                        // First deduct all subscription tokens
                        $subscriptionDeduction = $this->tokenService->deductUserTokens(
                            $userId,
                            $tokenBalances['subscription_token'],
                            'chat_completion (partial)',
                            'subscription_token',
                            $modelId,
                            'text',
                            intval($inputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                            intval($outputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
                        );
                        
                        // Then deduct the remainder from addon tokens
                        $remainingCost = $tokenCost - $tokenBalances['subscription_token'];
                        $addonDeduction = $this->tokenService->deductUserTokens(
                            $userId,
                            $remainingCost,
                            'chat_completion (remainder)',
                            'addons_token',
                            $modelId,
                            'text',
                            $inputTokens - intval($inputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                            $outputTokens - intval($outputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
                        );
                        
                        // Both deductions must succeed
                        $deductionResult = $subscriptionDeduction && $addonDeduction;
                        
                        if (!$deductionResult) {
                            $updatedBalances = $this->tokenService->getUserTokens($userId);
                            Log::warning('Failed to deduct tokens for chat', [
                                'user_id' => $userId,
                                'tokens_to_deduct' => $tokenCost,
                                'available_subscription_tokens' => $updatedBalances['subscription_token'],
                                'available_addons_tokens' => $updatedBalances['addons_token']
                            ]);
                            // Continue despite failed deduction as we already processed the request
                        } else {
                            $updatedBalances = $this->tokenService->getUserTokens($userId);
                            Log::info('Successfully deducted tokens for chat message', [
                                'user_id' => $userId,
                                'deducted_tokens' => $tokenCost,
                                'remaining_subscription_tokens' => $updatedBalances['subscription_token'],
                                'remaining_addons_tokens' => $updatedBalances['addons_token']
                            ]);
                        }
                    }
                    
                    try {
                        $session = null;
                        
                        if ($sessionId) {
                            // Try to find an existing session
                            $session = ChatSession::where('id', $sessionId)
                                ->where('user_id', auth()->id())
                                ->first();
                        }
                        
                        if (!$session) {
                            // Create a new session
                            $session = ChatSession::create([
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
                        $userMessage = ChatMessage::create([
                            'chat_session_id' => $session->id,
                            'user_id' => auth()->id(),
                            'history_id' => $historyId, // Link to the history record if provided
                            'sender' => 'user',
                            'status' => 'sent',
                            'text' => is_array($messages[count($messages) - 1]['content']) 
                                ? json_encode($messages[count($messages) - 1]['content']) 
                                : $messages[count($messages) - 1]['content'],
                            'metadata' => [
                                'model' => $modelId
                            ],
                            'timestamp' => now(),
                        ]);
                        
                        // Save the assistant's reply
                        $assistantMessage = ChatMessage::create([
                            'chat_session_id' => $session->id,
                            'user_id' => auth()->id(),
                            'history_id' => $historyId, // Link to the history record if provided
                            'sender' => 'assistant',
                            'status' => 'sent',
                            'text' => $assistantReply,
                            'metadata' => [
                                'model' => $modelId,
                                'tokens' => $data['usage']['completion_tokens'] ?? 0
                            ],
                            'timestamp' => now(),
                        ]);
                        
                        // Return the session ID along with the reply
                        return response()->json([
                            'reply' => $assistantReply,
                            'session_id' => $session->id
                        ]);
                        
                    } catch (\Exception $e) {
                        // Log the error but don't fail the request
                        Log::error('Failed to save chat messages to database', [
                            'error' => $e->getMessage(),
                            'user_id' => auth()->id()
                        ]);
                        
                        // Still return the AI's reply even if saving to DB failed
                        return response()->json(['reply' => $assistantReply]);
                    }
                }
                
                // If user is not authenticated, just return the reply
                return response()->json(['reply' => $assistantReply]);
                
            } else {
                // If API call fails, log the error and return it
                $errorData = $response->json() ?: [];
                Log::error('OpenAI API error', ['error' => $errorData]);
                
                $errorMessage = isset($errorData['error']['message']) 
                    ? $errorData['error']['message'] 
                    : 'Unknown error from OpenAI API';
                
                return response()->json(['error' => $errorMessage], $response->status());
            }
            
        } catch (\Exception $e) {
            // Log and return any exceptions
            Log::error('Exception calling OpenAI API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['error' => 'Error calling OpenAI API: ' . $e->getMessage()], 500);
        }
    }
    
    // --- Cost Calculation Endpoint ---
    public function calculateCostEndpoint(Request $request)
    {
        $model = $request->input('model', 'gpt-4o-mini');
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


    /*
        Clement's Version of Image Analyzer using OpenAI gpt-4o or gpt-4o-2024-08-06
    */
    private $systemPrompt = <<<EOT
        You are an expert financial chart analyst. Your primary task is to accurately identify the trading pair and timeframe from the chart image.

        CRITICAL - FIRST STEP:
        Look at the chart image. Your first task is to identify and report ONLY these two pieces of information:

        1. Symbol/Trading Pair:
        [ONLY write the exact trading pair visible in the chart's title or header. Do not guess or make assumptions.]

        2. Timeframe:
        [ONLY write the exact timeframe visible in the chart's settings or header. Do not guess or make assumptions.]

        STRICT RULES:
        - Write ONLY what you can clearly see in the chart image
        - Do not use placeholders or examples
        - Do not make assumptions about what the chart might be
        - If you cannot see either value clearly, write "Not Visible"

        📊 MARKET CONTEXT
        • Current Price: [exact number if visible]
        • Market Structure: [clear definition]
        • Volatility: [quantified]

        🤖 **AI ANALYSIS**

        Symbol: [Exact trading pair]
        Timeframe: [Specific format: M1/M5/M15/H1/H4/D1/W1]

        📊 **MARKET SUMMARY**
        Current Price: [Exact number]
        Support Levels: [Specific numbers]
        Resistance Levels: [Specific numbers]
        Market Structure: [Clear trend definition]
        Volatility: [Quantified condition]

        📈 **TECHNICAL ANALYSIS**
        Price Movement: [Detailed analysis including:]
        - Exact price range and direction
        - Specific chart patterns
        - Key breakout/breakdown levels
        - Volume confirmation
        - Trend strength assessment
        - Market structure analysis

        **TECHNICAL INDICATORS**
        [Only include visible indicators]

        🎯 *RSI INDICATOR*
        Current Values: [Exact numbers]
        Signal: [Clear direction]
        Analysis: [Detailed interpretation]
        - Price correlation
        - Historical context
        - Divergence signals

        📊 *MACD INDICATOR*
        Current Values: [Exact numbers]
        Signal: [Clear direction]
        Analysis: [Detailed interpretation]
        - Momentum strength
        - Trend confirmation
        - Signal reliability

        💡 **TRADING SIGNAL**
        Action: [BUY/SELL/HOLD]
        Entry Price: [Exact level if BUY/SELL]
        Stop Loss: [Specific price]
        Take Profit: [Specific target]

        Signal Reasoning:
        - Technical justification
        - Multiple timeframe context
        - Risk/reward analysis
        - Market structure alignment
        - Volume confirmation

        Risk Assessment:
        - Position size calculation
        - Volatility consideration
        - Invalidation scenarios
        - Key risk levels

        1. MARKET CONTEXT
        - Symbol/Asset (exact pair)
        - Timeframe (specific format)
        - Current price (exact number)
        - Key price levels (specific numbers)
        - Market structure (clear definition)
        - Volatility conditions (quantified)

        2. ANALYSIS CONFIDENCE
        Calculate and display confidence level (0-100%) based on:
        - Pattern Clarity (0-25%): How clear and well-defined are the chart patterns
        - Technical Alignment (0-25%): How well do different indicators align
        - Volume Confirmation (0-25%): Does volume support the analysis
        - Signal Reliability (0-25%): How reliable is the generated signal

        Confidence Level: [Only for BUY/SELL]

        IMPORTANT FINAL OUTPUT FORMAT:
        After completing all the analysis steps above, your entire response MUST be a single, valid JSON object.
        Do not include any text, explanations, or markdown outside of this JSON structure.
        Adhere strictly to the following JSON schema. If a value for a field cannot be determined from the image or analysis, use `null` for that field. For array fields like Support_Levels, use an empty array `[]` if none are found.

        JSON Schema:
        {
        "Symbol": "string_or_null",
        "Timeframe": "string_or_null",
        "Current_Price": "float_or_null",
        "Key_Price_Levels": {
            "Support_Levels": ["float_or_null", "..."],
            "Resistance_Levels": ["float_or_null", "..."]
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
                "Current_Values": "string_or_null (e.g., '55.2' or 'Not Visible')",
                "Signal": "string_or_null (e.g., 'Neutral', 'Bullish', 'Bearish')",
                "Analysis": "string_or_null"
            },
            "MACD_Indicator": {
                "Current_Values": "string_or_null (e.g., 'MACD: 0.0012, Signal: 0.0008, Histogram: 0.0004' or 'Not Visible')",
                "Signal": "string_or_null",
                "Analysis": "string_or_null"
            },
            "Other_Indicator": "string_or_null (Describe any other visible indicator or 'Not Visible')"
        },
        "Entry_Price": "float_or_null",
        "Stop_Loss": "float_or_null",
        "Take_Profit": "float_or_null",
        "Technical_Justification": "string_or_null",
        "Multiple_Timeframe_Context": "string_or_null",
        "Risk_Assessment": {
            "Position_Size_Calculation": "string_or_null",
            "Volatility_Consideration": "string_or_null",
            "Invalidation_Scenarios": "string_or_null",
            "Key_Risk_Levels": "string_or_null"
        },
        "Analysis_Confidence": {
            "Pattern_Clarity_Percent": "integer_0_to_25_or_null",
            "Technical_Alignment_Percent": "integer_0_to_25_or_null",
            "Volume_Confirmation_Percent": "integer_0_to_25_or_null",
            "Signal_Reliability_Percent": "integer_0_to_25_or_null",
            "Confidence_Level_Percent": "integer_0_to_100_or_null (Overall confidence based on the above factors)"
        }
        }

        Ensure all string values are properly escaped if they contain special characters.
        The entire output should be a single JSON object starting with `{` and ending with `}`.
        EOT;
    
    // --- Analyze Image Endpoint ---
    public function analyzeImage(Request $request)
    {
        // Increase PHP execution time limit for this request
        ini_set('max_execution_time', 300); // 5 minutes
        set_time_limit(300); // 5 minutes
        // First check if the user is authenticated
        if (!auth()->check()) {
            // For non-authenticated users, return an error
            return response()->json([
                'error' => 'Authentication required',
                'message' => 'You must be signed in to use this feature'
            ], 401);
        }
        
        $user = Auth::user();
        $userId = Auth::user()->id;
        
        // Calculate the estimated token cost before processing the request
        $modelId = $request->input('model_id', 'gpt-4o-2024-08-06'); // Default model
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
        
        // 1) Ingest image
        Log::debug('Image analysis request received', [
            'has_file' => $request->hasFile('image'),
            'has_image_input' => $request->filled('image'),
            'request_keys' => $request->keys(),
            'content_type' => $request->header('Content-Type'),
            'all_files' => $request->allFiles(),
            'file_exists' => isset($_FILES['image']),
            'file_details' => isset($_FILES['image']) ? $_FILES['image'] : 'No file data'
        ]);
        
        $imageUrl = null;
        if ($request->hasFile('image')) {
            try {
                // Log before validation
                $file = $request->file('image');
                Log::debug('Before validation - File details', [
                    'is_valid' => $file->isValid(),
                    'error_code' => $file->getError(),
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'extension' => $file->getClientOriginalExtension() ?: 'unknown',
                    'size' => $file->getSize()
                ]);
                
                $request->validate(['image' => 'file|image|max:10240']);
                $ext  = $file->getClientOriginalExtension() ?: 'png'; // Default to png if no extension
                Log::debug('Processing uploaded file', [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'extension' => $ext,
                    'size' => $file->getSize()
                ]);
                
                // Upload to Firebase Storage if user is authenticated
                if (auth()->check()) {
                    try {
                        // Generate a unique filename
                        $userId = auth()->id();
                        $filename = 'chart_analysis/' . $userId . '/' . time() . '_' . Str::random(20) . '.' . $ext;
                        
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
                        
                        Log::info('Image uploaded to Firebase Storage', [
                            'filename' => $filename,
                            'url' => $imageUrl
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Firebase Storage error: ' . $e->getMessage());
                        // Continue with analysis even if Firebase upload fails
                    }
                }
                
                $imageData = 'data:image/'.$ext.';base64,'.base64_encode(file_get_contents($file->getRealPath()));
            } catch (\Exception $e) {
                Log::error('Error processing uploaded file', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['error' => 'Invalid image file: ' . $e->getMessage()], 422);
            }
        } elseif ($request->filled('image')) {
            $raw = $request->input('image');
            Log::debug('Processing image input', [
                'input_type' => gettype($raw),
                'starts_with_data_image' => strpos($raw, 'data:image') === 0,
                'input_length' => strlen($raw)
            ]);
            $imageData = strpos($raw, 'data:image') === 0 ? $raw : 'data:image/jpeg;base64,'.$raw;
        } else {
            // Check if this is a multipart/form-data request without a valid file
            $contentType = $request->header('Content-Type');
            if (strpos($contentType, 'multipart/form-data') !== false) {
                Log::warning('Multipart form data received but no valid image file found', [
                    'content_type' => $contentType,
                    'all_input' => $request->all()
                ]);
                return response()->json(['error'=>'Invalid image format in multipart request'], 422);
            }
            
            Log::warning('No image provided in request');
            return response()->json(['error'=>'No image provided'],422);
        }

        // 1) Use the exact GPT-4o model from the UI
        $modelId = $request->input('model_id','gpt-4o-2024-08-06');

        // 2) Build a richer, higher-temperature payload with an extra user prompt
        $payload = [
            'model'       => $modelId,
            'temperature' => 1.0,        // <-- ramped up
            'max_completion_tokens'  => 5000,       // <-- plenty of room
            'messages'    => [
                // a) your existing systemPrompt
                ['role'=>'system', 'content'=>$this->systemPrompt],

                // b) NEW second user prompt to fill every nested key
                ['role'=>'user', 'content'=><<<EOD
                    Please analyze this chart image and return **all** fields exactly as defined in the JSON schema. Do not omit any Resistance_Levels, Entry_Price, Stop_Loss, Take_Profit, or Confidence metrics.
                    EOD
                ],

                // c) the multimodal image payload
                [
                  'role'=>'user',
                  'content'=>[[
                    'type'=>'image_url',
                    'image_url'=>['url'=>$imageData]
                  ]]
                ],
            ],
        ];

        try {
            $resp    = Http::timeout(300) // Increase timeout to 5 minutes
                           ->withHeaders([
                               'Authorization'=>'Bearer '.env('OPENAI_API_KEY'),
                               'Content-Type'=>'application/json'
                           ])
                           ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (! $resp->successful()) {
                $err = $resp->json()['error']['message'] ?? 'OpenAI error';
                throw new \Exception($err);
            }

            $body    = $resp->json();
            $content = trim(preg_replace('/^\s*json\s*/i','',$body['choices'][0]['message']['content'] ?? ''));
            $decoded = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = $this->parseAnalysisResponse($content);
            }

            // Remap as before, now with guaranteed Entry/Stop/TP suggestions
            $result = [
                'Symbol'                        => $decoded['Symbol']                         ?? null,
                'Timeframe'                     => $decoded['Timeframe']                      ?? null,
                'Current_Price'                 => isset($decoded['Current_Price']) 
                                                      ? (float)$decoded['Current_Price'] : null,
                'Key_Price_Levels' => [
                    'Support_Levels'     => $decoded['Key_Price_Levels']['Support_Levels']     ?? [],
                    'Resistance_Levels'  => $decoded['Key_Price_Levels']['Resistance_Levels']  ?? [],
                ],
                'Market_Structure'              => $decoded['Market_Structure']              ?? null,
                'Volatility_Conditions'         => $decoded['Volatility_Conditions']         ?? null,
                'Price_Movement'                => $decoded['Price_Movement']                ?? null,
                'Chart_Patterns'                => $decoded['Chart_Patterns']                ?? null,
                'Key_Breakout_Breakdown_Levels' => $decoded['Key_Breakout_Breakdown_Levels'] ?? null,
                'Volume_Confirmation'           => $decoded['Volume_Confirmation']           ?? null,
                'Trend_Strength_Assessment'     => $decoded['Trend_Strength_Assessment']     ?? null,
                'INDICATORS' => [
                    'RSI_Indicator' => [
                        'Current_Values'=> $decoded['INDICATORS']['RSI_Indicator']['Current_Values'] ?? null,
                        'Signal'        => $decoded['INDICATORS']['RSI_Indicator']['Signal']         ?? null,
                        'Analysis'      => $decoded['INDICATORS']['RSI_Indicator']['Analysis']       ?? null,
                    ],
                    'MACD_Indicator' => [
                        'Current_Values'=> $decoded['INDICATORS']['MACD_Indicator']['Current_Values'] ?? null,
                        'Signal'        => $decoded['INDICATORS']['MACD_Indicator']['Signal']         ?? null,
                        'Analysis'      => $decoded['INDICATORS']['MACD_Indicator']['Analysis']       ?? null,
                    ],
                    'Other_Indicator'=> $decoded['INDICATORS']['Other_Indicator']                ?? null,
                ],
                'Entry_Price'                   => isset($decoded['Entry_Price']) 
                                                      ? (float)$decoded['Entry_Price']   : null,
                'Stop_Loss'                     => isset($decoded['Stop_Loss'])   
                                                      ? (float)$decoded['Stop_Loss']     : null,
                'Take_Profit'                   => isset($decoded['Take_Profit']) 
                                                      ? (float)$decoded['Take_Profit']   : null,
                'Technical_Justification'       => $decoded['Technical_Justification']       ?? null,
                'Multiple_Timeframe_Context'    => $decoded['Multiple_Timeframe_Context']    ?? null,
                'Risk_Assessment' => [
                    'Position_Size_Calculation'=> $decoded['Risk_Assessment']['Position_Size_Calculation'] ?? null,
                    'Volatility_Consideration' => $decoded['Risk_Assessment']['Volatility_Consideration']  ?? null,
                    'Invalidation_Scenarios'   => $decoded['Risk_Assessment']['Invalidation_Scenarios']    ?? null,
                    'Key_Risk_Levels'          => $decoded['Risk_Assessment']['Key_Risk_Levels']           ?? null,
                ],
                'Analysis_Confidence' => [
                    'Pattern_Clarity_Percent'     => isset($decoded['Analysis_Confidence']['Pattern_Clarity_Percent'])
                                                      ? (int)$decoded['Analysis_Confidence']['Pattern_Clarity_Percent']     : null,
                    'Technical_Alignment_Percent' => isset($decoded['Analysis_Confidence']['Technical_Alignment_Percent'])
                                                      ? (int)$decoded['Analysis_Confidence']['Technical_Alignment_Percent'] : null,
                    'Volume_Confirmation_Percent' => isset($decoded['Analysis_Confidence']['Volume_Confirmation_Percent'])
                                                      ? (int)$decoded['Analysis_Confidence']['Volume_Confirmation_Percent'] : null,
                    'Signal_Reliability_Percent'  => isset($decoded['Analysis_Confidence']['Signal_Reliability_Percent'])
                                                      ? (int)$decoded['Analysis_Confidence']['Signal_Reliability_Percent']  : null,
                    'Confidence_Level_Percent'    => isset($decoded['Analysis_Confidence']['Confidence_Level_Percent'])
                                                      ? (int)$decoded['Analysis_Confidence']['Confidence_Level_Percent']    : null,
                ],
            ];

            // Get actual token counts from the API response if available
            $actualInputTokens = $body['usage']['prompt_tokens'] ?? $inputTokens;
            $actualOutputTokens = $body['usage']['completion_tokens'] ?? $outputTokens;
            
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
            
            // Extract symbol and timeframe for the title if available
            $symbol = $result['Symbol'] ?? 'Chart';
            $timeframe = $result['Timeframe'] ?? '';
            $chartTitle = "{$symbol} {$timeframe} Analysis";
            
            // Store the request in history
            $chartUrls = [];
            if ($imageUrl) {
                $chartUrls[] = $imageUrl;
            }
            
            \App\Models\History::create([
                'user_id' => $user->id,
                'type' => 'image_analysis',
                'title' => $chartTitle,
                'content' => json_encode($result),
                'model' => $modelId,
                'provider' => 'openai',
                'chart_urls' => $chartUrls
            ]);
            
            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('analyzeImage failed', ['error'=>$e->getMessage()]);
            return response()->json(['error'=>$e->getMessage()],500);
        }
    }
}
