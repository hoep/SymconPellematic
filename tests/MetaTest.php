<?php

/**
 * Prueft, dass Faktoren, Grenzen und Aufzaehlungen wirklich aus der Anlage
 * kommen und nicht aus dem Code. Grundlage ist der echte Dump dieser Anlage.
 *
 * Aufruf: /usr/bin/php tests/MetaTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../libs/Pellematic/autoload.php';

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

echo "MetaTest\n";

$d = Parser::decode(file_get_contents(__DIR__ . '/../fixtures/all-metadaten.json'));
$meta = Meta::fromResponse($d['data']);

pruef('140 Datenpunkte mit Metadaten', $meta->count() === 140, (string) $meta->count());

// --- Faktoren -----------------------------------------------------------
pruef('pe1.L_temp_act hat Faktor 0,1', $meta->factor('pe1.L_temp_act') === 0.1);
pruef('666 wird zu 66,6 Grad', abs($meta->toDisplay('pe1.L_temp_act', 666) - 66.6) < 0.0001);
pruef('22,0 Grad werden zum Rohwert 220', $meta->toRaw('hk1.temp_heat', 22.0) === 220);
pruef('21,5 Grad werden zum Rohwert 215', $meta->toRaw('hk1.temp_heat', 21.5) === 215);
pruef('58,0 Grad Warmwasser werden zu 580', $meta->toRaw('ww1.temp_max_set', 58.0) === 580);
pruef('pe1.L_storage_fill hat Faktor 1 (kg)', $meta->factor('pe1.L_storage_fill') === 1.0);
pruef('pe1.L_runtimeburner hat Faktor 0,01 (zs)', $meta->factor('pe1.L_runtimeburner') === 0.01);
pruef('unbekannter Schluessel liefert Faktor 1,0', $meta->factor('gibtesnicht.xy') === 1.0);

// Der Fehler des Altskripts: L_uw_release traegt Faktor 0,1, wird dort aber roh
// uebernommen. Deshalb steht #<ID> auf 600 statt 60,0.
pruef('pe1.L_uw_release traegt Faktor 0,1', $meta->factor('pe1.L_uw_release') === 0.1);
pruef('Rohwert 600 bedeutet 60,0 Grad', abs($meta->toDisplay('pe1.L_uw_release', 600) - 60.0) < 0.0001);

// --- Grenzen ------------------------------------------------------------
pruef('hk1.temp_heat hat die Grenzen 100..400', $meta->range('hk1.temp_heat') === [100.0, 400.0],
    json_encode($meta->range('hk1.temp_heat')));
pruef('das sind 10,0 bis 40,0 Grad', $meta->displayRange('hk1.temp_heat') === [10.0, 40.0]);
pruef('ww1.temp_max_set hat die Grenzen 80..800', $meta->range('ww1.temp_max_set') === [80.0, 800.0]);
pruef('pu1.mintemp_on hat die Grenzen 80..900', $meta->range('pu1.mintemp_on') === [80.0, 900.0]);
pruef('ww1.smartstart hat die Grenzen 0..90 min', $meta->range('ww1.smartstart') === [0.0, 90.0]);
pruef('weather.cloud_limit hat die Grenzen 0..100', $meta->range('weather.cloud_limit') === [0.0, 100.0]);
pruef('weather.refresh meldet KEINE Grenzen', $meta->range('weather.refresh') === null);

// --- Aufzaehlungen -------------------------------------------------------
$mode = $meta->enumMap('pe1.mode');
pruef('pe1.mode hat drei Eintraege', count($mode) === 3, (string) count($mode));
pruef('pe1.mode 0 heisst Aus', ($mode[0] ?? '') === 'Aus', $mode[0] ?? '-');
pruef('pe1.mode 1 heisst Auto', ($mode[1] ?? '') === 'Auto');
pruef('pe1.mode 2 heisst Ein', ($mode[2] ?? '') === 'Ein');

$sys = $meta->enumMap('system.mode');
pruef('system.mode 2 heisst Warmwasser', ($sys[2] ?? '') === 'Warmwasser', $sys[2] ?? '-');
pruef('hk1.mode_auto hat vier Eintraege', count($meta->enumMap('hk1.mode_auto')) === 4);
pruef('ww1.sensor_on hat vier Eintraege', count($meta->enumMap('ww1.sensor_on')) === 4);
pruef('hk1.oekomode 3 heisst Oekologisch',
    ($meta->enumMap('hk1.oekomode')[3] ?? '') === "\u{00d6}kologisch");

$typ = $meta->enumMap('pe1.L_type');
pruef('pe1.L_type kennt 12 Kesseltypen', count($typ) === 12, (string) count($typ));
pruef('Typ 9 ist COMPACT - das ist diese Anlage', ($typ[9] ?? '') === 'COMPACT', $typ[9] ?? '-');

// --- Setzbarkeit ----------------------------------------------------------
pruef('pe1.L_temp_act ist NICHT setzbar (Praefix L_)', !$meta->isWritable('pe1.L_temp_act'));
pruef('pe1.mode ist setzbar', $meta->isWritable('pe1.mode'));
pruef('hk1.temp_heat ist setzbar', $meta->isWritable('hk1.temp_heat'));
// L_storage_min und L_storage_max fuehren min/max und sehen dadurch setzbar
// aus. Sie sind es nicht - es zaehlt allein das Praefix.
pruef('pe1.L_storage_max ist trotz Grenzen NICHT setzbar', !$meta->isWritable('pe1.L_storage_max'));
pruef('pe1.L_storage_max meldet aber Grenzen', $meta->range('pe1.L_storage_max') === [150.0, 30000.0]);

// --- Klartextnamen --------------------------------------------------------
pruef('die Anlage liefert eigene Bezeichnungen', $meta->text('pe1.L_temp_act') === 'Kesseltemperatur',
    $meta->text('pe1.L_temp_act'));
pruef('Einheit kg bei L_storage_fill', $meta->unit('pe1.L_storage_fill') === 'kg');
pruef('Einheit EH beim Unterdruck', $meta->unit('pe1.L_lowpressure') === 'EH');

// --- Runde durch Speicherung und Zurueckladen -----------------------------
$wieder = Meta::fromArray(json_decode(json_encode($meta->toArray()), true));
pruef('Tabelle uebersteht das Merken im Attribut', $wieder->factor('hk1.temp_heat') === 0.1
    && $wieder->range('hk1.temp_heat') === [100.0, 400.0]);

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
