<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Datei ↔ Objekt des Produkts, viele-zu-viele. `linkable_type` traegt das
 * Morph-Kuerzel (getMorphClass), `linkable_id` als Zeichenkette, damit auch
 * UUID-Schluessel passen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dateien = config('vault.tables.files', 'vault_files');
        $benutzer = (new (config('vault.user_model')))->getTable();

        Schema::create(config('vault.tables.links', 'vault_links'), function (Blueprint $table) use ($dateien, $benutzer) {
            $table->id();
            $table->foreignUlid('file_id')->constrained($dateien)->cascadeOnDelete();
            $table->string('linkable_type', 64);
            $table->string('linkable_id', 64);
            $table->string('rolle', 32)->default('notiz');
            $table->boolean('angeheftet')->default(false);
            $table->foreignId('user_id')->nullable()->constrained($benutzer)->nullOnDelete();
            $table->timestamps();

            $table->unique(['file_id', 'linkable_type', 'linkable_id', 'rolle'], 'vault_links_eindeutig');
            $table->index(['linkable_type', 'linkable_id'], 'vault_links_objekt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.links', 'vault_links'));
    }
};
