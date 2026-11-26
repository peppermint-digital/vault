<?php

use Peppermint\Vault\Umwandlung\HtmlZuMarkdown;

function md(?string $html): string
{
    return (new HtmlZuMarkdown)->wandeln($html)->markdown;
}

it('wandelt Absaetze und Auszeichnungen aus Tiptap', function () {
    expect(md('<p>Ein <strong>fetter</strong>, <em>kursiver</em> und <s>gestrichener</s> Text.</p><p>Zweiter</p>'))
        ->toBe("Ein **fetter**, *kursiver* und ~~gestrichener~~ Text.\n\nZweiter\n");
});

it('laesst leere Absaetze weg (F50)', function () {
    expect(md('<p>Oben</p><p></p><p><br></p><p>Unten</p>'))->toBe("Oben\n\nUnten\n");
});

it('macht aus Unterstreichung Fett und meldet es', function () {
    $ergebnis = (new HtmlZuMarkdown)->wandeln('<p>Das ist <u>wichtig</u> und <strong><u>doppelt</u></strong>.</p>');

    expect($ergebnis->markdown)->toBe("Das ist **wichtig** und **doppelt**.\n")
        ->and($ergebnis->verluste)->toBe(['unterstrichen_als_fett' => 2]);
});

it('nummeriert eine Buchstaben-Aufzaehlung normal und meldet es', function () {
    $ergebnis = (new HtmlZuMarkdown)->wandeln('<ol type="a"><li><p>eins</p></li><li><p>zwei</p></li></ol>');

    expect($ergebnis->markdown)->toBe("1. eins\n2. zwei\n")
        ->and($ergebnis->verluste)->toBe(['ol@type' => 1]);
});

it('behaelt Startnummer und verschachtelte Listen', function () {
    expect(md('<ol start="3"><li><p>drei</p><ul><li><p>a</p></li><li><p>b</p></li></ul></li><li><p>vier</p></li></ol>'))
        ->toBe("3. drei\n\n   - a\n   - b\n\n4. vier\n");
});

it('laesst Editor-Klassen an Links ohne Verlust weg', function () {
    $ergebnis = (new HtmlZuMarkdown)->wandeln('<p><a class="text-primary underline" href="https://example.test/x" target="_blank" rel="noopener">Seite</a></p>');

    expect($ergebnis->markdown)->toBe("[Seite](https://example.test/x)\n")
        ->and($ergebnis->verlustfrei())->toBeTrue();
});

it('macht aus einem Link mit sich selbst als Text einen Autolink', function () {
    expect(md('<p><a href="https://example.test">https://example.test</a></p>'))->toBe("<https://example.test>\n");
});

it('wandelt Ueberschriften, Zitate, Code und Trennlinien', function () {
    expect(md('<h2>Titel</h2><blockquote><p>Zitat</p></blockquote><pre><code class="language-php">echo 1;</code></pre><hr>'))
        ->toBe("## Titel\n\n> Zitat\n\n```php\necho 1;\n```\n\n---\n");
});

it('wandelt Zeilenumbrueche als harte Umbrueche', function () {
    expect(md('<p>eins<br>zwei</p>'))->toBe("eins\\\nzwei\n");
});

it('maskiert Zeichen, die Markdown sonst als Auszeichnung liest', function () {
    expect(md('<p>5 * 3 = 15 und a_b [x]</p><p># keine Ueberschrift</p><p>1. kein Punkt</p>'))
        ->toBe("5 \\* 3 = 15 und a\\_b \\[x\\]\n\n\\# keine Ueberschrift\n\n1\\. kein Punkt\n");
});

it('wandelt Tabellen nach GFM', function () {
    expect(md('<table><tbody><tr><th><p>A</p></th><th><p>B</p></th></tr><tr><td><p>1</p></td><td><p>x | y</p></td></tr></tbody></table>'))
        ->toBe("| A | B |\n| --- | --- |\n| 1 | x \\| y |\n");
});

it('laesst Klartext und Leeres unveraendert', function () {
    expect(md('nur Text'))->toBe("nur Text\n")
        ->and(md(''))->toBe('')
        ->and(md('<p></p>'))->toBe('')
        ->and(md(null))->toBe('');
});

it('meldet unbekannte Elemente, verliert aber ihren Text nicht', function () {
    $ergebnis = (new HtmlZuMarkdown)->wandeln('<p>Text mit <mark>Markierung</mark></p>');

    expect($ergebnis->markdown)->toBe("Text mit Markierung\n")
        ->and($ergebnis->verluste)->toBe(['tag:mark' => 1]);
});

it('behaelt Umlaute', function () {
    expect(md('<p>Grüße aus Köln – ß</p>'))->toBe("Grüße aus Köln – ß\n");
});
