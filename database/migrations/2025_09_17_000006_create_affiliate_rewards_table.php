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
        Schema::create('affiliate_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('reward_type'); // 'subscription', 'cash', 'plaque', etc.
            $table->text('value'); // Can be JSON encoded for complex values or direct amount for cash
            $table->foreignId('plan_id')->nullable()->constrained('plans')->onDelete('set null');
            $table->string('status')->default('pending'); // 'pending', 'awarded', 'fulfilled', 'cancelled'
            $table->text('notes')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            
            // Indexes for faster lookups
            $table->index(['user_id', 'reward_type']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_rewards');
    }
};
