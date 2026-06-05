<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('auction.internal_token', '');

        if ($token === '' || $request->header('X-Internal-Token') !== $token) {
            return response()->json(['ok' => false, 'error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
