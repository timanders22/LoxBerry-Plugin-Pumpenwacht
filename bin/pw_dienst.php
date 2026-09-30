<?php
/**
 * Pumpenwaechter - der MQTT-Zuhoerer (Weg B)
 *
 * WARUM ES IHN GIBT
 *
 * Am 28.08.2026 hat sich am Geraet gezeigt, dass der Zwischenzaehler ohnehin
 * schon am Broker haengt. Der Watt-Wert lief bis dahin einmal durch den
 * Miniserver hindurch, nur um zurueckzukommen:
 *
 *     Shelly -> Broker -> Gateway -> Miniserver -> Virtueller Ausgang
 *            -> HTTP -> Plugin -> Gateway -> Miniserver
 *
 * Dieser Dienst nimmt den Umweg heraus. Er laeuft nur, wenn die Quelle in den
 * Einstellungen auf "MQTT" steht; ab Werk steht sie auf "Loxone", und dann
 * beendet er sich sofort wieder.
 *
 * WARUM EIN DAUERLAEUFER UND KEIN ABRUF IM MINUTENTAKT
 *
 * Die Meldungen des Shelly sind NICHT aufbewahrt - gemessen: ein
 * 30-Sekunden-Lauf ohne Pumpenlauf brachte nur das aufbewahrte "online". Ein
 * "mosquitto_sub -C 1" im Cron muesste deshalb bis zu 65 Sekunden auf die
 * naechste Meldung warten und den Takt blockieren. Ein mitlaufender Zuhoerer
 * bekommt jede Meldung, sobald sie kommt.
 *
 * WAS GEMESSEN IST, UND WAS NICHT
 *
 * Gemessen (28.08.2026, zwei Laeufe ueber 30 und 120 Sekunden): der Shelly
 * meldet JEDE VOLLE MINUTE von selbst, auch wenn sich nichts aendert. Die
 * Zeitstempel 1787950200, 1787950560 und 1787950620 liegen exakt 60 Sekunden
 * auseinander. Je Minute kommen ZWEI Meldungen - eine mit Zaehlern, eine mit
 * apower. Nur die zweite traegt einen Messwert.
 *
 * NICHT gemessen: ob der Shelly beim Anlaufen der Pumpe SOFORT meldet oder
 * erst zur naechsten vollen Minute. Bei beiden Messungen stand die Pumpe
 * (apower 0.0, on_above_thr unveraendert 510). Der Dienst haengt nicht davon
 * ab - er nimmt, was kommt, wann es kommt. Die Frage entscheidet nur, ob ein
 * Trockenlauf in Sekunden oder in bis zu einer Minute auffaellt.
 *
 * Aufruf:
 *   php bin/pw_dienst.php            im Vordergrund laufen (der Cron haengt
 *                                    ein & an)
 *   php bin/pw_dienst.php --probe    einmal horchen und zeigen, was kommt -
 *                                    ohne zu schreiben und ohne zu senden
 *   php bin/pw_dienst.php stop       den laufenden Dienst beenden
 *   php bin/pw_dienst.php status     laeuft er? Rueckgabewert 0 = ja
 *   php bin/pw_dienst.php --selbsttest
 *                                    die Zeilenverarbeitung gegen die
 *                                    echten gemessenen Shelly-Zeilen
 *                                    fahren - ohne Broker, ohne dass die
 *                                    Pumpe laufen muss, ohne zu schreiben
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben. Findet sie sich nicht, wird das GESAGT und mit
 * Rueckgabewert 2 abgebrochen; ein Dienst, der stumm stirbt, faellt niemandem
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

/* Die LoxBerry-Bibliothek dazu, WENN es sie gibt. Sie wird nicht
 * gebraucht, um zu horchen - aber ohne sie kann pw_fassung() die
 * installierte Fassung nicht erfragen, und dann traegt die Kopfzeile des
 * Selbsttests keine Nummer. Am 29.08.2026 stand am Geraet
 * 'Pumpenwaechter - Selbstpruefung' ohne Fassung; wer eine solche Ausgabe
 * geschickt bekommt, muss raten, welcher Stand sie erzeugt hat.
 *
 * AUF OBERSTER EBENE, nicht in pw_fassung(): ein require innerhalb einer
 * Funktion macht die globalen Variablen der Bibliothek zu lokalen.
 *
 * Fehlt sie, wird NICHT abgebrochen - der Zuhoerer laeuft auch ohne sie,
 * und die Nummer bleibt dann eben leer. Fail closed gilt fuer Messwerte
 * und Sperren, nicht fuer eine Zeile Beschriftung.
 * Hausform: bin/apc_notify.php und fuenf weitere. */
$pw_sdk = $pw_home ? $pw_home . '/libs/phplib/loxberry_system.php' : '';
if ($pw_sdk !== '' && is_file($pw_sdk)) { require_once $pw_sdk; }

$pw_argumente = isset($argv) ? $argv : array();
$pw_hat = function ($x) use ($pw_argumente) { return in_array($x, $pw_argumente, true); };
$pw_probe = $pw_hat('--probe');

$pw_p = pw_paths();
$pw_pidfile = $pw_p['datadir'] . '/dienst.pid';
$pw_tsfile  = $pw_p['datadir'] . '/dienst.ts';
$pw_lockfile = $pw_p['datadir'] . '/dienst.lock';

/* ==================================================================
 * Selbstpruefung des Zuhoerers - ohne Broker, ohne Geraet
 *
 * Sie faehrt die ECHTEN Zeilen, die am 28.08.2026 aus mosquitto_sub kamen,
 * durch dieselbe Funktion, die im Betrieb jede Zeile bekommt. Was hier
 * gruen ist, ist an der Zeilenverarbeitung gemessen - nicht behauptet.
 *
 * Was sie NICHT beweist: dass der Broker erreichbar ist, dass die
 * Zugangsdaten stimmen, dass der Shelly ueberhaupt sendet. Das steht am
 * Ende ausdruecklich da. Eine Pruefung, die ihre eigenen Grenzen
 * verschweigt, liest sich wie eine Freigabe.
 * ================================================================== */

