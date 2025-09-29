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
     * NOTE: This migration adds a tokens column to the users table if it doesn't already exist.
     * The tokens column is used to track the number of tokens a user has available for use
     * with the referral system and other features.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'tokens')) {
                $table->integer('tokens')->default(0);
            }
        });
        
        // Mark the migration as run in the migrations table if it's not already there
        $migration = DB::table('migrations')->where('migration', '2025_05_29_000003_add_tokens_to_users_table')->first();
        if (!$migration) {
            DB::table('migrations')->insert([
                'migration' => '2025_05_29_000003_add_tokens_to_users_table',
                'batch' => DB::table('migrations')->max('batch') + 1
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'tokens')) {
                $table->dropColumn('tokens');
            }
        });
    }
};
