<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\ForexNew;

class AddForexNews extends Command
{
    protected $signature = 'add:forex-news';
    protected $description = 'Daily cronjob to add forex news from https://nfs.faireconomy.media/ff_calendar_thisweek.json';

    public function handle()
    {
        $url = "https://nfs.faireconomy.media/ff_calendar_thisweek.json";

        $response = Http::timeout(120)
            ->get($url);

        Log::info("Raw response from AddForexNews : ". json_encode($response->json()));
            
        if ($response->failed()) {
            Log::error("Forex News failed to fetch from {$url}");
            return Command::FAILURE;
        }

        $data = $response->json();

        foreach ($data as $item) {
            if (ForexNew::where('title', $item['title'])->exists()) {

            } else {
                ForexNew::create(
                    [
                        'title'    => $item['title'] ?? null,
                        'country'  => $item['country'] ?? null,
                        'impact'   => $item['impact'] ?? null,
                        'forecast' => $item['forecast'] ?? null,
                        'previous' => $item['previous'] ?? null,
                        'date'     => $item['date'] ?? null,
                    ]
                );
            }
        }

        $this->info("Forex news successfully updated.");
        return Command::SUCCESS;
    }
}
