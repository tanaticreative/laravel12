<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UniformNotFoundResponse
{
    /**
     * Every 404 on the API leaves as the same bytes.
     *
     * HoldPolicy denies as 404 rather than 403 so that hold ids — sequential
     * integers — cannot be enumerated. That only works if a hold which does
     * not exist and a hold which is not yours are indistinguishable. Laravel's
     * default body names the model and the id ("No query results for model
     * [Hold] 999999") while a denial says "Not Found", which hands the
     * distinction straight back to the caller.
     *
     * Also normalises the envelope to the {error, message} pair that
     * ApiException renders, so clients get one error shape from every path.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND) {
            return $response;
        }

        $payload = ['error' => 'not_found', 'message' => 'Not Found'];

        // Rewritten in place: building a fresh response would discard whatever
        // the pipeline attached on the way out — the X-RateLimit-* headers in
        // particular, which a client throttled while probing ids still needs.
        if ($response instanceof JsonResponse) {
            return $response->setData($payload);
        }

        $response->headers->remove('Content-Type');

        return new JsonResponse($payload, Response::HTTP_NOT_FOUND, $response->headers->all());
    }
}
