<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A topic the user wants to rank for on Pinterest, and how the autopilot should handle it.
        Schema::create('seo_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('keywords');                 // target search phrases
            $table->string('source_url', 2048)->nullable(); // website or sitemap to discover pages from
            $table->json('url_filters')->nullable();  // only pages whose URL contains one of these
            $table->foreignId('pinterest_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('board_id')->nullable();
            $table->foreignId('pin_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 10)->default('en');
            $table->string('image_mode', 20)->default('extract');
            $table->boolean('owns_images')->default(false); // user confirmed they own images on the source site
            $table->boolean('auto_discover')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->unsignedSmallInteger('max_new_per_run')->default(10);
            $table->unsignedSmallInteger('frequency_hours')->default(24);
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::table('generated_pins', function (Blueprint $table) {
            $table->foreignId('seo_topic_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->timestamp('analytics_synced_at')->nullable()->after('researched_keywords');
        });

        // Daily Pinterest metrics per published Pin.
        Schema::create('pin_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_pin_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('pin_clicks')->default(0);
            $table->unsignedInteger('outbound_clicks')->default(0);
            $table->timestamps();

            $table->unique(['generated_pin_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pin_analytics');
        Schema::table('generated_pins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seo_topic_id');
            $table->dropColumn('analytics_synced_at');
        });
        Schema::dropIfExists('seo_topics');
    }
};