function pw_st_zeilen()
{
    /* Zeichengetreu aus den beiden mosquitto_sub-Laeufen vom 28.08.2026.
     * Nicht nachgebaut, nicht gekuerzt - abgeschrieben. */
    $r = array();
    $r['online'] = 'PLATZ/online true';
    $r['counts'] = 'PLATZ/events/rpc {"src":"shelly1pmg4-7c2c67667790",'
        . '"dst":"PLATZ/events","method":"NotifyStatus",'
        . '"params":{"ts":1787950560.00,"switch:0":{"counts":'
        . '{"on_above_thr":510,"on_time":1912800,"switch_on":0}}}}';
    $r['apower'] = 'PLATZ/events/rpc {"src":"shelly1pmg4-7c2c67667790",'
        . '"dst":"PLATZ/events","method":"NotifyStatus",'
        . '"params":{"ts":1787950560.02,"switch:0":{"aenergy":{"by_minute":'
        . '[0.000,0.000,0.000],"minute_ts":1787950560,"total":1885.832},'
        . '"apower":0.0,"current":0.000,"freq":50.03,"ret_aenergy":'
        . '{"by_minute":[0.000,0.000,0.000],"minute_ts":1787950560,'
        . '"total":0.000},"voltage":232.0}}}';
    return $r;
}

