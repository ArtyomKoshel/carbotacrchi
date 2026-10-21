<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase, but migrate:fresh (≈100 s for 100+ migrations) only when
 * carbot_test does not already hold exactly the repo's migrations, or when
 * TESTS_FRESH_DB=1. Every test still runs in a rolled-back transaction.
 */
trait RefreshesDatabaseOnce
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated && ! getenv('TESTS_FRESH_DB') && $this->schemaIsCurrent()) {
            RefreshDatabaseState::$migrated = true;
        }

        $this->baseRefreshTestDatabase();
    }

    private function schemaIsCurrent(): bool
    {
        try {
            $applied = DB::table('migrations')->pluck('migration')->sort()->values()->all();
        } catch (\Throwable) {
            return false;
        }
        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();

        return $applied === $files;
    }
}
