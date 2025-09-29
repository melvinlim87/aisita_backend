<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTokenTypeColumnsToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Add the new columns
            $table->integer('registration_token')->default(0)->after('subscription_token');
            $table->integer('free_token')->default(0)->after('registration_token');
        });
        
        // Migrate existing data
        \DB::statement('UPDATE users SET registration_token = subscription_token WHERE telegram_id IS NOT NULL OR whatsapp_verified = 1');
        \DB::statement('UPDATE users SET subscription_token = 0 WHERE telegram_id IS NOT NULL OR whatsapp_verified = 1');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // When rolling back, merge token values back
        \DB::statement('UPDATE users SET subscription_token = subscription_token + registration_token + free_token');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['registration_token', 'free_token']);
        });
    }
}
