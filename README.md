# peppermint/vault

Der Vault als geteiltes Paket. **Er teilt Code, nicht Daten:** Jedes Produkt hat seine eigene Ablage, seine eigenen Tabellen und seine eigenen Rechte, wie bei `peppermint/mailbox`. Der private Vault bleibt in AI Brain, der Firmen-Vault lebt im Manager, und zwischen beiden gibt es keinen Abgleich.

Composer `peppermint/vault` und npm `@peppermint-digital/vault` liegen in diesem Repo und tragen immer dieselbe Version.

Plan und Herkunft: `codingshare/vault-paket-plan-2026-11-26.md`. Der Kern ist aus AI Brain `app/Services/VaultService.php` übernommen (Stand `118ee2b2`, Vault-Code unverändert seit `7b8eddee`).

## Was drin ist (0.1.0)

| Baustein | Klasse |
|---|---|
| Bereiche `user:{id}` / `area:{key}` | `Bereich` |
| Pfadsicherheit, Disk | `Ablage` |
| Lesen, Schreiben, Hochladen, Verschieben, Suche, Baum | `Vault` |
| Versionen (Text und Binär) | `Versionen` |
| Papierkorb | `Papierkorb`, `vault:papierkorb-leeren` |
| Feste Datei-ID (F42) | `Dateikennung`, Tabelle `vault_files` |
| Verknüpfungen mit Objekten | `Verknuepfung\Verknuepfungen`, Trait `HatVaultDateien`, Tabelle `vault_links` |
| Ablage-Regel je Objektart | `Ablageregeln`, Vertrag `Ablageregel` |
| Verweisgraph `[[wikilinks]]` | `Verweise` |
| Räume, Mitglieder | `Models\VaultSpace` |
| Freigabelinks | `Models\VaultShare` |
| HTML → Markdown (Umzug der Manager-Notizen) | `Umwandlung\HtmlZuMarkdown` |

**Noch nicht drin, folgt:** Routen und Controller, die Oberfläche (`@peppermint-digital/vault` enthält bisher nur die Datenformen), EuroOffice-Server-Seite, externe Quellen (SMB), PDF/EPUB-Anmerkungen und MCP-Bausteine.

## Verträge

Das Produkt bindet sie. Es gibt Vorgaben, alle über `bindIf`:

- `Contracts\Vaultrechte`: Wer liest und schreibt welchen Bereich. Die Vorgabe `Rechte\Standardrechte` liest `vault.rechte.*`. **Ein persönlicher Bereich gehört allein seiner Person, auch Verwalter lesen ihn nicht.**
- `Contracts\Benutzeradresse`: Person ↔ Adresse. Die Vorgabe ist die Spalte `email`.
- `Contracts\Ablageregel`: legt fest, in welchen Ordner eine Datei zu einem Objekt gehört. Die Regeln werden im `Ablageregeln`-Verzeichnis angemeldet.
- `Contracts\Verknuepfbar`: ein Modell, an dem Dateien hängen.

## Ereignisse

Das Paket selbst kennt weder Indexer noch Aktivitätsliste. Die Produkte hängen sich an diese Ereignisse:

- `DateiGespeichert`
- `DateiVerschoben`
- `DateiGeloescht`
- `BereichAngelegt`
- `KontoAngelegt`

## Einbau

```php
// config/vault.php (php artisan vendor:publish --tag=vault-config)
'disk' => 'vault',                       // lokal; AI Brain: Wurzel = vaults_path
'bereiche' => ['global' => 'firma'],     // AI Brain: '_shared'
'tables' => ['shares' => 'vault_shares'],// AI Brain: 'note_shares'
'run_migrations' => true,                // AI Brain: false (Tabellen gibt es schon)
'run_migrations_neu' => true,            // vault_files, vault_links: überall
```

Es gibt zwei Migrationsordner:

- `database/migrations/bestand`: die Tabellen, die AI Brain schon hat. Die Schemata sind dort gleich.
- `database/migrations/neu`: `vault_files` und `vault_links`.

Unter MySQL bekommt der Index über `scope` + `path` ein Präfix (`Support\Pfadindex`). Ohne Präfix lehnt MySQL ab („key too long“). Das ist geprüft, siehe `tests/Mysql`.

## Abweichungen vom Verhalten in AI Brain

1. **Pfadsicherheit:** Der Präfixvergleich zählt jetzt den Trenner mit. In AI Brain lag `/vaults/10/x` scheinbar in `/vaults/1`, erreichbar über einen verlinkten Unterordner.
2. **Verschieben:** Versionen und Datei-IDs wandern mit. In AI Brain blieb die Versionsliste am alten Pfad stehen.
3. **Graph-Cache geteilter Bereiche:** Neuer Schlüssel `vault_graph_area_*`. Der persönliche Bereich behält `vault_graph_{id}`.

## Tests

```bash
composer test                     # Pint + Pest (SQLite)
VAULT_MYSQL_HOST=… vendor/bin/pest tests/Mysql   # gegen ein Wegwerf-MySQL
```
