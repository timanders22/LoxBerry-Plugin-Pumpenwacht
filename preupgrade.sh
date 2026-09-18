#!/bin/bash
# Pumpenwaechter - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Die Reihenfolge des Installers ist:
#   preupgrade -> config/* aus dem Archiv ueber config/plugins/<ordner>
#              -> postinstall -> postupgrade -> Cleaning
# Wer eine Konfiguration ueber das Upgrade retten will, muss das VOR dem
# Kopierschritt tun, also hier - und nicht nach /tmp, das auf dem LoxBerry
# fluechtig ist.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# sechsten Argument. Deshalb wird hier ausschliesslich mit $3 und $5
# gearbeitet.
#
# Bis 0.9.7 hielt dieses Skript hier einen Dienst "dienst.sh" an, den es in
# diesem Plugin nie gab, und behauptete dazu, postinstall.sh starte ihn
# hinterher neu. Beides war Text aus einem anderen Plugin. Der Pumpenwaechter
# hat keinen Dauerlaeufer - er hat einen Minutentakt, und der ist in sich
# abgeschlossen: laeuft er waehrend des Updates, schreibt er hoechstens
# einmal und ist wieder fertig.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-pumpenwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

CF="$BASE/config/plugins/$PFOLDER/pumpenwacht.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$CF" ] && [ -s "$CF" ]; then
    if cp -p "$CF" "$BK"; then
        chmod 600 "$BK" 2>/dev/null
        echo "<OK> Konfiguration gesichert ($BK)."
    else
        echo "<FAIL> Die Konfiguration liess sich nicht sichern - Update trotzdem moeglich,"
        echo "<FAIL> aber die Einstellungen koennten verlorengehen."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi
# Den Zuhoerer anhalten, BEVOR seine Dateien ersetzt werden. Ein laufender
# Prozess, dessen Quelltext unter ihm ausgetauscht wird, ist eine Wette;
# der Minutentakt startet ihn hinterher ohnehin neu. Gibt es ihn nicht
# (Quelle steht auf Loxone), ist das kein Fehler.
# Gemeldet wird, WAS der Zuhoerer gesagt hat, nicht der Rueckgabewert von
# "stop": der ist auch dann 0, wenn gar nichts lief. Bis 1.0.2 stand deshalb
# "<OK> MQTT-Zuhoerer angehalten." auch dann im Installationsprotokoll, wenn
# es nichts anzuhalten gab - und im gemessenen Fall (18.09.2026, Klasse F)
# sogar dann, wenn ein FREMDER Vorgang beendet worden war.
DIENST="$BASE/bin/plugins/$PFOLDER/pw_dienst.php"
if [ -f "$DIENST" ]; then
    AUSGABE=$(LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php "$DIENST" stop 2>/dev/null)
    if [ -n "$AUSGABE" ]; then
        echo "$AUSGABE" | sed 's/^/<INFO> /'
    else
        echo "<INFO> Der Zuhoerer liess sich nicht befragen (php fehlt oder"
        echo "<INFO> pw_lib.php ist nicht auffindbar) - es wurde nichts beendet."
    fi
fi

echo "<OK> preupgrade abgeschlossen."
exit 0
