<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Peppermint\Vault\Support\Pfadindex;

/* Gleich AI Brain 2026_07_07_151719 + 2026_09_24_080437 (Binaerstaende). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('vault.tables.versions', 'vault_versions'), function (Blueprint $table) {
            $table->id();
            $table->string('scope', 128);
            $table->string('path', 1024);
            $table->longText('content');
            $table->string('storage_path', 1024)->nullable();
            $table->string('checksum', 64)->nullable();
            // 'assistant' fuer KI-Edits, sonst Name/Kennung; null = unbekannt.
            $table->string('author', 128)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamp('created_at')->nullable();

            Pfadindex::anlegen($table, 'path', 'vault_versions_scope_path_index');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.versions', 'vault_versions'));
    }
};
