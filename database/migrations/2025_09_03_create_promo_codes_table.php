<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('description')->nullable();
            $table->enum('type', ['percentage', 'fixed', 'free_month']);
            $table->decimal('value', 10, 2)->default(0);
            $table->integer('max_uses')->default(0); // 0 means unlimited
            $table->integer('used_count')->default(0);
            $table->integer('max_uses_per_user')->default(1);
            $table->unsignedBigInteger('plan_id')->nullable(); // If code is specific to a plan
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('set null');
        });
        
        // Create table for tracking promo code usage by users
        Schema::create('promo_code_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promo_code_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();
            
            $table->foreign('promo_code_id')->references('id')->on('promo_codes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('set null');
            
            $table->unique(['promo_code_id', 'user_id', 'subscription_id'], 'unique_promo_usage');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('promo_code_user');
        Schema::dropIfExists('promo_codes');
    }
};
