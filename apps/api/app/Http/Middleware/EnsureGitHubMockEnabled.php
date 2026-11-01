<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGitHubMockEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('services.github.mock_enabled', false), 404);

        return $next($request);
    }
}
