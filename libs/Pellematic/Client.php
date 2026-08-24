<?php

/**
 * Die EINZIGE Stelle, die die Anlage anfasst - lesend wie schreibend.
 *
 * Sie bringt vier Dinge mit, die dem Altskript fehlen:
 *   1. Taktbremse. Die Anlage verlangt mindestens 2500 ms zwischen zwei
 *      Anfragen. Semaphore plus gemerkter Zeitstempel sorgen dafuer, dass
 *      Poll, Formular-Probelauf und Schreibvorgang sich nicht ueberholen.
 *   2. Zeitueberschreitung. Das Altskript setzt keine, laeuft also in den
 *      default_socket_timeout von 60 Sekunden.
 *   3. Unterscheidung der Fehlerbilder. HTTP 401 heisst hier fast nie
 *      "falsches Passwort", sondern "zu schnell gefragt". Nur der Rumpf sagt,
 *      was gemeint ist.
 *   4. Maskierung. Das Passwort ist Pfadbestandteil und landet sonst in jedem
 *      Log, jeder Fehlermeldung und jedem anklickbaren Verlaufseintrag.
 *
 * Ein abgebrochener Versuch zaehlt bei der Anlage als Abfrage. Der
 * Wiederholversuch haelt deshalb ebenfalls den Mindestabstand ein.
 */

declare(strict_types=1);

namespace Hoep\Pellematic;

final class Client
{
    private string $host;
    private int $port;
    private string $password;
    private int $timeout;
    private int $minGapMs;

    /** @var callable():int Liefert den Zeitstempel der letzten Anfrage in Millisekunden. */
    private $rateGet;
    /** @var callable(int):void Merkt sich den Zeitstempel der letzten Anfrage. */
    private $rateSet;
    /** @var callable(int):void Wartet die angegebene Anzahl Millisekunden. */
    private $sleeper;
    /** @var null|callable(string,int):array Ersetzt den Netzzugriff im Test. */
    private $fetcher = null;

    /** Rueckfall, wenn kein Attributspeicher gesetzt ist (Test, Kommandozeile). */
    private static int $prozessLetzte = 0;

    private int $taktfehler = 0;

