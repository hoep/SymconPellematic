<?php

/**
 * Katalog der Anlagenschluessel.
 *
 * Der Katalog ist eine ERGAENZUNG, kein Zwang. Er liefert fuer bekannte
 * Schluessel eine schoene Beschriftung, den passenden IPS-Variablentyp, ein
 * bevorzugtes Profil und die Schreibklasse. Ein Schluessel, den er nicht kennt,
 * wird trotzdem verarbeitet: der Ident kommt aus dem Pfad, der Typ aus dem
 * Rohwert und das Profil aus dem format-String der Anlage. So bleibt das Modul
 * bei einem Firmwarewechsel lauffaehig, statt an einer fest verdrahteten Liste
 * zu zerbrechen.
 *
 * Faktoren, Grenzen und Aufzaehlungen stehen hier ABSICHTLICH nicht. Die kommen
 * ausschliesslich aus den Metadaten der Anlage (siehe Meta.php). Genau das ist
 * der Unterschied zum Altskript, das hart durch 10 teilt und dabei
 * PE_Freigabe_T um den Faktor 10 verschiebt.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Keys
{
    // IPS-Variablentypen
    public const T_BOOL = 0;
    public const T_INT = 1;
    public const T_FLOAT = 2;
    public const T_STRING = 3;

    // Schreibklassen. 'none' heisst: es gibt keine Freigabe, also wird nie
    // geschrieben. 'blocked' heisst: es gibt einen benannten Grund dagegen.
    public const W_NONE = 'none';
    public const W_COMFORT = 'comfort';
    public const W_MODES = 'modes';
    public const W_BUFFER = 'buffer';
    public const W_BLOCKED = 'blocked';

    // Statuscodes des Moduls. Sie stehen hier, weil Client, Parser und
    // module.php dieselben Codes brauchen und eine Wahrheit besser ist als drei.
    public const ST_ACTIVE = 102;       // Aktiv
    public const ST_IDLE = 104;         // Abfrage ausgeschaltet
    public const ST_NOHOST = 201;       // Adresse fehlt
    public const ST_AUTH = 202;         // Zugang abgewiesen, Passwort pruefen
    public const ST_RATE = 203;         // Taktgrenze bzw. zweite Abfrage erkannt
    public const ST_TRUNCATED = 204;    // Antwort unvollstaendig
    public const ST_UNREACHABLE = 205;  // Anlage nicht erreichbar

    // Sentinelwert der Anlage: der Fuehler ist nicht verbaut. Er darf NIE durch
    // die Faktorrechnung laufen, sonst stehen -3276,8 Grad im Archiv - genau so
    // sieht heute PE_T_Abgas (#<ID>) aus.
    public const SENTINEL = -32768;

    /**
     * Feste Sperrliste. Kein Schalter im Formular hebt sie auf.
     * Schluessel => Begruendung im Klartext, damit die Ablehnung erklaerbar ist.
     */
    public const BLOCKED = [
        'ww.sensor_on' => 'Ein ungueltiger Wert auf den Ein-/Abschaltfuehler erzeugte in der '
            . 'Home-Assistant-Integration (Issue 178) einen Geistersensor und die Stoerung 1020, '
            . 'die weder manuell noch automatisch quittierbar war. Es half nur zweimal '
            . 'Werksruecksetzung samt kompletter Neukonfiguration von Hand.',
        'ww.sensor_off' => 'Wie ww.sensor_on: ein ungueltiger Wert erzeugte dort die nicht quittierbare '
            . 'Stoerung 1020. Diese Anlage hat beide Fuehler auf 0:WW stehen; es gibt keinen Grund, das '
            . 'je anzufassen.',
        'hk.name' => 'Ein Umlaut in einem name-Feld bringt die Anlage dazu, die JSON-Antwort '
            . 'mitten im Datenstrom abzubrechen. Ein einziger Schreibvorgang koennte die gesamte '
            . 'Abfrage dauerhaft zerstoeren.',
        'ww.name' => 'Siehe hk.name.',
        'sk.name' => 'Siehe hk.name.',
        'circ.name' => 'Siehe hk.name.',
        'pe.storage_fill_today' => 'Statistikwert. Setzbar laut Praefixregel, aber ein Eingriff '
            . 'verfaelscht den Pelletzaehler #<ID>, der nur inkrementell existiert und nicht '
            . 'rekonstruierbar ist.',
        'pe.storage_fill_yesterday' => 'Siehe pe.storage_fill_today.',
    ];

    /**
     * Schluessel, deren Antwort nur gelesen und nie geschrieben wird, obwohl sie
     * kein L_-Praefix tragen. log0..log3 sind CSV-Dateien, keine Datenpunkte -
     * sie sind gross und bremsen die Steuerung.
     */
    public const NEVER_FETCH = ['log0', 'log1', 'log2', 'log3'];

    /**
     * Katalog. Schluessel ist der NORMALISIERTE Pfad, also ohne Bereichsindex:
     * hk1.temp_heat und hk2.temp_heat teilen sich den Eintrag hk.temp_heat.
     *
     * Aufbau je Eintrag:
     *   0 Beschriftung (Oberflaechentext, mit Umlauten)
     *   1 IPS-Variablentyp
     *   2 bevorzugtes vorhandenes Profil ('' = aus dem format-String bauen)
     *   3 Schreibklasse
     *   4 Merker: 'sentinel' = -32768 bedeutet kein Fuehler,
     *             'skipzero' = der Wert 0 wird nicht geschrieben
     */
    private const CATALOG = [
        // ---- system -------------------------------------------------------
        'system.L_ambient' => ['Außentemperatur Anlage', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'system.L_errors' => ['Anzahl Fehler', self::T_INT, '', self::W_NONE, ''],
        'system.L_usb_stick' => ['USB-Stick erkannt', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'system.L_existing_boiler' => ['Bestehender Kessel', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'system.L_boiler_temp' => ['Kesseltemperatur (System)', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'system.mode' => ['Betriebsart Anlage', self::T_INT, 'oeko_Mode', self::W_MODES, ''],

        // ---- weather ------------------------------------------------------
        'weather.L_temp' => ['Außentemperatur (Onlinewetter)', self::T_FLOAT, '~Temperature', self::W_NONE, ''],
        'weather.L_clouds' => ['Bewölkung', self::T_INT, 'OKP.Prozent', self::W_NONE, ''],
        'weather.L_forecast_temp' => ['Temperatur Prognose', self::T_FLOAT, '~Temperature', self::W_NONE, ''],
        'weather.L_forecast_clouds' => ['Bewölkung Prognose', self::T_INT, 'OKP.Prozent', self::W_NONE, ''],
        'weather.L_forecast_today' => ['Prognose Start', self::T_INT, '', self::W_NONE, ''],
        'weather.L_starttime' => ['Startzeit Prognose', self::T_INT, '', self::W_NONE, ''],
        'weather.L_endtime' => ['Endzeit Prognose', self::T_INT, '', self::W_NONE, ''],
        'weather.L_source' => ['Wetterquelle', self::T_STRING, '', self::W_NONE, ''],
        'weather.L_location' => ['Wetterort', self::T_STRING, '', self::W_NONE, ''],
        'weather.cloud_limit' => ['Bewölkungslimit', self::T_INT, 'OKP.Prozent', self::W_COMFORT, ''],
        'weather.hysteresys' => ['Abbruchtemperatur-Differenz', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'weather.offtemp' => ['Abschalttemperatur', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'weather.lead' => ['Vorhaltezeit', self::T_INT, 'OKP.Minuten', self::W_COMFORT, ''],
        'weather.refresh' => ['Wetter aktualisieren', self::T_BOOL, '~Switch', self::W_COMFORT, ''],
        'weather.oekomode' => ['Öko-Modus Wetter', self::T_INT, '', self::W_COMFORT, ''],

        // ---- forecast -----------------------------------------------------
        // Die 25 Textfelder tragen "Datum|Temp|Wolken|Wind|Bild|Code|Einheit".
        'forecast.L_w' => ['Vorhersage', self::T_STRING, '', self::W_NONE, ''],

        // ---- hk (Heizkreis) -----------------------------------------------
        'hk.L_roomtemp_act' => ['Raumtemperatur Ist', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'hk.L_roomtemp_set' => ['Raumtemperatur Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'hk.L_flowtemp_act' => ['Vorlauf Ist', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'hk.L_flowtemp_set' => ['Vorlauf Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'hk.L_comfort' => ['Komforttemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'hk.L_state' => ['Status', self::T_INT, 'oeko_Status', self::W_NONE, ''],
        'hk.L_statetext' => ['Statustext', self::T_STRING, '', self::W_NONE, ''],
        'hk.L_pump' => ['Heizkreispumpe', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'hk.sensor_avg' => ['Mittelung Raumfühler', self::T_INT, 'OKP.Minuten', self::W_COMFORT, ''],
        'hk.remote_override' => ['Fernbedienung (Versatz)', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'hk.mode_auto' => ['Betriebsart (Automatik)', self::T_INT, '', self::W_MODES, ''],
        'hk.mode_off' => ['Betriebsart (Anlage Aus)', self::T_INT, '', self::W_MODES, ''],
        'hk.mode_dhw' => ['Betriebsart (Warmwasser)', self::T_INT, '', self::W_MODES, ''],
        'hk.time_prg' => ['Zeitauswahl', self::T_INT, 'oeko_ZeitProgramm', self::W_COMFORT, ''],
        'hk.temp_setback' => ['Raumtemperatur Absenken', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'hk.temp_heat' => ['Raumtemperatur Heizen', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'hk.temp_vacation' => ['Raumtemperatur Urlaub', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'hk.name' => ['Anzeigename', self::T_STRING, '', self::W_BLOCKED, ''],
        'hk.oekomode' => ['Öko-Modus', self::T_INT, 'oeko_Oekomode', self::W_COMFORT, ''],

        // ---- pu (Puffer) --------------------------------------------------
        'pu.L_tpo_act' => ['Puffer oben Ist', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pu.L_tpo_set' => ['Puffer oben Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pu.L_tpm_act' => ['Puffer mitte Ist', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pu.L_tpm_set' => ['Puffer mitte Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pu.L_pump_release' => ['Pumpenfreigabe-Temperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pu.L_pump' => ['Drehzahl Speicherladepumpe', self::T_INT, 'OKP.Prozent', self::W_NONE, ''],
        'pu.L_state' => ['Status', self::T_INT, 'oeko_Puffer_Anforderung', self::W_NONE, ''],
        'pu.L_statetext' => ['Statustext', self::T_STRING, '', self::W_NONE, ''],
        'pu.mintemp_on' => ['Puffertemperatur min Ein', self::T_FLOAT, '~Temperature', self::W_BUFFER, ''],
        'pu.mintemp_off' => ['Puffertemperatur min Aus', self::T_FLOAT, '~Temperature', self::W_BUFFER, ''],
        'pu.ext_mintemp_on' => ['Puffertemperatur min Ein (extern)', self::T_FLOAT, '~Temperature', self::W_BUFFER, ''],
        'pu.ext_mintemp_off' => ['Puffertemperatur min Aus (extern)', self::T_FLOAT, '~Temperature', self::W_BUFFER, ''],

        // ---- ww (Warmwasser) ----------------------------------------------
        'ww.L_temp_set' => ['Wassertemperatur Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'ww.L_ontemp_act' => ['Einschaltfühler Ist', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'ww.L_offtemp_act' => ['Abschaltfühler Ist', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'ww.L_pump' => ['Ladepumpe', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'ww.L_state' => ['Status', self::T_INT, 'oeko_WWStatus', self::W_NONE, ''],
        'ww.L_statetext' => ['Statustext', self::T_STRING, '', self::W_NONE, ''],
        'ww.time_prg' => ['Zeitauswahl', self::T_INT, 'oeko_ZeitProgramm', self::W_COMFORT, ''],
        'ww.sensor_on' => ['Einschaltfühler', self::T_INT, 'oeko_WWFuehler', self::W_BLOCKED, ''],
        'ww.sensor_off' => ['Abschaltfühler', self::T_INT, 'oeko_WWFuehler', self::W_BLOCKED, ''],
        'ww.mode_auto' => ['Betriebsart (Automatik)', self::T_INT, '', self::W_MODES, ''],
        'ww.mode_off' => ['Betriebsart (Anlage Aus)', self::T_INT, '', self::W_MODES, ''],
        'ww.mode_dhw' => ['Betriebsart (Warmwasser)', self::T_INT, '', self::W_MODES, ''],
        'ww.heat_once' => ['Einmal aufbereiten', self::T_BOOL, '~Switch', self::W_COMFORT, ''],
        'ww.temp_min_set' => ['Wassertemperatur Min', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'ww.temp_max_set' => ['Wassertemperatur Soll/Max', self::T_FLOAT, '~Temperature', self::W_COMFORT, ''],
        'ww.name' => ['Anzeigename', self::T_STRING, '', self::W_BLOCKED, ''],
        'ww.smartstart' => ['Intelligenter Start', self::T_INT, 'OKP.Minuten', self::W_COMFORT, ''],
        'ww.use_boiler_heat' => ['Restwärmenutzung', self::T_BOOL, '~Switch', self::W_COMFORT, ''],
        'ww.oekomode' => ['Öko-Modus', self::T_INT, 'oeko_Oekomode', self::W_COMFORT, ''],

        // ---- pe (Kessel) --------------------------------------------------
        'pe.L_temp_act' => ['Kesseltemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pe.L_temp_set' => ['Kesseltemperatur Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pe.L_ext_temp' => ['Abgastemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pe.L_frt_temp_act' => ['Flammraumtemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pe.L_frt_temp_set' => ['Flammraumtemperatur Soll', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pe.L_frt_temp_end' => ['Flammraumendtemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'pe.L_br' => ['Brennerkontakt', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'pe.L_ak' => ['Bestehender Kessel (AK)', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'pe.L_not' => ['Not-Aus-Kette', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'pe.L_stb' => ['Sicherheitstemperaturbegrenzer', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'pe.L_modulation' => ['Modulationsstufe', self::T_INT, 'prozent_int', self::W_NONE, ''],
        'pe.L_runtimeburner' => ['Einschubzeit', self::T_FLOAT, '', self::W_NONE, ''],
        'pe.L_resttimeburner' => ['Pausenzeit', self::T_FLOAT, '', self::W_NONE, ''],
        'pe.L_currentairflow' => ['Lüfterdrehzahl', self::T_INT, 'prozent_int', self::W_NONE, ''],
        'pe.L_lowpressure' => ['Unterdruck', self::T_FLOAT, 'OKP.Unterdruck', self::W_NONE, ''],
        'pe.L_lowpressure_set' => ['Unterdruck Ende', self::T_FLOAT, 'OKP.Unterdruck', self::W_NONE, ''],
        'pe.L_fluegas' => ['Saugzugdrehzahl', self::T_INT, 'prozent_int', self::W_NONE, ''],
        'pe.L_uw_speed' => ['Drehzahl Umwälzpumpe', self::T_INT, 'prozent_int', self::W_NONE, ''],
        'pe.L_uw' => ['Drehzahl UW', self::T_INT, 'prozent_int', self::W_NONE, ''],
        'pe.L_uw_release' => ['Freigabetemperatur UW', self::T_FLOAT, '~Temperature', self::W_NONE, ''],
        // Kein Assoziationsprofil: der Kesselstatus liegt real ausserhalb der
        // eigenen format-Liste (heute 2147483648 bei Klartext "Aus").
        'pe.L_state' => ['Kesselstatus', self::T_INT, '', self::W_NONE, ''],
        'pe.L_statetext' => ['Kesselstatus Text', self::T_STRING, '', self::W_NONE, ''],
        'pe.L_type' => ['Kesseltyp', self::T_INT, 'OKP.Kesseltyp', self::W_NONE, ''],
        'pe.L_starts' => ['Brennerstarts', self::T_INT, '', self::W_NONE, ''],
        'pe.L_runtime' => ['Brennerlaufzeit', self::T_INT, 'OKP.Stunden', self::W_NONE, ''],
        'pe.L_avg_runtime' => ['Mittlere Laufzeit', self::T_INT, 'oeko_Minuten', self::W_NONE, ''],
        'pe.L_storage_fill' => ['Füllstand Lager', self::T_INT, 'OKP.Kilogramm', self::W_NONE, 'skipzero'],
        'pe.L_storage_min' => ['Lagerwarnung ab', self::T_INT, 'OKP.Kilogramm', self::W_NONE, ''],
        'pe.L_storage_max' => ['Lagerkapazität', self::T_INT, 'OKP.Kilogramm', self::W_NONE, ''],
        'pe.L_storage_popper' => ['Füllstand Zwischenbehälter', self::T_INT, 'OKP.Kilogramm', self::W_NONE, ''],
        'pe.storage_fill_today' => ['Pelletverbrauch heute', self::T_INT, 'OKP.Kilogramm', self::W_BLOCKED, 'skipzero'],
        'pe.storage_fill_yesterday' => ['Pelletverbrauch gestern', self::T_INT, 'OKP.Kilogramm', self::W_BLOCKED, ''],
        'pe.mode' => ['Betriebsart Kessel', self::T_INT, 'oeko_Mode', self::W_MODES, ''],

        // ---- circ / sk / se ------------------------------------------------
        'circ.L_pump' => ['Zirkulationspumpe', self::T_BOOL, '~Switch', self::W_NONE, ''],
        'circ.L_ret_temp' => ['Rücklauftemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'circ.L_state' => ['Status', self::T_INT, '', self::W_NONE, ''],
        'circ.L_statetext' => ['Statustext', self::T_STRING, '', self::W_NONE, ''],
        'sk.L_koll_temp' => ['Kollektortemperatur', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'sk.L_spu' => ['Speicher unten', self::T_FLOAT, '~Temperature', self::W_NONE, 'sentinel'],
        'sk.L_state' => ['Status', self::T_INT, '', self::W_NONE, ''],
        'sk.L_statetext' => ['Statustext', self::T_STRING, '', self::W_NONE, ''],
        'se.L_pwr' => ['Solarleistung', self::T_FLOAT, '~Power', self::W_NONE, ''],
        'se.L_total' => ['Solarertrag gesamt', self::T_FLOAT, '~Electricity', self::W_NONE, ''],
        'se.L_day' => ['Solarertrag heute', self::T_FLOAT, '~Electricity', self::W_NONE, ''],
    ];

    /**
     * Schreibvarianten, die zwischen Firmwarestaenden gewandert sind.
     * Links steht der Name, unter dem das Modul den Wert fuehrt, rechts die
     * Namen, unter denen die Anlage ihn liefern kann.
     */
    public const ALIASES = [
        'pe.L_storage_popper' => ['L_storage_popper', 'L_storage_hopper'],
        'pe.storage_fill_today' => ['storage_fill_today', 'L_pellets_today'],
        'pe.storage_fill_yesterday' => ['storage_fill_yesterday', 'L_pellets_yesterday'],
        'circ.L_pump' => ['L_pump', 'L_pummp'],
    ];

    /** Normalisiert "hk2.temp_heat" zu "hk.temp_heat" und "forecast.L_w_7" zu "forecast.L_w". */
    public static function normalize(string $key): string
    {
        $p = explode('.', $key, 2);
        if (count($p) !== 2) {
            return $key;
        }
        $sektion = rtrim($p[0], '0123456789');
        if ($sektion === '') {
            $sektion = $p[0];
        }
        $name = $p[1];
        if ($sektion === 'forecast' && preg_match('/^L_w_\d+$/', $name)) {
            $name = 'L_w';
        }
        return $sektion . '.' . $name;
    }

    /** Der Bereichsindex, also 2 bei hk2. Ohne Ziffer gilt 1. */
    public static function index(string $key): int
    {
        $sektion = explode('.', $key, 2)[0];
        return preg_match('/(\d+)$/', $sektion, $m) ? (int) $m[1] : 1;
    }

    /** Ident fuer IPS: der Pfad mit Unterstrich statt Punkt. Firmwarefest und kollisionsfrei. */
    public static function ident(string $key): string
    {
        return str_replace(['.', '-', ' '], '_', $key);
    }

    public static function known(string $key): bool
    {
        return isset(self::CATALOG[self::normalize($key)]);
    }

    /** Beschriftung; ohne Katalogeintrag faellt sie auf den text-String der Anlage zurueck. */
    public static function label(string $key, string $anlagentext = ''): string
    {
        $e = self::CATALOG[self::normalize($key)] ?? null;
        $basis = $e !== null ? $e[0] : ($anlagentext !== '' ? $anlagentext : $key);
        $idx = self::index($key);
        $sektion = rtrim(explode('.', $key, 2)[0], '0123456789');
        // Bei mehreren Kreisen gehoert die Nummer in den Namen, sonst heissen
        // zwei Variablen gleich und niemand weiss, welche der Erdgeschoss ist.
        if ($idx > 1 && in_array($sektion, ['hk', 'pu', 'ww', 'pe', 'sk', 'se', 'circ'], true)) {
            return $basis . ' ' . $idx;
        }
        return $basis;
    }

    /** IPS-Variablentyp; ohne Katalogeintrag aus dem Rohwert geraten. */
    public static function type(string $key, $rohwert = null): int
    {
        $e = self::CATALOG[self::normalize($key)] ?? null;
        if ($e !== null) {
            return $e[1];
        }
        if (is_bool($rohwert)) {
            return self::T_BOOL;
        }
        if (is_string($rohwert)) {
            return self::T_STRING;
        }
        if (is_float($rohwert)) {
            return self::T_FLOAT;
        }
        return self::T_INT;
    }

    /** Bevorzugtes vorhandenes Profil; leer heisst: aus dem format-String bauen. */
    public static function profile(string $key): string
    {
        $e = self::CATALOG[self::normalize($key)] ?? null;
        return $e !== null ? $e[2] : '';
    }

    /**
     * Schreibklasse. Die Praefixregel der Firmware schlaegt alles: was mit L_
     * beginnt, ist nie setzbar - auch L_storage_min und L_storage_max nicht,
     * die trotz Praefix min/max mitfuehren und dadurch faelschlich setzbar
     * aussehen.
     */
    public static function writeClass(string $key): string
    {
        $norm = self::normalize($key);
        if (isset(self::BLOCKED[$norm])) {
            return self::W_BLOCKED;
        }
        if (self::isReadOnlyByPrefix($key)) {
            return self::W_NONE;
        }
        $e = self::CATALOG[$norm] ?? null;
        // Ein unbekannter Parameter bekommt KEINE Klasse. Ohne Klasse gibt es
        // keine Freigabe, also wird er nie geschrieben. Das ist die sichere
        // Richtung fuer eine Hausheizung.
        return $e !== null ? $e[3] : self::W_NONE;
    }

    /** Die Regel der Firmware im Wortlaut: "only variables without a leading 'L_' can be set." */
    public static function isReadOnlyByPrefix(string $key): bool
    {
        $p = explode('.', $key, 2);
        $name = count($p) === 2 ? $p[1] : $key;
        return str_starts_with($name, 'L_');
    }

    public static function blockedReason(string $key): string
    {
        return self::BLOCKED[self::normalize($key)] ?? '';
    }

    public static function hasFlag(string $key, string $flag): bool
    {
        $e = self::CATALOG[self::normalize($key)] ?? null;
        return $e !== null && $e[4] === $flag;
    }

    /** Der Wert ist der Sentinel der Anlage: der Fuehler ist gar nicht verbaut. */
    public static function isSentinel($rohwert): bool
    {
        return is_numeric($rohwert) && (int) $rohwert === self::SENTINEL;
    }

    public static function all(): array
    {
        return self::CATALOG;
    }
}
