<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('admin.token');

        if (empty($token)) {
            abort(503, 'ADMIN_TOKEN is not configured');
        }

        $provided = $request->bearerToken()
            ?? $request->query('token')
            ?? $request->input('token');

        if (!hash_equals($token, (string) $provided)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            return response(
                view('admin.login'),
                401
            );
        }

        return $next($request);
    }
}
