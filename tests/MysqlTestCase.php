<?php

namespace Peppermint\Vault\Tests;

use Illuminate\Support\Facades\Schema;

/**
 * Dieselben Migrationen gegen MySQL — so laufen sie im Manager.
 *
 * Nur mit VAULT_MYSQL_HOST/PORT gesetzt (z.B. ein Wegwerf-Container);
 * sonst uebersprungen. Grund: Der Index ueber scope + path(1024) ist in
 * utf8mb4 laenger, als MySQL erlaubt — das faellt nur dort auf.
 */
abstract class MysqlTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('VAULT_MYSQL_HOST')) {
            $this->markTestSkipped('Kein MySQL gesetzt (VAULT_MYSQL_HOST).');
        }

        parent::setUp();
    }

    /** Ein Wegwerf-Schema: vorher leer raeumen, sonst stoert der letzte Lauf. */
    protected function defineDatabaseMigrations(): void
    {
        Schema::dropAllTables();

        parent::defineDatabaseMigrations();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'mysql_probe');
        $app['config']->set('database.connections.mysql_probe', [
            'driver' => 'mysql',
            'host' => getenv('VAULT_MYSQL_HOST'),
            'port' => getenv('VAULT_MYSQL_PORT') ?: 3306,
            'database' => getenv('VAULT_MYSQL_DATABASE') ?: 'probe',
            'username' => getenv('VAULT_MYSQL_USER') ?: 'root',
            'password' => getenv('VAULT_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }
}
