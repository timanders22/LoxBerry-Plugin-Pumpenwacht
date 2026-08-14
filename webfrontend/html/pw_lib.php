<?php
/**
 * Pumpenwaechter - gemeinsame Bibliothek
 *
 * Die Rechnung steht in pw_regel.php (der Kern, ohne Netz, Datei und Uhr).
 * Hier steht alles andere: Pfade, Konfiguration mit Zweitschrift und
 * Selbstheilung, Zustand, Wortzeichen, MQTT ueber den Gateway-Relay,
 * Sprache, Vorlagen. Kompatibel mit PHP 7.4 und 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/pw_regel.php';

function pw_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function pw_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/** Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function pw_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    $home = $home ? $home : lb_wurzel_ermitteln();
    // Der Pluginordner ergibt sich aus dem Ablageort, nicht aus einem
    // festen Namen - bei einer Zweitinstallation hiesse er pumpenwacht_01.
    // Installiert liegt diese Datei unter html/plugins/<ordner>/ - der
    // Ordnername ist also das ELTERNVERZEICHNIS der Datei (wie Weissware).
    $dir = basename(__DIR__);
    if ($dir === '' || $dir === 'html' || $dir === 'htmlauth' || $dir === 'plugins') {
        $dir = getenv('LBPPLUGINDIR') ?: 'pumpenwacht';
    }
    $p = array(
        'home'      => $home,
        'plugin'    => $dir,
        'configdir' => $home . '/config/plugins/' . $dir,
        'config'    => $home . '/config/plugins/' . $dir . '/pumpenwacht.json',
        // Zweitschrift NEBEN dem Ordner, nicht darin: beim Update kopiert der
        // Installer config/ aus dem Archiv darueber (Hausmuster Weissware/Kodi).
        'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
        'datadir'   => $home . '/data/plugins/' . $dir,
        'stand'     => $home . '/data/plugins/' . $dir . '/stand.json',
        'logdir'    => $home . '/log/plugins/' . $dir,
        'log'       => $home . '/log/plugins/' . $dir . '/pumpenwacht.log',
        'general'   => $home . '/config/system/general.json',
    );
    return $p;
}

function pw_json_lesen($pfad)
{
    $roh = is_file($pfad) ? (string) @file_get_contents($pfad) : '';
    $d = json_decode($roh, true);
    return is_array($d) ? $d : array();
}

/** Unteilbar schreiben; json_encode-false wird abgewiesen, nie geleert. */
function pw_json_schreiben($pfad, $daten, $rechte = 0664)
{
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    @mkdir(dirname($pfad), 0775, true);
    $tmp = $pfad . '.neu';
    if (@file_put_contents($tmp, $json) === false) { return false; }
    @chmod($tmp, $rechte);
    return @rename($tmp, $pfad);
}

/** Vorgaben. Die Zahlen der Modelle stehen im Kern (pw_modelle, Datenblatt). */
function pw_vorgaben()
{
    return array(
        'modell'          => 'frei',
        'an_w'            => 20,
        'trocken_w'       => 0,
        'trocken_s'       => 30,
        'ueberlast_w'     => 0,
        'dauerlauf_s'     => 1800,
        'starts_h'        => 25,
        'sperren_ein'     => 0,
        'sperre_trockenlauf' => 1,
        'sperre_dauerlauf'   => 0,
        'sperre_schaltspiel' => 0,
        'sperre_ueberlast'   => 1,
        'quittung_noetig' => 1,
        // Nach so vielen Sekunden ohne Anlieferung gilt der Zustand als
        // unbekannt (Befund "still") - der Ausfall des Zwischenzaehlers
        // darf nie wie eine ruhende Anlage aussehen.
        'stale_s'         => 300,
        'mqtt_ein'        => 1,
        'mqtt_topic'      => 'pumpe',
        'aktionstoken'    => '',
    );
}

/** Konfiguration mit Selbstheilung aus der Zweitschrift (Hausmuster). */
function pw_config()
{
    $p = pw_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    return array_merge(pw_vorgaben(), pw_json_lesen($p['config']));
}

