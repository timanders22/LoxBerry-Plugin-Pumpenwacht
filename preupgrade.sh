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
# Seit 1.0.4 legt es ausserdem Zustand und Tagesbilanz als Bestand NEBEN den
# Datenordner (C1/C2); postupgrade.sh spielt ihn nur bei Marke zurueck.
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
# Nicht "ist die Datei da", sondern "steht eine Unixzeit darin".
#
# SEIT 1.0.4 EIN ABBRUCHGRUND (I2, Entscheidung 1): die Marke ist das
# einzige Zeichen, an dem preinstall.sh, postinstall.sh und postupgrade.sh
# ein Update von einer Neuinstallation unterscheiden. Ohne sie legte
# preinstall.sh die eben gesicherte Konfiguration beiseite, und nach dem
# Update gaebe es ein neues Aktionstoken - jede Adresse im Miniserver waere
# tot. Bauform AudiConnect 0.9.22.
if grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Der Minutentakt startet bis zum Ende der Installation nichts."
else
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation das Update fuer eine Neuinstallation und legte"
    echo "<FAIL> Konfiguration, Aktionstoken und Zaehlerstaende beiseite. Das Update wird abgebrochen."
    exit 2
fi

# ---------- Die Zweitschrift der Konfiguration ----------
# I3 (1.0.4): erneuert wird sie NUR aus einer Konfiguration, die sich als
# JSON lesen laesst und ein Aktionstoken traegt - dieselbe Regel wie
# pw_config_hat_inhalt(). Bis 1.0.3 genuegte "die Datei ist nicht leer": eine
# abgeschnittene Konfiguration ueberschrieb den einzigen Rueckweg, und nach
# dem Update fehlte das Token (Pruefbericht installer, Fall K). Gebaut wird
# unter .neu mit Rechten 0600 VOR dem Inhalt (umask), verglichen, dann
# umbenannt.
CF="$BASE/config/plugins/$PFOLDER/pumpenwacht.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
cfg_traegt_token() {
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit((is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"]) && trim($d["aktionstoken"]) !== "") ? 0 : 1);' "$1" 2>/dev/null
}
if [ -f "$CF" ] && [ -s "$CF" ]; then
    cfg_traegt_token "$CF"
    PRUEF=$?
    if [ "$PRUEF" = "0" ]; then
        rm -f "$BK.neu" 2>/dev/null
        if (umask 077 && cp "$CF" "$BK.neu") 2>/dev/null && chmod 600 "$BK.neu" 2>/dev/null \
           && cmp -s "$CF" "$BK.neu" && mv -f "$BK.neu" "$BK"; then
            echo "<OK> Konfiguration gesichert ($BK)."
        else
            rm -f "$BK.neu" 2>/dev/null
            echo "<FAIL> Die Konfiguration liess sich nicht sichern - Update trotzdem moeglich,"
            echo "<FAIL> aber die Einstellungen koennten verlorengehen."
        fi
    elif [ "$PRUEF" = "2" ]; then
        echo "<WARNING> Ohne PHP laesst sich die Konfiguration nicht pruefen - die Zweitschrift bleibt unveraendert ($BK)."
    else
        echo "<WARNING> Die Konfiguration ist nicht lesbar oder traegt kein Aktionstoken - die Zweitschrift"
        echo "<WARNING> bleibt unveraendert ($BK); sie wird nach dem Update zurueckgespielt."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - nichts zu sichern."
fi
# Den Zuhoerer anhalten, BEVOR seine Dateien ersetzt werden. Gibt es ihn nicht
# (Quelle steht auf Loxone), ist das kein Fehler. Gemeldet wird, WAS der
# Zuhoerer gesagt hat, nicht der Rueckgabewert von "stop" (bis 1.0.2 stand
# "<OK> MQTT-Zuhoerer angehalten." auch dann, wenn nichts lief).
DIENST="$BASE/bin/plugins/$PFOLDER/pw_dienst.php"
if [ -f "$DIENST" ]; then
    AUSGABE=$(LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php "$DIENST" stop 2>&1 </dev/null)
    if [ -n "$AUSGABE" ]; then
        echo "$AUSGABE" | sed 's/^/<INFO> /'
    else
        echo "<INFO> Der Zuhoerer liess sich nicht befragen (php fehlt oder"
        echo "<INFO> pw_lib.php ist nicht auffindbar) - es wurde nichts beendet."
    fi
fi

# ---------- Zustand und Tagesbilanz beiseitelegen ----------
# C1/C2/I1 (1.0.4). purge_installation loescht data/plugins/<ordner>/ bei
# JEDEM Update (Regeln/06). Bis 1.0.3 gingen dabei die Tagesbilanz (bis 60
# Tage), Betriebsstunden, Starts gesamt, Wartungsmarken und der Zeitpunkt des
# letzten Laufs verloren - und damit war der Ruhe-Alarm der Sumpfpumpe nach
# jedem Update abgeschaltet, bis sie wieder einmal lief (Pruefbericht code C1,
# C2; installer 1, 2). Der Bestand liegt NEBEN dem Datenordner; ein alter
# Bestand wird vorher weggeraeumt (Entscheidung 1), gebaut wird unter .neu.
# Nach dem Anhalten des Zuhoerers, damit er nicht dazwischenschreibt; der
# Minutentakt steht wegen der Marke still.
PDATA="$BASE/data/plugins/$PFOLDER"
BESTAND="$BASE/data/plugins/$PFOLDER.bestand"
rm -rf "${BESTAND:?}" "${BESTAND:?}.neu" 2>/dev/null
if [ -d "$PDATA" ]; then
    N=0
    FEHL=""
    mkdir -p "$BESTAND.neu" 2>/dev/null
    # alarm.json (letzter Alarm) und signal.json (Merker der SignalBot-
    # Meldungen) gehen seit dem Verbesserungsbau 30.09.2026 mit - der Reiter
    # Test zeigt den letzten Alarm auch nach dem Update, und ein gemeldeter
    # Alarm bekommt sein Ende (Entscheidung 13).
    for f in stand.json tage.json mqtt_praefixe.json alarm.json signal.json; do
        [ -f "$PDATA/$f" ] || continue
        if cp "$PDATA/$f" "$BESTAND.neu/$f" 2>/dev/null && cmp -s "$PDATA/$f" "$BESTAND.neu/$f"; then
            N=$((N + 1))
        else
            FEHL="$FEHL $f"
        fi
    done
    if [ -n "$FEHL" ]; then
        rm -rf "${BESTAND:?}.neu" 2>/dev/null
        echo "<FAIL> Zustand und Tagesbilanz liessen sich nicht beiseitelegen ($FEHL) - sie gehen mit dem Update verloren."
    elif [ "$N" = "0" ]; then
        rm -rf "${BESTAND:?}.neu" 2>/dev/null
        echo "<INFO> Kein Zustand und keine Tagesbilanz vorhanden - nichts beiseitezulegen."
    elif mv "$BESTAND.neu" "$BESTAND" 2>/dev/null; then
        TAGE=$(grep -o '"tag"' "$BESTAND/tage.json" 2>/dev/null | wc -l)
        echo "<OK> Zustand und Tagesbilanz beiseitegelegt ($N Dateien, $TAGE Tage): $BESTAND"
    else
        rm -rf "${BESTAND:?}.neu" 2>/dev/null
        echo "<FAIL> Der Bestand liess sich nicht anlegen ($BESTAND) - Zustand und Tagesbilanz gehen verloren."
    fi
fi

echo "<OK> preupgrade abgeschlossen."
exit 0
