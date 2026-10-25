<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\BrowserLinkToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Sign-in for local work and UI tests, without Telegram and without a password.
 * Its routes (routes/dev.php) exist only when APP_ENV=local; every other environment answers 404.
 */
class DevLoginController extends Controller
{
    /** The local tester's Telegram id: far above real chat ids' first range, never sent anywhere. */
    public const CHAT_ID = 100000001;

    /**
     * POST /api/auth/dev-login → a browser token already linked to the local tester, the same
     * token the bot links in production, so the mini app and the API take the usual browser path.
     */
    public function miniapp(): JsonResponse
    {
        User::updateOrCreate(['id' => self::CHAT_ID], ['first_name' => 'Local tester', 'username' => 'local_tester']);
        $record = BrowserLinkToken::generate();
        $record->linkTo(self::CHAT_ID, 'Local tester', 'local_tester');

        return response()->json(['ok' => true, 'data' => [
            'token' => $record->token,
            'chat_id' => self::CHAT_ID,
            'first_name' => 'Local tester',
            'username' => 'local_tester',
        ]]);
    }

    /** GET /dev/admin-login → signed in to /admin as the local super admin (no usable password). */
    public function admin(Request $request): RedirectResponse
    {
        $admin = AdminUser::firstOrCreate(['username' => 'local'], ['password' => Str::random(64), 'role' => 'super']);
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
