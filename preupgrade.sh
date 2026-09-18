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

# ---------- Zuerst die Marke "Aktualisierung laeuft" ----------
# Sie steht VOR allem anderen, auch vor der Sicherung: der Installer legt die
# Cron-Datei rund eine Minute vor dem letzten Hakenskript neu an, und faellt
# ein Minutentakt in diese Luecke, startet er einen Zuhoerer mit halb
# abgeraeumter Umgebung (Regeln/06; in WSL am 18.09.2026 fuer diese Linie
# nachgestellt und gemessen: ein Zuhoerer, ein angelegter Datenordner).
#
# Sie liegt NEBEN dem Datenordner, nicht darin: purge_installation loescht
# data/plugins/<ordner>/ und trifft den Nachbarn mit dem Punkt nicht.
# cron/cron.01min achtet sie, solange sie juenger als eine Stunde ist;
# postupgrade.sh raeumt sie weg und startet danach selbst.
mkdir -p "$BASE/data/plugins" 2>/dev/null
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
# Die geschweiften Klammern sind kein Zierrat: die Umleitung wird VOR dem
# "2>/dev/null" ausgewertet. Scheitert sie - fehlender Ordner, volles
# Dateisystem -, meldet die Schale das selbst, und die Meldung stuende roh im
# Installationsprotokoll (Regeln/06, dieselbe Falle wie bei "exec 2>>datei").
{ date +%s > "$MARKE"; } 2>/dev/null
# Nicht "ist die Datei da", sondern "steht eine Unixzeit darin": eine leere
# oder halb geschriebene Marke gilt dem Takt als unlesbar und haelt ihn
# nicht auf - dann soll das hier stehen und nicht "<OK>".
if grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Der Minutentakt startet bis zum Ende der Installation nichts."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - der Minutentakt"
    echo "<WARNING> kann waehrend der Installation einen Zuhoerer starten."
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
