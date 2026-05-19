<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Models\Bid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * GET /donor/events/{event}/payment
     * Schema: group_members → groups(round_id, min_amount, total_amount) → rounds(event_id)
     */
    public function summary(Request $request, $id)
    {
        $event = \App\Models\Event::findOrFail($id);
        $user = $request->user();

        // Include both closed AND open rounds — last round may still be 'open' when payment is triggered
        $closedRounds = Round::where('event_id', $event->id)
            ->whereIn('status', ['closed', 'open'])
            ->orderBy('round_number')
            ->get();

        $roundsDetail = [];
        $totalAmount  = 0;

        foreach ($closedRounds as $round) {
            // Find donor's group for this round
            $group = DB::table('group_members')
                ->join('groups', 'group_members.group_id', '=', 'groups.id')
                ->where('groups.round_id', $round->id)
                ->where('group_members.user_id', $user->id)
                ->select('groups.id as group_id', 'groups.min_amount', 'groups.total_amount')
                ->first();

            if (!$group) continue;

            // Always recalculate from actual bids for this round — don't trust groups.min_amount
            // which can be stale or incorrect if GroupingService stored wrong values
            $memberIds = DB::table('group_members')
                ->where('group_id', $group->group_id)
                ->pluck('user_id');

            $matched = (int) (Bid::where('round_id', $round->id)
                ->whereIn('user_id', $memberIds)
                ->min('amount') ?? 0);

            // If no bids found for group members, fall back to groups.min_amount
            if ($matched === 0 && $group->min_amount !== null) {
                $matched = (int) $group->min_amount;
            }

            $totalAmount += $matched;
            $roundsDetail[] = ['round' => $round->round_number, 'matched' => $matched];
        }

        // Check current payment status
        $paymentStatus = 'unpaid';
        if (Schema::hasColumn('group_members', 'payment_status')) {
            $status = DB::table('group_members')
                ->join('groups', 'group_members.group_id', '=', 'groups.id')
                ->join('rounds', 'groups.round_id', '=', 'rounds.id')
                ->where('rounds.event_id', $event->id)
                ->where('group_members.user_id', $user->id)
                ->value('group_members.payment_status');

            if ($status === 'paid_offline') {
                $paymentStatus = 'paid';
            }
        }

        return response()->json([
            'donor_name'     => $user->name,
            'total_amount'   => $totalAmount,
            'event_name'     => $event->name,
            'charity_name'   => $event->charity_name,
            'charity_link'   => $event->charity_link,
            'reference'      => 'PF-' . now()->format('Y') . '-' . strtoupper(Str::random(8)),
            'date'           => now()->format('j M Y'),
            'rounds_detail'  => $roundsDetail,
            'payment_status' => $paymentStatus,
        ]);
    }

    /**
     * POST /donor/events/{event}/payment/mark-paid
     */
    public function markPaid(Request $request, $id)
    {
        $event = \App\Models\Event::findOrFail($id);
        $user = $request->user();

        // Only update payment_status if column exists (added by migration)
        if (Schema::hasColumn('group_members', 'payment_status')) {
            $groupId = DB::table('group_members')
                ->join('groups', 'group_members.group_id', '=', 'groups.id')
                ->join('rounds', 'groups.round_id',        '=', 'rounds.id')
                ->where('rounds.event_id', $event->id)
                ->where('group_members.user_id', $user->id)
                ->value('group_members.group_id');

            if ($groupId) {
                DB::table('group_members')
                    ->where('group_id', $groupId)
                    ->where('user_id', $user->id)
                    ->update(['payment_status' => 'paid_offline']);
            }
        }

        return response()->json(['success' => true]);
    }
}