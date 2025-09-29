<?php

namespace App\Http\Controllers;

use App\Models\History;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HistoryController extends Controller
{
    /**
     * Save a chart analysis result to history
     */
    public function saveAnalysis(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'model' => 'nullable|string|max:255',
            'content' => 'required|string',
            'chart_url' => 'nullable|string',
        ]);

        $user = Auth::user();
        
        $chartUrls = [];
        if ($request->has('chart_url')) {
            $chartUrls[] = $request->chart_url;
        }

        $history = History::create([
            'user_id' => $user->id,
            'title' => $request->title,
            'type' => 'chart_analysis',
            'model' => $request->model,
            'content' => $request->content,
            'chart_urls' => $chartUrls,
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Analysis saved successfully',
            'history_id' => $history->id
        ]);
    }

    /**
     * Get all analysis history for the authenticated user
     */
    public function getHistory()
    {
        $user = Auth::user();
        $history = History::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->with(['chatMessages' => function($query) {
                $query->orderBy('created_at', 'asc');
            }])
            ->get();

        return response()->json($history);
    }
    
    /**
     * Get history items of a specific type for the authenticated user
     */
    public function getHistoryByType($type)
    {
        // Validate the type parameter
        $validTypes = ['chart_analysis', 'ea_generation'];
        if (!in_array($type, $validTypes)) {
            return response()->json(['error' => 'Invalid history type. Valid types are: ' . implode(', ', $validTypes)], 400);
        }
        
        $user = Auth::user();
        $history = History::where('user_id', $user->id)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->with(['chatMessages' => function($query) {
                $query->orderBy('created_at', 'asc');
            }])
            ->get();

        return response()->json($history);
    }

    /**
     * Get a specific analysis by ID
     */
    public function getAnalysis($id)
    {
        $user = Auth::user();
        $analysis = History::where('user_id', $user->id)
            ->where('id', $id)
            ->with(['chatMessages' => function($query) {
                $query->orderBy('created_at', 'asc');
            }])
            ->first();

        if (!$analysis) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        return response()->json($analysis);
    }

    /**
     * Delete an analysis from history
     */
    public function deleteAnalysis($id)
    {
        $user = Auth::user();
        $analysis = History::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$analysis) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        $analysis->delete();

        return response()->json([
            'success' => true,
            'message' => 'Analysis deleted successfully'
        ]);
    }
}
