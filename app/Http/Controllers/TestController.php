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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Mail\CampaignMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
        $user = User::find(15);
        $smtpConfig = \App\Models\SmtpConfiguration::where('is_default', true)->first();
        \Log::info('SMTP Configuration: ' . json_encode($smtpConfig));
        if ($smtpConfig) {
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.transport', 'smtp');
            Config::set('mail.mailers.smtp.host', $smtpConfig->host); // smtp.titan.email
            Config::set('mail.mailers.smtp.port', (int) $smtpConfig->port);
            Config::set('mail.mailers.smtp.encryption', $smtpConfig->encryption);
            Config::set('mail.mailers.smtp.username', $smtpConfig->username);
            Config::set('mail.mailers.smtp.password', $smtpConfig->password);
            Config::set('mail.from.address', $smtpConfig->from_address);
            Config::set('mail.from.name', $smtpConfig->from_name);
            
            app()->forgetInstance('swift.mailer');
            app()->forgetInstance('mailer');
 
            $verificationEmail = EmailTemplate::where('name', 'Verification Email')->first();
            // If verification email template doesn't exist, fall back to welcome email
            if (!$verificationEmail) {
                $verificationEmail = EmailTemplate::where('name', 'Welcome Email')->first();
            }
            
            // Send verification email with verification code
            $campaign = EmailCampaign::create([
                'user_id' => $user->id,
                'name' => 'User Verification Email',
                'subject' => 'Please verify your email address',
                'from_email' => $smtpConfig->from_address,
                'from_name' => $smtpConfig->from_name,
                'html_content' => $verificationEmail->html_content ?? '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                        <h2 style="color: #333;">Welcome to Decypher AI!</h2>
                        <p>Hello {{firstName}},</p>
                        <p>Thank you for registering with Decypher AI. To complete your registration, please verify your email address by clicking the button below:</p>
                        <div style="text-align: center; margin: 30px 0;">
                            <a href="{{verificationUrl}}" style="background-color: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Verify Email Address</a>
                        </div>
                        <p>If the button above doesn\'t work, you can also copy and paste this link into your browser:</p>
                        <p style="word-break: break-all; color: #007bff;">{{verificationUrl}}</p>
                        <p>Your verification code is: <strong>{{verificationCode}}</strong></p>
                        <p style="color: #666; font-size: 12px; margin-top: 30px;">If you didn\'t create an account with us, please ignore this email.</p>
                    </div>
                ',
                'email_template_id' => $verificationEmail->id ?? null,
                'status' => 'draft', 
                'all_users' => 0,
            ]);
            
            // Add verification code to personalized variables
            $requestData = ['name' => "Testemail", 'email' => 'alvin42633@gmail.com', 'password' => '$2y$12$HaQKfKzQKEWtV3QHuhGobOSPoWJT.gsAS3MpV5rVq89BLJhmxQd46'];
            $verificationCode = Str::random(32);
        
            $personalizedVariables = $this->generatePersonalizedVariables($user, array_merge($requestData, [
                'verificationCode' => $verificationCode,
                'verificationUrl' => config('app.frontend_url', 'http://localhost:5173') . '/verify-signup?code=' . $verificationCode . '&email=' . urlencode($user->email)
            ]));
            
            \Log::info('Sending verification email with code', [
                'user_id' => $user->id,
                'email' => $user->email,
                'verification_code' => $verificationCode
            ]);
            
            Mail::to($user->email)->send(new CampaignMail($campaign, $personalizedVariables));
            
        } else {
            \Log::warning("No default SMTP configuration found");
        }
        return 'done send';

        // Free plan
        // ['id' => , 'stripe_price_id' => 'price_1SDiJj4Fje5CrOkWwDlsjAdk', 'price' => ], //	prod_TA2O1mYB1aoHBy	Premium Package 350,000 Tokens		txcd_10202000		2/10/2025 8:56	128.77
        // ['id' => , 'stripe_price_id' => 'price_1SDiJM4Fje5CrOkWT0Xe8fP5', 'price' => ], //	prod_TA2O48KkI2g7eq	Standard Package 175,000 Tokens		txcd_10202000		2/10/2025 8:56	64.38
        // ['id' => , 'stripe_price_id' => 'price_1SDiIs4Fje5CrOkWJTWN259L', 'price' => ], //	prod_TA2Nnvtiru4Xkf	Starter Package 35,000 Tokens		txcd_10202000		2/10/2025 8:55	12.88
        $prices = [
            ['id' => 16, 'stripe_price_id' => 'price_1SDiP14Fje5CrOkWOqDjH7wH', 'price' => 994.35], //	prod_TA2TapHptLhpwR	
            ['id' => 15, 'stripe_price_id' => 'price_1SDiOS4Fje5CrOkWSYVKVmYg', 'price' => 492.15], //	prod_TA2TZKhIdOTuSi	Pro Annual (35% Off)		txcd_10202000		2/10/2025 9:01	
            ['id' => 14, 'stripe_price_id' => 'price_1SDiNy4Fje5CrOkWLSsvr2oV', 'price' => 190.83], //	prod_TA2ScZmk0xBrec	Basic Annual (35% Off)		txcd_10202000		2/10/2025 9:01	190.83
            ['id' => 10, 'stripe_price_id' => 'price_1SDiNO4Fje5CrOkWnsn38A2H', 'price' => 1070.83], //	prod_TA2SAZx1d9gTlY	Decyphers Enterprise Annual Package: ONE-TIME PAYMENT of $1070.83 for 12,600,000 tokens.		txcd_10202000		2/10/2025 9:00	1070.83
            ['id' => 9, 'stripe_price_id' => 'price_1SDiMn4Fje5CrOkWzHTwegx0', 'price' => 530.01], //	prod_TA2RnzGA6whGgf	Decyphers Pro Annual Package: ONE-TIME PAYMENT of $530.01 for 4,200,000 tokens.		txcd_10202000		2/10/2025 9:00	530.01
            ['id' => 8, 'stripe_price_id' => 'price_1SDiME4Fje5CrOkWJzeLdLmJ', 'price' => 205.51], //	prod_TA2Rl7scjTdva7	Decyphers Basic Annual Package: ONE-TIME PAYMENT of $205.51 for 1,200,000 tokens.		txcd_10202000		2/10/2025 8:59	205.51
            ['id' => 7, 'stripe_price_id' => 'price_1SDiLe4Fje5CrOkWUMaL7jE2', 'price' => 89.24], //	prod_TA2QGZgjaJB1Wm	Decyphers Enterprise Plan: ONE-TIME PAYMENT of $89.24 for 1,050,000 tokens.		txcd_10202000		2/10/2025 8:58	89.24
            ['id' => 6, 'stripe_price_id' => 'price_1SDiKx4Fje5CrOkWtnZUm0M4', 'price' => 44.17], //	prod_TA2Ph0hRiKepEq	Decyphers Pro Plan: ONE-TIME PAYMENT of $44.17 for 350,000 tokens.		txcd_10202000		2/10/2025 8:58	44.17
            ['id' => 5, 'stripe_price_id' => 'price_1SDiKW4Fje5CrOkW7j9texfm', 'price' => 17.13], //	prod_TA2PYz2iUfyot4	Decyphers Basic Plan: ONE-TIME PAYMENT of $17.13 for 100,000 tokens.		txcd_10202000		2/10/2025 8:57	17.13
            ['id' => 4, 'stripe_price_id' => 'price_1SDiIG4Fje5CrOkWyVTbAUcL', 'price' => 127.48], //	prod_TA2MpkTIpr29wf	Enterprise Monthly Subscription		txcd_10202000		2/10/2025 8:55	127.48
            ['id' => 3, 'stripe_price_id' => 'price_1SDiHp4Fje5CrOkWs4B5awPq', 'price' => 63.1], //	prod_TA2MCmFtbNrjF0	Pro Monthly Subscription		txcd_10202000		2/10/2025 8:54	63.1
            ['id' => 2, 'stripe_price_id' => 'price_1SDiHP4Fje5CrOkWCjnIezLC', 'price' => 24.47], //	prod_TA2MFvOBYyjCz1	Basic Monthly Subscription		txcd_10202000		2/10/2025 8:54	24.47
            ['id' => 1, 'stripe_price_id' => 'price_1SDiH24Fje5CrOkWWWXRkLdB', 'price' => 0], //	prod_TA2LUSN0M5comz	Free Subscription		txcd_10202000		2/10/2025 8:54	0
            ['id' => 13, 'stripe_price_id' => 'price_1SDiGg4Fje5CrOkWiUNU5bPe', 'price' => 1223.81], //	prod_TA2LtYrS7YDqLh	Enterprise Annual Subscription		txcd_10202000		2/10/2025 8:53	1223.81
            ['id' => 12, 'stripe_price_id' => 'price_1SDiFy4Fje5CrOkWug92K4Kd', 'price' => 605.72], //	prod_TA2KOGYQ6iOsWc	Pro Annual Subscription		txcd_10202000		2/10/2025 8:52	605.72
            ['id' => 11, 'stripe_price_id' => 'price_1SDiFS4Fje5CrOkWD6NaakOR', 'price' => 234.87], //	prod_TA2KOCQgRRY1E4	Basic Annual Subscription		txcd_10202000		2/10/2025 8:52	234.87
        ];

        $count = 0;
        foreach($prices as $price) {
            Plan::find($price['id'])->update(['stripe_price_id' => $price['stripe_price_id'], 'price' => $price['price']]);
            $count++;
        }

        return 'done '.$count;
    }

    protected function generatePersonalizedVariables($user, array $baseVariables = []): array
    {
        // Start with the base variables
        $personalizedVariables = $baseVariables;
        
        // Add user-specific variables from the database
        $personalizedVariables['firstName'] = explode(' ', $user->name)[0] ?? $user->name;
        $personalizedVariables['fullName'] = $user->name;
        $personalizedVariables['email'] = $user->email;
        $personalizedVariables['userId'] = $user->id;
        
        // You can add more user attributes here as needed
        // For example, if your User model has custom fields:
        // $personalizedVariables['company'] = $user->company;
        // $personalizedVariables['phone'] = $user->phone;
        
        // Log the personalization for debugging
        \Log::info("Generated personalized variables for user {$user->id}", [
            'user' => $user->id,
            'name' => $user->name,
            'variables' => $personalizedVariables
        ]);
        
        return $personalizedVariables;
    }
}
