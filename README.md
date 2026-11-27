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
| Papierkorb | `Papierkorb`, `vault:papierkorb-leeren`, `vault:versionen-aufraeumen --trocken` |
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
| Oberfläche (npm) | `PdfAnnotator` (Einstieg `/anmerken`), `EpubAnnotator`, `EuroOfficeEditor`, `GeteilteAnsicht`, `VaultBrowser`, `Ordnerbaum`, `Dateiansicht`, `Versionsliste`, `Papierkorbliste`, `FreigabeDialog`, `AnlegenDialog`, `Bereichsreiter`, `VerweisGraph`, `VaultSeitenkopf`, `vaultApi` |

**Noch nicht drin, folgt:** das ebenenweise Nachladen externer Quellen im Ordnerbaum. Die Schiene dafür steht schon (`vaultApi.quelleEbene`).

## Leiste, Bereiche und Graph (0.5.0)

In jedem Produkt sieht die Vault-Leiste gleich aus, Vorbild ist AI Brain. Die Leiste zeigt **Funktionen**, die **Bereiche** stehen als Reiter oben auf der Seite.

- **`Navigation::eintraege($person)`:** liefert Vault, Graph und Freigabelinks mit `titel`, `pfad`, `symbol` (lucide).
  - Die Pfade stehen in `vault.navigation.*`; Vorgaben sind `/vault`, `/vault/graph`, `/vault/freigaben`. `null` oder `''` lässt einen Eintrag weg.
  - Wer keinen Bereich sieht, bekommt keine Leiste.
  - Eigenes hängt das Produkt **dahinter**: der Manager „Objekte“, AI Brain „Externe Quellen“.
- **`Navigation::bereiche($person)`:** liefert die lesbaren Bereiche in fester Reihenfolge (persönlich, Firma bzw. global, Räume, Quellen), jeweils mit `slug` (der `scope`-Wert), `label`, `art` und `darfSchreiben`.
- **`<Bereichsreiter bereiche aktiv onWechsel>`:** Aufbau und Aussehen der AI-Brain-Bereichsauswahl.
  - Karte, je Bereich ein kleiner Knopf: offen gefüllt, sonst umrandet, mit Symbol (Schloss, Globus, Personen, Festplatte).
  - Darunter eine Zeile, was der Bereich ist und ob man bearbeiten darf.
  - Eigenes des Produkts, z. B. „Neuer Space…“, kommt als `children` und steht rechts.
- **`<VerweisGraph api scope onOeffnen>`:** der Graph aus AI Brain (Suche, Zähler, Zoom, Hover-Kasten, Legende), **je Bereich** über `api.verweise(scope)`.
  - Die Daten laufen durch dieselbe Leserecht-Prüfung wie jeder Weg. Einen Graph über mehrere Bereiche gibt es nicht.
  - Braucht `d3`; es kommt als Abhängigkeit mit und bleibt im Bündel extern.

- **`<VaultSeitenkopf titel untertitel>`** (0.5.1): Überschrift und Untertitel der Vault-Seiten wie in AI Brain, ohne Symbol.
  - Vorgaben: „Vault“ mit `VAULT_UNTERTITEL`; auf der Graph-Seite „Graph“ mit `GRAPH_UNTERTITEL`.
  - Rechts steht nur, was das Produkt als `children` mitgibt, etwa den „Assistent“ in AI Brain.

- **Externe Quellen im `VaultBrowser`** (0.6.0): Ist der `scope` eine Quelle (`extern:<slug>`), arbeitet der Browser im Quellen-Modus.
  - **Baum:** wird ebenenweise über `quelle/ebene` geladen; ein Ordner lädt beim Aufklappen nach (`Ordnerbaum.onOrdnerOeffnen`).
  - **Dateien:** Anzeigen, Anlegen, Speichern, Umbenennen, Löschen und Hochladen laufen über `quelle/*`. Der Adapter `fuerQuelle(api)` hält die Schnittstelle gleich.
  - **Nicht erreichbar:** Antwortet die Quelle nicht, steht das als Hinweis da, statt einer leeren Liste.
  - **Ausgeblendet**, weil der Speicher nicht dem Vault gehört: Versionen, Papierkorb, Freigaben, Suche, Vorschaubilder, EuroOffice und PDF-Anmerkungen. Gelöschtes ist dort sofort weg.

