<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\StripeController;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use App\Http\Controllers\AnalyticsAssistantController;
use App\Http\Controllers\EmailCampaignController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\SmtpConfigurationController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TicketReplyController;
use App\Http\Controllers\SubscriptionController;


//Public routes



// Login Routes

// Public routes
Route::get('/test', [\App\Http\Controllers\UserController::class, 'test']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/register/{referral_code}', [AuthController::class, 'registerWithReferral']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/reset-password', [AuthController::class, 'forgetPassword']);
Route::post('/change-password', [AuthController::class, 'changePassword']);
Route::post('/verify-signup', [AuthController::class, 'verifySignup']);

Route::get('/config/recaptcha', [\App\Http\Controllers\ConfigController::class, 'getReCaptchaSiteKey']);

// Stripe webhook endpoint for subscription events (does not require authentication)
Route::post('/subscriptions/webhook', [SubscriptionController::class, 'webhook']);
Route::get('/config/telegram', [\App\Http\Controllers\ConfigController::class, 'getTelegramConfig']);

// Test routes - Development only
Route::get('/test/subscription-enforcement', [\App\Http\Controllers\TestController::class, 'testSubscriptionEnforcement']);

// Telegram auth routes
use App\Http\Controllers\TelegramAuthController;
Route::post('/auth/telegram/register', [TelegramAuthController::class, 'register']);
Route::post('/auth/telegram/verify', [TelegramAuthController::class, 'verify']);

// Test route
Route::get('/telegram/test', function() {
    return response()->json(['status' => 'success', 'message' => 'Test route is working']);
});

// Telegram webhook (must be public for Telegram servers to access)
Route::post('/telegram/webhook', [\App\Http\Controllers\TelegramWebhookController::class, 'handle']);

// WhatsApp auth routes
use App\Http\Controllers\WhatsAppAuthController;
Route::post('/auth/whatsapp/register', [WhatsAppAuthController::class, 'register']);
Route::post('/auth/whatsapp/verify', [WhatsAppAuthController::class, 'verify']);

Route::post('/verify-recaptcha', [\App\Http\Controllers\ConfigController::class, 'verifyReCaptcha']);

// Stripe payment routes
Route::post('/stripe/create-checkout', [StripeController::class, 'createCheckoutSession']);
Route::get('/stripe/verify-session', [StripeController::class, 'verifySession']);
Route::post('/stripe/verify-session', [StripeController::class, 'verifySessionPost']);
Route::post('/stripe/webhook', [StripeController::class, 'handleWebhook']);

//End public routes

