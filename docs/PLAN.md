# Plan

Der Plan, nach dem dieses Modul gebaut wurde. Er steht hier, weil ein Modul für
eine Hausheizung nicht nur zeigen soll, *was* es tut, sondern *warum* es das so
tut — und was es bewusst nicht tut.

## Die Ausgangslage

Eine Pellematic COMPACT hängt seit 2024 als reine HTTP/JSON-Quelle am Netz und
wird von einem einzigen Skript (#<ID>) alle 30 Sekunden komplett abgefragt.
Das Skript hat keine Zeitüberschreitung, keine Sperre gegen ineinanderlaufende
Abfragen, keine Fehlerbehandlung, und es ignoriert das Feld `factor`, das die
Anlage zu jedem Wert mitliefert. Es findet seine 61 Zielvariablen über
`IPS_GetVariableIDByName` — ein Umbenennen bricht es still. Drei seiner Zeilen
sind nachweislich falsch (siehe `HISTORIE.md`).

Unter #<ID> hängen 268 Variablen, 64 davon archiviert. Die sollen weiterleben.

## Der Prefix und die GUIDs

Prefix `OKP`, gegen alle Modulordner unter `/var/lib/symcon/modules` geprüft.
Die GUIDs stehen in `GUIDS.md` und sind unveränderlich.

## Aufbau

    library.json          Library-GUID, Name, Autor
    VERSION               Quelle der Wahrheit, Tag per scripts/release.sh
    GUIDS.md              Register, IMMUTABEL
    docs/                 PLAN, API (Katalog dieser Anlage), HISTORIE
    fixtures/             echte Antworten, auch die kaputten
    libs/Pellematic/      kernel-freie Fachlogik + feste Ladeliste
    Pellematic/           module.json + module.php
    tests/                reine php-cli-Skripte, kein Framework

Kein PSR-4: Symcon lädt Module in einem eigenen Prozessraum, ein registrierter
Autoloader überlebt den Modulwechsel nicht zuverlässig. Stattdessen eine feste,
abhängigkeitssortierte `require_once`-Liste. Eine vergessene Datei reißt die
gesamte Library mit — deshalb prüft `tests/VerboteTest.php` die Liste.

## Die Abfrage

Ein zentraler `Client` ist die einzige Stelle, die die Anlage anfasst — lesend
wie schreibend. Er bringt vier Dinge mit, die dem Altskript fehlen:

1. **Taktbremse.** Semaphore `OKP_<host>_<port>` plus ein Attribut mit dem
   Zeitstempel der letzten Anfrage. Liegt sie weniger als `MinGapMs` zurück
   (Vorgabe 2600 ms), wird gewartet. Damit können Poll, Formular-Probelauf und
   Schreibvorgang nicht ineinanderlaufen. Ein abgebrochener Versuch zählt bei
   der Anlage als Abfrage — der Wiederholversuch hält den Abstand ebenfalls ein.
2. **Zeitüberschreitung.** Stream-Kontext mit `timeout` aus dem Formular und
   `ignore_errors=true`, sonst ist der Rumpf einer 401-Antwort nicht lesbar —
   und genau dieser Rumpf unterscheidet Takt von Passwort.
3. **Antwortprüfung** in fester Reihenfolge: kein Rumpf ergibt nicht erreichbar (205);
   401 mit „Wait at least" ergibt Taktfehler (203); 401 sonst ergibt Zugang (202);
   Hilfeseite statt JSON ergibt Passwort falsch geschrieben (202); angekündigt aber
   weniger empfangen ergibt Antwort abgebrochen (204), alte Werte bleiben stehen.
4. **Maskierung.** Das Passwort ist Pfadbestandteil. `maskUrl()` ist die einzige
   Methode, die eine URL nach außen gibt.

Der `Parser` saniert vor dem Zerlegen: `L_statetext:` ergibt `L_statetext":` (V4.02),
echte Zeilenumbrüche innerhalb von JSON-Strings escapen (Bereich `error`),
Kodierung prüfen und nur bei Bedarf aus ISO-8859-1 wandeln. Erst danach
`json_decode`.

## Metadaten statt Konstanten

Beim Start und danach alle `MetaRefreshMinutes` holt das Modul `all?` und legt
Einheit, Faktor, Grenzen, Auswahlliste, Klartextname und Länge je Schlüssel in
ein Attribut. **Aus dieser Tabelle kommen alle Faktoren, alle Grenzen und alle
Aufzählungen** — nichts davon steht fest im Code. Genau das repariert den
Faktorfehler des Altskripts an der Wurzel statt an einer Stelle.

## Drei Töpfe von Variablen

| Topf | Wo | Wer legt an |
|---|---|---|
| gespiegelt | die bestehenden IDs unter #<ID> | niemand — es wird nur `SetValue` gerufen |
| eigene | unter der Instanz | das Modul, nur bei `CreateMissing` |
| Diagnose | unter der Instanz | das Modul, immer |

Bitmasken werden nicht mehr in `BitXX`-Kindvariablen zerlegt. Der Klartext kommt
fertig von der Anlage (`L_statetext`); die eigene Bittabelle ist nur der
Rückfall, wenn er fehlt.

## Die Betriebsstufe

Die wichtigste Eigenschaft des Moduls:

- **0 Trockenlauf** (Vorgabe): liest, rechnet, füllt nur die eigene Diagnose.
  Schreibt in keine einzige bestehende Variable.
- **1 Spiegelbetrieb**: füllt die zugeordneten bestehenden Variablen. Das
  Altskript läuft weiter.
- **2 Alleinbetrieb**: Altskript und Hilfsskripte sind stillgelegt.

## Schreiben: drei Tore, eine Sperrliste

Alle drei müssen offen stehen: der Hauptschalter (Vorgabe **aus**), der Schalter
der Klasse (Komfort / Betriebsarten / Puffer, alle **aus**), und die Prüfung
gegen die von der Anlage gemeldeten Grenzen. Dazu eine feste Sperrliste im Code,
die kein Schalter aufhebt: `ww.sensor_on/off` (belegter Schadensfall, Störung
1020), alle `name`-Felder (ein Umlaut bricht den Datenstrom), die
Pelletstatistik, und alles mit `L_`.

Ein Wert außerhalb der Grenzen wird **abgelehnt, nicht gekappt**. Kappen würde
stillschweigend etwas anderes tun als gewollt.

In der Klasse Betriebsarten verlangt jede Null eine ausdrückliche Bestätigung:
`pe1.mode=0` legt im Winter die Heizung still, und niemand merkt es, bis das
Haus kalt ist.

Nach dem Schreiben liest das Modul den Einzelwert zurück und vergleicht. Das ist
die einzige Quittung, die es gibt — die Schnittstelle liefert keine
dokumentierte Rückmeldung, und niemand im Dokumentationsrepo berichtet von einem
tatsächlich durchgeführten Schreibzugriff.

## Zeitsteuerung

Ein Timer, `RegisterTimer` in `Create`, `SetTimerInterval` in `ApplyChanges`.
Vorgabe 60 s, solange das Altskript noch läuft, 30 s im Alleinbetrieb, 0 = aus.
Kein zweiter Timer, keine Ereignisse auf fremde Variablen. Nach drei erfolglosen
Runden verdoppelt sich das Intervall (höchstens 300 s), nach der ersten guten
Runde geht es zurück.

## Was das Modul nie tut

`AC_SetLoggingStatus(..., false)`, `AC_SetAggregationType`,
`AC_ReAggregateVariable` und das Entfernen von Variablen oder Objekten kommen im
gesamten Modul nicht vor. `tests/VerboteTest.php` prüft das per Textsuche über
alle Quelldateien.

## Tests

Reine php-cli-Skripte, kein Framework, Aufruf `/usr/bin/php tests/XyzTest.php`
oder `sh tests/alle.sh`. Grundlage ist überall der echte Dump dieser Anlage.

| Test | Was er prüft |
|---|---|
| ParserTest | jede Fixture, auch die kaputten: Abbruch, Taktfehler, Hilfeseite, alte Firmware, V4.02, mehrzeilige Fehlertexte |
| MetaTest | Faktoren, Grenzen und Aufzählungen aus dem echten Dump |
| WriteGuardTest | jede Sperre und jede Grenze als eigener Fall |
| StateBitsTest | die Bitregel, gegengerechnet an den Statustexten der Anlage |
| DerivedTest | Tageswechsel ohne Doppelzählung, Zähler ohne Rücksprung |
| EchtvergleichTest | Altskript gegen Modul aus derselben Antwort |
| VerboteTest | die harten Verbote und die Vollständigkeit der Ladeliste |