- **Slots für Produkte** (0.6.1): alle optional; ohne Belegung verhält sich der Browser wie vorher.
  - `EditorSlot` enthält zusätzlich `scope` und `darfSchreiben`. Damit kann der Editor eines Produkts etwa Bilder selbst einfügen.
  - `renderDateiAktionen({ pfad, scope, darfSchreiben })`: Knöpfe rechts in der Kopfzeile einer Datei, etwa „Großansicht“.
  - `onDateiGeoeffnet(pfad)`: wird je geöffneter Datei einmal gerufen, auch beim Start über `startPfad`. Gedacht für Präsenz.
  - `renderBaumMarke({ pfad, art })`: etwas rechts an jedem Eintrag im Baum, vor dem ⋮, etwa die Köpfe der Bearbeitenden. Gibt es an `Ordnerbaum` und am `VaultBrowser`.
- **Hochladen** (0.6.2, aus AI Brain):
  - Dateien lassen sich auf den Baum ziehen. Sie landen im offenen Ordner, bei offener Datei in deren Ordner. Gezogene Ordner werden übersprungen.
  - „Ordner hochladen“ gibt es oben und im ⋮ eines Ordners. Unterordner bleiben erhalten.
  - Hochgeladen wird nacheinander; der Server legt fehlende Ordner selbst an.
  - Ohne Schreibrecht gibt es weder Ablage noch Knopf.
- **Ergänzungen** (0.7.5, „keine Funktion geht verloren“):
  - **`baumBreiteSchluessel`:** localStorage-Schlüssel, unter dem die Listenbreite als reine Zahl steht. AI Brain gibt `ai-brain-vault-sidebar-w` an, damit die alte Breite bleibt. Ohne Angabe nutzt das Paket einen eigenen Schlüssel. Das 0.7.4-Format gilt nicht mehr; 0.7.4 war nirgends ausgerollt.
  - **Handy, für alle Produkte:**
    - Ist eine Datei offen, steht die Liste eingeklappt darüber („Dateiliste zeigen“).
    - Nach der Wahl einer Datei klappt sie wieder zu.
    - Ohne offene Datei ist sie offen.
    - Der Zustand wird nicht gemerkt.
  - **`autoSpeichern.direkt`** (Vorgabe true):
    - Mit Schreibrecht öffnen passende Dateien sofort im Editor, ohne „Bearbeiten“ und „Verwerfen“. „Speichern“ bleibt als Speichern-sofort.
    - Ohne Schreibrecht nur Ansicht.
  - **Warnung beim Schließen des Tabs**, solange Ungespeichertes da ist; dazu der Status „Speichert…“.
- **Dateiliste und automatisches Speichern** (0.7.4):
  - **Dateiliste:**
    - Auf breiten Bildschirmen lässt sich die Breite am Trenner ziehen (200–600 px, Pfeiltasten ±16, Doppelklick = Vorgabe 288).
    - Der Knopf oben links klappt die Liste ein und wieder auf.
    - Beides merkt sich der Browser je Person (`localStorage`, bei gesperrtem Speicher nur für die Sitzung).
    - Auf dem Handy bleibt alles wie bisher.
    - Das Produkt muss `node_modules/@peppermint-digital/vault/dist` als Tailwind-`@source` einlesen (Klasse `md:w-[var(--vault-liste-breite)]`).
  - **`autoSpeichern={{ dateien?, nachMs? }}`** (Vorgabe aus):
    - Wirkt, während eine passende Datei bearbeitet wird. Vorgabe für `dateien` sind Zeichnungen; erlaubt sind ein Muster oder eine Funktion `(pfad) => boolean`.
    - Gespeichert wird `nachMs` (Vorgabe 2000) nach der letzten Änderung, über denselben Weg wie „Speichern“, mit Version als Basis. Der Editor bleibt offen.
    - Ein Konflikt hält das Speichern an und zeigt den Kasten.
    - Beim Verlassen der Datei wird Offenes sofort gespeichert.
- **Ansichts-Slot mit Navigation und Speichern** (0.7.3, aibrain-e1):
  - `renderAnsicht` bekommt zusätzlich `scope`, `oeffnen(pfad)` und `speichern(inhalt)`, rein additiv.
  - `oeffnen` öffnet eine Datei wie ein Klick im Baum, etwa für Wikilinks.
  - `speichern` nimmt denselben Weg wie der Editor:
    - Basis ist die geladene Version.
    - Danach zeigt die Ansicht den neuen Inhalt.
    - Bei einem Konflikt erscheint der Konfliktkasten, der eigene Stand wird zum Entwurf, und das Promise lehnt ab.
    - Ohne Schreibrecht ist `speichern` `undefined`.
  - Typ: `AnsichtSlot`.
