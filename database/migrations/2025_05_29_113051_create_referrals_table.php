<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * NOTE: This migration creates the referrals table for the referral system.
     * It replaces the original referrals table from the initial schema with a more
     * comprehensive structure for tracking referrals between users.
     */
    public function up(): void
    {
        // If the table already exists (from the original schema), drop it and recreate it
        if (Schema::hasTable('referrals')) {
            Schema::drop('referrals');
        }
        
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->string('referral_code');
            $table->boolean('is_converted')->default(false);
            $table->integer('tokens_awarded')->default(0);
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            
            // Ensure a user can only refer another user once
            $table->unique(['referrer_id', 'referred_id']);
        });
        
        // Mark the migration as run in the migrations table if it's not already there
        $migration = DB::table('migrations')->where('migration', '2025_05_29_113051_create_referrals_table')->first();
        if (!$migration) {
            DB::table('migrations')->insert([
                'migration' => '2025_05_29_113051_create_referrals_table',
                'batch' => DB::table('migrations')->max('batch') + 1
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
