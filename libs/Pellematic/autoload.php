<?php

/**
 * Feste Ladeliste statt PSR-4.
 *
 * Symcon laedt Module in einem eigenen Prozessraum, und ein registrierter
 * Autoloader ueberlebt den Modulwechsel nicht zuverlaessig. Deshalb steht hier
 * eine ausdrueckliche, abhaengigkeitssortierte Liste.
 *
 * ACHTUNG: Wer eine neue Datei unter libs/Pellematic ablegt und sie hier
 * VERGISST, reisst beim ersten Zugriff die GESAMTE Library mit - alle Instanzen
 * stehen dann auf Status 105 und keine einzige OKP_-Funktion ist mehr
 * registriert. Im Log steht nichts, was darauf zeigt. Also: neue Datei, neuer
 * Eintrag, in der richtigen Reihenfolge.
 *
 * Reihenfolge verbindlich:
 *   Keys      - reiner Katalog, haengt von nichts ab
 *   Meta      - Metadatentabelle, haengt von Keys ab
 *   Parser    - Zerlegung, haengt von Keys ab
 *   Client    - Netz, haengt von nichts ab
 *   Profiles  - Profile, haengt von Keys ab
 *   StateBits - Bittexte, haengt von nichts ab
 *   Derived   - Rechenwerk, haengt von nichts ab
 *   WriteGuard- Freigaben, haengt von Keys und Meta ab
 *   Mapping   - Vorbelegung, haengt von Keys ab
 */

declare(strict_types=1);

$basis = __DIR__ . '/';

foreach ([
    'Keys.php',
    'Meta.php',
    'Parser.php',
    'Forecast.php',
    'Client.php',
    'Profiles.php',
    'StateBits.php',
    'Derived.php',
    'WriteGuard.php',
    'Mapping.php',
] as $datei) {
    // Der file_exists-Guard verhindert, dass ein halb ausgecheckter Ordner den
    // Ladevorgang mit einem fatalen Fehler beendet.
    if (file_exists($basis . $datei)) {
        require_once $basis . $datei;
    }
}
