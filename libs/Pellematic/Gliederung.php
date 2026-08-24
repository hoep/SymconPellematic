<?php

declare(strict_types=1);

namespace Hoep\Pellematic;

/**
 * Ordnung im Baum: welcher Schluessel gehoert in welchen Bereich.
 *
 * Die Anlage liefert ihre Werte in Bereichen (system, weather, hk1, hk2, pu1,
 * ww1, pe1) - im Objektbaum lagen sie bisher alle nebeneinander in einer
 * Kategorie mit 86 Eintraegen. Wer dort den Vorlauf des Obergeschosses sucht,
 * liest sich durch die ganze Liste.
 *
 * Diese Klasse ist die EINE Stelle, an der die Zuordnung steht - sie gilt fuer
 * die alten Variablen unter der Altkategorie ebenso wie fuer die eigenen unter
 * der Instanz. Die Reihenfolge der Bereiche ist die des Waermeflusses: erst der
 * Kessel, dann Puffer und Warmwasser, dann die Heizkreise, zuletzt Wetter,
 * Anlage und Statistik.
 */
final class Gliederung
{
    /** Bereich => [Anzeigename, Position]. Die Position ordnet die Kategorien. */
    public const BEREICHE = [
        'pe'      => ['Kessel und Brenner', 10],
        'pu'      => ['Pufferspeicher', 20],
        'ww'      => ['Warmwasser', 30],
        'hk1'     => ['Heizkreis Erdgeschoss', 40],
        'hk2'     => ['Heizkreis Obergeschoss', 50],
        'hk'      => ['Heizkreise', 55],
        'weather' => ['Wetter', 60],
        'system'  => ['Anlage', 70],
        'derived' => ['Verbrauch und Statistik', 80],
        'diag'    => ['Diagnose', 90],
        'rest'    => ['Sonstiges', 99],
    ];

    /**
     * Bereich eines Schluessels. hk1/hk2 bleiben getrennt (zwei Geschosse, zwei
     * Kreise), pu1/ww1/pe1 werden zusammengefasst - es gibt je nur einen.
     */
    public static function bereich(string $key): string
    {
        $p = explode('.', $key, 2);
        $s = $p[0] ?? '';
        if ($s === 'hk1' || $s === 'hk2') {
            return $s;
        }
        $ohneZahl = rtrim($s, '0123456789');
        if (isset(self::BEREICHE[$ohneZahl])) {
            return $ohneZahl;
        }
        if (isset(self::BEREICHE[$s])) {
            return $s;
        }
        return 'rest';
    }

    /** Anzeigename der Kategorie eines Bereichs. */
    public static function name(string $bereich): string
    {
        return self::BEREICHE[$bereich][0] ?? self::BEREICHE['rest'][0];
    }

    /** Position der Kategorie im Baum. */
    public static function position(string $bereich): int
    {
        return self::BEREICHE[$bereich][1] ?? self::BEREICHE['rest'][1];
    }

    /**
     * Position einer Variablen INNERHALB ihres Bereichs.
     *
     * Gemessene Werte zuerst (die Anlage schreibt sie mit "L_"), danach die
     * Einstellwerte, zuletzt die Namensfelder. So steht oben, was man ansieht,
     * und unten, was man selten aendert.
     */
    public static function reihenfolge(string $key): int
    {
        $p = explode('.', $key, 2);
        $rest = $p[1] ?? $key;
        if (str_starts_with($rest, 'L_')) {
            return 100;
        }
        if (str_contains($rest, 'name')) {
            return 900;
        }
        if (str_contains($rest, 'mode') || str_contains($rest, 'time_prg')) {
            return 700;
        }
        return 500;
    }
}
