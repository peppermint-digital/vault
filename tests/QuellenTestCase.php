<?php

namespace Peppermint\Vault\Tests;

/** Mit eingeschalteten Quellen: Wege und Tabellen entstehen beim Hochfahren. */
abstract class QuellenTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('vault.quellen', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/quellen');
    }
}
