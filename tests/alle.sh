#!/bin/sh
# Alle Tests der Reihe nach. Aufruf: sh tests/alle.sh
# Rueckgabe 0 nur, wenn jeder einzelne Test fehlerfrei durchlaeuft.
cd "$(dirname "$0")/.." || exit 1
fehler=0
for t in ParserTest MetaTest WriteGuardTest StateBitsTest DerivedTest ForecastTest EchtvergleichTest VerboteTest; do
    /usr/bin/php "tests/$t.php" || fehler=1
done
if [ "$fehler" -eq 0 ]; then
    echo "Alle Tests fehlerfrei."
else
    echo "MINDESTENS EIN TEST IST FEHLGESCHLAGEN."
fi
exit "$fehler"
