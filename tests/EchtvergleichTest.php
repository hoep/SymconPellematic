<?php

/**
 * Altskript gegen Modul, aus DERSELBEN echten Antwort gerechnet.
 *
 * Die Rechnung des Altskripts #<ID> ist hier unabhaengig nachgebaut - Zeile
 * fuer Zeile aus dem Quelltext abgelesen, nicht aus dem Modul uebernommen.
 * Sonst wuerde der Vergleich nur zeigen, dass eine Rechnung mit sich selbst
 * uebereinstimmt.
 *
 * Erwartet wird: in der Voreinstellung bildet das Modul das Altskript nach -
 * einschliesslich seiner beiden Rechenfehler -, damit keine archivierte Reihe
 * springt. Die einzige Abweichung ist der Sentinelwert. Erst mit den drei
 * Schaltern kommen genau die drei bekannten Abweichungen dazu.
 *
 * Aufruf: /usr/bin/php tests/EchtvergleichTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Keys;
use Hoep\Pellematic\Mapping;
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

echo "EchtvergleichTest\n";

$d = Parser::decode(file_get_contents(__DIR__ . '/../fixtures/all-metadaten.json'));
$meta = Meta::fromResponse($d['data']);
$flat = Parser::flatten($d['data'], $meta);

/**
 * Nachbau des Altskripts #<ID>: es ignoriert das Feld factor und teilt hart
 * durch 10 - aber nur in den Zeilen, in denen "/10" wirklich steht.
 * Schluessel => [Quellschluessel im JSON, Teiler]
 */
$alt = [
    'weather.L_temp' => ['weather.L_temp', 10],
    'system.mode' => ['system.mode', 1],
    'system.L_errors' => ['system.L_errors', 1],
    'hk1.L_roomtemp_set' => ['hk1.L_roomtemp_set', 10],
    'hk1.L_flowtemp_set' => ['hk1.L_flowtemp_set', 10],
    'hk1.L_flowtemp_act' => ['hk1.L_flowtemp_act', 10],
    'hk1.L_state' => ['hk1.L_state', 1],
    'hk1.L_pump' => ['hk1.L_pump', 1],
    'hk1.time_prg' => ['hk1.time_prg', 1],
    'hk1.temp_setback' => ['hk1.temp_setback', 10],
    'hk1.temp_heat' => ['hk1.temp_heat', 10],
    'hk1.temp_vacation' => ['hk1.temp_vacation', 10],
    'hk1.oekomode' => ['hk1.oekomode', 1],
    'hk2.L_roomtemp_set' => ['hk2.L_roomtemp_set', 10],
    'hk2.L_flowtemp_set' => ['hk2.L_flowtemp_set', 10],
    'hk2.L_flowtemp_act' => ['hk2.L_flowtemp_act', 10],
    'hk2.L_state' => ['hk2.L_state', 1],
    'hk2.L_pump' => ['hk2.L_pump', 1],
    'hk2.time_prg' => ['hk2.time_prg', 1],
    'hk2.temp_setback' => ['hk2.temp_setback', 10],
    'hk2.temp_heat' => ['hk2.temp_heat', 10],
    'hk2.temp_vacation' => ['hk2.temp_vacation', 10],
    'hk2.oekomode' => ['hk2.oekomode', 1],
    'pu1.L_state' => ['pu1.L_state', 1],
    'pu1.L_pump_release' => ['pu1.L_pump_release', 10],
    'pu1.L_tpm_act' => ['pu1.L_tpm_act', 10],
    'pu1.L_tpm_set' => ['pu1.L_tpm_set', 10],
    'pu1.L_tpo_act' => ['pu1.L_tpo_act', 10],
    // FEHLER im Altskript: PufferT_Oben_Soll bekommt den ISTWERT.
    'pu1.L_tpo_set' => ['pu1.L_tpo_act', 10],
    'pu1.L_pump' => ['pu1.L_pump', 1],
    'ww1.L_temp_set' => ['ww1.L_temp_set', 10],
    'ww1.L_ontemp_act' => ['ww1.L_ontemp_act', 10],
    'ww1.temp_max_set' => ['ww1.temp_max_set', 10],
    'ww1.temp_min_set' => ['ww1.temp_min_set', 10],
    'ww1.sensor_off' => ['ww1.sensor_off', 1],
    'ww1.sensor_on' => ['ww1.sensor_on', 1],
    'ww1.use_boiler_heat' => ['ww1.use_boiler_heat', 1],
    'ww1.oekomode' => ['ww1.oekomode', 1],
    'ww1.L_pump' => ['ww1.L_pump', 1],
    'ww1.L_state' => ['ww1.L_state', 1],
    'ww1.time_prg' => ['ww1.time_prg', 1],
    'ww1.heat_once' => ['ww1.heat_once', 1],
    'pe1.L_temp_act' => ['pe1.L_temp_act', 10],
    'pe1.L_temp_set' => ['pe1.L_temp_set', 10],
    'pe1.L_ext_temp' => ['pe1.L_ext_temp', 10],
    'pe1.L_frt_temp_act' => ['pe1.L_frt_temp_act', 10],
    'pe1.L_frt_temp_set' => ['pe1.L_frt_temp_set', 10],
    'pe1.L_frt_temp_end' => ['pe1.L_frt_temp_end', 10],
    'pe1.L_modulation' => ['pe1.L_modulation', 1],
    'pe1.L_currentairflow' => ['pe1.L_currentairflow', 1],
    'pe1.L_fluegas' => ['pe1.L_fluegas', 1],
    'pe1.L_uw_speed' => ['pe1.L_uw_speed', 1],
    'pe1.L_state' => ['pe1.L_state', 1],
    'pe1.L_starts' => ['pe1.L_starts', 1],
    'pe1.L_runtime' => ['pe1.L_runtime', 1],
    'pe1.L_avg_runtime' => ['pe1.L_avg_runtime', 1],
    // FEHLER im Altskript: der Faktor 0,1 fehlt, die Variable steht auf 600.
    'pe1.L_uw_release' => ['pe1.L_uw_release', 1],
    'pe1.L_storage_fill' => ['pe1.L_storage_fill', 1],
    'pe1.L_storage_popper' => ['pe1.L_storage_popper', 1],
    'pe1.mode' => ['pe1.mode', 1],
    'pe1.storage_fill_today' => ['pe1.storage_fill_today', 1],
];

