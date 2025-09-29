<?php

namespace App\Http\Controllers;

use App\Http\Controllers\StripeController;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\TokenUsage;
use App\Models\User;
use App\Services\TokenService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $tokenService;
    protected $subscriptionService;

    public function __construct(TokenService $tokenService, SubscriptionService $subscriptionService)
    {
        $this->tokenService = $tokenService;
        $this->subscriptionService = $subscriptionService;
    }

    public function index(Request $request) {
        $startDate = $request->startDate ?? null;
        $endDate = $request->endDate ?? null;
        $stripe = new StripeController($this->tokenService, $this->subscriptionService);
        $subscriptionsResponse = $stripe->getUserWithSubscriptions($startDate, $endDate);
        $revenueResponse = $stripe->getPayments($startDate, $endDate);
        $subscriptions = 0;
        $revenue = 0;
        $revenues = [];
        $revenuesData = [
            ['label' => "Subscription", 'value' => []],
            ['label' => "Purchase Credit", 'value' => []],
        ];
        $subscriptionData = Plan::all()->mapWithKeys(function ($plan, $pk) {
            return [
                $pk => [
                    'label' => $plan->name,
                    'value'  => [],
                ],
            ];
        })->toArray();

        if ($subscriptionsResponse->original['success']) {
            $subscriptions = $subscriptionsResponse->original['data'];

            foreach ($subscriptions as $s) {
                $plan = Plan::where('stripe_price_id', $s->plan['id'])->first();
                if ($plan) {
                    $s->plan_detail = $plan;
                    $s->created_at = date("Y-m-d H:i:s", $s->created);
                    $subscriptionData[$plan->id - 1]['value'][] = $s; // now it works
                }
            }
        }

        if ($revenueResponse->original['success']) {
            $revenues = $revenueResponse->original['data'];
            foreach($revenues as $s) {
                $revenue += ($s->amount / 100);
                $s->created_at = date("Y-m-d H:i:s", $s->created);
                if ($s->invoice != null) {
                    // $invoice = \Stripe\Invoice::retrieve($s->invoice);
                    // if ($invoice->subscription) {
                    //     $sub = \Stripe\Subscription::retrieve($invoice->subscription);
                    //     $s->subscription_status = $sub->status; 
                    // active and canceled
                    // }
                    $revenuesData[0]['value'][] = $s;
                } else {
                    $revenuesData[1]['value'][] = $s;
                }
            }
        }
        
        $data = [
            'token_usages' => ['data' => [], 'value' => []],
            'revenues' => ['data' => $revenuesData, 'value' => number_format($revenue, 2, '.', "")],
            'subscriptions' => ['data' => $subscriptionData, 'value' => count($subscriptions)],
        ];
        if ($startDate && $endDate) {
            $users = User::where('role_id', 1)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->with(['subscription', 'subscription.plan'])
                ->get();
            $referral = User::where('role_id', 1)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('referred_by', '<>', null)
                ->with(['subscription', 'subscription.plan'])
                ->get();
            $data['users']['data'] = [['label' => "User", 'data' => $users], ['label' => "Referred", 'data' => $referral]];
            $data['users']['value'] = count($users);
            $data['token_usages']['data'] = TokenUsage::whereBetween('created_at', [$startDate, $endDate])->selectRaw("UPPER(feature) AS label, COUNT(feature) AS value")->groupBy('feature')->get();

            $data['token_usages']['value'] = TokenUsage::whereBetween('created_at', [$startDate, $endDate])
                ->selectRaw('COALESCE(SUM(total_tokens), 0) AS total_tokens_used')
                ->first()->total_tokens_used;
        } else {
            $users = User::where('role_id', 1)->with(['subscription', 'subscription.plan'])->get();
            $referral = User::where('role_id', 1)
                ->where('referred_by', '<>', null)
                ->with(['subscription', 'subscription.plan'])
                ->get();
            $data['users']['data'] = [['label' => "User", 'data' => $users], ['label' => "Referred", 'data' => $referral]];
            $data['users']['value'] = count($users);
            $data['token_usages']['data'] = TokenUsage::selectRaw("UPPER(feature) AS label, COUNT(feature) AS value")->groupBy('feature')->get();
            $data['token_usages']['value'] = TokenUsage::selectRaw('SUM(total_tokens) AS total_tokens_used')->first()->total_tokens_used;
        }

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);

    }
}
