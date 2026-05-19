<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * GET /api/user
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * POST /api/upload-avatar
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,webp,gif|max:5120',
        ]);

        $user = $request->user();

        // Delete old avatar if exists
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }

        // Store new avatar under storage/app/public/avatars/{user_id}/
        $path = $request->file('avatar')->store(
            "avatars/{$user->id}",
            'public'
        );

        $user->update(['avatar' => $path]);

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * PUT /api/profile
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'pseudonym' => 'sometimes|string|max:255',
            'email'     => 'sometimes|email|unique:users,email,' . $request->user()->id,
            'org'       => 'sometimes|string|max:255',
            'phone'     => 'sometimes|string|max:50',
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json($user);
    }

    /**
     * PUT /api/profile/password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'confirmed'],
        ]);

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        // Prevent reusing the same password
        if (Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'New password must be different from your current password.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * GET /api/profile/stats
     * Returns event count, total raised, and unique donor count for the host.
     */
    public function stats(Request $request): JsonResponse
    {
        $host = $request->user();

        $eventsCount = \App\Models\Event::where('host_id', $host->id)->count();

        $totalRaised = \App\Models\Bid::whereHas('round.event', function ($q) use ($host) {
            $q->where('host_id', $host->id);
        })->sum('amount');

        $donorsCount = \App\Models\Bid::whereHas('round.event', function ($q) use ($host) {
            $q->where('host_id', $host->id);
        })->distinct('user_id')->count('user_id');

        return response()->json([
            'events_count' => $eventsCount,
            'total_raised' => (float) $totalRaised,
            'donors_count' => $donorsCount,
        ]);
    }
}