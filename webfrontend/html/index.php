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
 *   ?token=<TOKEN>&aktion=json               dasselbe als JSON
 *   ?token=<TOKEN>&aktion=quittieren         Sperre von Hand aufheben
 *
 * Die Wertlieferung ist tokenpflichtig, obwohl sie "nur" einen Messwert
 * traegt: wer beliebige Watt-Zahlen einliefern koennte, koennte eine
 * Sperre ohne Quittungspflicht durch erfundene Normalwerte aufheben.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/pw_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$pw_cfg = pw_config();
if ((string) $pw_cfg['aktionstoken'] === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Wortzeichen.\n";
    exit;
}
if (!pw_token_ok($pw_cfg)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

$pw_aktion = isset($_GET['aktion']) ? strtolower((string) $_GET['aktion']) : 'stand';
if (!in_array($pw_aktion, array('wert', 'stand', 'json', 'quittieren'), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: wert, stand, json, quittieren\n";
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
        echo "watt muss eine Zahl sein, empfangen wurde: " . substr($roh, 0, 40) . "\n";
        exit;
    }
    $watt = (float) str_replace(',', '.', $roh);
    $alt = pw_stand();
    $jetzt = time();
    $neu = pw_schritt(array('watt' => $watt, 'tag' => date('Y-m-d', $jetzt)), $pw_cfg, $alt, $jetzt);
    $neu['watt'] = $watt;
    if (!pw_stand_speichern($neu)) {
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
        exit;
    }
    if (($neu['befund'] !== (isset($alt['befund']) ? $alt['befund'] : '')) && $neu['befund'] !== PW_OK) {
        pw_log('Befund: ' . $neu['befund'] . ' (Beiwert ' . $neu['beiwert'] . ', ' . $watt . ' W)');
    }
    if (!empty($neu['sperre']) && empty($alt['sperre'])) {
        pw_log('SPERRE gesetzt: ' . $neu['sperrgrund']);
    }
    $felder = pw_felder($neu, $pw_cfg, $jetzt);
    pw_mqtt_publish($felder);
    echo "OK=1;BEFUND=" . $felder['befund'] . ";SPERRE=" . $felder['sperre'] . "\n";
    exit;
}

if ($pw_aktion === 'quittieren') {
    $neu = pw_quittieren(pw_stand());
    if (!pw_stand_speichern($neu)) {
        http_response_code(500);
        echo "FEHLER;OK=0;GRUND=SPEICHERN\n";
        exit;
    }
    pw_log('Sperre quittiert (Endpunkt).');
    pw_mqtt_publish(pw_felder($neu, $pw_cfg));
    /* Die Wirkung melden, nicht die Absicht: zurueckgelesen aus der Datei. */
    echo "SPERRE=" . (!empty(pw_stand()['sperre']) ? 1 : 0) . "\n";
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

echo pw_zeilen($pw_felder);
