<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Notifications;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\NotificationSetting;
use Database\Factories\BotUserFactory;
use Database\Factories\NotificationRecipientFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** Telegram recipients, types, thresholds and the test message (Telegram faked). */
class NotificationsTest extends AdminTestCase
{
    private const TOKEN = '123456:TEST-TOKEN-SECRET'; // phpunit.xml TELEGRAM_BOT_TOKEN

    public function test_add_recipient_by_chat_id_with_default_types(): void
    {
        $this->signIn();

        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('create')->table(), data: ['chat_id' => '-100123456', 'label' => 'Группа'])
            ->assertHasNoFormErrors();

        $r = NotificationRecipient::where('chat_id', '-100123456')->sole();
        $this->assertTrue($r->enabled);
        $this->assertSame(NotificationRecipient::defaultTypes(), $r->types);
    }

    public function test_add_recipient_from_a_bot_user_uses_their_telegram_id(): void
    {
        $this->signIn();
        $user = BotUserFactory::new()->create(['username' => 'picker']);

        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('create')->table(), data: ['user_id' => $user->id]);

        $r = NotificationRecipient::where('chat_id', (string) $user->id)->sole();
        $this->assertSame('@picker', $r->label);
    }

    public function test_bad_or_duplicate_chat_id_is_refused(): void
    {
        $this->signIn();
        NotificationRecipientFactory::new()->create(['chat_id' => '555']);
        $before = NotificationRecipient::count();

        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('create')->table(), data: ['chat_id' => 'not a chat'])
            ->assertHasFormErrors(['chat_id']);
        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('create')->table(), data: ['chat_id' => '555'])
            ->assertNotified('Получатель 555 уже есть');

        $this->assertSame($before, NotificationRecipient::count());
    }

    public function test_disable_and_choose_types(): void
    {
        $this->signIn();
        $r = NotificationRecipientFactory::new()->create();

        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('edit')->table($r), data: ['enabled' => false, 'types' => ['jobs.failed']]);

        $this->assertFalse($r->fresh()->enabled);
        $this->assertSame(['jobs.failed'], $r->fresh()->types);
        $this->assertFalse($r->fresh()->wants('jobs.failed'), 'a disabled recipient gets nothing');
    }

    public function test_thresholds_save(): void
    {
        $this->signIn();

        Livewire::test(Notifications::class)
            ->set('thresholds.proxy_min_gb', 5)
            ->set('thresholds.no_run_hours', 30)
            ->call('saveThresholds');

        $this->assertSame(5.0, NotificationSetting::thresholds()['proxy_min_gb']);
        $this->assertSame(30.0, NotificationSetting::thresholds()['no_run_hours']);
    }

    public function test_test_message_success(): void
    {
        $this->signIn();
        $r = NotificationRecipientFactory::new()->create(['chat_id' => '777']);
        Http::fake(['telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('test')->table($r))
            ->assertNotified('Отправлено в 777');

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/sendMessage') && $req['chat_id'] === '777');
        $this->assertTrue(NotificationLog::latest('id')->first()->ok);
    }

    public function test_test_message_error_never_shows_the_bot_token(): void
    {
        $this->signIn();
        $r = NotificationRecipientFactory::new()->create(['chat_id' => '777']);
        // a connection error quotes the request URL, which contains the token
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to https://telegram.test/bot' . self::TOKEN . '/sendMessage'));

        $c = Livewire::test(Notifications::class)->callAction(TestAction::make('test')->table($r));

        $c->assertNotified('Не удалось отправить в 777');
        $this->assertStringNotContainsString(self::TOKEN, json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString(self::TOKEN, $c->html());
        $log = NotificationLog::latest('id')->first();
        $this->assertFalse($log->ok);
        $this->assertStringNotContainsString(self::TOKEN, (string) $log->error);
        $this->assertStringContainsString('***', (string) $log->error);
    }

    public function test_telegram_refusal_is_shown_as_is(): void
    {
        $this->signIn();
        $r = NotificationRecipientFactory::new()->create(['chat_id' => '777']);
        Http::fake(['telegram.test/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

        Livewire::test(Notifications::class)
            ->callAction(TestAction::make('test')->table($r))
            ->assertNotified('Не удалось отправить в 777');

        $this->assertStringContainsString('bot was blocked', NotificationLog::latest('id')->first()->error);
    }
}
