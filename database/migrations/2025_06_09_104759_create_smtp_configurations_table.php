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
        Schema::create('smtp_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Default'); // A friendly name for the configuration
            $table->string('driver')->default('smtp'); // Mail driver, e.g., smtp, sendmail
            $table->string('host');
            $table->integer('port');
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // Will be stored encrypted
            $table->string('encryption')->nullable(); // e.g., tls, ssl
            $table->string('from_address');
            $table->string('from_name');
            $table->json('provider_details')->nullable(); // For Mailgun domain, region, etc.
            $table->boolean('is_default')->default(false); // To mark as the active/default configuration
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_configurations');
    }
};
