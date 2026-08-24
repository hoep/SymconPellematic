<?php

/**
 * Pellematic (OKP) - eine Pelletsheizung als IP-Symcon-Modul.
 *
 * Kommentare deutsch OHNE Umlaute, Oberflaechentexte MIT Umlauten.
 *
 * Das Modul hat drei Betriebsstufen. Im Auslieferungszustand steht es auf
 * Trockenlauf: es liest, rechnet und fuellt NUR seine eigenen
 * Diagnosevariablen. Es schreibt in keine einzige bestehende Variable und es
 * schreibt nichts an die Heizung.
 *
 * Grundsaetze, die im Code durchgehalten werden:
 *   - Faktoren, Grenzen und Aufzaehlungen kommen IMMER aus den Metadaten der
 *     Anlage, nie aus dem Code.
 *   - Bei jedem Fehler bleibt jeder Wert stehen. Es wird niemals eine Null
 *     geschrieben, nur weil die Abfrage misslungen ist.
 *   - Unter "Hardware\Oekofen" (#<ID>) wird nichts angelegt, nichts umbenannt,
 *     nichts verschoben und am Logging nichts veraendert. Dort wird
 *     ausschliesslich per SetValue in bestehende IDs geschrieben.
 *   - Das Passwort ist Pfadbestandteil der URL und wird in jeder Meldung
 *     maskiert.
 */

declare(strict_types=1);

require_once __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Client;
use Hoep\Pellematic\Derived;
use Hoep\Pellematic\Forecast;
use Hoep\Pellematic\Keys;
use Hoep\Pellematic\Mapping;
use Hoep\Pellematic\Meta;
use Hoep\Pellematic\Parser;
use Hoep\Pellematic\Profiles;
use Hoep\Pellematic\StateBits;
use Hoep\Pellematic\WriteGuard;

class Pellematic extends IPSModule
{
    /** Fremd-GUID, siehe GUIDS.md. Hier im Haus ist das Instanz #<ID>. */
    private const GUID_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    /** Zyklisches Ereignis des Altskripts 20008, Takt 30 Sekunden. */
    private const EVENT_ALTPOLL = 26657;

    /** Ereignis unter WW_Nutzung_Restwaerme (#<ID>), startet den Rueckschreibpfad 52867. */
    private const EVENT_RUECKSCHREIB = 44436;

    /** Der Ordner, in dem die bestehenden Variablen liegen. Nur zur Anzeige. */
    private const ORDNER_ALT = 41584;

    /** Betriebsstufen. */
    private const MODE_TROCKEN = 0;
    private const MODE_SPIEGEL = 1;
    private const MODE_ALLEIN = 2;

    // ================================================================ Create

    public function Create()
    {
        parent::Create();

        // --- Verbindung ---------------------------------------------------
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 4321);
        // Zugangsdaten gehoeren NICHT in den Quelltext: das Repo geht auf GitHub,
        // und das Passwort ist bei dieser Schnittstelle Teil des URL-Pfads.
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Timeout', 10);
        $this->RegisterPropertyInteger('MinGapMs', 2600);
        $this->RegisterPropertyInteger('Retries', 1);

        // --- Abfrage ------------------------------------------------------
        $this->RegisterPropertyInteger('Mode', self::MODE_TROCKEN);
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyInteger('FetchMode', 0);
        $this->RegisterPropertyBoolean('SkipForecast', true);
        $this->RegisterPropertyInteger('MetaRefreshMin', 720);
        $this->RegisterPropertyBoolean('WarnForeignPoll', true);

        // --- Vorhandene Variablen ----------------------------------------
        $this->RegisterPropertyBoolean('UseExisting', true);
        $this->RegisterPropertyString('Mapping', '[]');
        $this->RegisterPropertyBoolean('CreateMissing', false);
        $this->RegisterPropertyBoolean('ForecastJson', true);
        $this->RegisterPropertyBoolean('CreateLinks', true);
        $this->RegisterPropertyBoolean('LogNew', false);
        $this->RegisterPropertyInteger('ArchiveID', 0);

        // --- Bekannte Fehler des Altsystems -------------------------------
        // Die drei Korrekturen sind am 24.08.2026 ausdruecklich freigegeben worden.
        // Jede erzeugt beim ersten Schreiben einen sichtbaren Sprung in der Kurve -
        // das Datum steht in docs/HISTORIE.md, sonst ist die Reihe spaeter nicht
        // deutbar. Wer den alten Stand fortfuehren will, schaltet sie hier aus.
        $this->RegisterPropertyBoolean('FixTpoSet', true);
        $this->RegisterPropertyBoolean('FixFreigabeT', true);
        $this->RegisterPropertyInteger('AmbientSource', 0);
        $this->RegisterPropertyBoolean('SkipSentinel', true);

        // --- Abgeleitete Groessen -----------------------------------------
        $this->RegisterPropertyBoolean('Derived', true);
        $this->RegisterPropertyInteger('StorageMaxSource', 0);
        $this->RegisterPropertyInteger('StorageMaxKg', 6000);
        $this->RegisterPropertyFloat('KwhPerKg', 4.8);
        $this->RegisterPropertyFloat('TotalStartKg', 0.0);

        // --- Schreiben (im Auslieferungszustand vollstaendig aus) ----------
        $this->RegisterPropertyBoolean('WriteEnabled', false);
        $this->RegisterPropertyBoolean('WriteComfort', false);
        $this->RegisterPropertyBoolean('WriteModes', false);
        $this->RegisterPropertyBoolean('WriteBuffer', false);
        $this->RegisterPropertyFloat('WwMaxCelsius', 60.0);
        $this->RegisterPropertyFloat('HkMinCelsius', 14.0);
        $this->RegisterPropertyFloat('HkMaxCelsius', 24.0);
        $this->RegisterPropertyBoolean('WriteVerify', true);
        $this->RegisterPropertyInteger('WriteMaxPerHour', 20);
        $this->RegisterPropertyBoolean('ActionOnVars', false);
        $this->RegisterPropertyBoolean('WriteLog', true);

        // --- Diagnose ------------------------------------------------------
        $this->RegisterPropertyBoolean('Debug', false);

        // --- Attribute (Laufzeitzustand, ueberlebt Prozesse) ---------------
        $this->RegisterAttributeInteger('LastRequestMs', 0);
        $this->RegisterAttributeString('Meta', '{}');
        $this->RegisterAttributeInteger('MetaFetched', 0);
        $this->RegisterAttributeInteger('LastRun', 0);
        $this->RegisterAttributeInteger('FailCount', 0);
        $this->RegisterAttributeInteger('LastOkTs', 0);
        $this->RegisterAttributeInteger('StartsAtMidnight', -1);
        $this->RegisterAttributeInteger('RuntimeAtMidnight', -1);
        $this->RegisterAttributeInteger('LastToday', 0);
        $this->RegisterAttributeInteger('YesterdayCredited', 0);
        $this->RegisterAttributeFloat('TotalKg', -1.0);
        $this->RegisterAttributeString('WriteLogRing', '[]');
        $this->RegisterAttributeInteger('RateHits', 0);
        $this->RegisterAttributeString('DayStamp', '');
        $this->RegisterAttributeString('PelletDay', '');
        $this->RegisterAttributeString('LastWarn', '');
        $this->RegisterAttributeInteger('PlainAllOk', 0);
        $this->RegisterAttributeString('LastFlat', '{}');

        // --- Diagnosevariablen ---------------------------------------------
        $this->RegisterVariableInteger('LastRead', 'Zuletzt gelesen', '~UnixTimestamp', 10);
        $this->RegisterVariableBoolean('Online', 'Erreichbar', '~Alert.Reversed', 20);
        $this->RegisterVariableString('Error', 'Letzter Fehler', '', 30);
        $this->RegisterVariableFloat('Duration', 'Antwortzeit', '', 40);
        $this->RegisterVariableInteger('RateHits', 'Taktfehler', '', 50);
        $this->RegisterVariableString('DeviceType', 'Anlagentyp', '', 60);
        $this->RegisterVariableString('ErrorText', 'Aktive Störungen', '', 70);
        $this->RegisterVariableString('LastWrite', 'Letzte Schreibaktion', '', 80);
        // Die Vorhersage als EIN JSON statt als 25 Textvariablen - im Format, das
        // das Wetter-Widget des LiveViewBuilders liest (OpenWeatherMap One-Call).
        // Die Anlage bezieht ihre Vorhersage ohnehin von dort.
        $this->RegisterVariableString('Forecast', 'Wettervorhersage (JSON)', '', 90);