function pw_selbsttest_dienst($cfg)
{
    $zeilen = array();
    $fehler = 0;

    $ok = function ($bed, $text) use (&$zeilen, &$fehler) {
        $zeilen[] = ($bed ? '[OK]   ' : '[FEHL] ') . $text;
        if (!$bed) { $fehler++; }
    };

    /* Ohne Fassungsnummer (weder LoxBerry-Bibliothek noch plugin.cfg) stand
     * hier 'Pumpenwaechter  - Selbstpruefung' mit zwei Leerzeichen. */
    $fa = pw_fassung();
    $zeilen[] = 'Pumpenwaechter' . ($fa === '' ? '' : ' ' . $fa)
              . ' - Selbstpruefung des MQTT-Zuhoerers';
    $zeilen[] = str_repeat('-', 68);

    /* ---- Zeilen ueber DIESE ANLAGE. Sie zaehlen nicht in die
     * Schlusszeile: "Quelle steht auf Loxone" ist auf einer Anlage ohne
     * MQTT die richtige Antwort und darf kein Freigabetor schliessen. */
    $quelle = (string) $cfg['quelle'];
    $zeilen[] = '[INFO] Messwertquelle: ' . $quelle
              . ($quelle === 'mqtt' ? ' - der Zuhoerer wird gebraucht'
                                    : ' - der Zuhoerer laeuft absichtlich nicht');
    $thema = trim((string) $cfg['quelle_topic']);
    if ($quelle === 'mqtt') {
        $ok($thema !== '', 'Quell-Thema eingetragen: '
            . ($thema !== '' ? $thema : 'NEIN - ohne Thema horcht niemand'));
        $hat = pw_hat_mosquitto();
        $ok($hat, 'mosquitto_sub vorhanden'
            . ($hat ? '' : ' - NEIN. sudo apt install mosquitto-clients'));
        $alter = pw_dienst_alter();
        $zeilen[] = ($alter >= 0 && $alter < 180 ? '[OK]   ' : '[WARN] ')
                  . 'Lebenszeichen des Zuhoerers: '
                  . ($alter < 0 ? 'keines - er laeuft nicht'
                                : $alter . ' s alt (der Cron holt ihn im Minutentakt zurueck)');
    } else {
        $zeilen[] = '[INFO] Quell-Thema, mosquitto_sub und Lebenszeichen werden'
                  . ' nicht geprueft - sie gelten nur fuer den MQTT-Weg.';
    }
    $b = pw_broker();
    $zeilen[] = '[INFO] Broker aus der Systemkonfiguration: '
              . ($b['host'] === '' ? 'kein Brokerhost eingetragen'
                 : $b['host'] . ':' . $b['port']
                   . ($b['user'] === '' ? ' (ohne Benutzer)'
                      : ' als ' . $b['user'] . ' (das Passwort steht NICHT hier'
                        . ' und nicht in der Sicherungsdatei)'));

    /* ---- Ab hier der Rechenkern. Diese Zeilen zaehlen. ---- */
    $kernAb = count($zeilen);
    $zeilen[] = str_repeat('-', 68);
    $zeilen[] = '[OK]   PHP ' . PHP_VERSION;
    $ok(extension_loaded('json'), 'PHP-Erweiterung json geladen');

    /* Die echten Zeilen auf DAS eingetragene Thema umschreiben. Steht
     * keines da (Loxone-Weg), wird ein Platzhalter genommen: gemessen wird
     * die Zeilenverarbeitung, nicht die Einrichtung. */
    $basis = $thema !== '' ? rtrim(rtrim($thema, '#'), '/') : 'pruefthema';
    $z = array();
    foreach (pw_st_zeilen() as $k => $v) { $z[$k] = str_replace('PLATZ', $basis, $v); }

    /* $schreiben = false: die Selbstpruefung rechnet, sie greift nicht ein. */
    $f = function ($zeile) use ($cfg) {
        return pw_zeile_verarbeiten($zeile, $cfg, 1787950560, false);
    };

    $e = $f($z['online']);
    $ok($e['art'] === 'anwesenheit',
        'die aufbewahrte Anmeldung wird als Anwesenheit erkannt (gemessen '
        . $e['art'] . ')');

    $e = $f($z['counts']);
    $ok($e['art'] === 'nichts',
        'die Zaehlermeldung traegt keinen Messwert und wird uebergangen (gemessen '
        . $e['art'] . ') - das ist der Normalfall, kein Fehler: von zwei'
        . ' Meldungen je Minute traegt nur eine apower');

    $e = $f($z['apower']);
    $ok($e['art'] === 'messwert',
        'die apower-Meldung wird als Messwert erkannt (gemessen ' . $e['art'] . ')');
    $ok($e['watt'] === 0.0, 'sie liest apower 0.0 als 0 W (gemessen '
        . var_export($e['watt'], true) . ')');
    $ok(isset($e['neben']['volt']) && $e['neben']['volt'] === 232.0,
        'die Spannung 232.0 V kommt mit');
    $ok(isset($e['neben']['hertz']) && $e['neben']['hertz'] === 50.03,
        'die Frequenz 50.03 Hz kommt mit');

    $lauf = str_replace(array('"apower":0.0', '"current":0.000'),
                        array('"apower":180.0', '"current":0.780'), $z['apower']);
    $e = $f($lauf);
    $ok($e['watt'] === 180.0,
        'dieselbe Form mit 180 W wird als 180 W gelesen (gemessen '
        . var_export($e['watt'], true) . ')');
    $ok(isset($e['neben']['ampere']) && $e['neben']['ampere'] === 0.78,
        'der Strom 0.78 A kommt mit');

    /* Der Bauteilname ist NICHT festgeschrieben. Gemessen wurde nur ein
     * Shelly 1PM Gen4 mit "switch:0"; ein 1PM-Mini meldet "pm1:0", ein
     * Energiemesser "em:0". Gesucht wird deshalb das erste Unterobjekt mit
     * einem numerischen apower, nicht ein bestimmter Name. */
    foreach (array('pm1:0', 'em:0', 'switch:1') as $bauteil) {
        $e = $f(str_replace('"switch:0"', '"' . $bauteil . '"', $z['apower']));
        $ok($e['art'] === 'messwert',
            'der Bauteilname ist gleichgueltig: auch "' . $bauteil
            . '" liefert einen Messwert');
    }

    $mist = array(
        'eine leere Zeile' => '',
        'ein Thema ohne Nutzlast' => $basis . '/x',
        'kaputtes JSON' => $basis . '/events/rpc {kaputt',
        'eine Meldung ohne params' => $basis . '/events/rpc {"a":1}',
        'apower als Text statt Zahl' =>
            str_replace('"apower":0.0', '"apower":"viel"', $z['apower']),
    );
    foreach ($mist as $name => $roh) {
        $e = $f($roh);
        $ok($e['art'] === 'nichts', $name . ' aendert nichts (gemessen ' . $e['art'] . ')');
    }

    /* Das Muster fuer das Quell-Thema durch DIESELBE Pruefung schicken, die
     * auch das Formular und die Sicherung benutzen. Am 28.08.2026 stand die
     * Raute dort als Trennzeichen UND in der Zeichenklasse; preg_match gab
     * false zurueck, jedes Thema galt als unzulaessig, und die Sicherung
     * liess sich nicht mehr zurueckspielen. */
    list($w1, $grund1) = pw_wert_pruefen('quelle_topic', 'shelly1pmg4-Pumpensumpf/#');
    $ok($grund1 === '', 'ein Thema mit Raute am Ende wird angenommen'
        . ($grund1 === '' ? '' : ' - NEIN: ' . $grund1));
    list($w2, $grund2) = pw_wert_pruefen('quelle_topic', "a\nb");
    $ok($grund2 !== '', 'ein Thema mit Zeilenumbruch wird abgelehnt');

    /* Die Optionsdatei traegt das Broker-Passwort. Sie darf niemandem sonst
     * gehoeren - und das Passwort darf NIE auf der Kommandozeile stehen, wo
     * /proc/<pid>/cmdline es jedem Benutzer des Geraets zeigt.
     *
     * NUR ANSEHEN, nicht anlegen: pw_broker_optionsdatei() wuerde Ordner
     * und Datei erzeugen und dabei das Passwort schreiben - auch auf einer
     * Anlage, die ueber Loxone laeuft. Deshalb pw_broker_optionspfad(). */
    $ordner = pw_broker_optionspfad();
    $datei = $ordner . '/mosquitto_sub';

    /* Traegt dieses Dateisystem ueberhaupt POSIX-Rechte? Auf NTFS meldet
     * PHP fuer jede Datei 0666 und fuer jeden Ordner 0777, und chmod
     * bleibt wirkungslos. Die Rechtepruefung waere dort IMMER rot - aus
     * einem Grund, der nichts mit dem Plugin zu tun hat. Ein Tor, das
     * immer rot ist, wird nach dem dritten Mal ueberlesen. Also erst
     * messen, ob gemessen werden kann. */
    $probe = pw_paths();
    $pf = $probe['datadir'] . '/.rechteprobe';
    $rechte_zaehlen = false;
    if (@file_put_contents($pf, "x") !== false) {
        @chmod($pf, 0600);
        clearstatcache(true, $pf);
        $rechte_zaehlen = ((fileperms($pf) & 0777) === 0600);
        @unlink($pf);
    }

    /* Reihenfolge: erst die LAGE, dann die Messbarkeit, dann die Messung.
     * Umgekehrt verschluckte der Messbarkeitszweig auf einem Dateisystem
     * ohne POSIX-Rechte die Auskunft ueber die Ordnerlage - man erfuhr,
     * dass nicht gemessen werden kann, aber nicht, ob es ueberhaupt etwas
     * zu messen gibt. */
    if (!is_dir($ordner)) {
        /* WELCHER der beiden Faelle vorliegt, ist bekannt - also wird er
         * genannt. Bis zum 29.08.2026 stand hier ein 'oder', und an einer
         * Anlage mit angemeldetem Broker war die erste Haelfte davon
         * nachweislich unwahr. Ein Satz, der beide Moeglichkeiten offen
         * laesst, obwohl eine davon ausgeschlossen ist, ist keine
         * Vorsicht - er ist ungenau. */
        $zeilen[] = '[INFO] Keine Optionsdatei angelegt. '
                  . (pw_optionswert($b['user']) === ''
                     ? 'Der Broker verlangt keine Anmeldung - dann braucht'
                       . ' der Zuhoerer auch keine.'
                     : 'Der Broker verlangt eine Anmeldung (Benutzer '
                       . $b['user'] . '); die Datei entsteht beim ersten'
                       . ' Start des Zuhoerers, mit Rechten 0600.');
    } elseif (!$rechte_zaehlen) {
        $zeilen[] = '[INFO] Die Zugangsdaten liegen in ' . $ordner . ', aber ihre'
                  . ' Rechte sind hier nicht messbar: dieses Dateisystem traegt'
                  . ' keine POSIX-Rechte (chmod 0600 bleibt wirkungslos). Auf dem'
                  . ' LoxBerry sind sie es. Das ist kein Haken - es ist ein Strich.';
    } else {
        $r1 = is_file($datei) ? (fileperms($datei) & 0777) : -1;
        $r2 = fileperms($ordner) & 0777;
        if ($r1 < 0) {
            $zeilen[] = '[INFO] Der Ordner fuer die Zugangsdaten steht, die Datei'
                      . ' noch nicht - der Broker verlangt keine Anmeldung.';
        } else {
            $ok($r1 === 0600, 'die Optionsdatei mit dem Broker-Passwort hat 0'
                . decoct($r1) . ' (erwartet 0600 - sonst liest sie jeder Benutzer des Geraets)');
        }
        $ok($r2 === 0700, 'ihr Ordner hat 0' . decoct($r2) . ' (erwartet 0700)');
    }

    $zeilen[] = '';
    $zeilen[] = 'Was hier NICHT geprueft ist:';
    $zeilen[] = '  - ob der Broker erreichbar ist und die Zugangsdaten stimmen';
    $zeilen[] = '    (dafuer: php bin/pw_dienst.php --probe)';
    $zeilen[] = '  - ob der Zwischenzaehler ueberhaupt sendet und unter welchem Thema';
    $zeilen[] = '  - ob der Shelly beim Anlaufen der Pumpe SOFORT meldet oder erst zur';
    $zeilen[] = '    naechsten vollen Minute - bei beiden Messungen stand die Pumpe';
    $zeilen[] = '  - ob die Schwellen zu DIESER Pumpe passen (dafuer die Selbstpruefung';
    $zeilen[] = '    auf der Seite "Pruefstand")';

    /* Die Schlusszeile - das erste, was ein Mensch liest, und das einzige,
     * was Werkzeuge/freigabe_pruefen.py auswerten kann.
     *
     * Gezaehlt wird AUS DEN AUSGEGEBENEN ZEILEN, nicht aus einem mitlaufenden
     * Zaehler. Sonst koennen Zahl und Ausgabe auseinanderlaufen - und eine
     * Zusammenfassung, die besser aussieht als ihre schlechteste Zeile, ist
     * die teuerste Fehlerart dieser Sammlung. */
    $anzahl = 0; $fehl = 0; $lage = 0; $warn = 0;
    foreach ($zeilen as $i => $zz) {
        if (!preg_match('/^\[(OK|FEHL|WARN)\s*\]/', $zz, $mm)) { continue; }
        if ($mm[1] === 'WARN') { $warn++; continue; }
        if ($i < $kernAb) {
            if ($mm[1] === 'FEHL') { $lage++; }
            continue;
        }
        $anzahl++;
        if ($mm[1] === 'FEHL') { $fehl++; }
    }
    $kopf = sprintf('Rechenkern: %d Faelle geprueft, %d Fehlschlaege.', $anzahl, $fehl);
    if ($lage > 0) {
        $kopf .= sprintf(' Dazu %d Beanstandung(en) zu DIESER Anlage - die stehen'
                       . ' unten und sind kein Urteil ueber das Plugin.', $lage);
    }
    if ($warn > 0) {
        $kopf .= sprintf(' Und %d Vorbehalt(e), gezeichnet mit [WARN].', $warn);
    }
    if (($fehl + $lage) !== $fehler) {
        $kopf .= sprintf(' ACHTUNG: der Rueckgabewert-Zaehler meldet %d Fehlschlaege,'
                       . ' gezaehlt wurden %d - eine der beiden Zahlen stimmt nicht.',
                         $fehler, $fehl + $lage);
    }
    array_unshift($zeilen, $kopf, '');
    echo implode("\n", $zeilen) . "\n";
    return $fehler ? 1 : 0;
}

