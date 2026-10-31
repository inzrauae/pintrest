<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('text_overlay')->default(false);
            $table->string('overlay_position', 10)->default('bottom'); // top | bottom
            $table->string('background_color', 9)->default('#000000');
            $table->unsignedTinyInteger('background_opacity')->default(60);
            $table->string('text_color', 9)->default('#FFFFFF');
            $table->unsignedSmallInteger('font_size')->default(56);
            $table->string('image_style', 500)->nullable(); // appended to AI image prompts
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('generated_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('url_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pinterest_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pin_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('board_id')->nullable();
            $table->string('title', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->string('destination_url', 2048);
            $table->char('destination_hash', 64);
            $table->string('language', 10)->default('en');
            $table->string('suggested_board')->nullable();
            $table->text('image_selection_reason')->nullable();
            $table->text('image_generation_prompt')->nullable();
            // draft | approved | scheduled | publishing | published | failed
            $table->string('status', 20)->default('draft');
            $table->string('pinterest_pin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('ai_metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'destination_hash']);
            $table->index('pinterest_pin_id');
        });

        Schema::create('pin_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_pin_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // extracted | generated | uploaded
            $table->string('source_url', 2048)->nullable();
            $table->string('original_path')->nullable();
            $table->string('path')->nullable();
            $table->string('mime', 50)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->json('crop')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->boolean('is_selected')->default(false);
            $table->boolean('rights_confirmed')->default(false);
            $table->string('status', 20)->default('ready'); // ready | failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['generated_pin_id', 'is_selected']);
        });

        Schema::create('pin_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_pin_id')->constrained()->cascadeOnDelete();
            $table->string('keyword', 100);
            $table->string('type', 10)->default('keyword'); // keyword | hashtag
            $table->timestamps();

            $table->index(['generated_pin_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_keywords');
        Schema::dropIfExists('pin_images');
        Schema::dropIfExists('generated_pins');
        Schema::dropIfExists('pin_templates');
    }
};
