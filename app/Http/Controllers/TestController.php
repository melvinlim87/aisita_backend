<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\KnowledgeBase;
use App\Services\TokenService;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class TestController extends Controller
{
    protected $tokenService;
    
    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Test the subscription enforcement logic
     *
     * @return JsonResponse
     */
    public function testSubscriptionEnforcement(): JsonResponse
    {
        $results = [];
        
        try {
            // Clean up any test users from previous runs
            $testEmails = [
                'telegram_full@test.com',
                'telegram_used@test.com',
                'telegram_subscription@test.com',
                'whatsapp_used@test.com',
                'standard@test.com',
                'firebase@test.com'
            ];
            
            foreach ($testEmails as $email) {
                $existingUser = User::where('email', $email)->first();
                if ($existingUser) {
                    $existingUser->delete();
                }
            }
            
            // Get a TokenController instance
            $controller = app()->make(TokenController::class);
            
            // Scenario 1: Telegram user with full free tokens (should be allowed to purchase)
            $telegramUserFull = User::create([
                'name' => 'Telegram Test User (Full)',
                'email' => 'telegram_full@test.com',
                'telegram_id' => '123456789',
                'telegram_username' => 'test_user_full',
                'password' => Hash::make('password'),
                'subscription_token' => 4000, // Full tokens
                'addons_token' => 0,
                'role_id' => 1
            ]);
            
            $request = new Request();
            $request->merge(['package_id' => 'micro']);
            $request->setUserResolver(function () use ($telegramUserFull) {
                return $telegramUserFull;
            });
            
            $response = $controller->purchaseTokens($request);
            $status = $response->getStatusCode();
            $content = json_decode($response->getContent(), true);
            
            $results['telegram_full'] = [
                'passed' => $status === 200 && isset($content['success']) && $content['success'] === true,
                'status' => $status,
                'content' => $content
            ];
            
            // Scenario 2: Telegram user with used tokens, no subscription (should be denied)
            $telegramUserUsed = User::create([
                'name' => 'Telegram Test User (Used)',
                'email' => 'telegram_used@test.com',
                'telegram_id' => '987654321',
                'telegram_username' => 'test_user_used',
                'password' => Hash::make('password'),
                'subscription_token' => 3500, // Some tokens used
                'addons_token' => 0,
                'role_id' => 1
            ]);
            
            $request = new Request();
            $request->merge(['package_id' => 'micro']);
            $request->setUserResolver(function () use ($telegramUserUsed) {
                return $telegramUserUsed;
            });
            
            $response = $controller->purchaseTokens($request);
            $status = $response->getStatusCode();
            $content = json_decode($response->getContent(), true);
            
            $results['telegram_used'] = [
                'passed' => $status === 403 && isset($content['message']) && $content['message'] === 'You must purchase a subscription before buying additional tokens.',
                'status' => $status,
                'content' => $content
            ];
            
            // Create a plan for testing
            $plan = Plan::firstOrCreate(
                ['name' => 'Test Plan'],
                [
                    'description' => 'Plan for testing',
                    'price' => 9.99,
                    'interval' => 'month',
                    'stripe_price_id' => 'price_test',
                    'tokens' => 1000,
                    'premium_models_access' => true
                ]
            );
            
            // Scenario 3: Telegram user with used tokens AND subscription (should be allowed)
            $telegramUserSubscription = User::create([
                'name' => 'Telegram Test User (Subscription)',
                'email' => 'telegram_subscription@test.com',
                'telegram_id' => '123123123',
                'telegram_username' => 'test_user_subscription',
                'password' => Hash::make('password'),
                'subscription_token' => 3500, // Some tokens used
                'addons_token' => 0,
                'role_id' => 1
            ]);
            
            // Create active subscription for the user
            $subscription = Subscription::create([
                'user_id' => $telegramUserSubscription->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'stripe_subscription_id' => 'sub_test',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth()
            ]);
            
            // Reset and reload the user to ensure relationship is properly loaded
            $telegramUserSubscription = User::find($telegramUserSubscription->id);
            
            $request = new Request();
            $request->merge(['package_id' => 'micro']);
            $request->setUserResolver(function () use ($telegramUserSubscription) {
                return $telegramUserSubscription;
            });
            
            $response = $controller->purchaseTokens($request);
            $status = $response->getStatusCode();
            $content = json_decode($response->getContent(), true);
            
            $results['telegram_subscription'] = [
                'passed' => $status === 200 && isset($content['success']) && $content['success'] === true,
                'status' => $status,
                'content' => $content
            ];
            
            // Scenario 4: WhatsApp user with used tokens (should be denied)
            $whatsappUser = User::create([
                'name' => 'WhatsApp Test User',
                'email' => 'whatsapp_used@test.com',
                'phone_number' => '+1234567890',
                'whatsapp_verified' => true,
                'password' => Hash::make('password'),
                'subscription_token' => 3500, // Some tokens used
                'addons_token' => 0,
                'role_id' => 1
            ]);
            
            $request = new Request();
            $request->merge(['package_id' => 'micro']);
            $request->setUserResolver(function () use ($whatsappUser) {
                return $whatsappUser;
            });
            
            $response = $controller->purchaseTokens($request);
            $status = $response->getStatusCode();
            $content = json_decode($response->getContent(), true);
            
            $results['whatsapp_used'] = [
                'passed' => $status === 403 && isset($content['message']) && $content['message'] === 'You must purchase a subscription before buying additional tokens.',
                'status' => $status,
                'content' => $content
            ];
            
            // Scenario 5: Standard user (no free tokens required, should be allowed)
            $standardUser = User::create([
                'name' => 'Standard Test User',
                'email' => 'standard@test.com',
                'password' => Hash::make('password'),
                'subscription_token' => 0,
                'addons_token' => 0,
                'role_id' => 1
            ]);
            
            $request = new Request();
            $request->merge(['package_id' => 'micro']);
            $request->setUserResolver(function () use ($standardUser) {
                return $standardUser;
            });
            
            $response = $controller->purchaseTokens($request);
            $status = $response->getStatusCode();
            $content = json_decode($response->getContent(), true);
            
            $results['standard_user'] = [
                'passed' => $status === 200 && isset($content['success']) && $content['success'] === true,
                'status' => $status,
                'content' => $content
            ];
            
            // Scenario 6: Firebase user (no free tokens required, should be allowed)
            $firebaseUser = User::create([
                'name' => 'Firebase Test User',
                'email' => 'firebase@test.com',
                'firebase_uid' => 'firebase_test_123',
                'password' => Hash::make('password'),
                'subscription_token' => 0,
                'addons_token' => 0,
                'role_id' => 1
            ]);
            
            $request = new Request();
            $request->merge(['package_id' => 'micro']);
            $request->setUserResolver(function () use ($firebaseUser) {
                return $firebaseUser;
            });
            
            $response = $controller->purchaseTokens($request);
            $status = $response->getStatusCode();
            $content = json_decode($response->getContent(), true);
            
            $results['firebase_user'] = [
                'passed' => $status === 200 && isset($content['success']) && $content['success'] === true,
                'status' => $status,
                'content' => $content
            ];
            
            // Cleanup test users
            foreach ($testEmails as $email) {
                $existingUser = User::where('email', $email)->first();
                if ($existingUser) {
                    $existingUser->delete();
                }
            }
            
            // Overall test results
            $allPassed = true;
            foreach ($results as $result) {
                if (!$result['passed']) {
                    $allPassed = false;
                    break;
                }
            }
            
            return response()->json([
                'success' => true,
                'all_tests_passed' => $allPassed,
                'results' => $results
            ]);
            
        } catch (\Exception $e) {
            Log::error('Test error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'results' => $results
            ], 500);
        }
    }

    public function createKnowledgebase(Request $request) {
        // return $request->all();
        try {
            $kb = new KnowledgeBase;
            
            $kb->source = $request->source;
            $kb->topic = $request->topic;
            $kb->title = $request->title;
            $kb->skill_level = $request->skill_level;
            $kb->related_keywords = $request->related_keywords;
            $kb->content = $request->content;
            $kb->save();
            return 'done';
        } catch (\Throwable $th) {
            return 'done';
            //throw $th;
        }
    }

    public function testing() {
        return ['stat' => env('STRIPE_WEBHOOK_SECRET')];
        try {
            $now = date('Y-m-d H:i:s');
            $oneMinuteAgo = now()->subMinute();
            $tasks = \App\Models\ScheduleTask::whereBetween('execute_at', [$oneMinuteAgo, $now])
                ->where('executed', false)
                ->get();
            return ['data' => $tasks, 'now' => $now];
            // return ['now' => $now];
            // return $now;
            $task = \App\Models\ScheduleTask::find(1);
    
            $cron = CronExpression::factory($task->cron_expression);
            $date = $cron->getNextRunDate($now)->format('Y-m-d H:i:s');
            return ['res' => $date];  
    
            return $task;
        } catch (\Throwable $th) {
            return ['err' => $th->getMessage()];
            throw $th;
        }

    }
}
