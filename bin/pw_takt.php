<?php
/**
 * Pumpenwaechter - der Minutentakt
 *
 * WARUM ES IHN GIBT
 *
 * Bis 0.9.7 rechnete das Plugin ausschliesslich, wenn der Miniserver einen
 * Watt-Wert anlieferte. Zwei Folgen, beide am 28.08.2026 gemessen:
 *
 *   1. Bleibt die Anlieferung aus, laeuft die Laufzeit nicht weiter. 180 W
 *      einmal geliefert, dann zwoelf Sekunden Ruhe: LAEUFT=1, BEFUND=0,
 *      SPERRE=0, LAUF_S=0. Kein Trockenlauf, keine Sperre. Dieselben 180 W
 *      im Sekundentakt: BEFUND=3, SPERRE=1, LAUF_S=12.
 *   2. Ohne Anlieferung wird auch nichts VEROEFFENTLICHT. Faellt der
 *      Zwischenzaehler aus, friert Loxone auf laeuft=1, befund=0 ein - genau
 *      das, was der Kern mit seinem -1 verhindern will. Ein virtueller
 *      Eingang behaelt seinen letzten Wert, bei MQTT mit Retain sogar ueber
 *      jeden Neustart des Miniservers hinweg. Das ist keine fehlende
 *      Auskunft, sondern eine Falschaussage, und sie sieht aus wie eine
 *      richtige.
 *
 * Dieser Lauf schreibt den Zustand jede Minute fort und schickt das
 * Lebenszeichen. Er liefert KEINEN Messwert - er benutzt den letzten, solange
 * er nicht veraltet ist, und laesst den Zustand danach auf "unbekannt"
 * fallen. Anlieferungen aus Loxone werden dadurch nicht ersetzt, nur
 * ueberbrueckt.
 *
 * Aufruf: php bin/pw_takt.php [--einmal|--probe]
 *   ohne Argument  ein Durchlauf, Ausgabe nur ins Protokoll (fuer den Cron)
 *   --einmal       ein Durchlauf mit Ausgabe auf der Konsole
 *   --probe        rechnen und ausgeben, aber NICHTS senden und NICHTS
 *                  schreiben
 *
 * Der Cron gibt seine normale Ausgabe nach /dev/null, die FEHLERAUSGABE aber
 * in die Protokolldatei - sonst verschwindet die Meldung "Bibliothek nicht
 * gefunden" (REGELN_2).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben. Installiert liegen bin/ und webfrontend/html/ in
 * verschiedenen Baeumen. Findet sie sich nicht, wird das GESAGT und mit
 * Rueckgabewert 2 abgebrochen; ein Cron, der stumm stirbt, faellt niemandem
 * auf (REGELN_1, Abschnitt 3). */
$pw_home = getenv('LBHOMEDIR');
$pw_dir = getenv('LBPPLUGINDIR');
$pw_kandidaten = array();
if ($pw_home && $pw_dir) {
    $pw_kandidaten[] = $pw_home . '/webfrontend/html/plugins/' . $pw_dir . '/pw_lib.php';
}
if ($pw_home) {
    $pw_kandidaten[] = $pw_home . '/webfrontend/html/plugins/'
                     . basename(dirname(__FILE__)) . '/pw_lib.php';
}
/* Aus dem EIGENEN Ort abgeleitet, ohne jede Umgebungsvariable. Im
 * installierten Zustand liegt dieses Skript unter
 * <lbhome>/bin/plugins/<ordner>/, die Bibliothek unter
 * <lbhome>/webfrontend/html/plugins/<ordner>/ - also drei Ebenen hoch und
 * wieder hinunter.
 *
 * Bis 0.9.10 hingen zwei der drei Kandidaten an LBHOMEDIR, und der dritte
 * traf nur den ENTPACKTEN Archivbaum (dort liegen bin/ und webfrontend/
 * nebeneinander). Gemessen 31.08.2026 im nachgebauten Installationsaufbau
 * ohne LBHOMEDIR: Abbruch mit Rueckgabewert 2 -
 *
 *     pw_lib.php an keiner der erwarteten Stellen gefunden:
 *       .../inst/bin/plugins/webfrontend/html/pw_lib.php
 *
 * waehrend die Referenzlinie (aWATTar, bin/cron.php) unter denselben
 * Bedingungen OK meldete: sie traegt diesen Kandidaten. cron.01min setzt
 * die Variable nicht; preupgrade.sh und uninstall/uninstall setzen sie
 * ausdruecklich, der Autor kennt die Abhaengigkeit also.
 *
 * Ob LoxBerrys Cron LBHOMEDIR exportiert, ist hier nicht messbar. Der
 * fehlende Kandidat ist der Befund; die Wirkung haengt daran. */
