<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * NOTE: This migration is now redundant as all these columns are already created in
     * the 2025_05_29_113051_create_referrals_table.php migration. This file is kept
     * for backward compatibility with existing databases.
     */
    public function up(): void
    {
        // Skip this migration as all columns are already defined in the create_referrals_table migration
        if (Schema::hasTable('referrals')) {
            // Only add columns if they don't exist and if the table exists
            Schema::table('referrals', function (Blueprint $table) {
                if (!Schema::hasColumn('referrals', 'referrer_id')) {
                    $table->unsignedBigInteger('referrer_id')->after('id');
                    $table->foreign('referrer_id')->references('id')->on('users')->onDelete('cascade');
                }
                
                if (!Schema::hasColumn('referrals', 'referred_id')) {
                    $table->unsignedBigInteger('referred_id')->after('referrer_id');
                    $table->foreign('referred_id')->references('id')->on('users')->onDelete('cascade');
                }
                
                if (!Schema::hasColumn('referrals', 'referral_code')) {
                    $table->string('referral_code')->after('referred_id');
                }
                
                if (!Schema::hasColumn('referrals', 'is_converted')) {
                    $table->boolean('is_converted')->default(false)->after('referral_code');
                }
                
                if (!Schema::hasColumn('referrals', 'tokens_awarded')) {
                    $table->integer('tokens_awarded')->default(0)->after('is_converted');
                }
                
                if (!Schema::hasColumn('referrals', 'converted_at')) {
                    $table->timestamp('converted_at')->nullable()->after('tokens_awarded');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            // Drop foreign keys first
            if (Schema::hasColumn('referrals', 'referrer_id')) {
                $table->dropForeign(['referrer_id']);
            }
            
            if (Schema::hasColumn('referrals', 'referred_id')) {
                $table->dropForeign(['referred_id']);
            }
            
            // Drop columns
            $columns = ['referrer_id', 'referred_id', 'referral_code', 'is_converted', 'tokens_awarded', 'converted_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('referrals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