/* ---------------- stop / status ---------------- */

if ($pw_hat('stop')) {
    /* ALLE eigenen Zuhoerer, nicht einer - und seit 1.0.4 auch ihre Waisen.
     *
     * Bis 1.0.2 wurde genau die eine Nummer aus der PID-Datei beendet (Faelle
     * fremd_preupgrade und zwei, 18.09.2026). pw_dienst_pids() findet beide
     * Lagen argumentweise, pw_signal() prueft vor JEDEM Signal selbst.
     *
     * C4 (1.0.4): bis 1.0.3 lebte mosquitto_sub nach "Der Zuhoerer wurde
     * beendet" weiter, hielt die Dateisperre, und kein Neustart kam mehr
     * durch (Pruefbericht code C4). Das Kind ist seither mosquitto_sub
     * selbst (keine Schale dazwischen), proc_terminate() trifft es, und was
     * danach noch mit der Marke des Zuhoerers dasteht, beendet
     * pw_waisen_beenden() - an Marke und Besitzer erkannt, nie am Namen. */
    $pids = pw_dienst_pids();
    if (!$pids) {
        @unlink($pw_pidfile);
        list($pw_w_weg, $pw_w_rest) = pw_waisen_beenden();
        if ($pw_w_rest) {
            fwrite(STDERR, "Der Zuhoerer laeuft nicht, aber seine Waise liess sich nicht beenden (PID "
                   . implode(', ', $pw_w_rest) . ").\n");
            exit(1);
        }
        echo $pw_w_weg
            ? "Der Zuhoerer lief nicht; seine Waise wurde beendet (PID " . implode(', ', $pw_w_weg) . ").\n"
            : "Der Zuhoerer laeuft nicht.\n";
        exit(0);
    }
    foreach ($pids as $pw_z) { pw_signal($pw_z, 15); }
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if (!pw_dienst_pids()) { break; }
    }
    /* Hart beendet wird NUR, was jetzt noch als eigener Zuhoerer dasteht -
     * neu gesucht, nicht angenommen. */
    $pw_hart = false;
    $rest = pw_dienst_pids();
    if ($rest) {
        foreach ($rest as $pw_z) { pw_signal($pw_z, 9); }
        usleep(300000);
        $pw_hart = true;
    }
    $noch = pw_dienst_pids();
    @unlink($pw_pidfile);
    list($pw_w_weg, $pw_w_rest) = pw_waisen_beenden();
    if ($noch || $pw_w_rest) {
        fwrite(STDERR, "Der Zuhoerer liess sich nicht beenden (PID "
               . implode(', ', array_merge($noch, $pw_w_rest)) . ").\n");
        exit(1);
    }
    echo "Der Zuhoerer wurde " . ($pw_hart ? 'hart ' : '') . "beendet (PID " . implode(', ', $pids) . ")"
       . ($pw_w_weg ? ", dazu seine Waise (PID " . implode(', ', $pw_w_weg) . ")" : '') . ".\n";
    exit(0);
}

