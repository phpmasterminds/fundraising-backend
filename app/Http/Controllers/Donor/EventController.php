<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Round;
use App\Models\Bid;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    // GET /donor/events?tab=upcoming|finished
    public function index(Request $request)
    {
        $user = $request->user();
        $tab  = $request->query('tab', 'upcoming');

        $statuses = $tab === 'upcoming' ? ['live', 'draft'] : ['finished'];

        $events = Event::whereIn('status', $statuses)
            ->orderByRaw("FIELD(status, 'live', 'draft', 'finished')")
            ->orderBy('started_at', 'asc')
            ->get()
            ->map(fn($e) => $this->formatListItem($e, $user->id));

        return response()->json($events);
    }

    // GET /donor/events/{id}
    public function show(Request $request, $id)
    {
        $event  = Event::findOrFail($id);
        $user   = $request->user();
        $member = $this->findMember((int) $event->id, (int) $user->id);

        $images = $event->images;
        if (is_string($images)) $images = json_decode($images, true) ?? [];

        return response()->json([
            'id'           => $event->id,
            'name'         => $event->name,
            'charity_name' => $event->charity_name,
            'description'  => $event->description ?? '',
            'started_at'   => $event->started_at,
            'status'       => $event->status,
            'logo'         => $event->logo,
            'images'       => $images ?? [],
            'charity_link' => $event->charity_link,
            'rounds_count' => (int) $event->rounds_count,
            'group_size'   => (int) $event->group_size,
            'duration'     => $event->duration,
            'round_time'   => (int) $event->round_time,
            'donors_count' => $this->countDonors((int) $event->id),
            'my_pseudonym' => $member?->pseudonym,
            'my_initial'   => $member ? mb_strtoupper(mb_substr($member->pseudonym, 0, 1)) : null,
            'my_emoji'     => $member?->emoji,
            'is_member'    => (bool) $member,
        ]);
    }

    // GET /donor/events/{id}/group
    public function myGroup(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();
        $round = $event->currentRound()->first();

        if (!$round) {
            return response()->json([
                'id'             => null,
                'round_number'   => 1,
                'status'         => 'waiting',
                'seconds_left'   => null,
                'matched_amount' => null,
                'match_ratio'    => null,
                'group_total'    => null,
                'group_size'     => (int) $event->group_size,
                'my_group'       => null,
                'my_bid'         => null,
                'my_cumulative'  => 0,
                'round_bids'     => [],
                'round_time'     => (int) $event->round_time,
                'all_round_bids' => $this->getAllRoundBids($event->id, $user->id),
            ]);
        }

        $data = $this->formatRoundState($round, $user, $event);
        $data['round_time']     = (int) $event->round_time;
        $data['all_round_bids'] = $this->getAllRoundBids($event->id, $user->id);

        return response()->json($data);
    }

    /**
     * GET /donor/events/{id}/round-status
     *
     * Lightweight polling endpoint called every 5 seconds by the donor app.
     * Returns current round state so frontend can detect:
     *   - Host opened next round early (round_status = 'open', current_round advanced)
     *   - Event finished (round_status = 'finished')
     *   - Still waiting between rounds (round_status = 'waiting')
     */
    public function roundStatus(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();

        // Check if donor has already paid
        $paymentStatus = 'unpaid';
        if (\Illuminate\Support\Facades\Schema::hasColumn('group_members', 'payment_status')) {
            $paid = \Illuminate\Support\Facades\DB::table('group_members')
                ->join('groups', 'group_members.group_id', '=', 'groups.id')
                ->join('rounds', 'groups.round_id', '=', 'rounds.id')
                ->where('rounds.event_id', $event->id)
                ->where('group_members.user_id', $user->id)
                ->where('group_members.payment_status', 'paid_offline')
                ->exists();
            if ($paid) $paymentStatus = 'paid';
        }

        // Check for open round first
        $openRound = $event->rounds()->where('status', 'open')->first();

        if ($openRound) {
            $durationSecs = $this->parseDuration($event->duration);
            $secondsLeft  = null;

            if ($openRound->opened_at && $durationSecs > 0) {
                $elapsed     = (int) $openRound->opened_at->diffInSeconds(now());
                $secondsLeft = max(0, $durationSecs - $elapsed);
            }

            return response()->json([
                'event_status'       => $event->status,
                'current_round'      => $openRound->round_number,
                'round_status'       => 'open',
                'seconds_left'       => $secondsLeft,
                'seconds_until_next' => null,
                'payment_status'     => $paymentStatus,
            ]);
        }

        // Check if event is finished
        if ($event->status === 'finished') {
            $lastRound = $event->rounds()->orderByDesc('round_number')->first();
            return response()->json([
                'event_status'       => 'finished',
                'current_round'      => $lastRound?->round_number ?? (int) $event->rounds_count,
                'round_status'       => 'finished',
                'seconds_left'       => null,
                'seconds_until_next' => null,
                'payment_status'     => $paymentStatus,
            ]);
        }

        // No open round — find last closed round and check waiting period
        $lastClosed = $event->rounds()
            ->where('status', 'closed')
            ->orderByDesc('round_number')
            ->first();

        // All rounds closed = finished
        $allClosed = $event->rounds()->whereNotIn('status', ['closed'])->doesntExist();
        if ($allClosed && $lastClosed) {
            $event->update(['status' => 'finished']);
            return response()->json([
                'event_status'       => 'finished',
                'current_round'      => $lastClosed->round_number,
                'round_status'       => 'finished',
                'seconds_left'       => null,
                'seconds_until_next' => null,
                'payment_status'     => $paymentStatus,
            ]);
        }

        // No more waiting rounds — all done
        $hasWaitingRounds = $event->rounds()->where('status', 'waiting')->exists();
        if (!$hasWaitingRounds) {
            // Last round closed, no more rounds — mark finished if not already
            if ($event->status !== 'finished') {
                $event->update(['status' => 'finished']);
            }
            return response()->json([
                'event_status'       => 'finished',
                'current_round'      => $lastClosed?->round_number ?? (int) $event->rounds_count,
                'round_status'       => 'finished',
                'seconds_left'       => null,
                'seconds_until_next' => null,
                'payment_status'     => $paymentStatus,
            ]);
        }

        // Waiting between rounds — has more rounds to go
        $roundTime        = (int) $event->round_time;
        $secondsUntilNext = null;

        if ($lastClosed && $lastClosed->closed_at && $roundTime > 0) {
            $elapsed          = (int) $lastClosed->closed_at->diffInSeconds(now());
            $secondsUntilNext = max(0, $roundTime - $elapsed);
        }

        return response()->json([
            'event_status'       => $event->status,
            'current_round'      => $lastClosed?->round_number ?? 0,
            'round_status'       => 'waiting',
            'seconds_left'       => null,
            'seconds_until_next' => $secondsUntilNext,
            'payment_status'     => $paymentStatus,
        ]);
    }

    // GET /events/join/{code}
    public function joinByCode(string $code): JsonResponse
    {
        $event = Event::where('join_code', $code)
            ->select('id', 'name', 'description', 'logo', 'images', 'started_at', 'status')
            ->firstOrFail();

        $alreadyJoined = false;
        if ($user = auth('sanctum')->user()) {
            $alreadyJoined = \App\Models\GroupMember::where('event_id', $event->id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return response()->json([
            'event'          => $event,
            'already_joined' => $alreadyJoined,
        ]);
    }

    // POST /donor/events/{id}/join
    public function join(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $user  = $request->user();

        $existing = \App\Models\GroupMember::where('event_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($request->filled('pseudonym')) {
                $existing->update(['pseudonym' => $request->pseudonym]);
            }
            return response()->json(['message' => 'Already joined.', 'already_joined' => true]);
        }

        \App\Models\GroupMember::create([
            'event_id'       => $id,
            'group_id'       => null,
            'bid_id'         => null,
            'user_id'        => $user->id,
            'pseudonym'      => $request->filled('pseudonym')
                                    ? $request->pseudonym
                                    : ($user->pseudonym ?? 'Anonymous'),
            'emoji'          => null,
            'payment_status' => 'unpaid',
        ]);

        return response()->json(['success' => true, 'message' => 'Joined successfully.']);
    }

    // ─── Private helpers ─────────────────────────────────────────

    /**
     * Returns all bids (active + pending) a donor has placed for this event.
     * Used to show the donor their upcoming pending bids.
     */
    private function getAllRoundBids(int $eventId, int $userId): array
    {
        return Bid::where('event_id', $eventId)
            ->where('user_id', $userId)
            ->orderBy('scheduled_round_number')
            ->get()
            ->map(fn($b) => [
                'round_number' => (int) $b->scheduled_round_number,
                'amount'       => (int) $b->amount,
                'status'       => $b->bid_status ?? 'active',
            ])
            ->toArray();
    }

    private function findMember(int $eventId, int $userId): ?\App\Models\GroupMember
    {
        return \App\Models\GroupMember::where('event_id', $eventId)
            ->where('user_id', $userId)
            ->first();
    }

    private function countDonors(int $eventId): int
    {
        return \App\Models\GroupMember::where('event_id', $eventId)
            ->distinct('user_id')
            ->count('user_id');
    }

    private function formatListItem(Event $e, int $userId): array
    {
        $images = $e->images;
        if (is_string($images)) $images = json_decode($images, true) ?? [];

        return [
            'id'           => $e->id,
            'name'         => $e->name,
            'charity_name' => $e->charity_name,
            'logo'         => $e->logo,
            'images'       => $images ?? [],
            'status'       => $e->status,
            'started_at'   => $e->started_at,
            'rounds_count' => (int) $e->rounds_count,
            'donors_count' => $this->countDonors((int) $e->id),
            'join_code'    => $e->join_code,
            'is_member'    => (bool) $this->findMember((int) $e->id, $userId),
        ];
    }

    private function formatRoundState(Round $round, $user, Event $event): array
    {
        $myBid   = Bid::where('round_id', $round->id)->where('user_id', $user->id)->first();
        $allBids = Bid::where('round_id', $round->id)->orderBy('amount')->get();

        $secondsLeft = null;
        if ($round->status === 'open' && $round->opened_at) {
            $durationSecs = $this->parseDuration($event->duration);
            if ($durationSecs > 0) {
                $elapsedSecs = (int) $round->opened_at->diffInSeconds(now());
                $secondsLeft = max(0, $durationSecs - $elapsedSecs);
            }
            // If duration not set → null (no timer shown)
        }

        $roundBids     = [];
        $matchedAmount = null;
        $groupTotal    = null;

        if ($allBids->count() > 0) {
            $minAmount = (int) $allBids->min('amount');

            if ($round->status === 'closed') {
                $matchedAmount = $minAmount;
                $groupTotal    = $allBids->count() * $minAmount;
            }

            foreach ($allBids as $bid) {
                $roundBids[] = [
                    'pseudonym'  => $bid->pseudonym ?? 'Donor',
                    'initial'    => mb_strtoupper(mb_substr($bid->pseudonym ?? 'D', 0, 1)),
                    'amount'     => (int) $bid->amount,
                    'is_you'     => $bid->user_id === $user->id,
                    'is_minimum' => (int) $bid->amount === $minAmount,
                ];
            }
        }

        $cumulative = $this->calcCumulative((int) $event->id, (int) $user->id, $round->round_number, $round->status, $round->id);
        $groupSize  = (int) $event->group_size;

        $myGroup = null;

        if ($round->status === 'closed') {
            $myGroup = $this->buildMyGroup($event->id, $user->id, $round->id, $round->id);
        } elseif ($round->status === 'open') {
            $prevRound = Round::where('event_id', $event->id)
                ->where('status', 'closed')
                ->orderByDesc('round_number')
                ->first();

            if ($prevRound) {
                $myGroup = $this->buildMyGroup($event->id, $user->id, $prevRound->id, $round->id);
            } else {
                $myGroup = $this->buildMyGroup($event->id, $user->id, $round->id, $round->id);
                if (!$myGroup) {
                    $myGroup = $this->buildPredictedGroup($event, $round, $user->id);
                }
            }
        }

        return [
            'id'             => $round->id,
            'round_number'   => $round->round_number,
            'status'         => $round->status,
            'seconds_left'   => $secondsLeft,
            'matched_amount' => $matchedAmount,
            'match_ratio'    => '1:' . ($groupSize - 1),
            'group_total'    => $groupTotal,
            'group_size'     => $groupSize,
            'my_group'       => $myGroup,
            'my_bid'         => $myBid ? (int) $myBid->amount : null,
            'my_cumulative'  => $cumulative,
            'round_bids'     => $roundBids,
        ];
    }

    private function buildMyGroup(int $eventId, int $userId, int $groupRoundId, int $bidRoundId): ?array
    {
        $member = \App\Models\GroupMember::where('event_id', $eventId)
            ->where('user_id', $userId)
            ->whereHas('group', fn($q) => $q->where('round_id', $groupRoundId))
            ->with(['group.members'])
            ->first();

        if (!$member || !$member->group) return null;

        $group = $member->group;

        $bidsThisRound = Bid::where('round_id', $bidRoundId)
            ->whereIn('user_id', $group->members->pluck('user_id'))
            ->pluck('user_id')
            ->toArray();

        $members = $group->members->map(function ($m) use ($userId, $bidsThisRound) {
            return [
                'pseudonym'  => $m->pseudonym ?? 'Donor',
                'initial'    => mb_strtoupper(mb_substr($m->pseudonym ?? 'D', 0, 1)),
                'emoji'      => $m->emoji,
                'is_you'     => $m->user_id === $userId,
                'bid_status' => in_array($m->user_id, $bidsThisRound) ? 'submitted' : 'bidding',
            ];
        })->values()->toArray();

        return ['name' => $group->group_name, 'members' => $members];
    }

    private function buildPredictedGroup(Event $event, Round $round, int $userId): ?array
    {
        $groupSize  = (int) $event->group_size;
        $allMembers = \App\Models\GroupMember::where('event_id', $event->id)
            ->orderBy('created_at', 'asc')->get();

        $position = $allMembers->search(fn($m) => $m->user_id === $userId);
        if ($position === false) return null;

        $groupIndex   = (int) floor($position / $groupSize);
        $groupLetter  = chr(65 + $groupIndex);
        $groupMembers = $allMembers->slice($groupIndex * $groupSize, $groupSize)->values();

        $bidsThisRound = Bid::where('round_id', $round->id)
            ->whereIn('user_id', $groupMembers->pluck('user_id'))
            ->pluck('user_id')->toArray();

        $members = $groupMembers->map(function ($m) use ($userId, $bidsThisRound) {
            return [
                'pseudonym'  => $m->pseudonym ?? 'Donor',
                'initial'    => mb_strtoupper(mb_substr($m->pseudonym ?? 'D', 0, 1)),
                'emoji'      => $m->emoji,
                'is_you'     => $m->user_id === $userId,
                'bid_status' => in_array($m->user_id, $bidsThisRound) ? 'submitted' : 'bidding',
            ];
        })->values()->toArray();

        return ['name' => 'Group ' . $groupLetter, 'members' => $members];
    }

    private function parseDuration(?string $duration): int
    {
        if (!$duration) return 0;
        [$hh, $mm] = array_map('intval', explode(':', $duration));
        return ($hh * 60 + $mm) * 60;
    }

    private function calcCumulative(int $eventId, int $userId, int $upToRound, string $currentStatus = 'closed', ?int $currentRoundId = null): int
    {
        // Sum the matched amount (group min) for each CLOSED round the donor participated in
        $closedRounds = Round::where('event_id', $eventId)
            ->where('status', 'closed')
            ->where('round_number', '<=', $upToRound)
            ->pluck('id');

        $total = 0;

        foreach ($closedRounds as $roundId) {
            // Find the donor's group for this round
            $group = \Illuminate\Support\Facades\DB::table('group_members')
                ->join('groups', 'group_members.group_id', '=', 'groups.id')
                ->where('groups.round_id', $roundId)
                ->where('group_members.user_id', $userId)
                ->select('groups.id as group_id', 'groups.min_amount')
                ->first();

            if (!$group) continue;

            // Always recalculate from actual bids — don't trust groups.min_amount
            // which can be stale or incorrect
            $memberIds = \Illuminate\Support\Facades\DB::table('group_members')
                ->where('group_id', $group->group_id)
                ->pluck('user_id');

            $minBid = Bid::where('round_id', $roundId)
                ->whereIn('user_id', $memberIds)
                ->min('amount');

            if ($minBid) {
                $total += (int) $minBid;
            } elseif ($group->min_amount !== null) {
                // Fallback to stored value if no bids found
                $total += (int) $group->min_amount;
            }
        }

        // If current round is still open, add its live min bid as a preview
        if ($currentStatus === 'open' && $currentRoundId) {
            $liveBids = Bid::where('round_id', $currentRoundId)->pluck('amount');
            if ($liveBids->isNotEmpty()) {
                $total += (int) $liveBids->min();
            }
        }

        return $total;
    }
}