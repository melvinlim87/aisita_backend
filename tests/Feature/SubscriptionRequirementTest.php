<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Mockery;
use Tests\TestCase;

class SubscriptionRequirementTest extends TestCase
{
    use RefreshDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the Stripe API calls to prevent actual API calls during tests
        $this->mock(\Stripe\Checkout\Session::class, function ($mock) {
            $mock->shouldReceive('create')->andReturn(
                (object) [
                    'id' => 'test_session_id',
                    'url' => 'https://test.checkout.url'
                ]
            );
        });
    }
    
    /**
     * Test that a Telegram user with full free tokens CANNOT purchase additional tokens without a subscription
     */
    public function test_telegram_user_with_full_free_tokens_cannot_purchase_tokens_without_subscription()
    {
        // Mock the TokenService
        $tokenServiceMock = $this->mock(TokenService::class);
        $tokenServiceMock->shouldReceive('getUserTokens')->andReturn([
            'registration_token' => 4000,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0,
            'total' => 4000
        ]);
        
        // Create a Telegram user with 4000 registration tokens
        $user = User::factory()->create([
            'telegram_id' => 'test_telegram_123',
            'registration_token' => 4000,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        $response = $this->actingAs($user)
                         ->postJson('/api/tokens/purchase', [
                             'package_id' => 'micro'
                         ]);
        
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'You must purchase a subscription before buying additional tokens.',
            'subscription_required' => true
        ]);
    }
    
    /**
     * Test that a Telegram user who has used some of their free tokens cannot 
     * purchase additional tokens without a subscription
     */
    public function test_telegram_user_with_used_free_tokens_cannot_purchase_without_subscription()
    {
        // Mock the TokenService
        $tokenServiceMock = $this->mock(TokenService::class);
        $tokenServiceMock->shouldReceive('getUserTokens')->andReturn([
            'subscription_token' => 3500,
            'addons_token' => 0
        ]);
        
        // Create a Telegram user with 3500 registration tokens (some used)
        $user = User::factory()->create([
            'telegram_id' => 'test_user_123',
            'registration_token' => 3500,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        $response = $this->actingAs($user)
                         ->postJson('/api/tokens/purchase', [
                             'package_id' => 'micro'
                         ]);
        
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'You must purchase a subscription before buying additional tokens.',
            'subscription_required' => true
        ]);
    }
    
    /**
     * Test that a Telegram user with an active subscription can purchase tokens 
     * even if they've used some free tokens
     */
    public function test_telegram_user_with_subscription_can_purchase_tokens()
    {
        // Mock the TokenService
        $tokenServiceMock = $this->mock(TokenService::class);
        $tokenServiceMock->shouldReceive('getUserTokens')->andReturn([
            'subscription_token' => 3500,
            'addons_token' => 0
        ]);
        
        // Create a Telegram user with some used registration tokens
        $user = User::factory()->create([
            'telegram_id' => 'test_user_123',
            'registration_token' => 3500,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        // Create active subscription for the user
        $plan = Plan::factory()->create();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active'
        ]);
        
        // Mock the relationship method
        $user->setRelation('subscription', $subscription);
        
        $response = $this->actingAs($user)
                         ->postJson('/api/tokens/purchase', [
                             'package_id' => 'micro'
                         ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'session_id', 'checkout_url']);
    }
    
    /**
     * Test that a WhatsApp user with used free tokens cannot purchase additional
     * tokens without a subscription
     */
    public function test_whatsapp_user_with_used_free_tokens_cannot_purchase_without_subscription()
    {
        // Mock the TokenService
        $tokenServiceMock = $this->mock(TokenService::class);
        $tokenServiceMock->shouldReceive('getUserTokens')->andReturn([
            'registration_token' => 3000,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0,
            'total' => 3000
        ]);
        
        // Create a WhatsApp user with 3000 registration tokens (some used)
        $user = User::factory()->create([
            'phone_number' => '+1234567890',
            'whatsapp_verified' => true,
            'registration_token' => 3000,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        $response = $this->actingAs($user)
                         ->postJson('/api/tokens/purchase', [
                             'package_id' => 'micro'
                         ]);
        
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'You must purchase a subscription before buying additional tokens.',
            'subscription_required' => true
        ]);
    }
    
    /**
     * Test that a standard user (no Telegram/WhatsApp) can purchase tokens
     * without requiring a subscription
     */
    public function test_standard_user_can_purchase_tokens_without_subscription()
    {
        // Mock the TokenService
        $tokenServiceMock = $this->mock(TokenService::class);
        $tokenServiceMock->shouldReceive('getUserTokens')->andReturn([
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        // Create standard user (no Telegram ID or WhatsApp verification)
        $user = User::factory()->create([
            'registration_token' => 0,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        $response = $this->actingAs($user)
                         ->postJson('/api/tokens/purchase', [
                             'package_id' => 'micro'
                         ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'session_id', 'checkout_url']);
    }
    
    /**
     * Test that a Firebase user can purchase tokens without requiring a subscription
     */
    public function test_firebase_user_can_purchase_tokens_without_subscription()
    {
        // Mock the TokenService
        $tokenServiceMock = $this->mock(TokenService::class);
        $tokenServiceMock->shouldReceive('getUserTokens')->andReturn([
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        // Create Firebase user
        $user = User::factory()->create([
            'firebase_uid' => 'firebase_test_user_123',
            'registration_token' => 0,
            'free_token' => 0,
            'subscription_token' => 0,
            'addons_token' => 0
        ]);
        
        $response = $this->actingAs($user)
                         ->postJson('/api/tokens/purchase', [
                             'package_id' => 'micro'
                         ]);
        
        $response->assertStatus(200);
        $response->assertJsonStructure(['success', 'session_id', 'checkout_url']);
    }
}
