<?php

namespace Peppermint\Vault\Umwandlung;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * HTML aus Tiptap (Manager-Notizen, Tagesnotizen, CRM) → GitHub-Markdown.
 *
 * Grundlage ist die Zaehlung vom 26.11.2026 auf pm (52 Notizen, 86
 * Tagesnotizen). Entscheidung F50 (Bastian, „alle deine Empfehlung"):
 *
 * - `<u>` wird fett — Markdown kennt keine Unterstreichung;
 * - `<ol type="a">` wird normal nummeriert;
 * - leere Absaetze (Abstand in Tiptap) fallen weg;
 * - Editor-Klassen an Links (`a@class`) fallen weg, sie tragen nichts.
 *
 * Das Original-HTML ist NICHT Sache dieses Wandlers: Die Migration legt es
 * als Fassung 1 in die Versionen, damit nichts verloren geht. Dieser Wandler
 * meldet nur, WAS er nicht abbilden konnte ({@see Ergebnis::$verluste}) —
 * als Zaehler je Merkmal, ohne Inhalt.
 */
class HtmlZuMarkdown
{
    /** Attribute, die ohne Verlust wegfallen (Tiptap setzt sie selbst). */
    private const HARMLOS = ['a' => ['class', 'target', 'rel'], 'p' => ['class'], 'span' => ['class']];

    /** @var array<string, int> */
    private array $verluste = [];

    public function wandeln(?string $html): Ergebnis
    {
        $this->verluste = [];
        $text = trim((string) $html);

        if ($text === '' || $text === '<p></p>') {
            return new Ergebnis('', []);
        }

        // Klartext (keine Tags) bleibt, wie er ist.
        if (! preg_match('/<[a-z][^>]*>/i', $text)) {
            return new Ergebnis($text."\n", []);
        }

        $dokument = new DOMDocument;
        $alt = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8"?><body>'.$text.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);

        $body = $dokument->getElementsByTagName('body')->item(0);
        $markdown = $body === null ? '' : $this->bloecke($body, 0);

        $markdown = preg_replace("/\n{3,}/", "\n\n", trim($markdown)) ?? trim($markdown);

        return new Ergebnis($markdown === '' ? '' : $markdown."\n", $this->verluste);
    }

    /** Folge von Bloecken, getrennt durch Leerzeilen. */
    private function bloecke(DOMNode $eltern, int $tiefe): string
    {
        $teile = [];
        $inline = '';

        foreach ($eltern->childNodes as $kind) {
            if ($kind instanceof DOMElement && $this->istBlock($kind)) {
                if (trim($inline) !== '') {
                    $teile[] = trim($inline);
                }
                $inline = '';
                $block = $this->block($kind, $tiefe);
                if (trim($block) !== '') {
                    $teile[] = rtrim($block);
                }
            } else {
                $inline .= $this->inline($kind);
            }
        }

        if (trim($inline) !== '') {
            $teile[] = trim($inline);
        }

        return implode("\n\n", $teile);
    }

    private function block(DOMElement $el, int $tiefe): string
    {
        $tag = strtolower($el->nodeName);
        $this->attributePruefen($el);

        return match ($tag) {
            'p', 'div' => $this->absatz($el),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => str_repeat('#', (int) $tag[1]).' '.$this->zeile($this->kinderInline($el)),
            'ul', 'ol' => $this->liste($el, $tiefe),
            'blockquote' => $this->zitat($el, $tiefe),
            'pre' => $this->code($el),
            'hr' => '---',
            'table' => $this->tabelle($el),
            default => $this->unbekannterBlock($el, $tiefe),
        };
    }

    private function absatz(DOMElement $el): string
    {
        $inhalt = trim($this->kinderInline($el));

        // Leerer Absatz = Abstand in Tiptap. Faellt weg (F50/V2).
        if ($inhalt === '' || $inhalt === '\\') {
            return '';
        }

        return $this->zeilenanfang($inhalt);
    }

    private function liste(DOMElement $el, int $tiefe): string
    {
        $geordnet = strtolower($el->nodeName) === 'ol';
        $nummer = $geordnet && ctype_digit($el->getAttribute('start')) ? (int) $el->getAttribute('start') : 1;
        $zeilen = [];

        foreach ($el->childNodes as $kind) {
            if (! $kind instanceof DOMElement || strtolower($kind->nodeName) !== 'li') {
                continue;
            }

            $this->attributePruefen($kind);
            $marke = $geordnet ? $nummer.'. ' : '- ';
            $nummer++;
            $einzug = str_repeat(' ', strlen($marke));

            $inhalt = $this->bloecke($kind, $tiefe + 1);
            $erste = true;
            $absatzzeilen = [];
            foreach (explode("\n", $inhalt) as $zeile) {
                $absatzzeilen[] = $erste ? $marke.$zeile : ($zeile === '' ? '' : $einzug.$zeile);
                $erste = false;
            }
            $zeilen[] = implode("\n", $absatzzeilen);
        }

        // Absaetze innerhalb eines Punkts brauchen eine Leerzeile, eng gefuehrte Listen nicht.
        $locker = (bool) array_filter($zeilen, fn ($z) => str_contains($z, "\n\n"));

        return implode($locker ? "\n\n" : "\n", $zeilen);
    }

    private function zitat(DOMElement $el, int $tiefe): string
    {
        $inhalt = $this->bloecke($el, $tiefe);

        return implode("\n", array_map(fn ($z) => $z === '' ? '>' : '> '.$z, explode("\n", $inhalt)));
    }

    private function code(DOMElement $el): string
    {
        $code = $el->textContent;
        $sprache = '';
        foreach ($el->childNodes as $kind) {
            if ($kind instanceof DOMElement && strtolower($kind->nodeName) === 'code'
                && preg_match('/language-([\w+-]+)/', $kind->getAttribute('class'), $m)) {
                $sprache = $m[1];
            }
        }

        $zaun = str_contains($code, '```') ? '~~~' : '```';

        return $zaun.$sprache."\n".rtrim($code, "\n")."\n".$zaun;
    }

    private function tabelle(DOMElement $el): string
    {
        $zeilen = [];
        foreach ($el->getElementsByTagName('tr') as $tr) {
            $zellen = [];
            foreach ($tr->childNodes as $zelle) {
                if ($zelle instanceof DOMElement && in_array(strtolower($zelle->nodeName), ['td', 'th'], true)) {
                    foreach ($zelle->childNodes as $c) {
                        if ($c instanceof DOMElement && in_array(strtolower($c->nodeName), ['ul', 'ol', 'table', 'pre', 'blockquote'], true)) {
                            $this->verlust('tabelle_block_in_zelle');
                        }
                    }
                    $text = trim(preg_replace('/\s*\n+\s*/', ' ', $this->zellinhalt($zelle)) ?? '');
                    $zellen[] = str_replace('|', '\|', $text);
                }
            }
            if ($zellen !== []) {
                $zeilen[] = $zellen;
            }
        }

        if ($zeilen === []) {
            return '';
        }

        $breite = max(array_map('count', $zeilen));
        $raus = [];
        foreach ($zeilen as $i => $zellen) {
            $zellen = array_pad($zellen, $breite, '');
            $raus[] = '| '.implode(' | ', $zellen).' |';
            if ($i === 0) {
                $raus[] = '|'.str_repeat(' --- |', $breite);
            }
        }

        return implode("\n", $raus);
    }

    private function zellinhalt(DOMElement $zelle): string
    {
        $teile = [];
        foreach ($zelle->childNodes as $kind) {
            $teile[] = $kind instanceof DOMElement && $this->istBlock($kind)
                ? $this->kinderInline($kind)
                : $this->inline($kind);
        }

        return implode(' ', array_filter(array_map('trim', $teile), fn ($t) => $t !== ''));
    }

    private function unbekannterBlock(DOMElement $el, int $tiefe): string
    {
        $this->verlust('tag:'.strtolower($el->nodeName));

        return $this->bloecke($el, $tiefe);
    }

    // ---- Inline ---------------------------------------------------------

    private function kinderInline(DOMNode $el): string
    {
        $raus = '';
        foreach ($el->childNodes as $kind) {
            $raus .= $this->inline($kind);
        }

        return $raus;
    }

    private function inline(DOMNode $knoten): string
    {
        if ($knoten instanceof DOMText) {
            return $this->maskieren(preg_replace('/\s+/u', ' ', $knoten->nodeValue ?? '') ?? '');
        }

        if (! $knoten instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($knoten->nodeName);
        $this->attributePruefen($knoten);

        return match ($tag) {
            'strong', 'b' => $this->umschliessen('**', $this->kinderInline($knoten)),
            'u' => $this->unterstrichen($knoten),
            'em', 'i' => $this->umschliessen('*', $this->kinderInline($knoten)),
            's', 'del', 'strike' => $this->umschliessen('~~', $this->kinderInline($knoten)),
            'code' => $this->inlineCode($knoten->textContent),
            'br' => "\\\n",
            'a' => $this->verweis($knoten),
            'img' => $this->bild($knoten),
            'span' => $this->kinderInline($knoten),
            'mark' => $this->mitVerlust('tag:mark', $this->kinderInline($knoten)),
            'sub', 'sup' => $this->mitVerlust('tag:'.$tag, $this->kinderInline($knoten)),
            default => $this->istBlock($knoten)
                ? ' '.$this->kinderInline($knoten).' '
                : $this->mitVerlust('tag:'.$tag, $this->kinderInline($knoten)),
        };
    }

    private function unterstrichen(DOMElement $el): string
    {
        $this->verlust('unterstrichen_als_fett');

        // Schon fett (<strong><u>…</u></strong>): nicht doppelt.
        $eltern = $el->parentNode;
        while ($eltern instanceof DOMElement) {
            if (in_array(strtolower($eltern->nodeName), ['strong', 'b'], true)) {
                return $this->kinderInline($el);
            }
            $eltern = $eltern->parentNode;
        }

        return $this->umschliessen('**', $this->kinderInline($el));
    }

    /** Markierung um den Text, Leerraum am Rand bleibt aussen (`** a**` waere kein Fett). */
    private function umschliessen(string $zeichen, string $inhalt): string
    {
        if (trim($inhalt) === '') {
            return $inhalt;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/su', $inhalt, $m);

        return $m[1].$zeichen.$m[2].$zeichen.$m[3];
    }

    private function inlineCode(string $code): string
    {
        $zaun = str_contains($code, '`') ? '``' : '`';
        $rand = str_starts_with($code, '`') || str_ends_with($code, '`') ? ' ' : '';

        return $zaun.$rand.$code.$rand.$zaun;
    }

    private function verweis(DOMElement $a): string
    {
        $text = $this->kinderInline($a);
        $ziel = trim($a->getAttribute('href'));

        if ($ziel === '') {
            return $text;
        }

        $ziel = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $ziel);
        $titel = $a->getAttribute('title');
        $titel = $titel !== '' ? ' "'.str_replace('"', '\"', $titel).'"' : '';

        // Autolink, wenn Text und Ziel gleich sind.
        if ($titel === '' && trim(str_replace('\\', '', $text)) === $a->getAttribute('href') && preg_match('#^https?://#', $ziel)) {
            return '<'.$ziel.'>';
        }

        return '['.(trim($text) === '' ? $ziel : $text).']('.$ziel.$titel.')';
    }

    private function bild(DOMElement $img): string
    {
        $quelle = trim($img->getAttribute('src'));
        if ($quelle === '') {
            return '';
        }

        if (str_starts_with($quelle, 'data:')) {
            $this->verlust('bild_eingebettet');
        }

        $titel = $img->getAttribute('title');

        return '!['.str_replace(['[', ']'], ['\[', '\]'], $img->getAttribute('alt')).']('
            .str_replace(' ', '%20', $quelle)
            .($titel !== '' ? ' "'.str_replace('"', '\"', $titel).'"' : '').')';
    }

    // ---- Hilfen ---------------------------------------------------------

    private function istBlock(DOMElement $el): bool
    {
        return in_array(strtolower($el->nodeName), [
            'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'pre', 'hr', 'table',
            'section', 'article', 'header', 'footer', 'figure', 'li', 'thead', 'tbody', 'tr', 'td', 'th',
        ], true);
    }

    /** Zeichen, die Markdown sonst als Auszeichnung liest. */
    private function maskieren(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]<>~])/', '\\\\$1', $text) ?? $text;
    }

    /** Am Zeilenanfang: `#`, `-`, `+`, `1.` und `>` wuerden zu Ueberschrift, Liste oder Zitat. */
    private function zeilenanfang(string $text): string
    {
        return implode("\n", array_map(function (string $zeile): string {
            if (preg_match('/^(\s*)(#{1,6}\s|[-+]\s|\d+[.)]\s|=+\s*$|-{3,}\s*$)/', $zeile, $m)) {
                $rest = substr($zeile, strlen($m[1]));

                return $m[1].(ctype_digit($rest[0]) ? preg_replace('/^(\d+)([.)])/', '$1\\\\$2', $rest) : '\\'.$rest);
            }

            return $zeile;
        }, explode("\n", $text)));
    }

    private function zeile(string $text): string
    {
        return trim(str_replace("\\\n", ' ', $text));
    }

    private function attributePruefen(DOMElement $el): void
    {
        $tag = strtolower($el->nodeName);
        $erlaubt = [
            'a' => ['href', 'title'], 'img' => ['src', 'alt', 'title'], 'ol' => ['start'],
            'code' => ['class'], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        ][$tag] ?? [];

        foreach ($el->attributes ?? [] as $attribut) {
            $name = strtolower($attribut->nodeName);
            if (in_array($name, $erlaubt, true) || in_array($name, self::HARMLOS[$tag] ?? [], true)) {
                continue;
            }
            if (in_array($tag, ['td', 'th'], true) && in_array($name, ['colspan', 'rowspan'], true)) {
                continue;
            }
            $this->verlust($tag.'@'.$name);
        }

        foreach (['colspan', 'rowspan'] as $spanne) {
            if ($el->hasAttribute($spanne) && (int) $el->getAttribute($spanne) > 1) {
                $this->verlust($tag.'@'.$spanne);
            }
        }
    }

    private function mitVerlust(string $merkmal, string $inhalt): string
    {
        $this->verlust($merkmal);

        return $inhalt;
    }

    private function verlust(string $merkmal): void
    {
        $this->verluste[$merkmal] = ($this->verluste[$merkmal] ?? 0) + 1;
    }
}
