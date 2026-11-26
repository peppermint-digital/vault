<?php

/*
|--------------------------------------------------------------------------
| peppermint/vault
|--------------------------------------------------------------------------
|
| Dieselben Bloecke in jedem Produkt (Wiki #359 Regel 2). Das Paket teilt
| Code, nicht Daten: Jedes Produkt hat seine eigene Ablage, seine eigenen
| Tabellen und seine eigenen Rechte. Zwischen zwei Produkten gibt es keinen
| Abgleich.
|
*/

return [

    /*
     | Die Laravel-Disk, unter der alle Bereiche liegen. Sie MUSS lokal sein:
     | Die Pfadsicherheit prueft mit realpath() und lehnt symbolische Links
     | ab, und die Editoren (EuroOffice, PDF-Anmerkungen) brauchen einen
     | echten Pfad. Fehlt die Disk in `filesystems.disks`, legt das Paket sie
     | lokal unter storage/app/vaults an.
     |
     | AI Brain: Wurzel = bisheriges `vaults_path`, damit sich kein Pfad aendert.
     */
    'disk' => env('VAULT_DISK', 'vault'),

    /*
     | Ordner der geteilten Bereiche unterhalb der Disk.
     |
     | `global` ist der eine Bereich, den alle lesen (AI Brain: `_shared`,
     | Manager: `firma`). Raeume liegen unter `raeume/<slug>`. Persoenliche
     | Bereiche liegen unter `<user-id>` — die fuehrende Ziffer kann mit
     | keinem dieser Namen kollidieren, solange sie nicht mit einer Ziffer
     | beginnen.
     */
    'bereiche' => [
        'global' => env('VAULT_GLOBALER_BEREICH', '_shared'),
        'raeume' => '_spaces',
    ],

    /*
     | Tabellennamen, abbildbar. Im Manager ist `note_shares` vom CRM belegt,
     | daher heisst die Freigabetabelle dort `vault_shares` (Vorgabe hier).
     | AI Brain bildet `shares` auf `note_shares` ab.
     */
    'tables' => [
        'spaces' => 'vault_spaces',
        'space_user' => 'vault_space_user',
        'versions' => 'vault_versions',
        'trash' => 'vault_trash',
        'shares' => 'vault_shares',
        'files' => 'vault_files',
        'links' => 'vault_links',
    ],

    /*
     | Eigene Migrationen des Pakets laden. AI Brain: false — dort gibt es die
     | Tabellen schon (die Schemata hier sind ihnen gleich). `vault_files` und
     | `vault_links` liegen getrennt und haengen an `run_migrations_neu`, weil
     | es sie in keinem Produkt vorher gab.
     */
    'run_migrations' => env('VAULT_RUN_MIGRATIONS', true),
    'run_migrations_neu' => env('VAULT_RUN_MIGRATIONS_NEU', true),

    'user_model' => env('VAULT_USER_MODEL', 'App\\Models\\User'),

    /*
     | Rechte. Das Paket fragt nur den Vertrag `Vaultrechte`; die
     | Standardrechte lesen diese beiden Angaben. Ein Produkt mit eigener
     | Logik bindet den Vertrag selbst.
     */
    'rechte' => [
        // Rolle (hasRole), die alle Raeume verwaltet. NIE fremde persoenliche Bereiche.
        'verwalterrolle' => env('VAULT_VERWALTERROLLE', 'admin'),
        // Recht (can), mit dem man im globalen Bereich schreibt.
        'global_schreiben' => env('VAULT_RECHT_GLOBAL_SCHREIBEN', 'shared-vault.edit'),
    ],

    /*
     | Dateien in der WURZEL eines persoenlichen Bereichs, die nicht der Person
     | gehoeren (Konfiguration, kein Inhalt). AI Brain: ['CLAUDE.md'] — die
     | Persona des Avatars. Bewusst nur die Wurzel.
     */
    'verborgene_wurzeldateien' => [],

    'versionen' => [
        'pfad' => env('VAULT_VERSIONS_PATH'),           // null = storage/app/vault-versions
        'max_je_datei' => (int) env('VAULT_MAX_VERSIONS_PER_FILE', 30),
        'max_binaer_je_datei' => (int) env('VAULT_MAX_BINARY_VERSIONS_PER_FILE', 3),
    ],

    'papierkorb' => [
        'pfad' => env('VAULT_TRASH_PATH'),              // null = storage/app/vault-trash
        'tage' => (int) env('VAULT_TRASH_DAYS', 30),    // 0 = nie selbst aufraeumen
    ],

    'suche' => [
        // Vorne an jeden Treffer-Pfad; die Seite gehoert dem Produkt.
        'url_praefix' => '/notes?path=',
    ],

];
