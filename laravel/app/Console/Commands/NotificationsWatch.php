<?php

namespace App\Console\Commands;

use App\Models\NotificationEvent;
use App\Models\NotificationSetting;
use App\Models\ParseJob;
use App\Services\LotLifecycle;
use App\Services\Notifications\BalanceChecker;
use App\Services\Notifications\JobReport;
use App\Services\Notifications\NotificationState;
use App\Services\Notifications\Notifier;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram notifications for admins: parse job lifecycle, DB outage, low
 * proxy / AI balance, no recent successful run. Each event is announced once
 * (notification_events ledger); incidents also get a "recovered" message.
 * Scheduled every 5 minutes (routes/console.php).
 */
class NotificationsWatch extends Command
{
    protected $signature = 'notifications:watch
                            {--dry-run : Print the messages instead of sending them; writes nothing}
                            {--only= : Comma-separated checks to run: jobs,proxy,ai,runs (the DB check always runs)}';

    protected $description = 'Send admin Telegram notifications about parse jobs, DB health and balances';

    private const CHECKS = ['jobs', 'proxy', 'ai', 'runs'];

    private Notifier $notifier;
    private NotificationState $state;
    private bool $dryRun;
    /** @var array<string, float> */
    private array $th = [];

    public function handle(Notifier $notifier, NotificationState $state, BalanceChecker $balances): int
    {
        $this->notifier = $notifier;
        $this->state    = $state;
        $this->dryRun   = (bool) $this->option('dry-run');
        $notifier->setDryRun($this->dryRun)->setOutput(fn (string $line) => $this->line($line));

        $checks = $this->option('only')
            ? array_values(array_intersect(self::CHECKS, array_map('trim', explode(',', (string) $this->option('only')))))
            : self::CHECKS;

        $lock = $this->dryRun ? null : $state->lock('watch', 600);
        if ($lock !== null && !$lock->get()) {
            $this->warn('Previous notifications:watch run is still active, skipping.');
            return self::SUCCESS;
        }

        try {
            if ($this->dryRun) {
                $this->info('DRY RUN: nothing will be sent or written.');
            }
            if (!$this->checkDatabase()) {
                return self::FAILURE;
            }
            if (!Schema::hasTable('notification_recipients')) {
                $this->warn('Notification tables are missing; run migrations first.');
                return self::FAILURE;
            }

            $this->th = NotificationSetting::thresholds();
            if (!$this->dryRun) {
                $state->put('recipients', $notifier->recipients());
                $state->put('thresholds', $this->th);
            }

            $status = (array) $state->get('last_status', []);
            $status['db'] = ['ok' => true, 'at' => now()->toIso8601String()];

            if (in_array('jobs', $checks, true)) {
                $this->checkJobs();
            }

            $balanceDue = $this->dryRun
                || (int) $state->get('balance_checked_at', 0) <= now()->subMinutes(config('notifications.balance_check_minutes'))->getTimestamp();
            if ($balanceDue && in_array('proxy', $checks, true)) {
                $status['proxy'] = $this->checkProxy($balances->proxy());
            }
            if ($balanceDue && in_array('ai', $checks, true)) {
                $status['ai'] = $this->checkAi($balances->ai());
            }
            if ($balanceDue && !$this->dryRun) {
                $state->put('balance_checked_at', now()->getTimestamp());
            }

            if (in_array('runs', $checks, true)) {
                $this->checkRecentRun();
            }

            if (!$this->dryRun) {
                $state->put('last_status', $status);
            }
            return self::SUCCESS;
        } finally {
            $lock?->release();
        }
    }

    // ── DB ──────────────────────────────────────────────────────────────

