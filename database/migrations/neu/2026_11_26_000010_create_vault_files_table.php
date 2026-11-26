<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Support\Pfadindex;

/*
 * Feste ID je Vault-Datei (F42). Neu in jedem Produkt; der Bestand wird
 * beim ersten Zugriff (`Dateikennung::sicherstellen`) oder per Lauf nachgetragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('vault.tables.files', 'vault_files'), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 128);
            $table->string('path', 1024);
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('art', 16)->nullable();
            // Geloescht = im Papierkorb. Die Zeile bleibt, damit Wiederherstellen dieselbe ID bringt.
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('trash_id')->nullable()->index();
            $table->timestamps();

            Pfadindex::anlegen($table, 'path', 'vault_files_scope_path_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.files', 'vault_files'));
    }
};
