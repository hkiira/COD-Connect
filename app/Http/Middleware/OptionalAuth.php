<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Attempt to authenticate from the Bearer token without blocking the request.
 *
 * If a valid token is present, Auth::user() will be populated for the rest of
 * the request lifecycle (controllers can call Auth::check() / getAccountUser()).
 * If the token is absent or invalid the request continues as a guest.
 *
 * Apply this middleware to routes that are publicly accessible but should
 * return personalised data (e.g. account-specific pricing) when the caller
 * is authenticated.
 */
class OptionalAuth
{
    public function handle(Request $request, Closure $next, string $guard = 'api'): mixed
    {
        if ($request->bearerToken()) {
            try {
                Auth::shouldUse($guard);

                // Calling user() on the guard triggers Passport's token
                // resolution. On success it hydrates the authenticated user.
                // On failure (expired / missing token) it returns null
                // without throwing, so the request continues as a guest.
                Auth::guard($guard)->user();
            } catch (\Throwable $e) {
                // Swallow any unexpected exceptions so a malformed token
                // never causes a 500 on a public endpoint.
            }
        }

        return $next($request);
    }
}
