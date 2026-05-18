<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            // Which round number this bid is pre-submitted for.
            // Round 1 bids = 1, pre-submitted Round 2 bids = 2, etc.
            $table->unsignedTinyInteger('scheduled_round_number')->default(1)->after('round_id');

            // 'pending'  = pre-submitted, waiting for round to open
            // 'active'   = released into the live round (round_id populated)
            $table->enum('bid_status', ['pending', 'active'])->default('active')->after('scheduled_round_number');
        });
    }

    public function down(): void
    {
        Schema::table('bids', function (Blueprint $table) {
            $table->dropColumn(['scheduled_round_number', 'bid_status']);
        });
    }
};