// Protected routes
Route::middleware(['auth:sanctum',])->group(function () {

        // Auth endpoints
        Route::post('/logout', [AuthController::class, 'logout']);
    
        // Usage Break Down
        Route::get('/usage', [\App\Http\Controllers\UsageBreakDownController::class, 'getUserUsageBreakDown']);

        // Model endpoints
        Route::get('/models', [\App\Http\Controllers\ModelController::class, 'getAvailableModels']);
        Route::post('/model-cost', [\App\Http\Controllers\ModelController::class, 'calculateTokenCost']);

        // Purchase history
        Route::get('/stripe/purchases', [StripeController::class, 'listPurchases']);
        
        // Token management
        Route::get('/tokens/packages', [\App\Http\Controllers\TokenController::class, 'listPackages']);
        Route::post('/tokens/purchase', [\App\Http\Controllers\TokenController::class, 'purchaseTokens']);
        Route::post('/tokens/verify-purchase', [\App\Http\Controllers\TokenController::class, 'verifyPurchase']);
        Route::get('/tokens/balance', [\App\Http\Controllers\TokenController::class, 'getBalance']);
        Route::get('/tokens/history', [\App\Http\Controllers\TokenController::class, 'getUsageHistory']);
        Route::get('/tokens/usage', [\App\Http\Controllers\TokenController::class, 'getTokenUsageData']);
        // Admin route to manually add tokens to users
        Route::post('/tokens/manually-add', [\App\Http\Controllers\TokenController::class, 'manuallyAddTokens'])->middleware(\App\Http\Middleware\CheckRole::class . ':admin');

        // OpenRouter imitation endpoints
        Route::post('/openrouter/analyze-image', [\App\Http\Controllers\OpenRouterController::class, 'analyzeImage']);
        Route::post('/openrouter/send-chat', [\App\Http\Controllers\OpenRouterController::class, 'sendChatMessage']);
        Route::post('/openrouter/calculate-cost', [\App\Http\Controllers\OpenRouterController::class, 'calculateCostEndpoint']);
        Route::post('/openrouter/generate-ea', [App\Http\Controllers\OpenRouterController::class, 'generateEA']);
        Route::post('/openrouter/chatbot', [App\Http\Controllers\OpenRouterController::class, 'chatbot']);
        Route::post('/openrouter/chatbot-history', [App\Http\Controllers\OpenRouterController::class, 'getChatbotHistory']);
        
        // AI Educator
        Route::post('/openrouter/ai-educator', [App\Http\Controllers\AIEducatorController::class, 'handleQuestion']);
        Route::post('/openrouter/ai-educator-history', [App\Http\Controllers\AIEducatorController::class, 'getAIEducatorHistory']);
        
        // OpenAI endpoints
        Route::post('/openai/analyze-image', [\App\Http\Controllers\OpenAIController::class, 'analyzeImage']);
        Route::post('/openai/send-chat', [\App\Http\Controllers\OpenAIController::class, 'sendChatMessage']);
        Route::post('/openai/calculate-cost', [\App\Http\Controllers\OpenAIController::class, 'calculateCostEndpoint']);

        // Gemini routes
        Route::post('/gemini/analyze-image', [\App\Http\Controllers\GeminiController::class, 'analyzeImage']);
        Route::post('/gemini/send-chat', [\App\Http\Controllers\GeminiController::class, 'sendChatMessage']);
        Route::post('/gemini/calculate-cost', [\App\Http\Controllers\GeminiController::class, 'calculateCostEndpoint']);

        // Chart on demand
        Route::post('/generate-chart', [\App\Http\Controllers\ChartOnDemandController::class, 'generateAdvancedChartV2']);

        // Regular user routes (available to all authenticated users)
        Route::get('/profile', [\App\Http\Controllers\UserController::class, 'profile']);
        Route::put('/profile', [\App\Http\Controllers\UserController::class, 'updateProfile']);
        Route::post('/profile/upload-picture', [\App\Http\Controllers\UserController::class, 'uploadProfilePicture']);

        // Schedule Task
        Route::get('/schedule-task', [\App\Http\Controllers\ScheduleTaskController::class, 'index']);
        Route::post('/schedule-task', [\App\Http\Controllers\ScheduleTaskController::class, 'store']);
        Route::get('/schedule-task/{id}', [\App\Http\Controllers\ScheduleTaskController::class, 'show']);
        Route::put('/schedule-task/{id}', [\App\Http\Controllers\ScheduleTaskController::class, 'update']);
        Route::delete('/schedule-task/{id}', [\App\Http\Controllers\ScheduleTaskController::class, 'destroy']);
        
        // Telegram and WhatsApp connect account
        // Telegram webhook moved to public routes
        Route::get('/telegram/check-telegram-connect', [\App\Http\Controllers\TelegramWebhookController::class, 'checkTelegramConnect']);
        Route::post('/telegram/verify-code', [\App\Http\Controllers\TelegramWebhookController::class, 'verifyCode']);

        
        // Get admin users for ticket assignment
        Route::get('/users/admins', [\App\Http\Controllers\UserController::class, 'getAdmins'])->name('users.admins');
        
        // Subscription endpoints (available to all authenticated users)
        Route::middleware(['auth:api'])->prefix('subscriptions')->group(function () {
            Route::post('/initiate', [SubscriptionController::class, 'initiateSubscription']);
            Route::post('/verify-checkout', [SubscriptionController::class, 'verifyCheckoutSession']);
            Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
            Route::post('/cancel', [SubscriptionController::class, 'cancel']);
            Route::post('/change-plan', [SubscriptionController::class, 'changePlan']);
            Route::post('/resume', [SubscriptionController::class, 'resume']);
            Route::get('/plans', [SubscriptionController::class, 'getPlans']);
            Route::get('/user', [SubscriptionController::class, 'getUserSubscription']);
            Route::post('/billing-portal', [StripeController::class, 'createBillingPortalSession']);
            Route::get('/customer-portal', [SubscriptionController::class, 'createCustomerPortalSession']);
            Route::get('/', [SubscriptionController::class, 'index'])->middleware('admin');
        });
        
        // Support Ticket Replies (available to all authenticated users)
        Route::post('/support/tickets/{ticket}/replies', [TicketReplyController::class, 'store'])->name('support.tickets.replies.store');
        Route::get('/support/tickets/{ticket}/replies', [TicketReplyController::class, 'index'])->name('support.tickets.replies.index');

        // History endpoints
        Route::post('/history/save-analysis', [\App\Http\Controllers\HistoryController::class, 'saveAnalysis']);
        Route::get('/history', [\App\Http\Controllers\HistoryController::class, 'getHistory']);
        Route::get('/history/type/{type}', [\App\Http\Controllers\HistoryController::class, 'getHistoryByType']);
        Route::get('/history/{id}', [\App\Http\Controllers\HistoryController::class, 'getAnalysis']);
        Route::delete('/history/{id}', [\App\Http\Controllers\HistoryController::class, 'deleteAnalysis']);
        
        // Referral endpoints
        Route::get('/referral/code', [\App\Http\Controllers\ReferralController::class, 'generateReferralCode']);
        Route::post('/referral/apply', [\App\Http\Controllers\ReferralController::class, 'applyReferralCode']);
        Route::get('/referral/list', [\App\Http\Controllers\ReferralController::class, 'getReferrals']);
        Route::post('/referral/convert', [\App\Http\Controllers\ReferralController::class, 'convertReferral']);
        Route::get('/referral/status', [\App\Http\Controllers\ReferralController::class, 'getReferralStatus']);
        Route::get('/referral/badges', [\App\Http\Controllers\ReferralController::class, 'getUserBadges']);
        
        // Affiliate endpoints
        Route::post('/affiliate/track-sale', [\App\Http\Controllers\AffiliateController::class, 'trackAffiliateSale']);
        Route::get('/affiliate/status', [\App\Http\Controllers\AffiliateController::class, 'getAffiliateStatus']);
        Route::get('/affiliate/leaderboard', [\App\Http\Controllers\AffiliateController::class, 'getLeaderboard']);
        Route::post('/affiliate/check-milestones', [\App\Http\Controllers\AffiliateController::class, 'checkMilestones']);
        
        // Support Ticket endpoints (for all authenticated users)
        Route::get('/support/my-tickets', [SupportTicketController::class, 'index'])->name('support.my-tickets.index');
        Route::post('/support/tickets', [SupportTicketController::class, 'store'])->name('support.tickets.store');
        Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])->name('support.tickets.show');
        Route::put('/support/tickets/{ticket}', [SupportTicketController::class, 'update'])->name('support.tickets.update');
        Route::post('/support/tickets/{ticket}/replies', [TicketReplyController::class, 'store'])->name('support.tickets.replies.store');
        Route::get('/support/tickets/{ticket}/replies', [TicketReplyController::class, 'index'])->name('support.tickets.replies.index');
        
        // Chat endpoints
        Route::post('/chat/sessions', [\App\Http\Controllers\ChatController::class, 'createSession']);
        Route::get('/chat/sessions', [\App\Http\Controllers\ChatController::class, 'getSessions']);
        Route::post('/chat/messages', [\App\Http\Controllers\ChatController::class, 'addMessage']);
        Route::get('/chat/sessions/{sessionId}/messages', [\App\Http\Controllers\ChatController::class, 'getSessionMessages']);
        Route::put('/chat/sessions/{sessionId}/close', [\App\Http\Controllers\ChatController::class, 'closeSession']);
        Route::delete('/chat/sessions/{sessionId}', [\App\Http\Controllers\ChatController::class, 'deleteSession']);

        // Forex New
        Route::get('/forex_news', [\App\Http\Controllers\ForexNewController::class, 'index']);
        Route::post('/forex_news', [\App\Http\Controllers\ForexNewController::class, 'store']);

        // Admin routes
        Route::middleware([\App\Http\Middleware\CheckRole::class.':admin'])->group(function () {
            Route::get('/dashboard-statistic', [\App\Http\Controllers\DashboardController::class, 'index']);

            // User management endpoints (view and edit)
            Route::get('/users', [\App\Http\Controllers\UserController::class, 'index']);
            Route::get('/users/basic-role', [\App\Http\Controllers\UserController::class, 'getUsersWithBasicRole']);
            Route::get('/users/{userId}', [\App\Http\Controllers\UserController::class, 'show']);
            Route::put('/users/{userId}', [\App\Http\Controllers\UserController::class, 'update']);
            
            // CRM endpoints - admin only
            Route::post('/analytics-assistant/query', [AnalyticsAssistantController::class, 'processQuery']);
            

            // Test endpoint for debugging
            Route::get('/admin-test', function() {
                return response()->json(['message' => 'Admin access successful']);
            });

            // Audit Log endpoint
            Route::get('/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index']);

            // Marketing Email Campaigns
            Route::get('/marketing/campaigns', [EmailCampaignController::class, 'index'])->name('campaigns.index');
            Route::post('/marketing/campaigns', [EmailCampaignController::class, 'store'])->name('campaigns.store');
            Route::get('/marketing/campaigns/{campaign}', [EmailCampaignController::class, 'show'])->name('campaigns.show');
            Route::put('/marketing/campaigns/{campaign}', [EmailCampaignController::class, 'update'])->name('campaigns.update');
            Route::delete('/marketing/campaigns/{campaign}', [EmailCampaignController::class, 'destroy'])->name('campaigns.destroy');
            Route::post('/marketing/campaigns/{campaign}/send', [EmailCampaignController::class, 'sendCampaign'])->name('campaigns.send');

            // Marketing Email Templates
            Route::get('/marketing/templates', [EmailTemplateController::class, 'index'])->name('templates.index');
            Route::post('/marketing/templates', [EmailTemplateController::class, 'store'])->name('templates.store');
            Route::get('/marketing/templates/{template}', [EmailTemplateController::class, 'show'])->name('templates.show');
            Route::put('/marketing/templates/{template}', [EmailTemplateController::class, 'update'])->name('templates.update');
            Route::delete('/marketing/templates/{template}', [EmailTemplateController::class, 'destroy'])->name('templates.destroy');

            // Subscription management (Admin only)
            Route::get('/admin/subscriptions', [SubscriptionController::class, 'index']);
            
            // Admin Transactions endpoint
            Route::get('/admin/transactions', [StripeController::class, 'getAllTransactions']);
            
            // Admin Invoices endpoint
            Route::get('/admin/invoices', [StripeController::class, 'getAllInvoices']);
            
            // Manual Token Additions endpoint
            Route::get('/admin/manual-token-additions', [\App\Http\Controllers\TokenController::class, 'getManualTokenAdditions']);
            Route::get('/admin/invoices/{id}', [StripeController::class, 'getInvoice']);
            
            // SMTP Configuration (Admin only)
            Route::get('/system/smtp-configuration', [SmtpConfigurationController::class, 'index'])->name('system.smtp.index'); // Changed to index
            Route::post('/system/smtp-configuration', [SmtpConfigurationController::class, 'store'])->name('system.smtp.store');
            Route::put('/system/smtp-configuration/{smtpConfiguration}', [SmtpConfigurationController::class, 'update'])->name('system.smtp.update');
            Route::patch('/system/smtp-configuration/{smtpConfiguration}', [SmtpConfigurationController::class, 'setDefault'])->name('system.smtp.setDefault'); // Simplified route structure
            Route::delete('/system/smtp-configuration/{smtpConfiguration}', [SmtpConfigurationController::class, 'destroy'])->name('system.smtp.destroy');
            
            // Referral Tier Management (Admin only)
            Route::get('/referral/tiers', [\App\Http\Controllers\Admin\ReferralTierController::class, 'index'])->name('referral.tiers.index');
            Route::post('/referral/tiers', [\App\Http\Controllers\Admin\ReferralTierController::class, 'store'])->name('referral.tiers.store');
            Route::get('/referral/tiers/{id}', [\App\Http\Controllers\Admin\ReferralTierController::class, 'show'])->name('referral.tiers.show');
            Route::put('/referral/tiers/{id}', [\App\Http\Controllers\Admin\ReferralTierController::class, 'update'])->name('referral.tiers.update');
            Route::delete('/referral/tiers/{id}', [\App\Http\Controllers\Admin\ReferralTierController::class, 'destroy'])->name('referral.tiers.destroy');
            
            // Sales Milestone Tier Management (Admin only)
            Route::get('/affiliate/milestone-tiers', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'index'])->name('affiliate.milestones.index');
            Route::post('/affiliate/milestone-tiers', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'store'])->name('affiliate.milestones.store');
            Route::get('/affiliate/milestone-tiers/{id}', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'show'])->name('affiliate.milestones.show');
            Route::put('/affiliate/milestone-tiers/{id}', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'update'])->name('affiliate.milestones.update');
            Route::delete('/affiliate/milestone-tiers/{id}', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'destroy'])->name('affiliate.milestones.destroy');
            
            // Affiliate Reward Management (Admin only)
            Route::get('/affiliate/rewards', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'getAllAffiliateRewards'])->name('affiliate.rewards.index');
            Route::put('/affiliate/rewards/{id}', [\App\Http\Controllers\Admin\SalesMilestoneTierController::class, 'updateRewardStatus'])->name('affiliate.rewards.update');
            
            // Test Email Endpoint
            Route::post('/test-email', [\App\Http\Controllers\TestEmailController::class, 'sendTestEmail'])->name('test.email');
            
            // Support Ticket Management (Admin)
            // Important: Use different route patterns to avoid conflicts with wildcard routes
            Route::get('/support/tickets', [SupportTicketController::class, 'index'])->name('support.tickets.index');
            Route::get('/support/tickets-statistics', [SupportTicketController::class, 'statistics'])->name('support.tickets.statistics');
            Route::get('/support/my-assigned-tickets', [SupportTicketController::class, 'assignedToMe'])->name('support.tickets.assigned');
            
            // Wildcard routes
            Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])->name('support.tickets.show');
            Route::put('/support/tickets/{ticket}', [SupportTicketController::class, 'update'])->name('support.tickets.update');
        });

        // Super Admin routes
        Route::middleware([\App\Http\Middleware\CheckRole::class.':super_admin'])->group(function () {
            // User deletion (super admin only)
            Route::delete('/users/{userId}', [\App\Http\Controllers\UserController::class, 'destroy']);
            
            // Role management (super admin only)
            Route::post('/roles', [\App\Http\Controllers\AuthController::class, 'createRole']);
            Route::get('/roles', [\App\Http\Controllers\AuthController::class, 'listRoles']);
            Route::put('/users/{userId}/role', [\App\Http\Controllers\AuthController::class, 'assignRole']);
            
            // Support Ticket Assignment (Super Admin only)
            Route::post('/support/tickets/{ticket}/assign', [SupportTicketController::class, 'assign'])->name('support.tickets.assign');
        });
});



