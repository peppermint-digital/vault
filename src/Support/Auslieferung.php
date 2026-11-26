<?php

namespace Peppermint\Vault\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Kopfzeilen fuer die Rohauslieferung einer Vault-Datei (AI Brain
 * `VaultController::raw`, `VaultHtmlPolicy`, Bug #951, #635, #6294).
 *
 * HTML aus dem Vault laeuft in der Origin des Produkts. Ohne die
 * Content-Security-Policy koennte ein Skript darin die API des Produkts mit
 * der Sitzung des Betrachters aufrufen — `connect-src` laesst nur die beiden
 * CDNs zu, `form-action` nichts.
 */
final class Auslieferung
{
    private const RICHTLINIE = [
        "default-src 'none'",
        "base-uri 'self'",
        "form-action 'none'",
        "frame-ancestors 'self'",
        "script-src 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdn.jsdelivr.net",
        "style-src 'unsafe-inline' 'self' https://unpkg.com https://fonts.googleapis.com",
        'font-src https://fonts.gstatic.com data:',
        "img-src 'self' data: blob: https://unpkg.com https://*.basemaps.cartocdn.com https://*.tile.openstreetmap.org",
        'connect-src https://unpkg.com https://cdn.jsdelivr.net',
    ];

    private const TYPEN = [
        'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'md' => 'text/markdown; charset=UTF-8', 'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8', 'excalidraw' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'epub' => 'application/epub+zip',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint', 'rtf' => 'application/rtf',
    ];

    public static function richtlinie(): string
    {
        return implode('; ', self::RICHTLINIE);
    }

    public static function typ(string $pfad): string
    {
        return self::TYPEN[strtolower(pathinfo($pfad, PATHINFO_EXTENSION))] ?? 'text/plain; charset=UTF-8';
    }

    /** @return array<string, string> */
    public static function kopf(string $pfad, bool $herunterladen): array
    {
        $typ = self::typ($pfad);
        $kopf = [
            'Content-Type' => $typ,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ];

        if (str_starts_with($typ, 'text/html')) {
            $kopf['Content-Security-Policy'] = self::richtlinie();
        }

        if ($herunterladen) {
            $name = basename($pfad);
            // Der Rueckfall muss reines ASCII ohne % und Schraegstriche sein, sonst wirft makeDisposition.
            $rueckfall = str_replace(['%', '/', '\\'], '_', (string) preg_replace('/[^\x20-\x7E]/u', '_', $name));
            $kopf['Content-Disposition'] = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $rueckfall);
        }

        return $kopf;
    }
}
