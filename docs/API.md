# Die JSON-Schnittstelle DIESER Anlage

Diese Datei beschreibt nicht die Schnittstelle im Allgemeinen, sondern das,
was die Pellematic COMPACT unter <Adresse der Anlage>:4321 am 23.08.2026 wirklich
geantwortet hat. Der gemessene Dump liegt als `fixtures/all-metadaten.json`
im Repo (13.499 Bytes, ISO-8859-1) und ist die Grundlage aller Tests.

**Der eigene Dump schlägt jede fremde Angabe.** Die README des
Dokumentationsrepos `thannaske/oekofen-json-documentation` ist lückenhaft und an
mindestens einer Stelle falsch (sie nennt `circ{n}.L_pump`, die Firmware liefert
`circ{n}.L_pummp`). Verlässlich ist allein das, was die Anlage selbst mitliefert
— und sie liefert zu jedem Datenpunkt Einheit, Faktor, Grenzen und Auswahlliste.

## Aufruf

    http://<adresse>:<port>/<passwort>/<befehl>

Das Passwort ist ein Pfadbestandteil, kein Kennwort im üblichen Sinn. Es gibt
keine Header-Authentifizierung, keine Anmeldung und keinen Ausloggvorgang.

| Befehl | Was kommt zurück |
|---|---|
| `all` | alle Bereiche, nur die Rohwerte |
| `all?` | dieselben Bereiche mit `unit`, `factor`, `min`, `max`, `format`, `text`, `length` |
| `<bereich>` bzw. `<bereich>?` | ein einzelner Bereich, etwa `pe1?` |
| `<bereich>.<schlüssel>` | ein Einzelwert, etwa `pe1.L_temp_act` |
| `error` | die aktiven Störungen |
| `log0` .. `log3` | CSV-Protokolldateien |
| `<bereich>.<parameter>=<rohwert>` | setzt einen Wert (GET, siehe unten) |

Bereichsindizes laut Firmware: `hk1..6`, `pu1..3`, `ww1..3`, `sk1..6`, `se1..3`,
`circ1..3`, `pe1..4`, `thirdparty1..20`. Wie viele davon eine Anlage wirklich
hat, sagt nur sie selbst — das Modul leitet die Bereiche aus der Antwort ab und
verdrahtet sie nicht.

**Das Modul liest `all?`.** Die Kurzform `all` wäre kleiner, ist an dieser
Anlage aber nicht gemessen. Das Modul versucht sie einmal und merkt sich das
Ergebnis; liefert sie zu wenig, bleibt es dauerhaft bei `all?`. Die
CSV-Endpunkte `log0..log3` werden gar nicht gelesen: sie sind groß und bremsen
die Steuerung.

## Was diese Anlage liefert

| Bereich | Datenpunkte |
|---|---|
| system | 5 |
| weather | 15 |
| forecast | 25 |
| hk1 | 17 + Statustext |
| hk2 | 17 + Statustext |
| pu1 | 11 + Statustext |
| ww1 | 18 + Statustext |
| pe1 | 32 + Statustext |
| error | 0 (leer, keine Störung) |

Das sind **140 Datenpunkte mit Metadaten**, dazu **5 flache `L_statetext`** und
**8 Beschriftungsfelder** `*_info`, die keine Messwerte sind. 98 Schlüssel
tragen das Präfix `L_` und sind damit nur lesbar, 47 sind grundsätzlich setzbar.

Ein Firmware- oder Versionsfeld gibt es in der Antwort nicht. Der einzige
Gerätehinweis ist `pe1.L_type = 9`, also COMPACT.

## Schreiben

    http://<adresse>:<port>/<passwort>/<bereich>.<parameter>=<rohwert>

Die Firmware sagt es im eigenen Hilfetext wörtlich:
*„only variables without a leading 'L_' can be set."* Das gilt ausnahmslos —
auch für `pe1.L_storage_min` und `pe1.L_storage_max`, die trotz Präfix `min` und
`max` mitführen und dadurch fälschlich setzbar aussehen.

Gesendet wird immer der **Rohwert**: `round(Anzeigewert / factor)`. 21,5 Grad
werden also zu `temp_heat=215`. Die Unterstrich-Schreibweise (`hk1_temp_heat=215`)
ist laut Firmware gleichwertig; das Modul benutzt nur die Punktform.

