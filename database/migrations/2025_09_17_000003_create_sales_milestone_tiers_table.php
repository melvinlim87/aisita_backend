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
        Schema::create('sales_milestone_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');                  // Sales Builder, Starter Affiliate, etc.
            $table->integer('required_sales');       // Number of sales required (10, 20, etc.)
            $table->string('badge');                 // Badge/level name
            $table->string('subscription_reward')->nullable(); // basic, pro, enterprise
            $table->integer('subscription_months')->default(1); // Number of months for the reward
            $table->decimal('cash_bonus', 10, 2)->nullable(); // Cash bonus amount if applicable
            $table->boolean('has_physical_plaque')->default(false); // Whether includes physical plaque
            $table->text('perks')->nullable(); // Additional perks (JSON or comma-separated)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_milestone_tiers');
    }
};