if ($pw_hat('status')) {
    /* Diese Zeile ist der Waechter im Minutentakt: gibt sie 0 zurueck,
     * startet cron.01min keinen neuen Zuhoerer. Beide Richtungen zaehlen
     * (18.09.2026, Fall status). Seit 1.0.4 (C4) nennt sie auch eine Waise -
     * ein mosquitto_sub ohne Zuhoerer ist KEIN laufender Zuhoerer: rc 1, der
     * Minutentakt startet neu, und der neue Zuhoerer beendet die Waise. */
    $pids = pw_dienst_pids();
    $pid = pw_dienst_pid();
    $alter = pw_dienst_alter();
    if ($pid > 0) {
        printf("laeuft, PID %d%s, letztes Lebenszeichen vor %s\n",
               $pid,
               count($pids) > 1 ? ' (und ' . (count($pids) - 1) . ' weitere)' : '',
               $alter >= 0 ? $alter . ' s' : 'unbekannt');
        exit(0);
    }
    $pw_w = pw_zuhoerer_waisen();
    if ($pw_w) {
        echo "laeuft nicht - es steht noch eine Waise (mosquitto_sub ohne Zuhoerer, PID "
           . implode(', ', $pw_w) . "); der naechste Start beendet sie\n";
        exit(1);
    }
    echo "laeuft nicht\n";
    exit(1);
}

/* ---------------- Voraussetzungen ---------------- */

$pw_cfg = pw_pumpe(pw_config());

/* Die Selbstpruefung steht VOR der Quellenpruefung: auf einer Anlage, die
 * noch ueber Loxone laeuft, will man vor dem Umstellen wissen, ob der Weg
 * traegt. Sie schreibt nichts und sendet nichts. */
if ($pw_hat('--selbsttest')) {
    exit(pw_selbsttest_dienst($pw_cfg));
}

/* Die VOLLE Konfiguration - der Zuhoerer bedient alle Pumpen und muss
 * deshalb wissen, welche es gibt. $pw_cfg bleibt daneben die flache Sicht
 * der ersten Pumpe; alles, was global ist (Broker, Geheimnisse), steht
 * darin unveraendert. */
$pw_voll = pw_config();

/* Alle Quell-Themen der Pumpen, die ueber MQTT lesen. */
$pw_themen = array();
$pw_ohne_thema = array();
foreach (pw_pumpe_ids($pw_voll) as $pw_pid_) {
    $pw_p_ = pw_pumpe($pw_voll, $pw_pid_);
    if ((string) $pw_p_['quelle'] !== 'mqtt') { continue; }
    $pw_th_ = trim((string) $pw_p_['quelle_topic']);
    if ($pw_th_ === '') { $pw_ohne_thema[] = $pw_pid_; continue; }
    $pw_themen[] = $pw_th_;
}

if (!$pw_themen && !$pw_probe) {
    /* Kein Fehler: keine Pumpe liest ueber MQTT, der Dienst wird nicht
     * gebraucht. Er sagt es einmal und geht - und nennt die Zahl, damit
     * "keine" nicht mit "eine, die schweigt" verwechselt wird. */
    printf("Keine der %d Pumpen liest ueber MQTT - der Zuhoerer wird nicht gebraucht.\n",
           count(pw_pumpe_ids($pw_voll)));
    exit(0);
}
if ($pw_ohne_thema) {
    /* Eine Pumpe auf MQTT ohne Thema ist ein Einrichtungsfehler und wird
     * GENANNT, nicht uebergangen - sonst sucht jemand lange, warum genau
     * eine von zwei Pumpen keine Werte bekommt. */
    fwrite(STDERR, "Ohne Quell-Thema, wird nicht abgehorcht: "
                   . implode(', ', $pw_ohne_thema) . "\n");
    pw_log('Zuhoerer: ohne Quell-Thema - ' . implode(', ', $pw_ohne_thema));
}
if (!$pw_themen) {
    fwrite(STDERR, "Es ist kein Quell-Thema eingetragen (Reiter Einstellungen).\n");
    pw_log('Zuhoerer: kein Quell-Thema eingetragen - nichts zu horchen.');
    exit(2);
}

if (!pw_hat_mosquitto()) {
    fwrite(STDERR, "mosquitto_sub wurde nicht gefunden. Paket: mosquitto-clients\n");
    pw_log('Zuhoerer: mosquitto_sub fehlt (Paket mosquitto-clients).');
    exit(2);
}

/* Nur EIN Zuhoerer. Die Sperre wird gehalten, solange der Prozess lebt -
 * stirbt er, gibt das Betriebssystem sie frei, und der Waechter darf sofort
 * neu starten. Eine PID-Datei allein waere eine Behauptung. */
@mkdir($pw_p['datadir'], 0775, true);
/* C4 (1.0.4): die Sperre wird mit close-on-exec geoeffnet ('e'). Bis
 * 1.0.3 erbten die Kinder (die Schale und mosquitto_sub) den Deskriptor;
 * starb der Zuhoerer, hielt die Waise die Sperre, und jeder Neustart
 * endete mit "Es laeuft bereits ein Zuhoerer." und rc 0 (Pruefbericht
 * code C4, Regeln/03 "Sperre vererbt sich an Kinder"). Wo 'e' nicht
 * geht, bleibt es beim alten Oeffnen; das Kind ist dann trotzdem
 * mosquitto_sub selbst und endet mit dem Zuhoerer. */
