<?php

/**
 * Abgeleitete Groessen, kernel-frei gerechnet.
 *
 * Der Zustand kommt als Parameter herein und geht als Parameter hinaus. Es gibt
 * hier keinen Archivzugriff: die Altskripte holen fuer Brennerstarts Heute und
 * Brenner Laufzeit Heute jedes Mal AC_GetAggregatedValues, was rund 275 ms je
 * Abfrage kostet und einen Ausreisserfilter noetig macht. Ein gemerkter
 * Zaehlerstand um Mitternacht tut dasselbe fuer nichts.
 *
 * WICHTIG zum Pelletzaehler: der Stand von "Pellet Verbrauch gesamt" (#<ID>,
 * heute 10413 kg) existiert nur in dieser einen Variable. Er wurde seit 2024
 * inkrementell aufgebaut und ist NICHT rekonstruierbar. Er wird uebernommen und
 * fortgeschrieben, nie neu begonnen und nie zurueckgesetzt.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Derived
{
    /**
     * Fuellstand in Prozent. Die Lagerkapazitaet meldet die Anlage selbst
     * (pe1.L_storage_max = 6000 kg); der hart verdrahtete Divisor des
     * Altskripts faellt damit weg.
     * Gibt null zurueck, wenn keine brauchbare Kapazitaet vorliegt - lieber
     * kein Wert als eine Division durch null.
     */
    public static function fillPercent(?float $fuellstand, ?float $kapazitaet): ?float
    {
        if ($fuellstand === null || $kapazitaet === null || $kapazitaet <= 0.0) {
            return null;
        }
        return round($fuellstand / $kapazitaet * 100.0, 1);
    }

    /**
     * Tageswert aus einem Zaehler: aktueller Stand minus Stand um Mitternacht.
     * Ein Zaehlerruecksprung (Firmwaretausch, Servicezuruecksetzung) darf keinen
     * negativen Tageswert erzeugen.
     */
    public static function dailyDelta(?int $stand, ?int $standUmMitternacht): ?int
    {
        if ($stand === null) {
            return null;
        }
        if ($standUmMitternacht === null || $standUmMitternacht > $stand) {
            // Kein Bezugspunkt oder Ruecksprung: der heutige Wert beginnt hier neu.
            return 0;
        }
        return $stand - $standUmMitternacht;
    }

    /**
     * Fortschreibung des Gesamtverbrauchs aus dem Tageszaehler der Anlage.
     *
     * Der Tageszaehler storage_fill_today springt um Mitternacht auf 0 zurueck.
     * Damit dabei nichts verloren geht:
     *   - waechst er, wird der Zuwachs addiert und als "heute bereits
     *     gutgeschrieben" gemerkt;
     *   - faellt er (Tageswechsel), wird zuerst der Rest des Vortags
     *     nachgetragen - storage_fill_yesterday minus dem, was fuer diesen Tag
     *     schon gutgeschrieben wurde - und danach der neue Tageswert.
     * Der Gesamtstand kann so nie zurueckspringen.
     *
     * @return array{total: float, lastToday: int, credited: int}
     */
    public static function totalConsumption(
        ?int $heute,
        ?int $gestern,
        int $letztesHeute,
        int $bereitsGutgeschrieben,
        float $gesamt,
        bool $istNeuerTag = true
    ): array {
        if ($heute === null) {
            return ['total' => $gesamt, 'lastToday' => $letztesHeute, 'credited' => $bereitsGutgeschrieben];
        }

        if ($heute >= $letztesHeute) {
            $zuwachs = $heute - $letztesHeute;
            return [
                'total' => $gesamt + $zuwachs,
                'lastToday' => $heute,
                'credited' => $bereitsGutgeschrieben + $zuwachs,
            ];
        }

        // Ein Rueckgang OHNE Datumswechsel ist kein Tageswechsel, sondern ein
        // Ausreisser (die Anlage meldet beim Ansaugen kurz 0). Wer ihn wie einen
        // Tageswechsel verbucht, schreibt den Vortag ein zweites Mal gut - auf
        // einem Zaehler, der sich nicht zurueckrechnen laesst.
        if (!$istNeuerTag) {
            return ['total' => $gesamt, 'lastToday' => $letztesHeute, 'credited' => $bereitsGutgeschrieben];
        }

        // Tageswechsel. Was der Vortag laut Anlage insgesamt verbraucht hat,
        // steht jetzt in storage_fill_yesterday. Davon ist bereits
        // $bereitsGutgeschrieben verbucht; der Rest wird nachgetragen.
        $rest = 0;
        if ($gestern !== null && $gestern > $bereitsGutgeschrieben) {
            $rest = $gestern - $bereitsGutgeschrieben;
        }

        return [
            'total' => $gesamt + $rest + $heute,
            'lastToday' => $heute,
            'credited' => $heute,
        ];
    }

    /** Energiemenge aus der Masse. Heizwert ist eine Eigenschaft, keine Konstante. */
    public static function kwh(float $kilogramm, float $kwhJeKg): float
    {
        return round($kilogramm * $kwhJeKg, 1);
    }

    /**
     * Laeuft der Brenner?
     * Belastbar ist der Brennerkontakt pe1.L_br. Nur wenn ihn ein Firmwarestand
     * nicht liefert, greift die alte Regel "Modulation ungleich null" - die
     * meldet in der Zuendphase noch nichts, obwohl der Brenner laeuft.
     */
    public static function burnerRunning($brennerkontakt, $modulation): ?bool
    {
        if ($brennerkontakt !== null) {
            if (is_bool($brennerkontakt)) {
                return $brennerkontakt;
            }
            if (is_numeric($brennerkontakt)) {
                return ((int) $brennerkontakt) === 1;
            }
        }
        if ($modulation !== null && is_numeric($modulation)) {
            return ((float) $modulation) != 0.0;
        }
        return null;
    }

    /** Die Speicherladepumpe laeuft, wenn ihre Drehzahl ungleich null ist. */
    public static function pumpRunning($drehzahl): ?bool
    {
        if ($drehzahl === null) {
            return null;
        }
        if (is_bool($drehzahl)) {
            return $drehzahl;
        }
        return is_numeric($drehzahl) ? (((float) $drehzahl) != 0.0) : null;
    }

    /**
     * Ist seit dem gemerkten Tagesstempel ein neuer Tag angebrochen?
     * Der Stempel ist bewusst ein Datum als Zeichenkette und keine Uhrzeit:
     * so ueberlebt die Erkennung einen Neustart mitten in der Nacht.
     */
    public static function istNeuerTag(string $gemerkt, ?int $jetzt = null): bool
    {
        $heute = date('Y-m-d', $jetzt ?? time());
        return $gemerkt !== $heute;
    }
}
