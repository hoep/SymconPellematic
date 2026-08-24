<?php

/**
 * Die drei Tore vor jedem Schreibvorgang - und die Sperren, die kein Schalter
 * aufhebt.
 *
 * Es wird NICHT gekappt, sondern abgelehnt. Ein gekappter Wert tut
 * stillschweigend etwas anderes als gewollt, und bei einer Hausheizung ist das
 * die schlechtere Ueberraschung.
 *
 * Was die Anlage bei einem Grenzwertverstoss tut, ist unbelegt: im gesamten
 * Doku-Repo berichtet niemand von einem durchgefuehrten Schreibzugriff. Genau
 * deshalb pruefen wir vorher und lesen hinterher nach.
 *
 * Kernel-frei und vollstaendig ohne Symcon testbar.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class WriteGuard
{
    /**
     * Prueft einen Schreibwunsch.
     *
     * @param string $key       voller Schluessel, etwa hk1.temp_heat
     * @param mixed  $wert      ANZEIGEWERT (Grad, Prozent, Minuten) bzw. Aufzaehlungswert
     * @param array  $freigaben ['enabled','comfort','modes','buffer'] als bool
     * @param Meta   $meta      Metadaten der Anlage
     * @param array  $grenzen   ['wwMax','hkMin','hkMax','maxPerHour'] aus dem Formular
     * @param array  $lage      ['gelesen'=>?float, 'bestaetigt'=>bool, 'verlauf'=>int[], 'jetzt'=>int]
     *
     * @return array{ok: bool, raw: int, grund: string, noop: bool, klasse: string}
     */
    public static function check(string $key, $wert, array $freigaben, Meta $meta, array $grenzen = [], array $lage = []): array
    {
        $klasse = Keys::writeClass($key);
        $nein = static fn (string $grund, bool $noop = false): array
            => ['ok' => false, 'raw' => 0, 'grund' => $grund, 'noop' => $noop, 'klasse' => $klasse];

        // --- Tor 1: der Hauptschalter -----------------------------------
        if (empty($freigaben['enabled'])) {
            return $nein('Schreiben ist nicht freigegeben. Der Hauptschalter "Schreiben freigeben" '
                . 'steht aus (Auslieferungszustand).');
        }

        // --- Sperrliste: kein Schalter hebt sie auf ----------------------
        if ($klasse === Keys::W_BLOCKED) {
            return $nein('Gesperrt: ' . Keys::blockedReason($key));
        }

        // --- Praefixregel der Firmware ----------------------------------
        if (Keys::isReadOnlyByPrefix($key)) {
            return $nein('"' . $key . '" trägt das Präfix L_ und ist damit nur lesbar. Die Anlage sagt es '
                . 'selbst so: only variables without a leading L_ can be set. Das gilt auch für '
                . 'L_storage_min und L_storage_max, die trotz Präfix Grenzen mitführen.');
        }

        // --- Tor 2: die Klassenfreigabe ---------------------------------
        $schalter = [
            Keys::W_COMFORT => 'comfort',
            Keys::W_MODES => 'modes',
            Keys::W_BUFFER => 'buffer',
        ];
        if (!isset($schalter[$klasse])) {
            return $nein('"' . $key . '" ist keiner Schreibklasse zugeordnet. Ohne Klasse gibt es keine '
                . 'Freigabe - das ist die sichere Richtung.');
        }
        if (empty($freigaben[$schalter[$klasse]])) {
            $namen = [Keys::W_COMFORT => 'Komfortwerte', Keys::W_MODES => 'Betriebsarten', Keys::W_BUFFER => 'Puffer'];
            return $nein('Die Klasse "' . $namen[$klasse] . '" ist nicht freigegeben.');
        }

        // --- Tor 3: die Metadaten der Anlage ----------------------------
        if (!$meta->has($key)) {
            return $nein('Für "' . $key . '" liegen keine Metadaten der Anlage vor. Ohne gemeldete Grenzen '
                . 'wird nicht geschrieben.');
        }

        $enum = $meta->enumMap($key);
        $range = $meta->range($key);

        if ($enum === [] && $range === null) {
            return $nein('Die Anlage meldet für "' . $key . '" weder Grenzen noch eine Auswahlliste. '
                . 'Ohne gemeldete Grenzen wird nicht geschrieben.');
        }

        if (!is_numeric($wert) && !is_bool($wert)) {
            return $nein('Der Wert ist keine Zahl. Es geht ausschließlich ein Rohwert an die Anlage.');
        }
        if (is_bool($wert)) {
            $wert = $wert ? 1 : 0;
        }
        $anzeige = (float) $wert;
        $raw = $meta->toRaw($key, $anzeige);

        // --- Aufzaehlung: der Wert muss in der Liste stehen --------------
        if ($enum !== []) {
            if (!array_key_exists((int) $raw, $enum)) {
                return $nein('Der Wert ' . $raw . ' steht nicht in der Auswahlliste der Anlage ('
                    . $meta->format($key) . ').');
            }
        } elseif ($range !== null) {
            if ($raw < $range[0] || $raw > $range[1]) {
                $f = $meta->factor($key);
                return $nein('Der Wert liegt außerhalb der von der Anlage gemeldeten Grenzen: erlaubt sind '
                    . self::zahl($range[0] * $f) . ' bis ' . self::zahl($range[1] * $f)
                    . ' (roh ' . (int) $range[0] . '..' . (int) $range[1] . '), gewünscht ' . self::zahl($anzeige) . '.');
            }
        }

        // --- Zusaetzliche Grenzen aus dem Formular -----------------------
        $fehler = self::formulargrenzen($key, $anzeige, $grenzen);
        if ($fehler !== '') {
            return $nein($fehler);
        }

        // --- Betriebsarten: die Null verlangt eine Bestaetigung -----------
        // system.mode=0, hk.mode_auto=0 und pe1.mode=0 legen Anlage, Heizkreis
        // oder Kessel still. Im Winter merkt das niemand, bis das Haus kalt ist.
        if ($klasse === Keys::W_MODES && $raw === 0 && empty($lage['bestaetigt'])) {
            return $nein('"' . $key . '" auf 0 (Aus) schaltet Wärme ab. Das verlangt eine ausdrückliche '
                . 'Bestätigung: OKP_SetValueByKey($id, "' . $key . '", 0, true).');
        }

        // --- Ratenbremse -------------------------------------------------
        $maxProStunde = (int) ($grenzen['maxPerHour'] ?? 20);
        $verlauf = (array) ($lage['verlauf'] ?? []);
        $jetzt = (int) ($lage['jetzt'] ?? time());
        $inDerStunde = 0;
        foreach ($verlauf as $stempel) {
            if (($jetzt - (int) $stempel) < 3600) {
                $inDerStunde++;
            }
        }
        if ($inDerStunde >= $maxProStunde) {
            return $nein('Ratenbremse: in der letzten Stunde wurden bereits ' . $inDerStunde
                . ' Schreibvorgänge ausgeführt (erlaubt sind ' . $maxProStunde . ').');
        }

        // --- Nichts-tun-Regel --------------------------------------------
        $gelesen = $lage['gelesen'] ?? null;
        if ($gelesen !== null && is_numeric($gelesen)) {
            $gelesenRaw = $meta->toRaw($key, (float) $gelesen);
            if ($gelesenRaw === $raw) {
                return $nein('Der gewünschte Wert steht bereits an der Anlage. Es wird nicht geschrieben.', true);
            }
        }

        return ['ok' => true, 'raw' => $raw, 'grund' => '', 'noop' => false, 'klasse' => $klasse];
    }

    /**
     * Grenzen, die der Nutzer im Formular enger zieht als die Anlage.
     * Die Anlage laesst Warmwasser bis 80 Grad zu - am Zapfhahn ist das eine
     * Verbruehung. Und ein Heizkreis, der auf 10 Grad gestellt wird, kuehlt das
     * Haus aus, ohne dass jemand einen Fehler sieht.
     */
    private static function formulargrenzen(string $key, float $anzeige, array $grenzen): string
    {
        $norm = Keys::normalize($key);

        if ($norm === 'ww.temp_max_set' || $norm === 'ww.temp_min_set') {
            $max = (float) ($grenzen['wwMax'] ?? 60.0);
            if ($anzeige > $max) {
                return 'Warmwasser höchstens ' . self::zahl($max) . ' Grad (Verbrühungsschutz aus dem Formular), '
                    . 'gewünscht ' . self::zahl($anzeige) . '.';
            }
        }

        if (in_array($norm, ['hk.temp_heat', 'hk.temp_setback', 'hk.temp_vacation'], true)) {
            $min = (float) ($grenzen['hkMin'] ?? 14.0);
            $max = (float) ($grenzen['hkMax'] ?? 24.0);
            if ($anzeige < $min || $anzeige > $max) {
                return 'Raumtemperatur nur zwischen ' . self::zahl($min) . ' und ' . self::zahl($max)
                    . ' Grad (Grenzen aus dem Formular), gewünscht ' . self::zahl($anzeige) . '.';
            }
        }

        if ($norm === 'hk.remote_override') {
            // Der Wert ist ein RELATIVER Versatz in Kelvin auf temp_heat, kein
            // absoluter Sollwert. Wer ihn absolut versteht, verstellt die
            // Raumtemperatur bei 22 Grad um 22 Kelvin.
            if ($anzeige < -5.0 || $anzeige > 5.0) {
                return 'Die Fernbedienung ist ein relativer Versatz in Kelvin auf "Raumtemperatur Heizen". '
                    . 'Erlaubt sind -5,0 bis +5,0 K, gewünscht ' . self::zahl($anzeige) . ' K.';
            }
        }

        return '';
    }

    private static function zahl(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    }

    /** Der Verlauf fuer die Ratenbremse: alles aelter als eine Stunde faellt raus. */
    public static function verlaufPflegen(array $verlauf, int $jetzt): array
    {
        $out = [];
        foreach ($verlauf as $stempel) {
            if (($jetzt - (int) $stempel) < 3600) {
                $out[] = (int) $stempel;
            }
        }
        return $out;
    }
}
