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
        // Skip creating tables if they already exist
        // This is needed because we've already migrated these tables in a previous migration
        if (!Schema::hasTable('email_campaign_users')) {
            // Create pivot table for email campaigns and users
            Schema::create('email_campaign_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('email_campaign_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->timestamps();
                
                // Ensure each user is only added once per campaign
                $table->unique(['email_campaign_id', 'user_id']);
            });
        }

        // We no longer need the contact lists table as we're using users directly
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_campaign_users');
    }
};
