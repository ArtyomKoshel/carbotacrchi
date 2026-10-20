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
 * admin_role, admin_username); the panel uses the `admin` guard. A panel
 * sign-in writes the old keys right away (AdminPanelProvider listens to the
 * Login event), so when the two disagree the old login is the newer one.
 */
class ShareLegacyAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard    = Auth::guard('admin');
        $legacyId = (int) $request->session()->get('admin_user_id');

        if ($legacyId && (int) $guard->id() !== $legacyId) {
            if (!$guard->loginUsingId($legacyId)) {
                $guard->logout();
            }
        }

        /** @var AdminUser|null $user */
        $user = $guard->user();
        if ($user && $request->session()->get('admin_role') !== $user->role) {
            self::remember($user);
        }

        return $next($request);
    }

    /** Session keys the old /admin pages read. */
    public static function remember(AdminUser $user): void
    {
        session()->put('admin_user_id', $user->id);
        session()->put('admin_role', $user->role);
        session()->put('admin_username', $user->username);
    }
}
