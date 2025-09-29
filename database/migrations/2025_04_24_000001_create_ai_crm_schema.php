<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Users table (Laravel default, add extra fields if needed)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('telegram_id')->nullable()->unique();
            $table->integer('bonuses_earned')->default(0);
            $table->integer('total_referrals')->default(0);
            $table->integer('total_tokens_used')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        // Chat sessions
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('open');
            $table->string('platform')->nullable();
            $table->string('browser')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        // Chat messages
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained('chat_sessions')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('sender');
            $table->string('status')->default('sent');
            $table->text('text');
            $table->json('metadata')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });

        // Chat message read by (many-to-many: message <-> user)
        Schema::create('chat_message_read_by', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // History
        Schema::create('histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->string('type')->nullable();
            $table->string('model')->nullable();
            $table->text('content')->nullable();
            $table->json('chart_urls')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });

        // Purchases table moved to a separate migration file (2025_05_29_000002_create_purchases_table.php)

        // Referrals table moved to a separate migration file (2025_05_29_113051_create_referrals_table.php)

        // Token usage
        Schema::create('token_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('feature')->nullable();
            $table->string('model')->nullable();
            $table->string('analysis_type')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('tokens_used')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
        });

        // Password reset tokens
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Sessions table
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Email Templates
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->text('html_content');
            $table->string('category', 100)->nullable();
            $table->timestamps();
        });

        // Email Template Tags
        Schema::create('email_template_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained('email_templates')->onDelete('cascade');
            $table->string('tag', 100);
            $table->timestamps();
            $table->unique(['email_template_id', 'tag']);
        });

        // Email Campaigns
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->string('from_email');
            $table->string('from_name');
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->text('html_content');
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed', 'archived'])->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('successful_sends')->default(0);
            $table->integer('failed_sends')->default(0);
            $table->integer('opens_count')->default(0);
            $table->integer('clicks_count')->default(0);
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // User who created the campaign
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('token_usage');
        // Referrals table dropped in its own migration
        // Purchases table dropped in its own migration
        Schema::dropIfExists('histories');
        Schema::dropIfExists('chat_message_read_by');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_template_tags');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('users');
    }
};
