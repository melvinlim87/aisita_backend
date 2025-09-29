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
        Schema::table('plans', function (Blueprint $table) {
            $table->float('regular_price')->nullable()->after('price');
            $table->float('discount_percentage')->nullable()->after('regular_price');
            $table->boolean('has_discount')->default(false)->after('discount_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['regular_price', 'discount_percentage', 'has_discount']);
        });
    }
};
