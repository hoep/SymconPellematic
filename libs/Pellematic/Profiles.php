<?php

/**
 * Variablenprofile.
 *
 * Zwei Grundsaetze:
 *   1. Die seit 2024 bestehenden Profile oeko_Mode, oeko_Oekomode, oeko_Status,
 *      oeko_WWStatus, oeko_WWFuehler, oeko_ZeitProgramm,
 *      oeko_Puffer_Anforderung, oeko_Minuten, oeko_Binary sowie Stunden, kg,
 *      Prozent, prozent und prozent_int werden WEITERVERWENDET und niemals
 *      ueberschrieben. Sie haengen an 64 archivierten Variablen.
 *   2. Eigene Profile heissen OKP.*, damit sie nie kollidieren, und ensure()
 *      legt nur an, was fehlt.
 *
 * Jedes Standardprofil wird vor Gebrauch geprueft: ein unbekannter Profilname
 * laesst IPS_CreateVariable/IPS_CreateInstance wortlos scheitern. '~Precipitation'
 * gibt es zum Beispiel entgegen der Erwartung gar nicht.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Profiles
{
    /** Name => [Typ, Suffix, Nachkommastellen, min, max, Schrittweite] */
    private const EIGENE = [
        'OKP.Kilogramm' => [1, ' kg', 0, 0.0, 30000.0, 0.0],
        'OKP.Prozent' => [1, ' %', 0, 0.0, 100.0, 0.0],
        'OKP.Minuten' => [1, ' min', 0, 0.0, 1440.0, 0.0],
        'OKP.Stunden' => [1, ' h', 0, 0.0, 200000.0, 0.0],
        'OKP.Unterdruck' => [2, ' EH', 1, 0.0, 200.0, 0.0],
        'OKP.Sekunden' => [2, ' s', 2, 0.0, 3600.0, 0.0],
    ];

    /** Aufzaehlungsprofile, die feststehen und nicht aus format kommen muessen. */
    private const KESSELTYP = 'OKP.Kesseltyp';

    public static function ensure(): void
    {
        if (!function_exists('IPS_VariableProfileExists')) {
            return; // ohne Kernel gibt es nichts anzulegen
        }

        foreach (self::EIGENE as $name => [$typ, $suffix, $dig, $min, $max, $schritt]) {
            if (\IPS_VariableProfileExists($name)) {
                continue;
            }
            @\IPS_CreateVariableProfile($name, $typ);
            @\IPS_SetVariableProfileText($name, '', $suffix);
            @\IPS_SetVariableProfileDigits($name, $dig);
            @\IPS_SetVariableProfileValues($name, $min, $max, $schritt);
        }

        // Der Kesseltyp ist eine Aufzaehlung, die die Anlage mitliefert. Sie
        // wurde ueber die Firmwarestaende erweitert, deshalb steht sie hier mit
        // allen bekannten Eintraegen und einem Standardfall.
        if (!\IPS_VariableProfileExists(self::KESSELTYP)) {
            @\IPS_CreateVariableProfile(self::KESSELTYP, 1);
            $typen = [
                'PE', 'PES', 'PEK', 'PESK', 'SMART V1', 'SMART V2', 'CONDENS',
                'SMART XS', 'SMART V3', 'COMPACT', 'AIR', 'CONDENS XL', 'PELLEMATIC HOME',
            ];
            foreach ($typen as $i => $t) {
                @\IPS_SetVariableProfileAssociation(self::KESSELTYP, $i, $t, '', -1);
            }
        }
    }

    /**
     * Liefert den Profilnamen nur, wenn es ihn wirklich gibt - sonst den leeren
     * Namen. Ohne diese Pruefung scheitert das Anlegen der Variablen wortlos.
     */
    public static function exists(string $name): string
    {
        if ($name === '') {
            return '';
        }
        if (!function_exists('IPS_VariableProfileExists')) {
            return $name; // ohne Kernel gilt der Name als gut, damit Tests laufen
        }
        return \IPS_VariableProfileExists($name) ? $name : '';
    }

    /**
     * Baut aus dem format-String der Anlage ein Integer-Profil, aber nur wenn
     * dem Schluessel kein vorhandenes Profil zugeordnet ist. So bleibt das Modul
     * bei einem Firmwarewechsel lauffaehig: aendert die Anlage ihre Liste,
     * aendert sich das Profil mit.
     *
     * Gibt den zu verwendenden Profilnamen zurueck ('' = kein Profil).
     */
    public static function enumProfile(string $key, string $format): string
    {
        $bevorzugt = self::exists(Keys::profile($key));
        if ($bevorzugt !== '') {
            return $bevorzugt;
        }

        $map = Meta::parseFormat($format);
        if ($map === []) {
            return '';
        }

        $name = 'OKP.Enum_' . preg_replace('/[^A-Za-z0-9]/', '_', $key);
        if (!function_exists('IPS_VariableProfileExists')) {
            return $name;
        }

        if (!\IPS_VariableProfileExists($name)) {
            @\IPS_CreateVariableProfile($name, 1);
        }
        foreach ($map as $wert => $text) {
            @\IPS_SetVariableProfileAssociation($name, $wert, $text, '', -1);
        }
        return $name;
    }

    /** Der Profilname fuer den Kesseltyp, sofern angelegt. */
    public static function kesseltyp(): string
    {
        return self::exists(self::KESSELTYP);
    }
}
