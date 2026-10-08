<?php
/**
 * Pumpenwaechter - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Bilanz | Test | Logdateien
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-Globale - deshalb ueberall pw_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$pw_lbhome = getenv('LBHOMEDIR');
if ($pw_lbhome) {
    $pw_sdk = $pw_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($pw_sdk)) { require_once $pw_sdk; require_once $pw_lbhome . '/libs/phplib/loxberry_web.php'; }
}
/* Die Bibliothek ueber eine Kandidatenliste finden - NICHT ueber eine feste
 * Zahl von ".." nach oben.
 *
 * Im entpackten Archiv liegen html/ und htmlauth/ nebeneinander, auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen:
 *
 *     <LBHOMEDIR>/webfrontend/htmlauth/plugins/<ordner>/index.php
 *     <LBHOMEDIR>/webfrontend/html/plugins/<ordner>/pw_lib.php
 *
 * dirname(__DIR__) ergibt dort <LBHOMEDIR>/webfrontend/htmlauth/plugins -
 * gesucht wurde also .../htmlauth/plugins/html/pw_lib.php. Die gibt es nicht, und
 * die Oberflaeche endete mit einem fatalen Fehler, also weiss.
 *
 * Gefunden am 16.08.2026 mit Werkzeuge/installationslage_pruefen.py.
 */
$pw_kandidaten = array();
if ($pw_lbhome) {
    $pw_kandidaten[] = $pw_lbhome . '/webfrontend/html/plugins/'
                     . (getenv('LBPPLUGINDIR') ?: basename(__DIR__)) . '/pw_lib.php';
}
$pw_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/html/plugins/' . basename(__DIR__) . '/pw_lib.php';
$pw_kandidaten[] = dirname(__DIR__) . '/html/pw_lib.php';

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, danach die Anzeigewerte NEU bilden,
 * dann erst lbheader(), dann HTML.
 *
 * Der vorletzte Halbsatz ist seit 0.9.8 dabei und er hatte einen Grund:
 * bis 0.9.7 entstanden $pw_cfg, $pw_endpunkt und $pw_felder VOR dem
 * Rueckspiel-Handler. Nach dem Zurueckspielen zeigte die Seite deshalb die
 * alten Werte - darunter das alte Aktionstoken in der Endpunktadresse.
 * Wer sie in diesem Augenblick abschrieb, trug eine tote Adresse in den
 * Miniserver. Gemessen am 28.08.2026.
 * ================================================================== */
$pw_lib = '';
foreach ($pw_kandidaten as $pw_kand) {
    if (is_file($pw_kand)) { $pw_lib = $pw_kand; break; }
}
if ($pw_lib === '') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Pumpenwaechter</h2><p>Die Programmbibliothek <code>pw_lib.php</code> wurde an '
       . 'keiner der erwarteten Stellen gefunden. Bitte das Plugin neu '
       . 'installieren.</p><ul>';
    foreach ($pw_kandidaten as $pw_kand) {
        echo '<li><code>' . htmlspecialchars($pw_kand, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul>';
    exit;
}
require_once $pw_lib;

$pw_meldungen = array();
$pw_fehler = array();
/* X-2 (Regeln/04, Verbesserungsbau 30.09.2026): die eingetippten Werte EINES
 * beanstandeten Formulars. Sie reisen mit der Einmalmeldung und fuellen beim
 * GET das Formular. Nie Geheimnisse: das SignalBot-Token wird gar nicht
 * gesammelt, sein Feld bleibt leer. */
$pw_eingaben = null;
$pw_falsch = array();
function pw_eingaben_sammeln($form, array $falsch, $pid)
{
    $str = function ($v) {
        return is_string($v) ? substr(preg_replace('/[\x00-\x1F\x7F]/', '', $v), 0, 4096) : '';
    };
    $felder = array(
        'settings' => array('name', 'art', 'ruht_s', 'modell', 'quelle', 'quelle_topic', 'an_w',
                            'trocken_w', 'trocken_s', 'ueberlast_w', 'dauerlauf_s', 'starts_h',
                            'anlauf_s', 'stale_s', 'signal_ordner', 'signal_an'),
        'mqtt'     => array('mqtt_topic'),
        'test'     => array('watt'));
    $haken = array(
        'settings' => array('sperren_ein', 'sperre_trockenlauf', 'sperre_dauerlauf', 'sperre_schaltspiel',
                            'sperre_ueberlast', 'sperre_kein_anlauf', 'sperre_ruht', 'quittung_noetig',
                            'signal_ein', 'signal_dringend'),
        'mqtt'     => array('mqtt_ein'),
        'test'     => array());
    $e = array('form' => $form, 'pumpe' => (string) $pid, 'falsch' => array_values(array_unique($falsch)),
               'werte' => array(), 'haken' => array());
    foreach (isset($felder[$form]) ? $felder[$form] : array() as $k) {
        if (isset($_POST[$k])) { $e['werte'][$k] = $str($_POST[$k]); }
    }
    foreach (isset($haken[$form]) ? $haken[$form] : array() as $k) {
        $e['haken'][$k] = isset($_POST[$k]);
    }
    if ($form === 'settings') {
        /* Nr. 36 b: Felder und Anlaesse der Ansage - nie die Sprechtoken (ansage_x2_felder()). */
        foreach (ansage_x2_felder(array('modi' => pw_ansage_modi())) as $k) {
            if (substr($k, -9) === '_loeschen') { $e['haken'][$k] = isset($_POST[$k]); }
            elseif (isset($_POST[$k])) { $e['werte'][$k] = $str($_POST[$k]); }
        }
        foreach (pw_ansage_anlaesse() as $k) { $e['haken'][$k] = isset($_POST[$k]); }
    }
    return $e;
}
/* Beim GET: Wert, Haken und Markierung - nach einer Beanstandung aus den
 * eingetippten Werten, sonst aus der Konfiguration. Nur fuer das eine
 * Formular und nur fuer die Pumpe, zu der es gehoerte. */
function pw_eingabe($form)
{
    global $pw_eingaben, $pw_pid;
    return (is_array($pw_eingaben) && isset($pw_eingaben['form'], $pw_eingaben['pumpe'])
            && $pw_eingaben['form'] === $form && $pw_eingaben['pumpe'] === (string) $pw_pid)
         ? $pw_eingaben : null;
}
function pw_fw($form, $k, $gespeichert)
{
    $e = pw_eingabe($form);
    if ($e !== null && isset($e['werte'][$k]) && is_string($e['werte'][$k])) { return $e['werte'][$k]; }
    return (string) $gespeichert;
}
function pw_fh($form, $k, $gespeichert)
{
    $e = pw_eingabe($form);
    if ($e !== null && isset($e['haken']) && is_array($e['haken']) && array_key_exists($k, $e['haken'])) {
        return !empty($e['haken'][$k]);
    }
    return (bool) $gespeichert;
}
function pw_fk($form, $k, $nur_klasse = false)
{
    $e = pw_eingabe($form);
    $ja = ($e !== null && isset($e['falsch']) && is_array($e['falsch']) && in_array($k, $e['falsch'], true));
    if ($nur_klasse) { return $ja ? ' sm-beanstandet' : ''; }
    return $ja ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/* X-2 (Nachzug G2, 02.10.2026): aria-invalid fuer Felder, deren Klasse mit
 * pw_fk(..., true) IM class-Attribut steht (die Auswahlfelder). */
function pw_fa($form, $k)
{
    return pw_fk($form, $k, true) !== '' ? ' aria-invalid="true"' : '';
}

/* ---------------- Konfiguration ---------------- */
/* Vervollstaendigen statt nur ergaenzen: array_merge beim Lesen macht
 * "fehlt" von "steht auf dem Vorgabewert" ununterscheidbar, und eine
 * Umbenennung waere still. Der Reiter Test zeigt danach "19 von 19". */
list($pw_voll, $pw_fehlten, $pw_fremd) = pw_cfg_vervollstaendigen();

/* ==================================================================
 * WELCHE PUMPE?
 * ==================================================================
 *
 * Die Kennung kommt aus der Adresse (Verweis) oder aus dem Formular
 * (verstecktes Feld) und wird GEPRUEFT. Eine unbekannte faellt auf die erste
 * zurueck: ohne diese Pruefung liesse sich ueber ?pumpe=erfunden ein
 * Unterbaum anlegen, den die Oberflaeche nie wieder zeigt.
 *
 * $_POST hat Vorrang vor $_GET - ein abgeschicktes Formular sagt genauer,
 * worauf es sich bezieht, als die Adresse, in der es steht. Auf $_REQUEST
 * verlassen wir uns nicht: welche Quelle dort gewinnt, haengt an
 * request_order in der php.ini, und das ist keine Groesse dieses Plugins.
 *
 * Diese Zeilen stehen VOR den Handlern. Ein Handler, der die Kennung erst
 * spaeter bekaeme, haette bis dahin auf die erste Pumpe geschrieben. */
$pw_alle_ids = pw_pumpe_ids($pw_voll);
$pw_pid = '';
if (isset($_POST['pumpe']) && is_string($_POST['pumpe'])) {
    $pw_pid = $_POST['pumpe'];
} elseif (isset($_GET['pumpe']) && is_string($_GET['pumpe'])) {
    $pw_pid = $_GET['pumpe'];
}
/* O2 (1.0.4): zurueckgefallen wird nur fuer die ANZEIGE. Ein POST mit
 * unbekannter Kennung wird unten hinter dem Wachposten abgewiesen. Bis
 * 1.0.3 entfernte die zweite Absendung von "Pumpe entfernen" (F5, ein
 * zweiter Tab) die ERSTE Pumpe - im gemessenen Fall die Sumpfpumpe samt
 * Zustand und Tagesbilanz (Pruefbericht oberflaeche O2). */
$pw_pid_unbekannt = !in_array($pw_pid, $pw_alle_ids, true);
if ($pw_pid_unbekannt) {
    $pw_pid = $pw_alle_ids ? $pw_alle_ids[0] : '';
}
$pw_cfg = pw_pumpe($pw_voll, $pw_pid);

/* Wortzeichen beim ersten Oeffnen erzeugen, danach nur noch auf
 * ausdruecklichen Wunsch - es steckt in den Adressen im Miniserver. */
if (empty($pw_cfg['aktionstoken']) || empty($pw_cfg['formgeheim'])) {
    if (empty($pw_cfg['aktionstoken'])) {
        $pw_cfg['aktionstoken'] = pw_token_erzeugen();
    }
    /* Das Geheimnis des Formularmerkmals. Es steht NEBEN dem Aktionstoken
     * und nicht an seiner Stelle: das Aktionstoken geht in jede Adresse im
     * Miniserver, dieses hier geht nirgendwohin - es wird auf der Seite
     * nicht angezeigt und in keine Vorlage geschrieben. Bis 0.9.10 war das
     * Merkmal aus dem Aktionstoken berechenbar, und damit war der
     * Wachposten fuer jeden offen, der die Adressen im Loxone-Projekt
     * lesen kann. */
    if (empty($pw_cfg['formgeheim'])) {
        $pw_cfg['formgeheim'] = pw_token_erzeugen();
    }
    /* Beide Geheimnisse sind GLOBAL. Geschrieben wird deshalb die volle
     * Form - wer hier die flache Sicht speicherte, machte aus der
     * Konfiguration wieder eine flache Datei und verloere beim naechsten
     * Lesen jede Pumpe ausser der ersten. */
    pw_config_speichern(pw_pumpe_zurueck(pw_config(), $pw_cfg, $pw_pid));
    $pw_cfg = pw_pumpe(pw_config(), $pw_pid);
}

/* ==================================================================
 * DER WACHPOSTEN
 * ==================================================================
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten mit, wenn der Browser eines ANGEMELDETEN
 * Bedieners ein Formular abschickt, das auf einer fremden Seite steht.
 *
 * Bis 0.9.7 gab es hier gar nichts, obwohl der Kommentar oben einen
 * Wachposten zusicherte. Gemessen am 28.08.2026 an einem echten Webserver:
 * ein POST ohne jedes Merkmal hat das Protokoll geleert und die
 * vollstaendige Konfiguration SAMT AKTIONSTOKEN als Datei geliefert.
 *
 * Einen einzelnen Handler kann man beim Erweitern vergessen, einen
 * Wachposten am Eingang nicht.
 * ================================================================== */
$pw_fmt = pw_formtoken($pw_cfg);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($pw_fmt === '') {
        $pw_fehler[] = pw_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!pw_formtoken_ok($pw_cfg)) {
        $pw_fehler[] = pw_t('FEHLER.CSRF');
        pw_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
    if ($pw_fehler) {
        // $_POST leeren, damit danach KEIN Handler mehr anlaeuft. Den aktiven
        // Reiter behalten - die Meldung soll dort stehen, wo der Bediener war.
        $pw_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($pw_behalten !== null) { $_POST['activetab'] = $pw_behalten; }
    }
}

/* O2 (1.0.4): ein Formular fuer eine Pumpe, die es nicht (mehr) gibt,
 * aendert NICHTS - abgewiesen mit Meldung, so wie der Endpunkt es mit
 * GRUND=PUMPE tut. Ein POST ganz ohne Kennung (aeltere Formulare,
 * Werkzeuge) gilt weiter der ersten Pumpe - abgewiesen wird die GENANNTE,
 * die es nicht gibt. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pw_fehler && $pw_pid_unbekannt && isset($_POST['pumpe'])) {
    $pw_fehler[] = pw_t('PUMPE.UNBEKANNT');
    pw_log('Ein Formular fuer eine unbekannte Pumpe wurde abgewiesen - es wurde nichts geaendert.');
    $pw_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
    $_POST = array();
    if ($pw_behalten !== null) { $_POST['activetab'] = $pw_behalten; }
}

/* Aktiver Reiter. Die Positivliste steht AUSGESCHRIEBEN - so findet
 * hausstandard_pruefen.py sie. Bis 0.9.7 entstand sie aus einer Schleife,
 * und die Spalte "tab" des Werkzeugs stand deshalb auf einem Strich: die
 * Pruefung war blind, und ein Strich sieht aus wie eine Kleinigkeit. Die
 * Uebereinstimmung von Liste, Leiste und Bereichen prueft der Reiter Test
 * nach - siehe PRUEF.REITER. */
$pw_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-bilanz',
                   'tab-test', 'tab-log');
$pw_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $pw_reiter, true)) {
    $pw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $pw_reiter, true)) {
    $pw_tab = 'tab-' . (string) $_GET['form'];
}