- **Fehlerbehebung** (0.7.2):
  - Dateiansicht und „Verweise hierher“ hatten seit 0.7.0 denselben React-`key`. Bei jedem Dateiwechsel blieb eine alte Ansicht stehen; die Ansichten stapelten sich untereinander. Jetzt hat jede ihren eigenen Schlüssel.
  - `renderPdf` wird auch in Quellen durchgereicht, das Produkt entscheidet, was es dort zeigt.
- **Office in Quellen und CSRF-Rückfall** (0.7.1):
  - **EuroOffice in Quellen:**
    - Vorhandene Office-Dateien öffnen im Editor.
    - Gelesen und gespeichert wird über `Quellen`, also direkt im fremden Speicher, ohne Versionen.
    - Bearbeiten nur mit Schreibrecht, sonst Ansicht.
    - Bricht die Quelle während der Sitzung weg, lehnt der Rückweg ab. Die Datei bleibt beim alten Stand, und es wird nie woanders hingeschrieben.
    - Über der Datei steht ein Hinweis darauf.
    - Neue Office-Dateien legt der Anlegen-Dialog in Quellen nicht an.
  - **CSRF:**
    - `csrfToken` ist optional.
    - Das Paket schickt zusätzlich `X-XSRF-TOKEN` aus dem Cookie `XSRF-TOKEN` mit (Laravel-Standard), `X-CSRF-TOKEN` nur noch mit Wert.
    - Ein Produkt ohne `csrf`-Meta-Tag schreibt damit trotzdem.
- **Rückverweise und Großansicht** (0.7.0, mit AI Brain abgestimmt):
  - **„Verweise hierher“:** Unter einer Markdown-Datei steht, welche Dateien des Bereichs auf sie verweisen; ein Klick öffnet die Datei. Das gilt in jedem Bereich, in Quellen nie. Abschaltbar mit `rueckverweise={false}`.
  - **Großansicht:**
    - Der Knopf rechts in der Kopfzeile der Dateiansicht legt sie über alles (`fixed inset-0`). Esc oder der Knopf schließt sie.
    - `renderDateiAktionen` wird dort mitgezeichnet; der Slot erfährt über `vollbild`, ob er in der Großansicht steht.
    - `onVollbild(offen)` meldet Öffnen und Schließen.
    - Das Auge im Baum („Bildschirmfüllend lesen“) öffnet die Datei direkt groß.
    - Abschaltbar mit `vollbild={false}`.
  - **Transport:** `api.rueckverweise(scope, path)`.
- **Quellen ohne 500** (0.6.5): Anmerkungen, Vorschaubilder und EuroOffice liefen bei Quellen in die Ablage.
  - Die Anmerkungsliste ist bei Quellen leer, Anlegen, Ändern und Entfernen antworten mit 403.
  - Vorschaubilder antworten mit 404.
  - Die Editor-Konfiguration antwortet mit 422 und dem Hinweis, die Datei herunterzuladen.
  - Office in Quellen folgt mit 0.7.x.
- **Quellen** (0.6.4):
  - `Navigation::bereiche()` meldet bei Quellen `erreichbar`, also eingeschaltet und eingehängt. Bei einer nicht erreichbaren Quelle zeigt der `Bereichsreiter` ein Warndreieck und sagt es in der Beschreibungszeile.
  - Der Graph einer Quelle (`GET verweise?scope=extern:…`) antwortet mit 404 „Quellen haben keinen Graphen.“. Bisher kam ein Fehler 500 aus der Ablage.
- **Dateien ohne Ordner** (0.6.3): `vault.hochladen.ohne_ordner` legt fest, wohin eine Datei geht, die ohne Ordner hochgeladen wird. Das betrifft den Knopf oben und das Ziehen, wenn kein Ordner offen ist.
  - Vorgabe ist `_attachments`. So macht es AI Brain: Dort zeigt der Steckbrief-Link des Avatars auf diesen Ordner, und ein hochgeladenes Dokument soll ihn ohne weiteres Zutun finden.
  - Ein Produkt ohne Steckbrief setzt `''`; dann landet die Datei oben im Bereich. Der Manager tut das.
  - Externe Quellen legen ohne Ordner schon immer oben ab.
- **Öffentliche Schiene** (0.6.1):
  - `vault.routen.oeffentlich_an` (Vorgabe `true`) schaltet `vault/geteilt/{token}/…` allein ab.
  - Das Entsperren nutzt die **benannte** Drossel `vault.routen.drossel_entsperren` (Vorgabe `vault-entsperren`). Fehlt der Begrenzer im Produkt, meldet ihn das Paket mit 10 pro Minute je IP an; das wirkt wie das frühere `throttle:10,1`.

## JSON-Schiene

