<?php

/**
 * Bitmasken und ihr Klartext.
 *
 * Die Regel lautet: bitNN ist gesetzt, wenn (state >> (NN-1)) & 1. Sie wird
 * hier an drei Werten aus dem echten Dump dieser Anlage gegengerechnet und an
 * zwei Werten aus dem Doku-Repo, bei denen der Klartext mitgeliefert ist.
 *
 * Aufruf: /usr/bin/php tests/StateBitsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Meta;
use Hoep\Pellematic\Parser;
use Hoep\Pellematic\StateBits;

$ok = 0;
$n = 0;
function pruef(string $was, bool $gut, string $ist = ''): void
{
    global $ok, $n;
    $n++;
    if ($gut) {
        $ok++;
    }
    printf("  %-5s %s%s\n", $gut ? 'ok' : 'FEHL', $was, $gut || $ist === '' ? '' : '  [' . $ist . ']');
}

echo "StateBitsTest\n";

// --- die Rechenregel ------------------------------------------------------
pruef('65552 ergibt Bit 5 und Bit 17', StateBits::bits(65552) === [5, 17],
    implode(',', StateBits::bits(65552)));
pruef('8200 ergibt Bit 4 und Bit 14', StateBits::bits(8200) === [4, 14],
    implode(',', StateBits::bits(8200)));
// ACHTUNG: 8208 ist NICHT 8200. 8208 = 8192 + 16 und ergibt Bit 5 und Bit 14.
// Genau dieser Wert steht im Dump dieser Anlage, und der Klartext bestaetigt es.
pruef('8208 ergibt Bit 5 und Bit 14 (nicht Bit 4)', StateBits::bits(8208) === [5, 14],
    implode(',', StateBits::bits(8208)));
pruef('512 ergibt Bit 10', StateBits::bits(512) === [10], implode(',', StateBits::bits(512)));
pruef('8 ergibt Bit 4', StateBits::bits(8) === [4]);

// --- Klartext gegen die mitgelieferten Texte -------------------------------
pruef('Heizkreis 65552 heisst "Absenkbetrieb aktiv|Aussentemperatur ueber Heizgrenze absenken"',
    StateBits::text('hk1', 65552) === "Absenkbetrieb aktiv|Au\u{00df}entemperatur \u{00fc}ber Heizgrenze absenken",
    StateBits::text('hk1', 65552));
pruef('Warmwasser 8200 heisst "Zeit ausserhalb Zeitprogramm|Anforderung Aus"',
    StateBits::text('ww1', 8200) === "Zeit au\u{00df}erhalb Zeitprogramm|Anforderung Aus",
    StateBits::text('ww1', 8200));
pruef('Puffer 512 heisst "Anforderung Aus"', StateBits::text('pu1', 512) === 'Anforderung Aus');

// --- Gegenprobe an der echten Antwort dieser Anlage -------------------------
$d = Parser::decode(file_get_contents(__DIR__ . '/../fixtures/all-metadaten.json'));
$flat = Parser::flatten($d['data'], Meta::fromResponse($d['data']));

foreach (['hk1', 'hk2', 'pu1', 'ww1'] as $bereich) {
    $state = (int) $flat[$bereich . '.L_state']['raw'];
    $ausAnlage = (string) $flat[$bereich . '.L_statetext']['value'];
    $eigen = StateBits::text($bereich, $state);
    pruef($bereich . ' L_state ' . $state . ': eigene Bittabelle trifft den Text der Anlage',
        $eigen === $ausAnlage, 'Anlage="' . $ausAnlage . '" eigen="' . $eigen . '"');
}

// Fuer den Kessel gibt es keine belastbare Bitliste - dort gewinnt immer der
// Text der Anlage, und die eigene Tabelle haelt sich bewusst heraus.
pruef('fuer pe1 gibt es keine Bittabelle', StateBits::table('pe1') === []);
pruef('und decode liefert dort nichts', StateBits::decode('pe1', 2147483648) === []);

// --- Bitstring in ordentlicher Breite ---------------------------------------
pruef('Bitstring hat 32 Stellen', strlen(StateBits::binString(8)) === 32);
pruef('2147483648 ist Bit 32 und wird nicht abgeschnitten',
    StateBits::binString(2147483648) === '1' . str_repeat('0', 31),
    StateBits::binString(2147483648));
pruef('8 wird links mit Nullen aufgefuellt',
    StateBits::binString(8) === str_repeat('0', 28) . '1000', StateBits::binString(8));
// Der Altcode paddet auf 14 Stellen und verliert damit genau dieses Bit.
pruef('14 Stellen wuerden Bit 32 verlieren', strlen(decbin(2147483648)) > 14);
// Der Ueberlaufwert aus HK_OG_Status darf keine 64 Stellen erzeugen.
pruef('ein Ueberlaufwert ergibt trotzdem 32 Stellen',
    strlen(StateBits::binString(PHP_INT_MIN)) === 32, StateBits::binString(PHP_INT_MIN));

// --- unbekannte Bits verschwinden nicht --------------------------------------
$t = StateBits::decode('pu1', 1 << 20);
pruef('ein Bit ausserhalb der Tabelle wird als "Bit 21" gemeldet',
    $t === ['Bit 21'], implode(',', $t));

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
