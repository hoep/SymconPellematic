<?php

/**
 * Bittexte fuer die Statusfelder - als RUECKFALL.
 *
 * Der Klartext kommt fertig von der Anlage: hk1.L_statetext, ww1.L_statetext,
 * pu1.L_statetext, pe1.L_statetext. Diese Tabellen greifen nur, wenn ein
 * Firmwarestand L_statetext nicht liefert.
 *
 * Regel: bitNN ist gesetzt, wenn (state >> (NN-1)) & 1. bit01 ist also das
 * niederwertigste Bit. Gegengerechnet an drei echten Werten dieser Anlage:
 *   hk1 L_state 8    -> Bit 4  -> "Betriebsart Aus"                  (stimmt mit L_statetext)
 *   pu1 L_state 512  -> Bit 10 -> "Anforderung Aus"                  (stimmt)
 *   ww1 L_state 8208 -> Bit 5 + Bit 14 -> "Zeit innerhalb Zeitprogramm|Anforderung Aus" (stimmt)
 *
 * Fuer den Kessel gibt es KEINE belastbare Bitliste: pe1.L_state ist bei dieser
 * Firmware ein Aufzaehlungswert, der auch ausserhalb der eigenen Liste liegen
 * kann (heute 2147483648 bei Klartext "Aus"). Dort gewinnt immer L_statetext.
 *
 * Es werden keine BitXX-Kindvariablen mehr angelegt. Die 89 Stueck, die die
 * Altskripte erzeugt haben, frieren ein und bleiben unberuehrt.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class StateBits
{
    /** Heizkreis, 25 Bit. Quelle: Bitliste aus dem Forum, an L_statetext gegengerechnet. */
    private const HK = [
        1 => 'Fehlermeldungen prüfen',
        2 => 'Warmwasser Vorrang aktiv',
        3 => 'Quellenüberhitzung / Kollektorschutz aktiv',
        4 => 'Betriebsart Aus',
        5 => 'Absenkbetrieb aktiv',
        6 => 'Heizbetrieb aktiv',
        7 => 'Estrichprogramm aktiv',
        8 => 'Urlaubsprogramm aktiv',
        9 => 'Partyprogramm aktiv',
        10 => 'Frostschutz aktiv',
        11 => 'Außentemperatur über Heizgrenze',
        12 => 'Raumtemperatur erreicht',
        13 => 'Freilauf-/Frostspülung aktiv',
        14 => 'Quellentemperatur unter Pumpenfreigabe',
        15 => 'Externe Anforderung aktiv',
        16 => 'Warten auf externe Anforderung',
        17 => 'Außentemperatur über Heizgrenze absenken',
        18 => 'Solares Heizen aktiv',
        19 => 'Schönwetterprognose, Solltemperatur verringert',
        20 => 'Schlechtwetterprognose, solares Heizen inaktiv',
        21 => 'Pumpennachlauf aktiv',
        22 => 'Vorhaltezeit aktiv',
        23 => 'Solares Heizen deaktiviert, Raumtemperatur erreicht',
        24 => 'Quellentemperatur über Vorlauftemperatur Max',
        25 => 'Komforttemperatur aktiv',
    ];

    /** Warmwasser, 21 Bit. */
    private const WW = [
        1 => 'Alarmtext prüfen',
        2 => 'Überhitzung / Kollektorschutz aktiv',
        3 => 'Betriebsart Aus',
        4 => 'Zeit außerhalb Zeitprogramm',
        5 => 'Zeit innerhalb Zeitprogramm',
        6 => 'Legionellenschutz aktiv',
        7 => 'Frostschutz aktiv',
        8 => 'Nachlauf aktiv',
        9 => 'Temperatur unter Pumpenfreigabetemperatur',
        10 => 'Temperatur unter Pumpentemperatur',
        11 => 'Warmwasserboost aktiv',
        12 => 'Freilauf-/Frostspülung aktiv',
        13 => 'Vorrangfunktion aktiv',
        14 => 'Anforderung Aus',
        15 => 'Anforderung Ein',
        16 => 'Externe Anforderung aktiv',
        17 => 'Wartet auf externe Anforderung',
        18 => 'Solare Heizung aktiv',
        19 => 'Schlechte Wettervorhersage, solare Heizung inaktiv',
        20 => 'Intelligente Warmwasserbereitung aktiv',
        21 => 'Intelligente Warmwasserbereitung aktiv',
    ];

    /** Pufferspeicher, 14 Bit. */
    private const PU = [
        1 => 'Alarmtext prüfen',
        2 => 'Quelle Überhitzung / Kollektorschutz aktiv',
        3 => 'Frostschutz aktiv',
        4 => 'Maximale Systemtemperatur erreicht',
        5 => 'Nachlauf aktiv',
        6 => 'Temperatur unter Pumpenfreigabetemperatur',
        7 => 'Temperatur unter Freigabetemperatur',
        8 => 'Urlaubsfunktion aktiv',
        9 => 'Anforderung Ein',
        10 => 'Anforderung Aus',
        11 => 'Externe Anforderung aktiv',
        12 => 'Wartet auf externe Anforderung',
        13 => 'Leistungsanforderung Stirling aktiv',
        14 => 'Zeitprogramm aktiv',
    ];

    /** Die Bittabelle eines Bereichs. Der Bereichsindex wird abgeschnitten. */
    public static function table(string $bereich): array
    {
        switch (rtrim($bereich, '0123456789')) {
            case 'hk':
                return self::HK;
            case 'ww':
                return self::WW;
            case 'pu':
                return self::PU;
            default:
                return [];
        }
    }

    /**
     * Zerlegt eine Bitmaske in Klartexte.
     * Unbekannte gesetzte Bits werden als "Bit NN" gemeldet statt verschwiegen -
     * ein neuer Firmwarestand soll auffallen, nicht stillschweigend fehlen.
     */
    public static function decode(string $bereich, int $state): array
    {
        $tab = self::table($bereich);
        if ($tab === []) {
            return [];
        }
        $out = [];
        for ($bit = 1; $bit <= 32; $bit++) {
            if ((($state >> ($bit - 1)) & 1) !== 1) {
                continue;
            }
            $out[] = $tab[$bit] ?? ('Bit ' . $bit);
        }
        return $out;
    }

    /** Klartext wie ihn die Anlage schreiben wuerde, mit senkrechtem Strich getrennt. */
    public static function text(string $bereich, int $state): string
    {
        return implode('|', self::decode($bereich, $state));
    }

    /**
     * Bitstring in ordentlicher Breite: 32 Stellen.
     * Der Altcode paddet auf 25, 21 respektive 14 Stellen und schneidet damit
     * genau die Bits ab, die real vorkommen - PE_KesselStatus fuehrt heute
     * 2147483648, also Bit 32.
     */
    public static function binString(int $state): string
    {
        // Auf 32 Bit begrenzen, damit ein negativer Ueberlaufwert nicht 64
        // Stellen erzeugt. -9223372036854775808 steht heute in HK_OG_Status,
        // weil ein Altskript den Bitstring in die Integer-Variable schrieb.
        $u = $state & 0xFFFFFFFF;
        return str_pad(decbin($u), 32, '0', STR_PAD_LEFT);
    }

    /** Welche Bitnummern sind gesetzt. Fuer Tests und Diagnose. */
    public static function bits(int $state): array
    {
        $out = [];
        for ($bit = 1; $bit <= 32; $bit++) {
            if ((($state >> ($bit - 1)) & 1) === 1) {
                $out[] = $bit;
            }
        }
        return $out;
    }
}
