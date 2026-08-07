<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UniformServerErrorResponse
{
    /**
     * Nothing about how the API broke leaves the process.
     *
     * A 5xx here is always a failure nobody modelled — a dead connection, a
     * missing table, a bug. Laravel answers those with the exception's own
     * message, which for a query failure is the SQL, the connection name, the
     * host and the database. That is a map of the infrastructure handed to
     * whoever asked for it. Every one of them leaves as the same bytes
     * instead, in the {error, message} envelope ApiException renders.
     *
     * This runs on the response, not around a try/catch: Illuminate's routing
     * pipeline catches exceptions where they are thrown and turns them into a
     * response, which then travels back out through this middleware. The
     * exception is also reported before that happens, so masking the body
     * costs nothing in the log.
     *
     * Modelled failures are unaffected — ApiException renders 4xx, and a 4xx
     * never reaches the branch below.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < Response::HTTP_INTERNAL_SERVER_ERROR) {
            return $response;
        }

        // Deliberately not gated on APP_DEBUG. Attaching the cause "only in
        // local" would make this guarantee depend on one env var being right on
        // every deploy forever, and the first misconfigured .env would publish
        // the schema, the host and the database name to whoever asked. The
        // cause is not lost — the handler reported it before rendering, so the
        // full exception and stack trace are already in the log, which is a
        // channel customers cannot read.
        $payload = ['error' => 'server_error', 'message' => 'Server Error'];

        // Rewritten in place, for the same reason as UniformNotFoundResponse:
        // a fresh response would drop the X-RateLimit-* headers the pipeline
        // attached on the way out.
        if ($response instanceof JsonResponse) {
            return $response->setData($payload);
        }

        $response->headers->remove('Content-Type');

        return new JsonResponse($payload, $response->getStatusCode(), $response->headers->all());
    }
}
