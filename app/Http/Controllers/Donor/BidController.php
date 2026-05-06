<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Models\Bid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BidController extends Controller
{
    /**
     * POST /donor/events/{id}/bid
     * Body: { amount: int }
     * Submits or updates a bid for the currently open round.
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

		// ❌ REMOVED — group_members check blocked new donors before any group exists
		// Anyone authenticated can bid on an open round

		// One bid per donor per round — update if already placed
		$bid = Bid::updateOrCreate(
			['round_id' => $round->id, 'user_id' => $user->id],
			['amount'   => $validated['amount'],
			'pseudonym' => $user->pseudonym ?? $user->name ?? 'Anonymous',

			]
		);

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

        // Mark donor as quit in group_members if column exists
        $updated = DB::table('group_members')
            ->join('groups', 'group_members.group_id', '=', 'groups.id')
            ->join('rounds', 'groups.round_id',        '=', 'rounds.id')
            ->where('rounds.event_id', $event->id)
            ->where('group_members.user_id', $user->id)
            ->value('group_members.id');

        if ($updated) {
            DB::table('group_members')
                ->where('id', $updated)
                ->update(['is_quit' => true, 'updated_at' => now()]);
        }

        return response()->json(['success' => true]);
    }
}