<?php

namespace Peppermint\Vault\EuroOffice;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Spricht den Euro-Office Document Server an (#6216).
 *
 * Bauart wie OnlyOffice: Der Browser lädt `api.js` und redet direkt mit dem
 * Document Server; der Server ruft unseren Callback auf, um die bearbeitete
 * Datei zurückzugeben. Beide Richtungen sind über ein gemeinsames JWT-Geheimnis
 * abgesichert.
 *
 * Wirft NIE bei Netzwerkfehlern — ein nicht erreichbarer Editor darf den Vault
 * nicht mitreißen. Fehlende Konfiguration ist dagegen ein Programmierfehler und
 * wird beim Signieren/Prüfen hart abgelehnt, nicht stillschweigend übergangen.
 *
 * Aus AI Brain `App\Services\EuroOfficeClient` (Stand 118ee2b2), unveraendert
 * bis auf den Konfigurationsschluessel `vault.eurooffice.*`. `firebase/php-jwt`
 * steht im Paket ausdruecklich in `require`.
 */
class Client
{
    /** Signatur-Verfahren des Document Servers (OnlyOffice-Bauart). */
    private const ALGO = 'HS256';

    /**
     * HS256 verlangt einen Schlüssel von mindestens 256 Bit. Die JWT-Bibliothek
     * lehnt kürzere mit einer nackten DomainException ab — und zwar erst beim
     * ersten echten Speichervorgang, also lange nach dem Einrichten. Wir prüfen
     * es deshalb vorne und sagen, was fehlt.
     */
    private const MIN_SECRET_LENGTH = 32;

    public function isEnabled(): bool
    {
        return (bool) config('vault.eurooffice.enabled');
    }

    /**
     * Vollständig konfiguriert = eingeschaltet, Adresse da, Geheimnis da.
     * Ohne Geheimnis wäre der Callback offen — das zählt NICHT als konfiguriert.
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && $this->publicUrl() !== ''
            && $this->secretIsUsable();
    }

    /** Vorhanden UND lang genug für HS256. */
    private function secretIsUsable(): bool
    {
        return strlen($this->jwtSecret()) >= self::MIN_SECRET_LENGTH;
    }

    /** Adresse, die der BROWSER des Nutzers erreichen muss. */
    public function publicUrl(): string
    {
        return (string) config('vault.eurooffice.public_url', '');
    }

    /** Adresse, die DIESER Server erreicht. Leer konfiguriert = public_url. */
    public function internalUrl(): string
    {
        $internal = (string) config('vault.eurooffice.internal_url', '');

        return $internal !== '' ? $internal : $this->publicUrl();
    }

    /** Unsere eigene Adresse, wie der Document Server sie erreicht. */
    public function callbackBaseUrl(): string
    {
        return (string) config('vault.eurooffice.callback_base_url', '');
    }

    /** Das Skript, das die Editor-Seite einbinden muss. */
    public function apiJsUrl(): string
    {
        return $this->publicUrl().'/web-apps/apps/api/documents/api.js';
    }

    public function jwtHeader(): string
    {
        return (string) config('vault.eurooffice.jwt_header', 'Authorization');
    }

    /**
     * Prüft die Wege EINZELN, statt nur "geht/geht nicht" zu melden.
     *
     * Die häufigste Störung ist nicht ein toter Dienst, sondern ein Weg, der
     * in genau eine Richtung fehlt — dann öffnet sich ein weißer Editor und
     * niemand weiß, warum. Diese Aufschlüsselung ist die Diagnose dafür.
     *
     * @return array{configured: bool, reachable: bool, healthy: bool, detail: string}
     */
    public function health(): array
    {
        if (! $this->isEnabled()) {
            return $this->result(false, false, false, 'Euro-Office ist ausgeschaltet (EUROOFFICE_ENABLED).');
        }

        if ($this->publicUrl() === '') {
            return $this->result(false, false, false, 'Keine Adresse konfiguriert (EUROOFFICE_PUBLIC_URL).');
        }

        if ($this->jwtSecret() === '') {
            return $this->result(false, false, false, 'Kein JWT-Geheimnis konfiguriert (EUROOFFICE_JWT_SECRET) — der Callback waere ungeschuetzt.');
        }

        if (! $this->secretIsUsable()) {
            return $this->result(false, false, false, sprintf(
                'JWT-Geheimnis zu kurz: %d Zeichen, HS256 braucht mindestens %d.',
                strlen($this->jwtSecret()),
                self::MIN_SECRET_LENGTH,
            ));
        }

        if ($this->callbackBaseUrl() === '') {
            return $this->result(true, false, false, 'Keine Rueckruf-Adresse konfiguriert (EUROOFFICE_CALLBACK_BASE_URL) — der Document Server koennte uns nicht erreichen.');
        }

        try {
            $response = Http::timeout($this->timeout())
                ->withoutVerifying()
                ->get($this->internalUrl().'/healthcheck');
        } catch (\Throwable $e) {
            Log::warning('vault EuroOffice: Document Server nicht erreichbar', [
                'url' => $this->internalUrl(),
                'error' => $e->getMessage(),
            ]);

            return $this->result(true, false, false, 'Document Server nicht erreichbar: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return $this->result(true, true, false, 'Document Server antwortet mit HTTP '.$response->status().'.');
        }

        // Der Healthcheck liefert wörtlich "true" — alles andere heisst: läuft,
        // ist aber nicht bereit.
        $healthy = trim($response->body()) === 'true';

        return $this->result(
            true,
            true,
            $healthy,
            $healthy ? 'Erreichbar und bereit.' : 'Erreichbar, meldet aber nicht "true": '.mb_substr(trim($response->body()), 0, 120),
        );
    }

    /**
     * Signiert eine Editor-Konfiguration für den Document Server.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $secret = $this->requireSecret();

        return JWT::encode($payload, $secret, self::ALGO);
    }

    /**
     * Prüft ein Token des Document Servers (Callback-Absicherung).
     *
     * Gibt null zurück, wenn die Signatur nicht stimmt — der Aufrufer MUSS das
     * als Ablehnung behandeln und darf keine Datei schreiben.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $secret = $this->requireSecret();

        try {
            $decoded = JWT::decode($token, new Key($secret, self::ALGO));
        } catch (\Throwable $e) {
            Log::warning('vault EuroOffice: Token abgelehnt', ['error' => $e->getMessage()]);

            return null;
        }

        return json_decode(json_encode($decoded) ?: '[]', true) ?: [];
    }

    /**
     * Stellt UNSER eigenes Dokument-Token aus (#6218).
     *
     * Der springende Punkt für die Sicherheit: Der Callback darf dem Document
     * Server NICHT glauben, in welche Datei er schreiben soll. Stünde der Pfad
     * in dessen Nutzlast, könnte jeder, der den Endpunkt kennt, eine beliebige
     * Datei im Vault überschreiben.
     *
     * Deshalb steckt das Ziel in einem Token, das WIR beim Öffnen des Editors
     * signieren und das in der Rückruf-Adresse mitläuft. Der Document Server
     * reicht es nur durch und kann es nicht verändern.
     *
     * @param  string  $scope  "user:{id}" oder "area:{key}"
     */
    public function issueDocumentToken(string $scope, string $relativePath, int $ttlMinutes = 720): string
    {
        return $this->sign([
            'scope' => $scope,
            'path' => $relativePath,
            'iat' => time(),
            // Ein Editor-Fenster kann lange offen stehen; ohne Ablauf bliebe
            // das Token aber unbegrenzt gültig.
            'exp' => time() + max(1, $ttlMinutes) * 60,
        ]);
    }

    /**
     * Liest unser eigenes Dokument-Token zurück.
     *
     * @return array{scope: string, path: string}|null null = ungültig, abgelaufen oder verfälscht
     */
    public function readDocumentToken(string $token): ?array
    {
        $claims = $this->verify($token);

        if ($claims === null) {
            return null;
        }

        $scope = (string) ($claims['scope'] ?? '');
        $path = (string) ($claims['path'] ?? '');

        if ($scope === '' || $path === '') {
            return null;
        }

        return ['scope' => $scope, 'path' => $path];
    }

    /**
     * Zeigt die Adresse wirklich auf UNSEREN Document Server?
     *
     * Der Callback bekommt eine Adresse genannt, von der er die bearbeitete
     * Datei holen soll. Ohne diese Prüfung liesse sich der Server dazu bringen,
     * beliebige interne Adressen abzurufen und deren Inhalt in den Vault zu
     * schreiben — der Klassiker einer serverseitigen Anfragefälschung.
     */
    public function isOwnDocumentServerUrl(string $url): bool
    {
        $target = parse_url($url);

        if ($target === false || ! isset($target['host'])) {
            return false;
        }

        foreach ([$this->internalUrl(), $this->publicUrl()] as $known) {
            $allowed = parse_url($known);

            if (! is_array($allowed) || ! isset($allowed['host'])) {
                continue;
            }

            $samePort = ($target['port'] ?? null) === ($allowed['port'] ?? null);

            if (strcasecmp($target['host'], $allowed['host']) === 0 && $samePort && $this->unterPfad($target['path'] ?? '/', $allowed['path'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laeuft der Document Server unter einem Pfad der eigenen Domain
     * (`<APP_URL>/eurooffice`, 0.4.1), teilt er sich den Host mit dem Produkt.
     * Dann reicht der Host-Vergleich nicht: Jede Adresse des Produkts saehe
     * wie „unser Document Server" aus. Hat die bekannte Adresse einen Pfad,
     * muss die Datei-Adresse darunter liegen.
     */
    private function unterPfad(string $pfad, string $basis): bool
    {
        $basis = rtrim($basis, '/');

        return $basis === '' || str_starts_with($pfad, $basis.'/');
    }

    /**
     * Woher DIESER Server die bearbeitete Datei holt: Nennt der Document
     * Server seine oeffentliche Adresse (`https://manager.example/eurooffice/cache/...`),
     * geht der Abruf ueber `internal_url` (`http://127.0.0.1:8099/cache/...`) —
     * ohne Umweg ueber die eigene Domain und ihr Zertifikat. Ohne eigene
     * interne Adresse bleibt die Adresse, wie sie ist.
     */
    public function abrufAdresse(string $url): string
    {
        $oeffentlich = rtrim($this->publicUrl(), '/');
        $intern = rtrim((string) config('vault.eurooffice.internal_url', ''), '/');

        if ($oeffentlich === '' || $intern === '' || ! str_starts_with($url, $oeffentlich.'/')) {
            return $url;
        }

        return $intern.substr($url, strlen($oeffentlich));
    }

    /**
     * Holt das Token aus dem konfigurierten Header ("Bearer x" oder nacktes x).
     */
    public function tokenFromHeader(?string $headerValue): ?string
    {
        $value = trim((string) $headerValue);

        if ($value === '') {
            return null;
        }

        if (stripos($value, 'Bearer ') === 0) {
            $value = trim(substr($value, 7));
        }

        return $value !== '' ? $value : null;
    }

    private function jwtSecret(): string
    {
        return (string) config('vault.eurooffice.jwt_secret', '');
    }

    private function requireSecret(): string
    {
        $secret = $this->jwtSecret();

        if ($secret === '') {
            throw new \RuntimeException('Euro-Office: EUROOFFICE_JWT_SECRET ist nicht gesetzt.');
        }

        if (! $this->secretIsUsable()) {
            throw new \RuntimeException(sprintf(
                'Euro-Office: EUROOFFICE_JWT_SECRET ist zu kurz (%d Zeichen, HS256 braucht mindestens %d).',
                strlen($secret),
                self::MIN_SECRET_LENGTH,
            ));
        }

        return $secret;
    }

    private function timeout(): int
    {
        return max(1, (int) config('vault.eurooffice.timeout_seconds', 15));
    }

    /**
     * @return array{configured: bool, reachable: bool, healthy: bool, detail: string}
     */
    private function result(bool $configured, bool $reachable, bool $healthy, string $detail): array
    {
        return [
            'configured' => $configured,
            'reachable' => $reachable,
            'healthy' => $healthy,
            'detail' => $detail,
        ];
    }
}
