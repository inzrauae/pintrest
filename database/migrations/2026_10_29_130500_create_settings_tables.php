<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('openai'); // openai | gemini | fake
            $table->string('model', 100)->nullable();
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->string('tone', 50)->default('helpful');
            $table->text('extra_instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('image_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->default('none'); // openai | none
            $table->string('model', 100)->nullable();
            $table->string('quality', 20)->default('medium');
            $table->timestamps();
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('language', 10)->default('en');
            $table->string('timezone', 64)->default('UTC');
            $table->string('image_mode', 20)->default('extract');
            $table->unsignedInteger('hourly_processing_limit')->default(30);
            $table->unsignedInteger('daily_processing_limit')->default(200);
            $table->unsignedInteger('daily_publish_limit')->default(20);
            $table->string('publishing_window_start', 5)->nullable(); // HH:MM in user timezone
            $table->string('publishing_window_end', 5)->nullable();
            $table->boolean('auto_publish')->default(false);
            $table->timestamp('auto_publish_acknowledged_at')->nullable();
            $table->unsignedInteger('auto_publish_spacing_minutes')->default(60);
            $table->boolean('automation_paused')->default(false);
            $table->foreignId('default_pinterest_account_id')->nullable()->constrained('pinterest_accounts')->nullOnDelete();
            $table->string('default_board_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('image_provider_settings');
        Schema::dropIfExists('ai_provider_settings');
    }
};
