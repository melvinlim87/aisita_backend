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
        Schema::create('user_learning_profile', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('skill_level', ['beginner','intermediate','advanced'])->default('beginner');
            $table->json('topics_learned')->nullable();     // ["forex basics","leverage"]
            $table->json('progress')->nullable();           // {"forex basics":100,"pips":40}
            $table->json('next_recommended')->nullable();   // ["pips","margin"]
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_learning_profile');
    }
};
