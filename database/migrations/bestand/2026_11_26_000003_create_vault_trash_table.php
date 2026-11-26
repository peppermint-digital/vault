<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Gleich AI Brain 2026_09_24_153220. */
return new class extends Migration
{
    public function up(): void
    {
        $benutzer = (new (config('vault.user_model')))->getTable();

        Schema::create(config('vault.tables.trash', 'vault_trash'), function (Blueprint $table) use ($benutzer) {
            $table->id();
            $table->string('scope');
            $table->string('original_path', 1024);
            $table->string('type', 16)->default('file');
            $table->string('storage_path', 1024);
            $table->unsignedBigInteger('size')->nullable();
            // nullOnDelete: Ein geloeschtes Konto nimmt den Papierkorb nicht mit.
            $table->foreignId('deleted_by')->nullable()->constrained($benutzer)->nullOnDelete();
            $table->timestamp('deleted_at')->useCurrent();

            $table->index(['scope', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.trash', 'vault_trash'));
    }
};
