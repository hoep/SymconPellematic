<?php

/**
 * Die abgeleiteten Groessen - vor allem der Pelletzaehler, der nicht
 * rekonstruierbar ist und deshalb nie zurueckspringen darf.
 *
 * Aufruf: /usr/bin/php tests/DerivedTest.php
 */

declare(strict_types=1);

require __DIR__ . '/../libs/Pellematic/autoload.php';

use Hoep\Pellematic\Derived;

$ok = 0;
$n = 0;
function pruef(string $was, bool $gut, string $ist = ''): void
{
    global $ok, $n;
    $n++;
    if ($gut) {
        $ok++;
    }
    printf("  %-5s %s%s\n", $gut ? 'ok' : 'FEHL', $was, $gut || $ist === '' ? '' : '  [' . $ist . ']');
}

echo "DerivedTest\n";

// --- Fuellstand -------------------------------------------------------------
pruef('705 kg von 6000 kg sind 11,8 Prozent', Derived::fillPercent(705.0, 6000.0) === 11.8,
    (string) Derived::fillPercent(705.0, 6000.0));
pruef('Kapazitaet 0 ergibt keinen Wert statt einer Division durch null',
    Derived::fillPercent(705.0, 0.0) === null);
pruef('ohne Fuellstand gibt es keinen Prozentwert', Derived::fillPercent(null, 6000.0) === null);
pruef('eine geaenderte Lagergroesse rechnet mit', Derived::fillPercent(705.0, 8000.0) === 8.8);

// --- Tageswerte --------------------------------------------------------------
pruef('1558 Starts bei 1550 um Mitternacht sind 8 heute', Derived::dailyDelta(1558, 1550) === 8);
pruef('ohne Bezugspunkt beginnt der Tag bei 0', Derived::dailyDelta(1558, null) === 0);
pruef('ein Zaehlerruecksprung erzeugt keinen negativen Tageswert',
    Derived::dailyDelta(5, 1550) === 0, (string) Derived::dailyDelta(5, 1550));
pruef('ohne Zaehlerstand gibt es keinen Tageswert', Derived::dailyDelta(null, 1550) === null);
pruef('5439 Stunden bei 5437 um Mitternacht sind 2 heute', Derived::dailyDelta(5439, 5437) === 2);

// --- Pelletzaehler ------------------------------------------------------------
// Ausgangslage: der uebernommene Stand aus #<ID>.
$gesamt = 10413.0;
$letztes = 2;
$gutgeschrieben = 2;

// Der Tag laeuft weiter: 2 -> 5 kg.
$r = Derived::totalConsumption(5, 3, $letztes, $gutgeschrieben, $gesamt);
pruef('Zuwachs von 3 kg wird addiert', $r['total'] === 10416.0, (string) $r['total']);
pruef('und als "heute gutgeschrieben" gemerkt', $r['credited'] === 5, (string) $r['credited']);

// Kein Zuwachs: nichts passiert.
$r2 = Derived::totalConsumption(5, 3, $r['lastToday'], $r['credited'], $r['total']);
pruef('ohne Zuwachs bleibt der Zaehler stehen', $r2['total'] === 10416.0);

// Tageswechsel: heute faellt auf 0, gestern meldet 5 kg - genau das, was schon
// gutgeschrieben wurde. Es darf nichts doppelt gezaehlt werden.
$r3 = Derived::totalConsumption(0, 5, $r2['lastToday'], $r2['credited'], $r2['total']);
pruef('Tageswechsel ohne Doppelzaehlung', $r3['total'] === 10416.0, (string) $r3['total']);
pruef('und der neue Tag beginnt bei 0', $r3['credited'] === 0);

// Tageswechsel mit Nachzuegler: gestern meldet 7 kg, gutgeschrieben waren 5.
$r4 = Derived::totalConsumption(1, 7, 5, 5, 10416.0);
pruef('der Rest des Vortags wird nachgetragen (2 kg) plus 1 kg heute',
    $r4['total'] === 10419.0, (string) $r4['total']);

// Ein Sprung nach unten ohne Vortagsmeldung darf nichts abziehen.
$r5 = Derived::totalConsumption(0, null, 12, 12, 10419.0);
pruef('ein Ruecksprung ohne Vortagswert zieht nichts ab', $r5['total'] === 10419.0);

// Der Zaehler darf ueber viele Runden nie kleiner werden.
$stand = 10000.0;
$last = 0;
$cred = 0;
$kleinste = $stand;
foreach ([0, 1, 3, 3, 7, 0, 2, 2, 9, 0, 4] as $i => $heute) {
    $vor = $stand;
    $res = Derived::totalConsumption($heute, 9, $last, $cred, $stand);
    $stand = $res['total'];
    $last = $res['lastToday'];
    $cred = $res['credited'];
    if ($stand < $vor) {
        $kleinste = -1;
    }
}
pruef('ueber elf Runden mit zwei Tageswechseln springt der Zaehler nie zurueck', $kleinste !== -1);
pruef('und ist am Ende gewachsen', $stand > 10000.0, (string) $stand);

// --- kWh -----------------------------------------------------------------------
pruef('10413 kg mal 4,8 kWh/kg sind 49982,4 kWh', Derived::kwh(10413.0, 4.8) === 49982.4,
    (string) Derived::kwh(10413.0, 4.8));
pruef('ein anderer Heizwert rechnet mit', Derived::kwh(100.0, 5.0) === 500.0);

// --- Brenner und Pumpe ----------------------------------------------------------
pruef('Brennerkontakt 1 heisst: der Brenner laeuft', Derived::burnerRunning(1, 0) === true);
pruef('Brennerkontakt 0 schlaegt die Modulation', Derived::burnerRunning(0, 55) === false);
pruef('ohne Brennerkontakt greift die Modulation', Derived::burnerRunning(null, 55) === true);
pruef('ohne beides gibt es keine Aussage', Derived::burnerRunning(null, null) === null);
pruef('ein Wahrheitswert wird direkt uebernommen', Derived::burnerRunning(true, null) === true);
pruef('Pumpendrehzahl 0 heisst: Pumpe aus', Derived::pumpRunning(0) === false);
pruef('Pumpendrehzahl 45 heisst: Pumpe laeuft', Derived::pumpRunning(45) === true);

// --- Tageswechsel erkennen ---------------------------------------------------------
pruef('derselbe Tag ist kein neuer Tag', !Derived::istNeuerTag(date('Y-m-d')));
pruef('gestern ist ein neuer Tag', Derived::istNeuerTag(date('Y-m-d', time() - 86400)));
pruef('ein leerer Stempel ist ein neuer Tag', Derived::istNeuerTag(''));

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
