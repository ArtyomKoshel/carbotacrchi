<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Base for Laravel feature tests.
 *
 * Safety: the app must point at the local throw-away database carbot_test (or a
 * carbot_test_<suffix> one, so parallel branches don't share a schema) before
 * anything (migrations included) runs, and no HTTP request may leave the
 * process unless a test fakes it.
 */
abstract class TestCase extends BaseTestCase
{
    private const ALLOWED_DB = '/^carbot_test(_[a-z0-9_]+)?$/';

    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', 'mysql'];

    public function createApplication()
    {
        $app = parent::createApplication();

        $db = $app['config']->get('database.connections.mysql');
        if ($app['config']->get('database.default') !== 'mysql'
            || !preg_match(self::ALLOWED_DB, (string) ($db['database'] ?? ''))
            || !in_array($db['host'] ?? null, self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against %s@%s: only carbot_test or carbot_test_<suffix> on a local host is allowed (config cached? run php artisan config:clear).',
                $db['database'] ?? '?', $db['host'] ?? '?',
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
