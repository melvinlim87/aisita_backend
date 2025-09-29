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
     * NOTE: This migration replaces the purchases table from the original schema.
     * The original purchases table had a simpler structure, and this one adds
     * Stripe-specific fields and other enhancements.
     */
    public function up(): void
    {
        // If the table already exists (from the original schema), drop it and recreate it
        if (Schema::hasTable('purchases')) {
            Schema::drop('purchases');
        }
        
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('session_id')->unique()->comment('Stripe session ID');
            $table->string('price_id')->comment('Stripe price ID');
            $table->decimal('amount', 10, 2)->comment('Amount in USD');
            $table->integer('tokens')->comment('Number of tokens purchased');
            $table->string('status')->default('pending');
            $table->string('customer_email')->nullable();
            $table->string('currency')->default('usd');
            $table->string('type')->default('purchase');
            $table->timestamps();
        });
        
        // Mark the migration as run in the migrations table if it's not already there
        $migration = DB::table('migrations')->where('migration', '2025_05_29_000002_create_purchases_table')->first();
        if (!$migration) {
            DB::table('migrations')->insert([
                'migration' => '2025_05_29_000002_create_purchases_table',
                'batch' => DB::table('migrations')->max('batch') + 1
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
