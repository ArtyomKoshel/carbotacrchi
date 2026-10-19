<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Telegram notifications:
 *  - notification_recipients: who gets which notification types;
 *  - notification_settings:   editable thresholds (key → value);
 *  - notification_events:     dedup ledger — one row per announced event / incident;
 *  - notification_log:        every delivery attempt.
 * Seeds the owner (@archi10764) as the first recipient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 40)->unique();
            $table->string('label', 100)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->comment('users.id (Telegram user id) when picked from users');
            $table->boolean('enabled')->default(true);
            $table->json('types');
            $table->timestamps();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->string('value', 255);
            $table->timestamps();
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 191)->unique();
            $table->string('type', 40)->index();
            $table->enum('status', ['sent', 'open', 'resolved'])->default('sent');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('event_key', 191)->nullable();
            $table->string('chat_id', 40);
            $table->boolean('ok');
            $table->string('error', 255)->nullable();
            $table->text('text');
            $table->timestamp('created_at')->nullable()->index();
        });

        $types = collect(config('notifications.types', []))
            ->filter(fn ($t) => $t['default'])
            ->keys()
            ->values()
            ->all();

        DB::table('notification_recipients')->insert([
            'chat_id'    => '799255534',
            'label'      => '@archi10764',
            'user_id'    => DB::table('users')->where('id', 799255534)->exists() ? 799255534 : null,
            'enabled'    => true,
            'types'      => json_encode($types),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('notification_recipients');
    }
};
