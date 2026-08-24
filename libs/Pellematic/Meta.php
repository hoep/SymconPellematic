<?php

/**
 * Metadatentabelle aus dem all?-Lauf.
 *
 * Die Anlage liefert zu jedem Datenpunkt unit, factor, min, max, format, text
 * und length gleich mit. Aus DIESER Tabelle kommen alle Faktoren, alle Grenzen
 * und alle Aufzaehlungen - nichts davon steht fest im Code. Das ist der
 * Unterschied zum Altskript: es teilt hart durch 10 und schreibt PE_Freigabe_T
 * seit 2024 um den Faktor 10 verschoben ins Archiv.
 *
 * Die Klasse ist kernel-frei und damit vollstaendig ohne Symcon testbar.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Meta
{
    /** Schluessel "sektion.name" => ['unit','factor','min','max','format','text','length'] */
    private array $tabelle = [];

    private function __construct(array $tabelle = [])
    {
        $this->tabelle = $tabelle;
    }

    public static function leer(): self
    {
        return new self([]);
    }

    /**
     * Baut die Tabelle aus einer bereits dekodierten all?-Antwort.
     * Antworten ohne Fragezeichen tragen keine Metadaten - dort bleibt die
     * Tabelle leer und factor() liefert 1.0, was fuer flache Antworten der
     * alten Firmware genau richtig ist.
     */
    public static function fromResponse(array $data): self
    {
        $t = [];
        foreach ($data as $sektion => $inhalt) {
            if (!is_array($inhalt)) {
                continue;
            }
            foreach ($inhalt as $name => $eintrag) {
                if (!is_array($eintrag)) {
                    continue; // L_statetext und die *_info-Beschriftungen sind flache Strings
                }
                if (!array_key_exists('val', $eintrag)) {
                    continue;
                }
                $t[$sektion . '.' . $name] = [
                    'unit' => (string) ($eintrag['unit'] ?? ''),
                    'factor' => isset($eintrag['factor']) ? (float) $eintrag['factor'] : 1.0,
                    'min' => array_key_exists('min', $eintrag) ? (float) $eintrag['min'] : null,
                    'max' => array_key_exists('max', $eintrag) ? (float) $eintrag['max'] : null,
                    'format' => (string) ($eintrag['format'] ?? ''),
                    'text' => (string) ($eintrag['text'] ?? ''),
                    'length' => array_key_exists('length', $eintrag) ? (int) $eintrag['length'] : null,
                ];
            }
        }
        return new self($t);
    }

    public static function fromArray(array $t): self
    {
        return new self($t);
    }

    public function toArray(): array
    {
        return $this->tabelle;
    }

    public function count(): int
    {
        return count($this->tabelle);
    }

    public function keys(): array
    {
        return array_keys($this->tabelle);
    }

    public function has(string $key): bool
    {
        return isset($this->tabelle[$key]);
    }

    /**
     * Faktor der Anlage. Ohne Meldung gilt 1.0 - lieber der Rohwert als ein
     * geratener Faktor, denn ein falscher Faktor faellt niemandem auf.
     */
    public function factor(string $key): float
    {
        $f = $this->tabelle[$key]['factor'] ?? 1.0;
        return ($f === 0.0) ? 1.0 : (float) $f;
    }

    public function unit(string $key): string
    {
        return (string) ($this->tabelle[$key]['unit'] ?? '');
    }

    public function text(string $key): string
    {
        return (string) ($this->tabelle[$key]['text'] ?? '');
    }

    public function format(string $key): string
    {
        return (string) ($this->tabelle[$key]['format'] ?? '');
    }

    public function length(string $key): ?int
    {
        return $this->tabelle[$key]['length'] ?? null;
    }

    /** Grenzen als ROHWERTE, so wie die Anlage sie meldet. null heisst: nicht gemeldet. */
    public function range(string $key): ?array
    {
        if (!isset($this->tabelle[$key])) {
            return null;
        }
        $min = $this->tabelle[$key]['min'];
        $max = $this->tabelle[$key]['max'];
        if ($min === null || $max === null) {
            return null;
        }
        return [(float) $min, (float) $max];
    }

    /** Aufzaehlung aus dem format-String: "0:Aus|1:Auto|2:Ein" wird zu [0=>'Aus',...]. */
    public function enumMap(string $key): array
    {
        return self::parseFormat($this->format($key));
    }

    public static function parseFormat(string $format): array
    {
        if ($format === '') {
            return [];
        }
        $out = [];
        foreach (explode('|', $format) as $stueck) {
            $p = explode(':', $stueck, 2);
            if (count($p) !== 2 || !is_numeric(trim($p[0]))) {
                continue;
            }
            $out[(int) trim($p[0])] = trim($p[1]);
        }
        return $out;
    }

    /**
     * Setzbar ausschliesslich ueber das fehlende L_-Praefix. Die Anlage sagt es
     * selbst so: "only variables without a leading 'L_' can be set."
     * min/max sind KEIN Hinweis auf Setzbarkeit - L_storage_min und
     * L_storage_max fuehren beides und sind trotzdem nur lesbar.
     */
    public function isWritable(string $key): bool
    {
        return !Keys::isReadOnlyByPrefix($key);
    }

    /** Anzeigewert zu Rohwert. Es geht IMMER der Rohwert an die Anlage. */
    public function toRaw(string $key, float $anzeigewert): int
    {
        return (int) round($anzeigewert / $this->factor($key));
    }

    /** Rohwert zu Anzeigewert. */
    public function toDisplay(string $key, $rohwert): float
    {
        return ((float) $rohwert) * $this->factor($key);
    }

    /** Anzeigegrenzen, also die Rohgrenzen mit dem Faktor multipliziert. */
    public function displayRange(string $key): ?array
    {
        $r = $this->range($key);
        if ($r === null) {
            return null;
        }
        $f = $this->factor($key);
        return [$r[0] * $f, $r[1] * $f];
    }
}