function pw_config_speichern($cfg)
{
    $p = pw_paths();
    if (!pw_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    @copy($p['config'], $p['sicherung']);
    return true;
}

function pw_stand() { return pw_json_lesen(pw_paths()['stand']); }
function pw_stand_speichern($stand) { return pw_json_schreiben(pw_paths()['stand'], $stand); }

function pw_log($text)
{
    $p = pw_paths();
    @mkdir($p['logdir'], 0775, true);
    /* Kappung nach dem Hausmuster (fer_log, FerienFeiertage): ab 500 kB
     * bleiben die letzten 200 Zeilen stehen. Ohne sie waechst die Datei
     * unbegrenzt - auf einem LoxBerry mit SD-Karte ist das kein
     * Schoenheitsfehler. */
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

function pw_log_ende($anzahl = 300)
{
    $p = pw_paths();
    if (!is_readable($p['log'])) { return array(); }
    $z = file($p['log'], FILE_IGNORE_NEW_LINES) ?: array();
    return array_slice(array_reverse($z), 0, $anzahl);
}

/* ---------------- Wortzeichen ---------------- */

function pw_token_erzeugen() { return bin2hex(random_bytes(12)); }

/** fail-closed; hash_equals gegen Zeitmessung (Hausstandard). */
function pw_token_ok($cfg)
{
    $soll = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    $ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($soll === '' || $ist === '') { return false; }
    return hash_equals($soll, $ist);
}

/* ---------------- MQTT (Regelweg) ---------------- */

function pw_mqtt_zustand()
{
    $p = pw_paths();
    $aus = array('gefunden' => false, 'udpport' => 0, 'autostart' => false);
    if (!is_file($p['general'])) { return $aus; }
    $d = json_decode((string) @file_get_contents($p['general']), true);
    if (!isset($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['udpport'] = isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0;
    // NICHT 'Autostart' - den Schluessel gibt es nicht (Fehlerklasse ACTiKamera 1.9.2).
    $aus['autostart'] = !empty($d['Mqtt']['Gatewayautostart']);
    return $aus;
}

/** Zeilenumbrueche und Tabulatoren zerlegen den Gateway-UDP-Weg (Hausstandard). */
function pw_mqtt_wert_saeubern($v)
{
    $w = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $w));
}

function pw_mqtt_publish($paare)
{
    $cfg = pw_config();
    if (empty($cfg['mqtt_ein'])) { return 0; }
    $m = pw_mqtt_zustand();
    if (!$m['udpport']) { return 0; }
    $topic = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'pumpe';
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) { return 0; }
    $n = 0;
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') { continue; }
        $msg = 'publish ' . $topic . '/' . $k . ' ' . pw_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', (int) $m['udpport']);
        $n++;
    }
    socket_close($s);
    return $n;
}

/** Befund als Zahl fuer Loxone. Die Zuordnung steht im Reiter "Einbindung". */
function pw_befund_zahl($befund)
{
    $map = array(PW_OK => 0, PW_SCHALTSPIEL => 1, PW_DAUERLAUF => 2,
                 PW_TROCKEN => 3, PW_UEBERLAST => 4, PW_STILL => 5);
    return isset($map[$befund]) ? $map[$befund] : 5;
}

/** Die Felder, die nach aussen gehen - eine Quelle fuer MQTT, Text und Vorlage. */
function pw_felder($stand, $cfg, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : $jetzt;
    $zeit = isset($stand['zeit']) ? (float) $stand['zeit'] : 0.0;
    $alter = $zeit > 0 ? (int) ($jetzt - $zeit) : -1;
    $veraltet = ($alter < 0 || $alter > (int) $cfg['stale_s']);
    // Veraltet heisst unbekannt - nie "steht" (siehe Kern, pw_laeuft).
    $laeuft = $veraltet ? -1 : (isset($stand['laeuft']) ? (int) $stand['laeuft'] : -1);
    $befund = $veraltet ? PW_STILL : (isset($stand['befund']) ? (string) $stand['befund'] : PW_STILL);
    return array(
        'laeuft'         => $laeuft,
        'befund'         => pw_befund_zahl($befund),
        'sperre'         => !empty($stand['sperre']) ? 1 : 0,
        'quittung'       => !empty($stand['quittung']) ? 1 : 0,
        'watt'           => isset($stand['watt']) ? round((float) $stand['watt'], 1) : -1,
        'lauf_s'         => isset($stand['lauf_s']) ? (int) $stand['lauf_s'] : 0,
        'letzter_lauf_s' => isset($stand['letzter_lauf_s']) ? (int) $stand['letzter_lauf_s'] : -1,
        'lauf_s_tag'     => isset($stand['lauf_s_tag']) ? (int) $stand['lauf_s_tag'] : 0,
        'starts_tag'     => isset($stand['starts_tag']) ? (int) $stand['starts_tag'] : 0,
        'laengster_tag'  => isset($stand['laengster_tag']) ? (int) $stand['laengster_tag'] : 0,
        'alter'          => $alter,
    );
}

/** Textzeilen KEY=WERT - eine je Feld, fuer die Befehlserkennung. */
function pw_zeilen($felder)
{
    $aus = '';
    foreach ($felder as $k => $v) { $aus .= strtoupper($k) . '=' . $v . "\n"; }
    return $aus;
}

