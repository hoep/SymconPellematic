# Bestandsaufnahme und Umstiegsablauf

Diese Datei ist ein Nachschlagewerk, kein Fließtext. Sie hält fest, was heute
unter „Hardware\Oekofen" (#<ID>) steht, welche Reihen wirklich schützenswert
sind, welche drei Fehler das Altsystem seit 2024 in die Archive schreibt und in
welcher Reihenfolge der Umstieg abläuft.

> **Der Satz, der über allem steht:**
> `AC_SetLoggingStatus(..., false)` **löscht** die Aufzeichnung unwiderruflich.
> Es pausiert sie nicht. Am 17.08.2026 sind auf diese Weise elf Jahre UV-Werte
> verschwunden. Für die 64 archivierten Variablen unter #<ID> gilt das als
> hartes Verbot — es steht im Modul nicht im Kommentar, sondern als Test
> (`tests/VerboteTest.php`).

## Was da liegt

| | |
|---|---|
| Kategorie | #<ID> „Hardware\Oekofen" |
| direkte Kinder | 86 |
| rekursiv | 313 Objekte, davon 268 Variablen |
| Skripte im Baum | 13 (1x `getData` #<ID>, 12 Hilfsskripte) |
| Ereignisse im Baum | 12 |
| archiviert | 64 Variablen, Archivinstanz #<ID> |
| Aggregationstyp Zähler | 5 Variablen: #<ID>, #<ID>, #<ID>, #<ID>, #<ID> |

Die 188 zusätzlichen Variablen sind die 89 `BitXX`-Kinder unter den vier
`*_Status_Bin`-Variablen und die Kanäle der Fremdinstanz „Abgas Temperatur".
Das Modul legt keine `BitXX`-Variablen mehr an und füllt die vorhandenen nicht
weiter — sie frieren ein und bleiben unberührt.

## Die Wahrheit über die Historie

Von „Jahren von Historie" trifft das nur auf **vier IDs** zu, und drei davon
gehören gar nicht zur Pellematic:

| ID | Name | seit | Anmerkung |
|---|---|---|---|
| #<ID> | Aussen | 2014-01-25 | 13 Jahre. Die einzige lange Reihe unter den Oekofen-Kernvariablen. |
| #<ID> | Rücklauf Erdgeschoss / TEMPERATURE | 2013-11-17 | Fremdinstanz #<ID>, wird vom Modul **nie** angefasst. |
| #<ID> | Rücklauf Obergeschoss / TEMPERATURE | 2013-11-17 | Fremdinstanz #<ID>, wird **nie** angefasst. |
| #<ID> | T_Heizraum / TEMPERATURE | 2013-11-17 | Fremdinstanz #<ID>, wird **nie** angefasst. |

Alle übrigen Pellematic-Variablen beginnen erst am 25. bzw. 26.10.2024,
einzelne am 27.10.2024, #<ID> sogar erst am 08.02.2025. Das ist knapp zwei
Jahre — schützenswert, aber nicht historisch. Wer das nicht vorher misst,
schützt die falschen Variablen.

**Die einzige LiveViewBuilder-Bindung** unter allen Oekofen-IDs ist #<ID>
„Pellet Verbrauch gesamt" (Seite Energie und Popup Pelletsverbrauch). Ihre ID
ist unantastbar. Ihr Zählerstand (10413 kg) wurde seit 2024 inkrementell
aufgebaut und ist **nicht rekonstruierbar**: er wird übernommen und
fortgeschrieben, nie zurückgesetzt und nie neu angelegt.

## Die drei Fehler des Altsystems

### 1. PufferT_Oben_Soll (#<ID>) trägt den Istwert

Skript #<ID> schreibt in Zeile
`SetValue($puTObenSoll, $data->pu1->L_tpo_act->val/10)` — also **denselben**
Wert wie in PufferT_Oben (#<ID>). Beide standen bei der Messung auf 59,1.
Der echte Sollwert `pu1.L_tpo_set` lag zur selben Zeit bei 8,0.

Folge: die Archivreihe seit 2024-10-25 ist inhaltlich falsch. Auswertungen auf
dieser „Sollkurve" sind wertlos. Der Schalter *„PufferT_Oben_Soll künftig mit
dem echten Sollwert füllen"* korrigiert es und erzeugt dabei einen sichtbaren
Sprung von 59 auf 8. Im Auslieferungszustand ist er **aus**.

### 2. PE_Freigabe_T (#<ID>) ist um Faktor 10 verschoben

`pe1.L_uw_release` trägt laut Anlage `factor 0.1`. Das Altskript übernimmt den
Rohwert unverändert. Die Variable steht deshalb auf 600 statt auf 60,0 Grad.
Der Schalter *„PE_Freigabe_T mit Faktor 0,1 füllen"* korrigiert es — Sprung von
600 auf 60. Im Auslieferungszustand **aus**.

### 3. HK_OG_Status (#<ID>) ist vergiftet

Skript #<ID> („HK_OG_Status_Bin\setzen") schreibt in Zeile 43 den Bitstring in
die **Integer**-Variable 48568 statt in 19153:

    SetValue(HK_OG_STATUS, str_pad(decbin(GetValue(HK_OG_STATUS)), 25, ...))

Ergebnis: #<ID> steht dauerhaft auf -9223372036854775808 und wird alle 30
Sekunden neu verbogen; #<ID> und #<ID> sind seit 27.10.2024 eingefroren.
#<ID> ist archiviert — dieser Teil der Historie ist unbrauchbar. Er wird
**trotzdem nicht gelöscht**. Das Modul schreibt dort künftig den echten
Statuswert; die Reihe wird ab diesem Zeitpunkt wieder brauchbar.

### Und ein vierter, der keiner ist

`weather.L_temp` (#<ID> „Aussen") ist die Prognose von OpenWeatherMap für
Musterhuegeln, **nicht** der Fühler der Anlage. Der echte Fühler ist
`system.L_ambient` (bei der Messung 20,2 gegen 18,0 Grad). An dieser Variable
hängen 13 Jahre Historie und vier Fremdskripte. Die Quelle darf **nicht
stillschweigend** umgestellt werden — dafür gibt es das Formularfeld
*„Quelle für die Variable Aussen"*, Voreinstellung: wie seit 2014.

Ebenso kein Fehler: `pe1.L_ext_temp` meldet -32768. Das ist der Sentinel für
„Fühler nicht verbaut", nicht eine Störung.

## Die 61 gespiegelten Zuordnungen

Die vollständige Tabelle steht als Code in `libs/Pellematic/Mapping.php` — dort,
wo sie wirkt, statt in einer Datei, die veralten kann. Sie enthält Schlüssel,
Objekt-ID, erwarteten Namen und bei drei Zeilen einen Warnvermerk. Dazu kommen
16 abgeleitete Ziele unter dem Namensraum `derived.*`, zusammen **77 Ziele**.

Drei Zeilen kommen aus der Vorbelegung **inaktiv** heraus, weil sie eine
bewusste Entscheidung verlangen:

| Zeile | Warum inaktiv |
|---|---|
| `pu1.L_tpo_set` auf #<ID> | Die Reihe enthält bis heute den Istwert. |
| `pe1.L_uw_release` auf #<ID> | Faktor fehlt, Sprung von 600 auf 60. |
| `ww1.use_boiler_heat` auf #<ID> | **Rückschreibpfad**, siehe unten. |

## Der Rückschreibpfad — vor Phase 1 entschärfen

Das ist die einzige Stelle, an der ein rein lesendes Modul die Heizung
tatsächlich schalten könnte:

1. #<ID> schreibt `ww1.use_boiler_heat` alle 30 Sekunden in Variable #<ID>.
2. Jede **Änderung** von #<ID> startet über Ereignis **#<ID>** das Skript #<ID>.
3. #<ID> ruft ohne Zeitüberschreitung, ohne Grenzprüfung und ohne Auswertung
   `Sys_GetURLContent('http://.../ww1.use_boiler_heat=' . GetValue(48267))` auf.
   Der PHP-Bool wandert ungewandelt in die URL: bei `false` entsteht
   `ww1.use_boiler_heat=` — ganz ohne Wert.

Sobald das Modul dieselbe Variable füllt, löst es damit einen echten
Schreibvorgang an der Heizung aus, obwohl es selbst gar nicht schreiben will.

**Reihenfolge daher zwingend:** erst Ereignis #<ID> deaktivieren (Freigabe des
Nutzers nötig), dann Mode 1 einschalten. Solange das Ereignis aktiv ist, zeigt
das Formular des Moduls einen Warnhinweis und lässt die Zuordnung inaktiv.

## Die 12 Hilfsskripte und ihre Ereignisse

| Skript | Ziel | Ereignis | Still werden in |
|---|---|---|---|
| #<ID> getData | 61 Variablen | #<ID> (30 s, zyklisch) | Phase 2 |
| #<ID> WW_Nutzung_Restwärme | schreibt an die **Heizung** | #<ID> (bei Änderung #<ID>) | **vor** Phase 1 |
| #<ID> PE_Brenner_Betrieb | #<ID> | #<ID> | Phase 1 |
| #<ID> Puffer_Speicherladepumpe | #<ID> | #<ID> | Phase 1 |
| #<ID> HK_EG_Status_Bin/Text | #<ID>, #<ID> | #<ID> | Phase 1 |
| #<ID> HK_OG_Status_Bin/Text | #<ID>, #<ID> (**defekt**) | #<ID> | Phase 1 |
| #<ID> WW_Status_Bin/Text | #<ID>, #<ID> | #<ID> | Phase 1 |
| #<ID> Puffer_Status_Bin/Text | #<ID>, #<ID> | #<ID> | Phase 1 |
| #<ID> PE_KesselStatus_Bin | #<ID> | **keines** (läuft seit 2024 nicht) | — |
| #<ID> Füllstand % | #<ID> | #<ID> | Phase 1 |
| #<ID> Brennerstarts Heute | #<ID> | #<ID> | Phase 1 |
| #<ID> Brenner Laufzeit Heute | #<ID> | #<ID> | Phase 1 |
| #<ID> Pellet gesamt + kWh | #<ID>, #<ID> | #<ID> | Phase 1 |

Läuft ein Hilfsskript weiter, während das Modul dieselbe abgeleitete Variable
füllt, rechnen zwei Stellen dasselbe — und die eine überschreibt die andere im
Sekundentakt.

Zusätzlich fallen mit dem Modul zwei teure Archivabfragen weg: #<ID> und
#<ID> holen bei jedem Lauf `AC_GetAggregatedValues` über die Zählervariablen
(rund 275 ms je Abfrage) und filtern die Ausreißer heraus. Das Modul merkt sich
stattdessen den Zählerstand um Mitternacht.

## Externe Nutzer

24 Variablen werden außerhalb des Baums referenziert — alle über die ID, keiner
über den Namen. Ein Umzug in den Modulbaum (`OKP_Adopt`) fällt ihnen daher nicht
auf:

- #<ID> Gastherme-Temperaturen: 56962, 36851, 36959, 19990, 35344, 12866, 44181
- #<ID> Config Statistik Boiler: 36009, 47525, 14282 (Fremdinstanzen)
- #<ID> Heizungssteuerung: 56832 (WW_Max_T)
- #<ID> Gas Umrechnung: 28670
- #<ID>, #<ID>, #<ID>: 53289
- #<ID>: 40570 · #<ID>: 13890 · #<ID>: 25718 · #<ID>: 54689
- #<ID>, #<ID>, #<ID>: 56962
- LiveViewBuilder: 53289

Die Suche nach fünfstelligen IDs erzeugt Fehltreffer (#<ID> Proxmox-Swap,
#<ID> FritzBox-Signalstärke, #<ID> Stehlampe). Vor jeder ID-Änderung den
Fundort im Quelltext gegenlesen, nicht der Trefferliste vertrauen.

## Der Umstiegsablauf

### Phase 0 — Trockenlauf (Voreinstellung)

Instanz anlegen, Adresse und Passwort eintragen, *Verbindung prüfen*.
*Zuordnung vorbelegen*, *Zuordnung prüfen*, *Alt gegen Neu vergleichen*.
Das Modul liest und rechnet, füllt aber nur seine eigenen Diagnosevariablen.
Das Altskript läuft unverändert weiter. Zwei Abfragen auf dieselbe Anlage
vertragen sich, solange das Intervall bei 60 Sekunden bleibt.

Erwartet wird im Vergleich **eine** Abweichung: `pe1.L_ext_temp`, weil das
Modul den Sentinel nicht schreibt. Alles andere muss auf die Nachkommastelle
gleich sein — auch die beiden Rechenfehler, denn ohne die drei Schalter bildet
das Modul das Altskript bewusst nach.

Optional: *Historie sichern (CSV)*.

### Phase 1 — Spiegelbetrieb

1. **Zuerst** Ereignis #<ID> deaktivieren (Rückschreibpfad).
2. Die 11 Ereignisse der abgeleiteten Hilfsskripte deaktivieren.
3. Betriebsstufe auf *Spiegelbetrieb* stellen.

Das Modul füllt jetzt die bestehenden Variablen. #<ID> läuft weiter und
schreibt dieselben Werte — das schadet nicht, weil beide dasselbe rechnen.
Wer die drei Korrekturschalter einschalten will, tut es hier und bewusst: jeder
erzeugt einen sichtbaren Sprung in einer archivierten Reihe.

### Phase 2 — Alleinbetrieb

1. Ereignis #<ID> deaktivieren (der 30-Sekunden-Takt des Altskripts).
2. Betriebsstufe auf *Alleinbetrieb*, Intervall auf 30 Sekunden.

Erst jetzt ist das Modul die einzige Quelle. Optional lassen sich die Variablen
mit `OKP_Adopt($id, true)` in den Modulbaum übernehmen: `IPS_SetIdent` plus
`IPS_SetParent`, die Objekt-ID bleibt und damit das Archiv. Danach findet
#<ID> seine Ziele nicht mehr — das ist beabsichtigt, aber erst nach dem
Stilllegen von #<ID> zulässig. Vorher `settings.json` sichern und
`OKP_Adopt($id, false)` als Vorschau laufen lassen.

### Was nie passiert

Der dritte denkbare Weg — Variablen ersetzen und die Historie per CSV
migrieren — wird ausdrücklich **nicht** beschritten. Er ist teuer,
verlustbehaftet und hier ohne Not: die bestehenden Objekt-IDs bleiben einfach in
Gebrauch.

## Zwei Kuriositäten, die bleiben dürfen

- #<ID> und #<ID> heißen **beide** „HK_EG_Raum_Soll_Party", sind beide seit
  2024-10-25 archiviert, stehen beide auf 0 und werden von niemandem geschrieben
  oder gelesen. Ein Gegenstück für das Obergeschoss gibt es nicht.
- #<ID> „Puffer" (bool, false) wird von niemandem geschrieben und von niemandem
  gelesen.

Beide bleiben unberührt: es gibt keinen Schlüssel dafür, und Aufräumen wäre ein
Eingriff ohne Nutzen.

## Entscheidung vom 24.08.2026: die drei Altfehler werden richtiggestellt

Freigegeben wurde, alle drei bekannten Fehler in den bestehenden Reihen zu
korrigieren. Jede Korrektur erzeugt beim ersten Schreiben einen einmaligen,
sichtbaren Sprung in der Kurve. Wer die Reihen spaeter auswertet, muss das
Datum kennen - deshalb steht es hier:

| Variable | bis 24.08.2026 | ab dann | Sprung |
|---|---|---|---|
| **PE_T_Abgas** #<ID> | dauerhaft -3276,8 Grad (Sentinel -32768 der nicht verbauten Fuehlerstelle) | wird gar nicht mehr geschrieben, der letzte Wert bleibt stehen | keiner, die Reihe endet |
| **PE_Freigabe_T** #<ID> | Rohwert 600 (der Faktor 0,1 fehlte im Altskript) | 60,0 Grad | einmalig 600 -> 60 |
| **PufferT_Oben_Soll** #<ID> | seit 25.10.2024 faelschlich der ISTwert `pu1.L_tpo_act` | der echte Sollwert `pu1.L_tpo_set` | einmalig, Hoehe je nach Betriebslage |

Die zugehoerigen Schalter (`FixFreigabeT`, `FixTpoSet`, `SkipSentinel`) stehen
deshalb im Auslieferungszustand auf EIN. Wer den alten Stand fortfuehren will -
etwa um eine laufende Auswertung nicht zu brechen -, schaltet sie im Formular
aus; das Modul schreibt dann wieder wie das Altskript.

Die echte Abgastemperatur liefert die separate Instanz #<ID> (Kanal #<ID>).
Sie wird vom Modul NICHT nach #<ID> gespiegelt: eine Reihe, die dreizehn Jahre
lang eine Fehlanzeige trug, wird nicht rueckwirkend zur Messreihe umgedeutet.

Offen und bewusst nicht entschieden: **Aussen** #<ID> wird weiterhin aus
`weather.L_temp` gefuellt (der Vorhersagewert fuer Musterhuegeln, nicht der
Fuehler der Anlage). Vier Fremdskripte haengen an dieser Variablen, und die
Reihe reicht dreizehn Jahre zurueck. Der echte Fuehler `system.L_ambient` steht
als eigene Variable daneben zur Verfuegung.
