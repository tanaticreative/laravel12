<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    /**
     * These routes are an API even when the caller forgets to say so.
     *
     * Without this, Laravel's content negotiation treats a client that omitted
     * Accept as a browser: a validation failure redirects (302) instead of
     * answering 422, and a 404 comes back as an HTML error page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
