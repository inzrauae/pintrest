<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_pin_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20)->default('manual'); // manual | scheduled | automation
            $table->string('status', 20)->default('queued'); // queued | running | succeeded | failed | cancelled
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('scheduled_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_pin_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            // scheduled | dispatched | published | failed | cancelled
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('publication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('generated_pin_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pinterest_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 10)->default('info'); // info | warning | error
            $table->string('event', 50);
            $table->string('message', 500);           // user-facing summary
            $table->json('details')->nullable();      // developer-facing (raw API errors, never secrets)
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_logs');
        Schema::dropIfExists('scheduled_pins');
        Schema::dropIfExists('publishing_jobs');
    }
};