$pw_ist_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$pw_testausgabe = '';

/* ---------- Loxone-Vorlagen herunterladen (Hausstandard) ---------- */
if ($pw_ist_post && isset($_POST['vorlage'])) {
    $pw_art = in_array((string) $_POST['vorlage'], array('vi', 'vihttp', 'vo'), true)
              ? (string) $_POST['vorlage'] : 'vi';
    /* vi und vo tragen ALLE Pumpen - sie bekommen deshalb die volle Form.
     * vihttp kann es nicht (eine Abfrageadresse je Datei) und bekommt die
     * flache Sicht der gewaehlten Pumpe. */
    list($pw_vname, $pw_vinhalt) = pw_vorlage(
        $pw_art, $pw_art === 'vihttp' ? $pw_cfg : pw_config(), null, 'pw_t');
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $pw_vname . '"');
    echo $pw_vinhalt;
    exit;
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * Seit 0.9.8 traegt sie einen Kopf (_plugin, _fassung, _stand), und die
 * Lesefunktion VERLANGT ihn. */
if ($pw_ist_post && isset($_POST['pw_sichern'])) {
    /* AUSDRUECKLICH die volle Form, nicht die flache Sicht der gewaehlten
     * Pumpe: eine Sicherung, die nur die gerade angesehene Pumpe enthaelt,
     * loescht beim Zurueckspielen alle anderen. */
    $pw_js = json_encode(pw_sicherung_bauen(pw_config()),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($pw_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="pumpenwacht_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $pw_js;
        exit;
    }
    $pw_fehler[] = pw_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($pw_ist_post && isset($_POST['pw_zurueck'])) {
    if (!isset($_FILES['pw_sicherung']) || !is_array($_FILES['pw_sicherung'])
        || !isset($_FILES['pw_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['pw_sicherung']['tmp_name'])) {
        $pw_fehler[] = pw_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['pw_sicherung']['size'] > 262144) {
        $pw_fehler[] = pw_t('EINST.SICH_ZU_GROSS');
    } else {
        list($pw_neu, $pw_mangel, $pw_n, $pw_soll, $pw_unver) = pw_sicherung_lesen(
            (string) @file_get_contents($_FILES['pw_sicherung']['tmp_name']));
        if ($pw_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $pw_fehler[] = pw_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $pw_mangel);
        /* HIER die volle Form OHNE pw_pumpe_zurueck(): $pw_neu kommt aus
         * pw_sicherung_lesen() und ist bereits die ganze Konfiguration samt
         * Pumpenliste. Wer sie durch pw_pumpe_zurueck() schickt, liest sie
         * als flache Sicht - und 'pumpen' faellt heraus, weil es dort weder
         * global noch ein Pumpenschluessel ist. Die Sicherung galt dann als
         * angenommen und wurde nicht uebernommen. */
        } elseif (pw_config_speichern($pw_neu)) {
            /* B9 (1.0.4): Praefixe, die es nach dem Zurueckspielen nicht
             * mehr gibt, werden abgeraeumt - wie beim Wechsel im Reiter MQTT. */
            $pw_bleiben = pw_eigene_praefixe($pw_neu);
            foreach (pw_eigene_praefixe($pw_voll) as $pw_ap) {
                if (in_array($pw_ap, $pw_bleiben, true)) { continue; }
                list($pw_am, $pw_af) = pw_ui_praefix_leeren($pw_ap);
                $pw_meldungen = array_merge($pw_meldungen, $pw_am);
                $pw_fehler = array_merge($pw_fehler, $pw_af);
            }
            /* Beide Zahlen nennen, und die uebrigen beim Namen. "1 Werte
             * uebernommen" war bis 0.9.7 woertlich richtig und trotzdem
             * beschwichtigend - es sagte nicht, was mit den anderen
             * sechzehn geschah. */
            $pw_meldungen[] = sprintf(pw_t('EINST.SICH_UEBERNOMMEN'), $pw_n, $pw_soll)
                . ($pw_unver
                   ? ' ' . sprintf(pw_t('EINST.SICH_UNVERAENDERT'),
                                   count($pw_unver), pw_e(implode(', ', $pw_unver)))
                   : '');
            pw_log(sprintf('Sicherung zurueckgespielt: %d von %d Einstellungen.',
                           $pw_n, $pw_soll));
        } else {
            $pw_fehler[] = pw_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
    $pw_tab = 'tab-settings';
}

/* ---------- Pumpe anlegen ---------- */
if ($pw_ist_post && isset($_POST['pumpe_neu'])) {
    $pw_neue = pw_pumpe_anlegen();
    if ($pw_neue === null) {
        $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config']));
    } else {
        /* Auf die neue Pumpe umschalten. Wer eine anlegt, will sie
         * einrichten - und saehe sonst weiter die alte, waehrend die neue
         * unbemerkt in der Leiste steht. */
        $pw_pid = $pw_neue;
        $pw_meldungen[] = sprintf(pw_t('PUMPE.ANGELEGT'), pw_e($pw_neue));
    }
    $pw_tab = 'tab-settings';
}

/* ---------- Pumpe entfernen ---------- */
if ($pw_ist_post && isset($_POST['pumpe_weg'])) {
    /* O3 (1.0.4): nur mit dem Bestaetigungshaken (Regeln/04, "Formregeln
     * fuer einen loeschenden Knopf"). Bis 1.0.3 entfernte ein einziger
     * Klick in der Pumpenleiste die Pumpe samt Zustand und Tagesbilanz. */
    if (!isset($_POST['bestaetigt']) || !is_string($_POST['bestaetigt']) || $_POST['bestaetigt'] !== '1') {
        $pw_erg = 'haken';
        $pw_fehler[] = sprintf(pw_t('PUMPE.WEG_HAKEN'), pw_e(pw_pumpe_name($pw_cfg)));
    } else {
        $pw_weg_praefix = pw_mqtt_thema($pw_cfg);
        $pw_weg_name = pw_pumpe_name($pw_cfg);
        $pw_erg = pw_pumpe_entfernen($pw_pid);
    }
    if ($pw_erg === 'haken') {
        /* nichts geaendert */
    } elseif ($pw_erg === true) {
        $pw_meldungen[] = sprintf(pw_t('PUMPE.ENTFERNT'), pw_e($pw_weg_name));
        /* B9 (1.0.4): ihre retained Themen gehen mit - sonst stuende der
         * letzte Befund der entfernten Pumpe fuer immer im Broker. Nur,
         * wenn keine andere Pumpe dasselbe Praefix traegt. */
        if (!in_array($pw_weg_praefix, pw_eigene_praefixe(pw_config()), true)) {
            list($pw_am, $pw_af) = pw_ui_praefix_leeren($pw_weg_praefix);
            $pw_meldungen = array_merge($pw_meldungen, $pw_am);
            $pw_fehler = array_merge($pw_fehler, $pw_af);
        }
        /* Die Wahl zeigt jetzt ins Leere. Sie wird bewusst NICHT hier
         * berichtigt, sondern im Anzeigeblock - dort steht die Pruefung
         * ohnehin, und zwei Stellen mit derselben Regel gehen auseinander. */
    } elseif ($pw_erg === 'letzte') {
        $pw_fehler[] = pw_t('PUMPE.LETZTE');
    } else {
        $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config']));
    }
    $pw_tab = 'tab-settings';
}

/* ---------- Einstellungen speichern ---------- */
if ($pw_ist_post && isset($_POST['speichern'])) {
    /* Die volle Form MERKEN und flach weiterarbeiten - der Handler
     * unten liest und schreibt Schluessel ohne Pumpenliste, so wie
     * immer. pw_pumpe_zurueck() legt sie am Ende wieder auseinander. */
    $pw_voll = pw_config();
    $pw_neu = pw_pumpe($pw_voll, $pw_pid);
    $pw_beanstandet = array();
    $pw_beschriftung = array(
        'name' => 'PUMPE.L_NAME', 'modell' => 'EINST.L_MODELL', 'art' => 'PUMPE.L_ART',
        'ruht_s' => 'PUMPE.L_RUHT_S', 'quelle' => 'EINST.L_QUELLE',
        'quelle_topic' => 'EINST.L_QUELLE_TOPIC', 'an_w' => 'EINST.L_AN_W',
        'trocken_w' => 'EINST.L_TROCKEN_W', 'trocken_s' => 'EINST.L_TROCKEN_S',
        'ueberlast_w' => 'EINST.L_UEBERLAST_W', 'dauerlauf_s' => 'EINST.L_DAUERLAUF_S',
        'starts_h' => 'EINST.L_STARTS_H', 'anlauf_s' => 'EINST.L_ANLAUF_S',
        'stale_s' => 'EINST.L_STALE_S',
        'signal_ordner' => 'EINST.L_SIGNAL_ORDNER', 'signal_an' => 'EINST.L_SIGNAL_AN');
    /* Dieselbe Positivliste wie die Sicherung - eine zweite Wahrheit ueber
     * zulaessige Werte gibt es nicht (pw_grenzen). */
    foreach (array('name', 'modell', 'art', 'ruht_s', 'quelle', 'quelle_topic',
                   'an_w', 'trocken_w', 'trocken_s', 'ueberlast_w',
                   'dauerlauf_s', 'starts_h', 'anlauf_s', 'stale_s',
                   'signal_ordner', 'signal_an') as $pw_f) {
        /* Ein Feld, das das Formular GAR NICHT mitschickt, behaelt seinen
         * bisherigen Wert - es wird weder zurueckgesetzt noch beanstandet.
         * REGELN_2, 'Speichern-Handler: uebernehmen, was das Formular nicht
         * mitschickt'. Ohne diese Zeile scheiterte das ganze Speichern,
         * sobald ein Feld fehlte: 'quelle' ist ein Auswahlfeld, und ein
         * leerer Wert steht in keiner Positivliste. Gefunden von einem
         * Pruefstueck, das den POST von Hand zusammensetzt - ein Browser
         * schickt das Auswahlfeld immer mit, und deshalb waere es am
         * Bildschirm nie aufgefallen. */
        if (!isset($_POST[$pw_f])) { continue; }
        /* Ungecastet in die Pruefung: ein Feld, das als Liste kommt, faellt
         * dort durch - (string) machte daraus "Array" samt Warnung. */
        $pw_roh = $_POST[$pw_f];
        list($pw_wert, $pw_grund) = pw_wert_pruefen($pw_f, $pw_roh);
        if ($pw_grund === '') {
            $pw_neu[$pw_f] = $pw_wert;
            continue;
        }
        /* Beanstanden, den bisherigen Eintrag stehen lassen, weitermachen.
         * ALLE Felder werden genannt, nicht nur das letzte: bis 0.9.7
         * ueberschrieb jede Runde die Meldung der vorigen, und gespeichert
         * wurde bei einem einzigen Fehler gar nichts - der Anwender sah ein
         * Feld beanstandet und verlor alle Eingaben. */
        /* O8 (1.0.4): die Beschriftung aus einer ausgeschriebenen Zuordnung.
         * Bis 1.0.3 wurde der Schluessel gerechnet, und fuer Name, Art und
         * Ruhefrist stand "EINST.L_NAME" roh in der Meldung - die
         * Schluessel stehen unter PUMPE.L_* (Pruefbericht oberflaeche O8). */
        $pw_falsch[] = $pw_f;     // X-2
        $pw_bez = pw_t(isset($pw_beschriftung[$pw_f]) ? $pw_beschriftung[$pw_f] : 'EINST.L_MODELL');
        if (strncmp($pw_grund, 'bereich:', 8) === 0) {
            list(, $pw_min, $pw_max) = explode(':', $pw_grund, 3);
            $pw_beanstandet[] = sprintf(pw_t('EINST.FEHLER_BEREICH'), $pw_bez, $pw_min, $pw_max);
        } elseif ($pw_grund === 'keine_zahl') {
            $pw_beanstandet[] = sprintf(pw_t('EINST.FEHLER_ZAHL'), $pw_bez);
        } else {
            /* "bitte eine Zahl eingeben" fuer ein MQTT-Thema waere eine
             * Meldung, die in die Irre fuehrt. Seit die Liste auch Text-
             * und Wahlfelder enthaelt, braucht sie einen eigenen Satz. */
            $pw_beanstandet[] = sprintf(pw_t('EINST.FEHLER_MUSTER'), $pw_bez);
        }
    }
    foreach (array('sperren_ein', 'sperre_trockenlauf', 'sperre_dauerlauf',
                   'sperre_schaltspiel', 'sperre_ueberlast', 'sperre_kein_anlauf',
                   'sperre_ruht', 'quittung_noetig', 'signal_ein', 'signal_dringend') as $pw_h) {
        $pw_neu[$pw_h] = isset($_POST[$pw_h]) ? 1 : 0;
    }
    /* c1 (Verbesserungsbau 30.09.2026): das SignalBot-Token. Ein leeres Feld
     * heisst "unveraendert" (wie ein Kennwortfeld); es geht nie in die Seite
     * zurueck, auch nicht nach einer Beanstandung (X-2). Geprueft ueber
     * DIESELBE Positivliste wie beim Zurueckspielen (pw_grenzen). */
    if (isset($_POST['signal_token']) && (!is_string($_POST['signal_token']) || trim($_POST['signal_token']) !== '')) {
        list($pw_wert, $pw_grund) = pw_wert_pruefen('signal_token', $_POST['signal_token']);
        if ($pw_grund === '') {
            $pw_neu['signal_token'] = $pw_wert;
        } else {
            $pw_beanstandet[] = sprintf(pw_t('EINST.FEHLER_MUSTER'), pw_t('EINST.L_SIGNAL_TOKEN'));
            $pw_falsch[] = 'signal_token';
        }
    }
    if (!empty($pw_neu['signal_ein']) && !in_array('signal_token', $pw_falsch, true)
        && trim((string) (isset($pw_neu['signal_token']) ? $pw_neu['signal_token'] : '')) === '') {
        $pw_beanstandet[] = pw_t('EINST.FEHLER_SIGNAL_TOKEN');
        $pw_falsch[] = 'signal_token';
    }
    /* Nr. 36 b (Stufe 2): die Ansage. Jede Beanstandung verhindert das Speichern (Nr. 16); kein
     * Sprechtoken steht in einer Meldung, ein leeres Tokenfeld heisst "behalten". */
    $pw_tmangel = array();
    $pw_tbean = array();
    $pw_neu['tts'] = ansage_formular_lesen($_POST, pw_tts($pw_voll), $pw_tmangel, $pw_tbean,
                                           array('modi' => pw_ansage_modi()), pw_ansage_k());
    foreach ($pw_tmangel as $pw_tm) { $pw_beanstandet[] = pw_e($pw_tm['text']); }
    foreach ($pw_tbean as $pw_tb) { $pw_falsch[] = $pw_tb; }
    foreach (pw_ansage_anlaesse() as $pw_ak) { $pw_neu[$pw_ak] = isset($_POST[$pw_ak]) ? 1 : 0; }
    /* B4 (1.0.4): ein Quell-Filter, der die eigenen Themen trifft ('#',
     * '+/+', '<praefix>/#'), liesse den Zuhoerer sich selbst abhoeren. */
    if (!$pw_beanstandet) {
        foreach (pw_quelle_kollisionen(pw_pumpe_zurueck($pw_voll, $pw_neu, $pw_pid)) as $pw_k) {
            $pw_beanstandet[] = sprintf(pw_t('EINST.FEHLER_ECHO'), pw_e($pw_k));
            $pw_falsch[] = 'quelle_topic';
        }
    }
    if ($pw_beanstandet) {
        $pw_fehler = array_merge($pw_fehler, $pw_beanstandet);
        $pw_eingaben = pw_eingaben_sammeln('settings', $pw_falsch, $pw_pid);     // X-2
    } elseif (pw_config_speichern(pw_pumpe_zurueck($pw_voll, $pw_neu, $pw_pid))) {
        $pw_meldungen[] = pw_t('ALLG.GESPEICHERT');
    } else {
        $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config']));
    }
    $pw_tab = 'tab-settings';
}

/* ---------- MQTT speichern (eigener Reiter, eigenes Formular) ---------- */
if ($pw_ist_post && isset($_POST['mqtt_save'])) {
    /* Die volle Form MERKEN und flach weiterarbeiten - der Handler
     * unten liest und schreibt Schluessel ohne Pumpenliste, so wie
     * immer. pw_pumpe_zurueck() legt sie am Ende wieder auseinander. */
    $pw_voll = pw_config();
    $pw_neu = pw_pumpe($pw_voll, $pw_pid);
    $pw_neu['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    /* Geprueft wird ueber DIESELBE Positivliste wie im Einstellungs-
     * Handler und in der Sicherung - eine zweite Wahrheit ueber zulaessige
     * Werte gibt es nicht (pw_grenzen).
     *
     * Bis 0.9.10 stand hier nur pw_mqtt_thema_saeubern(), und das bog still
     * zurecht. Gemessen 31.08.2026 per POST gegen einen echten Webserver:
     *
     *     "Pumpe Keller!!" -> "PumpeKeller"   Meldung "Gespeichert."
     *     "###"            -> "pumpe"         Meldung "Gespeichert."
     *     (leer)           -> "pumpe"         Meldung "Gespeichert."
     *     90 Zeichen       -> auf 64 gekuerzt Meldung "Gespeichert."
     *
     * Das Thema ist die Adresse in Loxone. Ein stiller Wechsel von
     * pumpe_keller auf pumpe macht JEDEN virtuellen Eingang tot, ohne dass
     * eine Zeile darauf hinweist. REGELN_1, Abschnitt 4: Eingaben abweisen,
     * nicht stillschweigend zurechtbiegen.
     *
     * pw_mqtt_thema_saeubern() bleibt trotzdem stehen und wird weiter
     * gebraucht - fuer den Weg ueber eine zurueckgespielte Sicherung und
     * beim Senden selbst. Hier davor steht jetzt die Pruefung. */
    $pw_thema_roh = isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '';
    $pw_alt_praefix = pw_mqtt_thema(pw_pumpe($pw_voll, $pw_pid));
    list($pw_thema_wert, $pw_thema_grund) = pw_wert_pruefen('mqtt_topic', $pw_thema_roh);
    if ($pw_thema_grund !== '') {
        /* O7 (1.0.4): bei einer Beanstandung wird NICHTS gespeichert - auch
         * nicht der Haken. Bis 1.0.3 stand "Gespeichert." neben der
         * Beanstandung, und mqtt_ein war umgestellt (Pruefbericht
         * oberflaeche O7; Regeln/05: speichern nur mit leerer Liste). */
        $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_MUSTER'), pw_t('MQTT.L_TOPIC'));
        $pw_eingaben = pw_eingaben_sammeln('mqtt', array('mqtt_topic'), $pw_pid);     // X-2
    } else {
        $pw_neu['mqtt_topic'] = pw_mqtt_thema_saeubern($pw_thema_wert);
        $pw_neu_voll = pw_pumpe_zurueck($pw_voll, $pw_neu, $pw_pid);
        $pw_kol = pw_quelle_kollisionen($pw_neu_voll);
        if ($pw_kol) {
            foreach ($pw_kol as $pw_k) { $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_ECHO'), pw_e($pw_k)); }
            $pw_eingaben = pw_eingaben_sammeln('mqtt', array('mqtt_topic'), $pw_pid);     // X-2
        } elseif (pw_config_speichern($pw_neu_voll)) {
            $pw_meldungen[] = pw_t('ALLG.GESPEICHERT');
            $pw_neu_praefix = pw_mqtt_thema(pw_pumpe(pw_config(), $pw_pid));
            /* B9 (1.0.4): das alte Praefix wird abgeraeumt - seine retained
             * Themen stuenden sonst fuer immer im Broker. Nur, wenn keine
             * andere Pumpe es noch traegt. */
            if ($pw_neu_praefix !== $pw_alt_praefix
                && !in_array($pw_alt_praefix, pw_eigene_praefixe(pw_config()), true)) {
                list($pw_am, $pw_af) = pw_ui_praefix_leeren($pw_alt_praefix);
                $pw_meldungen = array_merge($pw_meldungen, $pw_am);
                $pw_fehler = array_merge($pw_fehler, $pw_af);
            }
            /* B2 (1.0.4): nach dem Speichern sofort der VOLLE Satz - nach
             * einem Praefixwechsel und nach "MQTT ein" stuenden die Zustaende
             * sonst erst nach einer Aenderung im Broker (Regeln/07). Gesendet
             * werden die Felder aus dem gespeicherten Zustand, ohne ihn zu
             * schreiben: das Lebenszeichen und den Merker fuehrt der naechste
             * Takt fort, und der schickt nach einem Praefixwechsel ohnehin
             * noch einmal den vollen Satz. */
            if (!empty($pw_neu['mqtt_ein'])) {
                $pw_vp = pw_pumpe(pw_config(), $pw_pid);
                list($pw_nv, $pw_nf, $pw_ng) = pw_mqtt_publish(pw_felder(pw_stand($pw_pid), $pw_vp), $pw_vp);
                if ($pw_ng !== null) {
                    $pw_meldungen[] = sprintf(pw_t('MQTT.VOLL_GESENDET'), pw_e($pw_neu_praefix), (int) $pw_nv, (int) $pw_nf);
                }
            }
        } else {
            $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config']));
        }
    }
    $pw_tab = 'tab-mqtt';
}

/* ---------- Neues Aktionstoken ----------
 *
 * Er wird gebraucht, weil die Sicherungsdatei das Token traegt: wer die Datei
 * einmal aus der Hand gegeben hat - an ein Forum, an einen Fehlerbericht -,
 * muss sie entwerten koennen. Bis 0.9.7 ging das nur, indem man die
 * Konfigurationsdatei von Hand aenderte. */
if ($pw_ist_post && isset($_POST['pw_token_neu'])) {
    /* Die volle Form MERKEN und flach weiterarbeiten - der Handler
     * unten liest und schreibt Schluessel ohne Pumpenliste, so wie
     * immer. pw_pumpe_zurueck() legt sie am Ende wieder auseinander. */
    $pw_voll = pw_config();
    $pw_neu = pw_pumpe($pw_voll, $pw_pid);
    $pw_neu['aktionstoken'] = pw_token_erzeugen();
    if (pw_config_speichern(pw_pumpe_zurueck($pw_voll, $pw_neu, $pw_pid))) {
        pw_log('Neues Aktionstoken erzeugt - die bisherigen Adressen in Loxone wirken nicht mehr.');
        $pw_meldungen[] = pw_t('EINST.TOKEN_NEU_ERZEUGT');
    } else {
        $pw_fehler[] = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config']));
    }
    $pw_tab = 'tab-settings';
}

/* ---------- Test-Aktionen ---------- */
if ($pw_ist_post && isset($_POST['selbsttest'])) {
    ob_start();
    list($pw_tn, $pw_tf) = pw_selbsttest(true);
    $pw_testausgabe = ob_get_clean();
    $pw_meldung = ($pw_tf === 0) ? sprintf(pw_t('TEST.SELBSTTEST_OK'), $pw_tn)
                                 : sprintf(pw_t('TEST.SELBSTTEST_FEHL'), $pw_tf, $pw_tn);
    if ($pw_tf === 0) { $pw_meldungen[] = $pw_meldung; } else { $pw_fehler[] = $pw_meldung; }
    $pw_tab = 'tab-test';
}
if ($pw_ist_post && isset($_POST['testwert'])) {
    $pw_roh = (isset($_POST['watt']) && is_string($_POST['watt'])) ? trim($_POST['watt']) : '';
    /* C7 (1.0.4): unter -5 W, NaN und unendlich ist keine Messung. */
    if ($pw_roh === '' || !is_numeric(str_replace(',', '.', $pw_roh)) || !pw_watt_gueltig($pw_roh)) {
        $pw_fehler[] = pw_t('TEST.FEHLER_WATT');
        $pw_eingaben = pw_eingaben_sammeln('test', array('watt'), $pw_pid);     // X-2
    } else {
        $pw_wattwert = (float) str_replace(',', '.', $pw_roh);
        list($pw_ns, $pw_grund, $pw_v, $pw_fl) =
            pw_verarbeiten($pw_wattwert, $pw_cfg, time(), 'test');
        if ($pw_ns === null) {
            $pw_fehler[] = ($pw_grund === 'belegt')
                ? pw_t('TEST.FEHLER_BELEGT') : pw_t('TEST.FEHLER_SPEICHERN');
        } else {
            $pw_schl = pw_befund_schluessel();
            $pw_bz = pw_befund_zahl($pw_ns['befund']);
            /* Der Befund UEBERSETZT. Bis 0.9.7 stand hier die Kennung
             * "trockenlauf" statt "Trockenlauf" - ueberall sonst ging sie
             * durch pw_t(). */
            $pw_meldungen[] = sprintf(pw_t('TEST.WERT_OK'), pw_e($pw_roh),
                pw_e(pw_t(isset($pw_schl[$pw_bz]) ? $pw_schl[$pw_bz] : 'BEFUND.STILL')),
                (int) $pw_v, (int) $pw_fl);
        }
    }
    $pw_tab = 'tab-test';
}
if ($pw_ist_post && isset($_POST['ansage_test'])) {
    /* Nr. 36 b: die Testansage. Ins Protokoll nur die Kurzform ohne Text und Token. */
    $pw_ak = pw_ansage_k();
    $pw_ar = ansage_testansage(pw_tts(pw_config()), $pw_ak);
    pw_log('Testansage: ' . ansage_kurz($pw_ar));
    if ($pw_ar['stand'] === 1) {
        $pw_meldungen[] = pw_e(pw_t('DURCHSAGE.M_TEST_OK'));
    } elseif ($pw_ar['stand'] === -1) {
        $pw_meldungen[] = sprintf(pw_e(pw_t('DURCHSAGE.M_TEST_NICHTS')), pw_e(ansage_kennung_text($pw_ar['kennung'], $pw_ak)));
    } else {
        $pw_fehler[] = sprintf(pw_e(pw_t('DURCHSAGE.M_TEST_FEHL')), pw_e(ansage_kennung_text($pw_ar['kennung'], $pw_ak)));
    }
    $pw_tab = 'tab-test';
}
if ($pw_ist_post && isset($_POST['quittieren'])) {
    list($pw_ns, $pw_grund) = pw_zustand_aendern('pw_quittieren', $pw_cfg);
    if ($pw_ns !== null) {
        pw_log('Sperre quittiert (Oberflaeche).');
        $pw_meldungen[] = pw_t('TEST.QUITTIERT');
    } else {
        $pw_fehler[] = pw_t($pw_grund === 'belegt' ? 'TEST.FEHLER_BELEGT' : 'TEST.FEHLER_SPEICHERN');
    }
    $pw_tab = 'tab-test';
}
if ($pw_ist_post && isset($_POST['wartung_reset'])) {
    if (pw_wartung_setzen($pw_cfg)) {
        pw_log('Wartung vermerkt.');
        $pw_meldungen[] = pw_t('BILANZ.WARTUNG_OK');
    } else {
        $pw_fehler[] = pw_t('TEST.FEHLER_SPEICHERN');
    }
    $pw_tab = 'tab-bilanz';
}
if ($pw_ist_post && isset($_POST['log_leeren'])) {
    /* O9 (1.0.4): die Erfolgsmeldung nur, wenn wirklich geschrieben wurde.
     * Bis 1.0.3 stand "Das Protokoll wurde geleert." auch dann, wenn an
     * der Stelle der Datei ein Ordner lag (Pruefbericht oberflaeche O9). */
    $pw_lz = '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n";
    $pw_lr = is_dir(pw_paths()['log']) ? false : @file_put_contents(pw_paths()['log'], $pw_lz);
    if ($pw_lr === strlen($pw_lz)) {
        $pw_meldungen[] = pw_t('LOG.GELEERT');
    } else {
        $pw_fehler[] = sprintf(pw_t('LOG.FEHLER_LEEREN'), pw_e(pw_paths()['log']));
    }
    $pw_tab = 'tab-log';
}

/* ==================================================================
 * JEDER POST ENDET MIT EINER UMLEITUNG (O1, 1.0.4)
 * ==================================================================
 *
 * Regeln/04: header('Location: index.php?form=...', true, 303) und exit;
 * das Ergebnis reist als Einmalmeldung (Datenordner, 0600, 120 s, nur
 * beim GET gelesen und dabei geloescht). Bis 1.0.3 lieferte jeder POST
 * die Seite direkt: F5 wuerfelte ein neues Aktionstoken, legte eine
 * Pumpe doppelt an und zaehlte einen Testwert doppelt (Pruefbericht
 * oberflaeche O1, Bauart D). Die Downloads (Vorlage, Sicherung) sind
 * oben schon mit exit fertig. Auch die Abweisung durch den Wachposten
 * geht diesen Weg. */
if ($pw_ist_post) {
    if (!pw_meldung_ablegen(array('meldungen' => $pw_meldungen, 'fehler' => $pw_fehler,
                                  'testausgabe' => $pw_testausgabe,
                                  'eingaben' => $pw_eingaben))) {
        pw_log('Die Einmalmeldung liess sich nicht schreiben - das Ergebnis des letzten '
               . 'Knopfdrucks ist nach der Umleitung nicht zu sehen.');
    }
    $pw_ziel_ids = pw_pumpe_ids(pw_config());
    $pw_ziel = in_array($pw_pid, $pw_ziel_ids, true) ? $pw_pid : ($pw_ziel_ids ? $pw_ziel_ids[0] : '');
    header('Location: index.php?form=' . substr($pw_tab, 4)
           . ($pw_ziel !== '' ? '&pumpe=' . rawurlencode($pw_ziel) : ''), true, 303);
    exit;
}
$pw_einmal = pw_meldung_abholen();
if ($pw_einmal !== null) {
    foreach (array('meldungen', 'fehler') as $pw_ek) {
        foreach (isset($pw_einmal[$pw_ek]) && is_array($pw_einmal[$pw_ek]) ? $pw_einmal[$pw_ek] : array() as $pw_ez) {
            if (!is_string($pw_ez)) { continue; }
            if ($pw_ek === 'meldungen') { $pw_meldungen[] = $pw_ez; } else { $pw_fehler[] = $pw_ez; }
        }
    }
    if (isset($pw_einmal['testausgabe']) && is_string($pw_einmal['testausgabe'])) {
        $pw_testausgabe = $pw_einmal['testausgabe'];
    }
    /* X-2: nach einer Beanstandung fuellt DIESER GET das Formular aus den
     * eingetippten Werten. Die Einmalmeldung ist schon geloescht - der
     * naechste GET zeigt wieder die gespeicherten. */
    if (isset($pw_einmal['eingaben']) && is_array($pw_einmal['eingaben'])
        && isset($pw_einmal['eingaben']['form']) && is_string($pw_einmal['eingaben']['form'])) {
        $pw_eingaben = $pw_einmal['eingaben'];
    }
}

/* ==================================================================
 * ERST JETZT die Anzeigewerte bilden - nach ALLEN Handlern
 * ================================================================== */
/* Neu lesen: die Handler haben womoeglich geschrieben, und die Liste der
 * Pumpen kann sich dabei geaendert haben (hinzugefuegt, entfernt). Deshalb
 * wird auch die Wahl noch einmal geprueft - eine soeben entfernte Pumpe darf
 * die Anzeige nicht auf einen Unterbaum richten, den es nicht mehr gibt. */
$pw_voll = pw_config();
$pw_alle_ids = pw_pumpe_ids($pw_voll);
if (!in_array($pw_pid, $pw_alle_ids, true)) {
    $pw_pid = $pw_alle_ids ? $pw_alle_ids[0] : '';
}
$pw_cfg = pw_pumpe($pw_voll, $pw_pid);
$pw_fmt = pw_formtoken($pw_cfg);
$pw_stand = pw_stand($pw_pid);
$pw_felder = pw_felder($pw_stand, $pw_cfg);
$pw_m = pw_mqtt_gateway_info();
$pw_p = pw_paths();
$pw_topic = pw_mqtt_thema($pw_cfg);
$pw_endpunkt = pw_e(pw_endpunkt($pw_cfg));
$pw_modelle = pw_modelle();
$pw_befunde = pw_befund_schluessel();
$pw_takt = pw_takt($pw_stand);
$pw_wart = pw_wartung($pw_stand);
$pw_tage = pw_tage(14, $pw_pid);
$pw_abolage = pw_abo_lage();
$pw_frame = class_exists('LBWeb', false);

/* Der Zusatz, den JEDER Verweis und JEDES Formular traegt.
 *
 * $pw_q gehoert in ein Attribut - deshalb steht dort &amp;, nicht &.
 * $pw_qf ist die versteckte Zeile; sie wird FERTIG gebaut, damit an den
 * vierzehn Fundstellen nichts zu vergessen ist. */
$pw_q = '&amp;pumpe=' . rawurlencode($pw_pid);
$pw_qf = '<input data-role="none" type="hidden" name="pumpe" value="'
       . pw_e($pw_pid) . '">';

if ($pw_frame) { LBWeb::lbheader(pw_t('ALLG.TITEL') . ' ' . pw_fassung(), 'https://wiki.loxberry.de/', 'help.html'); }

?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-pumpen { display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
              margin: 10px 0 0; padding: 8px 10px; background: #f2f6ec;
              border: 1px solid #d8e4c8; border-radius: 8px; }
.sm-kennung { color: #777; font-size: 0.85em; }
.sm-ueberblick td a { text-decoration: none; font-weight: 600; }
.sm-pumpen-titel { font-weight: 600; font-size: 0.9em; color: #4f7d17; margin-right: 4px; }
.sm-pumpen-form { display: inline; margin: 0; }
.sm-pumpe { background: #fff; border: 1px solid #c6d5b0; border-radius: 6px;
            padding: 5px 12px; font-size: 0.92em; color: #444 !important;
            text-decoration: none; display: inline-block; }
.sm-pumpe.sm-active { background: #6dac20; color: #fff !important;
                      border-color: #6dac20; font-weight: 600; }
.sm-pumpe-knopf { padding: 5px 10px !important; font-size: 0.85em !important; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Mit data-role="none"
   nimmt jQuery Mobile den Pfeil weg, und uebrig bleibt ein Kasten, der wie
   ein Textfeld aussieht - am Geraet gemeldet, der Anwender hat die Vorlagen
   dahinter nicht gefunden. Die Raute im SVG wird als %23 geschrieben: eine
   rohe Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* sm-auswahl kennzeichnet ein Auswahlfeld als solches. Sie traegt heute keine
   eigene Gestaltung ueber die Regel darueber hinaus - sie steht da, damit die
   Auswahlfelder auffindbar sind und eine spaetere Aenderung genau sie trifft
   und nicht jedes select der Seite. Eine benutzte Klasse, die nirgends
   definiert ist, meldet hausstandard_pruefen.py als Zusatzzeile - und genau
   so ist einmal die einzige Warnung eines Plugins als nackter Fliesstext
   dagestanden. */
.sm-wrap .sm-auswahl { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Eine breite Tabelle bekommt ihren eigenen Rollbereich, damit die SEITE
   nicht waagerecht rollt. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-strich { color: #777; font-weight: 700; }
/* Ergaenzung (Verbesserungsbau 30.09.2026, nicht aus der Vorlage): X-2 markiert
   ein beanstandetes Feld. */
.sm-wrap input.sm-beanstandet, .sm-wrap select.sm-beanstandet { border: 2px solid #b00000 !important; background: #fff5f4 !important; }
</style>
<div class="sm-wrap">
<h1 style="font-size:1.4em;margin:10px 0 0;"><?= pw_e(pw_t('ALLG.TITEL')) ?> <span style="font-size:0.62em;color:#777;font-weight:400;"><?= pw_e(pw_fassung()) ?></span></h1>

<?php if (pw_sprache_fehlt()) { ?><div class="sm-warnung"><?= pw_sprache_notfall() ?></div><?php } ?>
<?php /* Die Meldungen kommen seit 1.0.4 aus der Einmalmeldung und sind
   Klartext - deshalb maskiert (Regeln/04). */ ?>
<?php foreach ($pw_meldungen as $pw_z) { ?><div class="sm-hinweis"><?= pw_e($pw_z) ?></div><?php } ?>
<?php if ($pw_fehler) { ?><div class="sm-warnung"><b><?= pw_t('ALLG.FEHLER') ?></b><ul style="margin:6px 0 0 18px;padding:0;"><?php
    foreach ($pw_fehler as $pw_z) { echo '<li>' . pw_e($pw_z) . '</li>'; } ?></ul></div><?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= pw_t('KOPF.PUMPE') ?><b class="<?= $pw_felder['laeuft'] === 1 ? 'sm-an' : ($pw_felder['laeuft'] === 0 ? '' : 'sm-aus') ?>"><?=
      $pw_felder['laeuft'] === 1 ? pw_t('KOPF.LAEUFT') : ($pw_felder['laeuft'] === 0 ? pw_t('KOPF.STEHT') : pw_t('KOPF.UNBEKANNT')) ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.BEFUND') ?><b><?= pw_e(pw_t($pw_befunde[$pw_felder['befund']])) ?><?php
      if ($pw_felder['befund'] !== 0 && $pw_felder['befund'] !== 5 && $pw_felder['beiwert'] >= 0) {
          echo ' <span style="font-size:0.62em;color:#777;">(' . pw_e($pw_felder['beiwert']) . ')</span>';
      } ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.SPERRE') ?><b class="<?= $pw_felder['sperre'] ? 'sm-aus' : 'sm-an' ?>"><?= $pw_felder['sperre'] ? pw_t('ALLG.EIN') : pw_t('ALLG.AUS') ?><?php
      if ($pw_felder['sperre'] && $pw_felder['sperrgrund'] > 0) {
          echo ' <span style="font-size:0.62em;color:#777;">' . pw_e(pw_t($pw_befunde[$pw_felder['sperrgrund']])) . '</span>';
      } ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.LEISTUNG') ?><b><?= $pw_felder['watt'] >= 0 ? pw_e($pw_felder['watt']) . ' W' : '&mdash;' ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.STARTS_HEUTE') ?><b><?= (int) $pw_felder['starts_tag'] ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.BETRIEB') ?><b><?= pw_e($pw_wart['gesamt_h']) ?> h</b></div>
</div>

<?php
/* Die Kachel sagt "unbekannt" - hier steht, WORAN es liegt.
 *
 * Am 29.08.2026 hat jemand aus "unbekannt" auf einen Fehler in der
 * Modellauswahl geschlossen. Die Seite sagte, WAS ist, aber nicht, warum;
 * und die naechstliegende Erklaerung war die falsche.
 *
 * Kein Warnkasten, sondern ein Hinweis: bei frischer Einrichtung ist
 * "es ist noch kein Messwert da" der RICHTIGE Zustand, kein Fehler.
 *
 * pw_unbekannt_grund() wird nur in dieser Lage gerufen - das command -v
 * fuer mosquitto_sub kostet dann einmal je Seitenaufbau, und nur dort,
 * wo die Antwort zaehlt. */
if ($pw_felder['laeuft'] === -1) {
    /* KEIN pw_e() um pw_t(): die Texte tragen Auszeichnung und typografische
     * Anfuehrungszeichen als Entitaeten. Maskiert man sie noch einmal, steht
     * woertlich '&bdquo;unbekannt&ldquo;' auf dem Schirm. Gefunden von
     * Werkzeuge/hausstandard_pruefen.py, Spalte msk. */
    list($pw_gk, $pw_gt) = pw_unbekannt_grund($pw_cfg, $pw_stand);
    if ($pw_gk !== '') {
        echo '<div class="sm-hinweis"><b>' . pw_t('GRUND.WARUM') . '</b> '
           . $pw_gt . '</div>';
    }
}
?>

<?php /* DIE PUMPENWAHL steht UEBER den Reitern: sie gilt fuer alle sechs.
   Ein Umschalten ist ein VERWEIS, kein Formular - es aendert nichts, es
   sieht nur woanders hin; deshalb braucht es kein Merkmal und darf im
   Verlauf des Browsers stehen. */ ?>
<div class="sm-pumpen">
  <span class="sm-pumpen-titel"><?= pw_e(pw_t('PUMPE.WAHL')) ?></span>
<?php foreach ($pw_alle_ids as $pw_i): $pw_pp = pw_pumpe($pw_voll, $pw_i);
      /* Eine namenlose Pumpe zeigt ihre Kennung. Ein leerer Knopf waere
         nicht anklickbar und die Pumpe damit unerreichbar. */
      $pw_nm = trim((string) $pw_pp['name']) !== '' ? $pw_pp['name'] : $pw_i; ?>
  <a data-role="none" class="sm-pumpe<?= $pw_i === $pw_pid ? ' sm-active' : '' ?>" href="index.php?form=<?= pw_e(substr($pw_tab, 4)) ?>&amp;pumpe=<?= rawurlencode($pw_i) ?>"><?= pw_e($pw_nm) ?></a>
<?php endforeach; ?>
  <form action="index.php" method="post" class="sm-pumpen-form">
    <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion sm-pumpe-knopf" type="submit" name="pumpe_neu" value="1"><?= pw_e(pw_t('PUMPE.K_NEU')) ?></button>
  </form>
<?php if (count($pw_alle_ids) > 1): ?>
  <form action="index.php" method="post" class="sm-pumpen-form">
    <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.85em;"><input data-role="none" type="checkbox" name="bestaetigt" value="1"> <?= pw_e(sprintf(pw_t('PUMPE.WEG_BESTAETIGEN'), pw_pumpe_name($pw_cfg))) ?></label>
    <button data-role="none" class="sm-btn sm-b-aktion sm-pumpe-knopf" type="submit" name="pumpe_weg" value="1"><?= pw_e(pw_t('PUMPE.K_WEG')) ?></button>
  </form>
<?php endif; ?>
</div>

<div class="sm-tabs">
	<a data-role="none" class="sm-tab<?= $pw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings<?= $pw_q ?>"><?= pw_e(pw_t('REITER.EINSTELLUNGEN')) ?></a>
	<a data-role="none" class="sm-tab<?= $pw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt<?= $pw_q ?>">MQTT</a>
	<a data-role="none" class="sm-tab<?= $pw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone" href="index.php?form=loxone<?= $pw_q ?>"><?= pw_e(pw_t('REITER.LOXONE')) ?></a>
	<a data-role="none" class="sm-tab<?= $pw_tab === 'tab-bilanz' ? ' sm-active' : '' ?>" data-ziel="tab-bilanz" href="index.php?form=bilanz<?= $pw_q ?>"><?= pw_e(pw_t('REITER.BILANZ')) ?></a>
	<a data-role="none" class="sm-tab<?= $pw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test" href="index.php?form=test<?= $pw_q ?>"><?= pw_e(pw_t('REITER.TEST')) ?></a>
	<a data-role="none" class="sm-tab<?= $pw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log" href="index.php?form=log<?= $pw_q ?>"><?= pw_e(pw_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= pw_t('EINST.WAS_IST_DAS') ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= pw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span>
</div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= pw_e(pw_t('PUMPE.H_PUMPE')) ?></h2>
<div class="sm-feld">
  <label><?= pw_e(pw_t('PUMPE.L_NAME')) ?></label>
  <input data-role="none" type="text" name="name" value="<?= pw_e(pw_fw('settings', 'name', $pw_cfg['name'])) ?>"<?= pw_fk('settings', 'name') ?> placeholder="<?= pw_e($pw_cfg['id']) ?>">
  <div class="sm-hilfe"><?= pw_t('PUMPE.H_NAME') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('PUMPE.L_ART')) ?></label>
  <select data-role="none" class="sm-auswahl<?= pw_fk('settings', 'art', true) ?>" name="art"<?= pw_fa('settings', 'art') ?>>
<?php foreach (pw_arten() as $pw_ak => $pw_av): ?>
    <option value="<?= pw_e($pw_ak) ?>"<?= pw_fw('settings', 'art', pw_art($pw_cfg)) === $pw_ak ? ' selected' : '' ?>><?= pw_e(pw_t($pw_av['name'])) ?></option>
<?php endforeach; ?>
  </select>
  <div class="sm-hilfe"><?= pw_t('PUMPE.H_ART') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('PUMPE.L_RUHT_S')) ?></label>
  <input data-role="none" type="text" name="ruht_s" value="<?= pw_e(pw_fw('settings', 'ruht_s', $pw_cfg['ruht_s'])) ?>"<?= pw_fk('settings', 'ruht_s') ?>>
  <div class="sm-hilfe"><?= pw_t('PUMPE.H_RUHT_S') ?></div>
</div>
<div class="sm-feld sm-mono sm-kennung"><?= pw_e(pw_t('PUMPE.L_ID')) ?>: <?= pw_e($pw_cfg['id']) ?></div>

<h2><?= pw_e(pw_t('EINST.H_MODELL')) ?></h2>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_MODELL')) ?></label>
  <select data-role="none" class="sm-auswahl<?= pw_fk('settings', 'modell', true) ?>" name="modell"<?= pw_fa('settings', 'modell') ?> id="pw_modell" onchange="pwModell()">
<?php foreach ($pw_modelle as $pw_mk => $pw_mv) { ?>
    <option value="<?= pw_e($pw_mk) ?>" data-p1="<?= (int) $pw_mv['p1'] ?>" data-starts="<?= (int) $pw_mv['starts_h'] ?>"<?= pw_fw('settings', 'modell', $pw_cfg['modell']) === $pw_mk ? ' selected' : '' ?>><?= pw_e(pw_t($pw_mv['name'])) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= pw_t('EINST.H_MODELL_TEXT') ?></div>
</div>

<h2><?= pw_e(pw_t('EINST.H_QUELLE')) ?></h2>
<div class="sm-hinweis"><?= pw_t('EINST.H_QUELLE_TEXT') ?></div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_QUELLE')) ?></label>
  <select data-role="none" class="sm-auswahl<?= pw_fk('settings', 'quelle', true) ?>" name="quelle"<?= pw_fa('settings', 'quelle') ?>>
    <option value="loxone"<?= pw_fw('settings', 'quelle', $pw_cfg['quelle']) !== 'mqtt' ? ' selected' : '' ?>><?= pw_e(pw_t('EINST.Q_LOXONE')) ?></option>
    <option value="mqtt"<?= pw_fw('settings', 'quelle', $pw_cfg['quelle']) === 'mqtt' ? ' selected' : '' ?>><?= pw_e(pw_t('EINST.Q_MQTT')) ?></option>
  </select>
  <div class="sm-hilfe"><?= pw_t('EINST.H_QUELLE_WAHL') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_QUELLE_TOPIC')) ?></label>
  <input data-role="none" type="text" name="quelle_topic" value="<?= pw_e(pw_fw('settings', 'quelle_topic', $pw_cfg['quelle_topic'])) ?>"<?= pw_fk('settings', 'quelle_topic') ?> placeholder="shelly1pmg4-Pumpensumpf/#">
  <div class="sm-hilfe"><?= pw_t('EINST.H_QUELLE_TOPIC') ?></div>
</div>
<?php if ($pw_cfg['quelle'] === 'mqtt' && !pw_hat_mosquitto()) { ?>
<div class="sm-warnung"><?= pw_t('EINST.W_KEIN_MOSQUITTO') ?></div>
<?php } ?>

<h2><?= pw_e(pw_t('EINST.H_SCHWELLEN')) ?></h2>
<?php
/* Die Beschriftungen sind AUSGESCHRIEBEN, nicht aus dem Feldnamen gerechnet.
 * pw_t('EINST.L_' . strtoupper($f)) findet der Sprachpruefer nicht - er
 * meldete deshalb bis 0.9.7 sechzehn benutzte Schluessel als "unbenutzt",
 * und der naechste Leser sucht dort. */
$pw_zahlfelder = array(
    array('an_w',        'EINST.L_AN_W',        'EINST.H_AN_W'),
    array('trocken_w',   'EINST.L_TROCKEN_W',   'EINST.H_TROCKEN_W'),
    array('trocken_s',   'EINST.L_TROCKEN_S',   'EINST.H_TROCKEN_S'),
    array('ueberlast_w', 'EINST.L_UEBERLAST_W', 'EINST.H_UEBERLAST_W'),
    array('dauerlauf_s', 'EINST.L_DAUERLAUF_S', 'EINST.H_DAUERLAUF_S'),
    array('starts_h',    'EINST.L_STARTS_H',    'EINST.H_STARTS_H'),
    array('anlauf_s',    'EINST.L_ANLAUF_S',    'EINST.H_ANLAUF_S'),
    array('stale_s',     'EINST.L_STALE_S',     'EINST.H_STALE_S'),
);
foreach ($pw_zahlfelder as $pw_zf): ?>
<div class="sm-feld">
  <label><?= pw_e(pw_t($pw_zf[1])) ?></label>
  <input data-role="none" type="text" name="<?= pw_e($pw_zf[0]) ?>" value="<?= pw_e(pw_fw('settings', $pw_zf[0], $pw_cfg[$pw_zf[0]])) ?>"<?= pw_fk('settings', $pw_zf[0]) ?>>
  <div class="sm-hilfe"><?= pw_t($pw_zf[2]) ?></div>
</div>
<?php endforeach; ?>

<h2><?= pw_e(pw_t('EINST.H_SPERREN')) ?></h2>
<div class="sm-warnung"><?= pw_t('EINST.SPERREN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="sperren_ein" value="1" <?= pw_fh('settings', 'sperren_ein', !empty($pw_cfg['sperren_ein'])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_SPERREN_EIN')) ?>
  </label>
</div>
<?php
$pw_sperrfelder = array(
    array('sperre_trockenlauf', 'EINST.L_SPERRE_TROCKENLAUF'),
    array('sperre_dauerlauf',   'EINST.L_SPERRE_DAUERLAUF'),
    array('sperre_schaltspiel', 'EINST.L_SPERRE_SCHALTSPIEL'),
    array('sperre_ueberlast',   'EINST.L_SPERRE_UEBERLAST'),
    array('sperre_kein_anlauf', 'EINST.L_SPERRE_KEIN_ANLAUF'),
    /* Sie wirkt nur bei einer Pumpe, die ueberhaupt eine Ruhefrist hat -
     * und gesperrt wird ohnehin nur bei einem Hauswasserwerk. Der Haken
     * steht trotzdem immer da: ein Feld, das je nach Nachbarfeld
     * verschwindet, laesst den Anwender raten, ob er es vergessen hat. */
    array('sperre_ruht',        'EINST.L_SPERRE_RUHT'),
);
foreach ($pw_sperrfelder as $pw_sf): ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="<?= pw_e($pw_sf[0]) ?>" value="1" <?= pw_fh('settings', $pw_sf[0], !empty($pw_cfg[$pw_sf[0]])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t($pw_sf[1])) ?>
  </label>
</div>
<?php endforeach; ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="quittung_noetig" value="1" <?= pw_fh('settings', 'quittung_noetig', !empty($pw_cfg['quittung_noetig'])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_QUITTUNG')) ?>
  </label>
  <div class="sm-hilfe"><?= pw_t('EINST.H_QUITTUNG') ?></div>
</div>

<?php /* c1 (Verbesserungsbau 30.09.2026): Alarm zusaetzlich ueber SignalBot,
   ab Werk aus. Die Felder gelten fuer das ganze Plugin, nicht fuer die
   gewaehlte Pumpe; die Nachricht nennt die Pumpe. Das Token steht nie im
   HTML - ein leeres Feld heisst "unveraendert". */ ?>
<h2><?= pw_e(pw_t('EINST.H_SIGNAL')) ?></h2>
<div class="sm-hinweis"><?= pw_t('EINST.SIGNAL_TEXT') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="signal_ein" value="1" <?= pw_fh('settings', 'signal_ein', !empty($pw_cfg['signal_ein'])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_SIGNAL_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_SIGNAL_ORDNER')) ?></label>
  <input data-role="none" type="text" name="signal_ordner" value="<?= pw_e(pw_fw('settings', 'signal_ordner', $pw_cfg['signal_ordner'])) ?>"<?= pw_fk('settings', 'signal_ordner') ?> placeholder="signalbot">
  <div class="sm-hilfe"><?= pw_t('EINST.H_SIGNAL_ORDNER') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_SIGNAL_TOKEN')) ?></label>
  <input data-role="none" type="password" name="signal_token" value="" autocomplete="off"<?= pw_fk('settings', 'signal_token') ?> placeholder="<?= trim((string) $pw_cfg['signal_token']) !== '' ? pw_e(pw_t('EINST.PH_UNVERAENDERT')) : '' ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_SIGNAL_TOKEN') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_SIGNAL_AN')) ?></label>
  <input data-role="none" type="text" name="signal_an" value="<?= pw_e(pw_fw('settings', 'signal_an', $pw_cfg['signal_an'])) ?>"<?= pw_fk('settings', 'signal_an') ?> placeholder="+49...">
  <div class="sm-hilfe"><?= pw_t('EINST.H_SIGNAL_AN') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="signal_dringend" value="1" <?= pw_fh('settings', 'signal_dringend', !empty($pw_cfg['signal_dringend'])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_SIGNAL_DRINGEND')) ?>
  </label>
  <div class="sm-hilfe"><?= pw_t('EINST.H_SIGNAL_DRINGEND') ?></div>
</div>

<?php /* Nr. 36 b (Stufe 2): Ansage bei Alarm ueber die gemeinsame Sprachausgabe, ab Werk aus. Die
   Felder gelten fuer das ganze Plugin; der Satz nennt die Pumpe. */ ?>
<h2><?= pw_e(pw_t('DURCHSAGE.H')) ?></h2>
<div class="sm-hinweis"><?= pw_e(pw_t('DURCHSAGE.TEXT_HILFE')) ?></div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('DURCHSAGE.L_ANLAESSE')) ?></label>
<?php $pw_bs = pw_befund_schluessel(); foreach (pw_ansage_anlaesse() as $pw_ab => $pw_ak) { ?>
  <label style="display:inline-flex;align-items:center;gap:8px;margin-right:14px;">
    <input data-role="none" type="checkbox" name="<?= pw_e($pw_ak) ?>" value="1" <?= pw_fh('settings', $pw_ak, !empty($pw_cfg[$pw_ak])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t($pw_bs[pw_befund_zahl($pw_ab)])) ?>
  </label>
<?php } ?>
  <div class="sm-hilfe"><?= pw_e(pw_t('DURCHSAGE.H_ANLAESSE')) ?></div>
</div>
<?= ansage_formular_html(pw_tts(pw_config()), array(
    'w' => function ($n, $g) { return pw_fw('settings', $n, $g); },
    'm' => function ($n) { return pw_fk('settings', $n); },
    'c' => function ($n, $g) { return pw_fh('settings', $n, $g); },
    'modi' => pw_ansage_modi()), pw_ansage_k()) ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= pw_e(pw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= pw_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= pw_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= pw_t('EINST.SICH_WARNUNG') ?></div>
<?php
/* X-3 (Verbesserungsbau 30.09.2026): besteht die eigene Sicherung das eigene
 * Zurueckspielen? Dieselbe Pruefung wie beim Zurueckspielen
 * (pw_sicherung_selbstpruefung()). Eine Warnung - der Knopf liefert die
 * Datei trotzdem. Die Beanstandungen kommen aus pw_sicherung_lesen() mit
 * maskierten Teilen; sie werden wie im Rueckspielweg erst entschluesselt
 * und dann EINMAL maskiert ausgegeben. */
$pw_sich_mangel = pw_sicherung_selbstpruefung();
if ($pw_sich_mangel) { ?>
<div class="sm-warnung"><b><?= pw_e(pw_t('EINST.SICH_SELBST_WARN')) ?></b><ul style="margin:6px 0 0 18px;padding:0;"><?php
    foreach ($pw_sich_mangel as $pw_z) { echo '<li>' . pw_e(html_entity_decode(strip_tags((string) $pw_z), ENT_QUOTES, 'UTF-8')) . '</li>'; } ?></ul></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="pw_sichern" value="1"><?= pw_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="pw_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="pw_zurueck" value="1"><?= pw_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>

<h2><?= pw_t('EINST.H_TOKEN') ?></h2>
<div class="sm-hinweis"><?= pw_t('EINST.TOKEN_TEXT') ?></div>
<div class="sm-feld"><span class="sm-mono"><?= pw_e($pw_cfg['aktionstoken']) ?></span></div>
<div class="sm-warnung"><?= pw_t('EINST.TOKEN_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="pw_token_neu" value="1"><?= pw_t('EINST.K_TOKEN_NEU') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span></div>
<h2>MQTT</h2>
<?php if ($pw_m['gefunden'] && !$pw_m['autostart']) { ?><div class="sm-warnung"><b>MQTT:</b> <?= pw_t('MQTT.W_AUTOSTART') ?></div><?php } ?>
<?php if (!$pw_m['gefunden']) { ?><div class="sm-warnung"><b>MQTT:</b> <?= pw_t('MQTT.W_UNBEKANNT') ?></div><?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= pw_fh('mqtt', 'mqtt_ein', !empty($pw_cfg['mqtt_ein'])) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('MQTT.L_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('MQTT.L_TOPIC')) ?></label>
  <input data-role="none" type="text" name="mqtt_topic" value="<?= pw_e(pw_fw('mqtt', 'mqtt_topic', $pw_cfg['mqtt_topic'])) ?>"<?= pw_fk('mqtt', 'mqtt_topic') ?> placeholder="pumpe">
  <div class="sm-hilfe"><?= pw_t('MQTT.H_TOPIC') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= pw_e(pw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= pw_e(pw_t('MQTT.H_ZUSTAND')) ?></h2>
<table class="sm-tbl">
<tr><td><?= pw_e(pw_t('MQTT.T_GEFUNDEN')) ?></td><td class="<?= $pw_m['gefunden'] ? 'sm-an' : 'sm-strich' ?>"><?= $pw_m['gefunden'] ? pw_e(pw_t('WORT.JA')) : '&ndash;' ?></td></tr>
<tr><td><?= pw_e(pw_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $pw_m['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $pw_m['autostart'] ? pw_e(pw_t('ALLG.EIN')) : pw_e(pw_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= pw_e(pw_t('MQTT.T_FASSUNG')) ?></td><td><span class="sm-mono"><?= $pw_m['fassung'] > 0 ? 'V' . (int) $pw_m['fassung'] : '?' ?></span></td></tr>
<tr><td><?= pw_e(pw_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $pw_m['udpport'] ?></span></td></tr>
<tr><td><?= pw_e(pw_t('MQTT.T_THEMA')) ?></td><td><span class="sm-mono"><?= pw_e($pw_topic) ?>/#</span></td></tr>
</table>
<div class="sm-hilfe"><?= pw_t('MQTT.H_LEBENSZEICHEN') ?></div>

<?php /* Abo und Themenliste stehen auch hier, nicht nur im Loxone-Reiter:
   REGELN_2, "Die fuenf Reiter" - der Reiter MQTT traegt alle MQTT-Belange.
   Beide kommen aus derselben Quelle wie dort (pw_abo_text, pw_felderliste),
   es gibt also keine zweite Wahrheit, nur eine zweite Ausgabestelle. */ ?>
<h2><?= pw_abo_titel() ?></h2>
<div class="sm-hinweis"><?= pw_abo_text() ?></div>
<?php if ($pw_abolage !== 'v2') { ?>
<pre class="sm-pre"><?= pw_e($pw_topic) ?>/#</pre>
<?php } ?>

<h2><?= pw_e(pw_t('MQTT.H_THEMEN')) ?></h2>
<div class="sm-hilfe"><?= pw_t('MQTT.H_THEMEN_TEXT') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= pw_t('MQTT.SP_THEMA') ?></th><th style="width:12%"><?= pw_t('LOX.SP_EINHEIT') ?></th><th style="width:20%"><?= pw_t('MQTT.SP_RETAIN') ?></th><th style="width:38%"><?= pw_t('LOX.SP_BEDEUTUNG') ?></th></tr>
<?php foreach (array_merge(pw_felderliste(), pw_statusliste()) as $pw_mk => $pw_mr): ?>
<tr><td class="sm-mono"><?= pw_e($pw_topic . '/' . $pw_mk) ?></td><td><?= pw_e($pw_mr['einheit']) ?></td><td><?= in_array($pw_mk, pw_retain_liste(), true) ? pw_t('WORT.JA') : pw_t('WORT.NEIN') ?></td><td><?= pw_t($pw_mr['bed']) ?></td></tr>
<?php endforeach; ?>
</table>
</div>
<?php /* Die Spalte Retain wird nicht behauptet, sondern aus DERSELBEN
   Liste gezaehlt, nach der gesendet wird (pw_retain_liste(), B1 1.0.4,
   Entscheidung 3). Bis 1.0.3 waren es 0 von allen. */ ?>
<div class="sm-hilfe"><?= sprintf(pw_t('MQTT.H_RETAIN'), count(pw_retain_liste()),
    count(pw_felderliste()) + count(pw_statusliste())) ?></div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= pw_t('LEGENDE.TECHNIK') ?></span></div>
<h2><?= pw_e(pw_t('LOX.H')) ?></h2>
<?php if (count($pw_alle_ids) > 1): ?>
<div class="sm-warnung"><?= sprintf(pw_t('LOX.NUR_DIESE'),
    '<b>' . pw_e(pw_pumpe_name($pw_cfg)) . '</b>') ?></div>
<?php endif; ?>

<div class="sm-step"><b><?= pw_t('LOX.S0_TITEL') ?></b><br><br>
<?= pw_t('LOX.S0_TEXT') ?>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S1_TITEL') ?></b><br><br>
<?= pw_t('LOX.S1_TEXT') ?>
<pre class="sm-pre"><?= $pw_endpunkt ?>&amp;aktion=wert&amp;watt=&lt;v&gt;</pre>
<?= pw_t('LOX.S1_TAKT') ?>
</div>

<div class="sm-step"><b><?= pw_abo_titel() ?></b><br><br>
<?= pw_abo_text() ?>
<?php if ($pw_abolage !== 'v2') { ?>
<pre class="sm-pre"><?= pw_e($pw_topic) ?>/#</pre>
<?php } ?>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S3_TITEL') ?></b><br><br>
<?= str_replace('%TOPIC%', pw_e($pw_topic), pw_t('LOX.S3_TEXT')) ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= pw_t('LOX.SP_TITEL') ?></th><th style="width:14%"><?= pw_t('LOX.SP_EINHEIT') ?></th><th style="width:44%"><?= pw_t('LOX.SP_BEDEUTUNG') ?></th></tr>
<?php
/* Die Tabelle kommt aus DERSELBEN Quelle wie die Vorlage und wie das, was
 * ueber MQTT hinausgeht - pw_felderliste(). Bis 0.9.7 stand die Liste
 * dreimal im Quelltext, obwohl der Kommentar "eine Quelle" behauptete. Ein
 * zwoelftes Feld waere weder in der Vorlage noch hier erschienen. */
foreach (array_merge(pw_felderliste(), pw_statusliste()) as $pw_fk => $pw_fr): ?>
<tr><td class="sm-mono"><?= pw_e(pw_eingangsname($pw_topic, $pw_fk)) ?></td><td><?= pw_e($pw_fr['einheit']) ?></td><td><?= pw_t($pw_fr['bed']) ?></td></tr>
<?php endforeach; ?>
</table>
</div>
<div class="sm-hilfe"><?= pw_t('LOX.BEFUND_LEGENDE') ?></div>
<div class="sm-hilfe"><?= pw_t('LOX.UNTERSTRICH') ?></div>
</div>

<h2><?= pw_t('LOX.H_VORLAGE') ?></h2>
<div class="sm-hinweis"><?= pw_t('LOX.H_VORLAGE_TEXT') ?></div>
<div class="sm-warnung"><?= pw_t('LOX.H_VORLAGE_ADRESSE') ?></div>
<?php if (count($pw_alle_ids) > 1): ?>
<div class="sm-hinweis"><?= pw_t('LOX.VORLAGEN_MEHRERE') ?></div>
<?php endif; ?>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="vorlage" value="vi">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= pw_t('LOX.K_VORLAGE_VI') ?></button>
</form>
</div>
<div class="sm-hilfe"><?= pw_t('LOX.K_VORLAGE_VI_TEXT') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="vorlage" value="vo">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= pw_t('LOX.K_VORLAGE_VO') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="vorlage" value="vihttp">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= pw_t('LOX.K_VORLAGE_VIHTTP') ?></button>
</form>
</div>
<div class="sm-hilfe"><?= pw_t('LOX.K_VORLAGE_VIHTTP_TEXT') ?></div>

<div class="sm-step"><b><?= pw_t('LOX.S4_TITEL') ?></b><br><br>
<?= pw_t('LOX.S4_TEXT') ?>
<pre class="sm-pre"><?= $pw_endpunkt ?>&amp;aktion=quittieren</pre>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S5_TITEL') ?></b><br><br>
<?= pw_t('LOX.S5_TEXT') ?>
<pre class="sm-pre"><?= $pw_endpunkt ?>&amp;aktion=anforderung&amp;an=1
<?= $pw_endpunkt ?>&amp;aktion=anforderung&amp;an=0</pre>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S6_TITEL') ?></b><br><br>
<?= pw_t('LOX.S6_TEXT') ?>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S7_TITEL') ?></b><br><br>
<?php /* Nachzug G2 (02.10.2026): die Liste kommt aus pw_bausteinliste() -
   Nummern gerechnet, Eingangs- und Befehlsnamen aus der Vorlagenquelle.
   Typ, Name, Parameter und Saetze stammen aus der Sprachdatei und duerfen
   ihre Auszeichnung behalten; Namen sind dort schon maskiert. */
$pw_bl = pw_bausteinliste($pw_cfg); ?>
<?= $pw_bl['text'] ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th style="width:4%">#</th><th style="width:22%"><?= pw_t('LOX.BSP_TYP') ?></th><th style="width:20%"><?= pw_t('LOX.BSP_NAME') ?></th><th style="width:24%"><?= pw_t('LOX.BSP_PARAM') ?></th><th><?= pw_t('LOX.BSP_EIN') ?></th></tr>
<?php foreach ($pw_bl['zeilen'] as $pw_bz) { ?>
<tr><td><?= (int) $pw_bz[0] ?></td><td><?= $pw_bz[1] ?></td><td><?= $pw_bz[2] ?></td><td><?= $pw_bz[3] ?></td><td><?= $pw_bz[4] ?></td></tr>
<?php } ?>
</table>
</div>
<?php foreach ($pw_bl['zu'] as $pw_bh) { ?>
<div class="sm-hilfe"><?= $pw_bh ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S8_TITEL') ?></b><br><br>
<?= str_replace('%TOPIC%', pw_e($pw_topic), pw_t('LOX.S8_TEXT')) ?>
</div>
</div>

<!-- ================= Reiter: Bilanz ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-bilanz' ? ' sm-active' : '' ?>" id="tab-bilanz">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span></div>

<h2><?= pw_e(pw_t('BILANZ.H_TAGE')) ?></h2>
<div class="sm-hilfe"><?= pw_t('BILANZ.H_TAGE_TEXT') ?></div>
<?php if (!$pw_tage) { ?>
<div class="sm-hinweis"><?= pw_t('BILANZ.KEINE_TAGE') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= pw_t('BILANZ.SP_TAG') ?></th><th><?= pw_t('BILANZ.SP_LAUF') ?></th><th><?= pw_t('BILANZ.SP_STARTS') ?></th><th><?= pw_t('BILANZ.SP_LAENGSTER') ?></th></tr>
<?php foreach ($pw_tage as $pw_tg): ?>
<?php /* Jeder Schluessel einzeln gefragt. Ein halb geschriebener Satz in
   tage.json ergab bis 0.9.10 unter PHP 8 drei Warnungen MITTEN in der
   Seite - error_reporting maskiert E_NOTICE, aber "Undefined array key"
   ist seit PHP 8.0 E_WARNING. Gemessen 31.08.2026. */ ?>
<tr><td><?= pw_e(isset($pw_tg['tag']) ? $pw_tg['tag'] : '?') ?></td><td><?= isset($pw_tg['lauf_s']) ? pw_e(round($pw_tg['lauf_s'] / 60, 1)) . ' min' : '&ndash;' ?></td><td><?= isset($pw_tg['starts']) ? (int) $pw_tg['starts'] : '&ndash;' ?></td><td><?= isset($pw_tg['laengster']) ? pw_e(round($pw_tg['laengster'] / 60, 1)) . ' min' : '&ndash;' ?></td></tr>
<?php endforeach; ?>
</table>
<?php } ?>

<h2><?= pw_e(pw_t('BILANZ.H_WARTUNG')) ?></h2>
<div class="sm-hilfe"><?= pw_t('BILANZ.H_WARTUNG_TEXT') ?></div>
<table class="sm-tbl">
<tr><td><?= pw_t('BILANZ.W_GESAMT') ?></td><td><?= pw_e($pw_wart['gesamt_h']) ?> h / <?= (int) $pw_wart['gesamt_starts'] ?> <?= pw_t('BILANZ.W_STARTS') ?></td></tr>
<tr><td><?= pw_t('BILANZ.W_SEIT') ?></td><td><?= pw_e($pw_wart['seit_h']) ?> h / <?= (int) $pw_wart['seit_starts'] ?> <?= pw_t('BILANZ.W_STARTS') ?></td></tr>
<tr><td><?= pw_t('BILANZ.W_LETZTE') ?></td><td><?= $pw_wart['wartung_ts'] > 0 ? pw_e(date('d.m.Y H:i', $pw_wart['wartung_ts'])) : '&ndash;' ?></td></tr>
</table>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-bilanz">
  <!-- Das versteckte "wartung_reset" ist kein Schmuck: wirkungstest.py drueckt
       JEDEN Knopf und meldet jede Aenderung an stand.json als Verlust. Ein
       Formular ohne sichtbares Eingabefeld, dessen verstecktes Merkmal einen
       Aktionsnamen traegt, erkennt es als Aktionsformular - es SOLL ja etwas
       aendern. Der Name sagt zugleich, was der Knopf tut. -->
  <input data-role="none" type="hidden" name="wartung_reset" value="1">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= pw_t('BILANZ.K_WARTUNG') ?></button>
</form>
</div>

<h2><?= pw_e(pw_t('BILANZ.H_QUELLE')) ?></h2>
<div class="sm-hilfe"><?= pw_t('BILANZ.H_QUELLE_TEXT') ?></div>
<table class="sm-tbl">
<tr><td><?= pw_t('BILANZ.Q_WEG') ?></td><td><?= pw_e(pw_t($pw_cfg['quelle'] === 'mqtt' ? 'EINST.Q_MQTT' : 'EINST.Q_LOXONE')) ?></td></tr>
<?php if ($pw_cfg['quelle'] === 'mqtt') { ?>
<tr><td><?= pw_t('BILANZ.Q_ONLINE') ?></td><td class="<?= $pw_felder['quelle_online'] === 1 ? 'sm-an' : ($pw_felder['quelle_online'] === 0 ? 'sm-aus' : 'sm-strich') ?>"><?=
    $pw_felder['quelle_online'] === 1 ? pw_e(pw_t('WORT.JA')) : ($pw_felder['quelle_online'] === 0 ? pw_e(pw_t('WORT.NEIN')) : '&ndash;') ?></td></tr>
<?php /* $pw_dpid, NICHT $pw_pid: das ist seit 1.0.0 die Kennung der
   gewaehlten Pumpe. Wer sie hier fuer die Prozessnummer wiederverwendet,
   kippt sie fuer alles, was danach kommt - und das Schaltwerk am Ende der
   Datei schrieb dann "&pumpe=0" in die Adresszeile. Dieselbe Klasse wie
   $p = pw_paths(). */ ?>
<tr><td><?= pw_t('BILANZ.Q_ZUHOERER') ?></td><td><?php $pw_dpid = pw_dienst_pid(); $pw_da = pw_dienst_alter();
    echo $pw_dpid > 0 ? ('PID ' . (int) $pw_dpid . ($pw_da >= 0 ? ', ' . (int) $pw_da . ' s' : '')) : '&ndash;'; ?></td></tr>
<?php } ?>
<tr><td><?= pw_t('LOX.B_VOLT') ?></td><td><?= $pw_felder['volt'] >= 0 ? pw_e($pw_felder['volt']) . ' V' : '&mdash;' ?></td></tr>
<tr><td><?= pw_t('LOX.B_AMPERE') ?></td><td><?= $pw_felder['ampere'] >= 0 ? pw_e($pw_felder['ampere']) . ' A' : '&mdash;' ?></td></tr>
<tr><td><?= pw_t('LOX.B_HERTZ') ?></td><td><?= $pw_felder['hertz'] >= 0 ? pw_e($pw_felder['hertz']) . ' Hz' : '&mdash;' ?></td></tr>
</table>

<h2><?= pw_e(pw_t('BILANZ.H_TAKT')) ?></h2>
<div class="sm-hilfe"><?= pw_t('BILANZ.H_TAKT_TEXT') ?></div>
<table class="sm-tbl">
<tr><td><?= pw_t('BILANZ.T_ANZAHL') ?></td><td><?= (int) $pw_takt['anzahl'] ?> <?= pw_t('BILANZ.T_UEBER') ?> <?= pw_e(round($pw_takt['spanne_s'] / 60, 1)) ?> min</td></tr>
<tr><td><?= pw_t('BILANZ.T_KUERZESTER') ?></td><td><?= (int) $pw_takt['kuerzester'] ?> s</td></tr>
<tr><td><?= pw_t('BILANZ.T_MITTLERER') ?></td><td><?= (int) $pw_takt['mittlerer'] ?> s</td></tr>
<tr><td><?= pw_t('BILANZ.T_LAENGSTER') ?></td><td><?= (int) $pw_takt['laengster'] ?> s</td></tr>
<tr><td><?= pw_t('BILANZ.T_URTEIL') ?></td><td class="<?= $pw_takt['urteil'] === 'zyklisch' ? 'sm-an' : ($pw_takt['urteil'] === 'zu_wenig' ? 'sm-strich' : 'sm-aus') ?>"><b><?= pw_t('BILANZ.T_' . strtoupper($pw_takt['urteil'])) ?></b></td></tr>
</table>
<div class="sm-hilfe"><?= pw_t('BILANZ.T_ERKLAERUNG') ?></div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= pw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= pw_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span>
</div>

<?php if (count($pw_alle_ids) > 1): ?>
<h2><?= pw_e(pw_t('TEST.H_ALLE')) ?></h2>
<div class="sm-hilfe"><?= pw_t('TEST.H_ALLE_TEXT') ?></div>
<div class="sm-breit">
<table class="sm-tbl sm-ueberblick">
<tr><th style="width:6%"></th><th><?= pw_t('TEST.SP_PUMPE') ?></th>
    <th style="width:18%"><?= pw_t('PUMPE.L_ART') ?></th>
    <th style="width:16%"><?= pw_t('EINST.L_QUELLE') ?></th>
    <th style="width:18%"><?= pw_t('TEST.SP_THEMA') ?></th>
    <th style="width:22%"><?= pw_t('TEST.SP_LAGE') ?></th></tr>
<?php foreach ($pw_alle_ids as $pw_ui):
    $pw_up = pw_pumpe($pw_voll, $pw_ui);
    $pw_us = pw_stand($pw_ui);
    $pw_uf = pw_felder($pw_us, $pw_up);
    /* Der Punkt sagt dasselbe wie im Kopf: gruen, wenn der Befund "ok"
       ist, rot bei einem Befund, grau wenn nie ein Messwert kam. Eine
       Pumpe ohne Auskunft bekommt KEINEN gruenen Punkt - das ist der
       Unterschied zwischen "in Ordnung" und "keine Auskunft". */
    $pw_uq = pw_zahl(isset($pw_us['quelle_ts']) ? $pw_us['quelle_ts'] : 0, 0.0);
    $pw_ua = $pw_uq > 0 ? (int) (time() - $pw_uq) : -1;
    $pw_uk = $pw_ua < 0 ? 'sm-strich'
           : (($pw_uf['befund'] === 0 && $pw_ua <= (int) pw_zahl($pw_up['stale_s'], 300.0))
              ? 'sm-an' : 'sm-aus'); ?>
<tr><td class="<?= $pw_uk ?>" style="text-align:center;font-size:1.15em;"><?=
    $pw_uk === 'sm-an' ? '&#10003;' : ($pw_uk === 'sm-aus' ? '&#10007;' : '&ndash;') ?></td>
    <td><a data-role="none" href="index.php?form=test&amp;pumpe=<?= rawurlencode($pw_ui) ?>"><?= pw_e(pw_pumpe_name($pw_up)) ?></a></td>
    <td><?= pw_e(pw_t(pw_arten()[pw_art($pw_up)]['name'])) ?></td>
    <td><?= pw_e(pw_t($pw_up['quelle'] === 'mqtt' ? 'PRUEF.Q_MQTT' : 'PRUEF.Q_LOXONE')) ?></td>
    <td class="sm-mono"><?= pw_e(pw_mqtt_thema($pw_up)) ?></td>
    <td><?= pw_e(pw_t($pw_befunde[$pw_uf['befund']])) ?><?=
        $pw_ua < 0 ? ' (' . pw_e(pw_t('PRUEF.NIE')) . ')' : ' (' . (int) $pw_ua . ' s)' ?></td></tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php /* b1 (Verbesserungsbau 30.09.2026): der letzte Alarm DIESER Pumpe - aus
   alarm.json im Datenordner, die ein Update ueberlebt (Entscheidung 13).
   Kein Netz, deshalb in jedem Seitenaufbau. */
$pw_la = pw_alarm_lesen($pw_pid);
$pw_la = $pw_la['aktuell']; ?>
<h2><?= pw_e(pw_t('TEST.H_LETZTER_ALARM')) ?></h2>
<?php if ($pw_la === null) { ?>
<div class="sm-hinweis"><?= pw_e(pw_t('TEST.LA_KEINER')) ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><td style="width:30%"><?= pw_e(pw_t('TEST.LA_ART')) ?></td><td><b><?= pw_e(pw_t($pw_befunde[pw_befund_zahl($pw_la['art'])])) ?></b></td></tr>
<tr><td><?= pw_e(pw_t('TEST.LA_BEGINN')) ?></td><td><?= pw_e(date('d.m.Y H:i:s', $pw_la['beginn'])) ?></td></tr>
<tr><td><?= pw_e(pw_t('TEST.LA_ENDE')) ?></td><td class="<?= $pw_la['ende'] > 0 ? 'sm-an' : 'sm-aus' ?>"><?= $pw_la['ende'] > 0 ? pw_e(date('d.m.Y H:i:s', $pw_la['ende'])) : pw_e(pw_t('TEST.LA_DAUERT')) ?></td></tr>
</table>
<?php } ?>
<div class="sm-hilfe"><?= pw_t('TEST.LA_TEXT') ?></div>

<h2><?= pw_e(pw_t('TEST.H_PRUEFUNG')) ?></h2>
<div class="sm-hilfe"><?= pw_t('TEST.H_PRUEFUNG_TEXT') ?></div>
<?php
/* O10 (1.0.4): die Selbstpruefung ruft den eigenen Endpunkt ueber HTTP ab
 * und darf nur laufen, wenn der Reiter Test serverseitig der offene ist
 * (Regeln/04). Bis 1.0.3 lief sie bei JEDEM Seitenaufbau, weil alle Reiter
 * mitgerendert werden - mit haengendem Webserver 3-4 s je Seite
 * (Pruefbericht oberflaeche O10). */
if ($pw_tab === 'tab-test') {
$pw_zeilen = pw_selbstpruefung($pw_cfg);
$pw_haken = 0; $pw_kreuz = 0; $pw_striche = 0;
foreach ($pw_zeilen as $pw_zz) {
    if ($pw_zz['ok'] === 1) { $pw_haken++; }
    elseif ($pw_zz['ok'] === 0) { $pw_kreuz++; }
    else { $pw_striche++; }
}
?>
<table class="sm-tbl">
<tr><th style="width:6%"></th><th><?= pw_t('TEST.SP_FRAGE') ?></th><th style="width:28%"><?= pw_t('TEST.SP_GEMESSEN') ?></th></tr>
<?php foreach ($pw_zeilen as $pw_zz): ?>
<tr><td class="<?= $pw_zz['ok'] === 1 ? 'sm-an' : ($pw_zz['ok'] === 0 ? 'sm-aus' : 'sm-strich') ?>" style="text-align:center;font-size:1.15em;"><?=
    $pw_zz['ok'] === 1 ? '&#10003;' : ($pw_zz['ok'] === 0 ? '&#10007;' : '&ndash;') ?></td>
    <td><?= pw_t($pw_zz['bez']) ?></td>
    <td><span class="sm-mono"><?= $pw_zz['text'] !== '' ? pw_e($pw_zz['text']) : '&nbsp;' ?></span></td></tr>
<?php endforeach; ?>
</table>
<div class="sm-<?= ($pw_kreuz > 0) ? 'warnung' : 'hinweis' ?>"><?=
    sprintf(pw_t('TEST.ZUSAMMEN'), $pw_haken, count($pw_zeilen), $pw_kreuz, $pw_striche) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= pw_e(pw_t('TEST.NUR_IM_REITER')) ?> <a data-role="none" href="index.php?form=test<?= $pw_q ?>"><?= pw_e(pw_t('TEST.K_PRUEFEN')) ?></a></div>
<?php } ?>
<div class="sm-hilfe"><?= pw_t('TEST.STRICH_ERKLAERUNG') ?></div>

<h2><?= pw_e(pw_t('REITER.TEST')) ?></h2>
<h3><?= pw_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= $pw_endpunkt ?>&amp;aktion=stand" target="_blank"><?= pw_t('TEST.K_STAND') ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= $pw_endpunkt ?>&amp;aktion=zeile" target="_blank"><?= pw_t('TEST.K_ZEILE') ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= $pw_endpunkt ?>&amp;aktion=json" target="_blank"><?= pw_t('TEST.K_JSON') ?></a>
</div>

<h3><?= pw_t('TEST.H_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= pw_t('TEST.K_SELBSTTEST') ?></button>
</form>
</div>

<h3><?= pw_t('TEST.H_SCHALTEN') ?></h3>
<?php /* Der Satz steht UEBER der Reihe, nicht darunter: wer ihn erst
   hinterher liest, hat schon gedrueckt (REGELN_2, "Der Reiter Test"). */ ?>
<p class="sm-hilfe"><?= pw_t('TEST.SCHALTEN_WARNUNG') ?></p>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;display:flex;gap:10px;align-items:center;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="text" name="watt" value="<?= pw_e(pw_fw('test', 'watt', '')) ?>"<?= pw_fk('test', 'watt') ?> placeholder="<?= pw_e(pw_t('TEST.PLATZHALTER_WATT')) ?>" style="max-width:140px;">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testwert" value="1"><?= pw_t('TEST.K_WERT') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="quittieren" value="1"><?= pw_t('TEST.K_QUITTIEREN') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1"><?= pw_e(pw_t('DURCHSAGE.K_TEST')) ?></button>
</form>
</div>
<div class="sm-hilfe"><?= pw_t('TEST.HINWEIS') ?></div>

<?php if ($pw_testausgabe !== '') { ?>
<pre class="sm-pre"><?= pw_e($pw_testausgabe) ?></pre>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span></div>
<h2><?= pw_e(pw_t('REITER.LOG')) ?></h2>
<?php /* O12 (1.0.4): Hausform des Reiters Logdateien (Regeln/04) - die
   Protokollliste von LoxBerry, der Hinweis auf die Ramdisk, und die beiden
   Dateien des Minutentakts: das Startprotokoll des Zuhoerers (seine
   Fehlerausgabe) und cron.err (gescheiterte Starts). Bis 1.0.3 zeigte der
   Reiter bei fehlendem Protokoll ein leeres Feld ohne Satz, und die
   Startfehler waren aus der Oberflaeche nicht erreichbar. */ ?>
<div class="sm-hinweis"><?= pw_e(pw_t('LOG.RAMDISK')) ?></div>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
<div class="sm-hilfe"><?= pw_t('LOG.TEXT') ?> <span class="sm-mono"><?= pw_e($pw_p['log']) ?></span></div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="fmt" value="<?= pw_e($pw_fmt) ?>"><?= $pw_qf ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= pw_t('LOG.K_LEEREN') ?></button>
  </div>
</form>
<?php $pw_lz_zeilen = pw_log_lesen();
if (!$pw_lz_zeilen) { ?>
<div class="sm-hinweis"><?= pw_e(pw_t('LOG.LEER')) ?></div>
<?php } else { ?>
<pre class="sm-pre"><?php foreach ($pw_lz_zeilen as $pw_z) { echo pw_e($pw_z) . "\n"; } ?></pre>
<?php } ?>
<?php foreach (array(array('LOG.H_START', $pw_p['startlog']), array('LOG.H_CRONERR', $pw_p['cronerr'])) as $pw_ld) {
    /* Das ENDE der Datei, hoechstens 32 KiB - das Neueste steht unten. */
    $pw_lt = '';
    if (is_file($pw_ld[1]) && is_readable($pw_ld[1])) {
        clearstatcache(true, $pw_ld[1]);
        $pw_lt = (string) @file_get_contents($pw_ld[1], false, null, max(0, (int) filesize($pw_ld[1]) - 32768));
    } ?>
<h3><?= pw_e(pw_t($pw_ld[0])) ?> <span class="sm-mono" style="font-weight:400;"><?= pw_e($pw_ld[1]) ?></span></h3>
<?php if (trim($pw_lt) === '') { ?>
<div class="sm-hilfe"><?= pw_e(pw_t('LOG.DATEI_LEER')) ?></div>
<?php } else { ?>
<pre class="sm-pre"><?= pw_e($pw_lt) ?></pre>
<?php } } ?>
</div>

</div><!-- sm-wrap -->

<script>
(function () {
	var pwQ = <?= json_encode('&pumpe=' . rawurlencode($pw_pid)) ?>;
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '') + pwQ); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($pw_tab) ?>);
})();
function pwModell() {
	var s = document.getElementById('pw_modell');
	var o = s.options[s.selectedIndex];
	var p1 = parseInt(o.getAttribute('data-p1'), 10);
	if (!p1) { return; } // "frei" schlaegt nichts vor
	/* Vorschlaege aus dem Datenblatt (siehe pw_modelle im Kern):
	   Trockenlauf unter ~25 % von P1, Ueberlast ueber ~120 % von P1. */
	document.getElementsByName('trocken_w')[0].value = Math.round(p1 * 0.25);
	document.getElementsByName('ueberlast_w')[0].value = Math.round(p1 * 1.2);
	document.getElementsByName('starts_h')[0].value = o.getAttribute('data-starts');
}
</script>
<?php if ($pw_frame) { LBWeb::lbfooter(); } ?>