    /** False when the DB is unreachable (after alerting from the cached recipient list). */
    private function checkDatabase(): bool
    {
        $error = $this->probeDatabase();
        $down  = $this->state->get('db_down');

        if ($error !== null) {
            $this->error("Database unavailable: {$error}");
            $this->notifier->setDbAvailable(false)->useRecipients((array) $this->state->get('recipients', []));
            $thresholds = (array) $this->state->get('thresholds', []);
            $repeat     = (float) ($thresholds['repeat_hours'] ?? config('notifications.thresholds.repeat_hours.default'));

            if (!$down) {
                $sent = $this->notifier->broadcast('infra.db_down', "🔴 <b>БД недоступна</b>\n<code>".e($error)."</code>\nПарсер и бот не могут работать до восстановления.", 'infra:db_down');
                if ($sent && !$this->dryRun) {
                    $this->state->put('db_down', ['since' => now()->getTimestamp(), 'last_sent' => now()->getTimestamp(), 'error' => $error]);
                }
            } elseif ($repeat > 0 && $down['last_sent'] <= now()->subMinutes((int) round($repeat * 60))->getTimestamp()) {
                $for  = JobReport::duration(now()->getTimestamp() - $down['since']);
                $sent = $this->notifier->broadcast('infra.db_down', "🔴 <b>БД всё ещё недоступна</b> — {$for}\n<code>".e($error).'</code>', 'infra:db_down');
                if ($sent && !$this->dryRun) {
                    $this->state->put('db_down', ['last_sent' => now()->getTimestamp()] + $down);
                }
            }

            if (!$this->dryRun) {
                $this->state->put('last_status', ['db' => ['ok' => false, 'error' => $error, 'at' => now()->toIso8601String()]]
                    + (array) $this->state->get('last_status', []));
            }
            return false;
        }

        if ($down) {
            $for = JobReport::duration(now()->getTimestamp() - $down['since']);
            if ($this->notifier->broadcast('infra.db_down', "✅ <b>БД снова доступна</b>\nПростой: ~{$for}", 'infra:db_down') && !$this->dryRun) {
                $this->state->forget('db_down');
            }
        }
        if (($n = $this->notifier->flushPendingLog()) > 0) {
            $this->line("Flushed {$n} buffered log row(s).");
        }

        return true;
    }

    /** Null when the DB answers, else a short error text without credentials. */
    private function probeDatabase(): ?string
    {
        $name = config('database.default');
        config(["database.connections.{$name}.options" =>
            (array) config("database.connections.{$name}.options", []) + [\PDO::ATTR_TIMEOUT => 5]]);
        DB::purge($name);

        try {
            DB::connection($name)->select('select 1');
            return null;
        } catch (\Throwable $e) {
            $msg = ($e->getPrevious() ?? $e)->getMessage();
            // Laravel appends "(Connection: …, Host: …, SQL: …)"; drop it.
            $msg = preg_replace('/\s*\(Connection:.*$/s', '', $msg);
            return mb_strimwidth(trim($msg), 0, 200, '…');
        }
    }

    // ── Parse jobs ──────────────────────────────────────────────────────

