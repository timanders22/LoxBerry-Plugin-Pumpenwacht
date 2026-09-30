#!/bin/bash
# Pumpenwaechter - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Was hier bleibt: den Bestand aus preupgrade.sh zurueckspielen, die
# Zwischenspeicher aufraeumen, den Zuhoerer neu starten und die Marke
# entfernen.
#
# Bis 0.9.7 stand hier der Kommentar der Einspeisebremse - ueber gestellte
# Grenzen und Wechselrichter, die ihren Wert behalten. Nichts davon gibt es
# in diesem Plugin.
#
# WAS NACH EINEM UPDATE BLEIBT (C1/C2/I1, 1.0.4):
#   stand.json          KOMMT ZURUECK - aus dem Bestand, den preupgrade.sh
#                       neben den Datenordner gelegt hat. Er traegt
#                       Betriebsstunden, Starts gesamt, Wartungsmarken, den
#                       Zeitpunkt des letzten Laufs (Ruhe-Ueberwachung) und
#                       eine anliegende Sperre. Bis 1.0.3 wurde er hier
#                       geloescht: danach war die Ruhe-Ueberwachung der
#                       Sumpfpumpe abgeschaltet, bis sie wieder einmal lief
#                       (je_gelaufen haengt an starts_gesamt), und die Zaehler
#                       standen in Loxone auf 0 (Pruefbericht code C1).
#   tage.json           KOMMT ZURUECK. Bis 1.0.3 stand hier "BLEIBT" - das
#                       war falsch: purge_installation loescht den Datenordner
#                       bei jedem Update, und die Tagesbilanz ging verloren
#                       (Pruefbericht code C2, installer 1).
#   mqtt_praefixe.json  KOMMT ZURUECK - die Deinstallation raeumt unter jedem
#                       je benutzten Praefix ab (B9).
#   alarm.json          KOMMT ZURUECK - der letzte Alarm je Pumpe (Reiter Test,
#                       Verbesserungsbau 30.09.2026).
#   signal.json         KOMMT ZURUECK - welcher Alarm schon ueber SignalBot
#                       gemeldet ist; sonst bekaeme ein gemeldeter Alarm nach
#                       dem Update kein Ende gemeldet.
#   endpunkt*.json      weg. Das ist nur der Zwischenspeicher der
#                       Selbstpruefung; nach einem Update soll sie neu messen.
#   dienst.json         weg - ein Bericht ueber einen Prozess, den es nicht
#                       mehr gibt.
# Zurueckgespielt wird NUR bei vorhandener Marke (Entscheidung 1); ohne Marke
# geht der Bestand nach .alt.
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
BESTAND="$BASE/data/plugins/$PFOLDER.bestand"
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
TAKT="$BASE/bin/plugins/$PFOLDER/pw_takt.php"
# Die Endpunktprobe liegt seit 1.0.0 JE PUMPE (endpunkt_<kennung>.json);
# die endungslose Datei kommt noch aus 0.9.14.
rm -f "$PDATA/endpunkt.json" "$PDATA"/endpunkt_*.json "$PDATA/dienst.json"

ZURUECK=0
if [ -d "$BESTAND" ]; then
    if [ -f "$MARKE" ]; then
        mkdir -p "$PDATA"
        FEHL=""
        for f in stand.json tage.json mqtt_praefixe.json alarm.json signal.json; do
            [ -f "$BESTAND/$f" ] || continue
            # Unter einem eigenen Namen kopieren und dann umbenennen. Liegt
            # schon eine Sperrdatei (eine Anlieferung aus Loxone hat in der
            # Zwischenzeit geschrieben), wird unter ihr umbenannt - sonst
            # koennte die Anlieferung den zurueckgespielten Stand gleich
            # wieder ueberschreiben. Angelegt wird sie hier nicht: sie gehoert
            # dem Plugin.
            if cp "$BESTAND/$f" "$PDATA/$f.bestand.neu" 2>/dev/null; then
                if [ -f "$PDATA/stand.lock" ] && command -v flock >/dev/null 2>&1; then
                    flock -w 10 "$PDATA/stand.lock" mv -f "$PDATA/$f.bestand.neu" "$PDATA/$f"
                else
                    mv -f "$PDATA/$f.bestand.neu" "$PDATA/$f"
                fi
                if cmp -s "$BESTAND/$f" "$PDATA/$f"; then
                    ZURUECK=$((ZURUECK + 1))
                else
                    FEHL="$FEHL $f"
                fi
            else
                FEHL="$FEHL $f"
            fi
            rm -f "$PDATA/$f.bestand.neu" 2>/dev/null
        done
        if [ -z "$FEHL" ]; then
            rm -rf "${BESTAND:?}"
            echo "<OK> Zustand zurueckgespielt: Zaehler, Wartungsmarken und die Ruhe-Ueberwachung laufen weiter."
        else
            echo "<FAIL> Nicht zurueckgespielt:$FEHL - der Bestand bleibt liegen: $BESTAND"
        fi
    else
        rm -rf "${BESTAND:?}.alt" 2>/dev/null
        mv -f "$BESTAND" "$BESTAND.alt" 2>/dev/null
        echo "<WARNING> Ohne die Marke aus preupgrade.sh wird der Bestand nicht eingespielt - beiseitegelegt: $BESTAND.alt"
    fi
fi
if [ -f "$PDATA/tage.json" ]; then
    echo "<OK> Tagesbilanz behalten ($(grep -o '"tag"' "$PDATA/tage.json" 2>/dev/null | wc -l) Tage)."
fi
# Der erste Takt nach dem Update schickt den VOLLEN Satz (B2): sonst stuenden
# die retained Zustaende, die sich seit dem Update nicht geaendert haben,
# nicht im Broker (Regeln/07, ACTiKamera 1.9.19). Derselbe Aufruf setzt ein
# aus 1.0.3 uebernommenes stale_s=300 auf 180 (Entscheidung 13).
if [ -f "$TAKT" ] && command -v php >/dev/null 2>&1; then
    NU=$(LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php "$TAKT" --nach-update 2>&1 </dev/null)
    [ -n "$NU" ] && echo "$NU" | sed 's/^/<INFO> /'
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

echo "<OK> postupgrade abgeschlossen."
exit 0
