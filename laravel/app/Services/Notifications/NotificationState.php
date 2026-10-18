<?php

namespace App\Services\Notifications;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Notification state that must survive a MySQL outage: the DB-down incident,
 * the recipient snapshot used while the DB is down, buffered log rows, the
 * last check results. Redis when configured, else the local file store.
 */
class NotificationState
{
    private const PREFIX = 'notifications:';

    private ?Repository $store = null;

    public function store(): Repository
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $name = (string) config('notifications.cache_store', 'file');
        if ($name !== 'file') {
            try {
                $store = Cache::store($name);
                $store->get(self::PREFIX.'ping');
                return $this->store = $store;
            } catch (\Throwable $e) {
                // Class name only: connection errors may quote the host.
                Log::warning("[notifications] cache store '{$name}' unavailable (".get_class($e).'), using file store');
            }
        }

        return $this->store = Cache::store('file');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get(self::PREFIX.$key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->store()->forever(self::PREFIX.$key, $value);
    }

    public function forget(string $key): void
    {
        $this->store()->forget(self::PREFIX.$key);
    }

    /** Append to a list, keeping at most $max newest items. */
    public function push(string $key, mixed $item, int $max = 500): void
    {
        $list   = (array) $this->get($key, []);
        $list[] = $item;
        $this->put($key, array_slice($list, -$max));
    }

    public function pull(string $key): array
    {
        $list = (array) $this->get($key, []);
        $this->forget($key);
        return $list;
    }

    public function lock(string $name, int $seconds): Lock
    {
        return $this->store()->lock(self::PREFIX.'lock:'.$name, $seconds);
    }
}
