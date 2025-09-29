<?php

namespace App\Http\Controllers;

use App\Models\ForexNew;
use Illuminate\Http\Request;

class ForexNewController extends Controller
{
    public function index(Request $request) {
        $currency = $request->currency;
        $data = ForexNew::where('currency', $currency)->orderBy('date', 'desc')->first();
        if ($data) {

        } else {
            $data = ForexNew::where('currency', 'USD')->orderBy('date', 'desc')->first();
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    public function store(Request $request) {
        $validated = $request->validate([
            'title' => 'required|string',
            'country' => 'required|string',
            'date' => 'required|string',
            'impact' => 'nullable|string',
            'forecast' => 'nullable|string',
            'previous' => 'nullable|string',
        ]);

        ForexNew::create($request->all());

        return response()->json([
            'success' => true,
            'message' => "Forex news created successful"
        ]);
    }
}