$pw_lock = @fopen($pw_lockfile, 'ce');
if (!$pw_lock) { $pw_lock = @fopen($pw_lockfile, 'c'); }
if (!$pw_lock) {
    fwrite(STDERR, "Sperrdatei nicht anlegbar: " . $pw_lockfile . "\n");
    exit(1);
}
if (!$pw_probe && !@flock($pw_lock, LOCK_EX | LOCK_NB)) {
    /* Auf die FEHLERAUSGABE und mit rc 1: der Minutentakt schreibt einen
     * gescheiterten Start nach cron.err (C4). */
    fwrite(STDERR, "Es laeuft bereits ein Zuhoerer (die Sperre " . $pw_lockfile . " ist belegt).\n");
    exit(1);
}
if (!$pw_probe) {
    /* Eine Waise eines frueheren Zuhoerers beenden, bevor ein neuer
     * mosquitto_sub angemeldet wird - sonst stuenden zwei Clients am
     * Broker (C4). */
    list($pw_w_weg, $pw_w_rest) = pw_waisen_beenden();
    if ($pw_w_weg) {
        pw_log('Zuhoerer: Waise eines frueheren Zuhoerers beendet (PID ' . implode(', ', $pw_w_weg)
               . ($pw_w_rest ? '; nicht zu beenden: ' . implode(', ', $pw_w_rest) : '') . ').');
    }
}

/* ---------------- den Zuhoerer starten ---------------- */

$pw_b = pw_broker();
$pw_ordner = pw_broker_optionsdatei(true);

/* DIE ZUGANGSDATEN STEHEN NICHT AUF DER KOMMANDOZEILE.
 * /proc/<pid>/cmdline hat die Rechte 444, und dieser Prozess laeuft dauernd -
 * jeder lokale Benutzer koennte mitlesen. mosquitto_sub liest sie aus
 * $XDG_CONFIG_HOME/mosquitto_sub; auf der Zeile steht nur der Pfad. */
/* EIN Prozess, mehrere -t. Am Geraet gemessen (08.09.2026): mosquitto_sub
 * nimmt beliebig viele Themen entgegen und schreibt sie in denselben Strom.
 * Ein Prozess je Pumpe waere die schlechtere Wahl - jeder braucht seine
 * Schale, und proc_terminate() trifft die Schale, nicht mosquitto_sub. */
$pw_argv = array('mosquitto_sub',
                 '-h', $pw_b['host'],
                 '-p', (string) $pw_b['port'],
                 '-v', '-q', '1');
foreach ($pw_themen as $pw_th) { $pw_argv[] = '-t'; $pw_argv[] = $pw_th; }
/* C4 (1.0.4): OHNE Schale - die Befehlsliste geht unmittelbar an exec
 * (PHP ab 7.4), die Zugangsdaten-Umgebung ueber den env-Parameter. Bis
 * 1.0.3 lief "sh -c 'XDG_CONFIG_HOME=... mosquitto_sub ...'"; dash
 * ersetzt sich dabei nicht durch mosquitto_sub, und proc_terminate()
 * traf die Schale, nicht den Zuhoerer (gemessen, Pruefbericht code C4).
 * Die Marke PW_ZUHOERER erkennt das Kind spaeter als Waise wieder.
 *
 * SIGPIPE: PHP-CLI ignoriert es, und ein ignoriertes Signal erbt jedes
 * Kind ueber exec hinweg (gemessen SigIgn ...1000). Stirbt der Zuhoerer,
 * endet mosquitto_sub dann nicht beim naechsten Schreiben. Fuer den Start
 * des Kindes wird es deshalb auf die Vorgabe gestellt und danach wieder
 * ignoriert (nur mit pcntl; ohne bleibt es wie bisher). */
$pw_umgebung = getenv();
if (!is_array($pw_umgebung)) { $pw_umgebung = array(); }
if ($pw_ordner !== '') { $pw_umgebung['XDG_CONFIG_HOME'] = $pw_ordner; }
$pw_umgebung['PW_ZUHOERER'] = $pw_p['datadir'];

$pw_rohre = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$pw_sigpipe = function_exists('pcntl_signal') && defined('SIGPIPE');
if ($pw_sigpipe) { @pcntl_signal(SIGPIPE, SIG_DFL); }
$pw_ph = @proc_open($pw_argv, $pw_rohre, $pw_pipes, null, $pw_umgebung);
if ($pw_sigpipe) { @pcntl_signal(SIGPIPE, SIG_IGN); }
if (!is_resource($pw_ph)) {
    fwrite(STDERR, "mosquitto_sub liess sich nicht starten.\n");
    pw_log('Zuhoerer: mosquitto_sub liess sich nicht starten.');
    exit(1);
}
stream_set_blocking($pw_pipes[1], false);
stream_set_blocking($pw_pipes[2], false);

if (!$pw_probe) {
    @file_put_contents($pw_pidfile, (string) getmypid());
    pw_log('Zuhoerer gestartet, Themen ' . implode(', ', $pw_themen)
           . ' an ' . $pw_b['host'] . ':' . $pw_b['port'] . '.');
}

/* Die aufbewahrte Anwesenheit EINMAL abholen.
 *
 * Am Geraet gemessen (08.09.2026): `<basis>/online` liegt aufbewahrt im
 * Broker, und trotzdem stand quelle_online im Zustand auf "keine Auskunft".
 * Eine aufbewahrte Meldung kommt NUR beim Abonnieren; der Zuhoerer hatte sie
 * bekommen, die Installation raeumte den Zustand drei Sekunden spaeter ab,
 * und danach kam sie nie wieder.
 *
 * Ein eigener kurzer Aufruf je Pumpe loest das unabhaengig von der
 * Reihenfolge. Er wartet hoechstens zwei Sekunden und schreibt nichts, wenn
 * nichts kommt - ein Zaehler ohne online-Thema ist kein Fehler. */
if (!$pw_probe && pw_hat_mosquitto()) {
    foreach (pw_pumpe_ids($pw_voll) as $pw_oid) {
        $pw_op = pw_pumpe($pw_voll, $pw_oid);
        if ((string) $pw_op['quelle'] !== 'mqtt') { continue; }
        $pw_ot = pw_online_thema($pw_op['quelle_topic']);
        if ($pw_ot === '') { continue; }
        $pw_oargv = array('mosquitto_sub', '-h', $pw_b['host'],
                          '-p', (string) $pw_b['port'], '-v', '-q', '1',
                          '-C', '1', '-W', '2', '-t', $pw_ot);
        $pw_obefehl = ($pw_ordner !== ''
                       ? 'XDG_CONFIG_HOME=' . escapeshellarg($pw_ordner) . ' ' : '')
                    . implode(' ', array_map('escapeshellarg', $pw_oargv));
        $pw_oaus = @shell_exec($pw_obefehl . ' 2>/dev/null');
        if (is_string($pw_oaus) && trim($pw_oaus) !== '') {
            foreach (explode("\n", trim($pw_oaus)) as $pw_ozeile) {
                if (trim($pw_ozeile) === '') { continue; }
                pw_zeile_verarbeiten($pw_ozeile, $pw_op, time(), true);
            }
            pw_log('Zuhoerer: Anwesenheit von ' . $pw_oid . ' abgeholt ('
                   . trim($pw_oaus) . ').');
        }
    }
}