**Was die Anlage beim Schreiben antwortet, ist unbelegt.** In keinem der vier
Vorgänge des Dokumentationsrepos und in keinem der vierzehn Kommentare berichtet
jemand von einem tatsächlich durchgeführten Schreibzugriff. Offen bleibt:
Kommt JSON oder Klartext zurück? Wird ein Wert außerhalb der Grenzen abgewiesen
oder stillschweigend gekappt? Wirkt er sofort oder erst zum nächsten Zyklus?
Diese Lücke bleibt hier ausdrücklich offen, statt sie mit einer Vermutung zu
füllen. Deshalb prüft das Modul vorher gegen die gemeldeten Grenzen und liest
hinterher einzeln zurück — das ist die einzige Quittung, die es gibt.

Schreib-URLs gehören nie in einen Browser: es sind GET-Aufrufe, und jeder
Verlaufseintrag, jede Vorschau und jeder erneute Aufruf schaltet erneut.

## Fehlerbilder und was sie bedeuten

| Was ankommt | Was es heißt |
|---|---|
| HTTP 401, Rumpf „Wait at least 2500ms during requests" | Taktgrenze. Es fragt zu schnell oder zu oft — meist, weil noch eine zweite Stelle dieselbe Anlage abfragt. **Kein Passwortfehler.** |
| HTTP 401, anderer Rumpf | Zugang abgewiesen. |
| HTTP 200, Rumpf ist die Hilfeseite statt JSON | Das Passwort ist falsch geschrieben. Es unterscheidet Groß- und Kleinschreibung, und manche Browser schreiben Eingaben automatisch klein. |
| HTTP 200, angekündigte Content-Length, weniger Bytes | Die Anlage bricht den Datenstrom ab. Ursache ist praktisch immer ein **Umlaut** in einem Statustext oder in einem `name`-Feld. |
| gar keine Antwort | Die Anlage ist nicht erreichbar. |

Die Taktgrenze liegt bei 2500 ms. Ein abgebrochener Versuch zählt bei der Anlage
**als Abfrage** — ein sofortiger Wiederholversuch läuft deshalb in den 401.

## Eigenheiten dieser Firmware

**`L_statetext` kommt flach.** In der `all?`-Antwort ist jeder Datenpunkt ein
Objekt mit `val` — außer den fünf `L_statetext`, die als nackte Zeichenkette
dastehen. Ein Auswerter, der überall `->val` erwartet, verliert sie stillschweigend.

**Firmware V4.02 liefert kaputtes JSON:** `"L_statetext:` ohne schließendes
Anführungszeichen des Schlüssels. Vor dem Parsen muss `L_statetext:` durch
`L_statetext":` ersetzt werden, sonst kommt gar nichts an.

**Fehlertexte enthalten echte Zeilenumbrüche** innerhalb der JSON-Strings. Das
ist ebenfalls ungültiges JSON und muss vorher escapt werden.

**Die Kodierung ist ISO-8859-1**, in anderen Ständen UTF-8, gelegentlich
gemischt. Erst prüfen, dann wandeln — nie blind.

**`pe1.L_state` liegt außerhalb der eigenen Auswahlliste.** Die Liste kennt
0..12 und 97..101, gemessen wurde 2147483648 bei Klartext „Aus". Ein
Variablenprofil mit festen Assoziationen braucht dort einen Standardfall; für
die Anzeige gilt immer `L_statetext`.

**`pe1.L_ext_temp` meldet -32768.** Das ist der Sentinel für „Fühler nicht
verbaut", keine Temperatur. Wer ihn durch den Faktor 0,1 laufen lässt, schreibt
-3276,8 Grad ins Archiv — genau das steht heute in #<ID>. Die reale
Abgastemperatur liefert die separate Instanz #<ID>, Kanal #<ID>.

**`L_state` bei hk, ww, pu ist eine Bitmaske**, keine Aufzählung. Die Regel
lautet: Bit NN ist gesetzt, wenn `(state >> (NN-1)) & 1`. Gegengerechnet an den
Werten dieser Anlage: hk1 = 8 setzt Bit 4, Klartext „Betriebsart Aus"; pu1 = 512
setzt Bit 10, Klartext „Anforderung Aus"; ww1 = 8208 setzt Bit 5 und Bit 14,
Klartext „Zeit innerhalb Zeitprogramm|Anforderung Aus". In allen drei Fällen
trifft die eigene Bittabelle den Text der Anlage genau.

