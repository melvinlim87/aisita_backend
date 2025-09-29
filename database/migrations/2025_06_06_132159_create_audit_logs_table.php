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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable(); // User who performed the action (nullable for system events)
            $table->string('event');                          // e.g., 'user_login_success', 'contact_updated'
            $table->string('auditable_type')->nullable();     // Model type, e.g., App\Models\User
            $table->unsignedBigInteger('auditable_id')->nullable(); // ID of the affected model
            $table->text('old_values')->nullable();           // JSON of old data
            $table->text('new_values')->nullable();           // JSON of new data
            $table->string('url')->nullable();                // URL of the request
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();   // Truncated user agent
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
