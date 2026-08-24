<?php

/**
 * Jede Sperre und jede Grenze als eigener Fall.
 *
 * Der Schreibweg ist der einzige Teil des Moduls, der die Heizung wirklich
 * veraendern kann. Deshalb wird hier nicht die Funktion geprueft, sondern die
 * Verweigerung.
 *
 * Aufruf: /usr/bin/php tests/WriteGuardTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Keys;
use Hoep\Pellematic\Meta;
use Hoep\Pellematic\Parser;
use Hoep\Pellematic\WriteGuard;

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

echo "WriteGuardTest\n";

$d = Parser::decode(file_get_contents(__DIR__ . '/../fixtures/all-metadaten.json'));
$meta = Meta::fromResponse($d['data']);

$aus = ['enabled' => false, 'comfort' => false, 'modes' => false, 'buffer' => false];
$alles = ['enabled' => true, 'comfort' => true, 'modes' => true, 'buffer' => true];
$nurKomfort = ['enabled' => true, 'comfort' => true, 'modes' => false, 'buffer' => false];
$grenzen = ['wwMax' => 60.0, 'hkMin' => 14.0, 'hkMax' => 24.0, 'maxPerHour' => 20];

// --- Tor 1: Hauptschalter --------------------------------------------------
$r = WriteGuard::check('hk1.temp_heat', 21.0, $aus, $meta, $grenzen);
pruef('Hauptschalter aus: nichts geht', !$r['ok']);
pruef('und die Ablehnung nennt den Hauptschalter', str_contains($r['grund'], 'Hauptschalter'), $r['grund']);

// --- Tor 2: Klassenfreigabe ------------------------------------------------
$r = WriteGuard::check('pe1.mode', 1.0, $nurKomfort, $meta, $grenzen);
pruef('Klasse Betriebsarten aus: Kesselfreigabe bleibt zu', !$r['ok'], $r['grund']);
$r = WriteGuard::check('pu1.mintemp_on', 20.0, $nurKomfort, $meta, $grenzen);
pruef('Klasse Puffer aus: Frostschutzwerte bleiben zu', !$r['ok'], $r['grund']);
$r = WriteGuard::check('hk1.temp_heat', 21.0, $nurKomfort, $meta, $grenzen);
pruef('Klasse Komfort an: Raumtemperatur geht durch', $r['ok'], $r['grund']);
pruef('und sendet den Rohwert 210', $r['raw'] === 210, (string) $r['raw']);

// --- Feste Sperrliste ------------------------------------------------------
foreach (['ww1.sensor_on', 'ww1.sensor_off'] as $k) {
    $r = WriteGuard::check($k, 1.0, $alles, $meta, $grenzen);
    pruef($k . ' ist fest gesperrt, auch mit allen Freigaben', !$r['ok']);
    pruef('und der Grund nennt die Stoerung 1020', str_contains($r['grund'], '1020'), $r['grund']);
}
foreach (['hk1.name', 'hk2.name', 'ww1.name'] as $k) {
    $r = WriteGuard::check($k, 1.0, $alles, $meta, $grenzen);
    pruef($k . ' ist fest gesperrt (Umlaut bricht den Datenstrom)', !$r['ok']);
}
foreach (['pe1.storage_fill_today', 'pe1.storage_fill_yesterday'] as $k) {
    $r = WriteGuard::check($k, 5.0, $alles, $meta, $grenzen);
    pruef($k . ' ist fest gesperrt (verfaelscht den Pelletzaehler)', !$r['ok']);
}

// --- Praefixregel ----------------------------------------------------------
foreach (['pe1.L_temp_act', 'pe1.L_state', 'pe1.L_storage_max', 'pe1.L_storage_min', 'hk1.L_pump'] as $k) {
    $r = WriteGuard::check($k, 1.0, $alles, $meta, $grenzen);
    pruef($k . ' ist nur lesbar (Praefix L_)', !$r['ok']);
}
pruef('auch L_storage_max mit gemeldeten Grenzen bleibt zu',
    !WriteGuard::check('pe1.L_storage_max', 6000.0, $alles, $meta, $grenzen)['ok']);

// --- Tor 3: Grenzen der Anlage ---------------------------------------------
$r = WriteGuard::check('hk1.temp_heat', 45.0, $alles, $meta, $grenzen);
pruef('45 Grad liegen ueber der Anlagengrenze 40,0', !$r['ok'], $r['grund']);
$r = WriteGuard::check('hk1.temp_heat', 5.0, $alles, $meta, $grenzen);
pruef('5 Grad liegen unter der Anlagengrenze 10,0', !$r['ok'], $r['grund']);
pruef('ein Wert ausserhalb wird abgelehnt, nicht gekappt',
    !str_contains($r['grund'], 'gekappt') && str_contains($r['grund'], 'außerhalb'), $r['grund']);

$r = WriteGuard::check('pe1.mode', 7.0, $alles, $meta, $grenzen);
pruef('Aufzaehlungswert 7 steht nicht in der Liste von pe1.mode', !$r['ok'], $r['grund']);

$r = WriteGuard::check('gibtesnicht.xy', 1.0, $alles, $meta, $grenzen);
pruef('ohne Metadaten wird nicht geschrieben', !$r['ok'], $r['grund']);
$r = WriteGuard::check('weather.refresh', 1.0, $alles, $meta, $grenzen);
pruef('weather.refresh meldet weder Grenzen noch Liste und bleibt zu', !$r['ok'], $r['grund']);

// --- Zusaetzliche Grenzen aus dem Formular ---------------------------------
$r = WriteGuard::check('ww1.temp_max_set', 75.0, $alles, $meta, $grenzen);
pruef('75 Grad Warmwasser: von der Anlage erlaubt, vom Formular nicht', !$r['ok'], $r['grund']);
pruef('und die Begruendung nennt den Verbruehungsschutz',
    str_contains($r['grund'], "Verbr\u{00fc}hungsschutz"), $r['grund']);
$r = WriteGuard::check('ww1.temp_max_set', 55.0, $alles, $meta, $grenzen);
pruef('55 Grad Warmwasser gehen durch', $r['ok'], $r['grund']);
pruef('und ergeben den Rohwert 550', $r['raw'] === 550, (string) $r['raw']);

$r = WriteGuard::check('hk1.temp_heat', 26.0, $alles, $meta, $grenzen);
pruef('26 Grad Raumtemperatur: ueber der Formulargrenze 24', !$r['ok'], $r['grund']);
$r = WriteGuard::check('hk2.temp_setback', 12.0, $alles, $meta, $grenzen);
pruef('12 Grad Absenkung: unter der Formulargrenze 14', !$r['ok'], $r['grund']);

$r = WriteGuard::check('hk1.remote_override', 8.0, $alles, $meta, $grenzen);
pruef('Fernbedienung +8 K uebersteigt die Begrenzung auf +-5 K', !$r['ok'], $r['grund']);
pruef('und der Text erklaert, dass der Wert relativ ist',
    str_contains($r['grund'], 'relativer Versatz'), $r['grund']);
$r = WriteGuard::check('hk1.remote_override', 2.0, $alles, $meta, $grenzen);
pruef('Fernbedienung +2 K geht durch', $r['ok'], $r['grund']);

// --- Betriebsarten: die Null verlangt eine Bestaetigung ---------------------
$r = WriteGuard::check('pe1.mode', 0.0, $alles, $meta, $grenzen);
pruef('pe1.mode=0 ohne Bestaetigung wird abgelehnt', !$r['ok'], $r['grund']);
$r = WriteGuard::check('pe1.mode', 0.0, $alles, $meta, $grenzen, ['bestaetigt' => true]);
pruef('pe1.mode=0 MIT Bestaetigung geht durch', $r['ok'], $r['grund']);
$r = WriteGuard::check('system.mode', 0.0, $alles, $meta, $grenzen);
pruef('system.mode=0 ohne Bestaetigung wird abgelehnt', !$r['ok']);
$r = WriteGuard::check('hk1.mode_auto', 0.0, $alles, $meta, $grenzen);
pruef('hk1.mode_auto=0 ohne Bestaetigung wird abgelehnt', !$r['ok']);
$r = WriteGuard::check('hk1.mode_auto', 1.0, $alles, $meta, $grenzen);
pruef('hk1.mode_auto=1 braucht keine Bestaetigung', $r['ok'], $r['grund']);
$r = WriteGuard::check('hk1.time_prg', 0.0, $alles, $meta, $grenzen);
pruef('eine 0 in der Klasse Komfort braucht keine Bestaetigung', $r['ok'], $r['grund']);

// --- Ratenbremse ------------------------------------------------------------
$jetzt = 1_700_000_000;
$voll = array_fill(0, 20, $jetzt - 60);
$r = WriteGuard::check('hk1.temp_heat', 21.0, $alles, $meta, $grenzen,
    ['verlauf' => $voll, 'jetzt' => $jetzt]);
pruef('Ratenbremse greift bei 20 Vorgaengen in der Stunde', !$r['ok'], $r['grund']);
$alt = array_fill(0, 20, $jetzt - 7200);
$r = WriteGuard::check('hk1.temp_heat', 21.0, $alles, $meta, $grenzen,
    ['verlauf' => $alt, 'jetzt' => $jetzt]);
pruef('Vorgaenge von vor zwei Stunden zaehlen nicht mehr', $r['ok'], $r['grund']);
pruef('der Verlauf wird beim Pflegen ausgeduennt',
    WriteGuard::verlaufPflegen(array_merge($voll, $alt), $jetzt) === $voll);

// --- Nichts-tun-Regel --------------------------------------------------------
$r = WriteGuard::check('hk1.temp_heat', 22.0, $alles, $meta, $grenzen, ['gelesen' => 22.0]);
pruef('der bereits gesetzte Wert wird nicht erneut geschrieben', !$r['ok']);
pruef('und das gilt als "nichts zu tun", nicht als Fehler', $r['noop'] === true);
$r = WriteGuard::check('hk1.temp_heat', 22.5, $alles, $meta, $grenzen, ['gelesen' => 22.0]);
pruef('ein wirklich anderer Wert geht durch', $r['ok'], $r['grund']);

// --- Klassenzuordnung --------------------------------------------------------
pruef('hk1.temp_heat gehoert zur Klasse Komfort', Keys::writeClass('hk1.temp_heat') === Keys::W_COMFORT);
pruef('pe1.mode gehoert zur Klasse Betriebsarten', Keys::writeClass('pe1.mode') === Keys::W_MODES);
pruef('pu1.ext_mintemp_off gehoert zur Klasse Puffer', Keys::writeClass('pu1.ext_mintemp_off') === Keys::W_BUFFER);
pruef('ww1.sensor_on ist gesperrt', Keys::writeClass('ww1.sensor_on') === Keys::W_BLOCKED);
pruef('ein unbekannter Parameter bekommt keine Klasse',
    Keys::writeClass('pe1.nagelneu') === Keys::W_NONE);
$r = WriteGuard::check('pe1.nagelneu', 1.0, $alles, $meta, $grenzen);
pruef('und wird deshalb nicht geschrieben', !$r['ok'], $r['grund']);

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