$pw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
                 . basename(__DIR__) . '/pw_lib.php';
$pw_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/pw_lib.php';

$pw_lib = '';
foreach ($pw_kandidaten as $k) {
    if (is_file($k)) { $pw_lib = $k; break; }
}
if ($pw_lib === '') {
    fwrite(STDERR, "Pumpenwaechter: pw_lib.php an keiner der erwarteten Stellen gefunden:\n");
    foreach ($pw_kandidaten as $k) { fwrite(STDERR, '  ' . $k . "\n"); }
    exit(2);
}
require_once $pw_lib;

$pw_argumente = isset($argv) ? $argv : array();
$pw_laut  = in_array('--einmal', $pw_argumente, true);
$pw_probe = in_array('--probe', $pw_argumente, true);

function pw_sagen($text, $laut)
{
    if ($laut) { echo $text . "\n"; }
}

/* B9 (1.0.4): aus der Deinstallation - die retained Themen unter jedem
 * je benutzten Praefix direkt am Broker leeren (TCP, nachgelesen; UDP nur
 * als Rueckfall). VOR pw_config(): hier wird nichts geheilt und nichts
 * geschrieben. */
if (in_array('--mqtt-leeren', $pw_argumente, true)) {
    exit(pw_mqtt_leeren());
}
/* Die Praefixe aller Pumpen in die Liste der je benutzten aufnehmen -
 * die Deinstallation ruft das, BEVOR sie die Konfiguration entfernt. */
if (in_array('--mqtt-merken', $pw_argumente, true)) {
    $pw_mv = pw_config(false);
    $pw_mg = pw_inhalt_oder_null(pw_paths()['config']) !== null ? pw_eigene_praefixe($pw_mv) : array();
    foreach ($pw_mg as $pw_mp) { pw_mqtt_praefix_merken($pw_mp); }
    echo count($pw_mg) . " Praefix(e) vorgemerkt: " . implode(', ', $pw_mg) . "\n";
    exit(0);
}
/* C1/B2 (1.0.4): aus postupgrade.sh, nachdem der Zustand zurueckgespielt
 * ist - der Merker der zuletzt gesendeten Werte wird verworfen, damit der
 * erste Takt nach dem Update den VOLLEN Satz schickt (Regeln/07,
 * ACTiKamera 1.9.19: sonst fehlen die retained Zustaende im Broker). */
