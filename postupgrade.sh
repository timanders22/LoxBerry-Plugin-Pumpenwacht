#!/bin/bash
# Pumpenwaechter - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Was hier bleibt, ist das eine, was postinstall NICHT tun darf: den
# zwischengespeicherten Zustand aufraeumen.
#
# Bis 0.9.7 stand hier der Kommentar der Einspeisebremse - ueber gestellte
# Grenzen und Wechselrichter, die ihren Wert behalten. Nichts davon gibt es
# in diesem Plugin.
#
# WAS GELOESCHT WIRD UND WAS NICHT:
#   stand.json          weg. Er traegt Laufzeiten, Startlisten und die
#                       MQTT-Signatur. Aendert sich sein Aufbau zwischen zwei
#                       Fassungen, rechnete der erste Durchlauf sonst mit
#                       Feldern, die anders gemeint sind.
#   endpunkt.json       weg. Das ist nur der Zwischenspeicher der
#                       Selbstpruefung; nach einem Update soll sie neu messen.
#   tage.json           BLEIBT. Die Tagesbilanz ist die einzige Zahl, die
#                       dieses Plugin ueber Wochen sammelt - sie ist der
#                       eigentliche Wert, und sie neu aufzubauen dauerte
#                       sechzig Tage.
#   stand.lock          BLEIBT (leere Datei, traegt nur die Dateisperre).
#
# Die Sperre in Loxone geht dabei NICHT verloren: sie steht in stand.json und
# faellt damit weg - aber der Waechter schaltet ohnehin nichts selbst, und der
# erste Durchlauf nach dem Update stellt den Befund neu. Ein Trockenlauf, der
# noch anliegt, wird sofort wieder erkannt; einer, der vorbei ist, gilt als
# vorbei. Das ist die Seite, auf der man in dieser Luecke irren will.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-pumpenwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PDATA="$BASE/data/plugins/$PFOLDER"
rm -f "$PDATA/stand.json" "$PDATA/endpunkt.json"
if [ -f "$PDATA/tage.json" ]; then
    echo "<OK> Tagesbilanz behalten ($(grep -o '"tag"' "$PDATA/tage.json" 2>/dev/null | wc -l) Tage)."
fi
echo "<OK> postupgrade abgeschlossen - beim naechsten Durchlauf wird frisch gemessen."
exit 0
