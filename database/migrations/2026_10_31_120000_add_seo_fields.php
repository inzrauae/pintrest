<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_pins', function (Blueprint $table) {
            $table->unsignedTinyInteger('seo_score')->nullable()->after('ai_metadata');
            $table->string('focus_keyword', 100)->nullable()->after('seo_score');
            // Pinterest search terms found by keyword research when the Pin was written.
            $table->json('researched_keywords')->nullable()->after('focus_keyword');
            $table->index(['user_id', 'seo_score']);
        });

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('keyword_research')->default(true)->after('image_mode');
            $table->string('trends_region', 20)->default('US')->after('keyword_research');
        });
    }

    public function down(): void
    {
        Schema::table('generated_pins', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'seo_score']);
            $table->dropColumn(['seo_score', 'focus_keyword', 'researched_keywords']);
        });
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn(['keyword_research', 'trends_region']);
        });
    }
};
