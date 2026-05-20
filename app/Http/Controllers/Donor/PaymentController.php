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
     * Each donor pays their OWN bid per round (not the group minimum).
     */
    public function summary(Request $request, $id)
    {
        $event = \App\Models\Event::findOrFail($id);
        $user  = $request->user();

        // Include both closed AND open rounds — last round may still be 'open' when payment is triggered
        $rounds = Round::where('event_id', $event->id)
            ->whereIn('status', ['closed', 'open'])
            ->orderBy('round_number')
            ->get();

        $roundsDetail = [];
        $totalAmount  = 0;

        foreach ($rounds as $round) {
            // Get THIS donor's own latest bid for this round
            $bid = Bid::where('round_id', $round->id)
                ->where('user_id', $user->id)
                ->orderBy('id', 'desc')  // latest bid if multiple rows exist
                ->first();

            if (!$bid) continue;

            $matched      = (int) $bid->amount;
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
                ->orderBy('rounds.round_number', 'desc')
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
        $user  = $request->user();

        if (Schema::hasColumn('group_members', 'payment_status')) {
            // Get all group_ids for this user across all rounds of this event
            $groupIds = DB::table('group_members')
                ->join('groups', 'group_members.group_id', '=', 'groups.id')
                ->join('rounds', 'groups.round_id', '=', 'rounds.id')
                ->where('rounds.event_id', $event->id)
                ->where('group_members.user_id', $user->id)
                ->pluck('group_members.group_id');

            if ($groupIds->isNotEmpty()) {
                DB::table('group_members')
                    ->whereIn('group_id', $groupIds)
                    ->where('user_id', $user->id)
                    ->update(['payment_status' => 'paid_offline']);
            }
        }

        return response()->json(['success' => true]);
    }
}