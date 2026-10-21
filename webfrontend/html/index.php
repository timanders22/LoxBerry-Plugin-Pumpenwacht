<?php
/**
 * Pumpenwaechter - der Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich und ist deshalb durch ein Wortzeichen
 * geschuetzt (hash_equals, fail-closed - wie Einspeisebremse).
 *
 *   ?token=<TOKEN>&aktion=wert&watt=<ZAHL>   Messwert anliefern, rechnen,
 *                                            speichern, per MQTT melden
 *
 * Seit 1.0.0 kann das Plugin mehrere Pumpen fuehren. Welche gemeint ist,
 * sagt ein zusaetzliches &pumpe=<KENNUNG>:
 *
 *   ?token=<TOKEN>&aktion=wert&watt=600&pumpe=sumpf
 *
 * OHNE die Angabe ist es die ERSTE Pumpe - genau wie bisher. Das ist
 * Absicht: in einem Miniserver stehen die Adressen in virtuellen
 * Ausgaengen, Bausteinen und Formeln, und ein Update, das sie alle
 * ungueltig macht, haelt die Anlage an.
 *
 * Eine UNBEKANNTE Kennung wird abgewiesen (400, GRUND=PUMPE) und nicht auf
 * die erste umgebogen. Hier sitzt niemand davor: ein stiller Rueckfall
 * liesse einen Tippfehler in einer Loxone-Adresse monatelang die Messwerte
 * der einen Pumpe bei der anderen ablegen.
 *   ?token=<TOKEN>&aktion=stand              alle Werte als Textzeilen
 *   ?token=<TOKEN>&aktion=zeile              dieselben Werte in EINER Zeile
 *   ?token=<TOKEN>&aktion=json               dasselbe als JSON
 *   ?token=<TOKEN>&aktion=quittieren         Sperre von Hand aufheben
 *   ?token=<TOKEN>&aktion=anforderung&an=1|0 Pumpe angefordert (optional)
 *   ?token=<TOKEN>&aktion=selftest           nur pruefen, loest NICHTS aus
 *
 * Die Wertlieferung ist tokenpflichtig, obwohl sie "nur" einen Messwert
 * traegt: wer beliebige Watt-Zahlen einliefern koennte, koennte eine
 * Sperre ohne Quittungspflicht durch erfundene Normalwerte aufheben.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/pw_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
/* Der Endpunkt wirft im Fehlerfall die empfangene Eingabe zurueck. Der
 * Inhaltstyp ist text/plain; nosniff sorgt dafuer, dass ein Zwischenspeicher
 * oder Proxy, der den Typ verliert, daraus nichts anderes macht. */
header('X-Content-Type-Options: nosniff');

/* Die Aktion wird VOR der Tokenpruefung gelesen - sie ist nur eine
 * Zeichenkette und loest nichts aus. Damit kann der Selbsttest in seiner
 * eigenen, im Hausstandard gemessenen Form antworten, statt in der
 * allgemeinen Fehlerform:
 *   richtiges Wortzeichen   HTTP 200  SELFTEST;OK=1;TOKEN=OK
 *   falsches                HTTP 403  SELFTEST;OK=0;ERR=TOKEN
 *   keines eingerichtet     HTTP 403  SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET */
$pw_erlaubt = array('wert', 'stand', 'zeile', 'json', 'quittieren', 'anforderung', 'selftest');
/* Nachtrag vb_pw2 (30.09.2026): jeder Parameter nur als Zeichenkette. Eine
 * Liste (aktion[]=) machte (string) zu "Array" samt PHP-Warnung - VOR der
 * Tokenpruefung und mit display_errors mitten in der Antwort. Eine Liste ist
 * jetzt eine unbekannte Aktion (400 nach der Tokenpruefung). */
$pw_aktion = !isset($_GET['aktion']) ? 'stand'
           : (is_string($_GET['aktion']) ? strtolower($_GET['aktion']) : '[liste]');
$pw_selbst = ($pw_aktion === 'selftest');

/* Die Konfiguration OHNE Selbstheilung lesen.
 *
 * Bis 0.9.7 stand hier pw_config() MIT Heilung, und die laeuft VOR der
 * Tokenpruefung: eine einzige tokenlose Anfrage stellte die Konfiguration
 * aus der Zweitschrift wieder her. Gemessen am 28.08.2026 - die Antwort
 * war korrekt 403, die Datei trotzdem geschrieben.
 * Siehe REGELN_2, "Der unangemeldete Endpunkt legt auch nichts AN". */
