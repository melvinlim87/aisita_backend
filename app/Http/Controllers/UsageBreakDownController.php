<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\History;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UsageBreakDownController extends Controller
{
    /**
     * Get user usage break down analytics.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserUsageBreakDown(Request $request): JsonResponse
    {
        // Get the authenticated user
        $user = Auth::user();
        
        // Debug user authentication
        Log::info('User usage breakdown request', [
            'user_id' => $user->id,
            'user_role' => $user->role_id,
            'request_params' => null
        ]);
        
        $chartAnalysis = History::where([['user_id', $user->id], ['type', 'chart_analysis']])->count();
        $eaGeneration = History::where([['user_id', $user->id], ['type', 'ea_generation']])->count();
        $aiEducator = History::where([['user_id', $user->id], ['type', 'ai_educator']])->count();
        $chatMessages = ChatMessage::where([['user_id', $user->id], ['sender', 'user']])->count();
        $total = $chartAnalysis + $eaGeneration + $chatMessages + $aiEducator;
        if ($total === 0) {
            $data = [
                ['category' => "AIChartAnalysis", 'percentage' => 0, 'color' => 'bg-blue-500'],
                ['category' => "EAGenerator", 'percentage' => 0, 'color' => 'bg-emerald-500'],
                ['category' => "AIChatDiscussion", 'percentage' => 0, 'color' => 'bg-purple-500'],
                // ['category' => "AI Educator", 'percentage' => 0, 'color' => 'bg-yellow-500'],
            ];
        } else {
            $data = [
                ['category' => "AIChartAnalysis", 'percentage' => number_format(($chartAnalysis/$total) * 100, 0, '.', "") , 'color' => 'bg-blue-500'],
                ['category' => "EAGenerator", 'percentage' => number_format(($eaGeneration/$total) * 100, 0, '.', ""), 'color' => 'bg-emerald-500'],
                ['category' => "AIChatDiscussion", 'percentage' => number_format(($chatMessages/$total) * 100, 0, '.', ""), 'color' => 'bg-purple-500'],
                // ['category' => "AI Educator", 'percentage' => number_format(($aiEducator/$total) * 100, 0, '.', ""), 'color' => 'bg-yellow-500'],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}
