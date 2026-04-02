<?php

namespace App\Http\Middleware;

use Closure;

class VerifyDomain
{
    public function handle($request, Closure $next)
    {
        if (!in_array($request->getHttpHost(), ['space.codconnect.com', 'localhost:8000', 'localhost:3001', 'localhost:3000', '127.0.0.1:3032', '127.0.0.1:3000', 'mini.codconnect.com', 'space.falekhir.com', 'mini.falekhir.com', 'garbage-reprint-somewhat-imported.trycloudflare.com'])) {
            // Return a response with an error message if the domain is not valid
            return response('Invalid domain', 403);
        }
        return $next($request);
    }
}
