<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpireIdleSanctumToken
{
    private const IDLE_MINUTES = 10;

    /**
     * Sanctum tokens have no expiration configured (config/sanctum.php) — they're valid
     * forever until deleted. This enforces a sliding idle timeout instead: any authenticated
     * request resets the clock (Sanctum's Guard already updates last_used_at on every request),
     * so the token only dies after IDLE_MINUTES with no activity, not from a fixed login time.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token) {
            $lastActivity = $token->last_used_at ?? $token->created_at;

            if ($lastActivity->lt(now()->subMinutes(self::IDLE_MINUTES))) {
                $token->delete();

                return response()->json(['message' => 'Session expired due to inactivity.'], 401);
            }
        }

        return $next($request);
    }
}
