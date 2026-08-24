<?php

/**
 * Die harten Verbote, als Test statt als guter Vorsatz.
 *
 * AC_SetLoggingStatus(false) LOESCHT die aufgezeichneten Daten, es pausiert sie
 * nicht. Unter #<ID> haengen 64 archivierte Variablen, vier davon mit 13 und
 * 14 Jahren Historie. Ein einziger falscher Aufruf ist unumkehrbar - deshalb
 * darf er im Quelltext gar nicht erst vorkommen.
 *
 * Aufruf: /usr/bin/php tests/VerboteTest.php
 */

declare(strict_types=1);

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

echo "VerboteTest\n";

$wurzel = dirname(__DIR__);
$dateien = [];
foreach ([$wurzel . '/libs', $wurzel . '/Pellematic'] as $ordner) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ordner));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $dateien[] = $f->getPathname();
        }
    }
}
sort($dateien);
pruef('es wurden Quelldateien gefunden', count($dateien) >= 10, (string) count($dateien));

// --- Verbotene Aufrufe ------------------------------------------------------
$verboten = [
    'AC_SetAggregationType' => 'wuerde eine bestehende Aggregation umstellen',
    'AC_ReAggregateVariable' => 'wuerde eine bestehende Reihe neu aufbauen',
    'IPS_Delete' . 'Variable' => 'wuerde eine Variable samt Historie entfernen',
    'IPS_Delete' . 'Object' => 'wuerde ein Objekt samt allem darunter entfernen',
];
foreach ($verboten as $name => $warum) {
    $treffer = [];
    foreach ($dateien as $f) {
        foreach (file($f) as $i => $zeile) {
            if (str_contains($zeile, $name)) {
                $treffer[] = basename($f) . ':' . ($i + 1);
            }
        }
    }
    pruef($name . ' kommt nirgends vor (' . $warum . ')', $treffer === [], implode(', ', $treffer));
}

// --- AC_SetLoggingStatus nur mit true ----------------------------------------
$logZeilen = [];
foreach ($dateien as $f) {
    foreach (file($f) as $i => $zeile) {
        // Nur echte Aufrufstellen zaehlen, nicht die Pruefung auf Vorhandensein.
        if (str_contains($zeile, 'AC_SetLoggingStatus(') && !str_contains($zeile, 'function_exists')) {
            $logZeilen[] = [basename($f) . ':' . ($i + 1), trim($zeile)];
        }
    }
}
pruef('AC_SetLoggingStatus steht an hoechstens einer Stelle', count($logZeilen) <= 1,
    (string) count($logZeilen));
foreach ($logZeilen as [$wo, $zeile]) {
    pruef('AC_SetLoggingStatus in ' . $wo . ' schaltet nur EIN', str_contains($zeile, ', true)'), $zeile);
    pruef('und niemals AUS', !str_contains($zeile, 'false'), $zeile);
}

// --- utf8_encode ist seit PHP 8.2 abgekuendigt --------------------------------
$u = [];
foreach ($dateien as $f) {
    if (str_contains(file_get_contents($f), 'utf8_encode')) {
        $u[] = basename($f);
    }
}
pruef('utf8_encode kommt nicht vor (seit PHP 8.2 abgekuendigt)', $u === [], implode(', ', $u));

// --- kein Passwort im Klartext ------------------------------------------------
$p = [];
foreach ($dateien as $f) {
    // KEINE Ausnahme fuer module.php: das Repo geht auf GitHub, und ein Passwort
    // als Vorbelegung eines Formularfeldes ist trotzdem ein Passwort im Klartext.
    if (str_contains(file_get_contents($f), 'c3ua')) {
        $p[] = basename($f);
    }
}
pruef('das Passwort steht in keiner Datei des Repos', $p === [], implode(', ', $p));

// --- keine Adresse aus dem Hausnetz als Vorbelegung ---------------------------
$ip = [];
foreach ($dateien as $f) {
    if (preg_match('/10\.10\.\d+\.\d+/', (string) file_get_contents($f))) {
        $ip[] = basename($f);
    }
}
pruef('keine Adresse des Hausnetzes im Quelltext', $ip === [], implode(', ', $ip));

// --- alle Dateien sind syntaktisch heil ----------------------------------------
$kaputt = [];
foreach ($dateien as $f) {
    exec('/usr/bin/php -l ' . escapeshellarg($f) . ' 2>&1', $aus, $rc);
    if ($rc !== 0) {
        $kaputt[] = basename($f);
    }
    $aus = [];
}
pruef('alle ' . count($dateien) . ' Quelldateien sind syntaktisch fehlerfrei', $kaputt === [],
    implode(', ', $kaputt));

// --- jede Datei unter libs steht in der Ladeliste ---------------------------------
$autoload = file_get_contents($wurzel . '/libs/Pellematic/autoload.php');
$fehlend = [];
foreach (glob($wurzel . '/libs/Pellematic/*.php') as $f) {
    $b = basename($f);
    if ($b === 'autoload.php') {
        continue;
    }
    if (!str_contains($autoload, "'" . $b . "'")) {
        $fehlend[] = $b;
    }
}
pruef('jede Bibliotheksdatei steht in der Ladeliste', $fehlend === [], implode(', ', $fehlend));

printf("  %d/%d\n\n", $ok, $n);
exit($ok === $n ? 0 : 1);
