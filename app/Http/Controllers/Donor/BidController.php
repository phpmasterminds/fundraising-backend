<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Models\Bid;
use App\Services\GroupingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BidController extends Controller
{
    protected GroupingService $grouping;

    public function __construct(GroupingService $grouping)
    {
        $this->grouping = $grouping;
    }

    /**
     * POST /donor/events/{id}/bid
     * Body: { amount: int }
     *
     * Donors can ONLY bid on currently OPEN rounds.
     * The round lifecycle is controlled by scheduler + host:
     *   - Scheduler opens Round 1 at started_at
     *   - Scheduler closes rounds when duration expires (opened_at + duration)
     *   - Scheduler opens next round after round_time waiting period (closed_at + round_time)
     *   - Host can also open/close rounds manually anytime
     *
     * If no round is open → return error. Donor must wait.
     * If donor has already paid → return error. No further bidding allowed.
     */
    public function store(Request $request, $id)
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        // Block bidding if donor has already marked payment as paid
        $hasPaid = DB::table('group_members')
            ->join('groups', 'group_members.group_id', '=', 'groups.id')
            ->join('rounds', 'groups.round_id', '=', 'rounds.id')
            ->where('rounds.event_id', $event->id)
            ->where('group_members.user_id', $user->id)
            ->where('group_members.payment_status', 'paid_offline')
            ->exists();

        if ($hasPaid) {
            throw ValidationException::withMessages([
                'amount' => 'You have already completed payment for this event.',
            ]);
        }

        $round = Round::where('event_id', $event->id)
            ->where('status', 'open')
            ->first();

        if (!$round) {
            throw ValidationException::withMessages([
                'amount' => 'No round is currently open. Please wait for the next round.',
            ]);
        }

        $isNewBid = !Bid::where('round_id', $round->id)
            ->where('user_id', $user->id)
            ->exists();

        $bid = Bid::updateOrCreate(
            [
                'round_id' => $round->id,
                'user_id'  => $user->id,
            ],
            [
                'event_id'               => $event->id,
                'scheduled_round_number' => $round->round_number,
                'bid_status'             => 'active',
                'amount'                 => $validated['amount'],
                'pseudonym'              => $user->pseudonym ?? $user->name ?? 'Anonymous',
            ]
        );

        if ($isNewBid) {
            $this->grouping->assignOnBid($round, $user->id, $bid->id);
        } else {
            $this->grouping->updateGroupMin($round, $user->id);
        }

        return response()->json([
            'success'       => true,
            'bid_id'        => $bid->id,
            'round_id'      => $round->id,
            'round_number'  => $round->round_number,
            'amount'        => (int) $bid->amount,
            'bid_status'    => 'active',
            'message'       => 'Bid placed for Round ' . $round->round_number . '.',
        ]);
    }

    /**
     * POST /donor/events/{id}/quit
     */
    public function quit(Request $request, $id)
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();

        $memberId = DB::table('group_members')
            ->join('groups', 'group_members.group_id', '=', 'groups.id')
            ->join('rounds', 'groups.round_id', '=', 'rounds.id')
            ->where('rounds.event_id', $event->id)
            ->where('group_members.user_id', $user->id)
            ->value('group_members.id');

        if ($memberId) {
            DB::table('group_members')
                ->where('id', $memberId)
                ->update(['is_quit' => true, 'updated_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}