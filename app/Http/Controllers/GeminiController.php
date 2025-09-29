<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\History;
use App\Models\ChatSession;
use App\Models\ChatMessage;

class GeminiController extends Controller
{
    /**
     * Check if the Gemini API key is valid
     * 
     * @param string $apiKey API key to validate
     * @return array Status array with 'valid' boolean and 'message' string
     */
    protected function checkApiKeyStatus($apiKey)
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json'
                ])
                ->get('https://generativelanguage.googleapis.com/v1/models?key=' . $apiKey);
            
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
            'id' => 'gemini-1.5-pro',
            'name' => 'Gemini 1.5 Pro',
            'description' => 'Advanced vision-language capabilities',
            'premium' => true,
            'creditCost' => 1.5,
            'beta' => false
        ],
        [
            'id' => 'gemini-1.5-flash',
            'name' => 'Gemini 1.5 Flash',
            'description' => 'Fast and efficient analysis',
            'premium' => true,
            'creditCost' => 1.0,
            'beta' => false
        ],
        [
            'id' => 'gemini-pro',
            'name' => 'Gemini Pro',
            'description' => 'Text-only model without vision capabilities',
            'premium' => false,
            'creditCost' => 0.5,
            'beta' => false
        ]
    ];

    private $modelBaseCosts = [
        'gemini-1.5-pro' => ['input' => 0.0025, 'output' => 0.0075],
        'gemini-1.5-flash' => ['input' => 0.0010, 'output' => 0.0030],
        'gemini-pro' => ['input' => 0.0005, 'output' => 0.0015]
    ];

    private $estimatedAnalysisTokens = ['input' => 1000, 'output' => 2000];
    private $estimatedChatTokens = ['input' => 500, 'output' => 1000];

    // --- Cost Calculation ---
    public function calculateCost($model, $inputTokens, $outputTokens)
    {
        // Check if we have pricing for this model
        if (!isset($this->modelBaseCosts[$model])) {
            return [
                'error' => 'Unknown model',
                'cost' => 0
            ];
        }

        $inputCost = $this->modelBaseCosts[$model]['input'] * $inputTokens;
        $outputCost = $this->modelBaseCosts[$model]['output'] * $outputTokens;
        $totalCost = $inputCost + $outputCost;

        return [
            'inputCost' => round($inputCost, 6),
            'outputCost' => round($outputCost, 6),
            'totalCost' => round($totalCost, 6)
        ];
    }

    // --- Analyze Image Endpoint ---
    public function analyzeImage(Request $request)
    {
        // First check what kind of input we have (file or base64)
        if ($request->hasFile('image')) {
            // Validate file upload
            $request->validate([
                'image' => 'file|image|max:10240', // Max 10MB image file
            ]);
            
            // Get the uploaded image file
            $imageFile = $request->file('image');
            
            // Convert image to base64 for Gemini API
            $imageExtension = $imageFile->getClientOriginalExtension();
            $base64Image = base64_encode(file_get_contents($imageFile->getRealPath()));
            $mimeType = 'image/' . $imageExtension;
        } 
        elseif ($request->has('image_base64')) {
            // Validate base64 string
            $request->validate([
                'image_base64' => 'required|string',
            ]);
            
            $base64Data = $request->input('image_base64');
            
            // Check if the base64 string includes the data URI scheme
            if (preg_match('/^data:(.*?);base64,(.*)$/', $base64Data, $matches)) {
                $mimeType = $matches[1];
                $base64Image = $matches[2];
            } else {
                // Assume it's a pure base64 string and guess JPEG
                $base64Image = $base64Data;
                $mimeType = 'image/jpeg';
            }
        }
        else {
            return response()->json([
                'message' => 'No image provided',
                'errors' => [
                    'image' => ['Please provide either an image file upload or a base64-encoded image string']
                ]
            ], 422);
        }
        
        // Get the model ID with default
        $modelId = $request->input('model_id', 'gemini-1.5-pro'); // Default model
        
        // Get Gemini API key from environment
        $apiKey = env('GOOGLE_GEMINI_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'Gemini API key not configured'], 500);
        }
        
        // Check API key validity
        $keyStatus = $this->checkApiKeyStatus($apiKey);
        if (!$keyStatus['valid']) {
            return response()->json(['error' => 'Invalid Gemini API key: ' . $keyStatus['message']], 401);
        }
        
        // Prepare the system prompt for financial chart analysis
        $systemPrompt = "";
        $userPrompt = "Analyze this financial chart and provide a detailed technical analysis. ";
        $userPrompt .= "Your response should be in a structured JSON format with the following fields: ";
        $userPrompt .= "symbol, timeframe, action, current_price, entry_price, stop_loss, take_profit, confidence, ";
        $userPrompt .= "market_structure, volatility, key_levels, and indicators.";

        // Call the Gemini API to analyze the image
        try {
            // Build parts array for Gemini API
            $parts = [
                // Text part
                [
                    'text' => $userPrompt
                ],
                // Image part
                [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => $base64Image
                    ]
                ]
            ];
            
            // Prepare payload for Gemini API
            $payload = [
                'contents' => [
                    [
                        'parts' => $parts
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'topP' => 0.8,
                    'topK' => 40,
                    'maxOutputTokens' => 2048
                ]
            ];
            
            // Use the requested model without automatic fallback
            $parsedContent = $this->tryImageAnalysis($modelId, $payload, $apiKey, $base64Image);
            
            // If there's an error, return it directly
            if (is_array($parsedContent) && isset($parsedContent['error'])) {
                return response()->json([
                    'error' => 'Analysis failed with model ' . $modelId . ': ' . $parsedContent['error'],
                    'model_tried' => [$modelId]
                ], 500);
            }

            // Save history for authenticated users
            if (auth()->check()) {
                $userId = auth()->id();
                $resultSummary = json_encode($parsedContent);
                
                // Extract symbol and timeframe for the title if available
                $symbol = $parsedContent['symbol'] ?? 'Chart';
                $timeframe = $parsedContent['timeframe'] ?? '';
                $chartTitle = "{$symbol} {$timeframe} Analysis";
                
                History::create([
                    'user_id' => $userId,
                    'title' => $chartTitle, 
                    'model' => $modelId,
                    'prompt' => $userPrompt,
                    'result_summary' => Str::limit($resultSummary, 255),
                    'cost' => $this->calculateCost($modelId, $this->estimatedAnalysisTokens['input'], $this->estimatedAnalysisTokens['output'])['totalCost'],
                    'credits' => 1
                ]);
            }
            
            // Return response
            return response()->json($parsedContent);
            
        } catch (\Exception $e) {
            Log::error('Gemini API error: ' . $e->getMessage());
            return response()->json(['error' => 'Error analyzing image: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Try to analyze an image with a given model
     * 
     * @param string $modelId Model ID to use
     * @param array $payload Request payload
     * @param string $apiKey Gemini API key
     * @param string $base64Image Base64-encoded image data
     * @return array|string Parsed content or error
     */
    protected function tryImageAnalysis($modelId, $payload, $apiKey, $base64Image)
    {
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json'
                ])
                ->post(
                    "https://generativelanguage.googleapis.com/v1/models/{$modelId}:generateContent?key={$apiKey}",
                    $payload
                );

            // Check for empty response
            if ($response->body() === '' || $response->body() === '[]' || strlen($response->body()) === 0) {
                Log::error("Gemini API returned empty response for model: {$modelId}");
                return ['error' => "Empty response from Gemini API"];
            }

            // Check if response was successful
            if ($response->successful()) {
                $data = $response->json();
                
                // Check if response has content
                if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    Log::error("No content found in Gemini API response: " . json_encode($data));
                    return ['error' => "No content found in API response"];
                }
                
                // Extract the text content
                $content = $data['candidates'][0]['content']['parts'][0]['text'];
                
                // Try to parse as JSON
                try {
                    $jsonContent = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                    return $jsonContent;
                } catch (\JsonException $e) {
                    // If not valid JSON, use regex fallback parsing
                    Log::warning("Gemini response not valid JSON, using regex parsing: " . $e->getMessage());
                    return $this->parseAnalysisResponse($content);
                }
            } else {
                $errorData = $response->json();
                $errorMessage = isset($errorData['error']['message']) 
                    ? $errorData['error']['message'] 
                    : "API error with status code: {$response->status()}";
                
                Log::error("Gemini API error for model {$modelId}: {$errorMessage}");
                return ['error' => $errorMessage];
            }
        } catch (\Exception $e) {
            Log::error("Exception calling Gemini API with model {$modelId}: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Parse an analysis response text into structured data using regex
     * This is a fallback method if JSON parsing fails
     * 
     * @param string $text Analysis text to parse
     * @return array Structured data
     */
    protected function parseAnalysisResponse($text)
    {
        // Process and clean the text
        $text = html_entity_decode($text);
        $text = str_replace('*', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        
        // Helper function to trim special characters
        $robustTrim = function($value) {
            if (!is_string($value)) return $value;
            return trim(preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', $value));
        };
        
        // Helper function to extract pattern
        $extract = function($pattern, $text) use ($robustTrim) {
            if (preg_match($pattern, $text, $matches)) {
                return $robustTrim($matches[1]);
            }
            return null;
        };
        
        // Helper function to extract list
        $extractList = function($pattern, $text) use ($extract, $robustTrim) {
            $value = $extract($pattern, $text);
            if ($value) {
                $items = preg_split('/[,\s]+/u', $value);
                return array_map($robustTrim, array_filter($items));
            }
            return [];
        };
        
        // Extract fields using regex patterns
        $result = [
            'symbol' => $extract('/symbol:?\s*([\w\d\/.-]+)/iu', $text),
            'timeframe' => $extract('/timeframe:?\s*([\w\d.-]+)/iu', $text),
            'action' => $extract('/action:?\s*(\w+)/iu', $text),
            'confidence' => $extract('/confidence:?\s*([\d\.]+)%?/iu', $text),
            'market_structure' => $extract('/market[_\s]structure:?\s*([^\n]+?)(?=\s+-\s+\w[\w\s()]*:|\s+###|$)/iu', $text),
            'volatility' => $extract('/volatility:?\s*([^\n]+?)(?=\s+-\s+\w[\w\s()]*:|\s+###|$)/iu', $text),
            'key_levels' => $extractList('/key[_\s]levels:?\s*([^\n]+?)(?=\s+-\s+\w[\w\s()]*:|\s+###|$)/iu', $text),
            'indicators' => $extractList('/indicators:?\s*([^\n]+?)(?=\s+-\s+\w[\w\s()]*:|\s+###|$)/iu', $text),
            'raw_response' => $text // For debugging
        ];
        
        // Extract numeric values and handle decimal separators
        $numericFields = [
            'current_price' => '/(?:\d+\.\s*)?current[_\s]price:?\s*([\d\.,]+)/iu',
            'entry_price' => '/(?:\d+\.\s*)?entry[_\s]price:?\s*([\d\.,]+)/iu',
            'stop_loss' => '/(?:\d+\.\s*)?stop[_\s]loss:?\s*([\d\.,]+)/iu',
            'take_profit' => '/(?:\d+\.\s*)?take[_\s]profit:?\s*([\d\.,]+)/iu'
        ];
        
        foreach ($numericFields as $field => $pattern) {
            $value = $extract($pattern, $text);
            if ($value !== null) {
                $value = str_replace(',', '.', $value); // Replace comma decimal separator
                $result[$field] = (float) $value;
            } else {
                $result[$field] = null;
            }
        }
        
        return $result;
    }
    
    // --- Send Chat Message Endpoint ---
    public function sendChatMessage(Request $request)
    {
        // Validate request
        $validatedData = $request->validate([
            'messages' => 'required|array',
            'model_id' => 'nullable|string'
        ]);
        
        // Get messages and model ID
        $messages = $validatedData['messages'];
        $modelId = $validatedData['model_id'] ?? 'gemini-1.5-pro'; // Default model
        
        // Get API key from environment
        $apiKey = env('GOOGLE_GEMINI_API_KEY');
        if (!$apiKey) {
            return response()->json(['error' => 'Gemini API key not configured'], 500);
        }
        
        // Check API key validity
        $keyStatus = $this->checkApiKeyStatus($apiKey);
        if (!$keyStatus['valid']) {
            return response()->json(['error' => 'Invalid Gemini API key: ' . $keyStatus['message']], 401);
        }
        
        // Get chat session ID if provided
        $chatSessionId = $request->input('session_id');
        $session = null;
        
        // Load existing session if authenticated and session ID provided
        if (auth()->check() && $chatSessionId) {
            $session = ChatSession::where('id', $chatSessionId)
                ->where('user_id', auth()->id())
                ->first();
                
            if (!$session) {
                return response()->json(['error' => 'Chat session not found'], 404);
            }
        }
        
        // Create a new session if authenticated and no session found
        if (auth()->check() && !$session) {
            $session = ChatSession::create([
                'user_id' => auth()->id(),
                'model' => $modelId,
                'title' => 'Chat Session ' . now()->format('Y-m-d H:i:s')
            ]);
        }
        
        // Format messages for Gemini API
        $formattedMessages = [];
        $currentPart = [];
        
        foreach ($messages as $message) {
            if ($message['role'] === 'user') {
                $currentPart[] = ['text' => $message['content']];
            }
        }
        
        // Prepare payload for Gemini API
        $payload = [
            'contents' => [
                [
                    'parts' => $currentPart
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topP' => 0.9,
                'topK' => 40,
                'maxOutputTokens' => 2048
            ]
        ];
        
        try {
            // Call Gemini API
            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json'
                ])
                ->post(
                    "https://generativelanguage.googleapis.com/v1/models/{$modelId}:generateContent?key={$apiKey}",
                    $payload
                );
                
            // Check for empty response
            if ($response->body() === '' || $response->body() === '[]' || strlen($response->body()) === 0) {
                Log::error("Gemini API returned empty chat response for model: {$modelId}");
                return response()->json(['error' => 'Empty response from Gemini API'], 500);
            }
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Extract assistant reply
                if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    Log::error("No assistant reply found in Gemini API response: " . json_encode($data));
                    return response()->json(['error' => 'No assistant reply found in API response'], 500);
                }
                
                $assistantReply = $data['candidates'][0]['content']['parts'][0]['text'];
                
                // Save chat messages for authenticated users
                if (auth()->check() && $session) {
                    // Save the user's message
                    $userMessage = end($messages);
                    if ($userMessage['role'] === 'user') {
                        ChatMessage::create([
                            'session_id' => $session->id,
                            'role' => 'user',
                            'content' => $userMessage['content']
                        ]);
                    }
                    
                    // Save the assistant's reply
                    ChatMessage::create([
                        'session_id' => $session->id,
                        'role' => 'assistant',
                        'content' => $assistantReply
                    ]);
                    
                    // Save to history
                    $userMessage = end($messages)['content'];
                    
                    // Create a meaningful title from the first few words of the user message
                    $chatTitle = Str::limit($userMessage, 30);
                    if (strlen($userMessage) > 30) {
                        $chatTitle .= '...';  
                    }
                    
                    History::create([
                        'user_id' => auth()->id(),
                        'title' => $chatTitle,
                        'model' => $modelId,
                        'prompt' => Str::limit($userMessage, 255),
                        'result_summary' => Str::limit($assistantReply, 255),
                        'cost' => $this->calculateCost($modelId, $this->estimatedChatTokens['input'], $this->estimatedChatTokens['output'])['totalCost'],
                        'credits' => 1
                    ]);
                }
                
                // Return the assistant's reply
                $responseData = [
                    'reply' => $assistantReply
                ];
                
                if ($session) {
                    $responseData['session_id'] = $session->id;
                }
                
                return response()->json($responseData);
            } else {
                $errorData = $response->json();
                $errorMessage = isset($errorData['error']['message']) 
                    ? $errorData['error']['message'] 
                    : "API error with status code: {$response->status()}";
                
                Log::error("Gemini API chat error for model {$modelId}: {$errorMessage}");
                return response()->json(['error' => $errorMessage], $response->status());
            }
        } catch (\Exception $e) {
            Log::error('Gemini chat API error: ' . $e->getMessage());
            return response()->json(['error' => 'Error sending chat message: ' . $e->getMessage()], 500);
        }
    }
    
    // --- Calculate Cost Endpoint ---
    public function calculateCostEndpoint(Request $request)
    {
        // Validate request
        $model = $request->input('model', 'gemini-1.5-pro');
        $inputTokens = (int) $request->input('inputTokens', 1000);
        $outputTokens = (int) $request->input('outputTokens', 2000);
        
        // Calculate cost
        $cost = $this->calculateCost($model, $inputTokens, $outputTokens);
        
        // Return cost calculation
        return response()->json([
            'model' => $model,
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'cost' => $cost
        ]);
    }
}
