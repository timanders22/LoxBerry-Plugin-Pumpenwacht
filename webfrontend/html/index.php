<?php
/**
 * Pumpenwaechter - der Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich und ist deshalb durch ein Wortzeichen
 * geschuetzt (hash_equals, fail-closed - wie Einspeisebremse).
 *
 *   ?token=<TOKEN>&aktion=wert&watt=<ZAHL>   Messwert anliefern, rechnen,
 *                                            speichern, per MQTT melden
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
$pw_aktion = isset($_GET['aktion']) ? strtolower((string) $_GET['aktion']) : 'stand';
$pw_selbst = ($pw_aktion === 'selftest');

/* Die Konfiguration OHNE Selbstheilung lesen.
 *
 * Bis 0.9.7 stand hier pw_config() MIT Heilung, und die laeuft VOR der
 * Tokenpruefung: eine einzige tokenlose Anfrage stellte die Konfiguration
 * aus der Zweitschrift wieder her. Gemessen am 28.08.2026 - die Antwort
 * war korrekt 403, die Datei trotzdem geschrieben.
 * Siehe REGELN_2, "Der unangemeldete Endpunkt legt auch nichts AN". */
$pw_cfg = pw_config(false);
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

if ($pw_aktion === 'wert') {
    $roh = isset($_GET['watt']) ? trim((string) $_GET['watt']) : '';
    /* Abweisen statt zurechtbiegen: eine leere oder unlesbare Zahl darf
     * nicht als 0 W gelten - 0 W hiesse "Pumpe steht", und genau diese
     * Falschaussage verhindert der Kern mit seinem -1 (siehe pw_laeuft). */
    if ($roh === '' || !is_numeric(str_replace(',', '.', $roh))) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=WATT\n";
        echo "watt muss eine Zahl sein, empfangen wurde: "
             . htmlspecialchars(substr($roh, 0, 40), ENT_QUOTES, 'UTF-8') . "\n";
        exit;
    }
    $watt = (float) str_replace(',', '.', $roh);
    $jetzt = time();
    list($neu, $grund, $versucht, $fehl) = pw_verarbeiten($watt, $pw_cfg, $jetzt, 'endpunkt');
    if ($neu === null) {
        /* "belegt" ist KEIN Fehler: ein anderer Durchlauf schreibt gerade,
         * und der naechste Wert kommt ohnehin gleich. Der Miniserver soll
         * daraus keinen Ausfall machen. */
        if ($grund === 'belegt') {
            echo "OK=2;GRUND=BELEGT\n";
            exit;
        }
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
        exit;
    }
    $felder = pw_felder($neu, $pw_cfg, $jetzt);
    echo "OK=" . ($fehl === 0 ? 1 : 0) . ";BEFUND=" . $felder['befund']
         . ";SPERRE=" . $felder['sperre'] . ";GESENDET=" . $versucht
         . ";FEHL=" . $fehl . "\n";
    exit;
}

if ($pw_aktion === 'quittieren') {
    list($neu, $grund) = pw_zustand_aendern('pw_quittieren', $pw_cfg);
    if ($neu === null) {
        if ($grund === 'belegt') { echo "OK=2;GRUND=BELEGT\n"; exit; }
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
        exit;
    }
    pw_log('Sperre quittiert (Endpunkt).');
    /* Die Wirkung melden, nicht die Absicht: zurueckgelesen aus der Datei.
     * Und in derselben Form wie jede andere Antwort - eine
     * Befehlserkennung auf OK= findet sonst hier nichts. */
    $ist = pw_stand();
    echo "OK=1;SPERRE=" . (!empty($ist['sperre']) ? 1 : 0)
         . ";QUITTUNG=" . (!empty($ist['quittung']) ? 1 : 0) . "\n";
    exit;
}

if ($pw_aktion === 'anforderung') {
    $an = isset($_GET['an']) ? trim((string) $_GET['an']) : '';
    if (!in_array($an, array('0', '1'), true)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=AN\n";
        echo "an muss 0 oder 1 sein.\n";
        exit;
    }
    $pw_an = ($an === '1');
    list($neu, $grund) = pw_zustand_aendern(
        function ($s) use ($pw_an) { return pw_anfordern($s, $pw_an, time()); },
        $pw_cfg);
    if ($neu === null) {
        if ($grund === 'belegt') { echo "OK=2;GRUND=BELEGT\n"; exit; }
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
        exit;
    }
    echo "OK=1;ANFORDERUNG=" . ($an === '1' ? 1 : 0) . "\n";
    exit;
}

$pw_felder = pw_felder(pw_stand(), $pw_cfg);

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
    $st = pw_stand();
    $pw_felder['status_ok'] = ($pw_felder['laeuft'] === -1) ? 0 : 1;
    $pw_felder['status_ts'] = time();
    $pw_felder['status_zaehler'] = (int) pw_zahl(isset($st['status_zaehler']) ? $st['status_zaehler'] : 0, 0.0);
    $pw_felder['status_quelle_ts'] = (int) pw_zahl(isset($st['quelle_ts']) ? $st['quelle_ts'] : 0, 0.0);
    echo pw_eine_zeile($pw_felder) . "\n";
    exit;
}

echo pw_zeilen($pw_felder);