/* ---------------- Vorlagen fuer Loxone Config ---------------- */

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Format wie Original-Export aus Loxone Config 17.1. */
function pw_vorlage_vi()
{
    $cfg = pw_config();
    $topic = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'pumpe';
    $crlf = "\r\n";
    $w = array(
        array('laeuft',         'Pumpe laeuft (1/0, -1 unbekannt)', 'true',  '-1', '1',          '<v.0>'),
        array('befund',         'Befund (0 ok .. 5 still)',         'false', '0',  '5',          '<v.0>'),
        array('sperre',         'Sperre aktiv',                     'false', '0',  '1',          '<v.0>'),
        array('quittung',       'Quittung noetig',                  'false', '0',  '1',          '<v.0>'),
        array('watt',           'Leistungsaufnahme',                'true',  '-1', '5000',       '<v.1> W'),
        array('lauf_s',         'Laufzeit aktueller Lauf',          'false', '0',  '86400',      '<v.0> s'),
        array('letzter_lauf_s', 'Dauer des letzten Laufs',          'true',  '-1', '86400',      '<v.0> s'),
        array('lauf_s_tag',     'Laufzeit heute',                   'false', '0',  '86400',      '<v.0> s'),
        array('starts_tag',     'Starts heute',                     'false', '0',  '1000',       '<v.0>'),
        array('laengster_tag',  'Laengster Lauf heute',             'false', '0',  '86400',      '<v.0> s'),
        array('alter',          'Alter der Meldung',                'true',  '-1', '2147483647', '<v.0> s'),
    );
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Pumpenwaechter" Comment="Erzeugt vom LoxBerry-Plugin Pumpenwaechter (' . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo ' . pw_x($topic) . '/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($w as $f) {
        $o .= "\t" . '<VirtualInHttpCmd Title="' . pw_x($topic . '_' . $f[0]) . '" ';
        $o .= 'Comment="' . pw_x($f[1]) . '" Check=" " ';
        $o .= 'Signed="' . $f[2] . '" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $f[3] . '" MaxVal="' . $f[4] . '" Unit="' . pw_x($f[5]) . '" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_pumpenwaechter.xml', $o);
}

/** VO-Vorlage (Steuerbefehle) nach dem Heimkino-Muster: templateType 3,
 *  Wortzeichen eingesetzt. Wertlieferung mit <v>-Platzhalter, Quittieren. */
function pw_vorlage_vo()
{
    $cfg = pw_config();
    $p = pw_paths();
    $host = gethostname() ?: 'loxberry';
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . rawurlencode((string) $cfg['aktionstoken']);
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Pumpenwaechter (LoxBerry-Plugin)" Comment="Erzeugt vom LoxBerry-Plugin Pumpenwaechter (' . date('d.m.Y') . '). Bitte Adresse pruefen." Address="http://' . pw_x($host) . '" CloseAfterSend="true" CmdInit="" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    $o .= "\t" . '<VirtualOutCmd Title="Messwert liefern (Watt)" Comment="Zwischenzaehler-Leistung anliefern; Analogwert am Eingang" ';
    $o .= 'CmdOnMethod="GET" CmdOn="' . pw_x($basis . '&aktion=wert&watt=<v>') . '" CmdOnHTTP="" CmdOnPost="" ';
    $o .= 'CmdOffMethod="GET" CmdOff="" CmdOffHTTP="" CmdOffPost="" Analog="true" Repeat="0" RepeatRate="0"/>' . $crlf;
    $o .= "\t" . '<VirtualOutCmd Title="Sperre quittieren" Comment="Hebt die Sperre nach Pruefung von Hand auf" ';
    $o .= 'CmdOnMethod="GET" CmdOn="' . pw_x($basis . '&aktion=quittieren') . '" CmdOnHTTP="" CmdOnPost="" ';
    $o .= 'CmdOffMethod="GET" CmdOff="" CmdOffHTTP="" CmdOffPost="" Analog="false" Repeat="0" RepeatRate="0"/>' . $crlf;
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_pumpenwaechter.xml', $o);
}

/* ---------------- Sprache ---------------- */

function pw_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = strtolower(substr((string) LBSystem::lblanguage(), 0, 2)) ?: 'de';
    }
    return $s === 'en' ? 'en' : 'de';
}

function pw_langdir()
{
    $p = pw_paths();
    foreach (array(
        $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang',
        dirname(dirname(__DIR__)) . '/templates/lang',
    ) as $k) {
        if (is_dir($k) && is_file($k . '/language_de.ini')) { return $k; }
    }
    return '';
}

function pw_sprache_fehlt() { return pw_langdir() === ''; }

function pw_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $texte = array();
        $dir = pw_langdir();
        if ($dir !== '') {
            $de = @parse_ini_file($dir . '/language_de.ini', true, INI_SCANNER_RAW) ?: array();
            $en = @parse_ini_file($dir . '/language_en.ini', true, INI_SCANNER_RAW) ?: array();
            // Englisch als Rueckfallebene unter Deutsch mischen
            $texte = (pw_sprache() === 'en') ? array_replace_recursive($de, $en) : array_replace_recursive($en, $de);
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
