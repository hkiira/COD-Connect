<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $accountUser = getAccountUser();

        if (!$accountUser) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($accountUser->hasAnyRole(['admin', 'administrateur', 'super-admin']) || $accountUser->hasAnyRole($roles)) {
            return $next($request);
        }

        return response()->json(['message' => 'Forbidden – insufficient role'], 403);
    }
}
