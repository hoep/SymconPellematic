<?php
declare(strict_types=1);
require __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Forecast;
use Hoep\Pellematic\Parser;

$ok = 0; $fehler = 0;
function pruef(string $was, bool $gut, string $zusatz = ''): void {
    global $ok, $fehler;
    if ($gut) { $ok++; echo "  ok    $was\n"; }
    else { $fehler++; echo "  FEHLER $was" . ($zusatz !== '' ? " ($zusatz)" : '') . "\n"; }
}

echo "ForecastTest\n";
$roh = file_get_contents(__DIR__ . '/fixtures/all.json');
$antwort = Parser::decode($roh);
$flat = Parser::flatten($antwort['data']);
// Fester Bezugspunkt, damit der Jahreswechsel nicht am Testtag haengt: 23.08.2026
$jetzt = mktime(21, 40, 0, 8, 23, 2026);
$v = Forecast::owm($flat, $jetzt);

pruef('es kommt eine Vorhersage heraus', $v !== []);
pruef('25 Stundenwerte', count($v['hourly'] ?? []) === 25, (string) count($v['hourly'] ?? []));
pruef('Tageswerte aggregiert', count($v['daily'] ?? []) >= 3, (string) count($v['daily'] ?? []));

$h0 = $v['hourly'][0] ?? [];
pruef('erste Stunde traegt einen Zeitstempel', !empty($h0['dt']));
pruef('Zeitstempel liegt im richtigen Jahr',
    !empty($h0['dt']) && (int) date('Y', $h0['dt']) === 2026, date('d.m.Y H:i', $h0['dt'] ?? 0));
pruef('Temperatur der ersten Stunde ist 18', ($h0['temp'] ?? null) == 18.0, (string) ($h0['temp'] ?? 'null'));
pruef('Bewoelkung der ersten Stunde ist 84', ($h0['clouds'] ?? null) === 84, (string) ($h0['clouds'] ?? 'null'));
pruef('Wettercode 803 uebernommen', (int) ($h0['weather'][0]['id'] ?? 0) === 803);
pruef('Bildkuerzel 04n uebernommen', ($h0['weather'][0]['icon'] ?? '') === '04n');
// 2 km/h -> 0,56 m/s (das Widget rechnet selbst wieder nach km/h)
pruef('Wind in m/s zurueckgerechnet', abs(((float) ($h0['wind_speed'] ?? 0)) - 0.56) < 0.02,
    (string) ($h0['wind_speed'] ?? 'null'));

$tag = $v['daily'][0] ?? [];
pruef('Tageswert hat Hoechst- und Tiefstwert',
    isset($tag['temp']['max'], $tag['temp']['min']) && $tag['temp']['max'] >= $tag['temp']['min']);

pruef('aktueller Wert aus der Anlage (18,0 Grad)', abs(((float) ($v['current']['temp'] ?? 0)) - 18.0) < 0.01,
    (string) ($v['current']['temp'] ?? 'null'));
pruef('Ort aus der Anlage', ($v['ort'] ?? '') === 'Musterhuegeln', (string) ($v['ort'] ?? ''));

// Das Wetter-Widget erkennt OWM an genau diesen Merkmalen (weather.js, wDetect)
pruef('das Widget erkennt das Format als OWM',
    is_array($v['daily'] ?? null) && isset($v['current']['weather'][0]));

echo "  $ok/" . ($ok + $fehler) . "\n";
exit($fehler === 0 ? 0 : 1);
