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
| Namen der Bereiche in Anfragen | Vertrag `Bereichsnamen`, Vorgabe `StandardNamen` |
| HTML → Markdown (Umzug der Manager-Notizen) | `Umwandlung\HtmlZuMarkdown` |
| JSON-Schiene für die Oberfläche | `routes/vault.php`, `Http\Controllers\*` |
| Externe Quellen (SMB, nur mit `vault.quellen`) | `Quellen\*`, `Models\VaultSource`, Vertrag `Einhaengung` |
| MCP-Bausteine (lesen, auflisten, suchen, schreiben) | `Mcp\*` (mit `laravel/mcp`) |
| Vorschaubilder, PDF/EPUB-Anmerkungen mit Spiegelung in den Steckbrief | `Vorschaubilder`, `Anmerkungen\*`, `scripts/pdf_annotations.py` |
| EuroOffice: Konfiguration, Datei, Rückweg, Blankovorlagen | `EuroOffice\*`, `Http\Controllers\EuroOfficeController`, `stubs/office/` |
| Oberfläche (npm) | `PdfAnnotator` (Einstieg `/anmerken`), `EpubAnnotator`, `EuroOfficeEditor`, `GeteilteAnsicht`, `VaultBrowser`, `Ordnerbaum`, `Dateiansicht`, `Versionsliste`, `Papierkorbliste`, `FreigabeDialog`, `AnlegenDialog`, `vaultApi` |

**Noch nicht drin, folgt:** das ebenenweise Nachladen externer Quellen im Ordnerbaum. Die Schiene dafür steht schon (`vaultApi.quelleEbene`).

## JSON-Schiene

Unter `vault.routen.praefix` (Vorgabe `vault/api`), Middleware `web` + `auth`. Abschalten mit `VAULT_ROUTEN=false`, wenn ein Produkt seine eigenen Controller behält.

Jede Anfrage trägt `?scope=`: `private` steht für den eigenen Bereich, sonst steht dort der Slug eines Raums. Wer nicht lesen darf, bekommt **404** (ein fremder Raum soll nicht erkennbar sein). Wer nicht schreiben darf, bekommt **403**.

| | |
|---|---|
| `GET baum` | Bereich (Label, darfSchreiben) und Baum |
| `GET/POST/PATCH/DELETE datei` | lesen, anlegen (überschreibt nie, 422), speichern (409 bei fremder Änderung über `base_version`), in den Papierkorb |
| `GET roh` | Rohdatei; HTML nur mit Content-Security-Policy |
| `POST verschieben`, `POST hochladen`, `GET suche`, `GET verweise` | |
| `versionen…`, `papierkorb…`, `freigaben…` | Listen und Aktionen; Rechte über den Bereich des Eintrags |

**Öffentliche Schiene der Freigabelinks** unter `vault.routen.oeffentlich_praefix` (Vorgabe `vault/geteilt`), ohne Anmeldung:
- `GET {token}/info`: Name, Format und Inhalt, sofern nicht gesperrt.
- `POST {token}/entsperren`: Passwort prüfen, gedrosselt auf 10 Versuche je Minute.
- `GET {token}/roh/{unterpfad?}`: die Datei selbst oder eine Nachbardatei aus demselben Ordner.

Ein unbekannter Token gibt 404, ein zurückgezogener oder abgelaufener 410, ein gesperrter 403. Eine Freigabe in der Wurzel bekommt keine Nachbarn. Die **Seite** `/geteilt/{token}` rendert das Produkt mit dem Baustein `GeteilteAnsicht`. HTML läuft dort in einem iframe ohne `allow-same-origin`.

## Oberfläche

```tsx
import { VaultBrowser, vaultApi } from '@peppermint-digital/vault';

const api = vaultApi({ basisUrl: '/vault/api', csrfToken: () => document.querySelector<HTMLMetaElement>('meta[name=csrf-token]')!.content });

<VaultBrowser api={api} scope="private" renderEditor={({ wert, onAendern }) => <MarkdownEditor value={wert} onChange={onAendern} />} />
```