**Schlüsselnamen wandern zwischen Firmwareständen.** Bekannte Paare:
`L_storage_popper` / `L_storage_hopper`, `storage_fill_today` /
`L_pellets_today`, `L_pump` / `L_pummp` (Tippfehler im Regler). Das Modul prüft
nachgiebig auf beide Schreibweisen.

**`weather.refresh` meldet weder Grenzen noch Auswahlliste.** Nach der eigenen
Regel „ohne gemeldete Grenzen wird nicht geschrieben" bleibt dieser Auslöser
deshalb gesperrt. Das ist beabsichtigt: raten wäre die Alternative.

## Der vollständige Katalog dieser Anlage

Gemessen, nicht abgeschrieben. Die Spalte „schreibbar" nennt die Schreibklasse
des Moduls: `comfort`, `modes`, `buffer`, `nein` oder `gesperrt` (feste
Sperrliste, durch keinen Schalter erreichbar).

### system

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_ambient` | °C | 0.1 | -32768..32767 | Außentemperatur | 20.2 | nein (L\_) |
| `L_errors` | - | 1 | -32768..32767 | Fehler | 0 | nein (L\_) |
| `L_usb_stick` | - | 1 | 0:Aus / 1:Ein | Usb Stick erkannt | 0 | nein (L\_) |
| `L_existing_boiler` | °C | 0.1 | -32768..32767 | Bestehender Kessel | 0 | nein (L\_) |
| `mode` | - | 1 | 0:Aus / 1:Auto / 2:Warmwasser | Betriebsart | 2 | modes |

### weather

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_temp` | °C | 0.1 | -32768..32767 | Akt. Temperatur | 18 | nein (L\_) |
| `L_clouds` | % | 1 | -32768..32767 | Akt. Bewölkung | 84 | nein (L\_) |
| `L_forecast_temp` | °C | 0.1 | -32768..32767 | Durchschnittliche Temperatur | 24 | nein (L\_) |
| `L_forecast_clouds` | % | 1 | -32768..32767 | Durchschnittliche Bewölkung | 71 | nein (L\_) |
| `L_forecast_today` | - | 1 | 0:Heute / 1:Morgen | Wetterprognose start | 0 | nein (L\_) |
| `L_starttime` | - | 1 | -32768..32767 | Startzeit | 810 | nein (L\_) |
| `L_endtime` | - | 1 | -32768..32767 | Endzeit | 1730 | nein (L\_) |
| `L_source` | - | 1 | - | Info | https://www.openweathermap.org | nein (L\_) |
| `L_location` | - | 1 | - | Ort | Musterhügeln|AT|0000000 | nein (L\_) |
| `cloud_limit` | % | 1 | 0..100 | Bewölkungslimit | 55 | comfort |
| `hysteresys` | K | 0.1 | -200..0 | Abbruchtmp. Differenz | -4 | comfort |
| `offtemp` | °C | 0.1 | -300..200 | Abschalttemperatur | -10 | comfort |
| `lead` | min | 1 | 0..600 | Vorhaltezeit | 120 | comfort |
| `refresh` | - | 1 | - | Aktualiseren | 0 | comfort |
| `oekomode` | - | 1 | 0:Aus / 1:Ein | Öko Modus | 0 | comfort |

