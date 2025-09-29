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
        Schema::create('affiliate_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->onDelete('set null');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->onDelete('set null');
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Index for quick lookups
            $table->index(['affiliate_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
        
        // Add sales_count column to users table if it doesn't exist
        if (!Schema::hasColumn('users', 'sales_count')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('sales_count')->default(0)->after('referral_count');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_sales');
        
        // Remove sales_count column from users table
        if (Schema::hasColumn('users', 'sales_count')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sales_count');
            });
        }
    }
};
