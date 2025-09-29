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
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('badge_type'); // 'referral', 'usage', etc.
            $table->string('badge_level'); // Bronze-Silver, Gold, Platinum, Elite
            $table->text('description')->nullable();
            $table->timestamp('awarded_at');
            $table->timestamps();
            
            // Ensure a user can only have one badge of each type
            $table->unique(['user_id', 'badge_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
