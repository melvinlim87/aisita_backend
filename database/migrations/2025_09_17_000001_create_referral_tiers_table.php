<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('referral_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');              // Bronze-Silver, Gold, etc.
            $table->integer('min_referrals');    // Minimum referrals needed for this tier
            $table->integer('max_referrals')->nullable(); // Maximum referrals for this tier (null for highest tier)
            $table->integer('referrer_tokens');  // Tokens to award to the referrer per conversion
            $table->integer('referee_tokens');   // Tokens to award to the person being referred
            $table->string('badge');             // Badge name for this tier
            $table->string('subscription_reward')->nullable(); // basic, pro, enterprise
            $table->integer('subscription_months')->default(1); // Number of months for the reward
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_tiers');
    }
};
