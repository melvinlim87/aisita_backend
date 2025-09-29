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
        Schema::create('affiliate_milestone_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tier_id')->constrained('sales_milestone_tiers')->onDelete('cascade');
            $table->integer('sales_count');
            $table->timestamp('awarded_at');
            $table->timestamps();
            
            // Ensure a user can only receive each tier award once
            $table->unique(['user_id', 'tier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_milestone_awards');
    }
};
