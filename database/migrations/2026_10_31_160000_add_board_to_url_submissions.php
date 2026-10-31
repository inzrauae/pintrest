<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The board chosen when the URL (or its batch) was submitted; null = the user's default board.
        Schema::table('url_submissions', function (Blueprint $table) {
            $table->foreignId('pinterest_account_id')->nullable()->after('image_mode')->constrained()->nullOnDelete();
            $table->string('board_id')->nullable()->after('pinterest_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('url_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinterest_account_id');
            $table->dropColumn('board_id');
        });
    }
};
