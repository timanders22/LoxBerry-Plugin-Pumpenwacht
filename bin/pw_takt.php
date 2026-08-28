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

$pw_jetzt = time();
$pw_cfg = pw_config();

if ((string) $pw_cfg['aktionstoken'] === '') {
    /* Die Oberflaeche wurde noch nie geoeffnet. Nichts tun und nichts
     * behaupten - aber es einmal sagen, damit man den Grund findet. */
    pw_sagen('Noch kein Aktionstoken - die Oberflaeche wurde nie geoeffnet. Nichts zu tun.', true);
    exit(0);
}

if ($pw_probe) {
    $stand = pw_stand();
    $felder = pw_felder($stand, $pw_cfg, $pw_jetzt);
    $takt = pw_takt($stand);
    echo "Zustand (nichts geschrieben, nichts gesendet):\n";
    foreach ($felder as $k => $v) { printf("  %-16s %s\n", $k, var_export($v, true)); }
    printf("\nAnlieferungen: %d, mittlerer Abstand %d s, laengster %d s -> %s\n",
           $takt['anzahl'], $takt['mittlerer'], $takt['laengster'], $takt['urteil']);
    exit(0);
}

list($pw_neu, $pw_grund, $pw_versucht, $pw_fehl) =
    pw_verarbeiten(null, $pw_cfg, $pw_jetzt, 'takt');
if ($pw_neu === null) {
    if ($pw_grund === 'belegt') {
        /* Kein Fehler: gerade schreibt eine Anlieferung. Der naechste Takt
         * kommt in einer Minute. */
        pw_sagen('Uebersprungen - eine Anlieferung schreibt gerade.', $pw_laut);
        exit(0);
    }
    fwrite(STDERR, "Pumpenwaechter: Zustand konnte nicht geschrieben werden.\n");
    pw_log('Takt: Zustand konnte nicht geschrieben werden.');
    exit(1);
}

/* Gerechnet, gesendet und geschrieben hat pw_verarbeiten() - unter EINER
 * Sperre und in EINEM Schreibvorgang. Hier steht nur noch die Bilanz. */
$pw_felder = pw_felder($pw_neu, $pw_cfg, $pw_jetzt);
if ($pw_fehl > 0) {
    /* Ein LOGOK setzt voraus, dass nichts gescheitert ist. Beides wird
     * getrennt genannt (REGELN_2, "Der Zaehler zaehlt Zustellungen"). */
    pw_log(sprintf('Takt: %d Themen versucht, %d gescheitert.', $pw_versucht, $pw_fehl));
}
pw_sagen(sprintf('Takt: laeuft=%d befund=%d sperre=%d - %d Themen gesendet, %d gescheitert.',
                 $pw_felder['laeuft'], $pw_felder['befund'], $pw_felder['sperre'],
                 $pw_versucht, $pw_fehl), $pw_laut);
exit($pw_fehl > 0 ? 1 : 0);
