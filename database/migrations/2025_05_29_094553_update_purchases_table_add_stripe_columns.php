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
        Schema::table('purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('purchases', 'session_id')) {
                $table->string('session_id')->nullable()->unique()->comment('Stripe session ID');
            }
            if (!Schema::hasColumn('purchases', 'price_id')) {
                $table->string('price_id')->nullable()->comment('Stripe price ID');
            }
            if (!Schema::hasColumn('purchases', 'amount')) {
                $table->decimal('amount', 10, 2)->default(0)->comment('Amount in USD');
            }
            if (!Schema::hasColumn('purchases', 'tokens')) {
                $table->integer('tokens')->default(0)->comment('Number of tokens purchased');
            }
            if (!Schema::hasColumn('purchases', 'status')) {
                $table->string('status')->default('pending');
            }
            if (!Schema::hasColumn('purchases', 'customer_email')) {
                $table->string('customer_email')->nullable();
            }
            if (!Schema::hasColumn('purchases', 'currency')) {
                $table->string('currency')->default('usd');
            }
            if (!Schema::hasColumn('purchases', 'type')) {
                $table->string('type')->default('purchase');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn([
                'session_id',
                'price_id',
                'amount',
                'tokens',
                'status',
                'customer_email',
                'currency',
                'type'
            ]);
        });
    }
};
