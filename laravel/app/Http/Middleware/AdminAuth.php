<?php

namespace App\Http\Middleware;

use App\Models\AdminPagePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->session()->get('admin_user_id');

        if (!$userId) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            return redirect()->route('admin.login');
        }

        // Page access check for limited role
        $role = $request->session()->get('admin_role', 'limited');
        if ($role === 'limited') {
            $routeName  = $request->route()?->getName();
            $pageKey    = AdminPagePermission::ROUTE_MAP[$routeName] ?? 'unknown';

            if ($pageKey === null) {
                // super-only route
                abort(403, 'Доступ запрещён');
            }

            if ($pageKey !== 'unknown') {
                $allowed = AdminPagePermission::where('page_key', $pageKey)
                    ->where('enabled', true)
                    ->exists();
                if (!$allowed) {
                    abort(403, 'Доступ к этой странице запрещён');
                }
            }
        }

        return $next($request);
    }
}
