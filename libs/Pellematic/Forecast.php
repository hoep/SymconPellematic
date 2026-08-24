<?php

declare(strict_types=1);

namespace Hoep\Pellematic;

/**
 * Die Wettervorhersage der Anlage als EIN JSON - im Format, das das
 * Wetter-Widget des LiveViewBuilders ohnehin liest.
 *
 * Die Pellematic liefert den Block "forecast" als 25 Textzeilen L_w_0 bis
 * L_w_24, jede eine Zeile mit senkrechten Strichen:
 *
 *     So, 23 Aug 21:35|18|84|2 km/h|04n|803|C|06:08|20:03
 *     Datum/Zeit      |T |Wo|Wind  |Bild|Code|Einheit[|SA|SU]
 *
 * 25 Einzelvariablen daraus zu machen waere Unfug: es ist EINE Vorhersage.
 * Das Widget erkennt unter anderem das Format von OpenWeatherMap (One-Call) -
 * und die Anlage bezieht ihre Vorhersage genau von dort, Bildkuerzel und
 * Wettercode sind bereits die von OpenWeatherMap. Deshalb wird hier ihre Form
 * nachgebaut statt eine eigene erfunden:
 *
 *     {"current":{...},"hourly":[...],"daily":[...],"quelle":...,"ort":...}
 *
 * Wind steht in METERN JE SEKUNDE, weil das Widget die Umrechnung nach km/h
 * selbst macht (owm liefert m/s). Die Anlage schreibt "2 km/h" - der Wert wird
 * also zurueckgerechnet, sonst stuende dort das Dreieinhalbfache.
 */