Unter `vault.routen.praefix` (Vorgabe `vault/api`), Middleware `web` + `auth`. Abschalten mit `VAULT_ROUTEN=false`, wenn ein Produkt seine eigenen Controller behält.

Jede Anfrage trägt `?scope=`: `private` steht für den eigenen Bereich, sonst steht dort der Slug eines Raums. Wer nicht lesen darf, bekommt **404** (ein fremder Raum soll nicht erkennbar sein). Wer nicht schreiben darf, bekommt **403**.

| | |
|---|---|
| `GET baum` | Bereich (Label, darfSchreiben) und Baum |
| `GET verweise` | Verweisgraph des Bereichs (`?scope=`), mit `path` die Rückverweise einer Datei |
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
- **Anlegen-Dialog (0.4.0):** Die Art wählt man per Kachel, die Endung steht neben dem Namen. `VaultBrowser` bietet Notiz an, mit `euroOffice` zusätzlich Dokument, Tabelle und Präsentation. Wer `AnlegenDialog` selbst einbaut, gibt `formate` vor (`md`, `html`, `excalidraw`, `docx`, `xlsx`, `pptx`), dazu `vorhanden` (Namen im Zielordner, für den Hinweis vor dem Absenden) und `bereichName`.

## EuroOffice

Je Produkt gibt es einen eigenen Document Server mit eigenem JWT-Geheimnis (F40), eingestellt in `vault.eurooffice.*` (`EUROOFFICE_*`). Fehlt `enabled` oder ist das Geheimnis kürzer als 32 Zeichen, gibt es keinen Editor und keinen Rückweg.

- `GET vault/api/eurooffice/konfiguration?scope=&path=` liefert mit Sitzung die signierte Konfiguration. Bearbeiten und einen Rückweg gibt es nur mit Schreibrecht.
- `GET vault/eurooffice/datei/{token}` und `POST vault/eurooffice/rueckweg/{token}` laufen **ohne Sitzung und ohne CSRF**. Gesichert sind sie über unser eigenes signiertes Token, gedrosselt auf 120 Anfragen pro Minute.
- **Schalter (0.3.5):**
  - `vault.eurooffice.routen` legt die EuroOffice-Routen unabhängig von `vault.routen.an` an. `null` heißt „wie `routen.an`“; das ist die Vorgabe.
  - `vault.eurooffice.drossel` ist die Middleware der Wege ohne Sitzung, als Text oder Liste. Vorgabe ist `throttle:120,1`.
  - `vault.eurooffice.konfiguration.praefix` und `.middleware` gelten für die Konfigurations-Route. `null` heißt „wie `routen.praefix` und `routen.middleware`“.
  - Mit den Vorgaben bleiben Adressen, Namen und Middleware wie bisher.
- Der Rückweg glaubt dem Server nie, wohin er schreiben soll: Das Ziel steht in unserem Token. Die Nutzlast muss signiert sein, und Dateien werden nur vom eigenen Document Server geholt (Schutz gegen SSRF). Eine leere oder fehlgeschlagene Antwort überschreibt nichts.
- **Neu gegenüber AI Brain:** Das Rückweg-Token trägt die Person. Beim Speichern wird über `Vaultrechte` erneut geprüft, ob sie noch schreiben darf.
- Neue `.docx`, `.xlsx` und `.pptx` entstehen als Kopie der leeren Vorlagen in `stubs/office/`. Die Vorlagen sind mit LibreOffice erzeugt und enthalten keinen Autor.

### EuroOffice installieren (Standardweg, 0.4.1)

Der Document Server läuft **auf demselben Server wie das Produkt, nur auf `127.0.0.1`**. Der Browser erreicht ihn über einen Pfad der eigenen Domain, `<APP_URL>/eurooffice/`. Es gibt keine eigene Domain, kein zweites Zertifikat und kein VPN dazwischen. Die Vorlagen liegen in `stubs/eurooffice/`.

```
Browser ──https──► <APP_URL>/eurooffice/…    (nginx der Site)  ──► 127.0.0.1:8099 (Container)
Produkt ──http───► 127.0.0.1:8099            (EUROOFFICE_INTERNAL_URL)
Document Server ──https──► <APP_URL>/vault/eurooffice/…   (Datei holen, Rückweg; per Token)
```

1. **Container** (Docker mit Compose-Plugin, Root):

   ```bash
   install -d -m 0750 /opt/eurooffice
   cp stubs/eurooffice/compose.yml /opt/eurooffice/
   umask 077; printf 'JWT_SECRET=%s\n' "$(openssl rand -hex 32)" > /opt/eurooffice/.env
   docker compose -f /opt/eurooffice/compose.yml --env-file /opt/eurooffice/.env up -d
   curl -fsS http://127.0.0.1:8099/healthcheck     # true
   ```