### hk1

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_roomtemp_act` | °C | 0.1 | -32768..32767 | Raumtemperatur | 0 | nein (L\_) |
| `L_roomtemp_set` | °C | 0.1 | -32768..32767 | RT Soll | 8 | nein (L\_) |
| `L_flowtemp_act` | °C | 0.1 | -32768..32767 | VL Ist | 30.6 | nein (L\_) |
| `L_flowtemp_set` | °C | 0.1 | -32768..32767 | VL Soll | 8 | nein (L\_) |
| `L_comfort` | K | 0.1 | -32768..32767 | Komforttemperatur | 0 | nein (L\_) |
| `L_state` | - | 1 | - | Status | 8 | nein (L\_) |
| `L_statetext` | - | 1 | - | - | Betriebsart Aus | nein (L\_) |
| `L_pump` | - | 1 | 0:Aus / 1:Ein | HK Pumpe | 0 | nein (L\_) |
| `remote_override` | K | 0.1 | -32768..32767 | HK Fernbedienung | 0 | comfort |
| `mode_off` | - | 1 | 0:Aus / 1:Auto / 2:Heizen / 3:Absenken | Betriebsart | 0 | modes |
| `mode_auto` | - | 1 | 0:Aus / 1:Auto / 2:Heizen / 3:Absenken | Betriebsart | 1 | modes |
| `mode_dhw` | - | 1 | 0:Aus / 1:Auto / 2:Heizen / 3:Absenken | Betriebsart | 0 | modes |
| `time_prg` | - | 1 | 0:Zeit 1 / 1:Zeit 2 | Zeitauswahl | 0 | comfort |
| `temp_setback` | °C | 0.1 | 100..400 | Raumtemp Absenken | 18 | comfort |
| `temp_heat` | °C | 0.1 | 100..400 | Raumtemp Heizen | 22 | comfort |
| `temp_vacation` | °C | 0.1 | 100..400 | Raumtemp Urlaub | 15 | comfort |
| `name` | - | 1 | - | Anzeigename | Erdgeschoss | **gesperrt** |
| `oekomode` | - | 1 | 0:Aus / 1:Komfort / 2:Minimum / 3:Ökologisch | Öko Modus | 0 | comfort |

### hk2

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_roomtemp_act` | °C | 0.1 | -32768..32767 | Raumtemperatur | 0 | nein (L\_) |
| `L_roomtemp_set` | °C | 0.1 | -32768..32767 | RT Soll | 8 | nein (L\_) |
| `L_flowtemp_act` | °C | 0.1 | -32768..32767 | VL Ist | 28.1 | nein (L\_) |
| `L_flowtemp_set` | °C | 0.1 | -32768..32767 | VL Soll | 8 | nein (L\_) |
| `L_comfort` | K | 0.1 | -32768..32767 | Komforttemperatur | 0 | nein (L\_) |
| `L_state` | - | 1 | - | Status | 8 | nein (L\_) |
| `L_statetext` | - | 1 | - | - | Betriebsart Aus | nein (L\_) |
| `L_pump` | - | 1 | 0:Aus / 1:Ein | HK Pumpe | 0 | nein (L\_) |
| `remote_override` | K | 0.1 | -32768..32767 | HK Fernbedienung | 0 | comfort |
| `mode_off` | - | 1 | 0:Aus / 1:Auto / 2:Heizen / 3:Absenken | Betriebsart | 0 | modes |
| `mode_auto` | - | 1 | 0:Aus / 1:Auto / 2:Heizen / 3:Absenken | Betriebsart | 1 | modes |
| `mode_dhw` | - | 1 | 0:Aus / 1:Auto / 2:Heizen / 3:Absenken | Betriebsart | 0 | modes |
| `time_prg` | - | 1 | 0:Zeit 1 / 1:Zeit 2 | Zeitauswahl | 0 | comfort |
| `temp_setback` | °C | 0.1 | 100..400 | Raumtemp Absenken | 18 | comfort |
| `temp_heat` | °C | 0.1 | 100..400 | Raumtemp Heizen | 22 | comfort |
| `temp_vacation` | °C | 0.1 | 100..400 | Raumtemp Urlaub | 15 | comfort |
| `name` | - | 1 | - | Anzeigename | Obergeschoss | **gesperrt** |
| `oekomode` | - | 1 | 0:Aus / 1:Komfort / 2:Minimum / 3:Ökologisch | Öko Modus | 0 | comfort |

