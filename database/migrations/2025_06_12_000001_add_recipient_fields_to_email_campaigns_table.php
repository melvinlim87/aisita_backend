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
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->boolean('all_contacts')->default(false)->after('user_id');
            $table->json('contact_list_ids')->nullable()->after('all_contacts');
            $table->json('contact_ids')->nullable()->after('contact_list_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn(['all_contacts', 'contact_list_ids', 'contact_ids']);
        });
    }
};
