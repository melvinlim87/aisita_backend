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
        Schema::table('users', function (Blueprint $table) {
            // Rename 'tokens' to 'subscription_token'
            $table->renameColumn('tokens', 'subscription_token');
            
            // Add new column for addons tokens
            $table->integer('addons_token')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert the column rename
            $table->renameColumn('subscription_token', 'tokens');
            
            // Drop the added column
            $table->dropColumn('addons_token');
        });
    }
};