$pw_cfg = pw_pumpe(pw_config(false));
if ((string) $pw_cfg['aktionstoken'] === '') {
    http_response_code(403);
    if ($pw_selbst) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Wortzeichen.\n";
    exit;
}
if (!pw_token_ok($pw_cfg)) {
    http_response_code(403);
    echo $pw_selbst ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* WELCHE PUMPE?
 *
 * Steht hier NACH der Tokenpruefung: davor liesse sich an der Unterscheidung
 * 400 (Kennung unbekannt) / 403 (Wortzeichen falsch) ablesen, welche
 * Kennungen es in dieser Anlage gibt - eine Auskunft an jeden, der die
 * Adresse kennt.
 *
 * Ohne Angabe die erste Pumpe. Mit unbekannter Angabe: abgewiesen. */
$pw_pid = !isset($_GET['pumpe']) ? ''
        : (is_string($_GET['pumpe']) ? trim($_GET['pumpe']) : '[liste]');     // Liste = unbekannte Pumpe
if ($pw_pid !== '') {
    $pw_voll = pw_config(false);
    if (!in_array($pw_pid, pw_pumpe_ids($pw_voll), true)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=PUMPE\n";
        /* Die vorhandenen Kennungen werden NICHT genannt - sie stehen in der
         * Oberflaeche, und die ist angemeldet. */
        echo "Es gibt keine Pumpe mit dieser Kennung.\n";
        exit;
    }
    $pw_cfg = pw_pumpe($pw_voll, $pw_pid);
}

if (!in_array($pw_aktion, $pw_erlaubt, true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: " . implode(', ', $pw_erlaubt) . "\n";
    exit;
}

/* selftest steht unmittelbar hinter der Tokenpruefung: die Pruefung greift,
 * und danach geschieht NICHTS - kein Schreiben, kein Protokolleintrag, kein
 * MQTT. Er beantwortet die eine Frage, die am Miniserver sonst nicht zu
 * klaeren ist: stimmt das Wortzeichen in der Adresse? Ein aktion=stand
 * beantwortet sie auch, verraet dabei aber den ganzen Zustand und sagt bei
 * einem Fehler nicht, woran es lag. */
if ($pw_selbst) {
    echo "SELFTEST;OK=1;TOKEN=OK;FASSUNG=" . pw_fassung() . "\n";
    exit;
}

/* ---------------- wert, quittieren, anforderung ----------------
 * Gruppe 5 / MQTT (1.0.14, Entwurf pw 3.2): die Pruefung und die Wirkung stehen in pw_befehl_ausfuehren()
 * (pw_lib.php); dieselbe Funktion ruft die Befehlsbruecke (bin/befehle_zuordnung.php, Quelle 'mqtt'). Antwort, Code
 * und Protokoll dieses Endpunkts sind unveraendert (Wirkungstest Quelle gegen Bau): 400 GRUND=WATT mit dem
 * empfangenen Text, 200 OK=2;GRUND=BELEGT ohne Wiederholung, 500 GRUND=SPEICHERN, die Zeile mit BEFUND/SPERRE/
 * GESENDET/FEHL, "Sperre quittiert (Endpunkt).", 400 GRUND=AN. Hier bleiben nur das Einlesen der Abfrage und die
 * schon gepruefte Pumpe ($pw_cfg, Token und Kennung oben). Die Wertlieferung ist tokenpflichtig: wer beliebige
 * Watt-Zahlen einliefern koennte, koennte eine Sperre ohne Quittungspflicht durch erfundene Normalwerte aufheben. */
if ($pw_aktion === 'wert' || $pw_aktion === 'quittieren' || $pw_aktion === 'anforderung') {
    $pw_par = array();
    /* Jeder Parameter nur als Zeichenkette (vb_pw2): eine Liste (watt[]=, an[]=) gilt als keine Zahl bzw. als
     * weder 0 noch 1 - die Funktion weist sie ab wie bisher. */
    if ($pw_aktion === 'wert') {
        $pw_par['watt'] = (isset($_GET['watt']) && is_string($_GET['watt'])) ? $_GET['watt'] : '';
    } elseif ($pw_aktion === 'anforderung') {
        $pw_par['an'] = (isset($_GET['an']) && is_string($_GET['an'])) ? $_GET['an'] : '';
    }
    list($pw_code, $pw_text) = pw_befehl_ausfuehren($pw_aktion, $pw_par, $pw_cfg, 'http');
    if ($pw_code !== 200) { http_response_code($pw_code); }
    echo $pw_text;
    exit;
}

$pw_felder = pw_felder(pw_stand($pw_cfg['id']), $pw_cfg);

if ($pw_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_encode($pw_felder, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false) {
        http_response_code(500);
        echo json_encode(array('ok' => 0, 'fehler' => json_last_error_msg()));
        exit;
    }
    echo $j;
    exit;
}

if ($pw_aktion === 'zeile') {
    /* EINE Zeile fuer einen virtuellen Eingang mit Befehlserkennung - der
     * Weg ohne MQTT-Gateway. Die Lebenszeichen gehoeren dazu, sonst kann
     * Loxone auf diesem Weg keinen Ausfall erkennen. */
    $st = pw_stand($pw_cfg['id']);
    $pw_felder['status_ok'] = ($pw_felder['laeuft'] === -1) ? 0 : 1;
    /* B8 (1.0.4): der Zeitpunkt des letzten MINUTENTAKTS, nicht die
     * Abrufzeit - sonst zeigt eine Alterung in Loxone immer 0 s, und ein
     * stehender Takt faellt nicht auf. 0 = es lief noch keiner. */
    $pw_felder['status_ts'] = (int) pw_zahl(isset($st['takt_ts']) ? $st['takt_ts'] : 0, 0.0);
    $pw_felder['status_zaehler'] = (int) pw_zahl(isset($st['status_zaehler']) ? $st['status_zaehler'] : 0, 0.0);
    $pw_felder['status_quelle_ts'] = (int) pw_zahl(isset($st['quelle_ts']) ? $st['quelle_ts'] : 0, 0.0);
    echo pw_eine_zeile($pw_felder) . "\n";
    exit;
}

echo pw_zeilen($pw_felder);
