<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\KnowledgeBase;
use App\Models\UserLearningProfile;
use App\Models\UserTopicEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Services\TokenService;
use Carbon\Carbon;

class AIEducatorController extends Controller
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

    private $modelBaseCosts = [
        'openai/gpt-oss-20b:free' => ['input' => 0.005, 'output' => 0.005],
        'deepseek/deepseek-r1-0528:free' => ['input' => 0.005, 'output' => 0.005],
        'qwen/qwen2.5-vl-72b-instruct:free' => ['input' => 0.005, 'output' => 0.005],
        'mistralai/mistral-small-3.2-24b-instruct-2506:free' => ['input' => 0.005, 'output' => 0.005],
        'meta-llama/llama-4-maverick:free' => ['input' => 0.005, 'output' => 0.005],
        'nvidia/llama-3.3-nemotron-super-49b-v1:free' => ['input' => 0.005, 'output' => 0.005],
        'qwen/qwen3-235b-a22b:free' => ['input' => 0.005, 'output' => 0.005],
        'google/gemma-3-12b-it:free' => ['input' => 0.005, 'output' => 0.005],
        'qwen/qwen3-30b-a3b:free' => ['input' => 0.005, 'output' => 0.005],
    ];

    private $models = [
        'openai/gpt-oss-20b:free',
        'deepseek/deepseek-r1-0528:free',
        'qwen/qwen2.5-vl-72b-instruct:free',
        'mistralai/mistral-small-3.2-24b-instruct-2506:free',
        'meta-llama/llama-4-maverick:free',
        'nvidia/llama-3.3-nemotron-super-49b-v1:free',
        'qwen/qwen3-235b-a22b:free',
        'google/gemma-3-12b-it:free',
        'qwen/qwen3-30b-a3b:free',
    ];

    private $estimatedAnalysisTokens = ['input' => 1000, 'output' => 2000];
    private $estimatedChatTokens = ['input' => 500, 'output' => 1000];


    public function handleQuestion(Request $request)
    {
        // add token usage
        // save user message and ai message to history
        $userId = $request->user_id ?? auth()->user()->id;
        $question = $request->question;
        $lastMessage = json_encode($request->lastMessage);
        \Log::info('AI Educator receive question' . $question);
            
        // 1️⃣ First Agent → Extract keyword(s)
        $keywordPrompt = "You are a forex keyword extractor. Given the user's question, return ONLY the most relevant topic/keyword(s) in JSON format: {\"keywords\": [\"keyword1\", \"keyword2\"]}.\n\nLast reply: {$lastMessage}\nUser Question: {$question}";
        
        \Log::info('AI Educator second prompt : ' . $keywordPrompt);
        $keywordResponse = $this->callAIWithFallback($keywordPrompt);
        $firstContent = $keywordResponse['content']['choices'][0]['message']['content'] ?? null;

        if (!$firstContent) {
            return response()->json(['error' => 'All AI models failed for keyword extraction'], 500);
        }

        $keywords = [];
        \Log::info('Get first response : '.$firstContent);
        preg_match('/\{.*\}/s', $firstContent, $matches);
        if (!empty($matches)) {
            $json = json_decode($matches[0], true);
            if (isset($json['keywords'])) {
                $keywords = $json['keywords'];
            }
        }

        if (empty($keywords)) {
            return response()->json(['error' => 'No keywords extracted'], 500);
        }

        // 2️⃣ Search Knowledge Base
        $kbEntry = KnowledgeBase::where(function ($query) use ($keywords) {
            foreach ($keywords as $keyword) {
                $query->orWhere('title', 'like', "%$keyword%")
                      ->orWhere('content', 'like', "%$keyword%");
            }
        })->first();

        $kbContent = $kbEntry->content ?? null;

        // 3️⃣ Second Agent → Generate human-like reply
        $secondPrompt = $kbContent
            ? "You are a forex educator. Answer the user's question using ONLY this knowledge base entry:\n\n{$kbContent}\n\nUser Question: {$question}\n\nYour reply should be friendly, human-like, and end with a follow-up question to encourage learning."
            : "You are a forex educator. The database does not contain the answer, so use your own knowledge to answer.\n\nUser Question: {$question}\n\nProvide a complete, clear, and educational answer with a follow-up question.";

        \Log::info('AI Educator second prompt : ' . $secondPrompt);

        $secondContent = $this->callAIWithFallback($secondPrompt);
        $finalAnswer = $secondContent['content']['choices'][0]['message']['content'] ?? null;

        // Only calculate the token usage for second prompt
        
        $inputTokens = $this->estimatedAnalysisTokens['input'];
        $outputTokens = $this->estimatedAnalysisTokens['output'];
        
        $tokenCost = 0;
        $costCalculation = $this->calculateCost($secondContent['model'], $inputTokens, $outputTokens);
        
        // Handle the case when the model is unknown and calculateCost returns an error
        if (isset($costCalculation['error'])) {
            // Use a default cost if model is unknown
            $tokenCost = ceil(($inputTokens * 0.0005 + $outputTokens * 0.0015) * 667);
            \Log::warning('Unknown model in cost calculation, using default pricing', [
                'model' => $secondContent['model'],
                'error' => $costCalculation['error']
            ]);
        } else {
            $tokenCost = ceil($costCalculation['totalCost'] * 667); // 667 tokens per dollar conversion
        }
        // Get latest token balances
        $tokenBalances = $this->tokenService->getUserTokens($userId);
        
        // Smart token deduction logic that can split across subscription and addon tokens if needed
        $deductionResult = false;
        $actualInputTokens = $secondContent['usage']['prompt_tokens'] ?? $inputTokens;
        $actualOutputTokens = $secondContent['usage']['completion_tokens'] ?? $outputTokens;
            
        if ($tokenBalances['subscription_token'] >= $tokenCost) {
            // If subscription tokens are enough, deduct from there
            $deductionResult = $this->tokenService->deductUserTokens(
                $userId,
                $tokenCost,
                'ai_educator',
                'subscription_token',
                $secondContent['model'],
                'text',
                $actualInputTokens,
                $actualOutputTokens
            );
        } elseif ($tokenBalances['addons_token'] >= $tokenCost) {
            // If addon tokens are enough, deduct from there
            $deductionResult = $this->tokenService->deductUserTokens(
                $userId,
                $tokenCost,
                'ai_educator',
                'addons_token',
                $secondContent['model'],
                'text',
                $actualInputTokens,
                $actualOutputTokens
            );
        } else {
            // Need to split the deduction across both token sources
            // First use all available subscription tokens
            $subscriptionDeduction = $this->tokenService->deductUserTokens(
                $userId,
                $tokenBalances['subscription_token'],
                'ai_educator (partial)',
                'subscription_token',
                $secondContent['model'],
                'text',
                intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
            );
            
            // Then deduct the remainder from addon tokens
            $remainingCost = $tokenCost - $tokenBalances['subscription_token'];
            $addonDeduction = $this->tokenService->deductUserTokens(
                $userId,
                $remainingCost,
                'ai_educator (remainder)',
                'addons_token',
                $secondContent['model'],
                'text',
                $actualInputTokens - intval($actualInputTokens * ($tokenBalances['subscription_token'] / $tokenCost)),
                $actualOutputTokens - intval($actualOutputTokens * ($tokenBalances['subscription_token'] / $tokenCost))
            );
            
            // Both deductions must succeed
            $deductionResult = $subscriptionDeduction && $addonDeduction;
            
            \Log::info('Split token deduction for image analysis', [
                'user_id' => $userId,
                'total_cost' => $tokenCost,
                'subscription_amount' => $tokenBalances['subscription_token'],
                'addon_amount' => $remainingCost,
                'success' => $deductionResult
            ]);
        }
        
        if (!$deductionResult) {
            \Log::warning('Failed to deduct tokens for image analysis', [
                'user_id' => $userId,
                'tokens_to_deduct' => $tokenCost
            ]);
            // Continue despite failed deduction as we already processed the request
        } else {
            $updatedBalances = $this->tokenService->getUserTokens($userId);
            \Log::info('Successfully deducted tokens for image analysis', [
                'user_id' => $userId,
                'deducted_tokens' => $tokenCost,
                'remaining_subscription_tokens' => $updatedBalances['subscription_token'],
                'remaining_addons_tokens' => $updatedBalances['addons_token']
            ]);
        }

        if (!$finalAnswer) {
            return response()->json(['error' => 'All AI models failed for answering'], 500);
        }

        // If KB did not contain answer → Save it
        if (!$kbEntry && $finalAnswer && count($keywords) > 0) {
            $newKB = new KnowledgeBase();
            $newKB->topic = ucfirst($keywords[0]);
            $newKB->title = ucfirst($keywords[0]);
            $newKB->content = $finalAnswer;
            $newKB->save();
            $kbEntry = $newKB;
        }

        // Update User Learning Profile
        $profile = UserLearningProfile::firstOrNew(['user_id' => $userId]);
        $profile->skill_level = $profile->skill_level ?? 'beginner';
        $profile->save();

        // Log Topic Event
        UserTopicEvent::create([
            'user_id' => $userId,
            'topic' => count($keywords) ? $keywords[0]: '[]',
            'event_type' => 'view',
            'details' => $question
        ]);

        \Log::info('AI Educator answer' . $finalAnswer);

        $history = \App\Models\History::create([
            'user_id' => auth()->id(),
            'title' => $question,
            'type' => 'ai_educator',
            'model' => $secondContent['model'],
            'content' => json_encode($finalAnswer),
            'chart_urls' => [],
            'timestamp' => now(),
        ]);

        return response()->json([
            'code' => $finalAnswer,
            'keywords' => $keywords,
            'knowledge_base_used' => (bool) $kbContent
        ]);
    }

    /**
     * Call OpenRouter AI with fallback models
     */
    private function callAIWithFallback($prompt)
    {
        $apiKey = env('OPENROUTER_API_KEY');
        foreach ($this->models as $model) {
            try {
                $response = Http::timeout(60)->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'HTTP-Referer' => 'https://yourapp.com',
                    'X-Title' => 'Forex Educator'
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 1000
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    return [
                        'model' => $model,
                        'content' => $json
                    ];
                }
            } catch (\Exception $e) {
                \Log::info("Model failed using : ".$model);
                \Log::info("Error occured : ".$e->getMessage());
                        
                // Continue to next model
            }
        }
        return null;
    }

    public function getAIEducatorHistory(Request $request) {
        try {
            $userId = $request->user_id;
            $data = \App\Models\History::where('user_id', $userId)->where('type', 'ai_educator')->get();
            $res = [];
            $id = 2;
            foreach($data as $d) {
                $res[] = [
                    'id' => "$id",
                    'content' => $d->title,
                    'sender' => 'user',
                    'timestamp'=> $d->timestamp
                ];
                $id++;
                $res[] = [
                    'id' => "$id",
                    'content' => json_decode($d->content),
                    'sender' => 'ai',
                    'timestamp'=> $d->timestamp
                ];
                $id++;
            }
            return response()->json([
                'success' => true,
                'data' => $res
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
