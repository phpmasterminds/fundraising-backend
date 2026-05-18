<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Waiting period (in seconds) between rounds.
            // e.g. 120 = 2 minutes wait before next round auto-opens.
            // If host manually launches next round, this is skipped.
            $table->unsignedInteger('round_time')->default(0)->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('round_time');
        });
    }
};