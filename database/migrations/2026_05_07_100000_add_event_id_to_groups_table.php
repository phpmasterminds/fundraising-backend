<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            // Add event_id after id if it doesn't exist
            if (!Schema::hasColumn('groups', 'event_id')) {
                $table->unsignedBigInteger('event_id')->after('id')->nullable();
                $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};