<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Storage as FirebaseStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Role;
use App\Models\AuditLog;

class UserController extends Controller
{
    /**
     * The Firebase Auth instance.
     *
     * @var \Kreait\Firebase\Contract\Auth
     */
    protected $auth;
    protected $storage;

    /**
     * Create a new controller instance.
     *
     * @param  \Kreait\Firebase\Contract\Auth  $auth
     * @return void
     */
    public function __construct(FirebaseAuth $auth, FirebaseStorage $storage)
    {
        $this->auth = $auth;
        $this->storage = $storage;
    }

    /**
     * Get all users from the database.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            // Get all users from the database with their roles
            $users = User::with(['role', 'subscription', 'subscription.plan', 'referrer', 'referrals'])->get();
            
            return response()->json([
                'success' => true,
                'data' => $users
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in UserController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Get users with a basic role (role_id IS NULL or role_id = 1).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsersWithBasicRole(Request $request): JsonResponse
    {
        try {
            $query = $request['query'];
            $users = User::with('role')
                         ->whereNull('role_id')
                         ->where('role_id', 1)
                         ->orWhere("email", "LIKE", "%$query%")
                         ->orWhere("name", "LIKE", "%$query%")
                         ->with(['subscription', 'subscription.plan', 'referrer'])
                         ->get();
            
            return response()->json([
                'success' => true,
                'data' => $users
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in UserController@getUsersWithBasicRole: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching users with basic role',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    
    /**
     * Update a user's details.
     *
     * @param string $id
     * @param \Illuminate\Http\Request $request
     * @return JsonResponse
     */
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            // Find the user in the database
            $user = User::findOrFail($id);
            $oldValues = $user->getAttributes(); // Get all current attributes
            
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
                'password' => 'sometimes|string|min:8|confirmed',
                'role_id' => 'sometimes|exists:roles,id',
                'disabled' => 'sometimes|boolean'
            ]);
            
            // Update user properties
            if ($request->has('name')) {
                $user->name = $request->name;
            }
            
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            
            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }
            
            if ($request->has('role_id')) {
                // Only allow role updates if the current user is an admin
                if ($request->user()->isAdmin()) {
                    $user->role_id = $request->role_id;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to change user roles'
                    ], 403);
                }
            }

            if ($request->has('disabled')) {
                $user->disabled = $request->disabled;
            }

            if ($request->has('email_verified') && $request->email_verified == true) {
                $user->email_verified_at = date('Y-m-d H:i:s');
            }
            
            $user->save();

            // Audit logging starts
            $newValues = $user->getAttributes();
            $loggedOldValues = [];
            $loggedNewValues = [];

            // Define attributes we are interested in logging changes for
            $monitoredAttributes = ['name', 'email', 'role_id']; 

            foreach ($monitoredAttributes as $attribute) {
                if ($request->has($attribute)) { // Only consider attributes present in the request
                    // Ensure $oldValues[$attribute] exists or default to null
                    $oldVal = array_key_exists($attribute, $oldValues) ? $oldValues[$attribute] : null;
                    $newVal = $newValues[$attribute] ?? null; 
                    if ($oldVal !== $newVal) {
                        $loggedOldValues[$attribute] = $oldVal;
                        $loggedNewValues[$attribute] = $newVal;
                    }
                }
            }

            if (!empty($loggedOldValues) || !empty($loggedNewValues)) {
                AuditLog::create([
                    'user_id' => Auth::id(), // The admin performing the action
                    'event' => 'user_updated',
                    'auditable_type' => User::class,
                    'auditable_id' => $user->id,
                    'old_values' => $loggedOldValues,
                    'new_values' => $loggedNewValues,
                    'url' => $request->fullUrl(),
                    'ip_address' => $request->ip(),
                    'user_agent' => substr($request->userAgent() ?? '', 0, 1023),
                ]);
            }
            // Audit logging ends
            
            // Update Firebase user if connected
            if ($user->firebase_uid) {
                try {
                    $properties = [];
                    
                    if ($request->has('name')) {
                        $properties['displayName'] = $request->name;
                    }
                    
                    if ($request->has('email')) {
                        $properties['email'] = $request->email;
                    }
                    
                    if (!empty($properties)) {
                        $this->auth->updateUser($user->firebase_uid, $properties);
                    }
                } catch (\Exception $e) {
                    // Log the error but don't fail the request
                    Log::warning('Failed to update Firebase user: ' . $e->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user->load('role')
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error in UserController@update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
    
    /**
     * Get a specific user by ID.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Find the user in the database with all related data
            $user = User::with([
                'role',
                'subscriptions.plan', // Include subscription data with the related plan
                'purchases',          // Purchase history
                'supportTickets',     // Support tickets
                'assignedTickets',    // Support tickets assigned to the user (if admin)
                'ticketReplies',      // Support ticket responses
                'referrals',          // Users this user has referred
                'referrals.referred.subscription.plan', // This user subscription
                'referredBy',         // How this user was referred
                'referrer',           // The user who referred this user
            ])->findOrFail($id);
            
            // Get Firebase details if available
            $firebaseData = null;
            if ($user->firebase_uid) {
                try {
                    $firebaseUser = $this->auth->getUser($user->firebase_uid);
                    $firebaseData = [
                        'displayName' => $firebaseUser->displayName ?? null,
                        'emailVerified' => $firebaseUser->emailVerified ?? false,
                        'disabled' => $firebaseUser->disabled ?? false,
                        'metadata' => [
                            'createdAt' => $firebaseUser->metadata->createdAt ? $firebaseUser->metadata->createdAt->format('Y-m-d H:i:s') : null,
                            'lastLoginAt' => $firebaseUser->metadata->lastLoginAt ? $firebaseUser->metadata->lastLoginAt->format('Y-m-d H:i:s') : null,
                        ]
                    ];
                } catch (\Exception $e) {
                    Log::warning('Could not fetch Firebase details: ' . $e->getMessage());
                }
            }
            
            // Make sure we explicitly load all relationships in the response
            // Create a custom array to ensure all relationships are included
            $userData = [
                // Basic user information
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'firebase_uid' => $user->firebase_uid,
                'telegram_id' => $user->telegram_id,
                'telegram_username' => $user->telegram_username,
                'subscription_token' => $user->subscription_token,
                'registration_token' => $user->registration_token,
                'free_token' => $user->free_token,
                'addons_token' => $user->addons_token,
                'referral_code' => $user->referral_code,
                'referral_count' => $user->referral_count,
                'referred_by' => $user->referred_by,
                'country' => $user->country,
                'date_of_birth' => $user->date_of_birth,
                'gender' => $user->gender,
                'profile_picture_url' => $user->profile_picture_url,
                'last_login_at' => $user->last_login_at,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
                
                // Related data
                'role' => $user->role,
                'subscriptions' => $user->subscriptions,
                'purchases' => $user->purchases,
                'supportTickets' => $user->supportTickets,
                'assignedTickets' => $user->assignedTickets,
                'ticketReplies' => $user->ticketReplies,
                'referrals' => $user->referrals,
                'referredBy' => $user->referredBy,
                'referrer' => $user->referrer
            ];
            
            // Add Firebase data if available
            if ($firebaseData) {
                $userData['firebase'] = $firebaseData;
            }
            
            return response()->json([
                'success' => true,
                'data' => $userData
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error in UserController@show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Delete a user by ID.
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            // Find the user in the database
            $user = User::findOrFail($id);
            
            // Delete from Firebase if connected
            if ($user->firebase_uid) {
                try {
                    $this->auth->deleteUser($user->firebase_uid);
                } catch (\Exception $e) {
                    Log::warning('Could not delete Firebase user: ' . $e->getMessage());
                }
            }
            
            // Revoke all tokens
            $user->tokens()->delete();
            
            // Delete the user from the database
            $user->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error in UserController@destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    
    /**
     * Get the authenticated user's profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            
            // If user has firebase_uid, get additional details from Firebase
            $firebaseUser = null;
            if ($user->firebase_uid) {
                try {
                    $firebaseUser = $this->auth->getUser($user->firebase_uid);
                } catch (\Exception $e) {
                    Log::warning('Could not fetch Firebase details for user: ' . $e->getMessage());
                }
            }
            
            $userData = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'firebase_uid' => $user->firebase_uid,
                'street_address' => $user->street_address,
                'city' => $user->city,
                'state' => $user->state,
                'zip_code' => $user->zip_code,
                'country' => $user->country,
                'date_of_birth' => $user->date_of_birth,
                'gender' => $user->gender,
                'profile_picture_url' => $user->profile_picture_url,
                'role' => $user->role ? [
                    'id' => $user->role->id,
                    'name' => $user->role->name,
                    'display_name' => $user->role->display_name
                ] : null,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at
            ];
            
            // Add Firebase-specific data if available
            if ($firebaseUser) {
                $userData['firebase'] = [
                    'displayName' => $firebaseUser->displayName ?? null,
                    'emailVerified' => $firebaseUser->emailVerified ?? false,
                    'disabled' => $firebaseUser->disabled ?? false,
                    'metadata' => [
                        'createdAt' => $firebaseUser->metadata->createdAt ? $firebaseUser->metadata->createdAt->format('Y-m-d H:i:s') : null,
                        'lastLoginAt' => $firebaseUser->metadata->lastLoginAt ? $firebaseUser->metadata->lastLoginAt->format('Y-m-d H:i:s') : null,
                    ]
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $userData
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in UserController@profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
    
    /**
     * Update the authenticated user's profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            
            $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
                'password' => 'sometimes|string|min:8|confirmed',
                'phone_number' => 'sometimes|string|max:20|nullable',
                'street_address' => 'sometimes|string|max:255|nullable',
                'city' => 'sometimes|string|max:100|nullable',
                'state' => 'sometimes|string|max:100|nullable',
                'zip_code' => 'sometimes|string|max:20|nullable',
                'country' => 'sometimes|string|max:100|nullable',
                'date_of_birth' => 'sometimes|date|nullable',
                'gender' => 'sometimes|string|in:male,female,other|nullable',
                'profile_picture_url' => 'sometimes|string|url|nullable',
            ]);
            
            // Update Laravel user
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

            if ($request->has('street_address')) {
                $user->street_address = $request->street_address;
            }

            if ($request->has('city')) {
                $user->city = $request->city;
            }

            if ($request->has('state')) {
                $user->state = $request->state;
            }

            if ($request->has('zip_code')) {
                $user->zip_code = $request->zip_code;
            }

            if ($request->has('country')) {
                $user->country = $request->country;
            }

            if ($request->has('date_of_birth')) {
                $user->date_of_birth = $request->date_of_birth;
            }

            if ($request->has('gender')) {
                $user->gender = $request->gender;
            }

            if ($request->has('profile_picture_url')) {
                $user->profile_picture_url = $request->profile_picture_url;
            }
            
            // Check if profile was incomplete before this update
            $wasProfileIncomplete = false;
            $requiredFields = ['name', 'phone_number', 'street_address', 'city', 'state', 'zip_code', 'country', 'date_of_birth', 'gender'];
            
            // Get the original user data to check previous completion status
            $originalUser = \App\Models\User::find($user->id);
            foreach ($requiredFields as $field) {
                if (empty($originalUser->$field)) {
                    $wasProfileIncomplete = true;
                    break;
                }
            }
            
            $user->save();
            
            // Check if profile is now complete after the update
            $isProfileNowComplete = true;
            foreach ($requiredFields as $field) {
                if (empty($user->$field)) {
                    $isProfileNowComplete = false;
                    break;
                }
            }
            
            $tokensAwarded = 0;
            $tokenMessage = '';
            
            // Award tokens if profile just became complete and Telegram is connected
            if ($wasProfileIncomplete && $isProfileNowComplete && !empty($user->telegram_id)) {
                $user->free_token += 4000;
                $tokensAwarded = 4000;
                $tokenMessage = ' You have been awarded 4000 free tokens for completing your profile with Telegram connected!';
                $user->save(); // Save again to update the token count
            }
            
            // Update Firebase user if connected
            if ($user->firebase_uid) {
                $properties = [];
                
                if ($request->has('name')) {
                    $properties['displayName'] = $request->name;
                }
                
                if ($request->has('email')) {
                    $properties['email'] = $request->email;
                }
                
                if (!empty($properties)) {
                    $this->auth->updateUser($user->firebase_uid, $properties);
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully' . $tokenMessage,
                'data' => $user->load('role'),
                'tokens_awarded' => $tokensAwarded,
                'profile_complete' => $isProfileNowComplete
            ]);
            
        } catch (AuthException $e) {
            Log::error('Firebase Auth error updating profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed',
                'error' => $e->getMessage()
            ], 401);
        } catch (\Exception $e) {
            Log::error('Error in UserController@updateProfile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }

    }

    /**
     * Upload and update user's profile picture
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadProfilePicture(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            $user = $request->user();
            $file = $request->file('profile_picture');
            
            // Generate a unique filename
            $filename = 'profile_pictures/' . $user->id . '/' . Str::random(40) . '.' . $file->getClientOriginalExtension();
            
            // Get Firebase Storage bucket name from config
            $bucketName = config('firebase.storage.bucket', config('firebase.project_id', 'ai-crm-windsurf') . '.firebasestorage.app');
            
            try {
                // Create a storage reference
                $storage = app('firebase.storage');
                $defaultBucket = $storage->getBucket($bucketName); // Directly use the configured bucket name
                
                // Upload file to Firebase Storage
                $object = $defaultBucket->upload(
                    file_get_contents($file->getRealPath()),
                    [
                        'name' => $filename,
                        'predefinedAcl' => 'publicRead'
                    ]
                );
                
                // Get the public URL
                $publicUrl = 'https://storage.googleapis.com/' . $bucketName . '/' . $filename; // Use the $bucketName variable
                
            } catch (\Exception $e) {
                Log::error('Firebase Storage error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to upload file to storage',
                    'error' => $e->getMessage()
                ], 500);
            }
            
            // Update user's profile_picture_url
            $user->profile_picture_url = $publicUrl;
            $user->save();
            
            // If user has Firebase UID, update Firebase profile
            if ($user->firebase_uid) {
                try {
                    $this->auth->updateUser($user->firebase_uid, [
                        'photoUrl' => $publicUrl
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Could not update Firebase photo URL: ' . $e->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Profile picture uploaded successfully',
                'data' => [
                    'profile_picture_url' => $publicUrl
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error uploading profile picture: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload profile picture',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function test(): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::info('Test endpoint called');
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Test endpoint successful',
                'time' => date('Y-m-d H:i:s')
            ], 200, [], JSON_PRETTY_PRINT);
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Test endpoint error: ' . $e->getMessage());
            
            return new JsonResponse([
                'success' => false,
                'message' => 'Test endpoint error',
                'error' => $e->getMessage()
            ], 500, [], JSON_PRETTY_PRINT);

        }
    }
    
    /**
     * Get all admin users (for ticket assignment)
     *
     * @return JsonResponse
     */
    public function getAdmins(): JsonResponse
    {
        try {
            // Get all users with admin or super_admin roles
            $admins = User::with('role')
                ->whereHas('role', function($query) {
                    $query->whereIn('name', ['admin', 'super_admin']);
                })
                ->select(['id', 'name', 'email', 'profile_picture_url', 'role_id'])
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $admins
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in UserController@getAdmins: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching admin users',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
}
