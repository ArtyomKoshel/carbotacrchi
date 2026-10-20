<?php

namespace App\Http\Middleware\Panel;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * One sign-in for both admins while the old /admin pages still exist.
 *
 * The old pages keep the signed-in admin in session keys (admin_user_id,
 * admin_role, admin_username); the panel uses the `admin` guard. Whichever
 * one signed in, this fills in the other.
 */
class ShareLegacyAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard   = Auth::guard('admin');
        $session = $request->session();

        if (!$guard->check() && ($legacyId = $session->get('admin_user_id'))) {
            $guard->loginUsingId($legacyId);
        }

        /** @var AdminUser|null $user */
        $user = $guard->user();
        if ($user && (int) $session->get('admin_user_id') !== (int) $user->id) {
            $session->put('admin_user_id', $user->id);
            $session->put('admin_username', $user->username);
        }
        if ($user && $session->get('admin_role') !== $user->role) {
            $session->put('admin_role', $user->role);
        }

        return $next($request);
    }
}