        $this->RegisterTimer('Poll', 0, 'OKP_Poll($_IPS[\'TARGET\']);');
    }

    // ========================================================== ApplyChanges

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        Profiles::ensure();

        // Aendert sich die Adresse, sind die gemerkten Metadaten wertlos: sie
        // gehoeren zu einer anderen Anlage oder zu einem anderen Zugang.
        $signatur = $this->ReadPropertyString('Host') . '|' . $this->ReadPropertyInteger('Port')
            . '|' . md5($this->ReadPropertyString('Password'));
        $gemerkt = json_decode($this->ReadAttributeString('Meta'), true);
        if (is_array($gemerkt) && ($gemerkt['_sig'] ?? '') !== $signatur) {
            $this->WriteAttributeString('Meta', '{}');
            $this->WriteAttributeInteger('MetaFetched', 0);
            $this->WriteAttributeInteger('PlainAllOk', 0);
        }

        $this->SetStatus($this->ermittleStatus());

        $iv = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Poll', $iv * 1000);

        if ($this->ReadPropertyBoolean('CreateLinks')) {
            $this->syncLinks();
        }

        if ($this->ReadPropertyBoolean('ActionOnVars')) {
            $this->enableActions();
        }

        // Der Hinweis auf die zweite Abfragestelle gehoert ins Log, aber nicht bei
        // JEDEM Uebernehmen: im Trockenlauf ist das Nebeneinander gewollt und
        // harmlos, und wer die Einstellungen ein Dutzend Mal speichert, hat sonst
        // ein Dutzend Warnungen im Log stehen. Erst ab dem Spiegelbetrieb wird es
        // eine Aussage - und auch dann nur, wenn sie sich seit dem letzten Mal
        // geaendert hat.
        if ($this->ReadPropertyBoolean('WarnForeignPoll') && $this->ReadPropertyInteger('Mode') >= 1) {
            $hinweis = $this->pruefeFremdabfrage();
            if ($hinweis !== '' && $hinweis !== $this->ReadAttributeString('LastWarn')) {
                $this->LogMessage($hinweis, KL_WARNING);
            }
            $this->WriteAttributeString('LastWarn', $hinweis);
        }
    }

    /** Der Status haengt allein an der Einrichtung; Fehler setzt der Poll. */
    private function ermittleStatus(): int
    {
        if (trim($this->ReadPropertyString('Host')) === '') {
            return Keys::ST_NOHOST;
        }
        if ($this->ReadPropertyInteger('Interval') === 0) {
            return Keys::ST_IDLE;
        }
        return Keys::ST_ACTIVE;
    }

    /**
     * Rein lesende Pruefung, ob noch eine zweite Stelle dieselbe Anlage abfragt.
     * Das ist der haeufigste Grund fuer HTTP 401: jede Seite haelt fuer sich die
     * 2500 ms ein, zusammen nicht.
     */
    private function pruefeFremdabfrage(): string
    {
        if (!function_exists('IPS_EventExists') || !@IPS_EventExists(self::EVENT_ALTPOLL)) {
            return '';
        }
        $e = @IPS_GetEvent(self::EVENT_ALTPOLL);
        if (!is_array($e) || empty($e['EventActive'])) {
            return '';
        }
        return 'Pellematic: das Ereignis #' . self::EVENT_ALTPOLL . ' des Altskripts #<ID> ist aktiv und '
            . 'fragt dieselbe Anlage alle 30 Sekunden ab. Zusammen mit dem Modul kann das die Taktgrenze '
            . 'der Anlage reißen (HTTP 401). Im Spiegelbetrieb ist das gewollt, im Alleinbetrieb muss das '
            . 'Ereignis still werden.';
    }

    // ================================================================== Poll

    /**
     * Eine Abfragerunde.
     *
     * Bei jedem Fehler gilt: KEINE Variable wird geschrieben, schon gar nicht
     * auf 0. Der letzte gute Wert bleibt stehen, "Erreichbar" geht auf false
     * und "Letzter Fehler" traegt Klartext plus Uhrzeit.
     */
    public function Poll(): void
    {
        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(Keys::ST_NOHOST);
            return;
        }

        $client = $this->client();

        // --- Metadaten holen, wenn faellig ---------------------------------
        $meta = $this->holeMetaWennFaellig($client);

        // --- Nutzdaten holen ------------------------------------------------
        $runde = $this->holeDaten($client, $meta);

        if (!$runde['ok']) {
            $this->fehlerbehandlung($runde['code'], $runde['error'], $runde['ms'] ?? 0.0);
            return;
        }

        $flat = $runde['flat'];
        $this->WriteAttributeString('LastFlat', json_encode($flat, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        // --- Diagnose immer, in jeder Betriebsstufe -------------------------
        $this->diagnose($runde, $flat, $meta);

        // --- Spiegel- und Alleinbetrieb schreiben in die bestehenden IDs ----
        $geschrieben = 0;
        if ($this->ReadPropertyInteger('Mode') >= self::MODE_SPIEGEL && $this->ReadPropertyBoolean('UseExisting')) {
            $geschrieben = $this->schreibeGespiegelt($flat, $meta);
        }

        // --- Eigene Variablen unter der Instanz ------------------------------
        if ($this->ReadPropertyBoolean('CreateMissing')) {
            $this->pflegeEigeneVariablen($flat, $meta);
        }

        // --- Abgeleitete Groessen -------------------------------------------
        if ($this->ReadPropertyBoolean('Derived')) {
            $this->rechneAbgeleitet($flat, $meta);
        }

        if ($this->ReadPropertyBoolean('ForecastJson')) {
            $vh = Forecast::owm($flat);
            if ($vh !== []) {
                $this->setzeEigene('Forecast', (string) json_encode($vh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        // --- Rueckkehr aus dem Rueckzug -------------------------------------
        if ($this->ReadAttributeInteger('FailCount') > 0) {
            $this->WriteAttributeInteger('FailCount', 0);
            $this->SetTimerInterval('Poll', max(0, $this->ReadPropertyInteger('Interval')) * 1000);
        }
        $this->WriteAttributeInteger('LastRun', time());
        $this->WriteAttributeInteger('LastOkTs', time());   // Bezugspunkt fuer "noch frisch"
        $this->SetStatus($this->ReadPropertyInteger('Interval') === 0 ? Keys::ST_IDLE : Keys::ST_ACTIVE);

        $this->debug('Poll', sprintf('%d Datenpunkte, %d gespiegelt, %.0f ms',
            count($flat), $geschrieben, (float) ($runde['ms'] ?? 0)));
    }

    /** Baut den Client und haengt die Taktbremse an das Attribut. */
    private function client(): Client
    {
        $c = new Client(
            trim($this->ReadPropertyString('Host')),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyInteger('Timeout'),
            $this->ReadPropertyInteger('MinGapMs')
        );
        // Der Zeitstempel liegt im Attribut, damit Poll, Formular-Probelauf und
        // Schreibvorgang sich ueber Prozessgrenzen hinweg nicht ueberholen.
        $c->setRateStore(
            fn (): int => $this->ReadAttributeInteger('LastRequestMs'),
            fn (int $ms) => $this->WriteAttributeInteger('LastRequestMs', $ms)
        );
        return $c;
    }

    /** Metadaten aus dem Attribut, notfalls frisch geholt. */
    private function meta(): Meta
    {
        $roh = json_decode($this->ReadAttributeString('Meta'), true);
        if (!is_array($roh)) {
            return Meta::leer();
        }
        unset($roh['_sig']);
        return Meta::fromArray($roh);
    }

    private function holeMetaWennFaellig(Client $client): Meta
    {
        $meta = $this->meta();
        $alter = time() - $this->ReadAttributeInteger('MetaFetched');
        $takt = $this->ReadPropertyInteger('MetaRefreshMin');

        $faellig = ($meta->count() === 0) || ($takt > 0 && $alter >= $takt * 60);
        if (!$faellig) {
            return $meta;
        }

        $r = $client->readAll(true, $this->ReadPropertyInteger('Retries'));
        if (!$r['ok']) {
            // Ohne frische Metadaten laeuft das Modul mit den alten weiter. Das
            // ist besser als gar nicht zu laufen - und wenn es noch keine gibt,
            // faellt das im naechsten Schritt ohnehin auf.
            $this->debug('Meta', 'nicht erneuert: ' . $r['error']);
            return $meta;
        }

        $d = Parser::decode($r['body']);
        if (!$d['ok']) {
            $this->debug('Meta', 'nicht lesbar: ' . $d['error']);
            return $meta;
        }

        $neu = Meta::fromResponse($d['data']);
        if ($neu->count() === 0) {
            // Diese Firmware liefert bei all? keine Metadaten. Dann bleibt es
            // beim Rohwert, Faktor 1 - lieber roh als geraten.
            $this->debug('Meta', 'Antwort enthält keine Metadaten (alte Firmware).');
            return $meta;
        }

        $tabelle = $neu->toArray();
        $tabelle['_sig'] = $this->ReadPropertyString('Host') . '|' . $this->ReadPropertyInteger('Port')
            . '|' . md5($this->ReadPropertyString('Password'));
        $this->WriteAttributeString('Meta', json_encode($tabelle, JSON_UNESCAPED_UNICODE));
        $this->WriteAttributeInteger('MetaFetched', time());
        $this->debug('Meta', $neu->count() . ' Datenpunkte beschrieben.');

        return $neu;
    }

    /**
     * Holt die Nutzdaten - je nach Einstellung in einem Zug oder Bereich fuer
     * Bereich.
     *
     * Bereichsweise kostet wegen der Taktgrenze rund 20 Sekunden je Runde,
     * rettet aber bei einem Umlautabbruch die gesunden Bloecke: faellt nur ww1
     * aus, bleiben die WW-Variablen unveraendert und alle anderen laufen weiter.
     */
    private function holeDaten(Client $client, Meta $meta): array
    {
        $retries = $this->ReadPropertyInteger('Retries');

        if ($this->ReadPropertyInteger('FetchMode') === 1) {
            return $this->holeBereichsweise($client, $meta, $retries);
        }

        // Ohne Fragezeichen ist die Antwort kleiner. Ob diese Firmware das
        // Format ueberhaupt liefert, ist an dieser Anlage nicht gemessen -
        // deshalb wird es einmal versucht und das Ergebnis gemerkt.
        $plain = $this->ReadAttributeInteger('PlainAllOk');
        $mitMeta = ($plain !== 1) || $meta->count() === 0;

        $r = $client->readAll($mitMeta, $retries);
        if (!$r['ok']) {
            return ['ok' => false, 'code' => $r['code'], 'error' => $r['error'], 'ms' => $r['ms']];
        }

        $d = Parser::decode($r['body']);
        if (!$d['ok']) {
            return ['ok' => false, 'code' => $d['code'], 'error' => $d['error'], 'ms' => $r['ms']];
        }

        $flat = Parser::flatten($d['data'], $meta);

        if (!$mitMeta && $meta->count() > 0 && count($flat) < (int) ($meta->count() / 2)) {
            // Die flache Form liefert zu wenig - ab jetzt wieder mit Fragezeichen.
            $this->WriteAttributeInteger('PlainAllOk', -1);
            return ['ok' => false, 'code' => Keys::ST_TRUNCATED,
                'error' => 'Die Kurzform "all" lieferte nur ' . count($flat) . ' Datenpunkte. '
                    . 'Ab sofort wird wieder "all?" verwendet.', 'ms' => $r['ms']];
        }
        if ($mitMeta && $plain === 0) {
            $this->WriteAttributeInteger('PlainAllOk', 1);
        }

        if ($this->ReadPropertyBoolean('SkipForecast')) {
            $flat = $this->ohneForecast($flat);
        }

        return ['ok' => true, 'code' => Keys::ST_ACTIVE, 'error' => '', 'ms' => $r['ms'],
            'flat' => $flat, 'data' => $d['data'], 'bereiche' => Parser::sections($d['data'])];
    }

    private function holeBereichsweise(Client $client, Meta $meta, int $retries): array
    {
        // Welche Bereiche es gibt, sagt die Anlage - abgeleitet aus den
        // Metadaten, nicht fest verdrahtet.
        $bereiche = [];
        foreach ($meta->keys() as $k) {
            $bereiche[explode('.', $k, 2)[0]] = true;
        }
        if ($bereiche === []) {
            $bereiche = ['system' => true, 'weather' => true, 'hk1' => true, 'pu1' => true,
                'ww1' => true, 'pe1' => true];
        }
        if ($this->ReadPropertyBoolean('SkipForecast')) {
            unset($bereiche['forecast']);
        }
        $bereiche['error'] = true;

        $flat = [];
        $data = [];
        $ms = 0.0;
        $fehler = [];
        foreach (array_keys($bereiche) as $sektion) {
            $r = $client->readSection($sektion, true, $retries);
            $ms += (float) $r['ms'];
            if (!$r['ok']) {
                $fehler[] = $sektion . ': ' . $r['error'];
                continue;
            }
            $d = Parser::decode($r['body']);
            if (!$d['ok']) {
                $fehler[] = $sektion . ': ' . $d['error'];
                continue;
            }
            $data = array_merge($data, $d['data']);
            $flat = array_merge($flat, Parser::flatten($d['data'], $meta));
        }

        if ($flat === []) {
            return ['ok' => false, 'code' => Keys::ST_UNREACHABLE,
                'error' => 'Kein einziger Bereich lesbar. ' . implode(' | ', $fehler), 'ms' => $ms];
        }

        // Ein Teilausfall ist ein Teilausfall: die gesunden Bereiche laufen
        // weiter, die fehlenden bleiben auf ihrem letzten Wert stehen.
        return ['ok' => true, 'code' => Keys::ST_ACTIVE, 'ms' => $ms, 'flat' => $flat, 'data' => $data,
            'bereiche' => array_keys($bereiche),
            'error' => $fehler === [] ? '' : 'Teilausfall: ' . implode(' | ', $fehler)];
    }

    private function ohneForecast(array $flat): array
    {
        foreach (array_keys($flat) as $k) {
            if (str_starts_with($k, 'forecast.')) {
                unset($flat[$k]);
            }
        }
        return $flat;
    }

    /**
     * Rueckzug bei Fehlern: nach drei erfolglosen Runden wird das Intervall
     * verdoppelt, hoechstens auf 300 Sekunden. Nach der ersten guten Runde geht
     * es zurueck.
     */
    private function fehlerbehandlung(int $code, string $meldung, float $ms): void
    {
        $n = $this->ReadAttributeInteger('FailCount') + 1;
        $this->WriteAttributeInteger('FailCount', $n);

        // Eine geplatzte Anfrage ist noch kein Ausfall.
        //
        // Solange eine zweite Stelle dieselbe Anlage abfragt, reisst die
        // Taktgrenze regelmaessig - die Anlage antwortet dem Zweiten mit 401.
        // Wuerde die Instanz deswegen jedes Mal auf Rot springen, staende sie
        // die halbe Zeit auf Rot, obwohl aktuelle Werte vorliegen. Rot heisst
        // hier: seit drei Runden keine Daten. Bis dahin bleibt der Zustand
        // gruen, der Fehlertext steht trotzdem in der Diagnosevariablen.
        $frisch = ($n < 3) && ((time() - $this->ReadAttributeInteger('LastOkTs'))
            < 3 * max(30, $this->ReadPropertyInteger('Interval')));
        $this->SetStatus($frisch ? Keys::ST_ACTIVE : $code);
        $this->setzeEigene('Online', $frisch);
        $this->setzeEigene('Error', date('d.m.Y H:i:s') . ' - ' . $meldung);
        if ($ms > 0) {
            $this->setzeEigene('Duration', round($ms, 1));
        }
        if ($code === Keys::ST_RATE) {
            $treffer = $this->ReadAttributeInteger('RateHits') + 1;
            $this->WriteAttributeInteger('RateHits', $treffer);
            $this->setzeEigene('RateHits', $treffer);
        }

        if ($n >= 3) {
            $basis = max(1, $this->ReadPropertyInteger('Interval'));
            $aktuell = (int) ($this->GetTimerInterval('Poll') / 1000);
            $neu = min(300, max($basis, $aktuell) * 2);
            if ($this->ReadPropertyInteger('Interval') > 0 && $neu !== $aktuell) {
                $this->SetTimerInterval('Poll', $neu * 1000);
                $this->debug('Rueckzug', 'Intervall auf ' . $neu . ' s verlangsamt.');
            }
        }

        $this->LogMessage('Pellematic: ' . $meldung, KL_WARNING);
    }

    // =========================================================== Diagnose

    private function diagnose(array $runde, array $flat, Meta $meta): void
    {
        $this->setzeEigene('LastRead', time());
        $this->setzeEigene('Online', true);
        $this->setzeEigene('Duration', round((float) ($runde['ms'] ?? 0), 1));
        $this->setzeEigene('Error', (string) ($runde['error'] ?? ''));
        $this->setzeEigene('RateHits', $this->ReadAttributeInteger('RateHits'));

        // Anlagentyp aus der Aufzaehlung der Anlage, nicht aus einer eigenen Liste.
        $typKey = Parser::resolve($flat, 'pe1.L_type');
        if ($typKey !== null) {
            $map = Meta::parseFormat($flat[$typKey]['format'] ?: $meta->format($typKey));
            $roh = (int) $flat[$typKey]['raw'];
            $this->setzeEigene('DeviceType', $map[$roh] ?? ('Typ ' . $roh));
        }

        $stoerungen = Parser::errorText($runde['data'] ?? []);
        $this->setzeEigene('ErrorText', $stoerungen);
    }

    // ================================================ Gespiegelte Variablen

    /**
     * Schreibt in die BESTEHENDEN Variablen unter #<ID>.
     *
     * Hier wird nichts angelegt, nichts umbenannt, nichts verschoben und am
     * Logging nichts veraendert - es wird ausschliesslich der Wert gesetzt.
     */
    private function schreibeGespiegelt(array $flat, Meta $meta): int
    {
        $n = 0;
        foreach ($this->mappingRows() as $row) {
            if (empty($row['Active'])) {
                continue;
            }
            $key = (string) ($row['Key'] ?? '');
            if ($key === '' || Mapping::istAbgeleitet($key)) {
                continue;
            }
            $vid = (int) ($row['VarID'] ?? 0);
            if ($vid <= 0 || !$this->variableDa($vid)) {
                continue;
            }

            $wert = $this->wertFuer($key, $flat, $meta, (float) ($row['Factor'] ?? 0.0));
            if ($wert === null) {
                continue; // Sentinel, Nullwert oder Schluessel fehlt: alter Wert bleibt stehen
            }

            $this->setzeFremde($vid, $wert);
            $n++;
        }
        return $n;
    }

    /**
     * Welcher Anlagenschluessel speist eine Zielvariable wirklich?
     *
     * Zwei Altlasten haengen hier: PufferT_Oben_Soll bekommt bis heute den
     * Istwert, und "Aussen" fuehrt seit 2014 die Prognose des Onlinewetters,
     * nicht den Fuehler der Anlage. Beides bleibt so, solange der Nutzer es
     * nicht ausdruecklich umstellt - sonst bricht die Zeitreihe.
     */
    private function quelleFuer(string $key): string
    {
        if ($key === 'pu1.L_tpo_set' && !$this->ReadPropertyBoolean('FixTpoSet')) {
            return 'pu1.L_tpo_act';
        }
        if ($key === 'weather.L_temp' && $this->ReadPropertyInteger('AmbientSource') === 1) {
            return 'system.L_ambient';
        }
        return $key;
    }

    /**
     * Der fertige Anzeigewert fuer einen Schluessel, oder null wenn nicht
     * geschrieben werden darf.
     */
    private function wertFuer(string $key, array $flat, Meta $meta, float $faktorAusZeile = 0.0)
    {
        $quelle = $this->quelleFuer($key);
        $echt = Parser::resolve($flat, $quelle);
        if ($echt === null) {
            return null; // fehlender Schluessel ist kein Fehler, sondern Firmwarestand
        }
        $e = $flat[$echt];

        if (!empty($e['sentinel'])) {
            // -32768 heisst "Fuehler nicht vorhanden". Er darf nie ins Archiv:
            // genau daher stammt die dauerhafte -3276,8 bei PE_T_Abgas.
            return $this->ReadPropertyBoolean('SkipSentinel') ? null : ((float) $e['raw']) * $e['factor'];
        }

        $roh = $e['raw'];

        // PE_Freigabe_T traegt seit 2024 den Rohwert 600 statt 60,0 Grad. Die
        // Korrektur erzeugt einen bewussten Sprung und ist deshalb ein Schalter.
        if ($key === 'pe1.L_uw_release' && !$this->ReadPropertyBoolean('FixFreigabeT')) {
            return is_numeric($roh) ? (float) $roh : null;
        }

        // Ein Faktor aus der Zuordnungszeile schlaegt den der Anlage - fuer den
        // Fall, dass eine Firmware etwas anderes meldet als die Reihe erwartet.
        if ($faktorAusZeile != 0.0 && is_numeric($roh)) {
            $wert = ((float) $roh) * $faktorAusZeile;
        } else {
            $wert = $e['value'];
        }

        // Nullwerte, die die Anlage beim Saugen kurz meldet, wuerden den
        // Fuellstand und den Tagesverbrauch verfaelschen.
        if (Keys::hasFlag($quelle, 'skipzero') && is_numeric($wert) && (float) $wert <= 0.0) {
            return null;
        }

        // Text in eine Zahlenreihe zu schreiben heisst in PHP: eine Null. Genau
        // diese Null stuende dann in einer seit Jahren aufgezeichneten Reihe und
        // waere spaeter nicht mehr von einem echten Messwert zu unterscheiden.
        // Die Anlage liefert im Wertfeld gelegentlich Platzhalter ("---"), einen
        // Firmwarestand oder einen Fehlertext.
        $ziel = Keys::type($quelle, $roh);
        if (($ziel === Keys::T_INT || $ziel === Keys::T_FLOAT) && !is_bool($wert) && !is_numeric($wert)) {
            $this->debug('Wert', 'Schluessel ' . $quelle . ' liefert "' . (string) $wert
                . '" statt einer Zahl - nicht geschrieben.');
            return null;
        }

        return $wert;
    }

    /** Setzt eine FREMDE, bereits bestehende Variable - typgerecht. */
    private function setzeFremde(int $vid, $wert): void
    {
        $v = @IPS_GetVariable($vid);
        if (!is_array($v)) {
            return;
        }
        // Zweite Schranke, unabhaengig von wertFuer(): in eine Zahlenvariable wird
        // nur geschrieben, was auch eine Zahl ist. Ein blinder Cast macht aus
        // jedem Text eine Null - und die bleibt fuer immer im Archiv stehen.
        $typ = (int) $v['VariableType'];
        if (($typ === 1 || $typ === 2) && !is_bool($wert) && !is_numeric($wert)) {
            $this->debug('Wert', 'Variable #' . $vid . ' ist numerisch, der Wert "'
                . (string) $wert . '" ist es nicht - nicht geschrieben.');
            return;
        }
        switch ($typ) {
            case 0:
                $w = is_bool($wert) ? $wert : ((float) $wert != 0.0);
                break;
            case 1:
                $w = is_bool($wert) ? ($wert ? 1 : 0) : (int) round((float) $wert);
                break;
            case 2:
                $w = is_bool($wert) ? ($wert ? 1.0 : 0.0) : (float) $wert;
                break;
            default:
                $w = is_bool($wert) ? ($wert ? '1' : '0') : (string) $wert;
                break;
        }
        @SetValue($vid, $w);
    }

    /** Setzt eine EIGENE Variable dieser Instanz ueber ihren Ident. */
    private function setzeEigene(string $ident, $wert): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if (is_int($vid) && $vid > 0) {
            @SetValue($vid, $wert);
        }
    }

    /** Existenztest, der auch ohne Kernel eine Antwort gibt. */
    private function variableDa(int $vid): bool
    {
        return function_exists('IPS_VariableExists') ? (bool) @IPS_VariableExists($vid) : false;
    }

    private function mappingRows(): array
    {
        $rows = json_decode($this->ReadPropertyString('Mapping'), true);
        return is_array($rows) ? $rows : [];
    }

    /** Die Zeile zu einem Schluessel, oder null. */
    private function zeileFuer(string $key): ?array
    {
        foreach ($this->mappingRows() as $row) {
            if ((string) ($row['Key'] ?? '') === $key) {
                return $row;
            }
        }
        return null;
    }

    /** Die Ziel-ID einer abgeleiteten Groesse, 0 wenn nicht zugeordnet oder inaktiv. */
    private function zielFuer(string $key): int
    {
        $row = $this->zeileFuer($key);
        if ($row === null || empty($row['Active'])) {
            return 0;
        }
        $vid = (int) ($row['VarID'] ?? 0);
        return ($vid > 0 && $this->variableDa($vid)) ? $vid : 0;
    }

    // ============================================== Abgeleitete Groessen

    private function rechneAbgeleitet(array $flat, Meta $meta): void
    {
        $schreiben = $this->ReadPropertyInteger('Mode') >= self::MODE_SPIEGEL
            && $this->ReadPropertyBoolean('UseExisting');

        $starts = $this->zahl(Parser::value($flat, 'pe1.L_starts'));
        $laufzeit = $this->zahl(Parser::value($flat, 'pe1.L_runtime'));

        // --- Tageswechsel -------------------------------------------------
        // Der Zaehlerpfad braucht dieselbe Auskunft, bekommt sie aber ueber einen
        // EIGENEN Stempel: der hier wird nur fortgeschrieben, wenn die Anlage die
        // beiden Mitternachtsstaende auch geliefert hat, und darf deshalb hinter
        // dem Kalender zurueckbleiben.
        $heuteDatum = date('Y-m-d');
        $stempelPellets = $this->ReadAttributeString('PelletDay');
        $tagWechselPellets = ($stempelPellets !== '' && $stempelPellets !== $heuteDatum);
        $this->WriteAttributeString('PelletDay', $heuteDatum);

        $stempel = $this->ReadAttributeString('DayStamp');
        if (Derived::istNeuerTag($stempel) || $this->ReadAttributeInteger('StartsAtMidnight') < 0) {
            $vollstaendig = true;
            if ($starts !== null) {
                $this->WriteAttributeInteger('StartsAtMidnight', (int) $starts);
            } else {
                $vollstaendig = false;
            }
            if ($laufzeit !== null) {
                $this->WriteAttributeInteger('RuntimeAtMidnight', (int) $laufzeit);
            } else {
                $vollstaendig = false;
            }
            // Den Stempel erst verbrauchen, wenn beide Staende stehen - sonst
            // bleiben die Tageswerte den ganzen Tag auf dem Stand von gestern.
            if ($vollstaendig) {
                $this->WriteAttributeString('DayStamp', $heuteDatum);
            }
        }

        // --- Fuellstand in Prozent -----------------------------------------
        $fuell = $this->zahl(Parser::value($flat, 'pe1.L_storage_fill'));
        $kapazitaet = $this->ReadPropertyInteger('StorageMaxSource') === 1
            ? (float) $this->ReadPropertyInteger('StorageMaxKg')
            : $this->zahl(Parser::value($flat, 'pe1.L_storage_max'));
        $prozent = Derived::fillPercent($fuell, $kapazitaet);
        if ($prozent !== null && $schreiben) {
            $this->schreibeAbgeleitet('derived.fill_percent', $prozent);
        }

        // --- Tageswerte ohne Archivabfrage ----------------------------------
        $startsHeute = Derived::dailyDelta(
            $starts === null ? null : (int) $starts,
            $this->ReadAttributeInteger('StartsAtMidnight')
        );
        if ($startsHeute !== null && $schreiben) {
            $this->schreibeAbgeleitet('derived.starts_today', $startsHeute);
        }
        $laufHeute = Derived::dailyDelta(
            $laufzeit === null ? null : (int) $laufzeit,
            $this->ReadAttributeInteger('RuntimeAtMidnight')
        );
        if ($laufHeute !== null && $schreiben) {
            $this->schreibeAbgeleitet('derived.runtime_today', $laufHeute);
        }

        // --- Pelletzaehler ---------------------------------------------------
        $this->rechnePelletzaehler($flat, $schreiben, $tagWechselPellets);

        // --- Boolesche Ableitungen -------------------------------------------
        $brenner = Derived::burnerRunning(
            Parser::raw($flat, 'pe1.L_br'),
            Parser::value($flat, 'pe1.L_modulation')
        );
        if ($brenner !== null && $schreiben) {
            $this->schreibeAbgeleitet('derived.burner_running', $brenner);
        }
        $pumpe = Derived::pumpRunning(Parser::value($flat, 'pu1.L_pump'));
        if ($pumpe !== null && $schreiben) {
            $this->schreibeAbgeleitet('derived.buffer_pump', $pumpe);
        }

        // --- Statusbits und Statustexte ---------------------------------------
        foreach (['hk1', 'hk2', 'ww1', 'pu1', 'pe1'] as $bereich) {
            $roh = Parser::raw($flat, $bereich . '.L_state');
            if ($roh === null || !is_numeric($roh)) {
                continue;
            }
            $state = (int) $roh;
            if ($schreiben) {
                $this->schreibeAbgeleitet('derived.' . $bereich . '_state_bin', StateBits::binString($state));
            }

            // Der Klartext kommt fertig von der Anlage. Die eigene Bittabelle
            // ist nur der Rueckfall, wenn L_statetext fehlt - und fuer den
            // Kessel gibt es ohnehin keine belastbare Bitliste.
            $text = Parser::value($flat, $bereich . '.L_statetext');
            if (!is_string($text) || $text === '') {
                $text = StateBits::text($bereich, $state);
            }
            if ($text !== '' && $schreiben) {
                $this->schreibeAbgeleitet('derived.' . $bereich . '_state_text', $text);
            }
        }
    }

    /**
     * Fortschreibung von "Pellet Verbrauch gesamt" (#<ID>).
     *
     * Der Zaehlerstand existiert nur in dieser einen Variable. Beim ERSTEN Lauf
     * wird er uebernommen - aus dem Formularfeld, sonst aus der vorhandenen
     * Variable. Danach wird nur noch fortgeschrieben, nie zurueckgesetzt.
     */
    /**
     * Haengt die Variable irgendwo unterhalb dieses Knotens? Geprueft wird der
     * ganze Weg nach oben, denn die Altvariablen liegen teils in Unterordnern.
     */
    private function liegtUnter(int $vid, int $wurzel): bool
    {
        $p = @IPS_GetParent($vid);
        $tiefe = 0;
        while ($p > 0 && $tiefe < 20) {
            if ($p === $wurzel) {
                return true;
            }
            $p = @IPS_GetParent($p);
            $tiefe++;
        }
        return false;
    }

    private function rechnePelletzaehler(array $flat, bool $schreiben, bool $tagWechsel): void
    {
        $heute = $this->zahl(Parser::value($flat, 'pe1.storage_fill_today'));
        $gestern = $this->zahl(Parser::value($flat, 'pe1.storage_fill_yesterday'));
        if ($heute === null) {
            return;
        }
        // Waehrend die Anlage Pellets ansaugt, meldet sie den Tageswert kurz als 0.
        // Der Katalog fuehrt dafuer den Merker skipzero - im Zaehlerpfad fehlte er,
        // und ein Rueckgang mitten am Tag sah aus wie ein Tageswechsel: der Rest des
        // Vortags wurde ein zweites Mal gutgeschrieben. Der Zaehler ist nicht
        // rekonstruierbar, also lieber eine Runde auslassen.
        if ((int) $heute <= 0 && !$tagWechsel) {
            $this->debug('Pellets', 'Tageswert 0 ohne Datumswechsel - Runde ausgelassen.');
            return;
        }

        $gesamt = $this->ReadAttributeFloat('TotalKg');
        if ($gesamt < 0.0) {
            $start = (float) $this->ReadPropertyFloat('TotalStartKg');
            if ($start <= 0.0) {
                $vid = Mapping::id('derived.pellets_total');
                if ($vid > 0 && @IPS_VariableExists($vid)) {
                    $start = (float) @GetValue($vid);
                }
            }
            $gesamt = max(0.0, $start);
            $this->WriteAttributeFloat('TotalKg', $gesamt);
            $this->WriteAttributeInteger('LastToday', (int) $heute);
            $this->WriteAttributeInteger('YesterdayCredited', (int) $heute);
            $this->debug('Pellets', 'Zählerstand übernommen: ' . $gesamt . ' kg.');
            return; // im ersten Lauf nur uebernehmen, nicht rechnen
        }

        $r = Derived::totalConsumption(
            (int) $heute,
            $gestern === null ? null : (int) $gestern,
            $this->ReadAttributeInteger('LastToday'),
            $this->ReadAttributeInteger('YesterdayCredited'),
            $gesamt,
            $tagWechsel
        );

        $this->WriteAttributeFloat('TotalKg', $r['total']);
        $this->WriteAttributeInteger('LastToday', $r['lastToday']);
        $this->WriteAttributeInteger('YesterdayCredited', $r['credited']);

        if ($schreiben) {
            $this->schreibeAbgeleitet('derived.pellets_total', round($r['total'], 1));
            $this->schreibeAbgeleitet('derived.pellets_kwh',
                Derived::kwh($r['total'], $this->ReadPropertyFloat('KwhPerKg')));
        }
    }

    private function schreibeAbgeleitet(string $key, $wert): void
    {
        $vid = $this->zielFuer($key);
        if ($vid > 0) {
            $this->setzeFremde($vid, $wert);
        }
    }

    private function zahl($v): ?float
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    }

    // ========================================== Eigene Variablen und Links

    /**
     * Legt fuer die nicht zugeordneten Groessen eigene Variablen unter der
     * Instanz an. Es wird nur angelegt und gefuellt - nie entfernt. Wer den
     * Schalter wieder ausschaltet, behaelt seine Variablen samt Aufzeichnung.
     */
    private function pflegeEigeneVariablen(array $flat, Meta $meta): void
    {
        $zugeordnet = [];
        foreach ($this->mappingRows() as $row) {
            $k = (string) ($row['Key'] ?? '');
            if ($k !== '' && (int) ($row['VarID'] ?? 0) > 0) {
                $zugeordnet[$this->quelleFuer($k)] = true;
                $zugeordnet[$k] = true;
            }
        }

        $pos = 100;
        foreach ($flat as $key => $e) {
            $pos++;
            if (isset($zugeordnet[$key])) {
                continue;
            }
            $ident = Keys::ident($key);
            $neu = !(is_int(@$this->GetIDForIdent($ident)) && @$this->GetIDForIdent($ident) > 0);

            $typ = Keys::type($key, $e['raw']);
            $profil = Profiles::enumProfile($key, (string) $e['format']);
            $name = Keys::label($key, (string) $e['text']);

            // Keep ist immer true: unter dieser Instanz wird nichts entfernt.
            $this->MaintainVariable($ident, $name, $typ, $profil, $pos, true);

            if ($neu && $this->ReadPropertyBoolean('LogNew')) {
                $this->archiviere($ident);
            }

            if ($e['sentinel'] && $this->ReadPropertyBoolean('SkipSentinel')) {
                continue;
            }
            $wert = $e['value'];
            if ($wert === null) {
                continue;
            }
            if ($typ === Keys::T_BOOL) {
                $wert = is_bool($wert) ? $wert : ((float) $wert != 0.0);
            } elseif ($typ === Keys::T_INT) {
                $wert = (int) round((float) $wert);
            } elseif ($typ === Keys::T_FLOAT) {
                $wert = (float) $wert;
            } else {
                $wert = (string) $wert;
            }
            $this->setzeEigene($ident, $wert);
        }
    }

    /**
     * Schaltet die Aufzeichnung fuer eine FRISCH ANGELEGTE eigene Variable ein.
     * Nur einschalten, nur unter dieser Instanz, nie fuer fremde Variablen:
     * das Gegenstueck wuerde die Aufzeichnung nicht pausieren, sondern
     * unwiderruflich verwerfen.
     */
    private function archiviere(string $ident): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if (!is_int($vid) || $vid <= 0) {
            return;
        }
        if (@IPS_GetParent($vid) !== $this->InstanceID) {
            return; // Sicherheitsnetz: nur eigene Variablen
        }
        $archiv = $this->archivId();
        if ($archiv > 0 && function_exists('AC_SetLoggingStatus')) {
            @AC_SetLoggingStatus($archiv, $vid, true);
        }
    }

    private function archivId(): int
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return 0;
        }
        $id = $this->ReadPropertyInteger('ArchiveID');
        if ($id > 0 && @IPS_InstanceExists($id)) {
            return $id;
        }
        $liste = @IPS_GetInstanceListByModuleID(self::GUID_ARCHIVE);
        return is_array($liste) && $liste !== [] ? (int) $liste[0] : 0;
    }

    /**
     * Verknuepfungen auf die zugeordneten Variablen, damit die Instanz zeigt,
     * womit sie arbeitet. Verwaiste Verknuepfungen werden entfernt, damit der
     * Lauf idempotent bleibt. Betroffen sind ausschliesslich die eigenen
     * bl_-Verknuepfungen, niemals die Zielvariablen selbst.
     */
    private function syncLinks(): void
    {
        if (!function_exists('IPS_GetChildrenIDs')) {
            return;
        }
        $soll = [];
        foreach ($this->mappingRows() as $row) {
            if (empty($row['Active'])) {
                continue;
            }
            $key = (string) ($row['Key'] ?? '');
            $vid = (int) ($row['VarID'] ?? 0);
            if ($key === '' || $vid <= 0 || !@IPS_VariableExists($vid)) {
                continue;
            }
            $soll['bl_' . Keys::ident($key)] = ['ziel' => $vid, 'name' => (string) ($row['Caption'] ?? $key)];
        }

        foreach (@IPS_GetChildrenIDs($this->InstanceID) ?: [] as $kid) {
            $o = @IPS_GetObject($kid);
            if (!is_array($o) || (int) $o['ObjectType'] !== 6) {
                continue; // 6 = Link
            }
            $ident = (string) $o['ObjectIdent'];
            if (!str_starts_with($ident, 'bl_')) {
                continue;
            }
            if (!isset($soll[$ident])) {
                @IPS_DeleteLink($kid);
                continue;
            }
            @IPS_SetLinkTargetID($kid, $soll[$ident]['ziel']);
            @IPS_SetName($kid, $soll[$ident]['name']);
            unset($soll[$ident]);
        }

        foreach ($soll as $ident => $z) {
            $lid = @IPS_CreateLink();
            if (!$lid) {
                continue;
            }
            @IPS_SetParent($lid, $this->InstanceID);
            @IPS_SetIdent($lid, $ident);
            @IPS_SetName($lid, $z['name']);
            @IPS_SetLinkTargetID($lid, $z['ziel']);
        }
    }

    /**
     * Macht die eigenen schreibbaren Variablen bedienbar. Bestehende Variablen
     * unter #<ID> koennen das nicht: EnableAction loest den Ident nur ueber
     * direkte Kinder der Instanz auf. Deshalb liegt der Messwert weiter dort und
     * der Stellwert beim Modul.
     */
    private function enableActions(): void
    {
        if (!function_exists('IPS_GetChildrenIDs')) {
            return;
        }
        foreach (@IPS_GetChildrenIDs($this->InstanceID) ?: [] as $kid) {
            $o = @IPS_GetObject($kid);
            if (!is_array($o) || (int) $o['ObjectType'] !== 2) {
                continue; // 2 = Variable
            }
            $ident = (string) $o['ObjectIdent'];
            $key = $this->keyZuIdent($ident);
            if ($key === '' || Keys::writeClass($key) === Keys::W_NONE || Keys::writeClass($key) === Keys::W_BLOCKED) {
                continue;
            }
            @$this->EnableAction($ident);
        }
    }

    /** Sucht zu einem Ident den Anlagenschluessel zurueck. */
    private function keyZuIdent(string $ident): string
    {
        $flat = json_decode($this->ReadAttributeString('LastFlat'), true);
        if (is_array($flat)) {
            foreach (array_keys($flat) as $key) {
                if (Keys::ident((string) $key) === $ident) {
                    return (string) $key;
                }
            }
        }
        return '';
    }

    // ==================================================== RequestAction

    /**
     * Bedienung einer eigenen Variablen. Sie laeuft durch GENAU dieselben drei
     * Tore wie die Scripting-API - es gibt keinen zweiten Weg an der Pruefung
     * vorbei.
     */
    public function RequestAction($Ident, $Value)
    {
        $key = $this->keyZuIdent((string) $Ident);
        if ($key === '') {
            throw new Exception('Unbekannter Ident: ' . $Ident);
        }
        $wert = is_bool($Value) ? ($Value ? 1.0 : 0.0) : (float) $Value;
        $ergebnis = $this->SetValueByKey($key, $wert, false);
        if (!str_starts_with($ergebnis, 'OK')) {
            throw new Exception($ergebnis);
        }
    }

    // ================================================== Oeffentliche Wege

    /** Probelauf: liest einmal und berichtet, was ankam. Schreibt nichts. */
    public function TestRead(): string
    {
        if (trim($this->ReadPropertyString('Host')) === '') {
            return 'Es ist keine Adresse eingetragen.';
        }
        $client = $this->client();
        $r = $client->readAll(true, $this->ReadPropertyInteger('Retries'));

        $kopf = 'Verbindung zu ' . $client->ziel() . ' (Passwort maskiert: ' . $r['url'] . ")\n";
        if (!$r['ok']) {
            return $kopf . 'FEHLER (' . $r['code'] . '): ' . $r['error'];
        }

        $d = Parser::decode($r['body']);
        if (!$d['ok']) {
            return $kopf . 'FEHLER (' . $d['code'] . '): ' . $d['error'];
        }

        $meta = Meta::fromResponse($d['data']);
        $flat = Parser::flatten($d['data'], $meta);
        $bereiche = Parser::sections($d['data']);

        $zeilen = [];
        $zeilen[] = $kopf . 'HTTP ' . $r['http'] . ', ' . $r['received'] . ' Bytes, '
            . round((float) $r['ms']) . ' ms.';
        $zeilen[] = count($flat) . ' Datenpunkte in ' . count($bereiche) . ' Bereichen: '
            . implode(', ', $bereiche);
        $zeilen[] = $meta->count() . ' Datenpunkte tragen Metadaten (Faktor, Grenzen, Auswahlliste).';

        $probe = ['pe1.L_temp_act', 'pu1.L_tpo_act', 'ww1.L_ontemp_act', 'pe1.L_storage_fill',
            'pe1.L_statetext', 'system.L_ambient', 'weather.L_temp'];
        foreach ($probe as $k) {
            $echt = Parser::resolve($flat, $k);
            if ($echt === null) {
                continue;
            }
            $e = $flat[$echt];
            $zeilen[] = sprintf('  %-22s roh %-12s Faktor %-5s -> %s',
                $echt, (string) $e['raw'], (string) $e['factor'],
                $e['sentinel'] ? 'kein Fühler verbaut' : (string) $e['value']);
        }

        $st = Parser::errorText($d['data']);
        $zeilen[] = 'Aktive Störungen: ' . ($st === '' ? 'keine' : $st);

        $fremd = $this->pruefeFremdabfrage();
        if ($fremd !== '') {
            $zeilen[] = 'HINWEIS: ' . $fremd;
        }
        return implode("\n", $zeilen);
    }

    /** Alle Schluessel, die die Anlage heute liefert - samt Metadaten. */
    public function Discover(): string
    {
        $client = $this->client();
        $r = $client->readAll(true, $this->ReadPropertyInteger('Retries'));
        if (!$r['ok']) {
            return 'FEHLER (' . $r['code'] . '): ' . $r['error'];
        }
        $d = Parser::decode($r['body']);
        if (!$d['ok']) {
            return 'FEHLER (' . $d['code'] . '): ' . $d['error'];
        }
        $meta = Meta::fromResponse($d['data']);
        $flat = Parser::flatten($d['data'], $meta);

        $zeilen = [sprintf('%-26s %-10s %-8s %-14s %s', 'Schlüssel', 'roh', 'Faktor', 'Grenzen/Liste', 'Bezeichnung')];
        $zeilen[] = str_repeat('-', 110);
        $lesend = 0;
        $setzbar = 0;
        foreach ($flat as $key => $e) {
            $grenzen = '';
            $range = $meta->range($key);
            if ($e['format'] !== '') {
                $grenzen = 'Liste';
            } elseif ($range !== null) {
                $grenzen = (int) $range[0] . '..' . (int) $range[1];
            }
            $klasse = Keys::writeClass($key);
            if (Keys::isReadOnlyByPrefix($key)) {
                $lesend++;
            } else {
                $setzbar++;
            }
            $zeilen[] = sprintf('%-26s %-10s %-8s %-14s %s%s',
                $key,
                is_bool($e['raw']) ? ($e['raw'] ? 'true' : 'false') : mb_substr((string) $e['raw'], 0, 10),
                (string) $e['factor'],
                $grenzen,
                $e['text'] !== '' ? $e['text'] : Keys::label($key),
                $klasse === Keys::W_NONE ? '' : ('  [' . $klasse . ']'));
        }
        array_splice($zeilen, 0, 0, [count($flat) . ' Datenpunkte, ' . $lesend . ' nur lesend (Präfix L_), '
            . $setzbar . ' grundsätzlich setzbar.', '']);
        return implode("\n", $zeilen);
    }

    /**
     * Belegt die Zuordnung vor. Ueberschreibt nichts Bestehendes und aendert
     * die Konfiguration nicht selbst - der Vorschlag wird ins Formular
     * geschrieben, uebernommen wird er vom Nutzer.
     */
    public function FillMapping(bool $Speichern = false): string
    {
        $v = Mapping::vorschlag($this->mappingRows());
        if ($Speichern) {
            // Ohne offenes Formular greift UpdateFormField ins Leere - dann muss
            // die Liste direkt in die Eigenschaft. Genau daran ist die
            // Einrichtung am 24.08.2026 gescheitert: der Knopf meldete
            // "77 Zeilen vorgeschlagen", gespeichert war danach nichts, und das
            // Modul legte mangels Zuordnung eigene Variablen an.
            @\IPS_SetProperty($this->InstanceID, 'Mapping', json_encode($v['rows'], JSON_UNESCAPED_UNICODE));
            @\IPS_ApplyChanges($this->InstanceID);
            return $v['bericht'] . "\n\nDie Liste wurde direkt gespeichert (" . count($v['rows']) . ' Zeilen).';
        }
        $this->UpdateFormField('Mapping', 'values', json_encode($v['rows'], JSON_UNESCAPED_UNICODE));
        return $v['bericht'] . "\n\nDie Liste steht jetzt im Formular. Sie wird erst mit "
            . '"Übernehmen" gespeichert.';
    }

    /** Prueft die Zuordnung: Existenz, Typ, Profil, Archivstatus, Doppelbelegung. */
    public function CheckMapping(): string
    {
        $rows = $this->mappingRows();
        if ($rows === []) {
            return 'Die Zuordnung ist leer. Der Knopf "Zuordnung vorbelegen" schlägt die 77 bekannten '
                . 'Ziele vor.';
        }
        $archiv = $this->archivId();
        $gesehen = [];
        $zeilen = [];
        $fehler = 0;
        foreach ($rows as $row) {
            $key = (string) ($row['Key'] ?? '');
            $vid = (int) ($row['VarID'] ?? 0);
            $aktiv = !empty($row['Active']);
            $bem = [];

            if ($vid <= 0) {
                $bem[] = 'keine Variable gewählt';
            } elseif (!@IPS_VariableExists($vid)) {
                $bem[] = 'Variable #' . $vid . ' gibt es nicht';
            } else {
                $v = @IPS_GetVariable($vid);
                $typen = ['Bool', 'Int', 'Float', 'String'];
                $erwartet = Keys::type($key);
                if ((int) $v['VariableType'] !== $erwartet && !Mapping::istAbgeleitet($key)) {
                    $bem[] = 'Typ ' . $typen[(int) $v['VariableType']] . ', erwartet '
                        . $typen[$erwartet] . ' (wird umgewandelt)';
                }
                $profil = (string) ($v['VariableCustomProfile'] ?: $v['VariableProfile']);
                if ($profil !== '') {
                    $bem[] = 'Profil ' . $profil;
                }
                if ($archiv > 0 && function_exists('AC_GetLoggingStatus') && @AC_GetLoggingStatus($archiv, $vid)) {
                    $bem[] = 'archiviert';
                }
                if (isset($gesehen[$vid])) {
                    $bem[] = 'DOPPELBELEGUNG mit "' . $gesehen[$vid] . '"';
                    $fehler++;
                }
                $gesehen[$vid] = $key;
                $erwarteterName = Mapping::name($key);
                if ($erwarteterName !== '' && @IPS_GetName($vid) !== $erwarteterName) {
                    $bem[] = 'Name "' . @IPS_GetName($vid) . '" statt "' . $erwarteterName . '"';
                }
            }

            $warn = Mapping::warnung($key);
            if ($warn !== '') {
                $bem[] = 'HINWEIS: ' . $warn;
            }

            $zeilen[] = sprintf('%s %-28s #%-6d %s',
                $aktiv ? '[x]' : '[ ]', $key, $vid, implode(' | ', $bem));
        }
        return 'Zuordnung prüfen: ' . count($rows) . ' Zeilen, ' . $fehler . " Doppelbelegungen.\n"
            . implode("\n", $zeilen);
    }

    /**
     * Stellt Alt gegen Neu. Liest einmal und rechnet beide Wege durch, ohne
     * irgendetwas zu schreiben. Erwartet werden genau drei Abweichungen.
     */
    public function Compare(): string
    {
        $client = $this->client();
        $r = $client->readAll(true, $this->ReadPropertyInteger('Retries'));
        if (!$r['ok']) {
            return 'FEHLER (' . $r['code'] . '): ' . $r['error'];
        }
        $d = Parser::decode($r['body']);
        if (!$d['ok']) {
            return 'FEHLER (' . $d['code'] . '): ' . $d['error'];
        }
        $meta = Meta::fromResponse($d['data']);
        $flat = Parser::flatten($d['data'], $meta);

        $zeilen = [sprintf('%-26s %-14s %-14s %-14s %s', 'Schlüssel', 'Altskript', 'Modul', 'Variable heute', 'Bemerkung')];
        $zeilen[] = str_repeat('-', 110);
        $abweichungen = 0;

        foreach (Mapping::VORBELEGUNG as $key => [$id, $name, $warnung]) {
            $alt = $this->altwert($key, $flat);
            $neu = $this->wertFuer($key, $flat, $meta, 0.0);
            $ist = @IPS_VariableExists($id) ? @GetValue($id) : null;

            $gleich = ($alt === null && $neu === null)
                || (is_numeric($alt) && is_numeric($neu) && abs((float) $alt - (float) $neu) < 0.001)
                || (!is_numeric($alt) && !is_numeric($neu) && $alt === $neu);
            if (!$gleich) {
                $abweichungen++;
            }

            $zeilen[] = sprintf('%-26s %-14s %-14s %-14s %s',
                $key,
                $this->text($alt),
                $this->text($neu),
                $this->text($ist),
                $gleich ? '' : 'ABWEICHUNG: ' . ($warnung !== '' ? mb_substr($warnung, 0, 60) : $name));
        }

        // Die Erwartung haengt an den drei Schaltern: stehen sie aus, bildet das
        // Modul das Altskript ABSICHTLICH nach, damit die Archivreihen nicht
        // springen. Dann bleibt als einzige Abweichung der Sentinel.
        $erwartet = ['pe1.L_ext_temp (Fühlerwert -32768 wird nicht geschrieben)'];
        if ($this->ReadPropertyBoolean('FixTpoSet')) {
            $erwartet[] = 'pu1.L_tpo_set (echter Sollwert statt Istwert)';
        }
        if ($this->ReadPropertyBoolean('FixFreigabeT')) {
            $erwartet[] = 'pe1.L_uw_release (Faktor 0,1: 60,0 statt 600)';
        }
        if ($this->ReadPropertyInteger('AmbientSource') === 1) {
            $erwartet[] = 'weather.L_temp (Fühler der Anlage statt Onlinewetter)';
        }

        array_splice($zeilen, 0, 0, [
            'Vergleich Altskript #<ID> gegen Modul, aus derselben Antwort gerechnet. '
                . 'Es wird nichts geschrieben.',
            'Erwartet nach der aktuellen Einstellung: ' . implode(', ', $erwartet) . '.',
            'Solange die drei Schalter unter "Bekannte Fehler des Altsystems" aus sind, bildet das Modul '
                . 'das Altskript bewusst nach - auch seine beiden Rechenfehler -, damit keine archivierte '
                . 'Reihe springt.',
            $abweichungen . ' Abweichungen gefunden, ' . count($erwartet) . ' erwartet.',
            '',
        ]);
        return implode("\n", $zeilen);
    }

    /**
     * Bildet die Rechnung des Altskripts 20008 nach: es ignoriert das Feld
     * factor und teilt hart durch 10, aber nur bei den Temperaturen. Zwei
     * Zeilen sind dabei nachweislich falsch.
     */
    private function altwert(string $key, array $flat)
    {
        // Das Altskript liest PufferT_Oben_Soll aus dem Istwert.
        $quelle = ($key === 'pu1.L_tpo_set') ? 'pu1.L_tpo_act' : $key;
        $echt = Parser::resolve($flat, $quelle);
        if ($echt === null) {
            return null;
        }
        $roh = $flat[$echt]['raw'];
        if (!is_numeric($roh)) {
            return $roh;
        }

        // Genau die Zeilen, in denen 20008 durch 10 teilt.
        $durchZehn = [
            'weather.L_temp', 'hk1.L_roomtemp_set', 'hk1.L_flowtemp_set', 'hk1.L_flowtemp_act',
            'hk1.temp_setback', 'hk1.temp_heat', 'hk1.temp_vacation',
            'hk2.L_roomtemp_set', 'hk2.L_flowtemp_set', 'hk2.L_flowtemp_act',
            'hk2.temp_setback', 'hk2.temp_heat', 'hk2.temp_vacation',
            'pu1.L_pump_release', 'pu1.L_tpm_act', 'pu1.L_tpm_set', 'pu1.L_tpo_act', 'pu1.L_tpo_set',
            'ww1.L_temp_set', 'ww1.L_ontemp_act', 'ww1.temp_max_set', 'ww1.temp_min_set',
            'pe1.L_temp_act', 'pe1.L_temp_set', 'pe1.L_ext_temp',
            'pe1.L_frt_temp_act', 'pe1.L_frt_temp_set', 'pe1.L_frt_temp_end',
        ];
        return in_array($key, $durchZehn, true) ? ((float) $roh) / 10.0 : (float) $roh;
    }

    private function text($v): string
    {
        if ($v === null) {
            return '-';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 3, ',', ''), '0'), ',');
        }
        return mb_substr((string) $v, 0, 14);
    }

    /**
     * Sichert die Archivhistorie der zugeordneten Variablen als CSV.
     *
     * Das ist eine SICHERUNG, kein Umzugsweg. Historie wird beim Umstieg nicht
     * bewegt, sondern durch Weiterverwendung derselben Objekt-IDs erhalten.
     * Die Abfrage ist teuer - je Variable geht ein Archivzugriff hinaus.
     */
    public function ExportCsv(): string
    {
        $archiv = $this->archivId();
        if ($archiv <= 0) {
            return 'Es wurde keine Archivsteuerung gefunden.';
        }
        if (!function_exists('AC_GetLoggedValues')) {
            return 'Die Archivsteuerung stellt AC_GetLoggedValues nicht bereit.';
        }

        $ordner = (function_exists('IPS_GetKernelDir') ? IPS_GetKernelDir() : sys_get_temp_dir())
            . 'pellematic-export-' . date('Ymd-His');
        if (!@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return 'Der Ordner ' . $ordner . ' ließ sich nicht anlegen.';
        }

        $zeilen = [];
        $gesamt = 0;
        foreach ($this->mappingRows() as $row) {
            $vid = (int) ($row['VarID'] ?? 0);
            $key = (string) ($row['Key'] ?? '');
            if ($vid <= 0 || !@IPS_VariableExists($vid)) {
                continue;
            }
            if (!@AC_GetLoggingStatus($archiv, $vid)) {
                $zeilen[] = sprintf('  %-28s #%-6d nicht archiviert', $key, $vid);
                continue;
            }
            $werte = @AC_GetLoggedValues($archiv, $vid, 0, 0, 0);
            if (!is_array($werte)) {
                $werte = [];
            }
            $datei = $ordner . '/' . $vid . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.csv';
            $fh = @fopen($datei, 'w');
            if ($fh === false) {
                continue;
            }
            fwrite($fh, "Zeitstempel;Datum;Wert\n");
            foreach ($werte as $w) {
                fwrite($fh, $w['TimeStamp'] . ';' . date('Y-m-d H:i:s', (int) $w['TimeStamp']) . ';'
                    . str_replace(';', ',', (string) $w['Value']) . "\n");
            }
            fclose($fh);
            $gesamt += count($werte);
            $zeilen[] = sprintf('  %-28s #%-6d %6d Werte', $key, $vid, count($werte));
        }

        return 'Historie gesichert nach ' . $ordner . "\n" . $gesamt . " Werte insgesamt.\n"
            . implode("\n", $zeilen);
    }

    /**
     * Uebernimmt die bestehenden Variablen in den Modulbaum.
     *
     * Die Objekt-ID bleibt, damit bleibt auch das Archiv. Es wird ausschliesslich
     * ein Ident gesetzt und der Elternknoten gewechselt.
     *
     * ACHTUNG: danach findet das Altskript #<ID> seine Ziele nicht mehr - es
     * sucht ueber IPS_GetVariableIDByName im Ordner #<ID> und faellt beim
     * ersten verschobenen Objekt still aus. Das ist beabsichtigt, aber erst nach
     * dem Stilllegen von Ereignis #<ID> zulaessig.
     */
    public function Adopt(bool $ausfuehren = false): string
    {
        $fremd = $this->pruefeFremdabfrage();
        if ($fremd !== '' && $ausfuehren) {
            return "Verweigert.\n" . $fremd . "\nErst das Ereignis #" . self::EVENT_ALTPOLL
                . ' stilllegen, dann übernehmen.';
        }

        $zeilen = [];
        $anzahl = 0;
        foreach ($this->mappingRows() as $row) {
            $key = (string) ($row['Key'] ?? '');
            $vid = (int) ($row['VarID'] ?? 0);
            if ($key === '' || $vid <= 0 || !@IPS_VariableExists($vid)) {
                continue;
            }
            $ident = Keys::ident($key);
            $eltern = @IPS_GetParent($vid);
            if ($eltern === $this->InstanceID) {
                continue; // schon uebernommen
            }
            // Uebernommen wird NUR, was unter der Altkategorie haengt. Eine
            // irrtuemlich zugeordnete Variable einer fremden Instanz wuerde sonst
            // aus ihr herausgerissen: das fremde Modul legt sie neu an, und die
            // verschobene Kopie wird zur Waise, deren Logging weiterlaeuft, ohne
            // je wieder gefuellt zu werden.
            if (!$this->liegtUnter($vid, self::ORDNER_ALT)) {
                $zeilen[] = sprintf('  #%-6d %-32s ABGELEHNT: liegt nicht unter #%d',
                    $vid, @IPS_GetName($vid), self::ORDNER_ALT);
                continue;
            }
            $anzahl++;
            $zeilen[] = sprintf('  #%-6d %-32s -> Ident "%s", neuer Elternknoten #%d',
                $vid, @IPS_GetName($vid), $ident, $this->InstanceID);

            if ($ausfuehren) {
                @IPS_SetIdent($vid, $ident);
                @IPS_SetParent($vid, $this->InstanceID);
            }
        }

        $kopf = $ausfuehren
            ? ('Übernommen: ' . $anzahl . " Variablen. Objekt-IDs und Archiv sind erhalten geblieben.\n")
            : ('VORSCHAU - es wurde nichts verändert. ' . $anzahl . " Variablen würden übernommen.\n"
                . "Aufruf zum Ausführen: OKP_Adopt(" . $this->InstanceID . ", true);\n"
                . "Vorher: settings.json sichern und Ereignis #" . self::EVENT_ALTPOLL . " stilllegen.\n");
        return $kopf . implode("\n", $zeilen);
    }

    // ======================================================== Schreibwege

    /**
     * Trockenprobe: laesst repraesentative Schreibwuensche durch die Pruefung,
     * OHNE etwas zu senden. So laesst sich zeigen, dass die Tore halten, bevor
     * je ein Wert an die Heizung geht.
     */
    public function WriteTest(): string
    {
        if (!$this->ReadPropertyBoolean('WriteEnabled')) {
            return 'Schreiben ist nicht freigegeben. Die Schreibprobe prüft nur, sie sendet nichts - '
                . 'aber ohne den Hauptschalter gibt es nichts zu prüfen.';
        }

        $meta = $this->meta();
        if ($meta->count() === 0) {
            return 'Es liegen noch keine Metadaten vor. Erst "Verbindung prüfen" ausführen.';
        }

        $faelle = [
            ['hk1.temp_heat', 21.5, false],
            ['hk1.temp_heat', 35.0, false],
            ['ww1.temp_max_set', 75.0, false],
            ['ww1.sensor_on', 1.0, false],
            ['hk1.name', 1.0, false],
            ['pe1.L_temp_act', 60.0, false],
            ['pe1.mode', 0.0, false],
            ['pe1.mode', 1.0, false],
            ['pu1.mintemp_on', 30.0, false],
        ];

        $zeilen = ['Trockenprobe - es wird NICHTS gesendet.', ''];
        foreach ($faelle as [$key, $wert, $bestaetigt]) {
            $p = WriteGuard::check($key, $wert, $this->freigaben(), $meta, $this->grenzen(),
                ['bestaetigt' => $bestaetigt, 'verlauf' => $this->schreibVerlauf(), 'jetzt' => time()]);
            $zeilen[] = sprintf('  %-20s %-8s %s', $key, $this->text($wert),
                $p['ok'] ? ('würde Rohwert ' . $p['raw'] . ' senden') : ('abgelehnt: ' . $p['grund']));
        }
        return implode("\n", $zeilen);
    }

    /**
     * Setzt einen Wert an der Anlage.
     *
     * Gesendet wird ausschliesslich der Rohwert. Nach dem Schreiben liest das
     * Modul den Einzelwert zurueck und vergleicht - das ist die einzige
     * Quittung, die es gibt: die Schnittstelle liefert keine dokumentierte
     * Rueckmeldung, und in den Vorgaengen des Doku-Repos berichtet niemand von
     * einem tatsaechlich durchgefuehrten Schreibzugriff.
     */
    public function SetValueByKey(string $key, float $wert, bool $bestaetigt = false): string
    {
        $meta = $this->meta();
        if ($meta->count() === 0) {
            return 'Nicht geschrieben: es liegen keine Metadaten der Anlage vor.';
        }

        // Den Ist-Zustand FRISCH holen, nicht aus dem Zwischenspeicher.
        //
        // Am 24.08.2026 hat genau das eine Wiederherstellung verschluckt: der
        // Schreibversuch setzte hk1.temp_vacation auf 15,5, der Ruecksetzer auf
        // 15,0 wurde aber mit "steht schon an der Anlage" abgelehnt - weil der
        // Zwischenspeicher noch den Stand VOR dem Schreiben trug. An der Anlage
        // blieben 15,5 stehen, und niemand haette es gemerkt. Ein Einzelwert
        // kostet einen Bruchteil einer Sekunde; die Taktbremse gilt ohnehin.
        // KEIN Rueckfall auf den Zwischenspeicher: laesst sich der Ist-Zustand nicht
        // frisch lesen, ist er unbekannt - und unbekannt heisst schreiben, nicht
        // "steht schon so". Ein zweites Mal denselben Wert zu senden schadet nicht,
        // ein verschluckter Ruecksetzer schon.
        $gelesen = $this->leseEinzelwert($this->client(), $key, $meta);

        $p = WriteGuard::check($key, $wert, $this->freigaben(), $meta, $this->grenzen(), [
            'gelesen' => is_numeric($gelesen) ? (float) $gelesen : null,
            'bestaetigt' => $bestaetigt,
            'verlauf' => $this->schreibVerlauf(),
            'jetzt' => time(),
        ]);

        if (!$p['ok']) {
            $meldung = ($p['noop'] ? 'Nichts zu tun: ' : 'Nicht geschrieben: ') . $p['grund'];
            $this->setzeEigene('LastWrite', date('d.m.Y H:i:s') . ' - ' . $key . ': ' . $meldung);
            return $meldung;
        }

        $client = $this->client();
        $r = $client->write($key, $p['raw']);
        $this->merkeSchreibvorgang(time());

        if (!$r['ok']) {
            $t = 'FEHLER beim Schreiben von ' . $key . ' (' . $r['code'] . '): ' . $r['error'];
            $this->protokolliere($key, $gelesen, $wert, $p['raw'], $t);
            return $t;
        }

        if (!$this->ReadPropertyBoolean('WriteVerify')) {
            $t = 'OK - ' . $key . ' = ' . $wert . ' (Rohwert ' . $p['raw'] . ') gesendet, '
                . 'ohne Rückleseprobe.';
            $this->protokolliere($key, $gelesen, $wert, $p['raw'], $t);
            return $t;
        }

        // Die Rueckleseprobe laeuft durch denselben Client und damit durch
        // dieselbe Taktbremse - der Mindestabstand wird eingehalten.
        $zurueck = $this->leseEinzelwert($client, $key, $meta);
        if ($zurueck === null) {
            $t = 'UNKLAR - ' . $key . ' wurde gesendet (Rohwert ' . $p['raw'] . '), '
                . 'die Rückleseprobe lieferte aber keinen auswertbaren Wert.';
            $this->protokolliere($key, $gelesen, $wert, $p['raw'], $t);
            return $t;
        }

        if (abs($zurueck - (float) $wert) > max(0.001, abs($meta->factor($key)) / 2)) {
            $t = 'FEHLER - ' . $key . ': gesendet ' . $wert . ', zurückgelesen ' . $zurueck
                . '. Es wird kein zweiter Versuch unternommen.';
            $this->protokolliere($key, $gelesen, $wert, $p['raw'], $t);
            return $t;
        }

        $t = 'OK - ' . $key . ' = ' . $zurueck . ' (Rohwert ' . $p['raw'] . '), zurückgelesen und geprüft.';
        $this->protokolliere($key, $gelesen, $wert, $p['raw'], $t);
        return $t;
    }

    /** Liest einen Wert. Ohne frische Runde kommt der Wert aus dem letzten Poll. */
    public function GetValueByKey(string $key)
    {
        $flat = json_decode($this->ReadAttributeString('LastFlat'), true);
        if (!is_array($flat)) {
            return null;
        }
        return Parser::value($flat, $key);
    }

    /** Einzelwert von der Anlage, defensiv ausgewertet. */
    private function leseEinzelwert(Client $client, string $key, Meta $meta): ?float
    {
        $r = $client->readSingle($key);
        if (!$r['ok']) {
            return null;
        }
        $body = trim(Parser::toUtf8($r['body']));

        if (Parser::looksLikeJson($body)) {
            $d = Parser::decode($body);
            if ($d['ok']) {
                $flat = Parser::flatten($d['data'], $meta);
                $v = Parser::value($flat, $key);
                if (is_numeric($v)) {
                    return (float) $v;
                }
            }
        }

        // Manche Staende antworten schlicht mit der Zahl.
        if (preg_match('/-?\d+(\.\d+)?/', $body, $m)) {
            return ((float) $m[0]) * $meta->factor($key);
        }
        return null;
    }

    private function freigaben(): array
    {
        return [
            'enabled' => $this->ReadPropertyBoolean('WriteEnabled'),
            'comfort' => $this->ReadPropertyBoolean('WriteComfort'),
            'modes' => $this->ReadPropertyBoolean('WriteModes'),
            'buffer' => $this->ReadPropertyBoolean('WriteBuffer'),
        ];
    }

    private function grenzen(): array
    {
        return [
            'wwMax' => $this->ReadPropertyFloat('WwMaxCelsius'),
            'hkMin' => $this->ReadPropertyFloat('HkMinCelsius'),
            'hkMax' => $this->ReadPropertyFloat('HkMaxCelsius'),
            'maxPerHour' => $this->ReadPropertyInteger('WriteMaxPerHour'),
        ];
    }

    private function schreibVerlauf(): array
    {
        $ring = json_decode($this->ReadAttributeString('WriteLogRing'), true);
        if (!is_array($ring)) {
            return [];
        }
        $out = [];
        foreach ($ring as $e) {
            $out[] = (int) ($e['t'] ?? 0);
        }
        return WriteGuard::verlaufPflegen($out, time());
    }

    private function merkeSchreibvorgang(int $zeit): void
    {
        $ring = json_decode($this->ReadAttributeString('WriteLogRing'), true);
        if (!is_array($ring)) {
            $ring = [];
        }
        $ring[] = ['t' => $zeit];
        $ring = array_slice($ring, -200);
        $this->WriteAttributeString('WriteLogRing', json_encode($ring));
    }

    /** Protokoll eines Schreibvorgangs. Die URL kommt hier nie vor. */
    private function protokolliere(string $key, $alt, $neu, int $roh, string $ergebnis): void
    {
        $zeile = date('d.m.Y H:i:s') . ' - ' . $key . ': ' . $this->text($alt) . ' -> ' . $this->text($neu)
            . ' (roh ' . $roh . ') - ' . $ergebnis;
        $this->setzeEigene('LastWrite', $zeile);
        if ($this->ReadPropertyBoolean('WriteLog')) {
            $this->LogMessage('Pellematic Schreibvorgang: ' . $zeile, KL_NOTIFY);
        }
    }

    private function debug(string $thema, string $text): void
    {
        if ($this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug($thema, $text, 0);
        }
    }

    // ================================================== Konfigurationsform

    /**
     * Jedes Feld traegt eine in Create() registrierte Eigenschaft. Ein
     * Formularfeld ohne Property verwirft "Uebernehmen" kommentarlos: der Nutzer
     * stellt etwas ein, sieht Erfolg und findet danach leere Felder.
     * Knoepfe stehen ausschliesslich unter "actions".
     */
    public function GetConfigurationForm()
    {
        $elements = [
            [
                'type' => 'ExpansionPanel', 'caption' => 'Verbindung', 'expanded' => true,
                'items' => [
                    ['type' => 'Label', 'caption' => 'Das Passwort ist Teil des URL-Pfades, nicht ein Kennwort '
                        . 'im üblichen Sinn. Es unterscheidet Groß- und Kleinschreibung: bei falscher '
                        . 'Schreibung liefert die Anlage still ihre Hilfeseite statt Daten.'],
                    ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'Adresse der Pelletronic Touch'],
                    ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port des JSON-Interface',
                        'minimum' => 1, 'maximum' => 65535],
                    ['type' => 'PasswordTextBox', 'name' => 'Password',
                        'caption' => 'Passwort (ist Teil des Pfades, Groß-/Kleinschreibung beachten)'],
                    ['type' => 'NumberSpinner', 'name' => 'Timeout', 'caption' => 'Zeitüberschreitung (Sekunden)',
                        'minimum' => 3, 'maximum' => 30],
                    ['type' => 'NumberSpinner', 'name' => 'MinGapMs',
                        'caption' => 'Mindestabstand zwischen zwei Anfragen (ms)',
                        'minimum' => 2500, 'maximum' => 10000],
                    ['type' => 'NumberSpinner', 'name' => 'Retries',
                        'caption' => 'Wiederholungen bei abgebrochener Antwort', 'minimum' => 0, 'maximum' => 3],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'caption' => 'Abfrage', 'expanded' => true,
                'items' => [
                    ['type' => 'Select', 'name' => 'Mode', 'caption' => 'Betriebsstufe', 'options' => [
                        ['caption' => 'Trockenlauf - nur lesen und vergleichen, es wird nichts geschrieben', 'value' => 0],
                        ['caption' => 'Spiegelbetrieb - füllt die zugeordneten bestehenden Variablen', 'value' => 1],
                        ['caption' => 'Alleinbetrieb - das Abfrageskript #<ID> ist stillgelegt', 'value' => 2],
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'Interval',
                        'caption' => 'Abfrage alle (Sekunden, 0 = aus)', 'minimum' => 0, 'maximum' => 3600],
                    ['type' => 'Select', 'name' => 'FetchMode', 'caption' => 'Abfrageart', 'options' => [
                        ['caption' => 'alles in einem Zug (all?)', 'value' => 0],
                        ['caption' => 'Bereich für Bereich (langsamer, überlebt einen Abbruch)', 'value' => 1],
                    ]],
                    ['type' => 'CheckBox', 'name' => 'SkipForecast',
                        'caption' => 'Wettervorhersage nicht abfragen (25 Textfelder)'],
                    ['type' => 'NumberSpinner', 'name' => 'MetaRefreshMin',
                        'caption' => 'Metadaten neu holen alle (Minuten, 0 = nur beim Start)',
                        'minimum' => 0, 'maximum' => 10080],
                    ['type' => 'CheckBox', 'name' => 'WarnForeignPoll',
                        'caption' => 'Warnen, wenn eine zweite Abfrage auf dieselbe Anlage läuft'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'caption' => 'Vorhandene Variablen', 'expanded' => false,
                'items' => [
                    ['type' => 'Label', 'caption' => 'Die Zuordnung läuft über die Objekt-ID, nicht über den '
                        . 'Namen. Das Modul schreibt in die bestehende Variable - es legt dort nichts an, '
                        . 'benennt nichts um, verschiebt nichts und verändert das Logging nicht.'],
                    ['type' => 'CheckBox', 'name' => 'UseExisting',
                        'caption' => 'Bestehende Variablen weiterverwenden (Historie bleibt erhalten)'],
                    ['type' => 'List', 'name' => 'Mapping',
                        'caption' => 'Zuordnung Anlagenwert zu vorhandener Variable',
                        'rowCount' => 12, 'add' => true, 'delete' => true, 'columns' => [
                            ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '230px', 'add' => '',
                                'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Bezeichnung', 'name' => 'Caption', 'width' => '240px', 'add' => '',
                                'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Variable', 'name' => 'VarID', 'width' => 'auto', 'add' => 0,
                                'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Faktor (0 = aus der Anlage)', 'name' => 'Factor', 'width' => '190px',
                                'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 4]],
                            ['caption' => 'aktiv', 'name' => 'Active', 'width' => '70px', 'add' => true,
                                'edit' => ['type' => 'CheckBox']],
                        ]],
                    ['type' => 'CheckBox', 'name' => 'CreateMissing',
                        'caption' => 'Nicht zugeordnete Größen zusätzlich unter der Instanz anlegen'],
                    ['type' => 'CheckBox', 'name' => 'CreateLinks',
                        'caption' => 'Verknüpfungen auf die zugeordneten Variablen anlegen'],
                    ['type' => 'CheckBox', 'name' => 'LogNew', 'caption' => 'Neu angelegte Variablen archivieren'],
                    ['type' => 'SelectInstance', 'name' => 'ArchiveID', 'caption' => 'Archiv (0 = automatisch suchen)'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'caption' => 'Bekannte Fehler des Altsystems', 'expanded' => false,
                'items' => [
                    ['type' => 'Label', 'caption' => 'Diese drei Schalter ändern den INHALT archivierter '
                        . 'Reihen. Jeder erzeugt einen sichtbaren Sprung. Wer die Reihe unverändert lassen '
                        . 'will, lässt sie aus.'],
                    ['type' => 'CheckBox', 'name' => 'FixTpoSet',
                        'caption' => 'PufferT_Oben_Soll künftig mit dem echten Sollwert füllen '
                            . '(erzeugt einen Bruch in der Reihe)'],
                    ['type' => 'CheckBox', 'name' => 'FixFreigabeT',
                        'caption' => 'PE_Freigabe_T mit Faktor 0,1 füllen (Sprung von 600 auf 60)'],
                    ['type' => 'Select', 'name' => 'AmbientSource',
                        'caption' => 'Quelle für die Variable "Aussen" (#<ID>)', 'options' => [
                            ['caption' => 'weather.L_temp - Onlinewetter, wie seit 2014', 'value' => 0],
                            ['caption' => 'system.L_ambient - Fühler der Anlage (bricht die Zeitreihe)', 'value' => 1],
                        ]],
                    ['type' => 'CheckBox', 'name' => 'SkipSentinel',
                        'caption' => 'Fühlerwert -32768 nicht schreiben (kein Fühler verbaut)'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'caption' => 'Abgeleitete Größen', 'expanded' => false,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'Derived', 'caption' => 'Abgeleitete Größen berechnen'],
                    ['type' => 'Select', 'name' => 'StorageMaxSource', 'caption' => 'Lagerkapazität', 'options' => [
                        ['caption' => 'aus der Anlage (pe1.L_storage_max)', 'value' => 0],
                        ['caption' => 'fester Wert', 'value' => 1],
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'StorageMaxKg',
                        'caption' => 'Lagerkapazität (kg), nur bei festem Wert', 'minimum' => 150, 'maximum' => 30000],
                    ['type' => 'NumberSpinner', 'name' => 'KwhPerKg', 'caption' => 'Heizwert der Pellets (kWh je kg)',
                        'digits' => 2, 'minimum' => 3.0, 'maximum' => 6.0],
                    ['type' => 'NumberSpinner', 'name' => 'TotalStartKg', 'digits' => 1,
                        'caption' => 'Startwert Gesamtverbrauch (kg), 0 = vorhandenen Zählerstand übernehmen'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'caption' => 'Schreiben', 'expanded' => false,
                'items' => [
                    ['type' => 'Label', 'caption' => 'Im Auslieferungszustand ist der Schreibweg vollständig '
                        . 'gesperrt. Es müssen drei Tore offen stehen: der Hauptschalter, die Klasse und die '
                        . 'von der Anlage gemeldeten Grenzen. Die Fühlerzuordnung (ww1.sensor_on/off) und die '
                        . 'name-Felder sind fest gesperrt und durch keinen Schalter erreichbar.'],
                    ['type' => 'CheckBox', 'name' => 'WriteEnabled', 'caption' => 'Schreiben freigeben'],
                    ['type' => 'CheckBox', 'name' => 'WriteComfort',
                        'caption' => 'Komfortwerte: Solltemperaturen, Zeitwahl, Ökomodus, Einmalladung'],
                    ['type' => 'CheckBox', 'name' => 'WriteModes',
                        'caption' => 'Betriebsarten: Anlage, Heizkreise, Warmwasser, Kessel'],
                    ['type' => 'CheckBox', 'name' => 'WriteBuffer', 'caption' => 'Puffer-Mindesttemperaturen'],
                    ['type' => 'NumberSpinner', 'name' => 'WwMaxCelsius', 'digits' => 1,
                        'caption' => 'Warmwasser höchstens (Grad)', 'minimum' => 30.0, 'maximum' => 80.0],
                    ['type' => 'NumberSpinner', 'name' => 'HkMinCelsius', 'digits' => 1,
                        'caption' => 'Raumtemperatur mindestens (Grad)', 'minimum' => 10.0, 'maximum' => 25.0],
                    ['type' => 'NumberSpinner', 'name' => 'HkMaxCelsius', 'digits' => 1,
                        'caption' => 'Raumtemperatur höchstens (Grad)', 'minimum' => 10.0, 'maximum' => 40.0],
                    ['type' => 'CheckBox', 'name' => 'WriteVerify', 'caption' => 'Nach dem Schreiben zurücklesen und prüfen'],
                    ['type' => 'NumberSpinner', 'name' => 'WriteMaxPerHour',
                        'caption' => 'Höchstens so viele Schreibvorgänge je Stunde', 'minimum' => 1, 'maximum' => 200],
                    ['type' => 'CheckBox', 'name' => 'ActionOnVars', 'caption' => 'Schreibbare Variablen bedienbar machen'],
                    ['type' => 'CheckBox', 'name' => 'WriteLog', 'caption' => 'Jeden Schreibvorgang protokollieren'],
                ],
            ],
            [
                'type' => 'ExpansionPanel', 'caption' => 'Diagnose', 'expanded' => false,
                'items' => [
                    ['type' => 'CheckBox', 'name' => 'Debug', 'caption' => 'Ausführliche Meldungen (Passwort wird maskiert)'],
                ],
            ],
        ];

        $hinweis = $this->pruefeFremdabfrage();
        if ($hinweis !== '') {
            array_unshift($elements, ['type' => 'Label', 'caption' => 'HINWEIS: ' . $hinweis]);
        }
        if ($this->ReadPropertyInteger('Mode') >= self::MODE_SPIEGEL
            && function_exists('IPS_EventExists') && @IPS_EventExists(self::EVENT_RUECKSCHREIB)) {
            $e = @IPS_GetEvent(self::EVENT_RUECKSCHREIB);
            if (is_array($e) && !empty($e['EventActive'])) {
                array_unshift($elements, ['type' => 'Label', 'caption' => 'WARNUNG: Ereignis #'
                    . self::EVENT_RUECKSCHREIB . ' unter WW_Nutzung_Restwärme (#<ID>) ist aktiv. Jede '
                    . 'Änderung dieser Variable startet das Skript #<ID> und schickt den Wert an den '
                    . 'Kessel zurück. Solange das so ist, bleibt die Zuordnung für ww1.use_boiler_heat '
                    . 'besser inaktiv.']);
            }
        }

        return json_encode([
            'elements' => $elements,
            'actions' => [
                ['type' => 'Button', 'caption' => 'Verbindung prüfen', 'onClick' => 'echo OKP_TestRead($id);'],
                ['type' => 'Button', 'caption' => 'Schlüssel der Anlage anzeigen', 'onClick' => 'echo OKP_Discover($id);'],
                ['type' => 'Button', 'caption' => 'Zuordnung vorbelegen', 'onClick' => 'echo OKP_FillMapping($id);'],
                ['type' => 'Button', 'caption' => 'Zuordnung prüfen', 'onClick' => 'echo OKP_CheckMapping($id);'],
                ['type' => 'Button', 'caption' => 'Alt gegen Neu vergleichen', 'onClick' => 'echo OKP_Compare($id);'],
                ['type' => 'Button', 'caption' => 'Historie sichern (CSV)', 'onClick' => 'echo OKP_ExportCsv($id);'],
                ['type' => 'Button', 'caption' => 'Bestehende Variablen übernehmen',
                    'onClick' => 'echo OKP_Adopt($id, false);'],
                ['type' => 'Button', 'caption' => 'Schreibprobe (ein Wert)', 'onClick' => 'echo OKP_WriteTest($id);'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Abfrage ausgeschaltet'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Adresse fehlt'],
                ['code' => 202, 'icon' => 'error', 'caption' => 'Zugang abgewiesen - Passwort prüfen (Groß-/Kleinschreibung)'],
                ['code' => 203, 'icon' => 'error', 'caption' => 'Taktgrenze - es fragt noch eine zweite Stelle dieselbe Anlage ab'],
                ['code' => 204, 'icon' => 'error', 'caption' => 'Antwort unvollständig - Anlage bricht den Datenstrom ab'],
                ['code' => 205, 'icon' => 'error', 'caption' => 'Anlage nicht erreichbar'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
