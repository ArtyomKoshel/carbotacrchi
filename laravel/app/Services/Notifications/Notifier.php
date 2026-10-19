<?php

namespace App\Services\Notifications;

use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Models\NotificationRecipient;
use App\Services\TelegramBot;
use Closure;

/**
 * Delivers notifications to subscribed recipients and keeps the dedup ledger
 * (notification_events). In dry-run mode prints instead of sending and writes
 * nothing. While the DB is down, uses the cached recipient snapshot and buffers
 * log rows in NotificationState.
 */
class Notifier
{
    private bool $dryRun = false;
    private bool $dbAvailable = true;

    /** @var list<array{chat_id: string, types: list<string>}>|null */
    private ?array $recipients = null;

    private ?Closure $output = null;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly NotificationState $state,
    ) {}

    public function setDryRun(bool $dryRun): static
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setDbAvailable(bool $available): static
    {
        $this->dbAvailable = $available;
        return $this;
    }

    /** @param list<array{chat_id: string, types: list<string>}> $recipients */
    public function useRecipients(array $recipients): static
    {
        $this->recipients = $recipients;
        return $this;
    }

    public function setOutput(Closure $output): static
    {
        $this->output = $output;
        return $this;
    }

    /** @return list<array{chat_id: string, types: list<string>}> enabled recipients */
    public function recipients(): array
    {
        return $this->recipients ??= NotificationRecipient::where('enabled', true)
            ->orderBy('id')
            ->get()
            ->map(fn (NotificationRecipient $r) => ['chat_id' => (string) $r->chat_id, 'types' => $r->types ?? []])
            ->all();
    }

    /**
     * Send to every enabled recipient subscribed to $type.
     * True when the event counts as announced: delivered to at least one
     * recipient, or nobody is subscribed. False means retry on the next run.
     */
    public function broadcast(string $type, string $text, ?string $eventKey = null): bool
    {
        $targets   = 0;
        $delivered = 0;
        foreach ($this->recipients() as $r) {
            if (!in_array($type, $r['types'], true)) {
                continue;
            }
            $targets++;
            if ($this->deliver($r['chat_id'], $text, $type, $eventKey)['ok']) {
                $delivered++;
            }
        }

        if ($targets === 0) {
            $this->say("  · {$type}: no subscribed recipients");
        }

        return $targets === 0 || $delivered > 0;
    }

    /** @return array{ok: bool, error: ?string} */
    public function deliver(string $chatId, string $text, string $type, ?string $eventKey = null): array
    {
        if ($this->dryRun) {
            $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
            $this->say("  [dry-run] → {$chatId} [{$type}]\n    ".str_replace("\n", "\n    ", $plain));
            return ['ok' => true, 'error' => null];
        }

        $res = $this->bot->isConfigured()
            ? $this->bot->sendMessage($chatId, $text, ['disable_web_page_preview' => true])
            : ['ok' => false, 'description' => 'TELEGRAM_BOT_TOKEN is not set'];

        $ok    = !empty($res['ok']);
        $error = $ok ? null : mb_substr($this->bot->redact((string) ($res['description'] ?? $res['error'] ?? 'unknown error')), 0, 255);

        $this->say($ok ? "  ✓ sent → {$chatId} [{$type}]" : "  ✗ failed → {$chatId} [{$type}]: {$error}");

        $this->log([
            'type'       => $type,
            'event_key'  => $eventKey,
            'chat_id'    => $chatId,
            'ok'         => $ok,
            'error'      => $error,
            'text'       => $text,
            'created_at' => now()->toDateTimeString(),
        ]);

        return ['ok' => $ok, 'error' => $error];
    }

    /**
     * Announce a one-off event (job started / finished) once.
     *
     * @param Closure(): string $text
     * @return bool true if the event is new (announced now or attempted)
     */
    public function once(string $key, string $type, Closure $text): bool
    {
        if (NotificationEvent::where('event_key', $key)->exists()) {
            return false;
        }

        $this->say("event {$key}");
        if ($this->broadcast($type, $text(), $key) && !$this->dryRun) {
            NotificationEvent::create([
                'event_key' => $key, 'type' => $type, 'status' => 'sent', 'last_sent_at' => now(),
            ]);
        }

        return true;
    }

    /** Record an event as already announced, without sending it. */
    public function markSeen(string $key, string $type): void
    {
        if (!$this->dryRun) {
            NotificationEvent::firstOrCreate(['event_key' => $key], ['type' => $type, 'status' => 'sent']);
        }
    }

    /**
     * Incident lifecycle: alert when it opens, remind every $repeatHours while
     * it stays open (0 = never), send the "recovered" text when it closes.
     *
     * @param Closure(): string                  $openText
     * @param Closure(NotificationEvent): string $resolvedText
     */
    public function incident(
        string $key,
        string $type,
        bool $active,
        Closure $openText,
        Closure $resolvedText,
        float $repeatHours,
        array $meta = [],
    ): void {
        $event  = NotificationEvent::where('event_key', $key)->first();
        $isOpen = $event?->status === 'open';

        if ($active && !$isOpen) {
            $this->say("incident {$key}: open");
            if ($this->broadcast($type, $openText(), $key) && !$this->dryRun) {
                NotificationEvent::updateOrCreate(['event_key' => $key], [
                    'type' => $type, 'status' => 'open', 'opened_at' => now(),
                    'last_sent_at' => now(), 'resolved_at' => null, 'meta' => $meta,
                ]);
            }
        } elseif ($active && $repeatHours > 0
            && $event->last_sent_at <= now()->subMinutes((int) round($repeatHours * 60))) {
            $this->say("incident {$key}: reminder");
            if ($this->broadcast($type, "⏰ <i>Всё ещё актуально</i>\n".$openText(), $key) && !$this->dryRun) {
                $event->update(['last_sent_at' => now(), 'meta' => $meta]);
            }
        } elseif (!$active && $isOpen) {
            $this->say("incident {$key}: resolved");
            if ($this->broadcast($type, $resolvedText($event), $key) && !$this->dryRun) {
                $event->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        }
    }

    /** Close open incidents under a key prefix without a message (e.g. the job has ended). */
    public function resolveSilently(string $keyPrefix): void
    {
        if (!$this->dryRun) {
            NotificationEvent::where('event_key', 'like', $keyPrefix.'%')
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_at' => now()]);
        }
    }

    /** Write log rows buffered while the DB was down. */
    public function flushPendingLog(): int
    {
        if ($this->dryRun) {
            return 0;
        }
        $rows = $this->state->pull('pending_log');
        foreach ($rows as $row) {
            NotificationLog::create($row);
        }
        return count($rows);
    }

    private function log(array $row): void
    {
        if ($this->dbAvailable) {
            try {
                NotificationLog::create($row);
                return;
            } catch (\Throwable) {
                // DB went away mid-run: keep the row for later
            }
        }
        $this->state->push('pending_log', $row);
    }

    private function say(string $line): void
    {
        if ($this->output !== null) {
            ($this->output)($line);
        }
    }
}
