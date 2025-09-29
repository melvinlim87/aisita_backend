<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chart Analysis Result</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-900 text-gray-100 p-6">
    <h1 class="text-xl font-bold mb-4">Chart Analysis Results</h1>
    @isset($images)
        <div class="flex flex-row flex-wrap justify-center items-center gap-4 py-4">
            @foreach ($images as $imgk => $img)
                <img 
                    src="{{ $img }}" 
                    alt="Chart Preview {{ $imgk }}" 
                    class="cursor-pointer object-contain border border-gray-700 rounded-md"
                    style="width: 400px; height: 300px; object-fit: contain; background-color: #1a1a20;"
                />
            @endforeach
        </div>
    @endisset
    <div class="grid grid-cols-4 gap-4">
        <div class="p-4 bg-gray-800 rounded-lg">
            <h2 class="font-semibold text-blue-400 mb-2">Market Overview</h2>
            <p>Symbol: {{ $analysisData['Symbol'] }}</p>
            <p>Timeframe: {{ $analysisData['Timeframe'] }}</p>
            <p>Current Price: {{ $analysisData['Current_Price'] }}</p>
        </div>

        <div class="p-4 bg-gray-800 rounded-lg">
            <h2 class="font-semibold text-yellow-400 mb-2">Trade Setup</h2>
            <p>Action: {{ $analysisData['Action'] }}</p>
            <p>Entry: {{ $analysisData['Entry_Price'] }}</p>
            <p>Stop Loss: {{ $analysisData['Stop_Loss'] }}</p>
            <p>Take Profit: {{ $analysisData['Take_Profit'] }}</p>
            <p>Risk Ratio: {{ $analysisData['Risk_Ratio'] }}</p>
        </div>

        <div class="p-4 bg-gray-800 rounded-lg col-span-2">
            <h2 class="font-semibold text-purple-400 mb-2">Market Structure</h2>
            <p>{{ $analysisData['Market_Structure'] }}</p>
        </div>

        <div class="p-4 bg-gray-800 rounded-lg">
            <h2 class="font-semibold text-green-400 mb-2">Risk Assessment</h2>
            <p>Support: {{ implode(', ', $analysisData['Key_Price_Levels']['Support_Levels']) }}</p>
            <p>Resistance: {{ implode(', ', $analysisData['Key_Price_Levels']['Resistance_Levels']) }}</p>
            <p>Invalidation: {{ $analysisData['Risk_Assessment']['Invalidation_Scenarios'] }}</p>
            <p>Key Risk Levels: {{ $analysisData['Risk_Assessment']['Key_Risk_Levels'] }}</p>
        </div>

        <div class="p-4 bg-gray-800 rounded-lg">
            <h2 class="font-semibold text-blue-400 mb-2">Technical Justification</h2>
            <p>{{ $analysisData['Technical_Justification'] }}</p>
        </div>

        <div class="p-4 bg-gray-800 rounded-lg col-span-2">
            <h2 class="font-semibold text-pink-400 mb-2">Analysis Confidence</h2>
            <p>Pattern Clarity: {{ $analysisData['Analysis_Confidence']['Pattern_Clarity_Percent'] }}%</p>
            <p>Signal Reliability: {{ $analysisData['Analysis_Confidence']['Signal_Reliability_Percent'] }}%</p>
            <p>Technical Alignment: {{ $analysisData['Analysis_Confidence']['Technical_Alignment_Percent'] }}%</p>
            <p>Confidence Level: {{ $analysisData['Analysis_Confidence']['Confidence_Level_Percent'] }}%</p>
        </div>
    </div>
</body>
</html>
