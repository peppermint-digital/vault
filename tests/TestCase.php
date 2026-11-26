<?php

namespace Peppermint\Vault\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Base;
use Peppermint\Vault\Tests\Fixtures\Benutzer;
use Peppermint\Vault\VaultServiceProvider;

abstract class TestCase extends Base
{
    protected string $wurzel = '';

    protected function getPackageProviders($app): array
    {
        return [VaultServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->wurzel = sys_get_temp_dir().'/vault-paket-test-'.bin2hex(random_bytes(6));

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('vault.user_model', Benutzer::class);
        $app['config']->set('auth.providers.users.model', Benutzer::class);
        $app['config']->set('filesystems.disks.vault', ['driver' => 'local', 'root' => $this->wurzel.'/vaults', 'throw' => true]);
        $app['config']->set('vault.versionen.pfad', $this->wurzel.'/versions');
        $app['config']->set('vault.papierkorb.pfad', $this->wurzel.'/trash');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('rolle')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/bestand');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/neu');
    }

    protected function tearDown(): void
    {
        if ($this->wurzel !== '') {
            File::deleteDirectory($this->wurzel);
        }

        parent::tearDown();
    }

    protected function person(string $name = 'Person', ?string $rolle = null): Benutzer
    {
        return Benutzer::query()->create([
            'name' => $name,
            'email' => strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.test',
            'rolle' => $rolle,
        ]);
    }
}
