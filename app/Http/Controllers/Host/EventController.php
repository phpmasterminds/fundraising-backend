<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

class EventController extends Controller
{
    // GET /api/host/events?tab=upcoming|finished|unlisted
    public function index(Request $request)
    {
        $tab = $request->query('tab', 'upcoming');

        $query = Event::where('host_id', $request->user()->id)
            ->latest();

        if ($tab === 'upcoming') {
            $query->whereIn('status', ['draft', 'live']);
        } elseif ($tab === 'finished') {
            $query->where('status', 'finished');
        } elseif ($tab === 'unlisted') {
            $query->where('status', 'unlisted');
        }

        $events = $query->get()
            ->map(fn($e) => array_merge($e->toArray(), [
                'total_raised' => $e->total_raised,
                'donors_count' => $e->rounds()
                    ->with('bids')
                    ->get()
                    ->pluck('bids')
                    ->flatten()
                    ->pluck('user_id')
                    ->unique()
                    ->count(),
            ]));

        return response()->json($events);
    }

    // POST /api/host/events
    public function store(Request $request)
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'charity_name'  => 'required|string|max:255',
            'description'   => 'nullable|string',
            'target_amount' => 'required|numeric|min:1',
            'rounds_count'  => 'required|integer|min:1|max:10',
            'group_size'    => 'required|integer|min:2|max:25',
            'started_at'    => 'nullable|string',  // keep as-is, no tz conversion
            'duration'      => 'nullable|string|max:5',
            'round_time'    => 'nullable|integer|min:0',  // ← FIXED: was missing
            'charity_link'  => 'nullable|string',
            'logo'          => 'nullable|image|max:2048',
            'images.*'      => 'nullable|image|max:2048',
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('events/logos', 'public');
        }

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                $imagePaths[] = $img->store('events/images', 'public');
            }
        }

        $joinCode = $this->generateJoinCode();
        $joinUrl  = config('app.frontend_url') . '/join?code=' . $joinCode;

        $qrFilename = 'events/qrcodes/' . $joinCode . '.svg';
        Storage::disk('public')->makeDirectory('events/qrcodes');

        $renderer = new ImageRenderer(new RendererStyle(300), new SvgImageBackEnd());
        $writer   = new Writer($renderer);
        Storage::disk('public')->put($qrFilename, $writer->writeString($joinUrl));

        $event = Event::create([
            'host_id'       => $request->user()->id,
            'name'          => $request->name,
            'charity_name'  => $request->charity_name,
            'description'   => $request->description,
            'target_amount' => $request->target_amount,
            'rounds_count'  => $request->rounds_count,
            'group_size'    => $request->group_size,
            'started_at'    => $request->started_at,
            'duration'      => $request->duration,
            'round_time'    => $request->round_time,  // ← FIXED: was missing
            'charity_link'  => $request->charity_link,
            'logo'          => $logoPath,
            'images'        => !empty($imagePaths) ? $imagePaths : null,
            'join_code'     => $joinCode,
            'qr_code'       => $qrFilename,
            'status'        => 'draft',
        ]);

        return response()->json($event, 201);
    }

    // GET /api/host/events/{id}
    public function show(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->with(['rounds.bids', 'rounds.groups'])
            ->findOrFail($id);

        // Query currentRound fresh from DB with all nested relations
        $currentRound = Round::where('event_id', $event->id)
            ->whereIn('status', ['waiting', 'open'])
            ->orderBy('round_number')
            ->with(['groups.members.bid', 'bids'])
            ->first();

        $completedRounds = $event->rounds->where('status', 'closed')->count();

        // ── Rounds overview ──────────────────────────────────────────
        $roundsOverview = $event->rounds->map(function ($round) {
            $groupRows = $round->groups->map(function ($group) {
                return [
                    'name'         => $group->group_name,
                    'status'       => 'done',
                    'alert'        => false,
                    'detail'       => '£' . number_format($group->min_amount, 0) . ' min · ' . $group->match_ratio,
                    'detail_color' => '#2BA7A0',
                ];
            })->values()->toArray();

            return [
                'id'           => $round->id,
                'round_number' => $round->round_number,
                'status'       => $round->status,
                'raised'       => $round->status === 'closed'
                    ? '£' . number_format($round->bids->sum('amount'), 0)
                    : null,
                'alerts'       => null,
                'groups_done'  => $round->groups->count() . '/' . $round->groups->count(),
                'group_rows'   => $groupRows,
                'opened_at'    => $round->opened_at,
                'closed_at'    => $round->closed_at,
            ];
        })->values();

        // ── Current groups ────────────────────────────────────────────
        $currentGroups = [];

        if ($currentRound) {
            if ($currentRound->groups->count() > 0) {
                // Groups exist — map each to a grid card
                foreach ($currentRound->groups as $group) {
                    // Every GroupMember has bid_id set at grouping time
                    $memberCount = $group->members->count();
                    $groupSize   = $event->group_size;

                    // 'done' = group is full; 'pending' = still filling up
                    $status = ($memberCount >= $groupSize) ? 'done' : 'pending';

                    $members = $group->members->map(function ($member) {
                        // pseudonym stored on GroupMember; amount via bid relationship
                        $pseudonym = $member->pseudonym ?? '—';
                        return [
                            'group_member_id' => $member->id,                          // ← for move API
                            'pseudonym'       => $pseudonym,
                            'initial'         => strtoupper(substr($pseudonym, 0, 1)),
                            'bid_amount'      => $member->bid
                                ? '£' . number_format($member->bid->amount, 0)
                                : null,
                            'is_quit'         => (bool) ($member->is_quit ?? false),
                            'total_committed' => null,
                            'emoji'           => $member->emoji ?? null,               // ← for avatar display
                        ];
                    })->values();

                    $currentGroups[] = [
                        'id'         => $group->id,                                    // ← group DB id for move API
                        'name'       => $group->group_name,
                        'bids'       => $memberCount,
                        'total_bids' => $groupSize,
                        'min'        => $group->min_amount
                            ? '£' . number_format($group->min_amount, 0)
                            : null,
                        'alert'      => false,
                        'status'     => $status,
                        'donors'     => $members,
                    ];
                }
            } elseif ($currentRound->bids->count() > 0) {
                // Bids placed but no groups formed yet — safety fallback placeholder
                $donors = $currentRound->bids->map(function ($bid) {
                    $pseudonym = $bid->pseudonym ?? '—';
                    return [
                        'pseudonym'       => $pseudonym,
                        'initial'         => strtoupper(substr($pseudonym, 0, 1)),
                        'bid_amount'      => $bid->amount
                            ? '£' . number_format($bid->amount, 0)
                            : null,
                        'is_quit'         => false,
                        'total_committed' => null,
                    ];
                })->values();

                $currentGroups = [[
                    'name'       => 'Round ' . $currentRound->round_number . ' Bids',
                    'bids'       => $donors->count(),
                    'total_bids' => $donors->count(),
                    'min'        => null,
                    'alert'      => false,
                    'status'     => 'pending',
                    'donors'     => $donors,
                ]];
            }
        }

        // ── Fallback: if current round has no groups/bids yet, show last closed round's groups ──
        // This covers the waiting period between rounds (round N+1 is open but empty).
        if (empty($currentGroups)) {
            $lastClosedRound = $event->rounds
                ->where('status', 'closed')
                ->sortByDesc('round_number')
                ->first();

            if ($lastClosedRound) {
                // Ensure members + bids loaded for last closed round
                $lastClosedRound->load('groups.members.bid');

                foreach ($lastClosedRound->groups as $group) {
                    $memberCount = $group->members->count();
                    $groupSize   = $event->group_size;

                    $members = $group->members->map(function ($member) {
                        $pseudonym = $member->pseudonym ?? '—';
                        return [
                            'group_member_id' => $member->id,
                            'pseudonym'       => $pseudonym,
                            'initial'         => strtoupper(substr($pseudonym, 0, 1)),
                            'bid_amount'      => $member->bid
                                ? '£' . number_format($member->bid->amount, 0)
                                : null,
                            'is_quit'         => (bool) ($member->is_quit ?? false),
                            'total_committed' => null,
                            'emoji'           => $member->emoji ?? null,
                        ];
                    })->values();

                    $currentGroups[] = [
                        'id'         => $group->id,
                        'name'       => $group->group_name,
                        'bids'       => $memberCount,
                        'total_bids' => $groupSize,
                        'min'        => $group->min_amount
                            ? '£' . number_format($group->min_amount, 0)
                            : null,
                        'alert'      => false,
                        'status'     => 'done',
                        'donors'     => $members,
                    ];
                }
            }
        }

        // ── All donors across event ────────────────────────────────────
        $allDonors = $event->rounds
            ->flatMap(fn($r) => $r->bids)
            ->unique('user_id')
            ->values()
            ->map(fn($bid) => [
                'pseudonym'  => $bid->pseudonym ?? '—',
                'initial'    => strtoupper(substr($bid->pseudonym ?? '?', 0, 1)),
                'bid_amount' => $bid->amount ? '£' . number_format($bid->amount, 0) : null,
                'is_quit'    => false,
            ]);

        return response()->json([
            'id'            => $event->id,
            'name'          => $event->name,
            'charity_name'  => $event->charity_name,
            'description'   => $event->description,
            'target_amount' => $event->target_amount,
            'logo'          => $event->logo,
            'images'        => $event->images,
            'join_code'     => $event->join_code,
            'status'        => $event->status,
            'rounds_count'  => $event->rounds_count,
            'group_size'    => $event->group_size,
            'duration'      => $event->duration,
            'round_time'    => $event->round_time,   // ← included in show response
            'charity_link'  => $event->charity_link,
            'started_at'    => $event->started_at,
            'ended_at'      => $event->ended_at ?? null,
            'total_raised'  => $event->total_raised,
            'donors_count'  => $allDonors->count(),
            'current_round_number' => $currentRound?->round_number ?? null,
            'completed_rounds'     => $completedRounds,
            'current_round_timer'  => $currentRound?->opened_at ? [
                'human'   => $currentRound->opened_at->diffForHumans(),
                'seconds' => (int) $currentRound->opened_at->diffInSeconds(now()),
            ] : null,
            'round_progress'  => $currentRound
                ? $currentRound->groups->sum(fn($g) => $g->members->count())
                  . '/' . ($currentRound->groups->count() * $event->group_size) . ' Complete'
                : null,
            'active_alert'    => null,
            'current_groups'  => $currentGroups,
            'rounds_overview' => $roundsOverview,
            'all_donors'      => $allDonors,
        ]);
    }

    // PUT /api/host/events/{id}
    public function update(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->update($request->only([
            'name', 'charity_name', 'description',
            'target_amount', 'rounds_count', 'group_size',
            'started_at', 'duration', 'charity_link',
            'round_time',  // ← FIXED: was missing from update
        ]));
        return response()->json($event);
    }

    // DELETE /api/host/events/{id}
    public function destroy(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->delete();
        return response()->json(['message' => 'Event deleted']);
    }

    // POST /api/host/events/{id}/start
    public function start(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->update([
            'status'     => 'live',
            'started_at' => $event->started_at ?? now(),
        ]);

        // Pre-create ALL rounds as 'waiting' if not already created
        if ($event->rounds()->count() === 0) {
            $totalRounds = (int) $event->rounds_count;

            for ($i = 1; $i <= $totalRounds; $i++) {
                Round::create([
                    'event_id'     => $event->id,
                    'round_number' => $i,
                    'status'       => 'waiting',
                    'opened_at'    => null,
                    'closed_at'    => null,
                ]);
            }

            // Open Round 1 immediately
            $event->rounds()->where('round_number', 1)->first()->update([
                'status'    => 'open',
                'opened_at' => now(),
            ]);
        }

        return response()->json($event->fresh()->load('rounds'));
    }

    // POST /api/host/events/{id}/end
    public function end(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $event->update(['status' => 'finished', 'ended_at' => now()]);
        return response()->json($event);
    }

    // POST /api/host/events/{id}/unlist
    public function unlist(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);

        if ($event->status === 'finished') {
            return response()->json(['message' => 'Finished events cannot be unlisted.'], 422);
        }

        $event->update(['status' => 'unlisted']);

        return response()->json(['message' => 'Event unlisted successfully.']);
    }

    // GET /api/host/events/{id}/donors
    public function donors(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);
        $donors = $event->rounds()
            ->with('bids.user')
            ->get()
            ->pluck('bids')
            ->flatten()
            ->unique('user_id')
            ->values();
        return response()->json($donors);
    }

    // POST /api/host/events/{eventId}/groups/{fromGroupId}/move-members
    public function moveMembers(Request $request, $eventId, $fromGroupId)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($eventId);

        $request->validate([
            'to_group_id'      => 'required|integer',
            'group_member_ids' => 'required|array|min:1',
            'group_member_ids.*' => 'integer',
        ]);

        $toGroupId       = $request->to_group_id;
        $groupMemberIds  = $request->group_member_ids;

        // Verify both groups belong to this event
        $fromGroup = Group::where('id', $fromGroupId)
            ->where('event_id', $event->id)
            ->firstOrFail();

        $toGroup = Group::where('id', $toGroupId)
            ->where('event_id', $event->id)
            ->firstOrFail();

        // Move each GroupMember to the target group
        $moved = GroupMember::whereIn('id', $groupMemberIds)
            ->where('group_id', $fromGroup->id)
            ->update(['group_id' => $toGroup->id]);

        return response()->json([
            'message'     => 'Members moved successfully.',
            'moved_count' => $moved,
        ]);
    }

    // POST /api/host/events/{id}/next-round
    public function nextRound(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)
            ->with(['rounds.bids'])
            ->findOrFail($id);

        if ($event->status !== 'live') {
            return response()->json(['message' => 'Event is not live'], 422);
        }

        $currentRound = $event->rounds
            ->whereIn('status', ['open', 'waiting'])
            ->sortBy('round_number')
            ->first();

        if (!$currentRound) {
            return response()->json(['message' => 'No active round found'], 422);
        }

        // ── Finalise groups before closing ──────────────────────────
        app(GroupingService::class)->finaliseGroups($currentRound);

        // Close the current round
        $currentRound->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        $nextRoundNumber = $currentRound->round_number + 1;

        // All rounds done → auto-finish event
        if ($nextRoundNumber > $event->rounds_count) {
            $event->update(['status' => 'finished', 'ended_at' => now()]);
            return response()->json([
                'message'      => 'All rounds complete. Event finished.',
                'event_status' => 'finished',
            ]);
        }

        // Open next round
        $nextRound = Round::create([
            'event_id'     => $event->id,
            'round_number' => $nextRoundNumber,
            'status'       => 'open',
            'opened_at'    => now(),
        ]);

        return response()->json([
            'message'      => "Round {$nextRoundNumber} opened.",
            'closed_round' => $currentRound->round_number,
            'opened_round' => $nextRound->round_number,
            'event_status' => 'live',
        ]);
    }

    // POST /api/host/events/{id}/open-round
    public function openRound(Request $request, $id)
    {
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);

        if ($event->status !== 'live') {
            return response()->json(['message' => 'Event is not live'], 422);
        }

        $hasOpenRound = $event->rounds()->whereIn('status', ['open', 'waiting'])->exists();

        if ($hasOpenRound) {
            return response()->json(['message' => 'A round is already open'], 422);
        }

        $lastRound  = $event->rounds()->orderByDesc('round_number')->first();
        $nextNumber = $lastRound ? $lastRound->round_number + 1 : 1;

        if ($nextNumber > $event->rounds_count) {
            return response()->json(['message' => 'All rounds already completed'], 422);
        }

        $round = Round::create([
            'event_id'     => $event->id,
            'round_number' => $nextNumber,
            'status'       => 'open',
            'opened_at'    => now(),
        ]);

        return response()->json([
            'message'      => "Round {$nextNumber} opened manually.",
            'round_number' => $round->round_number,
        ]);
    }

    // POST /api/host/events/{id}/repair-groups
    public function repairGroups(Request $request, $id)
    {
        $grouping = app(\App\Services\GroupingService::class);
        $event = Event::where('host_id', $request->user()->id)->findOrFail($id);

        $round = Round::where('event_id', $event->id)
            ->whereIn('status', ['open', 'waiting'])
            ->orderBy('round_number')
            ->first();

        if (!$round) {
            return response()->json(['message' => 'No open round found.'], 422);
        }

        $grouping->repairRound($round);

        return response()->json(['message' => 'Groups repaired for round ' . $round->round_number]);
    }

    private function generateJoinCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Event::where('join_code', $code)->exists());
        return $code;
    }
}