pruef('die Zuordnung deckt alle ' . count($alt) . ' Zeilen des Altskripts ab',
    count($alt) === count(Mapping::VORBELEGUNG),
    count($alt) . ' gegen ' . count(Mapping::VORBELEGUNG));

/** Das Modul in der Voreinstellung: Faktor aus der Anlage, Sentinel wird nicht geschrieben. */
function modulwert(array $flat, string $key, bool $fixTpo, bool $fixFreigabe, bool $ambient)
{
    $quelle = $key;
    if ($key === 'pu1.L_tpo_set' && !$fixTpo) {
        $quelle = 'pu1.L_tpo_act';
    }
    if ($key === 'weather.L_temp' && $ambient) {
        $quelle = 'system.L_ambient';
    }
    $echt = Parser::resolve($flat, $quelle);
    if ($echt === null) {
        return null;
    }
    $e = $flat[$echt];
    if ($e['sentinel']) {
        return null; // wird nicht geschrieben, der alte Wert bleibt stehen
    }
    if ($key === 'pe1.L_uw_release' && !$fixFreigabe) {
        return (float) $e['raw'];
    }
    if (Keys::hasFlag($quelle, 'skipzero') && is_numeric($e['value']) && (float) $e['value'] <= 0.0) {
        return null;
    }
    return $e['value'];
}

function altwert(array $flat, array $alt, string $key)
{
    [$quelle, $teiler] = $alt[$key];
    $roh = $flat[$quelle]['raw'] ?? null;
    if ($roh === null) {
        return null;
    }
    return is_numeric($roh) ? ((float) $roh) / $teiler : $roh;
}

function gleich($a, $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    if (is_numeric($a) && is_numeric($b)) {
        return abs((float) $a - (float) $b) < 0.0001;
    }
    return $a == $b;
}

// --- Voreinstellung: alle drei Schalter aus --------------------------------
$abweichungen = [];
foreach ($alt as $key => $x) {
    $a = altwert($flat, $alt, $key);
    $m = modulwert($flat, $key, false, false, false);
    if (!gleich($a, $m)) {
        $abweichungen[$key] = [$a, $m];
    }
}

pruef('in der Voreinstellung weicht genau EINE Zeile ab', count($abweichungen) === 1,
    implode(', ', array_keys($abweichungen)));
pruef('und das ist der Sentinel bei pe1.L_ext_temp', isset($abweichungen['pe1.L_ext_temp']));
pruef('das Altskript wuerde dort -3276,8 Grad ins Archiv schreiben',
    abs((float) $abweichungen['pe1.L_ext_temp'][0] + 3276.8) < 0.001,
    (string) $abweichungen['pe1.L_ext_temp'][0]);
pruef('das Modul schreibt dort gar nichts', $abweichungen['pe1.L_ext_temp'][1] === null);

// --- mit allen drei Korrekturen ---------------------------------------------
$abw2 = [];
foreach ($alt as $key => $x) {
    $a = altwert($flat, $alt, $key);
    $m = modulwert($flat, $key, true, true, true);
    if (!gleich($a, $m)) {
        $abw2[$key] = [$a, $m];
    }
}
pruef('mit allen drei Korrekturen weichen genau VIER Zeilen ab', count($abw2) === 4,
    implode(', ', array_keys($abw2)));
pruef('PufferT_Oben_Soll: 59,1 (Ist) gegen 8,0 (Soll)',
    abs((float) $abw2['pu1.L_tpo_set'][0] - 59.1) < 0.001 && abs((float) $abw2['pu1.L_tpo_set'][1] - 8.0) < 0.001,
    json_encode($abw2['pu1.L_tpo_set']));
pruef('PE_Freigabe_T: 600 gegen 60,0',
    abs((float) $abw2['pe1.L_uw_release'][0] - 600.0) < 0.001 && abs((float) $abw2['pe1.L_uw_release'][1] - 60.0) < 0.001,
    json_encode($abw2['pe1.L_uw_release']));
pruef('Aussen: 18,0 (Onlinewetter) gegen 20,2 (Fuehler der Anlage)',
    abs((float) $abw2['weather.L_temp'][0] - 18.0) < 0.001 && abs((float) $abw2['weather.L_temp'][1] - 20.2) < 0.001,
    json_encode($abw2['weather.L_temp']));

// --- Stichproben, die gleich bleiben MUESSEN ---------------------------------
foreach (['pe1.L_temp_act' => 66.6, 'pu1.L_tpo_act' => 59.1, 'ww1.L_ontemp_act' => 60.6,
          'hk1.temp_heat' => 22.0, 'pe1.L_storage_fill' => 705.0, 'pe1.L_starts' => 1558.0] as $key => $soll) {
    $a = altwert($flat, $alt, $key);
    $m = modulwert($flat, $key, false, false, false);
    pruef($key . ' ist in beiden Wegen ' . $soll,
        abs((float) $a - $soll) < 0.001 && abs((float) $m - $soll) < 0.001,
        'alt=' . $a . ' neu=' . $m);
}

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
