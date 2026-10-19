<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Notifications\NotificationState;
use App\Services\Notifications\Notifier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /admin/notifications — Telegram recipients, notification types, thresholds (super only). */
class NotificationsController extends Controller
{
    /** Private chat / group id, or a public @channel. */
    private const CHAT_ID_RULE = 'regex:/^(-?\d{3,20}|@[A-Za-z0-9_]{5,32})$/';

    public function index(NotificationState $state)
    {
        $this->authorizeSuper();

        $recipients = NotificationRecipient::orderBy('id')->get();
        $users = User::whereNotNull('username')
            ->orderByDesc('last_seen')
            ->limit(500)
            ->get(['id', 'username', 'first_name']);

        return view('admin.notifications', [
            'recipients'    => $recipients,
            'users'         => $users,
            'types'         => config('notifications.types'),
            'thresholdDefs' => config('notifications.thresholds'),
            'thresholds'    => NotificationSetting::thresholds(),
            'openEvents'    => NotificationEvent::where('status', 'open')->orderByDesc('opened_at')->get(),
            'log'           => NotificationLog::orderByDesc('id')->limit(40)->get(),
            'lastStatus'    => $this->safe(fn () => (array) $state->get('last_status', [])),
            'dbDown'        => $this->safe(fn () => $state->get('db_down')),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeSuper();

        $data = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:40', self::CHAT_ID_RULE, 'required_without:user_id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:chat_id'],
            'label'   => ['nullable', 'string', 'max:100'],
        ], [
            'chat_id.regex'            => 'Chat id — число (для групп с минусом) или @channel',
            'chat_id.required_without' => 'Укажите chat id или выберите пользователя',
        ]);

        $user   = !empty($data['user_id']) ? User::find($data['user_id']) : null;
        // A private chat with the bot has the same id as the Telegram user.
        $chatId = $user ? (string) $user->id : trim((string) $data['chat_id']);

        if (NotificationRecipient::where('chat_id', $chatId)->exists()) {
            return back()->withErrors(['chat_id' => "Получатель {$chatId} уже есть"]);
        }

        NotificationRecipient::create([
            'chat_id' => $chatId,
            'label'   => ($data['label'] ?? null) ?: ($user?->username ? '@'.$user->username : null),
            'user_id' => $user?->id,
            'enabled' => true,
            'types'         => NotificationRecipient::defaultTypes(),
        ]);

        return back()->with('success', "Получатель {$chatId} добавлен. Отправьте тестовое сообщение: бот может писать только тем, кто нажал /start.");
    }

    public function update(int $id, Request $request)
    {
        $this->authorizeSuper();
        $recipient = NotificationRecipient::findOrFail($id);

        $data = $request->validate([
            'label'   => ['nullable', 'string', 'max:100'],
            'types'         => ['array'],
            'types.*' => [Rule::in(array_keys(config('notifications.types')))],
        ]);

        $recipient->update([
            'label'   => $data['label'] ?? null,
            'enabled' => $request->boolean('enabled'),
            'types'         => array_values($data['types'] ?? []),
        ]);

        return back()->with('success', "Настройки для {$recipient->chat_id} сохранены");
    }

    public function delete(int $id)
    {
        $this->authorizeSuper();
        $recipient = NotificationRecipient::findOrFail($id);
        $recipient->delete();

        return back()->with('success', "Получатель {$recipient->chat_id} удалён");
    }

    public function test(int $id, Notifier $notifier)
    {
        $this->authorizeSuper();
        $recipient = NotificationRecipient::findOrFail($id);

        $text = "🔔 <b>Тестовое уведомление CarBot</b>\nУведомления настроены: "
            .count($recipient->types ?? []).' тип(ов)'.($recipient->enabled ? '' : ', получатель выключен')
            ."\nОтправил: ".e((string) session('admin_username', 'admin'));
        $res = $notifier->deliver((string) $recipient->chat_id, $text, 'test');

        return $res['ok']
            ? back()->with('success', "Тестовое сообщение отправлено в {$recipient->chat_id}")
            : back()->withErrors(['test' => "Не удалось отправить в {$recipient->chat_id}: {$res['error']}"]);
    }

    public function settings(Request $request)
    {
        $this->authorizeSuper();

        $defs  = config('notifications.thresholds');
        $rules = [];
        foreach (array_keys($defs) as $key) {
            $rules["thresholds.{$key}"] = ['required', 'numeric', 'min:0', 'max:100000'];
        }
        $data = $request->validate($rules);

        foreach ($data['thresholds'] as $key => $value) {
            NotificationSetting::updateOrCreate(['key' => $key], ['value' => (string) (float) $value]);
        }

        return back()->with('success', 'Пороги сохранены');
    }

    private function authorizeSuper(): void
    {
        if (session('admin_role') !== 'super') {
            abort(403);
        }
    }

    /** The state store is best-effort on this page: a redis hiccup must not break it. */
    private function safe(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }
}