2. **nginx** der Site:
   - `stubs/eurooffice/nginx-forge-before.conf` in den http-Kontext, bei Forge nach `forge-conf/<site-id>/before/`.
   - `nginx-forge-server.conf` in den `server`-Block, bei Forge nach `forge-conf/<site-id>/server/`. Forge überschreibt beide Ordner nicht.
   - Danach `nginx -t && systemctl reload nginx`.

3. **Produkt** (`.env`):

   ```dotenv
   EUROOFFICE_ENABLED=true
   EUROOFFICE_PUBLIC_URL="${APP_URL}/eurooffice"
   EUROOFFICE_INTERNAL_URL=http://127.0.0.1:8099
   EUROOFFICE_JWT_SECRET=<derselbe Wert wie JWT_SECRET oben>
   ```

   Danach `php artisan optimize` und PHP-FPM neu laden; bei Octane `octane:reload`.

**Worauf es ankommt:**
- **Port nur an `127.0.0.1`.** Docker trägt veröffentlichte Ports an der Firewall vorbei ein.
- **Image per Digest**, nicht `:latest`. Ein Update ist ein bewusster Tausch des Digests, mit einer Probe danach.
- **`location ^~ /eurooffice/`.** Ohne `^~` gewinnen die Regex-Locations der Site (`\.php$`).
- **`X-Forwarded-Prefix /eurooffice`.** Der Document Server baut die Adressen für den Browser aus der Anfrage. Ohne Präfix lädt der Editor und meldet dann „Herunterladen ist fehlgeschlagen“ (AI Brain, Learning #4031).
- **WebSocket-Upgrade** über die `map` aus `nginx-forge-before.conf`.
- **Wer durchreicht, darf nicht mit der Anwendung neu starten.**
  - Bei nginx vor PHP-FPM (Forge) ist das so: Ein Deploy lädt nur PHP-FPM neu, die Editor-Verbindung bleibt.
  - Läuft das Produkt unter Octane/FrankenPHP und reicht dessen eigener Caddy den Pfad durch, reißt jeder Neustart des Dienstes offene Bearbeitungen mit. So war es in AI Brain am 24.09.2026, Commit 88b9244b. Dann gehört die Durchreichung in einen Webserver **vor** der Anwendung.
  - Ob ein `octane:reload` die Verbindung ebenfalls trennt, ist nicht gemessen.
- **Datei-Adressen beim Rückweg (0.4.1):**
  - Teilt sich der Document Server den Host mit dem Produkt, nimmt der Rückweg nur Adressen **unterhalb** von `EUROOFFICE_PUBLIC_URL` an, also `/eurooffice/…`, nicht irgendeine Seite des Produkts.
  - Geholt wird über `EUROOFFICE_INTERNAL_URL` (127.0.0.1), nicht über die eigene Domain.
- **Prüfen:** im Browser die Netzwerkliste des Editors. `cache/files/…` und `Editor.bin` müssen unter `<APP_URL>/eurooffice/` mit 200 kommen, der WebSocket mit 101. Die Server-Logs sehen auch dann grün aus, wenn das nicht stimmt.

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
6. **Versionen im Papierkorb (0.4.0, #10293):**
   - **Löschen:** Die Versionen gehen mit in den Papierkorb (Pfad mit Präfix `\0papierkorb/<eintrag>/`). Eine neue Datei am alten Ort beginnt mit leerer Geschichte; in AI Brain erbte sie die der gelöschten.
   - **Zurückholen:** Die Versionen kommen an den Ort, an dem die Datei wirklich landet, auch bei `-wiederhergestellt`.
   - **Endgültig löschen** (auch über `vault:papierkorb-leeren`) entfernt die Versionen samt abgelegter Dateien. Fehlt der Inhalt im Papierkorb, bleiben sie stehen, denn dann sind sie womöglich die einzige Kopie.
   - Leere Papierkorb-Ordner werden entfernt.
   - Eine leere neue Datei bekommt keine leere Version.
   - **Bestand vor 0.4.0:** `vault:versionen-aufraeumen --trocken` zählt verwaiste Versionen und leere Papierkorb-Ordner. Es gibt nur Zahlen aus, keine Pfade, und schreibt nichts.

## Tests

```bash
composer test                     # Pint + Pest (SQLite)
npm test && npm run typecheck     # vitest (jsdom) + tsc
VAULT_MYSQL_HOST=… vendor/bin/pest tests/Mysql   # gegen ein Wegwerf-MySQL
```
