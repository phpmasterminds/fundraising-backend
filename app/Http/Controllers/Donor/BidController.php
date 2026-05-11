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
     * Submits or updates a bid for the currently open round,
     * then assigns the donor to a group sequentially.
     */
    public function store(Request $request, $id)
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:0'],
        ]);

        // Get the currently open round for this event
        $round = Round::where('event_id', $event->id)
            ->where('status', 'open')
            ->first();

        if (!$round) {
            throw ValidationException::withMessages([
                'amount' => 'No round is currently open for this event.',
            ]);
        }

        // One bid per donor per round — update if already placed
        $isNewBid = !Bid::where('round_id', $round->id)
            ->where('user_id', $user->id)
            ->exists();

        $bid = Bid::updateOrCreate(
            [
                'round_id' => $round->id,
                'user_id'  => $user->id,
            ],
            [
                'amount'    => $validated['amount'],
                'pseudonym' => $user->pseudonym ?? $user->name ?? 'Anonymous',
            ]
        );

        // ── Assign to group on first bid submission only ──────────────
        // If donor updates their bid, they stay in the same group.
        // New donor → assign to next available group slot.
        if ($isNewBid) {
            $this->grouping->assignOnBid($round, $user->id, $bid->id);
        } else {
            // Still update min_amount on their existing group
            $this->grouping->updateGroupMin($round, $user->id);
        }

        return response()->json([
            'success'  => true,
            'bid_id'   => $bid->id,
            'round_id' => $round->id,
            'amount'   => (int) $bid->amount,
        ]);
    }

    /**
     * POST /donor/events/{id}/quit
     * Donor opts out of further rounds.
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