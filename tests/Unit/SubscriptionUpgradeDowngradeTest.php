<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\UserToken;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Mockery;

class SubscriptionUpgradeDowngradeTest extends TestCase
{
    use RefreshDatabase;

    protected $subscriptionService;
    protected $user;
    protected $lowPlan;
    protected $mediumPlan;
    protected $highPlan;
    protected $subscription;

    public function setUp(): void
    {
        parent::setUp();
        
        // Create test user
        $this->user = User::factory()->create();
        
        // Create test plans with different prices and tokens
        $this->lowPlan = Plan::factory()->create([
            'name' => 'Low Plan',
            'price' => 10.00,
            'tokens_per_cycle' => 1000,
            'stripe_price_id' => 'price_low_test',
            'interval' => 'month'
        ]);
        
        $this->mediumPlan = Plan::factory()->create([
            'name' => 'Medium Plan',
            'price' => 20.00,
            'tokens_per_cycle' => 2000,
            'stripe_price_id' => 'price_medium_test',
            'interval' => 'month'
        ]);
        
        $this->highPlan = Plan::factory()->create([
            'name' => 'High Plan',
            'price' => 30.00,
            'tokens_per_cycle' => 3000,
            'stripe_price_id' => 'price_high_test',
            'interval' => 'month'
        ]);
        
        // Create a mock for Stripe API calls
        $stripeMock = Mockery::mock('alias:Stripe\Subscription');
        $stripeMock->shouldReceive('retrieve')->andReturn((object)[
            'id' => 'sub_test123',
            'items' => (object)[
                'data' => [(object)[
                    'id' => 'si_test123'
                ]]
            ],
            'metadata' => (object)[]
        ]);
        
        $stripeMock->shouldReceive('update')->andReturn((object)[
            'id' => 'sub_test123',
            'status' => 'active'
        ]);
        
        $invoiceMock = Mockery::mock('alias:Stripe\Invoice');
        $invoiceMock->shouldReceive('create')->andReturn((object)[
            'id' => 'in_test123',
            'hosted_invoice_url' => 'https://test.com/invoice'
        ]);
        
        $invoiceMock->shouldReceive('finalizeInvoice')->andReturn((object)[
            'id' => 'in_test123',
            'hosted_invoice_url' => 'https://test.com/invoice',
            'status' => 'paid'
        ]);
        
        // Create active subscription for user with the low plan
        $this->subscription = Subscription::factory()->create([
            'user_id' => $this->user->id,
            'plan_id' => $this->lowPlan->id,
            'stripe_subscription_id' => 'sub_test123',
            'status' => 'active',
            'next_billing_date' => now()->addDays(15), // 15 days left in the cycle
        ]);
        
        // Initialize user tokens
        UserToken::create([
            'user_id' => $this->user->id,
            'subscription_token' => 1000,
            'addons_token' => 0
        ]);
        
        // Create the subscription service with mocks
        $this->subscriptionService = new SubscriptionService();
    }
    
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_calculates_remaining_subscription_value_correctly()
    {
        // Test with 15 days left in a 30-day cycle
        $result = $this->subscriptionService->calculateRemainingSubscriptionValue($this->subscription);
        
        $this->assertEquals(15, $result['remainingDays']);
        $this->assertEquals(30, $result['totalDays']);
        $this->assertEquals(5.0, $result['remainingValue']); // $10 * 0.5 = $5
    }

    /** @test */
    public function it_upgrades_subscription_plan_immediately()
    {
        // Upgrade from low to medium plan
        $result = $this->subscriptionService->changePlan($this->subscription, $this->mediumPlan);
        
        // Assertions
        $this->assertEquals('upgrade', $result['type']);
        $this->assertArrayHasKey('invoice_url', $result);
        $this->assertArrayHasKey('prorated_amount', $result);
        
        // Verify subscription was updated
        $this->subscription->refresh();
        $this->assertEquals($this->mediumPlan->id, $this->subscription->plan_id);
        
        // Verify tokens were updated
        $userTokens = UserToken::where('user_id', $this->user->id)->first();
        $this->assertEquals(2000, $userTokens->subscription_token); // New tokens from medium plan
    }

    /** @test */
    public function it_schedules_downgrade_for_next_billing_cycle()
    {
        // Downgrade from medium to low plan
        // First upgrade to medium
        $this->subscription->update(['plan_id' => $this->mediumPlan->id]);
        
        // Then downgrade to low
        $result = $this->subscriptionService->changePlan($this->subscription, $this->lowPlan);
        
        // Assertions
        $this->assertEquals('downgrade', $result['type']);
        $this->assertArrayHasKey('effective_date', $result);
        
        // Verify subscription plan hasn't changed yet
        $this->subscription->refresh();
        $this->assertEquals($this->mediumPlan->id, $this->subscription->plan_id);
        
        // Verify metadata has been set with pending downgrade
        $metadata = json_decode($this->subscription->metadata ?? '{}', true);
        $this->assertArrayHasKey('pending_downgrade_plan_id', $metadata);
        $this->assertEquals($this->lowPlan->id, $metadata['pending_downgrade_plan_id']);
    }

    /** @test */
    public function it_processes_pending_downgrades_on_renewal()
    {
        // Setup subscription with pending downgrade
        $this->subscription->update([
            'plan_id' => $this->mediumPlan->id,
            'metadata' => json_encode([
                'pending_downgrade_plan_id' => $this->lowPlan->id
            ])
        ]);
        
        // Process renewal
        $result = $this->subscriptionService->processRenewal($this->subscription);
        
        // Verify downgrade was applied
        $this->subscription->refresh();
        $this->assertEquals($this->lowPlan->id, $this->subscription->plan_id);
        
        // Verify metadata was cleared
        $metadata = json_decode($this->subscription->metadata ?? '{}', true);
        $this->assertArrayNotHasKey('pending_downgrade_plan_id', $metadata);
        
        // Verify tokens were reset to the new plan amount
        $userTokens = UserToken::where('user_id', $this->user->id)->first();
        $this->assertEquals($this->lowPlan->tokens_per_cycle, $userTokens->subscription_token);
    }
}
