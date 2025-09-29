<?php

namespace App\Console\Commands;

use App\Http\Controllers\ChartOnDemandController;
use App\Http\Controllers\OpenRouterController;
use App\Mail\AnalysisResultMail;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\TokenService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\File\UploadedFile;



class ScheduleAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule-analysis';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate chart using chart-img api and pass the images to chart analysis, then send analysis report to user.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // find schedule 
        $now = date('Y-m-d H:i:s');
        $oneMinuteAgo = now()->subMinute();
                            
        // get all tasks that is 1 minute before execute_at
        // dont have loop issue since schedule task run every minute
        $tasks = ScheduleTask::whereBetween('execute_at', [$oneMinuteAgo, $now])
            ->where('executed', false)
            ->get();

        if (count($tasks) > 0) {
            
            $referralService = new ReferralService;
            $tokenService = new TokenService($referralService);
            $chartOnDemandController = new ChartOnDemandController;
            $openRouterController = new OpenRouterController($tokenService);
            
            foreach ($tasks as $task) {
                \Log::info('Schedule Task ID: '. $task->id);
                // login user
                $user = User::find($task->user_id); 
                auth()->login($user);
                $parameter = is_array($task->parameter) ? $task->parameter : json_decode($task->parameter, true);
    
                try {
                    $requestForChart = new Request($parameter);
                    $images = $chartOnDemandController->generateAdvancedChartV2($requestForChart);
                    if (!isset($images['data']) || !is_array($images['data'])) {
                        \Log::warning("No chart images generated for task {$task->id}");
                        continue;
                    }
                    $imageParams = ['images' => []];
                    
                    foreach ($images['data'] as $image) {
                        $tmpPath = tempnam(sys_get_temp_dir(), 'chart_');
                        file_put_contents($tmpPath, base64_decode($image['data'])); // decode base64 into file
                        \Log::info('append upload image');
                        $uploadedFile = new \Illuminate\Http\UploadedFile(
                            $tmpPath,
                            'chart.png', 
                            $image['content_type'],
                            null,
                            true 
                        );
                        $imageParams['images'][] = $uploadedFile;
                    }
                    
                    $files = ['images' => $imageParams['images']];
                    
                    \Log::info('Ready for generate report');
                    $requestForAnalysis = Request::create('/analyze','POST',[],[],$files,[]);
                    
                    $dataResponse = $openRouterController->analyzeImage($requestForAnalysis);
                    $report = $dataResponse instanceof \Illuminate\Http\JsonResponse
                        ? $dataResponse->getData(true)
                        : $dataResponse;
                    $smtpConfig = \App\Models\SmtpConfiguration::where('is_default', true)->first();
                    if ($smtpConfig) {
                        Config::set('mail.default', 'smtp');
                        Config::set('mail.mailers.smtp.transport', 'smtp');
                        Config::set('mail.mailers.smtp.host', $smtpConfig->host);
                        Config::set('mail.mailers.smtp.port', (int) $smtpConfig->port);
                        Config::set('mail.mailers.smtp.encryption', $smtpConfig->encryption);
                        Config::set('mail.mailers.smtp.username', $smtpConfig->username);
                        Config::set('mail.mailers.smtp.password', $smtpConfig->password);
                        Config::set('mail.from.address', $smtpConfig->from_address);
                        Config::set('mail.from.name', $smtpConfig->from_name);
                        
                        app()->forgetInstance('swift.mailer');
                        app()->forgetInstance('mailer');
                        
                        $history = \App\Models\History::find($report['history_id']);
                        $imagesLink = $history->chart_urls;
                        Mail::to($user->email)->send(new AnalysisResultMail($report, $imagesLink));
                        
                        $cron = CronExpression::factory($task->cron_expression);
                        $nextRunDate = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
                        $task->execute_at = $nextRunDate;
                        $task->save();
                    } else {
                        \Log::warning("No default SMTP configuration found");
                    }
    
                } catch (\Throwable $e) {
                    \Log::error("Task {$task->id} failed: " . $e->getMessage());
    
                    continue;
                }
            }
        } else {
            \Log::info('No schedule task found');
        }

    }
}
