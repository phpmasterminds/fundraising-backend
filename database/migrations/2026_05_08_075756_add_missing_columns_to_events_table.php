<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'qr_code')) {
                $table->string('qr_code')->nullable()->after('join_code');
            }
            if (!Schema::hasColumn('events', 'ended_at')) {
                $table->timestamp('ended_at')->nullable()->after('started_at');
            }
            if (!Schema::hasColumn('events', 'duration')) {
                $table->string('duration', 5)->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['qr_code', 'ended_at', 'duration']);
        });
    }
};