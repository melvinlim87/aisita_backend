<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Role;
use App\Models\TokenHistory;
use App\Services\ReferralService;
use App\Services\TokenService;
use Kreait\Firebase\Auth as FirebaseAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use App\Models\AuditLog;
use App\Mail\CampaignMail;

class AuthController extends Controller
{
    /**
     * The referral service instance.
     *
     * @var \App\Services\ReferralService
     */
    protected $referralService;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ReferralService  $referralService
     * @return void
     */
    public function __construct(ReferralService $referralService)
    {
        $this->referralService = $referralService;
    }
    // Register new user
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'referral_code' => 'nullable|string|max:255',
            'telegram_id' => 'nullable|string|max:255|unique:users,telegram_id',
        ]);
        
        return $this->processRegistration($request, $request->referral_code);
    }
    
    /**
     * Register new user with a referral code from URL
     *
     * @param Request $request
     * @param string $referralCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function registerWithReferral(Request $request, string $referralCode)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        // Manually validate referral code
        if (empty($referralCode)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => ['referral_code' => ['The referral code field is required.']]
            ], 422);
        }

        $referrerExists = User::where('referral_code', $referralCode)->exists();

        if (!$referrerExists) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => ['referral_code' => ['Invalid referral code.']]
            ], 422);
        }
        
        return $this->processRegistration($request, $referralCode);
    }
    
    /**
     * Process user registration with optional referral code
     *
     * @param Request $request
     * @param string|null $referralCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function processRegistration(Request $request, ?string $referralCode = null)
    {
        // No free tokens for standard registration - only Telegram and WhatsApp users get free tokens
        $bonusTokens = 0;
        $verificationCode = Str::random(32);
        
        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'subscription_token' => $bonusTokens, // Start with bonus subscription tokens
            'addons_token' => 0, // Start with 0 addon tokens
            'role_id' => 1, // Default role ID
            'verification_code' => $verificationCode, // Generate random verification code
        ];
        
        // Include telegram_id if provided
        if ($request->has('telegram_id')) {
            $userData['telegram_id'] = $request->telegram_id;
            
            // If user has telegram_id, award them the free tokens
            if (!empty($request->telegram_id)) {
                $userData['registration_token'] = 4000; // Free tokens for Telegram users
                $bonusTokens = 4000;
            }
        }
        
        $user = User::create($userData);
        
        // Record the free token award in history
        TokenHistory::create([
            'user_id' => $user->id,
            'amount' => $bonusTokens,
            'action' => 'credited',
            'reason' => 'New user registration bonus',
            'balance_after' => $bonusTokens,
        ]);
        
        Log::info('Awarded bonus tokens to new user', [
            'user_id' => $user->id,
            'bonus_tokens' => $bonusTokens
        ]);

        // Automatically generate a referral code for the new user
        $generatedReferralCode = $this->referralService->generateReferralCode($user);
        Log::info('Generated referral code for new user', [
            'user_id' => $user->id,
            'referral_code' => $generatedReferralCode
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        
        // Process referral if a code was provided (either from URL or request body)
        $referralResult = null;
        if (!empty($referralCode)) {
            Log::info('Processing referral for new user', [
                'user_id' => $user->id,
                'referral_code' => $referralCode,
                'source' => 'URL parameter'
            ]);
            
            $referralResult = $this->referralService->processNewUserReferral($user, $referralCode);
            
            if ($referralResult['success']) {
                Log::info('Referral processed successfully', [
                    'user_id' => $user->id,
                    'referral' => $referralResult['referral']
                ]);
            } else {
                Log::warning('Failed to process referral', [
                    'user_id' => $user->id,
                    'message' => $referralResult['message']
                ]);
            }
        }

        // Generate a shareable link
        $appUrl = config('app.url', 'http://localhost:8000');
        $shareableLink = $appUrl . '/register/' . $user->referral_code;
        
        // Send welcome email to user - Move this after the verification process
        $referralService = new ReferralService;
        $tokenService = new TokenService($referralService);
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
 
            $verificationEmail = EmailTemplate::where('name', 'Verification Email')->first();
            // If verification email template doesn't exist, fall back to welcome email
            if (!$verificationEmail) {
                $verificationEmail = EmailTemplate::where('name', 'Welcome Email')->first();
            }
            
            // Send verification email with verification code
            $campaign = EmailCampaign::create([
                'user_id' => 1,
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
            $personalizedVariables = $this->generatePersonalizedVariables($user, array_merge($request->all(), [
                'verificationCode' => $verificationCode,
                'verificationUrl' => config('app.frontend_url', 'http://localhost:3000') . '/verify-signup?code=' . $verificationCode . '&email=' . urlencode($user->email)
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
      
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'referral_code' => $user->referral_code,
            'shareable_link' => $shareableLink,
            'referral_applied' => $referralResult ? $referralResult['success'] : null,
            'referral_message' => $referralResult ? $referralResult['message'] : null,
            'verification_email_sent' => true,
            'requires_verification' => true
        ], 201);
    }

    // Login user
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        // Add check if user has email verified in production
        if (env('APP_ENV') === 'production' && !$user->email_verified_at) {
            return response()->json(['message' => 'Email not verified'], 401);
        }
        
        if (!$user || !Hash::check($request->password, $user->password) || $user->disabled) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->update(['last_login_at' => now()]);

        AuditLog::create([
            'user_id' => $user->id,
            'event' => 'user_login_success',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 1023),
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    // Get authenticated user
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    // Logout user
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    // Update user
    public function update(Request $request)
    {
        $user = $request->user();
        
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8|confirmed',
            'phone_number' => 'sometimes|string|max:20|nullable',
        ]);

        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        
        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }
        
        if ($request->has('phone_number')) {
            $user->phone_number = $request->phone_number;
        }
        
        $user->save();
        
        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user
        ]);
    }
    
    // Delete user
    public function delete(Request $request)
    {
        $user = $request->user();
        
        // Revoke all tokens
        $user->tokens()->delete();
        
        // Delete the user
        $user->delete();
        
        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }
    
    /**
     * Create a new role.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createRole(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);
        
        $role = Role::create([
            'name' => $request->name,
            'display_name' => $request->display_name,
            'description' => $request->description,
        ]);
        
        return response()->json([
            'message' => 'Role created successfully',
            'role' => $role
        ], 201);
    }
    
    /**
     * List all roles.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listRoles()
    {
        $roles = Role::all();
        
        return response()->json([
            'roles' => $roles
        ]);
    }
    
    /**
     * Assign a role to a user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignRole(Request $request, $userId)
    {
        $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);
        
        $user = User::findOrFail($userId);
        $user->role_id = $request->role_id;
        $user->save();
        
        return response()->json([
            'message' => 'Role assigned successfully',
            'user' => $user->load('role')
        ]);
    }

    // Firebase login for bridging Firebase Auth to Laravel Sanctum
    public function firebaseLogin(Request $request, FirebaseAuth $firebaseAuth)
    {
        $request->validate([
            'idToken' => 'required|string',
        ]);

        try {
            $verifiedIdToken = $firebaseAuth->verifyIdToken($request->idToken);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name', $email);

            // Debug log for Firebase UID and claims
            \Log::info('Firebase Login Debug', [
                'firebaseUid' => $firebaseUid,
                'email' => $email,
                'claims' => $verifiedIdToken->claims()->all(),
            ]);

            // First check if user exists by email
            $existingUser = User::where('email', $email)->first();
            
            if ($existingUser && empty($existingUser->firebase_uid)) {
                // User exists but doesn't have Firebase UID - update it
                $existingUser->firebase_uid = $firebaseUid;
                $existingUser->save();
                $user = $existingUser;
                \Log::info('Updated existing user with Firebase UID', ['user_id' => $user->id]);
            } else {
                // Find or create user by Firebase UID
                $user = User::firstOrCreate(
                    ['firebase_uid' => $firebaseUid],
                    [
                        'email' => $email,
                        'name' => $name,
                        'password' => Hash::make(Str::random(32)), // random password
                        'subscription_token' => 0, // Start with 0 subscription tokens
                        'addons_token' => 0, // Start with 0 addon tokens
                        'role_id' => 1, // Default role ID
                    ]
                );
            }

            // Issue Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        } catch (\Throwable $e) {
            // Log the specific error for debugging
            \Log::error('Firebase auth error in AuthController: ' . $e->getMessage());
            
            // Check for specific Firebase errors
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'auth/email-already-in-use') !== false) {
                return response()->json([
                    'message' => 'This email is already registered. Please try signing in with your password.',
                    'error' => 'email-already-in-use',
                    'error_details' => $errorMessage
                ], 409); // Conflict status code
            }
            
            return response()->json([
                'message' => 'Authentication failed',
                'error' => $errorMessage
            ], 401);
        }
    }
     
  /**
     * Generate personalized template variables for a specific recipient.
     * Combines base variables with user-specific data from the database.
     *
     * @param  \App\Models\User  $user
     * @param  array  $baseVariables
     * @return array
     */
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
        Log::info("Generated personalized variables for user {$user->id}", [
            'user' => $user->id,
            'name' => $user->name,
            'variables' => $personalizedVariables
        ]);
        
        return $personalizedVariables;
    }

    // Change password for authenticated user
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6', // expects password_confirmation
        ]);

        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized']);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The provided current password is incorrect.'
            ]);
        }

        // Update to new password
        $user->password = Hash::make($request->password);
        $user->save();

        // // Optionally: revoke all other tokens (keep current)
        // try {
        //     $currentTokenId = optional($request->user()->currentAccessToken())->id;
        //     $user->tokens()->when($currentTokenId, function ($q) use ($currentTokenId) {
        //         $q->where('id', '!=', $currentTokenId);
        //     })->delete();
        // } catch (\Throwable $e) {
        //     \Log::warning('Failed to revoke other tokens after password change: ' . $e->getMessage());
        // }

        // Log audit
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'user_password_changed',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 1023),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to create audit log for password change: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.'
        ]);
    }

    // Forgot/Reset password using a temporary password
    public function forgetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Always return a generic response to avoid user enumeration
        $genericResponse = response()->json([
            'message' => 'If an account with that email exists, a password reset email has been sent.'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $genericResponse; // Do not reveal whether the email exists
        }

        // Generate a secure temporary password
        $temporaryPassword = Str::random(14);

        // Update the user's password
        $user->password = Hash::make($temporaryPassword);
        $user->save();

        // Log audit
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'user_password_reset_temp_issued',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 1023),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to create audit log for password reset: ' . $e->getMessage());
        }

        // Configure SMTP using the existing pattern
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
        } else {
            \Log::warning('No default SMTP configuration found for password reset email');
        }

        // Send the temporary password email
        try {
            $appName = config('app.name', 'Decyphers');
            $resetInstructions = "Hello {$user->name},\n\n" .
                "A temporary password has been generated for your account.\n" .
                "Temporary Password: {$temporaryPassword}\n\n" .
                "For security, please log in and change your password immediately from your profile settings.\n\n" .
                "If you did not request this change, please contact support immediately.";

            Mail::raw($resetInstructions, function ($message) use ($user, $appName, $smtpConfig) {
                $message->to($user->email, $user->name)
                        ->subject("{$appName} - Password Reset");
                if ($smtpConfig && !empty($smtpConfig->from_address)) {
                    $message->from($smtpConfig->from_address, $smtpConfig->from_name ?: $appName);
                }
            });
        } catch (\Throwable $e) {
            \Log::error('Failed to send password reset email: ' . $e->getMessage());
        }

        return $genericResponse;
    }

    public function verifySignup(Request $request)
    {
        $request->validate([
            'code' => 'required',
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified',
            ], 400);
        }

        if ($user->verification_code !== $request->code) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code',
            ], 400);
        }

        // Add 2000 token in registration_token
        $user->registration_token = 2000;

        // Update verification code to null
        $user->verification_code = null;

        // User must be automatically subscribed to the free plan

        // Send user a welcome email
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
        }

        try {
            $appName = config('app.name', 'Decyphers');
            
            // Get or create welcome email template
            $welcomeEmail = EmailTemplate::where('name', 'Welcome Email')->first();
            if (!$welcomeEmail) {
                $welcomeEmail = EmailTemplate::create([
                    'name' => 'Welcome Email',
                    'subject' => 'Welcome to Decyphers AI!',
                    'html_content' => '
                        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                            <h2 style="color: #333;">Welcome to Decyphers AI!</h2>
                            <p>Hello {{name}},</p>
                            <p>We are excited to have you on board. Your account has been successfully verified and you\'ve received 2000 free tokens to get started!</p>
                            <p>You can now log in and start using our AI-powered trading analysis tools.</p>
                            <p>If you have any questions or need assistance, please don\'t hesitate to contact our support team.</p>
                            <p>Thank you for choosing Decyphers AI.</p>
                            <p>Best regards,<br>The Decyphers AI Team</p>
                        </div>
                    ',
                    'text_content' => 'Hello {{name}},\n\nWelcome to Decyphers AI!\n\nWe are excited to have you on board. Your account has been successfully verified and you\'ve received 2000 free tokens to get started!\n\nYou can now log in and start using our AI-powered trading analysis tools.\n\nIf you have any questions or need assistance, please don\'t hesitate to contact our support team.\n\nThank you for choosing Decyphers AI.\n\nBest regards,\nThe Decyphers AI Team'
                ]);
            }

            // Create campaign for welcome email
            $campaign = EmailCampaign::create([
                'user_id' => 1,
                'name' => 'Welcome Email',
                'subject' => "Welcome to {$appName}!",
                'from_email' => $smtpConfig->from_address,
                'from_name' => $smtpConfig->from_name,
                'html_content' => $welcomeEmail->html_content ?? '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
                        <h2 style="color: #333;">Welcome to {{appName}}!</h2>
                        <p>Hello {{name}},</p>
                        <p>We are excited to have you on board. Your account has been successfully verified and you\'ve received 2000 free tokens to get started!</p>
                        <p>You can now log in and start using our AI-powered trading analysis tools.</p>
                        <p>If you have any questions or need assistance, please don\'t hesitate to contact our support team.</p>
                        <p>Thank you for choosing {{appName}}.</p>
                        <p>Best regards,<br>The {{appName}} Team</p>
                    </div>
                ',
                'email_template_id' => $welcomeEmail->id ?? null,
                'status' => 'draft', 
                'all_users' => 0,
            ]);
            
            // Generate personalized variables for welcome email
            $personalizedVariables = $this->generatePersonalizedVariables($user, [
                'appName' => $appName,
                'name' => $user->name,
                'email' => $user->email
            ]);
            
            \Log::info('Sending welcome email', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
            Mail::to($user->email)->send(new CampaignMail($campaign, $personalizedVariables));
            
        } catch (\Throwable $e) {
            \Log::error('Failed to send welcome email: ' . $e->getMessage());
        }

        

        $user->email_verified_at = now();
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully',
        ], 200);
    }
}