final class Forecast
{
    /** Monatskuerzel, wie die Anlage sie schreibt - deutsch wie englisch. */
    private const MONATE = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'mrz' => 3, 'apr' => 4,
        'may' => 5, 'mai' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
        'oct' => 10, 'okt' => 10, 'nov' => 11, 'dec' => 12, 'dez' => 12,
    ];

    /**
     * @param array<string,array<string,mixed>> $flat  flache Liste des Parsers
     * @return array<string,mixed>  leeres Array, wenn die Anlage keine Vorhersage fuehrt
     */
    public static function owm(array $flat, ?int $jetzt = null): array
    {
        $jetzt = $jetzt ?? time();
        $stunden = [];
        for ($i = 0; $i < 64; $i++) {
            $roh = Parser::raw($flat, 'forecast.L_w_' . $i);
            if ($roh === null || trim((string) $roh) === '') {
                continue;
            }
            $z = self::zeile((string) $roh, $jetzt);
            if ($z !== null) {
                $stunden[] = $z;
            }
        }
        if ($stunden === []) {
            return [];
        }

        $out = [
            'current' => self::jetzt($flat, $stunden[0]),
            'hourly'  => $stunden,
            'daily'   => self::tage($stunden),
        ];
        $quelle = Parser::raw($flat, 'weather.L_source');
        $ort    = Parser::raw($flat, 'weather.L_location');
        if ($quelle !== null && $quelle !== '') {
            $out['quelle'] = (string) $quelle;
        }
        if ($ort !== null && $ort !== '') {
            // "Musterhuegeln|AT|7871034" - der Ort ist das erste Feld.
            $out['ort'] = explode('|', (string) $ort)[0];
        }
        $out['stand'] = $jetzt;
        return $out;
    }

    /** Eine Vorhersagezeile in einen Stundeneintrag nach OWM-Art. */
    private static function zeile(string $roh, int $jetzt): ?array
    {
        $t = explode('|', $roh);
        if (count($t) < 6) {
            return null;
        }
        $ts = self::zeitstempel(trim($t[0]), $jetzt);
        $code = is_numeric($t[5]) ? (int) $t[5] : 0;
        $eintrag = [
            'dt'      => $ts,
            'temp'    => is_numeric($t[1]) ? (float) $t[1] : null,
            'clouds'  => is_numeric($t[2]) ? (int) $t[2] : null,
            'weather' => [['id' => $code, 'icon' => trim($t[4] ?? ''), 'main' => '', 'description' => '']],
        ];
        // "2 km/h" -> 0,56 m/s. Das Widget rechnet selbst wieder nach km/h.
        if (preg_match('/(-?[\d.,]+)/', (string) ($t[3] ?? ''), $m) === 1) {
            $kmh = (float) str_replace(',', '.', $m[1]);
            $eintrag['wind_speed'] = round($kmh / 3.6, 2);
        }
        if (isset($t[7]) && trim($t[7]) !== '') {
            $eintrag['sunrise_txt'] = trim($t[7]);
        }
        if (isset($t[8]) && trim($t[8]) !== '') {
            $eintrag['sunset_txt'] = trim($t[8]);
        }
        return $eintrag;
    }

    /**
     * "So, 23 Aug 21:35" -> Zeitstempel. Das Jahr fehlt in der Zeile; genommen
     * wird das laufende, ausser der Eintrag laege dadurch mehr als ein halbes
     * Jahr in der Vergangenheit - dann ist es der Jahreswechsel.
     */
    private static function zeitstempel(string $s, int $jetzt): ?int
    {
        if (preg_match('/(\d{1,2})\.?\s+([A-Za-zÄÖÜäöü]{3,})\.?\s+(\d{1,2}):(\d{2})/u', $s, $m) !== 1) {
            return null;
        }
        $tag = (int) $m[1];
        $mon = self::MONATE[mb_strtolower(mb_substr($m[2], 0, 3))] ?? 0;
        if ($mon === 0) {
            return null;
        }
        $jahr = (int) date('Y', $jetzt);
        $ts = mktime((int) $m[3], (int) $m[4], 0, $mon, $tag, $jahr);
        if ($ts === false) {
            return null;
        }
        if ($ts < $jetzt - 180 * 86400) {
            $ts = mktime((int) $m[3], (int) $m[4], 0, $mon, $tag, $jahr + 1);
        }
        return is_int($ts) ? $ts : null;
    }

    /** Der aktuelle Zustand aus den Messwerten der Anlage, Bild aus der ersten Stunde. */
    private static function jetzt(array $flat, array $erste): array
    {
        $temp = Parser::value($flat, 'weather.L_temp');
        $wolken = Parser::value($flat, 'weather.L_clouds');
        return [
            'temp'    => is_numeric($temp) ? (float) $temp : ($erste['temp'] ?? null),
            'clouds'  => is_numeric($wolken) ? (int) $wolken : ($erste['clouds'] ?? null),
            'weather' => $erste['weather'],
            'dt'      => $erste['dt'],
        ];
    }

    /**
     * Tageswerte aus den Stunden: Hoechst- und Tiefstwert je Kalendertag, dazu
     * das haeufigste Wetterbild. Die Anlage liefert keine Tagesvorhersage - und
     * eine erfundene waere schlechter als eine gerechnete.
     *
     * @param list<array<string,mixed>> $stunden
     */
    private static function tage(array $stunden): array
    {
        $tage = [];
        foreach ($stunden as $h) {
            if (!isset($h['dt']) || $h['dt'] === null || $h['temp'] === null) {
                continue;
            }
            $tag = date('Y-m-d', (int) $h['dt']);
            if (!isset($tage[$tag])) {
                $tage[$tag] = ['hi' => $h['temp'], 'lo' => $h['temp'], 'dt' => (int) $h['dt'],
                               'codes' => [], 'clouds' => []];
            }
            $tage[$tag]['hi'] = max($tage[$tag]['hi'], $h['temp']);
            $tage[$tag]['lo'] = min($tage[$tag]['lo'], $h['temp']);
            $id = (int) ($h['weather'][0]['id'] ?? 0);
            $tage[$tag]['codes'][$id] = ($tage[$tag]['codes'][$id] ?? 0) + 1;
            if ($h['clouds'] !== null) {
                $tage[$tag]['clouds'][] = (int) $h['clouds'];
            }
        }
        $out = [];
        foreach ($tage as $t) {
            arsort($t['codes']);
            $id = (int) array_key_first($t['codes']);
            $out[] = [
                'dt'      => $t['dt'],
                'temp'    => ['max' => $t['hi'], 'min' => $t['lo']],
                'clouds'  => $t['clouds'] === [] ? null : (int) round(array_sum($t['clouds']) / count($t['clouds'])),
                'weather' => [['id' => $id, 'icon' => '', 'main' => '', 'description' => '']],
            ];
        }
        return $out;
    }
}
