<?php

/**
 * Prueft die Zerlegung echter Antworten - auch der kaputten.
 *
 * Aufruf: /usr/bin/php tests/ParserTest.php
 * Laeuft ohne Symcon.
 */

declare(strict_types=1);

require __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Client;
use Hoep\Pellematic\Keys;
use Hoep\Pellematic\Meta;
use Hoep\Pellematic\Parser;

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

$fix = __DIR__ . '/../fixtures/';

echo "ParserTest\n";

// ------------------------------------------------------- echte Antwort
$roh = file_get_contents($fix . 'all-metadaten.json');
pruef('Fixture ist Latin-1, nicht UTF-8', !mb_check_encoding($roh, 'UTF-8'));
pruef('Fixture hat die gemessenen 13.499 Bytes', strlen($roh) === 13499, (string) strlen($roh));

$d = Parser::decode($roh);
pruef('all? laesst sich dekodieren', $d['ok'], $d['error']);

$meta = Meta::fromResponse($d['data']);
$flat = Parser::flatten($d['data'], $meta);

pruef('9 Bereiche erkannt', count(Parser::sections($d['data'])) === 9, (string) count(Parser::sections($d['data'])));
pruef('140 Datenpunkte tragen Metadaten', $meta->count() === 140, (string) $meta->count());
pruef('145 Werte flach, also 140 plus 5 Statustexte', count($flat) === 145, (string) count($flat));
pruef('die *_info-Beschriftungen sind keine Datenpunkte', !isset($flat['system.system_info']));

pruef('Umlaut kommt richtig an (Musterhuegeln)',
    str_contains((string) $flat['weather.L_location']['value'], "\u{00fc}"),
    (string) $flat['weather.L_location']['value']);
pruef('Grad-Zeichen in der Einheit ist UTF-8', $flat['pe1.L_temp_act']['unit'] === "\u{00b0}C",
    $flat['pe1.L_temp_act']['unit']);

pruef('Kesseltemperatur 666 mit Faktor 0,1 ergibt 66,6',
    abs((float) $flat['pe1.L_temp_act']['value'] - 66.6) < 0.0001,
    (string) $flat['pe1.L_temp_act']['value']);

pruef('Sentinel -32768 wird als "kein Fuehler" erkannt', $flat['pe1.L_ext_temp']['sentinel'] === true);
pruef('Sentinel liefert keinen Zahlenwert (nie -3276,8)', $flat['pe1.L_ext_temp']['value'] === null);

pruef('L_statetext kommt flach und wird trotzdem gelesen',
    $flat['pe1.L_statetext']['value'] === 'Aus', (string) $flat['pe1.L_statetext']['value']);
pruef('Kesselstatus liegt ausserhalb der Aufzaehlung (2147483648)',
    (float) $flat['pe1.L_state']['raw'] === 2147483648.0);
pruef('Bereich error ist leer', Parser::errorText($d['data']) === '');
pruef('hk1 heisst laut Anlage Erdgeschoss', $flat['hk1.name']['value'] === 'Erdgeschoss');

// ------------------------------------------------------- abgebrochene Antwort
$abbruch = file_get_contents($fix . 'all-abbruch.txt');
$da = Parser::decode($abbruch);
pruef('abgebrochene Antwort wird abgelehnt', !$da['ok']);
pruef('abgebrochene Antwort meldet Status 204', $da['code'] === Keys::ST_TRUNCATED, (string) $da['code']);
pruef('abgebrochene Antwort liefert KEINE Werte', $da['data'] === []);

// Der Client erkennt den Abbruch schon an der angekuendigten Laenge.
$c = (new Client('10.0.0.1', 4321, 'geheim', 5, 2500))
    ->setSleeper(function (int $ms): void {})
    ->setFetcher(fn () => ['body' => $abbruch, 'http' => 200, 'announced' => 13499]);
$r = $c->get('all?');
pruef('Client erkennt "angekuendigt 13499, empfangen 4357"', $r['code'] === Keys::ST_TRUNCATED, (string) $r['code']);

// ------------------------------------------------------- Taktgrenze
$takt = file_get_contents($fix . '401-takt.txt');
$c = (new Client('10.0.0.1', 4321, 'geheim', 5, 2500))
    ->setSleeper(function (int $ms): void {})
    ->setFetcher(fn () => ['body' => $takt, 'http' => 401, 'announced' => strlen($takt)]);
$r = $c->get('all?');
pruef('401 MIT Wartetext ist ein Taktfehler, kein Passwortfehler',
    $r['code'] === Keys::ST_RATE, (string) $r['code']);

$c = (new Client('10.0.0.1', 4321, 'geheim', 5, 2500))
    ->setSleeper(function (int $ms): void {})
    ->setFetcher(fn () => ['body' => 'Unauthorized', 'http' => 401, 'announced' => 12]);
$r = $c->get('all?');
pruef('401 OHNE Wartetext ist ein Zugangsfehler', $r['code'] === Keys::ST_AUTH, (string) $r['code']);

