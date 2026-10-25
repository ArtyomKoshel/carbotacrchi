<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\DevLoginController;
use App\Models\BrowserLinkToken;
use App\Models\User;
use Tests\Concerns\RefreshesDatabaseOnce;
use Tests\TestCase;

/** The sign-in without Telegram (routes/dev.php): there locally, absent everywhere else. */
final class DevLoginTest extends TestCase
{
    use RefreshesDatabaseOnce;

    public function test_the_routes_do_not_exist_outside_local(): void
    {
        $this->assertFalse($this->app->environment('local'));
        $this->postJson('/api/auth/dev-login')->assertNotFound();
        $this->get('/dev/admin-login')->assertNotFound();
    }

    public function test_it_links_a_browser_token_to_the_local_tester(): void
    {
        $data = app(DevLoginController::class)->miniapp()->getData(true)['data'];

        $token = BrowserLinkToken::findValid($data['token']);
        $this->assertNotNull($token);
        $this->assertTrue($token->isLinked());
        $this->assertSame(DevLoginController::CHAT_ID, $token->chat_id);
        $this->assertSame('Local tester', User::find(DevLoginController::CHAT_ID)?->first_name);
    }

    public function test_a_linked_browser_token_acts_as_its_user(): void
    {
        $data = app(DevLoginController::class)->miniapp()->getData(true)['data'];

        $this->getJson('/api/favorites?browser_token=' . $data['token'] . '&user_id=42')->assertOk();
        $this->getJson('/api/favorites?browser_token=nope&user_id=42')->assertUnauthorized();
    }
}
