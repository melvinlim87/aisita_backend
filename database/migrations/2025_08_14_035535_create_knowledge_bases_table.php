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
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('topic')->index();
            $table->string('title');
            $table->enum('skill_level', ['beginner','intermediate','advanced'])->index();
            $table->json('related_keywords')->nullable();
            $table->longText('content');
            $table->timestamps();
            $table->unique(['topic','skill_level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};
