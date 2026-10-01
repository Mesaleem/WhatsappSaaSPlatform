<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety guard (added 2026-09-30 after a test run wiped the real
     * database). RefreshDatabase runs `migrate:fresh` — it DROPS every
     * table — so the suite must never start against a real database.
     *
     * The app is created, then checked BEFORE any trait (RefreshDatabase)
     * touches the database:
     *   - a cached config (bootstrap/cache/config.php) is refused outright:
     *     it makes Laravel ignore phpunit.xml's test environment, which is
     *     exactly how the real database was reached;
     *   - APP_ENV must be `testing`;
     *   - the database must be SQLite in memory, or a database whose name
     *     contains "test" or "throwaway" (the MariaDB verification suites).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->refuseUnsafeTestDatabase($app);

        return $app;
    }

    private function refuseUnsafeTestDatabase($app): void
    {
        if ($app->configurationIsCached()) {
            throw new RuntimeException(
                'Refusing to run tests: the configuration is cached (bootstrap/cache/config.php), so phpunit.xml is ignored '
                .'and the tests would use the real database. Run `php artisan config:clear` first.'
            );
        }

        if (! $app->environment('testing')) {
            throw new RuntimeException('Refusing to run tests: APP_ENV is "'.$app->environment().'", not "testing".');
        }

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        $safe = ($connection === 'sqlite' && $database === ':memory:')
            || preg_match('/(test|throwaway)/i', basename($database)) === 1;

        if (! $safe) {
            throw new RuntimeException(
                "Refusing to run tests against database \"{$database}\" ({$connection}): tests drop every table. "
                .'Use SQLite :memory: (phpunit.xml) or a database whose name contains "test".'
            );
        }
    }
}
