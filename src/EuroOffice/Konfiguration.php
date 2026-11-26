<?php

namespace Peppermint\Vault\EuroOffice;

use Illuminate\Contracts\Auth\Authenticatable;
use Peppermint\Vault\Ablage;
use Peppermint\Vault\Bereich;

/**
 * Baut die signierte Editor-Konfiguration (AI Brain `EuroOfficeConfigBuilder`, #6245).
 *
 * Ob jemand die Datei sehen oder bearbeiten darf, entscheidet der Aufrufer —
 * er kennt seinen Bereich. Hier haengt die Sicherheit an der Konfiguration
 * selbst: `permissions.edit`, die Abholadresse und der Rueckweg. Die Signatur
 * deckt die GANZE Konfiguration ab, sonst liesse sich `edit` unterwegs drehen.
 */
class Konfiguration
{
    /** Das Datei-Token gilt nur fuer den einen Abruf beim Oeffnen. */
    private const DATEI_TOKEN_MINUTEN = 10;

    public function __construct(
        private readonly Client $client,
        private readonly Ablage $ablage,
    ) {}

    /** @return array{apiJsUrl: string, config: array<string, mixed>} */
    public function bauen(Bereich $bereich, string $pfad, Authenticatable $user, bool $darfBearbeiten): array
    {
        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
        $absolut = $this->ablage->aufloesen($bereich, $pfad);

        $konfiguration = [
            'documentType' => Dateiart::DOKUMENTTYPEN[$endung],
            'document' => [
                'fileType' => $endung,
                // Muss sich mit dem Inhalt aendern, sonst liefert der Document
                // Server seinen zwischengespeicherten Stand.
                'key' => $this->schluessel($bereich, $pfad, $absolut),
                'title' => basename($pfad),
                'url' => $this->basis().'/datei/'.$this->client->issueDocumentToken($bereich->scope(), $pfad, self::DATEI_TOKEN_MINUTEN),
                'permissions' => ['edit' => $darfBearbeiten, 'download' => true],
            ],
            'editorConfig' => [
                'mode' => $darfBearbeiten ? 'edit' : 'view',
                'lang' => 'de',
                'user' => [
                    'id' => (string) $user->getAuthIdentifier(),
                    'name' => (string) ($user->name ?? 'Unbekannt'),
                ],
            ],
        ];

        // Ohne Schreibrecht kein Rueckweg: Der Server soll es gar nicht erst versuchen.
        if ($darfBearbeiten) {
            // Das Token traegt die Person: Der Rueckweg prueft beim Speichern, ob sie
            // noch schreiben darf (zwoelf Stunden sind lang genug, um aus einem Raum
            // genommen zu werden).
            $konfiguration['editorConfig']['callbackUrl'] = $this->basis().'/rueckweg/'.$this->client->sign([
                'scope' => $bereich->scope(),
                'path' => $pfad,
                'user' => (string) $user->getAuthIdentifier(),
                'iat' => time(),
                'exp' => time() + 720 * 60,
            ]);
        }

        $konfiguration['token'] = $this->client->sign($konfiguration);

        return ['apiJsUrl' => $this->client->apiJsUrl(), 'config' => $konfiguration];
    }

    /** Ueber `callback_base_url`, nicht route(): Der Server sieht uns unter Umstaenden anders als der Browser. */
    private function basis(): string
    {
        return rtrim($this->client->callbackBaseUrl(), '/').'/'.trim((string) config('vault.eurooffice.praefix', 'vault/eurooffice'), '/');
    }

    /** Groesse und Aenderungszeit reichen und sind billig. */
    private function schluessel(Bereich $bereich, string $pfad, string $absolut): string
    {
        $stempel = is_file($absolut) ? filemtime($absolut).'-'.filesize($absolut) : 'neu';

        return substr(hash('sha256', $bereich->scope().'|'.$pfad.'|'.$stempel), 0, 32);
    }
}
