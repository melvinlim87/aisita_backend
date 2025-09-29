<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UserToken;
use Laravel\Sanctum\Sanctum;

class SubscriptionPlanChangeTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;
    protected $lowPlan;
    protected $highPlan;
    protected $subscription;

    public function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::factory()->create();

        // Create test plans
        $this->lowPlan = Plan::factory()->create([
            'name' => 'Low Plan',
            'price' => 10.00,
            'tokens_per_cycle' => 1000,
            'stripe_price_id' => 'price_low_test',
            'interval' => 'month'
        ]);
        
        $this->highPlan = Plan::factory()->create([
            'name' => 'High Plan',
            'price' => 30.00,
            'tokens_per_cycle' => 3000,
            'stripe_price_id' => 'price_high_test',
            'interval' => 'month'
        ]);

        // Create subscription for user
        $this->subscription = Subscription::factory()->create([
            'user_id' => $this->user->id,
            'plan_id' => $this->lowPlan->id,
            'stripe_subscription_id' => 'sub_test_' . $this->faker->uuid,
            'status' => 'active',
            'next_billing_date' => now()->addDays(15)
        ]);

        // Set up user tokens
        UserToken::create([
            'user_id' => $this->user->id,
            'subscription_token' => 1000,
            'addons_token' => 500
        ]);

        // Mock the SubscriptionService methods
        $this->mock(\App\Services\SubscriptionService::class, function ($mock) {
            $mock->shouldReceive('changePlan')
                ->andReturn([
                    'type' => 'upgrade',
                    'invoice_url' => 'https://example.com/invoice',
                    'prorated_amount' => 20.00,
                    'effective_date' => now()->addDays(15)->format('Y-m-d H:i:s')
                ]);
                
            $mock->shouldReceive('cancelSubscription')
                ->andReturn([
                    'success' => true,
                    'subscription' => $this->subscription
                ]);
        });

        // Authenticate user
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function user_can_upgrade_subscription_plan()
    {
        $response = $this->postJson('/api/subscriptions/change-plan', [
            'plan_id' => $this->highPlan->id
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message',
                    'invoice_url',
                    'prorated_amount',
                    'details'
                ])
                ->assertJson([
                    'success' => true,
                    'message' => 'Your subscription has been upgraded successfully!'
                ]);
    }

    /** @test */
    public function user_cannot_change_to_same_plan()
    {
        $response = $this->postJson('/api/subscriptions/change-plan', [
            'plan_id' => $this->lowPlan->id
        ]);

        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'You are already subscribed to this plan'
                ]);
    }

    /** @test */
    public function user_cannot_change_to_nonexistent_plan()
    {
        $response = $this->postJson('/api/subscriptions/change-plan', [
            'plan_id' => 999 // Non-existent ID
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['plan_id']);
    }

    /** @test */
    public function user_can_cancel_subscription()
    {
        $response = $this->postJson('/api/subscriptions/cancel', [
            'subscription_id' => $this->subscription->id
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Subscription canceled successfully'
                ]);
    }

    /** @test */
    public function user_cannot_cancel_other_users_subscription()
    {
        // Create another user with subscription
        $anotherUser = User::factory()->create();
        $anotherSubscription = Subscription::factory()->create([
            'user_id' => $anotherUser->id,
            'plan_id' => $this->lowPlan->id
        ]);

        $response = $this->postJson('/api/subscriptions/cancel', [
            'subscription_id' => $anotherSubscription->id
        ]);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthorized to cancel this subscription'
                ]);
    }

    /** @test */
    public function admin_can_cancel_any_subscription()
    {
        // Create admin user
        $adminUser = User::factory()->create(['role' => 'admin']);
        
        // Create another user with subscription
        $anotherUser = User::factory()->create();
        $anotherSubscription = Subscription::factory()->create([
            'user_id' => $anotherUser->id,
            'plan_id' => $this->lowPlan->id
        ]);

        // Act as admin
        Sanctum::actingAs($adminUser);

        $response = $this->postJson('/api/subscriptions/cancel', [
            'subscription_id' => $anotherSubscription->id
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Subscription canceled successfully'
                ]);
    }
}
