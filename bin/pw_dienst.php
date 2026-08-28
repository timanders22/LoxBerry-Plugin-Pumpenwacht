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
    $pid = pw_dienst_pid();
    if ($pid <= 0) {
        echo "Der Zuhoerer laeuft nicht.\n";
        exit(0);
    }
    /* Erst hoeflich, dann bestimmt - und die WIRKUNG wird gemessen, nicht
     * der Rueckgabewert des Signals.
     *
     * posix_kill() steckt in einer Erweiterung, die nicht zugesichert ist,
     * und sie steht NICHT in dpkg/apt. Fehlt sie, ist das kein abfangbarer
     * Fehler, sondern ein toedlicher - dieselbe Klasse wie socket_create()
     * in 0.9.7. Also gefragt, nicht gehofft. */
    pw_signal($pid, 15);
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if (pw_dienst_pid() <= 0) {
            echo "Der Zuhoerer wurde beendet (PID " . $pid . ").\n";
            @unlink($pw_pidfile);
            exit(0);
        }
    }
    pw_signal($pid, 9);
    usleep(300000);
    $noch = pw_dienst_pid();
    @unlink($pw_pidfile);
    if ($noch > 0) {
        fwrite(STDERR, "Der Zuhoerer liess sich nicht beenden (PID " . $noch . ").\n");
        exit(1);
    }
    echo "Der Zuhoerer wurde hart beendet (PID " . $pid . ").\n";
    exit(0);
}

if ($pw_hat('status')) {
    $pid = pw_dienst_pid();
    $alter = pw_dienst_alter();
    if ($pid > 0) {
        printf("laeuft, PID %d, letztes Lebenszeichen vor %s\n",
               $pid, $alter >= 0 ? $alter . ' s' : 'unbekannt');
        exit(0);
    }
    echo "laeuft nicht\n";
    exit(1);
}

/* ---------------- Voraussetzungen ---------------- */

$pw_cfg = pw_config();

/* Die Selbstpruefung steht VOR der Quellenpruefung: auf einer Anlage, die
 * noch ueber Loxone laeuft, will man vor dem Umstellen wissen, ob der Weg
 * traegt. Sie schreibt nichts und sendet nichts. */
if ($pw_hat('--selbsttest')) {
    exit(pw_selbsttest_dienst($pw_cfg));
}

if ((string) $pw_cfg['quelle'] !== 'mqtt' && !$pw_probe) {
    /* Kein Fehler: die Quelle steht auf Loxone, der Dienst wird nicht
     * gebraucht. Er sagt es einmal und geht. */
    echo "Die Messwertquelle steht auf \"Loxone\" - der Zuhoerer wird nicht gebraucht.\n";
    exit(0);
}

$pw_thema = trim((string) $pw_cfg['quelle_topic']);
if ($pw_thema === '') {
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
$pw_lock = @fopen($pw_lockfile, 'c');
if (!$pw_lock) {
    fwrite(STDERR, "Sperrdatei nicht anlegbar: " . $pw_lockfile . "\n");
    exit(1);
}
if (!$pw_probe && !@flock($pw_lock, LOCK_EX | LOCK_NB)) {
    echo "Es laeuft bereits ein Zuhoerer.\n";
    exit(0);
}

/* ---------------- den Zuhoerer starten ---------------- */

$pw_b = pw_broker();
$pw_ordner = pw_broker_optionsdatei(true);

/* DIE ZUGANGSDATEN STEHEN NICHT AUF DER KOMMANDOZEILE.
 * /proc/<pid>/cmdline hat die Rechte 444, und dieser Prozess laeuft dauernd -
 * jeder lokale Benutzer koennte mitlesen. mosquitto_sub liest sie aus
 * $XDG_CONFIG_HOME/mosquitto_sub; auf der Zeile steht nur der Pfad. */
$pw_argv = array('mosquitto_sub',
                 '-h', $pw_b['host'],
                 '-p', (string) $pw_b['port'],
                 '-v', '-q', '1',
                 '-t', $pw_thema);
$pw_befehl = ($pw_ordner !== '' ? 'XDG_CONFIG_HOME=' . escapeshellarg($pw_ordner) . ' ' : '')
           . implode(' ', array_map('escapeshellarg', $pw_argv));

$pw_rohre = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$pw_ph = @proc_open($pw_befehl, $pw_rohre, $pw_pipes);
if (!is_resource($pw_ph)) {
    fwrite(STDERR, "mosquitto_sub liess sich nicht starten.\n");
    pw_log('Zuhoerer: mosquitto_sub liess sich nicht starten.');
    exit(1);
}
stream_set_blocking($pw_pipes[1], false);
stream_set_blocking($pw_pipes[2], false);

if (!$pw_probe) {
    @file_put_contents($pw_pidfile, (string) getmypid());
    pw_log('Zuhoerer gestartet, Thema ' . $pw_thema . ' an ' . $pw_b['host']
           . ':' . $pw_b['port'] . '.');
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
            $e = pw_zeile_verarbeiten($z, $pw_cfg, time(), !$pw_probe);
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

    if (!$pw_probe) { @file_put_contents($pw_tsfile, (string) time()); }

    /* Ist mosquitto_sub gestorben? Dann endet auch dieser Lauf - der
     * Waechter startet beide neu. Weiterlaufen hiesse, still nichts mehr zu
     * hoeren und dabei zu leben. */
    $st = @proc_get_status($pw_ph);
    if (is_array($st) && empty($st['running'])) {
        pw_log('Zuhoerer: mosquitto_sub hat sich beendet (Rueckgabewert '
               . (isset($st['exitcode']) ? $st['exitcode'] : '?') . ').');
        break;
    }

    if ($pw_probe && (time() - $pw_start) >= $PW_PROBE_S) { break; }
    if (!$pw_probe && (time() - $pw_start) >= $PW_LEBENSDAUER) {
        pw_log('Zuhoerer: geplantes Ende nach einem Tag, der Waechter startet neu.');
        break;
    }
    /* Wurde die Konfiguration geaendert? Dann neu anfangen - Thema oder
     * Quelle koennen andere sein. */
    $jetzt_cfg = @filemtime($pw_p['config']);
    if (!$pw_probe && $jetzt_cfg && $jetzt_cfg !== $pw_letzte_cfg) {
        pw_log('Zuhoerer: die Konfiguration hat sich geaendert, Neustart.');
        break;
    }
}

if ($pw_probe) {
    printf("\n%d Nachricht(en) mit Messwert, %d ohne.\n", $pw_gesehen, $pw_uebergangen);
    if ($pw_gesehen === 0) {
        echo "Kein einziger Messwert. Moegliche Gruende: falsches Thema, der\n"
           . "Zaehler meldet gerade nichts, oder die Anmeldung am Broker wurde\n"
           . "abgelehnt - dann steht das oben in der Fehlerausgabe.\n";
        exit(1);
    }
}
exit(0);
