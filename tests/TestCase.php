<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * SAFETY GUARD.
     *
     * Tests that use RefreshDatabase (or DatabaseMigrations/DatabaseTruncation)
     * DELETE EVERY TABLE before they run. So tests may only run against:
     *
     *   - the in-memory SQLite database (sqlite + ":memory:"), or
     *   - a database whose name ends in "_test", e.g. fintech_db_test.
     *
     * Anything else (like your real fintech_db) is refused. This also
     * protects you if `php artisan config:cache` makes Laravel ignore
     * phpunit.xml.
     *
     * setUpTraits() is where Laravel starts RefreshDatabase, so we check
     * the database here, before anything is deleted.
     */
    protected function setUpTraits()
    {
        $wipesDatabase = array_intersect(
            [RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class],
            class_uses_recursive(static::class)
        );

        if ($wipesDatabase) {
            $connection = config('database.default');
            $database = (string) config("database.connections.{$connection}.database");

            $isInMemorySqlite = $connection === 'sqlite' && $database === ':memory:';
            $isTestDatabase = Str::endsWith($database, '_test');

            if (! $isInMemorySqlite && ! $isTestDatabase) {
                throw new RuntimeException(
                    "Refusing to run tests against [{$connection}:{$database}] because they would erase it. "
                    . 'Tests may only use in-memory SQLite or a database whose name ends in "_test". '
                    . 'Check phpunit.xml and run `php artisan config:clear`.'
                );
            }
        }

        return parent::setUpTraits();
    }
}