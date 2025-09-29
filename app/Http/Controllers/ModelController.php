<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class ModelController extends BaseController
{
    private $availableModels = [
        [ 'id' => 'mistralai/mistral-small-3.2-24b-instruct:free', 'name' => 'Mistral Small 3.2 24B', 'description' => 'Mistral-Small-3.2-24B-Instruct-2506 is an updated 24B parameter model from Mistral optimized for instruction following.', 'premium' => false, 'creditCost' => 0.2, 'beta' => false, 'hasVision' => false, 'structuredOutput' => true ],
        [ 'id' => 'deepseek/deepseek-r1-0528:free', 'name' => 'DeepSeek: R1 0528', 'description' => 'DeepSeek R1 0528 is an updated model from DeepSeek R1', 'premium' => false, 'creditCost' => 0.2, 'beta' => false, 'hasVision' => false, 'structuredOutput' => true ],
        [ 'id' => 'meta-llama/llama-3.3-70b-instruct:free', 'name' => 'Meta LLama 3.3', 'description' => 'LLama is proficient in analyzing texts, charts, icons, graphics, and layouts within images.', 'premium' => false, 'creditCost' => 0.2, 'beta' => false, 'hasVision' => true, 'structuredOutput' => true ],
        [ 'id' => 'openai/gpt-4o-2024-11-20', 'name' => 'GPT-4o', 'description' => 'Fast and efficient analysis', 'premium' => true, 'creditCost' => 1.25, 'beta' => false, 'hasVision' => true, 'structuredOutput' => true ],
        [ 'id' => 'google/gemini-2.0-flash-001', 'name' => 'Gemini 2.0 Flash', 'description' => 'Rapid data processing capabilities', 'premium' => true, 'creditCost' => 0.5, 'beta' => false, 'hasVision' => true, 'structuredOutput' => true ],
        [ 'id' => 'anthropic/claude-3.7-sonnet', 'name' => 'Claude 3.7 Sonnet', 'description' => 'Advanced reasoning and analysis', 'premium' => true, 'creditCost' => 1.5, 'beta' => false, 'hasVision' => true, 'structuredOutput' => true ],
        [ 'id' => 'google/gemini-2.5-pro-preview-05-06', 'name' => 'Gemini 2.5 Pro', 'description' => 'Enhanced reasoning capabilities', 'premium' => true, 'creditCost' => 1.0, 'beta' => true, 'hasVision' => true, 'structuredOutput' => true ],
        [ 'id' => 'meta-llama/llama-4-scout', 'name' => 'Llama 4 Scout', 'description' => 'Fast and efficient reasoning model', 'premium' => false, 'creditCost' => 0.3, 'beta' => false, 'hasVision' => true, 'structuredOutput' => true ],
        [ 'id' => 'qwen/qwen-vl-plus', 'name' => 'Qwen VL Plus', 'description' => 'Vision language model with strong multimodal capabilities', 'premium' => false, 'creditCost' => 0.35, 'beta' => false, 'hasVision' => true, 'structuredOutput' => true ],
    ];




    private $MODEL_BASE_COSTS = [
        'openai/gpt-4o-2024-11-20' => [ 'input' => 0.005, 'output' => 0.015 ],
        'google/gemini-2.0-flash-001' => [ 'input' => 0.00035, 'output' => 0.00105 ],
        'anthropic/claude-3.7-sonnet' => [ 'input' => 0.003, 'output' => 0.015 ],
        'google/gemini-2.5-pro-preview-05-06' => [ 'input' => 0.0004, 'output' => 0.0012 ],
        'meta-llama/llama-4-scout' => [ 'input' => 0.0008, 'output' => 0.003 ],
        'qwen/qwen-vl-plus' => [ 'input' => 0.0001, 'output' => 0.0003 ],
        // fallback
        'openai/gpt-4o-mini' => [ 'input' => 0.0025, 'output' => 0.0075 ],
        // 'deepseek/deepseek-r1-0528-qwen3-8b:free ' => [ 'input' => 0.0025, 'output' => 0.0075 ],
        'meta-llama/llama-3.3-70b-instruct:free' => [ 'input' => 0.0025, 'output' => 0.0075 ],
        'mistralai/mistral-small-3.2-24b-instruct:free' => [ 'input' => 0.0025, 'output' => 0.0075 ],
        'deepseek/deepseek-r1-0528:free' => [ 'input' => 0.0025, 'output' => 0.0075 ],
    ];
    private $ESTIMATED_CHAT_TOKENS = [ 'input' => 800, 'output' => 400 ];
    private $ESTIMATED_ANALYSIS_TOKENS = [ 'input' => 2000, 'output' => 1000 ];
    private $TOKENS_PER_DOLLAR = 667;

    public function getAvailableModels()
    {
        $user = Auth::user();
        $models = $this->availableModels;
        
        // Check if user has premium access via subscription
        if (!$user || !$user->hasPremiumAccess()) {
            // Filter out premium models for non-premium users
            $models = array_filter($models, function($model) {
                return isset($model['premium']) && $model['premium'] === false;
            });
        }
        
        // return response()->json($models);
        return response()->json(array_values($models));
    }
    
    /**
     * Get only models that are compatible with image analysis
     * These models must have vision capabilities and be able to produce structured output
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getImageCompatibleModels()
    {
        $user = Auth::user();
        
        $compatibleModels = array_filter($this->availableModels, function($model) {
            return isset($model['hasVision']) && $model['hasVision'] === true && 
                   isset($model['structuredOutput']) && $model['structuredOutput'] === true;
        });
        
        // Filter premium models based on subscription status
        if (!$user || !$user->hasPremiumAccess()) {
            $compatibleModels = array_filter($compatibleModels, function($model) {
                return isset($model['premium']) && $model['premium'] === false;
            });
        }
        
        return response()->json(array_values($compatibleModels));
    }

    public function calculateTokenCost(Request $request)
    {
        $modelId = $request->input('modelId');
        $isAnalysis = $request->input('isAnalysis', false);
        $baseCosts = $this->MODEL_BASE_COSTS[$modelId] ?? $this->MODEL_BASE_COSTS['openai/gpt-4o-mini'];
        $tokenEstimate = $isAnalysis ? $this->ESTIMATED_ANALYSIS_TOKENS : $this->ESTIMATED_CHAT_TOKENS;
        $tokenCost = $this->calculateCost($modelId, $tokenEstimate['input'], $tokenEstimate['output']);
        return response()->json(['cost' => ceil($tokenCost)]);
    }

    private function calculateCost($modelId, $inputTokens, $outputTokens)
    {
        $costs = $this->MODEL_BASE_COSTS[$modelId] ?? $this->MODEL_BASE_COSTS['openai/gpt-4o-mini'];
        $inputCost = ($inputTokens / 1000.0) * $costs['input'];
        $outputCost = ($outputTokens / 1000.0) * $costs['output'];
        $totalCost = $inputCost + $outputCost;
        $costInUSD = $totalCost;
        $appTokenCost = ceil($costInUSD * $this->TOKENS_PER_DOLLAR);
        return $appTokenCost;
    }
}
