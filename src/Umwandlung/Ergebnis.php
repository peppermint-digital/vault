<?php

namespace Peppermint\Vault\Umwandlung;

/**
 * Markdown plus die Merkmale, die sich nicht abbilden liessen — als Zaehler,
 * ohne Inhalt (fuer das Protokoll der Migration).
 */
final class Ergebnis
{
    /** @param  array<string, int>  $verluste */
    public function __construct(
        public readonly string $markdown,
        public readonly array $verluste,
    ) {}

    public function verlustfrei(): bool
    {
        return $this->verluste === [];
    }
}