### pu1

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_tpo_act` | °C | 0.1 | -32768..32767 | Einschaltfühler | 59.1 | nein (L\_) |
| `L_tpo_set` | °C | 0.1 | -32768..32767 | TPO Soll | 8 | nein (L\_) |
| `L_tpm_act` | °C | 0.1 | -32768..32767 | Abschaltfühler | 50.7 | nein (L\_) |
| `L_tpm_set` | °C | 0.1 | -32768..32767 | TPM Soll | 8 | nein (L\_) |
| `L_pump_release` | °C | 0.1 | -32768..32767 | Pumpenfreigabe Temp | 8 | nein (L\_) |
| `L_pump` | % | 1 | 0..100 | Drehzahl | 0 | nein (L\_) |
| `L_state` | - | 1 | - | Status | 512 | nein (L\_) |
| `L_statetext` | - | 1 | - | - | Anforderung Aus | nein (L\_) |
| `mintemp_off` | °C | 0.1 | 80..900 | Puffertemp min Aus | 8 | buffer |
| `mintemp_on` | °C | 0.1 | 80..900 | Puffertemp min Ein | 8 | buffer |
| `ext_mintemp_off` | °C | 0.1 | 80..900 | Puffertemp min Aus | 8 | buffer |
| `ext_mintemp_on` | °C | 0.1 | 80..900 | Puffertemp min Ein | 8 | buffer |

### ww1

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_temp_set` | °C | 0.1 | -32768..32767 | Wassertemp Soll | 55 | nein (L\_) |
| `L_ontemp_act` | °C | 0.1 | -32768..32767 | Ein Temperatur | 60.6 | nein (L\_) |
| `L_offtemp_act` | °C | 0.1 | -32768..32767 | Aus Temperatur | 60.6 | nein (L\_) |
| `L_pump` | - | 1 | 0:Aus / 1:Ein | Pumpe | 0 | nein (L\_) |
| `L_state` | - | 1 | - | Status | 8208 | nein (L\_) |
| `L_statetext` | - | 1 | - | - | Zeit innerhalb Zeitprogramm|Anf... | nein (L\_) |
| `time_prg` | - | 1 | 0:Zeit 1 / 1:Zeit 2 | Zeitauswahl | 0 | comfort |
| `sensor_on` | - | 1 | 0:WW / 1:TPO / 2:TPM / 3:SpUnten | Einschaltfühler | 0 | **gesperrt** |
| `sensor_off` | - | 1 | 0:WW / 1:TPO / 2:TPM / 3:SpUnten | Abschaltfühler | 0 | **gesperrt** |
| `mode_off` | - | 1 | 0:Aus / 1:Auto / 2:Ein | Betriebsart | 0 | modes |
| `mode_auto` | - | 1 | 0:Aus / 1:Auto / 2:Ein | Betriebsart | 1 | modes |
| `mode_dhw` | - | 1 | 0:Aus / 1:Auto / 2:Ein | Betriebsart | 1 | modes |
| `heat_once` | - | 1 | 0:Aus / 1:Ein | Einmal Aufbereiten | 0 | comfort |
| `temp_min_set` | °C | 0.1 | 80..800 | Wassertemp Min | 30 | comfort |
| `temp_max_set` | °C | 0.1 | 80..800 | Wassertemp Soll | 60 | comfort |
| `name` | - | 1 | - | Anzeigename |  | **gesperrt** |
| `smartstart` | min | 1 | 0..90 | Intelligenter Start | 0 | comfort |
| `use_boiler_heat` | - | 1 | 0:Aus / 1:Ein | Restwärmenutzung | 0 | comfort |
| `oekomode` | - | 1 | 0:Aus / 1:Komfort / 2:Minimum / 3:Ökologisch | Öko Modus | 0 | comfort |

### pe1