- **Seite, Layout und die Wahl des Bereichs** bleiben beim Produkt. Der Baustein zeigt genau einen Bereich.
- **Editor und Lese-Ansicht** sind Slots (`renderEditor`, `renderAnsicht`). Ohne sie gibt es ein Textfeld und vorformatierten Text.
- **Konflikt:** Hat jemand die Datei geändert, während du sie bearbeitet hast, fragt die Ansicht nach. Zur Wahl stehen „Meine Fassung trotzdem speichern“ und „Andere Fassung übernehmen“. Nichts wird still überschrieben.
- **Tailwind v4 liest `node_modules` nicht ein.** Das Produkt braucht deshalb in seiner CSS-Datei:

  ```css
  @source "../../node_modules/@peppermint-digital/vault/dist";
  ```

  Fehlt die Zeile, gibt es keinen Fehler, die Bausteine sitzen dann nur falsch.

## EuroOffice

Je Produkt gibt es einen eigenen Document Server mit eigenem JWT-Geheimnis (F40), eingestellt in `vault.eurooffice.*` (`EUROOFFICE_*`). Fehlt `enabled` oder ist das Geheimnis kürzer als 32 Zeichen, gibt es keinen Editor und keinen Rückweg.

- `GET vault/api/eurooffice/konfiguration?scope=&path=` liefert mit Sitzung die signierte Konfiguration. Bearbeiten und einen Rückweg gibt es nur mit Schreibrecht.
- `GET vault/eurooffice/datei/{token}` und `POST vault/eurooffice/rueckweg/{token}` laufen **ohne Sitzung und ohne CSRF**. Gesichert sind sie über unser eigenes signiertes Token, gedrosselt auf 120 Anfragen pro Minute.
- Der Rückweg glaubt dem Server nie, wohin er schreiben soll: Das Ziel steht in unserem Token. Die Nutzlast muss signiert sein, und Dateien werden nur vom eigenen Document Server geholt (Schutz gegen SSRF). Eine leere oder fehlgeschlagene Antwort überschreibt nichts.
- **Neu gegenüber AI Brain:** Das Rückweg-Token trägt die Person. Beim Speichern wird über `Vaultrechte` erneut geprüft, ob sie noch schreiben darf.
- Neue `.docx`, `.xlsx` und `.pptx` entstehen als Kopie der leeren Vorlagen in `stubs/office/`. Die Vorlagen sind mit LibreOffice erzeugt und enthalten keinen Autor.

## Vorschaubilder und Anmerkungen

- `GET vault/api/vorschau`: Vorschaubild mit 320 px (JPEG über GD, SVG direkt). Abgelegt unter `vault.vorschau.pfad`, außerhalb der Disk. Der Ordnerbaum zeigt es bei Bildern.
- `GET/POST/PATCH/DELETE vault/api/anmerkungen`: PDF-Anmerkungen über PyMuPDF (`vault.anmerkungen.python`; ohne Python antwortet die Schiene mit 503). EPUB-Anmerkungen liegen in einer Datei daneben (`<buch>.epub.annot.json`). Jede Änderung wird in den Steckbrief `<stamm>.md` gespiegelt. Ansehen verlangt Leserecht, Ändern Schreibrecht.
- **Neu gegenüber AI Brain:** Nach jeder PDF-Änderung gibt es eine Binärversion, denn PyMuPDF schreibt in die Datei selbst. Außerdem gehen Anmerkungen auch in geteilten Bereichen (in AI Brain nur privat).

## Anmerken in der Oberfläche

- **PDF:** `PdfAnnotator` liegt im eigenen Einstieg `@peppermint-digital/vault/anmerken`, weil er `pdfjs-dist` braucht (optionale peerDependency). Der Haupteinstieg bleibt frei davon; ein Test hält das fest. Das Produkt setzt ihn über `renderPdf` in `VaultBrowser` oder `Dateiansicht` ein:

  ```tsx
  import { PdfAnnotator } from '@peppermint-digital/vault/anmerken';
  import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
  import 'pdfjs-dist/web/pdf_viewer.css';
  import '@peppermint-digital/vault/annotator.css';

  <VaultBrowser api={api} scope="private" renderPdf={({ api, scope, pfad }) => <PdfAnnotator api={api} scope={scope} path={pfad} workerUrl={workerUrl} />} />
  ```

- **EPUB:** `EpubAnnotator` ist im Haupteinstieg. Er öffnet sich automatisch, wenn die HTML-Lesefassung `<buch>.html` neben dem EPUB liegt.

## Externe Quellen

Nur mit `vault.quellen` (`VAULT_QUELLEN=true`); der Manager lässt das aus (F39). Eine Quelle ist eine auf dem Host eingehängte Freigabe und wird im Bereich `source:{slug}` angesprochen, in Anfragen als `?scope=extern:<slug>`.

