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
     * @return void
     */
    public function up()
    {
        // Add support_available column to plans table
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('support_available')->default(true)->after('tokens_per_cycle');
        });
        
        // Create free tier plan
        DB::table('plans')->insert([
            'name' => 'Free',
            'description' => 'Basic free tier with limited features and no support',
            'price' => 0.00,
            'tokens_per_cycle' => 100, // Limited tokens for free users
            'support_available' => false, // No support for free tier
            'interval' => 'month',
            'is_active' => true,
            'stripe_price_id' => null, // No Stripe price ID for free tier
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Add support_access column to users table to override plan settings when needed
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('support_access')->default(true)->after('role_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Delete free tier plan
        DB::table('plans')->where('name', 'Free')->delete();
        
        // Remove support_available column from plans table
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('support_available');
        });
        
        // Remove support_access column from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('support_access');
        });
    }
};
