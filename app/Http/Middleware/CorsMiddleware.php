<?php

namespace App\Http\Middleware;

use Closure;

/**
 * DEPRECATED — never registered in Kernel.php.
 *
 * CORS is handled globally by \Illuminate\Http\Middleware\HandleCors
 * (registered in Kernel::$middleware) using config/cors.php.
 *
 * Do NOT register this class: its wildcard Access-Control-Allow-Origin
 * would conflict with supports_credentials = true in config/cors.php
 * and break cookie/token flows from the frontend.
 *
 * This file is kept only to avoid breaking any direct references.
 */
class CorsMiddleware
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
