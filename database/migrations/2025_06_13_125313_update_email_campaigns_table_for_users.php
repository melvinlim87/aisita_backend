<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        // Check if SQLite is being used (mainly for tests)
        if ($connection === 'sqlite') {
            // SQLite compatible approach - create new columns, copy data, drop old ones
            Schema::table('email_campaigns', function (Blueprint $table) {
                // Add new columns
                $table->boolean('all_users')->default(0);
                $table->longText('user_ids')->nullable();
            });
            
            // Copy data from old columns to new ones
            DB::table('email_campaigns')->update([
                'all_users' => DB::raw('all_contacts'),
                'user_ids' => DB::raw('contact_ids')
            ]);
            
            // Drop old columns
            Schema::table('email_campaigns', function (Blueprint $table) {
                $table->dropColumn('all_contacts');
                $table->dropColumn('contact_ids');
                
                // Drop contact_list_ids if it exists
                if (Schema::hasColumn('email_campaigns', 'contact_list_ids')) {
                    $table->dropColumn('contact_list_ids');
                }
            });
        } else {
            // MySQL approach - use CHANGE
            DB::statement('ALTER TABLE email_campaigns CHANGE all_contacts all_users TINYINT(1) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE email_campaigns CHANGE contact_ids user_ids LONGTEXT NULL');
            
            // Drop contact_list_ids column if it exists
            if (Schema::hasColumn('email_campaigns', 'contact_list_ids')) {
                Schema::table('email_campaigns', function (Blueprint $table) {
                    $table->dropColumn('contact_list_ids');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        if ($connection === 'sqlite') {
            // SQLite compatible approach - create new columns, copy data, drop old ones
            Schema::table('email_campaigns', function (Blueprint $table) {
                // Add old columns back
                $table->boolean('all_contacts')->default(0);
                $table->longText('contact_ids')->nullable();
                $table->longText('contact_list_ids')->nullable();
            });
            
            // Copy data from new columns to old ones
            DB::table('email_campaigns')->update([
                'all_contacts' => DB::raw('all_users'),
                'contact_ids' => DB::raw('user_ids')
            ]);
            
            // Drop new columns
            Schema::table('email_campaigns', function (Blueprint $table) {
                $table->dropColumn('all_users');
                $table->dropColumn('user_ids');
            });
        } else {
            // MySQL approach - use CHANGE
            DB::statement('ALTER TABLE email_campaigns CHANGE all_users all_contacts TINYINT(1) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE email_campaigns CHANGE user_ids contact_ids LONGTEXT NULL');
            
            // Add back contact_list_ids column
            Schema::table('email_campaigns', function (Blueprint $table) {
                $table->longText('contact_list_ids')->nullable();
            });
        }
    }
};