    private function checkJobs(): void
    {
        $repeat = $this->th['repeat_hours'];
        // First run ever: don't replay finished jobs as fresh news.
        $bootstrap = !NotificationEvent::where('type', 'like', 'jobs.%')->exists();

        $jobs = ParseJob::query()
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'reparse'))
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereIn('status', ['running', 'interrupted'])->where('updated_at', '>=', now()->subDays(2)))
                ->orWhere('updated_at', '>=', now()->subHours(config('notifications.job_window_hours'))))
            ->orderBy('id')
            ->get();

        foreach ($jobs as $job) {
            $k = "job:{$job->id}";

            switch ($job->status) {
                case 'running':
                    $isNew = $this->notifier->once("{$k}:started", 'jobs.started', fn () => JobReport::started($job));
                    $this->notifier->incident("{$k}:interrupted", 'jobs.failed', false,
                        fn () => '', fn () => JobReport::resumed($job), $repeat);

                    $idle    = (int) $job->updated_at->diffInMinutes(now(), true);
                    $stalled = $idle >= $this->th['stall_minutes'];
                    $this->notifier->incident("{$k}:stalled", 'jobs.failed', $stalled,
                        fn () => JobReport::stalled($job, $idle), fn ($ev) => JobReport::unstalled($job, $ev), $repeat);

                    if (!$isNew && !$stalled) {
                        $this->maybeProgress($job);
                    }
                    break;

                case 'interrupted':
                    $this->notifier->resolveSilently("{$k}:stalled");
                    $this->notifier->incident("{$k}:interrupted", 'jobs.failed', true,
                        fn () => JobReport::interrupted($job), fn () => JobReport::resumed($job), $repeat);
                    break;

                case 'done':
                case 'error':
                case 'cancelled':
                    $this->notifier->resolveSilently("{$k}:");
                    [$suffix, $type, $text] = match ($job->status) {
                        'done'      => ['finished', 'jobs.finished', fn () => JobReport::finished($job)],
                        'error'     => ['failed', 'jobs.failed', fn () => JobReport::failed($job)],
                        'cancelled' => ['cancelled', 'jobs.failed', fn () => JobReport::cancelled($job)],
                    };
                    $bootstrap
                        ? $this->notifier->markSeen("{$k}:{$suffix}", $type)
                        : $this->notifier->once("{$k}:{$suffix}", $type, $text);
                    break;
            }
        }
    }

    /** Progress every progress_step_pct of lots, and at least every progress_every_hours. */
    private function maybeProgress(ParseJob $job): void
    {
        $key   = "job:{$job->id}:progress";
        $stats = JobReport::stats($job);
        $step  = max(1.0, $this->th['progress_step_pct']);
        $bucket = $stats['pct'] !== null ? (int) floor($stats['pct'] / $step) : 0;

        $event      = NotificationEvent::where('event_key', $key)->first();
        $lastBucket = (int) ($event?->meta['bucket'] ?? 0);
        $lastAt     = $event?->last_sent_at
            ?? NotificationEvent::where('event_key', "job:{$job->id}:started")->value('last_sent_at')
            ?? $job->created_at;
        $lastAt = Carbon::parse($lastAt);

        $byStep = $bucket > $lastBucket && $stats['pct'] < 100;
        $every  = $this->th['progress_every_hours'];
        $byTime = $every > 0 && $lastAt->lte(now()->subMinutes((int) round($every * 60)));
        if (!$byStep && !$byTime) {
            return;
        }

        $this->line("event {$key} (".($byStep ? "step {$bucket}" : 'time').')');
        if ($this->notifier->broadcast('jobs.progress', JobReport::progress($job), $key) && !$this->dryRun) {
            NotificationEvent::updateOrCreate(['event_key' => $key], [
                'type' => 'jobs.progress', 'status' => 'sent', 'last_sent_at' => now(),
                'meta' => ['bucket' => max($bucket, $lastBucket)],
            ]);
        }
    }

    // ── Balances ────────────────────────────────────────────────────────

    private function checkProxy(array $res): array
    {
        $repeat = $this->th['repeat_hours'];
        if (isset($res['skipped'])) {
            $this->line("proxy: skipped — {$res['skipped']}");
            return ['skipped' => $res['skipped'], 'at' => now()->toIso8601String()];
        }

        $this->apiFailureIncident('proxy', 'balance.proxy_low', 'FloppyData', $res);
        if (!$res['ok']) {
            $this->warn("proxy: check failed — {$res['error']}");
            return ['ok' => false, 'error' => $res['error'], 'at' => now()->toIso8601String()];
        }

        $gb       = (float) $res['gb'];
        $expires  = $res['expires_at'];
        $minGb    = $this->th['proxy_min_gb'];
        $daysLeft = $expires ? now()->diffInHours($expires, false) / 24 : null;
        $gbText   = number_format($gb, 2, '.', '');
        $expText  = $expires ? $expires->format('d.m.Y') : null;
        $this->line("proxy: {$gbText} GB".($expText ? ", expires {$expText}" : '')." via {$res['endpoint']}");

        $this->notifier->incident('balance:proxy:low', 'balance.proxy_low', $gb < $minGb,
            fn () => "🟠 <b>Прокси FloppyData: осталось {$gbText} ГБ</b> (порог {$minGb} ГБ)\nБез трафика парсер не сможет ходить в Encar — пополните баланс.",
            fn () => "✅ <b>Прокси FloppyData пополнен</b>: {$gbText} ГБ",
            $repeat);

        $soon = $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= $this->th['proxy_expiry_days'];
        $this->notifier->incident('balance:proxy:expiring', 'balance.proxy_low', $soon,
            fn () => "🟠 <b>Подписка FloppyData истекает {$expText}</b> (через ".JobReport::duration($daysLeft * 86400).")\nОстаток: {$gbText} ГБ",
            fn () => $expires && $expires->isFuture()
                ? "✅ <b>Подписка FloppyData продлена</b> до {$expText}"
                : "ℹ️ Подписка FloppyData истекла. Остаток: {$gbText} ГБ",
            $repeat);

        return ['ok' => true, 'gb' => $gb, 'expires_at' => $expires?->toIso8601String(), 'at' => now()->toIso8601String()];
    }

    private function checkAi(array $res): array
    {
        if (isset($res['skipped'])) {
            $this->line("ai: skipped — {$res['skipped']}");
            return ['skipped' => $res['skipped'], 'at' => now()->toIso8601String()];
        }

        $this->apiFailureIncident('ai', 'balance.ai_low', 'DeepSeek', $res);
        if (!$res['ok']) {
            $this->warn("ai: check failed — {$res['error']}");
            return ['ok' => false, 'error' => $res['error'], 'at' => now()->toIso8601String()];
        }

        $amount = number_format($res['amount'], 2, '.', '');
        $min    = $this->th['ai_min_usd'];
        $cur    = e($res['currency']);
        $this->line("ai: {$amount} {$res['currency']}".($res['available'] ? '' : ' (not available)'));

        $this->notifier->incident('balance:ai:low', 'balance.ai_low', !$res['available'] || $res['amount'] < $min,
            fn () => "🟠 <b>DeepSeek: баланс {$amount} {$cur}</b> (порог {$min})\nAI-перевод и классификация остановятся, когда деньги кончатся.",
            fn () => "✅ <b>DeepSeek пополнен</b>: {$amount} {$cur}",
            $this->th['repeat_hours']);

        return ['ok' => true, 'amount' => $res['amount'], 'currency' => $res['currency'], 'at' => now()->toIso8601String()];
    }

    /** A balance API failing 3 checks in a row is an incident of its own (bad key, API change). */
    private function apiFailureIncident(string $name, string $type, string $label, array $res): void
    {
        $fails = $res['ok'] ? 0 : (int) $this->state->get("{$name}_failures", 0) + 1;
        if (!$this->dryRun) {
            $this->state->put("{$name}_failures", $fails);
        }
        $this->notifier->incident("balance:{$name}:check_failed", $type, $fails >= 3,
            fn () => "🟠 <b>Не удаётся проверить баланс {$label}</b>\n<code>".e((string) ($res['error'] ?? '')).'</code>',
            fn () => "✅ Проверка баланса {$label} снова работает",
            $this->th['repeat_hours']);
    }

    // ── Parser runs ─────────────────────────────────────────────────────

    private function checkRecentRun(): void
    {
        $hours = $this->th['no_run_hours'];
        // Only successful full runs count (same rule as lots:lifecycle): a
        // maker/max_pages test job or a low-coverage run is not "the parser ran".
        $last = ParseJob::where('status', 'done')
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'reparse'))
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'type', 'status', 'filters', 'result', 'created_at', 'updated_at'])
            ->first(fn (ParseJob $j) => LotLifecycle::runRejection(
                $j->getAttributes(), (float) config('lifecycle.min_coverage'), (float) config('lifecycle.min_write_ratio'),
            ) === null)
            ?->updated_at;
        $last = $last ? Carbon::parse($last) : null;
        $stale = $last === null || $last->lt(now()->subMinutes((int) round($hours * 60)));
        $this->line('runs: last successful full run '.($last?->toDateTimeString() ?? 'never'));

        $this->notifier->incident('parser:no_recent_run', 'parser.no_recent_run', $stale,
            fn () => '⚠️ <b>Нет успешного прогона парсера дольше '.JobReport::num($hours)." ч</b>\nПоследний: "
                .($last ? $last->format('d.m.Y H:i').' ('.JobReport::duration($last->diffInSeconds(now(), true)).' назад)' : 'ни одного'),
            fn () => '✅ <b>Успешный прогон парсера</b>: '.($last?->format('d.m.Y H:i') ?? ''),
            $this->th['repeat_hours']);
    }
}