$pw_ende = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $pw_beenden = function () use (&$pw_ende) { $pw_ende = true; };
    pcntl_signal(SIGTERM, $pw_beenden);
    pcntl_signal(SIGINT, $pw_beenden);
}

function pw_aufraeumen()
{
    global $pw_ph, $pw_pipes, $pw_pidfile, $pw_probe;
    foreach (array(1, 2) as $i) {
        if (isset($pw_pipes[$i]) && is_resource($pw_pipes[$i])) { @fclose($pw_pipes[$i]); }
    }
    if (is_resource($pw_ph)) { @proc_terminate($pw_ph); @proc_close($pw_ph); }
    if (!$pw_probe) { @unlink($pw_pidfile); }
}
register_shutdown_function('pw_aufraeumen');

/* ---------------- die Schleife ---------------- */

$pw_rest = '';
$pw_start = time();
$pw_gesehen = 0;      // Nachrichten mit Messwert
$pw_uebergangen = 0;  // Nachrichten ohne Messwert (der Normalfall!)
$pw_fremd = 0;        // Zeilen, die zu KEINER Pumpe gehoeren
$pw_eigen = 0;        // Zeilen unter einem EIGENEN Praefix (B4, 1.0.4)
$pw_unsinn = 0;       // Messwerte, die keine sein koennen (C7, 1.0.4)
$pw_kind_ende = false;
$pw_eigene = pw_eigene_praefixe($pw_voll);
$pw_letzte_cfg = @filemtime($pw_p['config']);
/* Nach einem Tag geordnet aufhoeren. Der Waechter startet sofort neu. Ein
 * Prozess, der wochenlang laeuft, sammelt Kleinigkeiten - und ein geplantes
 * Ende ist billiger als die Suche danach. */
$PW_LEBENSDAUER = 86400;
$PW_PROBE_S = 130;    // etwas mehr als zwei Minutenmeldungen

