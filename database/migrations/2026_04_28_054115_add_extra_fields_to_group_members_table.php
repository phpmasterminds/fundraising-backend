<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
     public function up(): void
    {
        // group_members: add emoji + payment_status
        Schema::table('group_members', function (Blueprint $table) {
            if (!Schema::hasColumn('group_members', 'emoji')) {
                $table->string('emoji', 10)->nullable()->after('position');
            }
            if (!Schema::hasColumn('group_members', 'payment_status')) {
                $table->enum('payment_status', ['unpaid', 'paid', 'paid_offline'])
                    ->default('unpaid')
                    ->after('emoji');
            }
            // event_id on group_members (for quick lookups without joining groups)
            if (!Schema::hasColumn('group_members', 'event_id')) {
                $table->unsignedBigInteger('event_id')->nullable()->after('id');
                $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            }
			
			// user_id on group_members (for quick lookups without joining groups)
            if (!Schema::hasColumn('group_members', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('event_id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            }
        });
 
        // bids: add event_id if missing
        Schema::table('bids', function (Blueprint $table) {
            if (!Schema::hasColumn('bids', 'event_id')) {
                $table->unsignedBigInteger('event_id')->nullable()->after('id');
                $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            }
        });
 
        // rounds: ensure opened_at and closed_at exist
        Schema::table('rounds', function (Blueprint $table) {
            if (!Schema::hasColumn('rounds', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('rounds', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('opened_at');
            }
        });
    }
 
    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn(['emoji', 'payment_status']);
        });
    }
};
