<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HostMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->role !== 'host') {
            return response()->json([
                'message' => 'Unauthorized. Host access only.'
            ], 403);
        }

        return $next($request);
    }
}