#!/bin/bash
# Pumpenwaechter - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar
# sein, ohne Schaden anzurichten.
#
# Dieses Skript laeuft als Benutzer loxberry, NICHT als root.
#
# Bis 0.9.7 stand hier der Text des Einspeisebremse-Plugins: Netzzaehler mit
# Vorzeichen, Stellglieder mit {W}/{KW}/{PROZENT}, "Regelung einschalten",
# eine Pruefung auf mosquitto-clients, die dieses Plugin gar nicht benutzt,
# und ein chown ohne Root-Rechte. Nichts davon hatte mit dem Pumpenwaechter
# zu tun.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-pumpenwacht}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/pumpenwacht.json" ] || echo '{}' > "$PCONFIG/pumpenwacht.json"
chmod 600 "$PCONFIG/pumpenwacht.json" 2>/dev/null

# ---------- Konfiguration aus der Zweitschrift ----------
# Sie liegt NEBEN dem Ordner, nicht darin: LoxBerry entfernt
# config/plugins/<ordner>/ bei Deinstallation und Neuinstallation, und eine
# Sicherung im Ordner staerbe genau in dem Fall mit, fuer den es sie gibt.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/pumpenwacht.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        if cp -p "$BK" "$CF"; then
            chmod 600 "$CF" 2>/dev/null
            echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
        else
            echo "<FAIL> Die Zweitschrift liess sich nicht zurueckspielen: $BK"
        fi
    fi
fi
# Sie traegt dasselbe Geheimnis wie die Konfiguration - also dieselben Rechte.
[ -f "$BK" ] && chmod 600 "$BK" 2>/dev/null

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. Ohne PHP laeuft weder die Oberflaeche noch der Minutentakt."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- Der Selbsttest des Rechenkerns ----------
# Ohne Anlage und ohne Netz: er rechnet die Entscheidungen durch und
# vergleicht sie mit den hinterlegten Sollwerten. Ein Kern, der hier
# durchfaellt, wuerde in Loxone falsche Befunde stellen - das soll man
# erfahren, bevor man die Sperre verdrahtet.
KERN="$BASE/webfrontend/html/plugins/$PFOLDER/pw_regel.php"
if [ -f "$KERN" ]; then
    if AUSGABE=$(php "$KERN" 2>&1); then
        echo "<OK> $(echo "$AUSGABE" | tail -1)"
    else
        echo "<FAIL> Der Selbsttest des Rechenkerns ist fehlgeschlagen:"
        echo "$AUSGABE" | grep '\[FEHL\]' | head -10
    fi
else
    echo "<INFO> Rechenkern nicht gefunden ($KERN) - Selbsttest uebersprungen."
fi

# ---------- Der Minutentakt ----------
# Er laesst sich hier nicht starten (das macht der Cron des Systems), aber es
# laesst sich SAGEN, ob die Datei angekommen ist - ein Cron, der fehlt, faellt
# sonst niemandem auf.
if [ -f "$PBIN/pw_takt.php" ]; then
    if AUSGABE=$(php "$PBIN/pw_takt.php" --probe 2>&1); then
        echo "<OK> Der Minutentakt laeuft (einmal von Hand aufgerufen, nichts geschrieben)."
    else
        echo "<FAIL> Der Minutentakt bricht ab:"
        echo "$AUSGABE" | head -5
    fi
else
    echo "<FAIL> bin/pw_takt.php fehlt - ohne ihn merkt niemand, wenn der Zwischenzaehler ausfaellt."
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Pumpenmodell waehlen - die Schwellen"
echo "<INFO>     werden dann aus dem Datenblatt vorgeschlagen. Wer misst,"
echo "<INFO>     traegt seine eigenen Zahlen ein."
echo "<INFO>  2. Reiter Einbindung in Loxone: die beiden Vorlagen erzeugen und"
echo "<INFO>     in Loxone Config unter 'Vordefinierte Geraete' einlesen."
echo "<INFO>  3. Reiter Test: 'Zustand ansehen' und 'Testwert anliefern' -"
echo "<INFO>     dort steht, welchen Befund das Plugin daraus stellt."
echo "<INFO>  4. Erst wenn das stimmt: im Reiter Einstellungen 'Sperren"
echo "<INFO>     einschalten' anhaken. Ab Werk sperrt der Waechter NICHTS -"
echo "<INFO>     er misst und meldet."
exit 0
