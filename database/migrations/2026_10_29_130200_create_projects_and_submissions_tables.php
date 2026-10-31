<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('language', 10)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('url_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('status', 20)->default('queued'); // queued | processing | paused | completed | cancelled
            $table->json('options')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('url_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('url_batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('normalized_url', 2048);
            $table->char('url_hash', 64);
            $table->string('language', 10)->default('en');
            $table->string('image_mode', 20)->default('extract'); // extract | generate | both
            // pending | processing | generated | failed | cancelled | paused | duplicate
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'url_hash']);
            $table->index(['user_id', 'status']);
            $table->index(['url_batch_id', 'status']);
        });

        Schema::create('extracted_page_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('url_submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('final_url', 2048)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('title', 500)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('og_title', 500)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->string('twitter_image', 2048)->nullable();
            $table->json('images')->nullable();
            $table->json('headings')->nullable();
            $table->mediumText('content')->nullable();
            $table->string('page_language', 20)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_page_data');
        Schema::dropIfExists('url_submissions');
        Schema::dropIfExists('url_batches');
        Schema::dropIfExists('projects');
    }
};