    public function __construct(string $host, int $port, string $password, int $timeout = 10, int $minGapMs = 2600)
    {
        $this->host = trim($host);
        $this->port = $port;
        $this->password = $password;
        $this->timeout = max(3, $timeout);
        $this->minGapMs = max(2500, $minGapMs);

        $this->rateGet = static fn (): int => self::$prozessLetzte;
        $this->rateSet = static function (int $ms): void {
            self::$prozessLetzte = $ms;
        };
        $this->sleeper = static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    /** Das Modul haengt hier sein Attribut LastRequestMs ein, damit die Bremse Prozesse ueberlebt. */
    public function setRateStore(callable $get, callable $set): self
    {
        $this->rateGet = $get;
        $this->rateSet = $set;
        return $this;
    }

    public function setSleeper(callable $s): self
    {
        $this->sleeper = $s;
        return $this;
    }

    /** Nur fuer Tests: ersetzt den Netzzugriff durch eine vorbereitete Antwort. */
    public function setFetcher(callable $f): self
    {
        $this->fetcher = $f;
        return $this;
    }

    public function taktfehler(): int
    {
        return $this->taktfehler;
    }

    public function minGapMs(): int
    {
        return $this->minGapMs;
    }

    // ------------------------------------------------------------------ Wege

    /** Alles in einem Zug. Mit Fragezeichen kommen die Metadaten mit. */
    public function readAll(bool $meta = true, int $retries = 0): array
    {
        return $this->get('all' . ($meta ? '?' : ''), $retries);
    }

    /** Ein Bereich. Langsamer, ueberlebt aber einen Abbruch in einem anderen Bereich. */
    public function readSection(string $sektion, bool $meta = true, int $retries = 0): array
    {
        return $this->get($sektion . ($meta ? '?' : ''), $retries);
    }

    /** Ein Einzelwert, etwa pe1.L_temp_act. Wird fuer die Rueckleseprobe gebraucht. */
    public function readSingle(string $key, int $retries = 0): array
    {
        return $this->get($key, $retries, false);
    }

    /**
     * Schreibt einen ROHWERT. Es geht nie ein PHP-Bool in die URL: das
     * Altskript 52867 erzeugt so "ww1.use_boiler_heat=" ganz ohne Wert.
     * Hier steht immer eine Zahl.
     */
    public function write(string $key, int $rohwert): array
    {
        return $this->get($key . '=' . $rohwert, 0, false);
    }

    // ------------------------------------------------------------- Kernstueck

    /**
     * Eine Anfrage samt Taktbremse und Fehlerklassifizierung.
     *
     * Rueckgabe:
     *   ok         bool
     *   code       Statuscode des Moduls (102/202/203/204/205)
     *   http       HTTP-Statuscode oder 0
     *   body       Rumpf, roh wie geliefert
     *   announced  angekuendigte Content-Length oder null
     *   received   tatsaechlich empfangene Bytes
     *   ms         Dauer in Millisekunden
     *   error      Klartext, Passwort bereits maskiert
     *   url        maskierte URL
     */
    public function get(string $pfad, int $retries = 0, bool $erwarteJson = true): array
    {
        $r = $this->einmal($pfad, $erwarteJson);

        // Wiederholt wird nur bei Taktfehler und bei abgebrochener Antwort.
        // Ein Passwortfehler wird durch Wiederholen nicht besser, und ein
        // zusaetzlicher Versuch kostet nur wieder den Mindestabstand.
        $versuche = max(0, $retries);
        while (!$r['ok'] && $versuche > 0
            && in_array($r['code'], [Keys::ST_RATE, Keys::ST_TRUNCATED], true)) {
            $versuche--;
            $r = $this->einmal($pfad, $erwarteJson);
        }

        return $r;
    }

    private function einmal(string $pfad, bool $erwarteJson): array
    {
        $url = $this->url($pfad);
        $maske = $this->maskUrl($url);

        $sem = 'OKP_' . str_replace(['.', ':'], '_', $this->host) . '_' . $this->port;
        $habeSem = false;
        if (function_exists('IPS_SemaphoreEnter')) {
            // Grosszuegig warten: der Mindestabstand plus die Zeitueberschreitung
            // ist die laengste Zeit, die eine andere Anfrage rechtmaessig braucht.
            $habeSem = @\IPS_SemaphoreEnter($sem, $this->minGapMs + $this->timeout * 1000 + 1000);
        }

        try {
            $this->warteAufTakt();

            $start = $this->jetztMs();
            $antwort = $this->hole($url);
            $ende = $this->jetztMs();

            // Der Zeitstempel wird IMMER gesetzt, auch nach einem Fehlversuch:
            // die Anlage zaehlt einen Abbruch als Abfrage.
            ($this->rateSet)($ende);
        } finally {
            if ($habeSem && function_exists('IPS_SemaphoreLeave')) {
                @\IPS_SemaphoreLeave($sem);
            }
        }

        $body = (string) ($antwort['body'] ?? '');
        $http = (int) ($antwort['http'] ?? 0);
        $announced = $antwort['announced'] ?? null;
        $received = strlen($body);
        $ms = round(($ende - $start), 1);

        $basis = [
            'ok' => false,
            'code' => Keys::ST_UNREACHABLE,
            'http' => $http,
            'body' => $body,
            'announced' => $announced,
            'received' => $received,
            'ms' => $ms,
            'error' => '',
            'url' => $maske,
        ];

        // (a) Kein Rumpf: die Anlage ist nicht erreichbar.
        if ($body === '') {
            $basis['code'] = Keys::ST_UNREACHABLE;
            $basis['error'] = 'Anlage nicht erreichbar (' . $maske . ', HTTP ' . ($http ?: '-') . ').';
            return $basis;
        }

        // (b) HTTP 401 MIT Wartetext: Taktfehler, kein Passwortfehler.
        if ($http === 401 && stripos($body, 'Wait at least') !== false) {
            $this->taktfehler++;
            $basis['code'] = Keys::ST_RATE;
            $basis['error'] = 'Taktgrenze der Anlage erreicht ("' . trim($body) . '"). '
                . 'Es fragt vermutlich noch eine zweite Stelle dieselbe Anlage ab.';
            return $basis;
        }

        // (c) HTTP 401 sonst: Zugang beziehungsweise Passwort.
        if ($http === 401) {
            $basis['code'] = Keys::ST_AUTH;
            $basis['error'] = 'Zugang abgewiesen (HTTP 401 ohne Wartetext). Passwort prüfen, '
                . 'es unterscheidet Groß- und Kleinschreibung.';
            return $basis;
        }

        // (d) Hilfeseite statt JSON: das Passwort ist falsch geschrieben.
        if ($this->istHilfeseite($body) || ($erwarteJson && !Parser::looksLikeJson(Parser::toUtf8($body)))) {
            $basis['code'] = Keys::ST_AUTH;
            $basis['error'] = 'Die Anlage hat statt JSON ihre Hilfeseite geliefert. Das bedeutet: '
                . 'das Passwort ist falsch geschrieben (Groß-/Kleinschreibung).';
            return $basis;
        }

        // (e) Angekuendigt, aber weniger empfangen: abgebrochene Antwort.
        // Ursache ist praktisch immer ein Umlaut in einem Statustext oder in
        // einem name-Feld. Die alten Werte bleiben dann stehen.
        if ($announced !== null && $received < (int) $announced) {
            $basis['code'] = Keys::ST_TRUNCATED;
            $basis['error'] = 'Antwort unvollständig: ' . $received . ' von ' . (int) $announced
                . ' Bytes. Die Anlage bricht den Datenstrom ab (meist ein Umlaut in einem Statustext).';
            return $basis;
        }

        $basis['ok'] = true;
        $basis['code'] = Keys::ST_ACTIVE;
        return $basis;
    }

    /** Erkennt die Hilfeseite an ihren eigenen Worten, unabhaengig vom Zeichensatz. */
    private function istHilfeseite(string $body): bool
    {
        return stripos($body, 'JSON Interface') !== false
            || stripos($body, "only variables without") !== false
            || stripos($body, 'usage: http') !== false;
    }

    private function warteAufTakt(): void
    {
        $letzte = (int) ($this->rateGet)();
        if ($letzte <= 0) {
            return;
        }
        $abstand = $this->jetztMs() - $letzte;
        if ($abstand < $this->minGapMs) {
            ($this->sleeper)((int) ceil($this->minGapMs - $abstand));
        }
    }

    private function jetztMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Der einzige Netzzugriff.
     * ignore_errors ist zwingend: ohne ihn ist der Rumpf einer 401-Antwort
     * nicht lesbar, und genau dieser Rumpf unterscheidet Takt von Passwort.
     */
    private function hole(string $url): array
    {
        if ($this->fetcher !== null) {
            return ($this->fetcher)($url, $this->timeout);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'header' => "User-Agent: SymconPellematic\r\n"
                    . "Accept: application/json\r\n"
                    . "Connection: close\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $kopf = $http_response_header ?? [];

        $http = 0;
        $announced = null;
        foreach ($kopf as $zeile) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m)) {
                $http = (int) $m[1];
            } elseif (preg_match('#^content-length:\s*(\d+)#i', $zeile, $m)) {
                $announced = (int) $m[1];
            }
        }

        return [
            'body' => $body === false ? '' : (string) $body,
            'http' => $http,
            'announced' => $announced,
        ];
    }

    private function url(string $pfad): string
    {
        return 'http://' . $this->host . ':' . $this->port . '/' . $this->password . '/' . $pfad;
    }

    /**
     * Die EINZIGE Methode, die eine URL nach aussen gibt. Das Passwort ist
     * Pfadbestandteil; es darf weder in ein Log noch in eine Meldung noch in
     * einen anklickbaren Verlaufseintrag geraten. Schreib-URLs gehoeren aus
     * demselben Grund nie in einen Browser: ein erneuter Aufruf schaltet erneut.
     */
    public function maskUrl(string $url): string
    {
        if ($this->password !== '') {
            $url = str_replace('/' . $this->password . '/', '/***/', $url);
        }
        return (string) preg_replace('#(://[^/]+/)[^/]+(/|$)#', '$1***$2', $url);
    }

    /** Fuer Meldungen, in denen nur die Adresse vorkommen soll. */
    public function ziel(): string
    {
        return $this->host . ':' . $this->port;
    }
}
