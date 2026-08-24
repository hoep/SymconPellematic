# Pellematic (OKP)

Ein IP-Symcon-Modul für die OekoFEN Pellematic mit Pelletronic-Touch-Display.

## Warum es dieses Modul gibt

Die Heizung wird seit 2024 von einem einzigen Skript alle 30 Sekunden
abgefragt. Das Skript funktioniert — bis es das nicht mehr tut. Es hat keine
Zeitüberschreitung, also wartet es im Zweifel eine Minute lang. Es hat keine
Sperre, also können sich zwei Abfragen überholen. Es prüft nicht, was
zurückkommt, also schreibt es bei einer abgebrochenen Antwort auch schon mal
nichts oder Falsches. Und vor allem: es rechnet mit fest verdrahteten Faktoren,
obwohl die Anlage zu jedem einzelnen Wert Einheit, Faktor, Grenzen und
Auswahlliste selbst mitliefert.

Das kostet. `PE_Freigabe_T` steht seit zwei Jahren auf 600 statt auf 60,0 Grad,
weil an einer Stelle das Teilen durch zehn vergessen wurde. `PufferT_Oben_Soll`
enthält in Wahrheit den Istwert, weil zweimal dieselbe Quelle abgeschrieben
wurde. `PE_T_Abgas` zeigt -3276,8 Grad, weil der Sentinelwert -32768 („Fühler
nicht verbaut") durch die Faktorrechnung gelaufen ist. Alle drei stehen so im
Archiv.

Dieses Modul macht drei Dinge anders:

- Es **benutzt die Metadaten der Anlage** statt fester Faktoren. Ändert sich mit
  einem Firmwarestand eine Skalierung, rechnet es einfach richtig weiter.
- Es **führt die vorhandenen Variablen weiter**, statt neue anzulegen. Die
  Objekt-IDs bleiben, damit bleibt die Historie — auch die 13 Jahre der Variable
  „Aussen".
- Es **kann schreiben, tut es aber im Auslieferungszustand nicht.** Der
  Schreibweg ist vollständig gesperrt und wird erst durch drei voneinander
  unabhängige Schalter geöffnet.

## Was das Modul tut

- Fragt die Anlage in einem Zug (`all?`) oder Bereich für Bereich ab, mit
  Taktbremse, Zeitüberschreitung und Wiederholung bei abgebrochener Antwort.
- Holt beim Start und danach regelmäßig die Metadaten und legt sie ab. Aus
  dieser Tabelle kommen **alle** Faktoren, Grenzen und Aufzählungen.
- Erkennt die Bereiche aus der Antwort, statt sie zu verdrahten. Diese Anlage
  liefert heute 140 Datenpunkte in neun Bereichen; ein fehlender Schlüssel ist
  kein Fehler, sondern ein Firmwarestand.
- Schreibt die Werte in die bestehenden Variablen — über deren Objekt-ID, nicht
  über ihren Namen.
- Rechnet die abgeleiteten Größen ohne Archivzugriff: Füllstand in Prozent
  gegen die von der Anlage gemeldete Lagerkapazität, Tageswerte gegen den
  gemerkten Zählerstand um Mitternacht, den Pelletzähler fortschreibend.
- Zeigt den Anlagenzustand im Klartext — der kommt fertig von der Anlage
  (`L_statetext`); die eigene Bittabelle ist nur der Rückfall.
- Führt Diagnosevariablen: zuletzt gelesen, erreichbar, letzter Fehler,
  Antwortzeit, Taktfehler, Anlagentyp, aktive Störungen, letzte Schreibaktion.

## Was es ausdrücklich nicht tut

- **Es löscht nichts.** Keine Variable, kein Objekt, keine Aufzeichnung.
- **Es stellt kein Logging um.** `AC_SetLoggingStatus(..., false)` löscht die
  aufgezeichneten Daten unwiderruflich — es pausiert sie nicht. Der Aufruf kommt
  im gesamten Modul nicht vor, und ein Test prüft das.
- **Es schreibt keine Fühlerzuordnung.** `ww1.sensor_on` und `ww1.sensor_off`
  stehen auf einer festen Sperrliste, die kein Schalter aufhebt. Ein ungültiger
  Wert auf diese beiden Register erzeugte anderswo einen Geistersensor und die
  nicht quittierbare Störung 1020; es half nur zweimal Werksrücksetzung samt
  kompletter Neukonfiguration von Hand.
- **Es schreibt keine Namen.** Ein Umlaut in einem `name`-Feld bringt die Anlage
  dazu, die JSON-Antwort mitten im Datenstrom abzubrechen. Ein einziger
  Schreibvorgang könnte die gesamte Abfrage dauerhaft zerstören.
- **Es benennt nichts um und verschiebt nichts** — außer auf ausdrücklichen
  Knopfdruck (`OKP_Adopt`), und auch dann bleibt jede Objekt-ID erhalten.

## Einrichtung in drei Stufen

### Stufe 0 — Trockenlauf (Auslieferungszustand)

Instanz anlegen, Adresse, Port und Passwort eintragen. Dann der Reihe nach:

1. **Verbindung prüfen** — zeigt, wie viele Datenpunkte ankommen und wie lange
   die Anlage braucht.
2. **Zuordnung vorbelegen** — schlägt die 77 bekannten Ziele vor und
   überschreibt dabei nichts, was schon eingetragen ist.
3. **Zuordnung prüfen** — Existenz, Typ, Profil, Archivstatus, Doppelbelegung.
4. **Alt gegen Neu vergleichen** — stellt beide Rechenwege aus derselben Antwort
   gegenüber.

In dieser Stufe füllt das Modul nur seine eigenen Diagnosevariablen. Das
Altskript läuft unverändert weiter.

Im Vergleich ist genau **eine** Abweichung zu erwarten: `pe1.L_ext_temp`, weil
das Modul den Sentinelwert nicht schreibt. Alles andere muss gleich sein — denn
solange die drei Korrekturschalter aus sind, bildet das Modul das Altskript
bewusst nach, einschließlich seiner beiden Rechenfehler. So springt keine
archivierte Reihe, bevor jemand das entschieden hat.

### Stufe 1 — Spiegelbetrieb

**Zuerst muss Ereignis #<ID> still werden.** Es hängt unter
`WW_Nutzung_Restwärme` und startet bei jeder Änderung dieser Variable ein
Skript, das den Wert an den Kessel **zurückschickt** — ohne Zeitüberschreitung,
ohne Grenzprüfung, ohne Auswertung. Sobald das Modul dieselbe Variable füllt,
löst es damit einen echten Schreibvorgang an der Heizung aus, obwohl es selbst
gar nicht schreiben will. Das Modul zeigt dazu einen Warnhinweis im Formular und
lässt die Zuordnung inaktiv.

Danach die elf Ereignisse der abgeleiteten Hilfsskripte deaktivieren, sonst
rechnen zwei Stellen dieselben Variablen. Die Liste steht in `docs/HISTORIE.md`.

Erst dann die Betriebsstufe auf *Spiegelbetrieb* stellen.

### Stufe 2 — Alleinbetrieb

Ereignis #<ID> deaktivieren — das ist der 30-Sekunden-Takt des Altskripts.
Danach Betriebsstufe auf *Alleinbetrieb* und Intervall auf 30 Sekunden.

Wer will, holt die Variablen mit `OKP_Adopt($id, true)` in den Modulbaum. Die
Objekt-ID bleibt, damit bleibt das Archiv. Vorher `settings.json` sichern und
die Vorschau `OKP_Adopt($id, false)` lesen.

## Fehlerbilder und was sie bedeuten

| Status | Was ankommt | Was zu tun ist |
|---|---|---|
| 203 | HTTP 401 mit „Wait at least 2500ms during requests" | **Kein Passwortfehler.** Es fragt noch eine zweite Stelle dieselbe Anlage ab, oder das Intervall ist zu eng. |
| 202 | HTTP 401 ohne diesen Text | Zugang abgewiesen. |
| 202 | HTTP 200, aber die Hilfeseite statt JSON | Das Passwort ist falsch **geschrieben**. Es unterscheidet Groß- und Kleinschreibung, und manche Browser schreiben Eingaben automatisch klein. |
| 204 | HTTP 200, angekündigte Länge, weniger Bytes | Die Anlage bricht den Datenstrom ab. Ursache ist praktisch immer ein **Umlaut** in einem Statustext oder `name`-Feld. Abhilfe: Abfrageart auf „Bereich für Bereich" — dann überleben wenigstens die gesunden Bereiche. |
| 205 | gar keine Antwort | Die Anlage ist nicht erreichbar. |

Bei jedem dieser Fehler gilt: **keine Variable wird geschrieben**, schon gar
nicht auf 0. Der letzte gute Wert bleibt stehen, „Erreichbar" geht auf false,
und „Letzter Fehler" trägt Klartext plus Uhrzeit. Die URL wird in jeder Meldung
maskiert — das Passwort ist Pfadbestandteil und hätte in einem Log nichts
verloren.

## Schreiben

Im Auslieferungszustand vollständig gesperrt. Es müssen drei Tore offen stehen:

1. der Hauptschalter *Schreiben freigeben*,
2. der Schalter der Klasse (*Komfortwerte*, *Betriebsarten*, *Puffer*),
3. die Prüfung gegen die von der Anlage gemeldeten Grenzen beziehungsweise
   gegen ihre Auswahlliste.

Ein Wert außerhalb der Grenzen wird **abgelehnt, nicht gekappt**. Zusätzlich
lassen sich im Formular engere Grenzen ziehen als die Anlage erlaubt — sie ließe
Warmwasser bis 80 Grad zu, was am Zapfhahn eine Verbrühung ist.

In der Klasse *Betriebsarten* verlangt jede Null eine ausdrückliche Bestätigung:

    OKP_SetValueByKey($id, 'pe1.mode', 0, true);

`pe1.mode=0` ist die Kesselfreigabe. Im Winter merkt das niemand, bis das Haus
kalt ist.

Nach jedem Schreibvorgang liest das Modul den Wert einzeln zurück und
vergleicht. Das ist die einzige Quittung, die es gibt: die Schnittstelle liefert
keine dokumentierte Rückmeldung, und in der gesamten öffentlichen Dokumentation
berichtet niemand von einem tatsächlich durchgeführten Schreibzugriff.

### Scripting-API

    OKP_Poll($id);
    OKP_TestRead($id);                              // Probelauf im Klartext
    OKP_Discover($id);                              // alle Schlüssel der Anlage
    OKP_FillMapping($id);                           // Zuordnung vorschlagen
    OKP_CheckMapping($id);                          // Zuordnung prüfen
    OKP_Compare($id);                               // Alt gegen Neu
    OKP_ExportCsv($id);                             // Historie sichern
    OKP_Adopt($id, false);                          // Vorschau; true = ausführen
    OKP_WriteTest($id);                             // Trockenprobe, sendet nichts
    OKP_GetValueByKey($id, 'pe1.L_temp_act');
    OKP_SetValueByKey($id, 'ww1.temp_max_set', 55.0);

Alle geben Klartext zurück, nie stumm `true`.

## Tests

Reine php-cli-Skripte, kein Framework, keine laufende Anlage nötig — Grundlage
ist der echte Dump unter `fixtures/`:

    sh tests/alle.sh
    /usr/bin/php tests/ParserTest.php

Geprüft werden unter anderem: die Zerlegung einer echten Antwort samt Umlauten,
die abgebrochene Antwort (muss einen Fehler und **keine** Werte liefern), die
Hilfeseite (muss als Passwortproblem erkannt werden), das kaputte JSON der
Firmware V4.02, mehrzeilige Fehlertexte, die alte Firmware mit Werten als
Zeichenketten, jede einzelne Schreibsperre, die Bitregel gegen die Statustexte
der Anlage, der Pelletzähler über Mitternacht — und dass die verbotenen Aufrufe
im Quelltext gar nicht erst vorkommen.

## Weiterlesen

- `docs/API.md` — der Schlüsselkatalog **dieser** Anlage, gemessen statt abgeschrieben
- `docs/HISTORIE.md` — was heute unter #<ID> liegt, die drei Altfehler, der Umstiegsablauf
- `docs/PLAN.md` — der Bauplan und seine Begründungen
- `GUIDS.md` — das GUID-Register, unveränderlich

## Lizenz

MIT, siehe `LICENSE`.

## Erste Einrichtung (frische Anlage, nichts vorhanden)

Wer das Modul neu installiert, findet keine Zuordnung und keine Variablen vor.
Der Weg ist trotzdem kurz:

1. **Instanz anlegen**, Adresse und Port der Anlage eintragen und das Passwort -
   es ist bei dieser Schnittstelle ein Stück des URL-Pfads, kein Kennwortfeld im
   üblichen Sinn (`http://<adresse>:4321/<passwort>/all?`).
2. **Kategorie wählen** (Feld „Kategorie für die Variablen"). Bleibt es leer,
   sucht sich das Modul die Stelle selbst: eine vorhandene Ablage, sonst legt es
   neben der Instanz eine Kategorie „Pellematic" an.
3. **Abfragen** — der Knopf „Jetzt abfragen" holt einmal alles und legt die
   Metadaten der Anlage ab (Faktoren, Grenzen, Aufzählungen kommen von dort,
   nicht aus dem Code).
4. **„Fehlende Größen als freie Variablen im Baum anlegen"** — daraus entsteht
   die vollständige Gliederung: Kessel und Brenner, Pufferspeicher, Warmwasser,
   Heizkreise, Wetter, Anlage, Verbrauch und Statistik. Die Variablen gehören
   keiner Instanz; sie lassen sich verschieben, umbenennen und archivieren wie
   jede von Hand angelegte.
5. **Betriebsstufe** auf Spiegelbetrieb stellen. Schreiben an die Anlage bleibt
   gesperrt, bis es ausdrücklich freigegeben wird.

Wer von einem bestehenden Abfrageskript kommt, geht denselben Weg, ergänzt aber
vor Schritt 4 die **Zuordnung** („Zuordnung vorbelegen"): dort werden die
vorhandenen Variablen samt ihrer Historie eingetragen, und nur was dann noch
fehlt, wird neu angelegt.