if (in_array('--nach-update', $pw_argumente, true)) {
    $pw_fh = pw_sperre_holen();
    if (!is_resource($pw_fh)) {
        echo "Der Zustand ist gesperrt oder nicht beschreibbar - der Vollversand kommt spaetestens nach 30 min.\n";
        exit(1);
    }
    $pw_sv = pw_stand_voll();
    /* Entscheidung 13: ein aus 1.0.3 uebernommenes stale_s=300 (die alte
     * Vorgabe) wird 180 (3 x Takt, Entscheidung 4) - nur genau 300, und nur
     * beim Update AUS 1.0.3. Erkannt am Zustand: ab 1.0.4 traegt jede
     * Pumpe mqtt_voll_ts, 1.0.3 nie. Mit Protokollzeile. */
    $pw_aus_alt = true;
    foreach ($pw_sv['pumpen'] as $pw_s) {
        if (is_array($pw_s) && array_key_exists('mqtt_voll_ts', $pw_s)) { $pw_aus_alt = false; }
    }
    if ($pw_aus_alt && pw_inhalt_oder_null(pw_paths()['config']) !== null) {
        $pw_sc = pw_config(false);
        $pw_um = array();
        foreach ($pw_sc['pumpen'] as $pw_i => $pw_pp) {
            if (isset($pw_pp['stale_s']) && pw_zahl($pw_pp['stale_s'], -1.0) === 300.0) {
                $pw_sc['pumpen'][$pw_i]['stale_s'] = 180;
                $pw_um[] = (string) $pw_pp['id'];
            }
        }
        if ($pw_um && pw_config_speichern($pw_sc)) {
            pw_log('Update: stale_s 300 (Vorgabe bis 1.0.3) auf 180 s gesetzt (3 x Minutentakt) fuer '
                   . implode(', ', $pw_um) . '.');
            echo 'stale_s 300 -> 180 fuer ' . implode(', ', $pw_um) . ".\n";
        } elseif ($pw_um) {
            echo "stale_s liess sich nicht auf 180 setzen - die Konfiguration ist nicht schreibbar.\n";
        }
    }
    $pw_n = 0;
    foreach ($pw_sv['pumpen'] as $pw_k => $pw_s) {
        if (!is_array($pw_s)) { continue; }
        unset($pw_sv['pumpen'][$pw_k]['mqtt_letzte'], $pw_sv['pumpen'][$pw_k]['mqtt_voll_ts'],
              $pw_sv['pumpen'][$pw_k]['mqtt_sig']);
        $pw_n++;
    }
    $pw_gut = ($pw_n === 0) || pw_stand_voll_speichern($pw_sv);
    pw_sperre_geben($pw_fh);
    echo $pw_gut ? $pw_n . " Pumpe(n): der naechste Takt schickt den vollen Satz.\n"
                 : "Der Zustand liess sich nicht schreiben.\n";
    exit($pw_gut ? 0 : 1);
}

$pw_jetzt = time();
/* Die VOLLE Konfiguration - der Takt bedient ALLE Pumpen. $pw_cfg bleibt
 * daneben die flache Sicht der ersten; alles Globale (das Aktionstoken)
 * steht darin unveraendert. */
$pw_voll = pw_config();
$pw_cfg = pw_pumpe($pw_voll);
$pw_ids = pw_pumpe_ids($pw_voll);

if ((string) $pw_cfg['aktionstoken'] === '') {
    /* Die Oberflaeche wurde noch nie geoeffnet. Nichts tun und nichts
     * behaupten - aber es einmal sagen, damit man den Grund findet. */
    pw_sagen('Noch kein Aktionstoken - die Oberflaeche wurde nie geoeffnet. Nichts zu tun.', true);
    exit(0);
}

if ($pw_probe) {
    /* Je Pumpe ein Block. Eine Probe, die nur die erste zeigt, laesst
     * genau die Pumpe aus, wegen der man nachsieht. */
    foreach ($pw_ids as $pw_id) {
        $pw_p = pw_pumpe($pw_voll, $pw_id);
        $stand = pw_stand($pw_id);
        $felder = pw_felder($stand, $pw_p, $pw_jetzt);
        $takt = pw_takt($stand);
        printf("\n== %s (%s) - nichts geschrieben, nichts gesendet ==\n",
               pw_pumpe_name($pw_p), $pw_id);
        foreach ($felder as $k => $v) { printf("  %-16s %s\n", $k, var_export($v, true)); }
        printf("  Anlieferungen: %d, mittlerer Abstand %d s, laengster %d s -> %s\n",
               $takt['anzahl'], $takt['mittlerer'], $takt['laengster'], $takt['urteil']);
    }
    exit(0);
}

/* JEDE Pumpe, eine nach der anderen.
 *
 * Eine Pumpe haelt die anderen NICHT auf: scheitert bei einer das Senden,
 * wird das gezaehlt und am Ende gemeldet, aber die Schleife laeuft weiter.
 * Ein Takt, der bei der ersten Pumpe abbricht, liesse die zweite dauerhaft
 * ohne Aufsicht - und zwar still. */
