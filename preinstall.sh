#!/bin/bash
# Pumpenwaechter - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 1.0.4 (I2, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22. Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach
# preupgrade und dem Aufraeumen der alten Fassung und VOR dem Kopieren
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.json - sie traegt das Aktionstoken) und
# ein liegengebliebener Bestand (data/plugins/<ordner>.bestand - Zustand,
# Tagesbilanz) gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
# Bis 1.0.3 spielte postinstall.sh die Zweitschrift auch bei einer
# Neuinstallation zurueck: Token und Einstellungen einer frueheren
# Installation kamen still wieder (Pruefbericht installer, Fall N). Die
# Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-pumpenwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/data/plugins/$PFOLDER.bestand"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
A="$BASE/config/plugins/$PFOLDER.backup.json.alt"
[ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen, Aktionstoken und Zaehlerstaende einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
