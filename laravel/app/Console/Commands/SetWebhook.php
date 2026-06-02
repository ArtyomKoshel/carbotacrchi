<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SetWebhook extends Command
{
    protected $signature   = 'bot:set-webhook {--remove : Remove webhook instead of setting it}';
    protected $description = 'Register Telegram bot webhook URL';

    public function handle(): int
    {
        $token  = (string) config('auction.bot_token');
        $secret = (string) config('auction.webhook_secret', '');
        $appUrl = rtrim((string) config('app.url'), '/');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');
            return self::FAILURE;
        }

        $base = "https://api.telegram.org/bot{$token}";

        if ($this->option('remove')) {
            $resp = Http::post("{$base}/deleteWebhook");
            $this->info('deleteWebhook: ' . $resp->body());
            return self::SUCCESS;
        }

        if ($appUrl === '' || $appUrl === 'http://localhost:8080') {
            $this->error('APP_URL is not set or is localhost. Set it to the public Railway URL.');
            return self::FAILURE;
        }

        $webhookUrl = $appUrl . '/bot/webhook';

        $params = ['url' => $webhookUrl];
        if ($secret !== '') {
            $params['secret_token'] = $secret;
        }
        $params['allowed_updates'] = ['message', 'callback_query'];
        $params['drop_pending_updates'] = true;

        $resp = Http::post("{$base}/setWebhook", $params);
        $json = $resp->json();

        if ($json['ok'] ?? false) {
            $this->info("✅ Webhook set: {$webhookUrl}");
        } else {
            $this->error('❌ Failed: ' . ($json['description'] ?? $resp->body()));
            return self::FAILURE;
        }

        // Verify
        $info = Http::get("{$base}/getWebhookInfo")->json();
        $this->line('getWebhookInfo: ' . json_encode($info['result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
