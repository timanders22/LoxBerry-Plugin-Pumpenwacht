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
ARGV2=$2
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-pumpenwacht}"
# Der Cron-Eintrag heisst nach dem NAMEN des Plugins, nicht nach dem Ordner
# (plugininstall.pl kopiert nach system/cron/cron.01min/<name>). Bei dieser
# Linie sind beide "pumpenwacht"; der Ordner bleibt der Rueckfall.
PNAME="${ARGV2:-$PFOLDER}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PDATA="$BASE/data/plugins/$PFOLDER"
# Die Endpunktprobe liegt seit 1.0.0 JE PUMPE (endpunkt_<kennung>.json);
# die endungslose Datei kommt noch aus 0.9.14. Und dienst.json ist der
# Bericht des ALTEN Zuhoerers - nach einem Update ist er eine Auskunft
# ueber einen Prozess, den es nicht mehr gibt.
rm -f "$PDATA/stand.json" "$PDATA/endpunkt.json" \
      "$PDATA"/endpunkt_*.json "$PDATA/dienst.json"
if [ -f "$PDATA/tage.json" ]; then
    echo "<OK> Tagesbilanz behalten ($(grep -o '"tag"' "$PDATA/tage.json" 2>/dev/null | wc -l) Tage)."
fi

# ---------- Ende der Installation: Waisen, Start, Marke ----------
#
# preupgrade.sh hat die Marke "Aktualisierung laeuft" gelegt, damit der
# Minutentakt in der Luecke nichts startet. Dieses Skript ist bei dieser Linie
# das LETZTE, das LoxBerry ruft - ein postroot.sh gibt es nicht (Reihenfolge
# nach Regeln/06: preroot, preinstall, preupgrade, postinstall, postupgrade,
# postroot). Also faellt die Marke hier.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
DIENST="$BASE/bin/plugins/$PFOLDER/pw_dienst.php"
CRON="$BASE/system/cron/cron.01min/$PNAME"

if [ -f "$MARKE" ]; then
    # Ein Zuhoerer, der die Marke um Sekunden verpasst hat, laeuft mit einem
    # Datenordner, den purge_installation danach geloescht hat: seine
    # PID-Datei und seine Sperrdatei sind weg. Ohne ihn zu beenden stuenden
    # hinterher zwei - der alte horcht weiter und schreibt in Dateien, die
    # niemand mehr liest. "stop" sucht seit 1.0.2 argumentweise ueber
    # /proc/<pid>/cmdline und findet auch einen ohne PID-Datei (gemessen
    # 18.09.2026, Pruefung-Pumpenwacht-1.0.2, Fall zwei).
    #
    # Die FEHLERAUSGABE kommt mit (2>&1). Dort und nur dort steht "Der
    # Zuhoerer liess sich nicht beenden" - und ein Zuhoerer, der sich nicht
    # beenden laesst, ist der eine Fall, in dem hinterher zwei laufen. Wer
    # ihn nach /dev/null schickt, hat die Meldung weggeworfen, auf die es
    # ankommt.
    if [ -f "$DIENST" ]; then
        AUSGABE=$(LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" \
                  php "$DIENST" stop 2>&1 </dev/null)
        case "$AUSGABE" in
            *"nicht beenden"*) echo "$AUSGABE" | sed 's/^/<WARNING> /' ;;
            *beendet*)         echo "$AUSGABE" | sed 's/^/<INFO> /' ;;
        esac
    fi
fi

# Gestartet wird HIER, und zwar durch den Minutentakt selbst.
#
# Warum ueberhaupt: bis 1.0.2 kam der Zuhoerer aus der Luecke und lief nach
# dem Update einfach weiter (in WSL gemessen, Fall luecke: ein Zuhoerer am
# Ende). Mit der Marke startet dort nichts mehr - ohne diesen Aufruf bliebe
# das Plugin bis zum naechsten Takt stumm, also bis zu einer Minute.
#
# Warum durch den Takt und nicht mit einer eigenen Startzeile: der Takt ist
# der einzige Weg, der die Voraussetzungen kennt (laeuft schon einer? steht
# die Quelle ueberhaupt auf MQTT?). Eine zweite Startzeile daneben waere eine
# zweite Wahrheit.
#
# Warum die Marke ERST DANACH faellt: zwischen dem Entfernen und dem
# Augenblick, in dem der neue Zuhoerer dasteht, sieht ein Takt weder die
# Marke noch einen laufenden Zuhoerer. PW_START_TROTZ_MARKE=1 schliesst
# dieses Fenster - die Marke liegt waehrend des ganzen Starts und weist jeden
# anderen Starter ab. Bauart: Chromecast4lox 1.3.11 postroot.sh, dort mit 400
# Waechterlaeufen gemessen. Fuer diese Linie in WSL nachgestellt
# (Pruefung-Pumpenwacht-1.0.3, Fall wettlauf).
if [ -f "$CRON" ]; then
    START_AUS=$(PW_START_TROTZ_MARKE=1 sh "$CRON" 2>&1 </dev/null)
    [ -n "$START_AUS" ] && echo "$START_AUS" | sed 's/^/<INFO> /'
else
    # Kein <WARNING>: nicht gefunden zu haben ist etwas anderes als kaputt zu
    # sein, und der Minutentakt startet ihn ohnehin. Gesagt wird es trotzdem -
    # eine stille Auslassung sucht sonst niemand.
    echo "<INFO> Der Minutentakt liegt nicht unter $CRON - er wird hier nicht"
    echo "<INFO> aufgerufen. Das System ruft ihn binnen einer Minute selbst."
fi

# Entfernt wird immer, auch wenn der Start unterblieb - sonst sperrte die
# Marke den Minutentakt eine Stunde lang.
rm -f "$MARKE" 2>/dev/null
if [ -e "$MARKE" ]; then
    echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen."
    echo "<WARNING> Der Minutentakt arbeitet erst wieder, wenn sie eine Stunde alt ist."
fi

# Die WIRKUNG nachsehen, nicht den Rueckgabewert des Starts. "status" gibt 0
# zurueck, wenn ein Zuhoerer laeuft. Dass keiner laeuft, ist auf einer Anlage
# ohne MQTT-Quelle der richtige Zustand - deshalb steht dort kein <WARNING>,
# sondern die Auskunft, die der Zuhoerer selbst gegeben hat.
if [ -f "$DIENST" ]; then
    if LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" \
       php "$DIENST" status >/dev/null 2>&1 </dev/null; then
        echo "<OK> Der MQTT-Zuhoerer laeuft wieder."
    else
        echo "<INFO> Es laeuft kein MQTT-Zuhoerer. Das ist richtig, solange keine"
        echo "<INFO> Pumpe ihre Messwerte ueber MQTT liest; sonst startet ihn der"
        echo "<INFO> Minutentakt binnen einer Minute."
    fi
fi

echo "<OK> postupgrade abgeschlossen - beim naechsten Durchlauf wird frisch gemessen."
exit 0