while (!$pw_ende) {
    $lesen = array();
    foreach (array(1, 2) as $i) {
        if (is_resource($pw_pipes[$i])) { $lesen[] = $pw_pipes[$i]; }
    }
    if (!$lesen) { break; }
    $schreiben = null; $fehler = null;
    @stream_select($lesen, $schreiben, $fehler, 5);

    /* Die Fehlerausgabe von mosquitto_sub ist die einzige Stelle, an der
     * "not authorised" steht. Sie zu verwerfen hiesse, eine abgelehnte
     * Anmeldung als Netzproblem zu melden - genau die Verwechslung, die
     * REGELN_2 als eigene Regel fuehrt. */
    if (is_resource($pw_pipes[2])) {
        $e = @stream_get_contents($pw_pipes[2]);
        if (is_string($e) && trim($e) !== '') {
            $txt = trim(preg_replace('/\s+/', ' ', $e));
            pw_log('Zuhoerer meldet: ' . substr($txt, 0, 200));
            if ($pw_probe) { echo "  [Fehlerausgabe] " . $txt . "\n"; }
        }
    }

    $neu = is_resource($pw_pipes[1]) ? @stream_get_contents($pw_pipes[1]) : false;
    if (is_string($neu) && $neu !== '') {
        $pw_rest .= $neu;
        $zeilen = explode("\n", $pw_rest);
        // Die letzte Zeile kann angeschnitten sein - sie wartet auf den Rest.
        $pw_rest = array_pop($zeilen);
        foreach ($zeilen as $z) {
            /* Die Arbeit steckt in pw_zeile_verarbeiten() - dort laesst sie
             * sich mit den echten gemessenen Zeilen pruefen, ohne Broker
             * und ohne mosquitto_sub. Hier bleibt nur die Buchfuehrung. */
            /* Zuerst die Zuordnung: welcher Pumpe gehoert diese Zeile?
             * Eine, die zu keiner passt, wird verworfen UND gezaehlt - sie
             * ist ein Einrichtungsfehler, meistens ein von Hand erweitertes
             * Abo, und still verwerfen hiesse ihn verbergen. */
            $pw_thema_z = '';
            $pw_sp = strpos((string) $z, ' ');
            if ($pw_sp !== false) { $pw_thema_z = substr((string) $z, 0, $pw_sp); }
            /* B4 (1.0.4): eine Zeile unter einem EIGENEN Praefix ist das
             * eigene Echo, nie ein Messwert. Bis 1.0.3 las der Zuhoerer mit
             * quelle_topic 'pumpe/#' alle 25 eigenen Themen als Watt und
             * schickte daraufhin 331 neue Datagramme (Pruefbericht mqtt B4).
             * Verworfen, gezaehlt und einmal protokolliert. */
            $pw_echo = false;
            foreach ($pw_eigene as $pw_pr) {
                if ($pw_thema_z !== '' && pw_thema_passt($pw_pr . '/+', $pw_thema_z)) { $pw_echo = true; break; }
            }
            if ($pw_echo) {
                $pw_eigen++;
                if ($pw_eigen === 1) {
                    pw_log('Zuhoerer: Zeilen unter einem eigenen Praefix verworfen (' . $pw_thema_z
                           . ') - das Quell-Thema trifft die eigenen Veroeffentlichungen.');
                }
                if ($pw_probe) { echo "  EIGENES ECHO : " . $pw_thema_z . "\n"; }
                continue;
            }
            $pw_id = $pw_thema_z === ''
                   ? null : pw_pumpe_fuer_thema($pw_voll, $pw_thema_z);
            if ($pw_id === null) {
                if (trim((string) $z) !== '') {
                    $pw_fremd++;
                    if ($pw_probe) { echo "  OHNE PUMPE   : " . $pw_thema_z . "\n"; }
                }
                continue;
            }
            $e = pw_zeile_verarbeiten($z, pw_pumpe($pw_voll, $pw_id),
                                      time(), !$pw_probe);
            if ($e['art'] === 'unsinn') {
                /* C7 (1.0.4): unter -5 W, NaN, unendlich - keine Messung. */
                $pw_unsinn++;
                if ($pw_unsinn === 1) {
                    pw_log('Zuhoerer: unmoeglicher Messwert verworfen (' . var_export($e['watt'], true)
                           . ' W, ' . $pw_thema_z . ') - er zaehlt nicht als Messung.');
                }
                if ($pw_probe) { echo "  UNSINN       : " . var_export($e['watt'], true) . " W\n"; }
                continue;
            }
            if ($e['art'] === 'messwert') {
                $pw_gesehen++;
                if ($pw_probe) {
                    printf("  MESSWERT     : %s W  (%s V, %s A, %s Hz)\n",
                           $e['watt'],
                           $e['neben']['volt'] === null ? '-' : $e['neben']['volt'],
                           $e['neben']['ampere'] === null ? '-' : $e['neben']['ampere'],
                           $e['neben']['hertz'] === null ? '-' : $e['neben']['hertz']);
                }
                if ($e['gescheitert'] > 0) {
                    pw_log(sprintf('Zuhoerer: %d Themen versucht, %d gescheitert.',
                                   $e['versucht'], $e['gescheitert']));
                }
                continue;
            }
            $pw_uebergangen++;
            if ($pw_probe) {
                printf("  %-13s: %s\n",
                       $e['art'] === 'anwesenheit' ? 'Anwesenheit' : 'ohne Messwert',
                       substr($z, 0, 96));
            }
        }
    }

    if (!$pw_probe) {
        @file_put_contents($pw_tsfile, (string) time());
        /* Der Bericht neben dem Lebenszeichen. Er traegt vor allem die
         * Zahl der Zeilen, die zu KEINER Pumpe gehoeren - der Reiter Test
         * kann sie sonst nirgends erfahren, und still verworfene Zeilen
         * sind genau die, die niemand vermisst. */
        pw_json_schreiben($pw_p['datadir'] . '/dienst.json', array(
            'ts' => time(), 'gesehen' => $pw_gesehen,
            'uebergangen' => $pw_uebergangen, 'fremd' => $pw_fremd,
            'eigen' => $pw_eigen, 'unsinn' => $pw_unsinn));
    }

    /* Ist mosquitto_sub gestorben? Dann endet auch dieser Lauf - der
     * Waechter startet beide neu. Weiterlaufen hiesse, still nichts mehr zu
     * hoeren und dabei zu leben. */
    $st = @proc_get_status($pw_ph);
    if (is_array($st) && empty($st['running'])) {
        pw_log('Zuhoerer: mosquitto_sub hat sich beendet (Rueckgabewert '
               . (isset($st['exitcode']) ? $st['exitcode'] : '?') . ').');
        /* C4 (1.0.4): mit rc 3 enden, damit der Minutentakt es nach
         * cron.err schreibt - bis 1.0.3 endete dieser Weg mit rc 0. */
        fwrite(STDERR, 'mosquitto_sub hat sich beendet (Rueckgabewert '
               . (isset($st['exitcode']) ? $st['exitcode'] : '?') . ").\n");
        $pw_kind_ende = true;
        break;
    }

    if ($pw_probe && (time() - $pw_start) >= $PW_PROBE_S) { break; }
    if (!$pw_probe && (time() - $pw_start) >= $PW_LEBENSDAUER) {
        pw_log('Zuhoerer: geplantes Ende nach einem Tag, der Waechter startet neu.');
        break;
    }
    /* Wurde die Konfiguration geaendert? Dann neu anfangen - Thema oder
     * Quelle koennen andere sein. */
    /* clearstatcache VOR filemtime - sonst merkt der Dauerlaeufer eine
     * Aenderung der Konfiguration genau dann nicht, wenn es zaehlt.
     *
     * PHP haelt den Stat-Zwischenspeicher fuer EINE Datei; in einer
     * Warteschleife bleibt der Wert stehen. Gemessen 31.08.2026 mit einer
     * zeilengetreuen Nachbildung dieser Schleife, Konfiguration von aussen
     * geaendert: unter 7.4.33 - der Fassung, die auf dem Geraet laeuft -
     * ueber zwoelf Runden NICHT erkannt; unter 8.4.24 in Runde 3 erkannt.
     *
     * Der Neustart griff bisher nur in einer Runde, in der wirklich eine
     * Zeile verarbeitet wurde: nur dann statete pw_stand() eine andere
     * Datei und verdraengte den Eintrag. Bei laufendem Zaehler faellt das
     * kaum auf; kommt gar NICHTS an - falsches Thema, abgelehnte Anmeldung,
     * Zaehler offline -, faellt es nie auf. Und das ist genau die Lage, in
     * der jemand das Quell-Thema aendert.
     *
     * Dieselbe Fehlerklasse wie die Protokollkappung in 0.9.9, an einer
     * neuen Stelle. */
    clearstatcache(true, $pw_p['config']);
    $jetzt_cfg = @filemtime($pw_p['config']);
    if (!$pw_probe && $jetzt_cfg && $jetzt_cfg !== $pw_letzte_cfg) {
        pw_log('Zuhoerer: die Konfiguration hat sich geaendert, Neustart.');
        break;
    }
}

if ($pw_probe) {
    printf("\n%d Nachricht(en) mit Messwert, %d ohne, %d ohne Pumpe.\n",
           $pw_gesehen, $pw_uebergangen, $pw_fremd);
    if ($pw_fremd > 0) {
        echo "Zeilen ohne Pumpe kommen an, gehoeren aber zu keinem Quell-Thema.\n"
           . "Meistens ein Abo, das von Hand erweitert wurde.\n";
    }
    if ($pw_gesehen === 0) {
        echo "Kein einziger Messwert. Moegliche Gruende: falsches Thema, der\n"
           . "Zaehler meldet gerade nichts, oder die Anmeldung am Broker wurde\n"
           . "abgelehnt - dann steht das oben in der Fehlerausgabe.\n";
        exit(1);
    }
}
exit($pw_kind_ende && !$pw_probe ? 3 : 0);
