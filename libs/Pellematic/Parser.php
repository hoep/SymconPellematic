<?php

/**
 * Sanierung und Zerlegung der Anlagenantwort.
 *
 * Die Antwort dieser Anlage ist an drei Stellen kein sauberes JSON, und keine
 * dieser Stellen ist unser Fehler:
 *   1. Firmware V4.02 schreibt L_statetext ohne oeffnendes Anfuehrungszeichen.
 *   2. Fehlertexte im Bereich error enthalten ECHTE Zeilenumbrueche innerhalb
 *      der JSON-Strings.
 *   3. Die Kodierung ist in der Praxis ISO-8859-1, gelegentlich UTF-8, in
 *      Einzelfaellen gemischt.
 * Erst nach der Sanierung darf json_decode ueberhaupt gefragt werden.
 *
 * Die alte PHP-5-Umwandlung nach UTF-8, die im Altskript 20008 und in
 * PHPOekofen steht, kommt hier bewusst nicht vor: sie ist seit PHP 8.2
 * abgekuendigt. Gewandelt wird mit mb_convert_encoding, und nur nach Pruefung.
 *
 * Kernel-frei und vollstaendig ohne Symcon testbar.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Parser
{
    /**
     * Bringt den Rumpf in einen Zustand, in dem json_decode eine Chance hat.
     * Die Reihenfolge ist verbindlich.
     */
    public static function sanitize(string $body): string
    {
        // 1. V4.02: "L_statetext:" statt "L_statetext":". In einer heilen Antwort
        //    steht L_statetext" vor dem Doppelpunkt, die Suchzeichenkette kommt
        //    dort also gar nicht vor - der Ersatz kann nichts kaputt machen.
        $body = str_replace('L_statetext:', 'L_statetext":', $body);

        // 2. Echte Steuerzeichen innerhalb von JSON-Strings escapen.
        $body = self::escapeControlInStrings($body);

        // 3. Kodierung pruefen und nur bei Bedarf wandeln.
        $body = self::toUtf8($body);

        return $body;
    }

    /**
     * Escapt echte Zeilenumbrueche und Tabulatoren INNERHALB von JSON-Strings.
     * Ausserhalb bleibt alles unangetastet, sonst wuerde die Einrueckung der
     * Antwort mitverarbeitet und die Datei unnoetig aufgeblasen.
     */
    public static function escapeControlInStrings(string $s): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($escaped) {
                    $out .= $c;
                    $escaped = false;
                    continue;
                }
                if ($c === '\\') {
                    $out .= $c;
                    $escaped = true;
                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                    $out .= $c;
                    continue;
                }
                if ($c === "\n") {
                    $out .= '\\n';
                    continue;
                }
                if ($c === "\r") {
                    $out .= '\\r';
                    continue;
                }
                if ($c === "\t") {
                    $out .= '\\t';
                    continue;
                }
                if (ord($c) < 0x20) {
                    $out .= sprintf('\\u%04x', ord($c));
                    continue;
                }
                $out .= $c;
                continue;
            }
            if ($c === '"') {
                $inString = true;
            }
            $out .= $c;
        }
        return $out;
    }

    /** Kodierung pruefen statt raten. Latin-1 ist der Normalfall dieser Anlage. */
    public static function toUtf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Prueft, ob ueberhaupt JSON kommt. Bei falsch geschriebenem Passwort
     * antwortet die Anlage mit HTTP 200 und der Hilfeseite als Klartext - ohne
     * diese Pruefung haelt ein Auswerter das fuer ein leeres Ergebnis.
     */
    public static function looksLikeJson(string $body): bool
    {
        return str_starts_with(ltrim($body), '{');
    }

    /**
     * Dekodiert eine Antwort.
     * Rueckgabe: ['ok'=>bool, 'code'=>int, 'error'=>string, 'data'=>array]
     */
    public static function decode(string $body): array
    {
        if (trim($body) === '') {
            return ['ok' => false, 'code' => Keys::ST_UNREACHABLE, 'error' => 'Leere Antwort - die Anlage hat nichts geliefert.', 'data' => []];
        }

        $body = self::sanitize($body);

        if (!self::looksLikeJson($body)) {
            return [
                'ok' => false,
                'code' => Keys::ST_AUTH,
                'error' => 'Die Anlage hat kein JSON geliefert, sondern ihre Hilfeseite. Das heißt: '
                    . 'das Passwort ist falsch geschrieben. Es ist Teil des Pfades und unterscheidet '
                    . 'Groß- und Kleinschreibung.',
                'data' => [],
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [
                'ok' => false,
                'code' => Keys::ST_TRUNCATED,
                'error' => 'Die Antwort ist kein gültiges JSON (' . json_last_error_msg() . '). '
                    . 'Meist bricht die Anlage den Datenstrom an einem Umlaut in einem Statustext ab.',
                'data' => [],
            ];
        }

        return ['ok' => true, 'code' => Keys::ST_ACTIVE, 'error' => '', 'data' => $data];
    }

    /**
     * Die Bereiche, die die Antwort wirklich enthaelt. NICHT fest verdrahtet:
     * wie viele hk, pu, ww, sk, se, circ, pe eine Anlage hat, sagt nur sie
     * selbst. Diese Anlage liefert heute system, weather, forecast, hk1, hk2,
     * pu1, ww1, pe1 und error.
     */
    public static function sections(array $data): array
    {
        $out = [];
        foreach ($data as $sektion => $inhalt) {
            if (is_array($inhalt)) {
                $out[] = (string) $sektion;
            }
        }
        return $out;
    }

    /**
     * Macht aus der Antwort eine flache Liste "sektion.schluessel" => Eintrag.
     *
     * Je Eintrag:
     *   raw      Rohwert wie geliefert
     *   factor   aus der Antwort oder aus der uebergebenen Metatabelle
     *   format   Aufzaehlung der Anlage
     *   text     Klartextname der Anlage
     *   unit     Einheit
     *   sentinel true, wenn der Fuehler laut Anlage gar nicht verbaut ist
     *   value    fertiger Anzeigewert (Zahl mal Faktor, Text unveraendert),
     *            bei sentinel null
     *
     * Vertraegt beide Antwortformen: Objekt mit val (mit Fragezeichen) und
     * flacher String/Bool (ohne Fragezeichen, alte Firmware).
     */
    public static function flatten(array $data, ?Meta $meta = null): array
    {
        $out = [];
        foreach ($data as $sektion => $inhalt) {
            if (!is_array($inhalt)) {
                continue;
            }
            foreach ($inhalt as $name => $eintrag) {
                // Die *_info-Felder sind Beschriftungen des Bereichs, keine Messwerte.
                if (str_ends_with((string) $name, '_info')) {
                    continue;
                }
                $key = $sektion . '.' . $name;

                if (is_array($eintrag) && array_key_exists('val', $eintrag)) {
                    $raw = $eintrag['val'];
                    $factor = isset($eintrag['factor']) ? (float) $eintrag['factor'] : ($meta ? $meta->factor($key) : 1.0);
                    $format = (string) ($eintrag['format'] ?? '');
                    $text = (string) ($eintrag['text'] ?? '');
                    $unit = (string) ($eintrag['unit'] ?? '');
                } elseif (is_array($eintrag)) {
                    // Ein Objekt ohne val ist kein Datenpunkt, sondern eine
                    // Unterstruktur (etwa wp_data). Sie wird flach mitgezogen.
                    foreach ($eintrag as $unter => $wert) {
                        if (is_array($wert)) {
                            continue;
                        }
                        $out[$key . '.' . $unter] = self::eintrag($key . '.' . $unter, $wert, $meta);
                    }
                    continue;
                } else {
                    $raw = $eintrag;
                    $factor = $meta ? $meta->factor($key) : 1.0;
                    $format = $meta ? $meta->format($key) : '';
                    $text = $meta ? $meta->text($key) : '';
                    $unit = $meta ? $meta->unit($key) : '';
                }

                $out[$key] = self::baueEintrag($raw, $factor, $format, $text, $unit);
            }
        }
        return $out;
    }

    private static function eintrag(string $key, $wert, ?Meta $meta): array
    {
        return self::baueEintrag(
            $wert,
            $meta ? $meta->factor($key) : 1.0,
            $meta ? $meta->format($key) : '',
            $meta ? $meta->text($key) : '',
            $meta ? $meta->unit($key) : ''
        );
    }

    private static function baueEintrag($raw, float $factor, string $format, string $text, string $unit): array
    {
        // Die alte Firmware liefert Wahrheitswerte als Zeichenkette 'true'/'false'.
        if (is_string($raw) && ($raw === 'true' || $raw === 'false')) {
            $raw = ($raw === 'true');
        }

        $sentinel = Keys::isSentinel(is_bool($raw) ? null : $raw);

        if ($sentinel) {
            // Der Sentinel wird VOR der Faktorrechnung abgefangen. Sonst stehen
            // -3276,8 Grad im Archiv, so wie heute bei PE_T_Abgas.
            $value = null;
        } elseif (is_bool($raw)) {
            $value = $raw;
        } elseif (is_numeric($raw)) {
            $value = ((float) $raw) * ($factor === 0.0 ? 1.0 : $factor);
        } else {
            $value = $raw;
        }

        return [
            'raw' => $raw,
            'factor' => $factor === 0.0 ? 1.0 : $factor,
            'format' => $format,
            'text' => $text,
            'unit' => $unit,
            'sentinel' => $sentinel,
            'value' => $value,
        ];
    }

    /**
     * Loest einen Schluessel nachgiebig auf. Schluesselnamen wandern zwischen
     * Firmwarestaenden: L_storage_popper wurde zu L_storage_hopper,
     * storage_fill_today zu L_pellets_today, und circ liefert je nach Stand
     * L_pump oder das falsch geschriebene L_pummp.
     *
     * Gibt den TATSAECHLICH vorhandenen Schluessel zurueck oder null.
     */
    public static function resolve(array $flat, string $key): ?string
    {
        if (isset($flat[$key])) {
            return $key;
        }
        $p = explode('.', $key, 2);
        if (count($p) !== 2) {
            return null;
        }
        $norm = Keys::normalize($key);
        foreach (Keys::ALIASES[$norm] ?? [] as $variante) {
            $kandidat = $p[0] . '.' . $variante;
            if (isset($flat[$kandidat])) {
                return $kandidat;
            }
        }
        return null;
    }

    /** Anzeigewert eines Schluessels, nachgiebig aufgeloest. null = nicht vorhanden oder Sentinel. */
    public static function value(array $flat, string $key)
    {
        $k = self::resolve($flat, $key);
        return $k === null ? null : $flat[$k]['value'];
    }

    /** Rohwert eines Schluessels, nachgiebig aufgeloest. */
    public static function raw(array $flat, string $key)
    {
        $k = self::resolve($flat, $key);
        return $k === null ? null : $flat[$k]['raw'];
    }

    /**
     * Baut aus dem Bereich error einen lesbaren Klartext.
     * Im Normalfall ist der Bereich leer und das Ergebnis eine leere Zeichenkette.
     */
    public static function errorText(array $data): string
    {
        $roh = $data['error'] ?? [];
        if (!is_array($roh) || $roh === []) {
            return '';
        }
        $zeilen = [];
        foreach ($roh as $eintrag) {
            if (is_array($eintrag)) {
                $eintrag = $eintrag['val'] ?? '';
            }
            $t = trim(str_replace(["\r", "\n"], ' ', (string) $eintrag));
            if ($t !== '') {
                $zeilen[] = $t;
            }
        }
        return implode(' / ', $zeilen);
    }
}
