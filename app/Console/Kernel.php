<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run subscription renewals every day at midnight
        $schedule->command('subscriptions:process-renewals')->daily();
        // Add new forex news from url
        $schedule->command('add:forex-news')->daily();
        // Run schedule analysis every minute
        $schedule->command('schedule-analysis')->everyMinute();
        // Add monthly tokens to all users (15000 tokens) on the 1st day of each month
        $schedule->command('users:add-monthly-tokens')->monthly()->onTheFirst()->at('01:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
