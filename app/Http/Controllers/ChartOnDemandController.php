<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Exception;

class ChartOnDemandController extends Controller
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('services.chart_img.api_key', env('CHART_IMG_API_KEY'));
        $this->baseUrl = config('services.chart_img.base_url', 'https://api.chart-img.com');
        $this->timeout = config('services.chart_img.timeout', 30);
        
        if (empty($this->apiKey)) {
            throw new Exception('Chart-IMG API key is not configured. Please set CHART_IMG_API_KEY in your .env file');
        }
    }

    /**
     * Generate an advanced chart with studies (API v2)
     * POST /api/chart/v2/advanced
     */
    public function generateAdvancedChartV2(Request $request)
    {
        try {
            // Validate and transform the payload
            $res = [];
            $payloads = $this->validateAndTransformAdvancedChartV2Payload($request);
            
            foreach($payloads as $payload) {
                // Log the transformed payload for debugging
                Log::info('Chart-IMG API Payload:', $payload);
                
                $result = $this->makeV2Request('POST', '/v2/tradingview/advanced-chart', $payload);
                $uploaded = $this->uploadChartToFirebase($result, auth()->user()->id);
                $res[] = $result;
            }

            if ($request->asArray == true) {
                return [
                    'success' => true,
                    'data' => $res,
                    'message' => 'Advanced chart V2 generated successfully'
                ];
            } else {
                return response()->json([
                    'success' => true,
                    'data' => $res,
                    'message' => 'Advanced chart V2 generated successfully'
                ]);
            }
        } catch (Exception $e) {
            Log::error('Advanced chart V2 generation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of supported exchanges (API v3)
     * GET /api/chart/exchanges
     */
    public function getExchangeList(): JsonResponse
    {
        try {
            $result = $this->makeV3Request('GET', '/v3/tradingview/exchange/list', [], true);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Exchange list retrieved successfully'
            ]);
        } catch (Exception $e) {
            Log::error('Exchange list retrieval failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get symbols for a specific exchange (API v3)
     * GET /api/chart/exchanges/{exchangeId}/symbols
     */
    public function getExchangeSymbols(Request $request, string $exchangeId): JsonResponse
    {
        try {
            $filters = $request->only(['symbol', 'type']);
            $result = $this->makeV3Request('GET', "/v3/tradingview/exchange/{$exchangeId}", $filters, true);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => "Symbols for {$exchangeId} retrieved successfully"
            ]);
        } catch (Exception $e) {
            Log::error("Exchange symbols retrieval failed for {$exchangeId}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get chart image as base64 data URL for frontend display
     * POST /api/chart/v2/advanced/base64
     */
    public function generateAdvancedChartV2Base64(Request $request): JsonResponse
    {
        try {
            $payload = $this->validateAndTransformAdvancedChartV2Payload($request);
            
            Log::info('Chart-IMG API Payload:', $payload);
            
            $result = $this->makeV2Request('POST', '/v2/tradingview/advanced-chart', $payload);
            
            // Convert to data URL for frontend use
            if (is_array($result) && isset($result['data'])) {
                $dataUrl = 'data:' . $result['content_type'] . ';base64,' . $result['data'];
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'image_data_url' => $dataUrl,
                        'content_type' => $result['content_type'],
                        'size' => $result['size'],
                        'type' => $result['type']
                    ],
                    'message' => 'Chart generated successfully as base64'
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => $result,
                    'message' => 'Chart generated successfully'
                ]);
            }
        } catch (Exception $e) {
            Log::error('Advanced chart V2 base64 generation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate and transform advanced chart V2 payload to Chart-IMG API format
     */
    private function validateAndTransformAdvancedChartV2Payload(Request $request): array
    {
        // Basic validation
        $request->validate([
            'symbol' => 'required|string',
            'interval' => 'sometimes|string',
            'intervals' => 'sometimes|array',
            'width' => 'sometimes|integer|min:400',
            'height' => 'sometimes|integer|min:300',
            'style' => 'sometimes|in:bar,candle,line,area,heikinAshi,hollowCandle,baseline,hiLo,column',
            'theme' => 'sometimes|in:light,dark',
            'scale' => 'sometimes|in:regular,percent,indexedTo100,logarithmic',
            'session' => 'sometimes|in:regular,extended',
            'timezone' => 'sometimes|string',
            'format' => 'sometimes|in:png,jpeg',
            // 'range' => 'sometimes|string|in:1D,5D,1M,3M,6M,1Y,5Y,ALL,DTD,WTD,MTD,YTD',
            'override' => 'sometimes|array',
            'studies' => 'sometimes|array',
            'studies.*.name' => 'sometimes|string',
            'studies.*.input' => 'sometimes|array',
            'studies.*.override' => 'sometimes|array',
            'studies.*.forceOverlay' => 'sometimes|boolean',
            'drawings' => 'sometimes|array',
            'drawings.*.name' => 'sometimes|string',
            'drawings.*.input' => 'sometimes|array',
            'drawings.*.override' => 'sometimes|array',
            'drawings.*.zOrder' => 'sometimes|in:top,bottom',
            'shiftLeft' => 'sometimes|integer|min:1|max:1000',
            'shiftRight' => 'sometimes|integer|min:1|max:1000',
            'watermark' => 'sometimes|string',
            'watermarkSize' => 'sometimes|in:16,32,64,128',
            'watermarkOpacity' => 'sometimes|numeric|min:0.1|max:1.0'
        ]);

        $intervals = $request->input('intervals');
        $payloads = [];

        foreach($intervals as $int) {
            // Start with default payload structure
            $payload = [
                'symbol' => $request->input('symbol'),
                'interval' => $int,
                'width' => $request->input('width', 800),
                'height' => $request->input('height', 600),
                'style' => $request->input('style', 'candle'),
                'theme' => $request->input('theme', 'light'),
                'scale' => $request->input('scale', 'regular'),
                'session' => $request->input('session', 'regular'),
                'timezone' => $request->input('timezone', 'Etc/UTC'),
                'format' => $request->input('format', 'png')
            ];
    
            // Transform studies array
            if ($request->has('studies') && is_array($request->input('studies'))) {
                $payload['studies'] = [];
                
                foreach ($request->input('studies') as $study) {
                    if (!isset($study['name'])) {
                        continue; // Skip invalid studies
                    }
                    
                    $transformedStudy = [
                        'name' => $this->mapStudyName($study['name'])
                    ];
                    
                    // Transform input parameters from your format to Chart-IMG format
                    if (isset($study['input']) && is_array($study['input'])) {
                        $transformedStudy['input'] = $this->transformStudyInput($study['name'], $study['input']);
                    }
                    
                    // Add override parameters if provided
                    if (isset($study['override']) && is_array($study['override'])) {
                        $transformedStudy['override'] = $study['override'];
                    }
                    
                    // Add forceOverlay if provided
                    if (isset($study['forceOverlay'])) {
                        $transformedStudy['forceOverlay'] = (bool) $study['forceOverlay'];
                    }
                    
                    $payload['studies'][] = $transformedStudy;
                }
            }
    
            // Transform drawings array
            if ($request->has('drawings') && is_array($request->input('drawings'))) {
                $payload['drawings'] = [];
                
                foreach ($request->input('drawings') as $drawing) {
                    if (!isset($drawing['name']) || !isset($drawing['input'])) {
                        continue; // Skip invalid drawings
                    }
                    
                    $mappedName = $this->mapDrawingName($drawing['name']);
                    $transformedInput = $this->transformDrawingInput($drawing['name'], $drawing['input']);
                    
                    // Skip if we couldn't map the drawing or transform the input
                    if (!$mappedName || !$transformedInput) {
                        Log::warning("Skipping unsupported drawing: " . $drawing['name']);
                        continue;
                    }
                    
                    $transformedDrawing = [
                        'name' => $mappedName,
                        'input' => $transformedInput
                    ];
                    
                    // Add override parameters if provided
                    if (isset($drawing['override']) && is_array($drawing['override'])) {
                        $transformedDrawing['override'] = $drawing['override'];
                    }
                    
                    // Add zOrder if provided
                    if (isset($drawing['zOrder']) && in_array($drawing['zOrder'], ['top', 'bottom'])) {
                        $transformedDrawing['zOrder'] = $drawing['zOrder'];
                    }
                    
                    $payload['drawings'][] = $transformedDrawing;
                }
            }
    
            // Add override parameters if provided
            if ($request->has('override') && is_array($request->input('override'))) {
                $payload['override'] = $request->input('override');
            }
    
            // Add shift parameters if provided
            if ($request->has('shiftLeft')) {
                $payload['shiftLeft'] = $request->input('shiftLeft');
            }
            
            if ($request->has('shiftRight')) {
                $payload['shiftRight'] = $request->input('shiftRight');
            }
    
            // Add watermark parameters if provided
            if ($request->has('watermark')) {
                $payload['watermark'] = $request->input('watermark');
            }
            
            if ($request->has('watermarkSize')) {
                $payload['watermarkSize'] = $request->input('watermarkSize');
            }
            
            if ($request->has('watermarkOpacity')) {
                $payload['watermarkOpacity'] = $request->input('watermarkOpacity');
            }
    
            // Remove null values to keep payload clean
            Log::info('Payload values : '. json_encode($payload));
            $payloads[] = $this->removeNullValues($payload);
        }

        return $payloads;
    }

    /**
     * Map your study names to Chart-IMG API study names
     */
    private function mapStudyName(string $studyName): string
    {
        $studyMapping = [
            'Bollinger Bands' => 'Bollinger Bands',
            'MACD' => 'MACD',
            'VWAP' => 'VWAP',
            'Moving Average' => 'Moving Average',
            'Relative Strength Index' => 'Relative Strength Index',
            'RSI' => 'Relative Strength Index',
            'Volume' => 'Volume',
            'Stochastic' => 'Stochastic',
            'Moving Average Exponential' => 'Moving Average Exponential',
            'EMA' => 'Moving Average Exponential'
        ];

        return $studyMapping[$studyName] ?? $studyName;
    }

    /**
     * Transform study input parameters to Chart-IMG format
     */
    private function transformStudyInput(string $studyName, array $input): array
    {
        // Handle different study input formats
        switch ($studyName) {
            case 'Bollinger Bands':
                return [
                    'in_0' => $input['in_0'] ?? $input['length'] ?? 20,
                    'in_1' => $input['in_1'] ?? $input['mult'] ?? 2
                ];
                
            case 'MACD':
                return [
                    'in_0' => $input['in_0'] ?? $input['fastLength'] ?? 12,
                    'in_1' => $input['in_1'] ?? $input['slowLength'] ?? 26,
                    'in_2' => $input['in_2'] ?? $input['signalLength'] ?? 9,
                    'in_3' => $input['in_3'] ?? $input['source'] ?? 'close'
                ];
                
            case 'VWAP':
                // VWAP usually doesn't need input parameters
                return [];
                
            case 'Moving Average':
            case 'Moving Average Exponential':
                return [
                    'length' => $input['length'] ?? $input['in_0'] ?? 20,
                    'source' => $input['source'] ?? 'close'
                ];
                
            case 'Relative Strength Index':
                return [
                    'length' => $input['length'] ?? $input['in_0'] ?? 14
                ];
            
            default:
                return null;
        }
    }
    /**
     * Remove null values from array recursively
     */
    private function removeNullValues(array $array): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            if ($value === null) {
                continue;
            }
            
            if (is_array($value)) {
                $cleanedValue = $this->removeNullValues($value);
                if (!empty($cleanedValue)) {
                    $result[$key] = $cleanedValue;
                }
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Test endpoint to debug payload transformation
     * POST /api/chart/debug/transform
     */
    public function debugTransform(Request $request): JsonResponse
    {
        try {
            $originalPayload = $request->all();
            $transformedPayload = $this->validateAndTransformAdvancedChartV2Payload($request);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'original_payload' => $originalPayload,
                    'transformed_payload' => $transformedPayload,
                    'transformation_notes' => [
                        'drawings_mapped' => $this->getDrawingMappingInfo($originalPayload['drawings'] ?? []),
                        'studies_mapped' => $this->getStudyMappingInfo($originalPayload['studies'] ?? [])
                    ]
                ],
                'message' => 'Payload transformation preview'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->getTrace()
            ], 422);
        }
    }

    /**
     * Get drawing mapping information for debugging
     */
    private function getDrawingMappingInfo(array $drawings): array
    {
        $info = [];
        foreach ($drawings as $index => $drawing) {
            $originalName = $drawing['name'] ?? 'unknown';
            $mappedName = $this->mapDrawingName($originalName);
            $info[] = [
                'index' => $index,
                'original_name' => $originalName,
                'mapped_name' => $mappedName,
                'supported' => $mappedName !== null
            ];
        }
        return $info;
    }

    /**
     * Get study mapping information for debugging
     */
    private function getStudyMappingInfo(array $studies): array
    {
        $info = [];
        foreach ($studies as $index => $study) {
            $originalName = $study['name'] ?? 'unknown';
            $mappedName = $this->mapStudyName($originalName);
            $info[] = [
                'index' => $index,
                'original_name' => $originalName,
                'mapped_name' => $mappedName
            ];
        }
        return $info;
    }

    /**
     * Map your drawing names to Chart-IMG API drawing names
     */
    private function mapDrawingName(string $drawingName): ?string
    {
        $drawingMapping = [
            'Rectangle Zone' => 'Rectangle',
            'Resistance Line' => 'Horizontal Line',
            'Support Line' => 'Horizontal Line',
            'Long Position' => 'Long Position',
            'Short Position' => 'Short Position',
            'Trend Line' => 'Trend Line',
            'Horizontal Line' => 'Horizontal Line',
            'Vertical Line' => 'Vertical Line'
        ];

        return $drawingMapping[$drawingName] ?? null;
    }

    /**
     * Transform drawing input parameters to Chart-IMG format
     */
    private function transformDrawingInput(string $drawingName, array $input): ?array
    {
        switch ($drawingName) {
            case 'Rectangle Zone':
                // Rectangle requires startDatetime, startPrice, endDatetime, endPrice
                return [
                    'startDatetime' => $input['startDatetime'] ?? '',
                    'startPrice' => $input['startPrice'] ?? 0,
                    'endDatetime' => $input['endDatetime'] ?? '',
                    'endPrice' => $input['endPrice'] ?? 0,
                    'text' => $input['text'] ?? ''
                ];
                
            case 'Resistance Line':
            case 'Support Line':
                // Horizontal Line requires price
                return [
                    'price' => $input['price'] ?? 0,
                    'text' => $input['text'] ?? ''
                ];
                
            case 'Long Position':
                // Long Position requires specific parameters
                return [
                    'startDatetime' => $input['startDatetime'] ?? '',
                    'entryPrice' => $input['entryPrice'] ?? 0,
                    'targetPrice' => $input['targetPrice'] ?? 0,
                    'stopPrice' => $input['stopPrice'] ?? 0,
                    'endDatetime' => $input['endDatetime'] ?? null
                ];
                
            case 'Short Position':
                // Short Position requires specific parameters
                return [
                    'startDatetime' => $input['startDatetime'] ?? '',
                    'entryPrice' => $input['entryPrice'] ?? 0,
                    'targetPrice' => $input['targetPrice'] ?? 0,
                    'stopPrice' => $input['stopPrice'] ?? 0,
                    'endDatetime' => $input['endDatetime'] ?? null
                ];
                
            case 'Trend Line':
                return [
                    'startDatetime' => $input['startDatetime'] ?? '',
                    'startPrice' => $input['startPrice'] ?? 0,
                    'endDatetime' => $input['endDatetime'] ?? '',
                    'endPrice' => $input['endPrice'] ?? 0,
                    'text' => $input['text'] ?? ''
                ];
                
            default:
                return $input;
        }
    }

    /**
     * Make API v2 request
     */
    private function makeV2Request(string $method, string $endpoint, array $payload = [], bool $expectJson = false, int $timeout = null)
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json'
        ];

        try {
            $response = Http::timeout($timeout ?? $this->timeout)
                ->withHeaders($headers)
                ->$method($this->baseUrl . $endpoint, $payload);

            if (!$response->successful()) {
                $this->handleApiError($response);
            }

            // Handle different response types
            if ($expectJson) {
                // For storage endpoints that return JSON
                try {
                    return $response->json();
                } catch (Exception $e) {
                    Log::error('Failed to decode JSON response: ' . $e->getMessage());
                    throw new Exception('Invalid JSON response from Chart-IMG API');
                }
            } else {
                // For direct image endpoints that return binary data
                $contentType = $response->header('Content-Type');
                
                if (str_contains($contentType, 'image/')) {
                    // Return binary image data as base64 encoded string for JSON response
                    
                    return [
                        'type' => 'image',
                        'content_type' => $contentType,
                        'data' => base64_encode($response->body()),
                        'size' => strlen($response->body()),
                        'request' => $payload
                    ];
                } elseif (str_contains($contentType, 'application/json')) {
                    // Sometimes Chart-IMG returns JSON even for image endpoints (errors)
                    try {
                        return $response->json();
                    } catch (Exception $e) {
                        return ['error' => 'Unable to decode response'];
                    }
                } else {
                    // Unknown content type, return as base64
                    return [
                        'type' => 'binary',
                        'content_type' => $contentType,
                        'data' => base64_encode($response->body()),
                        'size' => strlen($response->body()),
                        'request' => $payload
                    ];
                }
            }
        } catch (Exception $e) {
            Log::error('Chart-IMG API request failed: ' . $e->getMessage());
            throw new Exception('Chart-IMG API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Make API v3 request
     */
    private function makeV3Request(string $method, string $endpoint, array $params = [], bool $expectJson = false)
    {
        $headers = [
            'x-api-key' => $this->apiKey
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->$method($this->baseUrl . $endpoint, ['query' => $params]);

            if (!$response->successful()) {
                $this->handleApiError($response);
            }

            // Handle different response types
            if ($expectJson) {
                try {
                    return $response->json();
                } catch (Exception $e) {
                    Log::error('Failed to decode JSON response: ' . $e->getMessage());
                    throw new Exception('Invalid JSON response from Chart-IMG API');
                }
            } else {
                // For binary responses
                $contentType = $response->header('Content-Type');
                
                if (str_contains($contentType, 'image/')) {
                    return [
                        'type' => 'image',
                        'content_type' => $contentType,
                        'data' => base64_encode($response->body()),
                        'size' => strlen($response->body())
                    ];
                } else {
                    return $response->json();
                }
            }
        } catch (Exception $e) {
            Log::error('Chart-IMG API request failed: ' . $e->getMessage());
            throw new Exception('Chart-IMG API request failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle API errors
     */
    private function handleApiError($response): void
    {
        $statusCode = $response->status();
        $body = $response->body();

        $errorMessage = match ($statusCode) {
            400 => 'Bad Request - Invalid request format',
            403 => 'Forbidden - Invalid API key or insufficient permissions',
            404 => 'Not Found - Endpoint or resource not found',
            409 => 'Conflict - Session disconnected',
            422 => 'Validation Error - Invalid parameters',
            429 => 'Too Many Requests - Rate limit exceeded',
            500 => 'Server Error - Internal server error',
            504 => 'Timeout - Request timed out',
            default => 'Unknown Error'
        };

        // Try to get more specific error message from response
        try {
            $jsonBody = json_decode($body, true);
            if (isset($jsonBody['message'])) {
                $errorMessage = $jsonBody['message'];
            } elseif (isset($jsonBody['error'])) {
                $errorMessage = $jsonBody['error'];
            } elseif (isset($jsonBody['errors'])) {
                $errorMessage = 'Validation errors: ' . json_encode($jsonBody['errors']);
            }
        } catch (Exception $e) {
            // If JSON decode fails, use the original error message
        }

        Log::error("Chart-IMG API Error [{$statusCode}]: {$errorMessage}", [
            'response_body' => $body,
            'status_code' => $statusCode
        ]);

        throw new Exception($errorMessage);
    }

    /**
     * Handle upload file to firebase
     */
    private function uploadChartToFirebase(array $imageData, int $userId): array
    {
        try {
            // Validate required keys
            if (!isset($imageData['data']) || !isset($imageData['content_type'])) {
                throw new Exception('Invalid image data provided');
            }

            // Decode base64 image
            $binaryData = base64_decode($imageData['data']);
            if ($binaryData === false) {
                throw new Exception('Failed to decode base64 image data');
            }

            // Generate unique filename
            $extension = explode('/', $imageData['content_type'])[1] ?? 'png';
            $filename = 'chart_on_demand/' . $userId . '/' . time() . '_' . Str::random(20) . '.' . $extension;

            // Get Firebase bucket
            $bucketName = config('firebase.storage.bucket', config('firebase.project_id', 'ai-crm-windsurf') . '.appspot.com');
            $storage = app('firebase.storage');
            $defaultBucket = $storage->getBucket($bucketName);

            // Upload binary data
            $object = $defaultBucket->upload(
                $binaryData,
                [
                    'name' => $filename,
                    'predefinedAcl' => 'publicRead'
                ]
            );

            // Get public URL
            $imageUrl = 'https://storage.googleapis.com/' . $bucketName . '/' . $filename;

            // Also keep base64 (optional)
            $imageBase64 = 'data:' . $imageData['content_type'] . ';base64,' . $imageData['data'];

            Log::info('Chart image uploaded to Firebase Storage', [
                'filename' => $filename,
                'url' => $imageUrl,
                'size' => strlen($binaryData)
            ]);

            return [
                'url' => $imageUrl,
                'base64' => $imageBase64,
                'filename' => $filename
            ];

        } catch (Exception $e) {
            Log::error('Failed to upload chart to Firebase: ' . $e->getMessage());
            throw $e;
        }
    }

}