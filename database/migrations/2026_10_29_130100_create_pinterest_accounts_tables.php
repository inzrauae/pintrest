<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pinterest_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pinterest_user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('profile_image', 2048)->nullable();
            $table->string('account_type')->nullable();
            $table->string('environment', 20)->default('production'); // production | sandbox
            $table->text('access_token');               // encrypted at rest (model cast)
            $table->text('refresh_token')->nullable();  // encrypted at rest (model cast)
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('scopes', 1000)->nullable();
            $table->string('status', 20)->default('active'); // active | expired | revoked | error
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'pinterest_user_id', 'environment']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('pinterest_boards_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pinterest_account_id')->constrained()->cascadeOnDelete();
            $table->string('board_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('privacy', 30)->nullable();
            $table->unsignedInteger('pin_count')->default(0);
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['pinterest_account_id', 'board_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinterest_boards_cache');
        Schema::dropIfExists('pinterest_accounts');
    }
};
