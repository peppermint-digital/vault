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
        'sources' => 'vault_sources',
        'source_user' => 'vault_source_user',
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
     | Externe Quellen (SMB-Freigaben, auf dem Host eingehaengt). Aus = es gibt
     | weder Tabellen noch Wege noch Bereiche dafuer. Der Manager laesst sie
     | aus (F39); AI Brain schaltet sie ein.
     */
    'quellen' => (bool) env('VAULT_QUELLEN', false),

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

    /*
     | Verweisgraph und Rueckverweise liegen im geteilten Laravel-Cache. Jedes
     | Datei-Ereignis des Pakets vergisst sie sofort. Wer AM PAKET VORBEI
     | schreibt (Agenten mit eingehaengtem Vault, Sync), ruft
     | `Vault::verweise()->vergessen($bereich)` — oder lebt mit dieser
     | Hoechstdauer. Minuten. AI Brain: 15.
     */
    'verweise' => [
        'ttl_minuten' => (int) env('VAULT_VERWEISE_TTL_MINUTEN', 1440),
    ],

    'versionen' => [
        'pfad' => env('VAULT_VERSIONS_PATH'),           // null = storage/app/vault-versions
        'max_je_datei' => (int) env('VAULT_MAX_VERSIONS_PER_FILE', 30),
        'max_binaer_je_datei' => (int) env('VAULT_MAX_BINARY_VERSIONS_PER_FILE', 3),
    ],

    'papierkorb' => [
        'pfad' => env('VAULT_TRASH_PATH'),              // null = storage/app/vault-trash
        'tage' => (int) env('VAULT_TRASH_DAYS', 30),    // 0 = nie selbst aufraeumen
    ],

    /*
     | Die JSON-Schiene der Oberflaeche. `an => false`, wenn ein Produkt eigene
     | Controller behaelt (AI Brain bis V2). Die Middleware sorgt nur fuer die
     | Anmeldung; jede Aktion prueft ihre Rechte selbst ueber `Vaultrechte`.
     */
    'routen' => [
        'an' => env('VAULT_ROUTEN', true),
        'praefix' => 'vault/api',
        'middleware' => ['web', 'auth'],
        'name' => 'vault.api.',
        // Die SEITE eines Freigabelinks (gehoert dem Produkt): /<freigabe_praefix>/<token>
        'freigabe_praefix' => 'geteilt',
        // Die oeffentliche Schiene dazu (info, entsperren, roh), ohne Anmeldung.
        // Eigener Schalter (0.6.1): Ein Produkt mit eigenen Freigabe-Seiten
        // (AI Brain bis F52c) schaltet sie aus, ohne die JSON-Schiene zu verlieren.
        'oeffentlich_an' => (bool) env('VAULT_ROUTEN_OEFFENTLICH', true),
        // Benannte Drossel fuer das Entsperren (Passwort raten). Gibt es den
        // Begrenzer im Produkt nicht, meldet das Paket ihn mit 10/min je IP an —
        // so wirkt er wie das fruehere `throttle:10,1`.
        'drossel_entsperren' => 'vault-entsperren',
        'oeffentlich_praefix' => 'vault/geteilt',
        'oeffentlich_middleware' => ['web'],
    ],

    /*
     | EuroOffice (Document Server, OnlyOffice-Bauart). Je Produkt ein eigener
     | Container und ein eigenes JWT-Geheimnis (F40). Ohne `enabled` und ohne
     | Geheimnis von mindestens 32 Zeichen gibt es keinen Editor und keinen
     | Rueckweg — ein offener Rueckweg waere eine Schreibtuer in den Vault.
     */
    'eurooffice' => [
        'enabled' => (bool) env('EUROOFFICE_ENABLED', false),
        // Adresse, die der BROWSER erreicht.
        'public_url' => rtrim((string) env('EUROOFFICE_PUBLIC_URL', ''), '/'),
        // Adresse, die DIESER Server erreicht (leer = public_url).
        'internal_url' => rtrim((string) env('EUROOFFICE_INTERNAL_URL', ''), '/'),
        'jwt_secret' => (string) env('EUROOFFICE_JWT_SECRET', ''),
        'jwt_header' => (string) env('EUROOFFICE_JWT_HEADER', 'Authorization'),
        // Unsere Adresse, wie der Document Server sie erreicht.
        'callback_base_url' => rtrim((string) env('EUROOFFICE_CALLBACK_BASE_URL', (string) env('APP_URL', '')), '/'),
        'timeout_seconds' => (int) env('EUROOFFICE_TIMEOUT', 15),
        // Praefix der Wege OHNE Sitzung (Datei holen, Rueckweg) — per eigenem Token gesichert.
        'praefix' => 'vault/eurooffice',
        // Eigener Schalter fuer alle EuroOffice-Routen (0.3.5). null = wie `routen.an`.
        'routen' => null,
        // Drossel der Wege ohne Sitzung: Text oder Liste von Middleware (0.3.5).
        'drossel' => 'throttle:120,1',
        // Die Editor-Konfiguration (mit Sitzung). null = wie `routen.praefix` / `routen.middleware`.
        'konfiguration' => [
            'praefix' => null,
            'middleware' => null,
        ],
    ],

    /*
     | Vorschaubilder (GD) und PDF/EPUB-Anmerkungen. PDF braucht PyMuPDF: ohne
     | `python` gibt es keine PDF-Anmerkungen (EPUB geht immer, es ist eine
     | JSON-Datei daneben). Die Vorschaubilder liegen ausserhalb der Disk.
     */
    'vorschau' => [
        'pfad' => env('VAULT_THUMBS_PATH'),             // null = storage/app/vault-thumbs
    ],
    'anmerkungen' => [
        'python' => env('VAULT_PDF_PYTHON'),            // z.B. storage/app/python/venv/bin/python
        'skript' => env('VAULT_PDF_SCRIPT'),            // null = scripts/pdf_annotations.py im Paket
        'timeout' => (int) env('VAULT_PDF_TIMEOUT', 60),
    ],

    'hochladen' => [
        'max_kb' => (int) env('VAULT_UPLOAD_MAX_KB', 51200),
        // Wohin eine Datei geht, die OHNE Ordner hochgeladen wird (Knopf oben,
        // Ziehen ohne offenen Ordner). Vorgabe `_attachments` wie in AI Brain:
        // Dort zeigt der Steckbrief-Link des Avatars auf diesen Ordner. Ein
        // Produkt ohne Steckbrief (Manager) setzt '' = oben im Bereich (0.6.3).
        'ohne_ordner' => '_attachments',
    ],

    /*
     | Was die Oberflaeche als Ueberschrift eines Bereichs zeigt. Raeume und
     | Quellen tragen ihren eigenen Namen; der persoenliche Bereich heisst je
     | Produkt anders (AI Brain „Privat", Manager „Mein Arbeits-Vault").
     */
    /*
     | Die Leiste des Vaults (0.5.0): dieselben Eintraege in jedem Produkt.
     | Die Bereiche stehen NICHT in der Leiste, sondern als Reiter auf der Seite
     | (`Bereichsreiter`). Pfade je Produkt; `null` laesst den Eintrag weg.
     | Eigenes (Manager: Objekte, AI Brain: Externe Quellen) haengt das Produkt
     | selbst hinten an.
     */
    'navigation' => [
        'vault' => '/vault',
        'graph' => '/vault/graph',
        'freigaben' => '/vault/freigaben',
    ],

    'bezeichnungen' => [
        'persoenlich' => 'Persönlich',
    ],

    'suche' => [
        // Vorne an jeden Treffer-Pfad; die Seite gehoert dem Produkt.
        'url_praefix' => '/notes?path=',
    ],

];
