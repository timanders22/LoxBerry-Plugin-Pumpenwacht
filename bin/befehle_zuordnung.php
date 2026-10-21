<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Pumpenwaechter.
 *
 * Gruppe 5 / MQTT (1.0.14; Bauvorschrift wz_bild/GRUPPE5_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe5_pw.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE5_MQTT.md; Muster vb_g4_wp_bau/bin/befehle_zuordnung.php und
 * vb_g1_mv26_bau/bin/befehle_zuordnung.php fuer das Muster {nr}). Die Bruecke selbst (bin/befehlsbruecke.php und .sh)
 * ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - pw_befehl_ausfuehren() - mit Quelle
 * 'mqtt' und gibt array(bool ok, string grund) zurueck (pw_befehl_bruecke()). Die Token-Pruefung des Endpunkts
 * ersetzen die Anmeldung am Broker und die beiden Haken (Entwurf gemeinsam E13).
 *
 * Praefix der Bruecke ist das MQTT-Praefix der ERSTEN Pumpe (Entwurf pw E2): die Bruecke abonniert genau ein
 * <praefix>/befehl/#, jede Pumpe hat ein eigenes Praefix. Kurzform watt|quittieren|anforderung fuer die erste Pumpe
 * (wie beim HTTP-Weg ohne &pumpe=), Langform <kennung>/watt usw. fuer jede Pumpe; eine unbekannte Kennung quittiert
 * die Bruecke "abgelehnt: PUMPE" (wie 400 GRUND=PUMPE am Endpunkt). Kurz- und Langform ueberschneiden sich nicht (eine
 * bzw. zwei Ebenen).
 *
 * Nur "Sperre quittieren" greift ein (Entwurf pw E3, ENTSCHEIDUNGEN Gruppe 5 Punkt 4): zweiter Haken "Sperre ueber MQTT
 * quittieren" (befehle_mqtt_steuern, ab Werk aus). Der Messwert ist die Datenlieferung des Waechters, die Anforderung
 * eine Meldung - beide nur hinter "Befehle ueber MQTT annehmen".
 */
if (!function_exists('pw_befehl_ausfuehren')) {
    $pw_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $pw_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/pw_lib.php';
    } else {
        $pw_kand[] = dirname(__DIR__) . '/webfrontend/html/pw_lib.php';
    }
    foreach ($pw_kand as $pw_k) {
        if (is_file($pw_k)) { require_once $pw_k; break; }
    }
}
$pw_ohne_sperre_watt = 'Der Sendetakt wiederholt denselben Wert alle 30 s; jede Wiederholung muss ankommen, sonst gilt der '
    . 'Zustand nach 180 s als unbekannt. Gleiche Werte im Lesepuffer legt die Bruecke ohnehin zusammen (Entwurf pw 3.2).';
$pw_ohne_sperre_anf = 'Ein - Aus - Ein binnen 60 s (kurze Laeufe) muss vollstaendig ankommen; die Sperrminute gilt je Name '
    . 'UND Wert und verschluckte das zweite Ein. Loxone sendet nur bei Aenderung (Entwurf pw 3.2).';
$pw_sperre_quitt = 'Quittieren ist wiederholbar und harmlos doppelt. 5 s fangen Prellen ab; eine zweite Betaetigung nach '
    . 'einem BELEGT muss durchgehen (Entwurf pw 3.2, Bauvorschrift Gruppe 5: Sperrzeiten 0/0/5 s).';
$pw_watt = function ($w, $t) {
    return pw_befehl_bruecke(pw_befehl_ausfuehren('wert', array('watt' => (string) $w),
                                                  isset($t['pumpe']) ? (string) $t['pumpe'] : null, 'mqtt'));
};
$pw_quitt = function ($w, $t) {
    return pw_befehl_bruecke(pw_befehl_ausfuehren('quittieren', array(),
                                                  isset($t['pumpe']) ? (string) $t['pumpe'] : null, 'mqtt'));
};
$pw_anf = function ($w, $t) {
    return pw_befehl_bruecke(pw_befehl_ausfuehren('anforderung', array('an' => (string) $w),
                                                  isset($t['pumpe']) ? (string) $t['pumpe'] : null, 'mqtt'));
};
$pw_bef = array(
    'watt'        => array('art' => 'zahl', 'min' => -5, 'max' => 100000, 'eingreifend' => false,
                           'sperre_s' => 0, 'begruendung' => $pw_ohne_sperre_watt,
                           'titel' => 'Pumpenwächter Messwert liefern (MQTT)', 'kachel' => 'BEFEHL.K_WATT',
                           'quittung' => 'ausgefuehrt', 'tun' => $pw_watt),
    'quittieren'  => array('art' => 'puls', 'eingreifend' => true, 'sperre_s' => 5, 'begruendung' => $pw_sperre_quitt,
                           'titel' => 'Pumpenwächter Sperre quittieren (MQTT)', 'kachel' => 'BEFEHL.K_QUITT',
                           'quittung' => 'ausgefuehrt', 'tun' => $pw_quitt),
    'anforderung' => array('art' => 'schalter', 'eingreifend' => false, 'sperre_s' => 0, 'begruendung' => $pw_ohne_sperre_anf,
                           'titel' => 'Pumpenwächter Pumpe angefordert (MQTT)', 'kachel' => 'BEFEHL.K_ANF',
                           'quittung' => 'ausgefuehrt', 'tun' => $pw_anf),
);
foreach (array('watt', 'quittieren', 'anforderung') as $pw_n) {
    $pw_bef['{pumpe}/' . $pw_n] = $pw_bef[$pw_n];
}
return array(
    'fassung' => 1,
    'linie'   => 'pumpenwacht',
    'plugin'  => 'Pumpenwächter',
    'geladen' => function_exists('pw_config') && function_exists('pw_befehl_ausfuehren') && function_exists('pw_befehl_bruecke'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'teile' => array('pumpe' => '[a-z0-9_]{1,32}'),
    'einstellungen' => function () {
        return pw_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        pw_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'befehle' => $pw_bef,
);
