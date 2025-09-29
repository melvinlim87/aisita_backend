<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVerificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->string('uid')->nullable(); // Unique identifier for the verification
            $table->string('email')->nullable(); // Email address to verify
            $table->string('username')->nullable(); // Telegram username to verify
            $table->string('verification_code'); // Code sent to the user
            $table->string('app')->nullable(); // Application name or identifier
            $table->string('type'); // Type of verification (e.g., 'email', 'password-reset')
            $table->timestamp('verified_at')->nullable(); // When the verification was completed
            $table->timestamp('expires_at')->nullable(); // When the verification code expires
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('verifications');
    }
}
