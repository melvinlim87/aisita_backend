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
        // To shows award tokens record in credit history 
        Schema::table('purchases', function (Blueprint $table) {
            $table->integer('tokens_awarded')->nullable()->comment('Number of tokens awarded to referral')->after('tokens');
            $table->integer('referrer_id')->nullable()->comment('Referrer user id')->after('tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            //
        });
    }
};
