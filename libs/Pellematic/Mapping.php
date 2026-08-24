<?php

/**
 * Die Vorbelegung: Anlagenschluessel -> bereits vorhandene Objekt-ID.
 *
 * Diese Tabelle ist der Grund, warum beim Umstieg keine Historie verlorengeht.
 * Das Modul legt unter "Hardware\Oekofen" (#<ID>) nichts an, benennt nichts um,
 * verschiebt nichts und ruehrt kein Logging an - es schreibt per SetValue in die
 * BESTEHENDE ID.
 *
 * Die Zuordnung laeuft ueber die ID, nicht ueber den Namen. Damit ist der stille
 * Bruch des Altskripts von vornherein ausgeschlossen: 20008 sucht seine Ziele
 * ueber IPS_GetVariableIDByName und schreibt nach einem Umbenennen wortlos ins
 * Leere.
 *
 * Die Funktion SCHLAEGT NUR VOR. Bestehende Eintraege des Nutzers werden nie
 * ueberschrieben.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Mapping
{
    /**
     * Schluessel => [Objekt-ID, erwarteter Name, Warnvermerk]
     * Der Name dient nur der Kontrolle beim Pruefen, nicht dem Auffinden.
     */
    public const VORBELEGUNG = [
        // -- system / weather ------------------------------------------------
        'weather.L_temp' => [56962, 'Aussen', 'Diese Reihe läuft seit 2014 und trägt die Prognose des '
            . 'Onlinewetters, nicht den Fühler der Anlage. Die Quelle darf nur bewusst umgestellt werden '
            . '(Formularfeld "Quelle für die Variable Aussen").'],
        'system.mode' => [46996, 'Betriebsart', ''],
        'system.L_errors' => [53921, 'Anzahl Fehler', ''],

        // -- hk1 = Erdgeschoss (von der Anlage selbst so benannt) -------------
        'hk1.L_roomtemp_set' => [31391, 'HK_EG_Raum_Soll', ''],
        'hk1.L_flowtemp_set' => [39734, 'HK_EG_VL_Soll', ''],
        'hk1.L_flowtemp_act' => [24962, 'HK_EG_VL_Ist', ''],
        'hk1.L_state' => [20704, 'HK_EG_Status', ''],
        'hk1.L_pump' => [10852, 'HK_EG_HKPumpe', ''],
        'hk1.time_prg' => [50175, 'HK_EG_ZeitProgramm', ''],
        'hk1.temp_setback' => [48798, 'HK_EG_Raum_Absenken', ''],
        'hk1.temp_heat' => [47536, 'HK_EG_Raum_Heizen', ''],
        'hk1.temp_vacation' => [46098, 'HK_EG_Raum_Soll_Urlaub', ''],
        'hk1.oekomode' => [29465, 'HK_EG_Oekomode', ''],

        // -- hk2 = Obergeschoss ----------------------------------------------
        'hk2.L_roomtemp_set' => [54341, 'HK_OG_Raum_Soll', ''],
        'hk2.L_flowtemp_set' => [35344, 'HK_OG_VL_Soll', ''],
        'hk2.L_flowtemp_act' => [19990, 'HK_OG_VL_Ist', ''],
        'hk2.L_state' => [48568, 'HK_OG_Status', 'Diese Variable ist vergiftet: das Altskript 10541 schreibt '
            . 'seit 2024 den Bitstring in die Integer-Variable, sie steht dauerhaft auf '
            . '-9223372036854775808. Die Archivreihe ist unbrauchbar, wird aber nicht gelöscht.'],
        'hk2.L_pump' => [16989, 'HK_OG_HKPumpe', ''],
        'hk2.time_prg' => [14556, 'HK_OG_ZeitProgramm', ''],
        'hk2.temp_setback' => [38055, 'HK_OG_Raum_Absenken', ''],
        'hk2.temp_heat' => [40749, 'HK_OG_Raum_Heizen', ''],
        'hk2.temp_vacation' => [47177, 'HK_OG_Raum_Soll_Urlaub', ''],
        'hk2.oekomode' => [25321, 'HK_OG_Oekomode', ''],

        // -- pu1 ---------------------------------------------------------------
        'pu1.L_state' => [47760, 'Puffer_Status', ''],
        'pu1.L_pump_release' => [42080, 'PufferT_Freigabe', ''],
        'pu1.L_tpm_act' => [16299, 'PufferT_Mitte', ''],
        'pu1.L_tpm_set' => [42015, 'PufferT_Mitte_Soll', ''],
        'pu1.L_tpo_act' => [52760, 'PufferT_Oben', ''],
        'pu1.L_tpo_set' => [36892, 'PufferT_Oben_Soll', 'ACHTUNG: die Reihe seit 2024-10-25 enthält in '
            . 'Wahrheit den ISTWERT - das Altskript schreibt hier L_tpo_act hinein. Ohne den Schalter '
            . '"PufferT_Oben_Soll künftig mit dem echten Sollwert füllen" bleibt es dabei.'],
        'pu1.L_pump' => [46937, 'Puffer Drehzahl Speicherladepumpe', ''],

        // -- ww1 ---------------------------------------------------------------
        'ww1.L_temp_set' => [44181, 'WW_Soll', ''],
        'ww1.L_ontemp_act' => [12866, 'WW_Ist', ''],
        'ww1.temp_max_set' => [56832, 'WW_Max_T', ''],
        'ww1.temp_min_set' => [17979, 'WW_Min_T', ''],
        'ww1.sensor_off' => [14305, 'WW_Abschaltfuehler', ''],
        'ww1.sensor_on' => [43350, 'WW_Einschaltfuehler', ''],
        'ww1.use_boiler_heat' => [48267, 'WW_Nutzung_Restwaerme', 'ACHTUNG Rückschreibpfad: jede Änderung '
            . 'dieser Variable startet über Ereignis #<ID> das Skript #<ID>, das den Wert an den Kessel '
            . 'zurückschickt. Erst das Ereignis stilllegen, dann diese Zeile aktivieren.'],
        'ww1.oekomode' => [29578, 'WW_OekoMode', ''],
        'ww1.L_pump' => [10146, 'WW_Pumpe', ''],
        'ww1.L_state' => [36293, 'WW_Status', ''],
        'ww1.time_prg' => [35484, 'WW_ZeitProgramm', ''],
        'ww1.heat_once' => [33646, 'WW_Heat_Once', ''],

        // -- pe1 ---------------------------------------------------------------
        'pe1.L_temp_act' => [36851, 'PE_Kessel_T', ''],
        'pe1.L_temp_set' => [36959, 'PE_Kessel_T_Soll', ''],
        'pe1.L_ext_temp' => [24597, 'PE_T_Abgas', 'Die Anlage meldet hier den Sentinel -32768: der Fühler '
            . 'ist nicht verbaut. Die Variable steht deshalb auf -3276,8 Grad. Die echte Abgastemperatur '
            . 'liefert die separate Instanz #<ID>, Kanal #<ID>.'],
        'pe1.L_frt_temp_act' => [48870, 'PE_Flammraum_T', ''],
        'pe1.L_frt_temp_set' => [25270, 'PE_Flammraum_T_Soll', ''],
        'pe1.L_frt_temp_end' => [14395, 'PE_Flammraum_T_End', ''],
        'pe1.L_modulation' => [25718, 'PE_Modulationsstufe', ''],
        'pe1.L_currentairflow' => [29750, 'PE_Luefter', ''],
        'pe1.L_fluegas' => [14703, 'PE_Saugzug', ''],
        'pe1.L_uw_speed' => [52312, 'PE_Umwaelzpumpe', ''],
        'pe1.L_state' => [44543, 'PE_KesselStatus', ''],
        'pe1.L_starts' => [13890, 'Brennerstarts', ''],
        'pe1.L_runtime' => [40871, 'Brenner Laufzeit', ''],
        'pe1.L_avg_runtime' => [43584, 'Brenner mittlere Laufzeit', ''],
        'pe1.L_uw_release' => [20314, 'PE_Freigabe_T', 'ACHTUNG: das Altskript übernimmt den Wert ohne den '
            . 'Faktor 0,1, die Variable steht auf 600 statt 60,0. Der Schalter "PE_Freigabe_T mit Faktor 0,1 '
            . 'füllen" erzeugt einen bewussten Sprung in der Archivreihe.'],
        'pe1.L_storage_fill' => [50789, 'Fuellstand Lager', ''],
        'pe1.L_storage_popper' => [38574, 'Fuellstand Zwischenbehaelter', ''],
        'pe1.mode' => [12714, 'PE_Betriebsart', ''],
        'pe1.storage_fill_today' => [40570, 'Pellet Verbrauch Heute', ''],
    ];

    /**
     * Abgeleitete Ziele. Das sind keine Anlagenschluessel, deshalb tragen sie
     * einen eigenen Namensraum. Sie stehen in derselben Zuordnungsliste, damit
     * der Nutzer auch sie umhaengen kann, ohne in den Code zu greifen.
     */
    public const ABGELEITET = [
        'derived.fill_percent' => [39636, 'Fuellstand %', 'Rechnet künftig gegen die von der Anlage '
            . 'gemeldete Lagerkapazität statt gegen die fest verdrahteten 6000 kg.'],
        'derived.starts_today' => [31634, 'Brennerstarts Heute', ''],
        'derived.runtime_today' => [47540, 'Brenner Laufzeit Heute', ''],
        'derived.pellets_total' => [53289, 'Pellet Verbrauch gesamt', 'Der Zählerstand existiert NUR in '
            . 'dieser Variable und ist nicht rekonstruierbar. Er wird übernommen und fortgeschrieben, nie '
            . 'zurückgesetzt. An dieser ID hängt außerdem die einzige LiveViewBuilder-Bindung.'],
        'derived.pellets_kwh' => [28670, 'Pellet_Umrechnung_kWh', ''],
        'derived.burner_running' => [58460, 'PE_Brenner_Betrieb', 'Kommt künftig aus dem Brennerkontakt '
            . 'pe1.L_br statt aus der Modulation.'],
        'derived.buffer_pump' => [56611, 'Puffer_Speicherladepumpe', ''],
        'derived.hk1_state_bin' => [48590, 'HK_EG_Status_Bin', ''],
        'derived.hk1_state_text' => [47213, 'HK_EG_StatusText', ''],
        'derived.hk2_state_bin' => [19153, 'HK_OG_Status_Bin', ''],
        'derived.hk2_state_text' => [20853, 'HK_OG_StatusText', ''],
        'derived.ww1_state_bin' => [43547, 'WW_Status_Bin', ''],
        'derived.ww1_state_text' => [35701, 'WW_StatusText', ''],
        'derived.pu1_state_bin' => [34944, 'Puffer_Status_Bin', ''],
        'derived.pu1_state_text' => [46054, 'Puffer_StatusText', ''],
        'derived.pe1_state_bin' => [26928, 'PE_KesselStatus_Bin', ''],
    ];

    /** Welcher Anlagenschluessel speist eine abgeleitete Groesse? Nur zur Anzeige. */
    public const ABGELEITET_QUELLE = [
        'derived.fill_percent' => 'pe1.L_storage_fill / pe1.L_storage_max',
        'derived.starts_today' => 'pe1.L_starts',
        'derived.runtime_today' => 'pe1.L_runtime',
        'derived.pellets_total' => 'pe1.storage_fill_today',
        'derived.pellets_kwh' => 'derived.pellets_total',
        'derived.burner_running' => 'pe1.L_br',
        'derived.buffer_pump' => 'pu1.L_pump',
        'derived.hk1_state_bin' => 'hk1.L_state',
        'derived.hk1_state_text' => 'hk1.L_statetext',
        'derived.hk2_state_bin' => 'hk2.L_state',
        'derived.hk2_state_text' => 'hk2.L_statetext',
        'derived.ww1_state_bin' => 'ww1.L_state',
        'derived.ww1_state_text' => 'ww1.L_statetext',
        'derived.pu1_state_bin' => 'pu1.L_state',
        'derived.pu1_state_text' => 'pu1.L_statetext',
        'derived.pe1_state_bin' => 'pe1.L_state',
    ];

    /** Alle Vorschlagszeilen in einer Tabelle. */
    public static function alle(): array
    {
        return self::VORBELEGUNG + self::ABGELEITET;
    }

    /**
     * Baut den Vorschlag fuer den Knopf "Zuordnung vorbelegen".
     *
     * @param array $bestehend Die bereits gepflegte Liste aus dem Formular.
     * @return array{rows: array, bericht: string}
     */
    public static function vorschlag(array $bestehend): array
    {
        $belegt = [];
        $vorhandeneSchluessel = [];
        foreach ($bestehend as $z) {
            $k = (string) ($z['Key'] ?? '');
            if ($k !== '') {
                $vorhandeneSchluessel[$k] = true;
            }
            $vid = (int) ($z['VarID'] ?? 0);
            if ($vid > 0) {
                $belegt[$vid] = $k;
            }
        }

        $rows = $bestehend;
        $neu = 0;
        $uebersprungen = 0;
        $zeilen = [];

        foreach (self::alle() as $key => [$id, $name, $warnung]) {
            if (isset($vorhandeneSchluessel[$key])) {
                $uebersprungen++;
                continue; // nichts Bestehendes ueberschreiben
            }

            $vermerk = self::pruefeZiel($id, $name);
            if (isset($belegt[$id])) {
                $vermerk = 'Doppelbelegung: #' . $id . ' ist bereits "' . $belegt[$id] . '" zugeordnet. '
                    . 'Zeile nicht angelegt.';
                $zeilen[] = sprintf('  %-28s -> #%-6d %s', $key, $id, $vermerk);
                continue;
            }

            $rows[] = [
                'Key' => $key,
                'Caption' => $name,
                'VarID' => $id,
                'Factor' => 0.0,
                // Die drei Zeilen mit Warnvermerk kommen INAKTIV herein. Wer sie
                // will, schaltet sie bewusst ein.
                'Active' => ($warnung === ''),
            ];
            $belegt[$id] = $key;
            $neu++;
            $zeilen[] = sprintf('  %-28s -> #%-6d %s%s', $key, $id, $name,
                $warnung === '' ? '' : "\n      HINWEIS: " . $warnung . ' (Zeile inaktiv angelegt)');
        }

        $bericht = 'Zuordnung vorbelegen: ' . $neu . ' Zeilen vorgeschlagen, '
            . $uebersprungen . " bereits vorhandene unverändert gelassen.\n" . implode("\n", $zeilen);

        return ['rows' => $rows, 'bericht' => $bericht];
    }

    /** Existenz und Typ eines Ziels pruefen - nur lesend. */
    private static function pruefeZiel(int $id, string $erwarteterName): string
    {
        if (!function_exists('IPS_VariableExists')) {
            return '';
        }
        if (!@\IPS_VariableExists($id)) {
            return 'Variable #' . $id . ' gibt es nicht (mehr).';
        }
        $istName = (string) @\IPS_GetName($id);
        if ($istName !== $erwarteterName) {
            return 'Name weicht ab: erwartet "' . $erwarteterName . '", gefunden "' . $istName . '".';
        }
        return '';
    }

    public static function warnung(string $key): string
    {
        $e = self::VORBELEGUNG[$key] ?? self::ABGELEITET[$key] ?? null;
        return $e === null ? '' : (string) $e[2];
    }

    public static function id(string $key): int
    {
        $e = self::VORBELEGUNG[$key] ?? self::ABGELEITET[$key] ?? null;
        return $e === null ? 0 : (int) $e[0];
    }

    public static function name(string $key): string
    {
        $e = self::VORBELEGUNG[$key] ?? self::ABGELEITET[$key] ?? null;
        return $e === null ? '' : (string) $e[1];
    }

    public static function istAbgeleitet(string $key): bool
    {
        return str_starts_with($key, 'derived.');
    }
}