- **Fremder Speicher:** keine Versionen, kein Papierkorb, keine Datei-IDs, kein Index. Löschen löscht wirklich. Der Baum kommt ebenenweise (`GET vault/api/quelle/ebene`).
- **Einhängung:** Ein nicht eingehängter Pfad sieht aus wie ein leeres Verzeichnis. Deshalb prüft jede Operation die Einhängung über den Vertrag `Einhaengung`; die Vorgabe vergleicht die Geräte-Kennung mit dem Elternverzeichnis. Ohne Einhängung wird nichts gelesen und nichts geschrieben, auch wenn sie mitten in einer Anfrage wegbricht. Die Schiene meldet dann `erreichbar: false` statt einer leeren Liste.
- **Rechte:** Lesen dürfen nur Mitglieder, auch Verwalter nicht automatisch. Schreiben darf `editor`, und nur solange die Quelle eingehängt ist.
- **Sperre:** Die normale Ablage nimmt keinen Quellen-Bereich an. Sonst entstünde ein Ordner gleichen Namens auf der eigenen Platte.

## MCP-Bausteine

Mit `laravel/mcp` gibt es vier abstrakte Werkzeuge: `VaultLesen`, `VaultAuflisten`, `VaultSuchen` und `VaultSchreiben`. Das Produkt leitet davon ab und setzt `$name` sowie `bereich()`. Dass Werkzeuge produktspezifisch benannt werden (Plan §5.4: `*-firmen-vault`), ist damit Sache des Produkts.

- **Ohne angemeldete Person gibt es keine Antwort.** Der Rückfall auf einen globalen Bereich aus AI Brain fehlt bewusst (Plan, Falle 8).
- Die Rechte laufen über `Vaultrechte`, wie in der Oberfläche.
- `VaultSchreiben` schreibt mit `base_version` nie über eine inzwischen geänderte Fassung. Der Autor heißt in den Versionen „assistant für <Person>“.

## Bereichsnamen (0.3.0)

`?scope=` wird über den Vertrag `Contracts\Bereichsnamen` aufgelöst. Die Vorgabe `Bereichsnamen\StandardNamen` kennt drei Formen:
- `private`
- Raum-Slugs
- `extern:<slug>`, nur mit eingeschalteten Quellen

Ein Produkt meldet weitere Präfixe an, ohne die Vorgabe nachzubauen:

```php
app(StandardNamen::class)->praefix('objekt:',
    aus: fn (string $rest, $person) => /* Bereich oder null */,
    name: fn (Bereich $b) => /* Rest-Name oder null */,
    bezeichnung: fn (Bereich $b) => /* Überschrift */);
```

- Rechte entscheidet weiter `Vaultrechte`.
- Ein unbekannter Name gibt wie ein fremder Bereich 404.
- Präfixe enden mit `:`, damit kein Raum sie überdecken kann. `extern:` ist eingebaut und lässt sich nicht überschreiben.
- Die Überschrift des persönlichen Bereichs setzt das Produkt über `vault.bezeichnungen.persoenlich`. Vorgabe ist „Persönlich“, der Manager nimmt „Mein Arbeits-Vault“.

## Fassungen übernehmen (0.3.0)

`Versionen::uebernehmen($bereich, $pfad, $inhalt, $autor, $zeitpunkt)` legt eine Fassung mit vorgegebenem Inhalt, Autor und Zeitpunkt an, etwa das Original-HTML einer alten Notiz beim Umzug.

- Anders als beim normalen Schreiben wirft der Aufruf bei Fehlern.
- Er beschneidet selbst nicht. Spätere Schreibvorgänge kürzen aber wie immer auf die neuesten `max_je_datei` Fassungen. Nach so vielen Bearbeitungen fällt auch die übernommene Fassung weg.

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
4. **Freigabelink anlegen** verlangt Schreibrecht im Bereich. In AI Brain reichte das Leserecht. Im Firmen-Vault liest aber jede Person alles, und ein Link macht eine Datei öffentlich.
5. **Ordnerbaum:** Die Aktionen liegen hinter „⋮“ statt hinter dem Rechtsklick, den es auf dem Telefon nicht gibt. Der Aufklapp-Zustand wird je Bereich gemerkt.

## Tests

```bash
composer test                     # Pint + Pest (SQLite)
npm test && npm run typecheck     # vitest (jsdom) + tsc
VAULT_MYSQL_HOST=… vendor/bin/pest tests/Mysql   # gegen ein Wegwerf-MySQL
```