| Schlüssel | Einheit | Faktor | Grenzen / Auswahlliste | Klartext der Anlage | Wert bei der Messung | schreibbar |
|---|---|---|---|---|---|---|
| `L_temp_act` | °C | 0.1 | -32768..32767 | Kesseltemperatur | 66.6 | nein (L\_) |
| `L_temp_set` | °C | 0.1 | -32768..32767 | KT Soll | 8 | nein (L\_) |
| `L_ext_temp` | °C | 0.1 | -32768..32767 | Abgastemperatur | -32768 (kein Fühler) | nein (L\_) |
| `L_frt_temp_act` | °C | 0.1 | -32768..32767 | Flammraumtemp | 75 | nein (L\_) |
| `L_frt_temp_set` | °C | 0.1 | -32768..32767 | FRT Soll | 8 | nein (L\_) |
| `L_frt_temp_end` | °C | 0.1 | -32768..32767 | Flammraumendtemp | 8 | nein (L\_) |
| `L_br` | - | 1 | 0:Aus / 1:Ein | Brennerkontakt | 0 | nein (L\_) |
| `L_ak` | - | 1 | 0:Aus / 1:Ein | Best Kessel (AK) | 0 | nein (L\_) |
| `L_not` | - | 1 | 0:Aus / 1:Ein | Emergency Stop | 1 | nein (L\_) |
| `L_stb` | - | 1 | 0:Aus / 1:Ein | Safety Thermostat | 1 | nein (L\_) |
| `L_modulation` | % | 1 | -32768..32767 | Modulationsstufe | 0 | nein (L\_) |
| `L_runtimeburner` | zs | 0.01 | - | Einschubzeit | 0 | nein (L\_) |
| `L_resttimeburner` | zs | 0.01 | - | Pausenzeit | 0 | nein (L\_) |
| `L_currentairflow` | % | 1 | -32768..32767 | Lüfter Drehzahl | 0 | nein (L\_) |
| `L_lowpressure` | EH | 0.1 | -32768..32767 | Unterdruck | 49.7 | nein (L\_) |
| `L_lowpressure_set` | EH | 0.1 | -32768..32767 | Unterdruck Ende | 95 | nein (L\_) |
| `L_fluegas` | % | 1 | -32768..32767 | Saugzug Drehzahl | 0 | nein (L\_) |
| `L_uw_speed` | % | 1 | -32768..32767 | Drehzahl UW | 0 | nein (L\_) |
| `L_state` | - | 1 | - | PE Kesselstatus | 2147483648 | nein (L\_) |
| `L_statetext` | - | 1 | - | - | Aus | nein (L\_) |
| `L_type` | - | 1 | 0:PE / 1:PES / 2:PEK / 3:PESK / 4:SMART V1 / 5:SMART V2 / 6:CONDENS / 7:SMART XS / 8:SMART V3 / 9:COMPACT / 10:AIR / 11:CONDENS XL | Kesseltyp | 9 | nein (L\_) |
| `L_starts` | - | 1 | - | Brennerstarts | 1558 | nein (L\_) |
| `L_runtime` | h | 1 | - | Brennerlaufzeit | 5439 | nein (L\_) |
| `L_avg_runtime` | min | 1 | - | PE Mittlere Laufzeit | 209 | nein (L\_) |
| `L_uw_release` | °C | 0.1 | -32768..32767 | Freigabetemperatur | 60 | nein (L\_) |
| `L_uw` | % | 1 | -32768..32767 | PE Drehzahl UW | 0 | nein (L\_) |
| `L_storage_fill` | kg | 1 | - | Füllstand Lager | 705 | nein (L\_) |
| `L_storage_min` | kg | 1 | 0..4000 | Warnung bei | 400 | nein (L\_) |
| `L_storage_max` | kg | 1 | 150..30000 | Max. Füllmenge Lager | 6000 | nein (L\_) |
| `L_storage_popper` | kg | 1 | -32768..32767 | Füllstand Zwischenb | 29 | nein (L\_) |
| `storage_fill_today` | kg | 1 | -32768..32767 | Pelletverbrauch heute | 2 | **gesperrt** |
| `storage_fill_yesterday` | kg | 1 | -32768..32767 | Pelletverbrauch gestern | 3 | **gesperrt** |
| `mode` | - | 1 | 0:Aus / 1:Auto / 2:Ein | Betriebsart | 1 | modes |
### forecast

Die 25 Felder `L_w_0` bis `L_w_24` sind Textfelder im Aufbau
`Datum|Temperatur|Bewölkung|Wind|Bildkennung|OWM-Code|Einheit`, bei `L_w_0`
zusätzlich `|Sonnenaufgang|Sonnenuntergang`. Beispiel aus der Messung:
`So, 23 Aug 21:35|18|84|2 km/h|04n|803|C|06:08|20:03`. Sie lassen sich im
Formular abschalten.

## Quellen

- Der eigene Dump dieser Anlage: `fixtures/all-metadaten.json`
- Hilfetext der Firmware V4.00b, abgetippt in Issue #2 des Dokumentationsrepos
- Issue #3 desselben Repos: Umlautabbruch und Taktgrenze
- Issue #4: HTTP 401 durch eine zweite abfragende Instanz
- PR #1: `remote_override` ist ein relativer Versatz, kein absoluter Sollwert
- Home-Assistant-Integration `dominikamann/oekofen-pellematic-compact`, Issue #178: der belegte Schadensfall beim Schreiben
