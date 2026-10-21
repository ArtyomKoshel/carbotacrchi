<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Base for Laravel feature tests.
 *
 * Safety: the app must point at the local throw-away database carbot_test
 * before anything (migrations included) runs, and no HTTP request may leave
 * the process unless a test fakes it.
 */
abstract class TestCase extends BaseTestCase
{
    private const ALLOWED_DB = 'carbot_test';

    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', 'mysql'];

    public function createApplication()
    {
        $app = parent::createApplication();

        $db = $app['config']->get('database.connections.mysql');
        if ($app['config']->get('database.default') !== 'mysql'
            || ($db['database'] ?? null) !== self::ALLOWED_DB
            || !in_array($db['host'] ?? null, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against %s@%s: only %s on a local host is allowed (config cached? run php artisan config:clear).',
                $db['database'] ?? '?', $db['host'] ?? '?', self::ALLOWED_DB,
            ));
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Parser logs live in a scratch folder inside storage/
        $dir = storage_path('framework/testing/parser-logs');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        config(['admin.log_file' => $dir . '/parser.log']);
    }
}