// ------------------------------------------------------- Hilfeseite
$hilfe = file_get_contents($fix . 'hilfeseite.txt');
pruef('Hilfeseite ist kein JSON', !Parser::looksLikeJson($hilfe));
$dh = Parser::decode($hilfe);
pruef('Hilfeseite wird als Passwortproblem gemeldet', $dh['code'] === Keys::ST_AUTH, (string) $dh['code']);
pruef('Hilfeseite nennt die Gross-/Kleinschreibung', str_contains($dh['error'], 'Kleinschreibung'));

$c = (new Client('10.0.0.1', 4321, 'geheim', 5, 2500))
    ->setSleeper(function (int $ms): void {})
    ->setFetcher(fn () => ['body' => $hilfe, 'http' => 200, 'announced' => strlen($hilfe)]);
$r = $c->get('all?');
pruef('Client erkennt die Hilfeseite trotz HTTP 200', $r['code'] === Keys::ST_AUTH, (string) $r['code']);

// ------------------------------------------------------- leere Antwort
$c = (new Client('10.0.0.1', 4321, 'geheim', 5, 2500))
    ->setSleeper(function (int $ms): void {})
    ->setFetcher(fn () => ['body' => '', 'http' => 0, 'announced' => null]);
$r = $c->get('all?');
pruef('keine Antwort heisst "nicht erreichbar"', $r['code'] === Keys::ST_UNREACHABLE, (string) $r['code']);

// ------------------------------------------------------- Passwort maskieren
pruef('Passwort steht nicht in der gemeldeten URL', !str_contains($r['url'], 'geheim'), $r['url']);
pruef('Passwort steht nicht in der Fehlermeldung', !str_contains($r['error'], 'geheim'), $r['error']);

// ------------------------------------------------------- alte Firmware V3.10d
$alt = file_get_contents($fix . 'v310d-flach.json');
$dv = Parser::decode($alt);
pruef('flache Antwort ohne Fragezeichen laesst sich dekodieren', $dv['ok'], $dv['error']);
$mv = Meta::fromResponse($dv['data']);
pruef('flache Antwort traegt keine Metadaten', $mv->count() === 0, (string) $mv->count());
$fv = Parser::flatten($dv['data'], $mv);
pruef('Zeichenkette "680" wird als Zahl gelesen', (float) $fv['pe1.L_temp_act']['value'] === 680.0);
pruef('Zeichenkette "false" wird zu einem Wahrheitswert', $fv['pe1.L_br']['value'] === false);
pruef('Sentinel wird auch in der flachen Form erkannt', $fv['pe1.L_ext_temp']['sentinel'] === true);
pruef('L_storage_hopper wird als L_storage_popper gefunden',
    Parser::resolve($fv, 'pe1.L_storage_popper') === 'pe1.L_storage_hopper');
pruef('L_pellets_today wird als storage_fill_today gefunden',
    Parser::resolve($fv, 'pe1.storage_fill_today') === 'pe1.L_pellets_today');
pruef('der Tippfehler L_pummp wird aufgeloest',
    Parser::resolve($fv, 'circ1.L_pump') === 'circ1.L_pummp');

// ------------------------------------------------------- V4.02 kaputtes JSON
$kaputt = file_get_contents($fix . 'v402-kaputt.json');
pruef('V4.02 ist ohne Sanierung KEIN gueltiges JSON', json_decode($kaputt, true) === null);
$dk = Parser::decode($kaputt);
pruef('V4.02 laesst sich nach der Sanierung lesen', $dk['ok'], $dk['error']);
$fk = Parser::flatten($dk['data'], Meta::fromResponse($dk['data']));
pruef('L_statetext ist danach da', ($fk['hk1.L_statetext']['value'] ?? '') === 'Betriebsart Aus');
pruef('die Sanierung beschaedigt heiles JSON nicht',
    Parser::sanitize('{"a":{"L_statetext":"x"}}') === '{"a":{"L_statetext":"x"}}');

// ------------------------------------------------------- Fehlertexte mehrzeilig
$err = file_get_contents($fix . 'error-mehrzeilig.json');
pruef('mehrzeiliger Fehlertext ist roh KEIN gueltiges JSON', json_decode($err, true) === null);
$de = Parser::decode($err);
pruef('mehrzeiliger Fehlertext laesst sich nach der Sanierung lesen', $de['ok'], $de['error']);
$text = Parser::errorText($de['data']);
pruef('beide Stoerungen kommen im Klartext an',
    str_contains($text, 'Aschebox') && str_contains($text, '5054'), $text);
pruef('Umlaute im Fehlertext sind heil', str_contains($text, "K\u{00fc}rze"), $text);

// ------------------------------------------------------- Testkopie
pruef('tests/fixtures/all.json ist mit fixtures/all-metadaten.json identisch',
    md5_file(__DIR__ . '/fixtures/all.json') === md5_file($fix . 'all-metadaten.json'));

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
