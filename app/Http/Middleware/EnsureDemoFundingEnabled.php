<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the demo funding endpoint unless DEMO_FUNDING_ENABLED=true.
 *
 * The money in this app is dummy money that can never leave the app,
 * so funding is allowed in every environment, production included.
 * The switch only exists so you can turn funding off quickly
 * (e.g. if someone is abusing it) without changing code.
 *
 * It runs BEFORE validation, so when funding is off the endpoint
 * simply looks like it does not exist (404).
 */
class EnsureDemoFundingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('fintech.demo_funding.enabled'), 404);

        return $next($request);
    }
}