$pw_fehl_ges = 0;
$pw_versucht_ges = 0;
$pw_schreibfehler = array();
foreach ($pw_ids as $pw_id) {
    $pw_p = pw_pumpe($pw_voll, $pw_id);
    list($pw_neu, $pw_grund, $pw_versucht, $pw_fehl) =
        pw_verarbeiten(null, $pw_p, $pw_jetzt, 'takt');
    if ($pw_neu === null) {
        if ($pw_grund === 'belegt') {
            /* Kein Fehler: gerade schreibt eine Anlieferung. Der naechste
             * Takt kommt in einer Minute. */
            pw_sagen(sprintf('%s: uebersprungen - eine Anlieferung schreibt gerade.',
                             pw_pumpe_name($pw_p)), $pw_laut);
            continue;
        }
        /* C5 (1.0.4): 'datenordner' heisst: nicht beschreibbar. Das
         * Lebenszeichen mit status_ok=0 ist dann schon hinaus
         * (pw_verarbeiten); hier stehen Grund, Protokollzeile und rc 1. */
        $pw_schreibfehler[] = $pw_id;
        fwrite(STDERR, 'Pumpenwaechter: Zustand von ' . $pw_id
                       . ' konnte nicht geschrieben werden (' . $pw_grund . ").\n");
        pw_log('Takt: Zustand von ' . $pw_id . ' konnte nicht geschrieben werden (' . $pw_grund
               . ($pw_grund === 'datenordner' ? ' - status_ok=0 gesendet, ' . (int) $pw_versucht . ' Nachrichten' : '')
               . ').');
        continue;
    }

    /* Gerechnet, gesendet und geschrieben hat pw_verarbeiten() - unter
     * EINER Sperre und in EINEM Schreibvorgang. Hier steht nur die
     * Bilanz. */
    $pw_versucht_ges += $pw_versucht;
    $pw_fehl_ges += $pw_fehl;
    $pw_felder = pw_felder($pw_neu, $pw_p, $pw_jetzt);
    /* C6 (1.0.4): fehlt der UDP-Eingang, meldet pw_publizieren() das je
     * Wechsel einmal - hier nicht noch jede Minute. rc 1 bleibt. */
    if ($pw_fehl > 0 && empty($pw_neu['mqtt_port_fehlt'])) {
        /* Ein LOGOK setzt voraus, dass nichts gescheitert ist. Beides wird
         * getrennt genannt (REGELN_2, "Der Zaehler zaehlt Zustellungen"). */
        pw_log(sprintf('Takt %s: %d Themen versucht, %d gescheitert.',
                       $pw_id, $pw_versucht, $pw_fehl));
    }
    pw_sagen(sprintf('%s (%s): laeuft=%d befund=%d sperre=%d - %d Nachrichten an den UDP-Eingang abgeschickt, %d gescheitert.',
                     pw_pumpe_name($pw_p), $pw_id,
                     $pw_felder['laeuft'], $pw_felder['befund'], $pw_felder['sperre'],
                     $pw_versucht, $pw_fehl), $pw_laut);
}

/* c1 (Verbesserungsbau 30.09.2026): Alarm zusaetzlich ueber SignalBot, ab
 * Werk aus. ERST NACH dem MQTT-Weg aller Pumpen und ohne Einfluss auf den
 * Rueckgabewert: fehlt SignalBot oder schweigt er, geht nach Loxone genau
 * dasselbe hinaus wie ohne die Einstellung (pw_signal_takt()). Die Zeile im
 * Reiter Test und das Protokoll sagen es. */
list($pw_sg_n, $pw_sg_f) = pw_signal_takt($pw_voll, $pw_jetzt);
if ($pw_sg_n > 0) {
    pw_sagen(sprintf('SignalBot: %d Meldung(en) abgegeben, %d davon gescheitert.', $pw_sg_n, $pw_sg_f), $pw_laut);
}

if (count($pw_ids) > 1) {
    pw_sagen(sprintf('Takt ueber %d Pumpen: %d Nachrichten abgeschickt, %d gescheitert.',
                     count($pw_ids), $pw_versucht_ges, $pw_fehl_ges), $pw_laut);
}
exit(($pw_fehl_ges > 0 || $pw_schreibfehler) ? 1 : 0);
