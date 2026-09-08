<?php
/**
 * Pumpenwaechter - gemeinsame Bibliothek
 *
 * Die Rechnung steht in pw_regel.php (der Kern, ohne Netz, Datei und Uhr).
 * Hier steht alles andere: Pfade, Konfiguration mit Zweitschrift und
 * Selbstheilung, Zustand, Wortzeichen, MQTT ueber den Gateway-Relay,
 * Sprache, Vorlagen, Selbstpruefung. Kompatibel mit PHP 7.4 und 8.x.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/pw_regel.php';

/**
 * Die laufende Fassung des Plugins - aus EINER Quelle.
 *
 * Erste Wahl ist LBSystem::pluginversion(): sie liest die
 * plugindatabase.json, also das, was LoxBerry TATSAECHLICH installiert
 * hat. Rueckfallebene ist die VERSION-Zeile der plugin.cfg, und die wird
 * ZEILENWEISE gelesen - parse_ini_file scheitert an der ganzen Datei,
 * weil LoxBerry '#'-Kommentare schreibt (PHP kennt seit 7.0 nur ';')
 * und in der zweiten Zeile ein Ausrufezeichen steht. Gemessen in
 * MGiSmart, von dort uebernommen.
 *
 * Findet sich keine Quelle, bleibt sie LEER. Das ist die ehrliche
 * Antwort; eine geratene Nummer waere von einer echten nicht zu
 * unterscheiden.
 *
 * Bis 0.9.8 stand die Nummer als Konstante hier - und blieb beim Wechsel
 * auf 0.9.9 zurueck: die Sicherungsdatei trug "_fassung": "0.9.8".
 * Werkzeuge/fassung_setzen.py kennt drei .cfg und die README, von dieser
 * vierten Stelle wusste es nichts. Eine Nummer, die an zwei Stellen steht,
 * steht frueher oder spaeter verschieden.
 */
function pw_fassung()
{
    static $v = null;
    if ($v !== null) { return $v; }
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        $aus = @LBSystem::pluginversion();
        if ($aus !== null && trim((string) $aus) !== '') {
            $v = trim((string) $aus);
            return $v;
        }
    }
    /* Im entpackten Bauordner liegt die plugin.cfg zwei Ebenen ueber
     * webfrontend/html. Auf dem Geraet liegt sie NIRGENDS - LoxBerry
     * installiert sie nicht mit; dort traegt LBSystem die Antwort. */
    foreach (array(dirname(dirname(__DIR__)) . '/plugin.cfg',
                   dirname(dirname(dirname(__DIR__))) . '/plugin.cfg') as $kand) {
        if (!is_file($kand)) { continue; }
        foreach (file($kand, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $zeile) {
            if (preg_match('/^VERSION\s*=\s*([0-9][0-9A-Za-z.\-]*)/', trim($zeile), $m)) {
                $v = $m[1];
                return $v;
            }
        }
    }

    /* Nichts gefunden. Dann steht hier NICHTS - und nicht eine Nummer,
     * die es nie gab. "0.9.x" in der Sicherungsdatei saehe aus wie eine
     * Fassung und waere doch nur geraten. */
    $v = '';
    return $v;
}

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
        // Entfernt wird sie von uninstall/uninstall - sie traegt das
        // Aktionstoken, und LoxBerry raeumt nur den Ordner weg.
        'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
        'datadir'   => $home . '/data/plugins/' . $dir,
        'stand'     => $home . '/data/plugins/' . $dir . '/stand.json',
        'tage'      => $home . '/data/plugins/' . $dir . '/tage.json',
        'sperre'    => $home . '/data/plugins/' . $dir . '/stand.lock',
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

/**
 * Unteilbar schreiben; json_encode-false wird abgewiesen, nie geleert.
 *
 * Der Name der Zwischendatei traegt die Prozessnummer. Bis 0.9.7 hiess sie
 * fest "<pfad>.neu": zwei gleichzeitige Aufrufe oeffneten dieselbe Datei mit
 * Abschneiden und schrieben ineinander. Das rename() war unteilbar, der
 * INHALT nicht - genau der Fall, der bei einem Miniserver mit Wiederholungen
 * und einem Minutentakt daneben auftritt.
 */
function pw_json_schreiben($pfad, $daten, $rechte = 0664)
{
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    /* Fragen statt anlegen: ein mkdir auf einen vorhandenen Ordner meldet
     * 'File exists'. Das @ unterdrueckt die Anzeige, aber ein eigener
     * Fehler-Aufnehmer sieht sie trotzdem - und dann steht sie bei JEDEM
     * Schreibvorgang im Protokoll. Gefunden von rendern.py. */
    $pw_d = dirname($pfad);
    if (!is_dir($pw_d)) { @mkdir($pw_d, 0775, true); }
    $tmp = $pfad . '.' . getmypid() . '.neu';
    if (@file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
    @chmod($tmp, $rechte);
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/* ==================================================================
 * Die EINE Positivliste zulaessiger Werte
 * ==================================================================
 *
 * Sie wird an drei Stellen benutzt: vom Speichern-Handler der Oberflaeche,
 * von der Sicherung beim Zurueckspielen und von der Selbstpruefung. Eine
 * zweite Wahrheit ueber zulaessige Werte gibt es nicht.
 *
 * Bis 0.9.7 pruefte nur das Formular. pw_sicherung_lesen() uebernahm den
 * Wert ungeprueft - gemessen 28.08.2026 gingen stale_s = -999 (das Plugin
 * wird blind), an_w = "kein wert" (stiller Rueckfall auf 20 W) und ein
 * mqtt_topic mit Zeilenumbruch (siehe pw_mqtt_publish) anstandslos durch.
 *
 * art: 'zahl' mit (min, max) | 'haken' 0/1 | 'text' mit Muster | 'wahl'
 */
function pw_grenzen()
{
    return array(
        'modell'             => array('art' => 'wahl', 'werte' => array('frei', '3-35', '3-45')),
        /* Der Name steht in der Wahlleiste und sonst nirgends - er geht in
         * kein Thema und in keine Adresse. Erlaubt ist deshalb viel; was
         * nicht erlaubt ist, sind Steuerzeichen und eine Laenge, die die
         * Leiste sprengt. */
        'name'               => array('art' => 'text', 'muster' => '#^[^\x00-\x1f]{0,40}$#u'),
        'art'                => array('art' => 'wahl', 'werte' => array('hauswasser', 'entwaesserung')),
        'ruht_s'             => array('art' => 'zahl', 'min' => 0, 'max' => 2678400),
        'quelle'             => array('art' => 'wahl', 'werte' => array('loxone', 'mqtt')),
        /* Das Thema darf ein # oder + tragen - es ist ein Abo-Muster, kein
         * Veroeffentlichungsziel. Gesaeubert wird es trotzdem: es geht als
         * Argument an mosquitto_sub.
         *
         * DAS TRENNZEICHEN IST EINE TILDE, NICHT DIE RAUTE. Alle anderen
         * Muster hier stehen zwischen Rauten - dieses kann es nicht, weil
         * die Raute IM Muster vorkommt. Mit '#...#' sah PHP das # in der
         * Zeichenklasse als Ende des Musters und meldete
         * 'Unknown modifier ]'; preg_match gab false zurueck, jedes Thema
         * galt als unzulaessig, und die Sicherung liess sich nicht mehr
         * zurueckspielen. Gefunden von Werkzeuge/sicherung_wirkung.py, nicht
         * beim Lesen. */
        'quelle_topic'       => array('art' => 'text', 'muster' => '~^[\w/\-+#]{0,128}$~'),
        'an_w'               => array('art' => 'zahl', 'min' => 1,  'max' => 5000),
        'trocken_w'          => array('art' => 'zahl', 'min' => 0,  'max' => 5000),
        'trocken_s'          => array('art' => 'zahl', 'min' => 5,  'max' => 3600),
        'ueberlast_w'        => array('art' => 'zahl', 'min' => 0,  'max' => 5000),
        'dauerlauf_s'        => array('art' => 'zahl', 'min' => 0,  'max' => 86400),
        'starts_h'           => array('art' => 'zahl', 'min' => 0,  'max' => 1000),
        'anlauf_s'           => array('art' => 'zahl', 'min' => 0,  'max' => 3600),
        'stale_s'            => array('art' => 'zahl', 'min' => 30, 'max' => 86400),
        'sperren_ein'        => array('art' => 'haken'),
        'sperre_trockenlauf' => array('art' => 'haken'),
        'sperre_dauerlauf'   => array('art' => 'haken'),
        'sperre_schaltspiel' => array('art' => 'haken'),
        'sperre_ueberlast'   => array('art' => 'haken'),
        'sperre_kein_anlauf' => array('art' => 'haken'),
        'sperre_ruht'        => array('art' => 'haken'),
        'quittung_noetig'    => array('art' => 'haken'),
        'mqtt_ein'           => array('art' => 'haken'),
        'mqtt_topic'         => array('art' => 'text', 'muster' => '#^[\w/\-]{1,64}$#'),
        'aktionstoken'       => array('art' => 'text', 'muster' => '#^[0-9a-f]{0,64}$#'),
        'formgeheim'         => array('art' => 'text', 'muster' => '#^[0-9a-f]{0,64}$#'),
    );
}

/**
 * Taugt der Wert ueberhaupt fuer diese Konfiguration?
 *
 * Die erste von zwei Wachen (REGELN_2, "Jeder Wert der Sicherungsdatei wird
 * geprueft, nicht nur der Schluessel"). Sie fragt nicht, ob der Wert fachlich
 * passt, sondern ob er als Wert taugt: kein Feld, kein Objekt, nicht
 * uferlos, keine Steuerzeichen. Ein Zeilenumbruch in einem Wert erzeugt in
 * einer zeilenorientierten Datei - und im UDP-Datagramm des MQTT-Gateways -
 * eine zusaetzliche Zeile.
 */
function pw_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_null($v)) { return false; }
    if (is_bool($v)) { return true; }
    $s = (string) $v;
    if (strlen($s) > 4096) { return false; }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/**
 * Die zweite Wache: ist der Wert fuer DIESEN Schluessel zulaessig?
 *
 * Rueckgabe: array(bereinigter Wert, '') oder array(null, 'Grund').
 * Der Grund ist ein Sprachschluessel mit Platzhaltern, damit der Aufrufer
 * ihn uebersetzen kann.
 */
function pw_wert_pruefen($schluessel, $wert)
{
    $g = pw_grenzen();
    if (!isset($g[$schluessel])) { return array(null, 'unbekannt'); }
    if (!pw_wert_taugt($wert)) { return array(null, 'untauglich'); }
    $r = $g[$schluessel];
    if ($r['art'] === 'zahl') {
        $s = str_replace(',', '.', trim((string) $wert));
        if ($s === '' || !is_numeric($s)) { return array(null, 'keine_zahl'); }
        $f = (float) $s;
        if (!is_finite($f) || $f < $r['min'] || $f > $r['max']) {
            return array(null, 'bereich:' . $r['min'] . ':' . $r['max']);
        }
        return array($f, '');
    }
    if ($r['art'] === 'haken') {
        $s = trim((string) $wert);
        if (!in_array($s, array('0', '1', '', 'true', 'false'), true)) {
            return array(null, 'kein_haken');
        }
        return array(($s === '1' || $s === 'true') ? 1 : 0, '');
    }
    if ($r['art'] === 'wahl') {
        $s = trim((string) $wert);
        if (!in_array($s, $r['werte'], true)) { return array(null, 'keine_wahl'); }
        return array($s, '');
    }
    // text
    $s = trim((string) $wert);
    if (!preg_match($r['muster'], $s)) { return array(null, 'muster'); }
    return array($s, '');
}

/** Vorgaben. Die Zahlen der Modelle stehen im Kern (pw_modelle, Datenblatt). */
function pw_vorgaben()
{
    return array(
        'modell'          => 'frei',
        /* Ohne Angabe ein Hauswasserwerk - das war bis 0.9.14 die einzige
         * Bauform, die pw_modelle() kannte. */
        'art'             => 'hauswasser',
        /* 0 = aus. Der Ruhebefund meldet erst, wenn jemand eine Frist
         * eintraegt; ein Waechter, der ungefragt Alarm gibt, wird
         * abgeschaltet. */
        'ruht_s'          => 0,
        /* Woher der Messwert kommt.
         *
         * 'loxone' ist die Vorgabe und bleibt es: neue Funktionen ab Werk
         * aus. Wer aktualisiert, aendert damit nichts an einer laufenden
         * Anlage - der Weg ueber den Virtuellen Ausgang arbeitet weiter.
         *
         * 'mqtt' laesst das Plugin den Zwischenzaehler unmittelbar am
         * Broker mithoeren. Der Umweg ueber den Miniserver faellt damit
         * weg, und Spannung, Strom und Frequenz kommen mit. */
        'quelle'          => 'loxone',
        'quelle_topic'    => '',
        'an_w'            => 20,
        'trocken_w'       => 0,
        'trocken_s'       => 30,
        'ueberlast_w'     => 0,
        'dauerlauf_s'     => 1800,
        'starts_h'        => 25,
        // 0 = aus. Die Anlaufueberwachung braucht ein Anforderungssignal aus
        // Loxone (aktion=anforderung); ohne sie bleibt der Zweig aus.
        // Neue Funktionen ab Werk aus (REGELN_1, Abschnitt 5).
        'anlauf_s'        => 0,
        'sperren_ein'     => 0,
        'sperre_trockenlauf' => 1,
        'sperre_dauerlauf'   => 0,
        'sperre_schaltspiel' => 0,
        'sperre_ueberlast'   => 1,
        'sperre_kein_anlauf' => 1,
        'sperre_ruht'     => 1,
        'quittung_noetig' => 1,
        // Nach so vielen Sekunden ohne Anlieferung gilt der Zustand als
        // unbekannt (Befund "still") - der Ausfall des Zwischenzaehlers
        // darf nie wie eine ruhende Anlage aussehen.
        'stale_s'         => 300,
        'mqtt_ein'        => 1,
        'mqtt_topic'      => 'pumpe',
        'aktionstoken'    => '',
        /* Grundlage des Formularmerkmals. Steht bewusst NEBEN dem
         * Aktionstoken und nicht an seiner Stelle: das Aktionstoken geht in
         * jede Loxone-Adresse, dieses hier geht nirgendwohin. */
        'formgeheim'      => '',
    );
}

/**
 * Konfiguration mit Selbstheilung aus der Zweitschrift (Hausmuster).
 *
 * $erzeugen = false schaltet die Selbstheilung ab. Der UNANGEMELDETE
 * Endpunkt ruft sie so: bis 0.9.7 lief die Heilung dort VOR der
 * Tokenpruefung, und eine einzige tokenlose Anfrage stellte die
 * Konfiguration aus der Zweitschrift wieder her. Gemessen 28.08.2026 -
 * Antwort korrekt 403, Datei trotzdem geschrieben. Siehe REGELN_2,
 * "Der unangemeldete Endpunkt legt auch nichts AN".
 */
/** Welche Schluessel gehoeren nach OBEN, welche zur Pumpe? */
function pw_global_schluessel()
{
    /* Die Geheimnisse gelten fuer das Plugin, nicht fuer eine Pumpe - sie
     * stehen in den Adressen, die der Miniserver aufruft. Und mqtt_ein ist
     * der Schalter "veroeffentlicht dieses Plugin ueberhaupt"; unter welchem
     * Praefix, entscheidet die Pumpe. */
    return array('aktionstoken', 'formgeheim', 'mqtt_ein');
}

/** Die Werksvorgaben EINER Pumpe. */
function pw_vorgaben_pumpe()
{
    $global = array_flip(pw_global_schluessel());
    $p = array_diff_key(pw_vorgaben(), $global);
    /* Kennung und Name gibt es erst seit 1.0.0. */
    return array_merge(array('id' => 'pumpe', 'name' => ''), $p);
}

/**
 * Die Wanderung: aus einer flachen Konfiguration wird eine mit Pumpenliste.
 *
 * Sie ist ein FESTPUNKT - zweimal angewandt kommt dasselbe heraus. Das
 * prueft `pw10_wanderung.py` ausdruecklich nach, weil eine Wanderung, die
 * bei jedem Aufruf noch einmal wandert, den Zustand langsam zerlegt.
 *
 * Drei Faelle:
 *   - es gibt schon 'pumpen'  -> nur Kennungen und Namen auffuellen
 *   - es gibt alte flache Schluessel -> genau EINE Pumpe daraus bauen
 *   - es gibt gar nichts -> eine Pumpe mit Werksvorgaben
 *
 * Der dritte Fall weicht bewusst von der Skizze ab, die dort eine LEERE
 * Liste vorsah. Eine frische Installation ohne Pumpe waere eine Oberflaeche
 * ohne Inhalt; bis 0.9.14 gab es immer genau eine, und dabei bleibt es.
 */
function pw_wandern($d, $vorgaben_fuellen = true)
{
    $d = is_array($d) ? $d : array();
    $global = pw_global_schluessel();

    if (isset($d['pumpen']) && is_array($d['pumpen'])) {
        $liste = array_values($d['pumpen']);
    } else {
        /* Alles, was nicht global ist, gehoert der einen alten Pumpe. Ein
         * unbekannter Schluessel wandert MIT - er wird an anderer Stelle
         * beanstandet, aber hier nicht stillschweigend weggeworfen. */
        $eine = array_diff_key($d, array_flip($global));
        /* OHNE $vorgaben_fuellen bleibt die Pumpe SPARSAM. Sie bekommt nur
         * das, was eine Pumpe ueberhaupt ansprechbar macht: eine Kennung.
         *
         * Der Unterschied ist beim Zurueckspielen alles: eine Sicherung aus
         * 0.9.14 mit einem einzigen Schluessel wurde sonst zu einer Pumpe
         * mit ALLEN Werksvorgaben aufgefuellt - und die sahen danach aus
         * wie Werte, die in der Datei standen. Modell, Trockenlaufschwelle
         * und Sperren fielen still auf Werk zurueck. */
        $vp_id = pw_vorgaben_pumpe();
        $grund = $vorgaben_fuellen ? pw_vorgaben_pumpe()
                                   : array('id' => $vp_id['id']);
        $liste = $eine ? array(array_merge($grund, $eine)) : array($grund);
    }

    /* Kennungen: vorhandene behalten, fehlende vergeben, doppelte aufloesen. */
    $vergeben = array();
    foreach ($liste as $i => $p) {
        $id = isset($p['id']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $p['id'])) : '';
        if ($id === '' || isset($vergeben[$id])) {
            $id = 'pumpe' . ($i + 1);
            $n = 2;
            while (isset($vergeben[$id])) { $id = 'pumpe' . ($i + 1) . '_' . $n++; }
        }
        $vergeben[$id] = true;
        $liste[$i]['id'] = $id;
        /* Auch hier: nur auffuellen, wenn das ausdruecklich gewollt ist.
         * Ein erfundener leerer Name ueberschriebe beim Zurueckspielen den
         * Namen, den die Pumpe traegt - und die Datei hatte dazu gar nichts
         * gesagt. */
        if ($vorgaben_fuellen) {
            if (!isset($liste[$i]['name']) || trim((string) $liste[$i]['name']) === '') {
                $liste[$i]['name'] = '';
            }
            $liste[$i] = array_merge(pw_vorgaben_pumpe(), $liste[$i]);
        }
    }

    $neu = array();
    $vorg = pw_vorgaben();
    foreach ($global as $g) {
        if (array_key_exists($g, $d)) {
            $neu[$g] = $d[$g];
        } elseif ($vorgaben_fuellen) {
            $neu[$g] = $vorg[$g];
        }
        /* Ohne $vorgaben_fuellen bleibt ein nicht genannter globaler
         * Schluessel ABWESEND. Fuer eine Sicherungsdatei ist das der
         * Unterschied zwischen "die Datei sagt dazu nichts" und "die Datei
         * sagt: leer" - und der zweite Fall loescht ein Geheimnis. */
    }
    $neu['pumpen'] = $liste;
    return $neu;
}

/**
 * Das Gegenstueck zu pw_pumpe(): eine flache Sicht wieder auseinanderlegen.
 *
 * Der Handler bearbeitet eine flache Konfiguration - so, wie er es immer
 * getan hat - und gibt sie hier zurueck. Globales geht nach oben, alles
 * andere in die genannte Pumpe. Schluessel, die weder das eine noch das
 * andere sind, werden verworfen: sie kaemen aus einem Formular und haetten
 * die Positivliste ohnehin nicht bestanden.
 *
 * Ohne Kennung trifft es die erste Pumpe - dasselbe wie bei pw_pumpe().
 */
function pw_pumpe_zurueck($cfg, $flach, $id = null)
{
    $global = pw_global_schluessel();
    $vp = pw_vorgaben_pumpe();
    $liste = isset($cfg['pumpen']) && is_array($cfg['pumpen'])
           ? array_values($cfg['pumpen']) : array();
    if (!$liste) { $liste = array(pw_vorgaben_pumpe()); }

    $i = 0;
    if ($id !== null) {
        foreach ($liste as $k => $p) {
            if (isset($p['id']) && (string) $p['id'] === (string) $id) { $i = $k; break; }
        }
    }

    foreach ($global as $g) {
        if (array_key_exists($g, $flach)) { $cfg[$g] = $flach[$g]; }
    }
    foreach ($flach as $k => $v) {
        if (in_array($k, $global, true)) { continue; }
        if ($k === 'id' || $k === 'name' || array_key_exists($k, $vp)) {
            $liste[$i][$k] = $v;
        }
    }
    $cfg['pumpen'] = $liste;
    return $cfg;
}

/**
 * Eine Pumpe anlegen.
 *
 * Die Kennung wird aus der Zahl der vorhandenen gebildet und so lange
 * hochgezaehlt, bis sie frei ist - "pumpe2", "pumpe3". Sie dient zugleich
 * als MQTT-Praefix: sie ist eindeutig, und pw_praefixe_pruefen() vergleicht
 * ABSCHNITTE, so dass "pumpe" und "pumpe2" einander nicht verschlucken.
 *
 * Die neue Pumpe steht auf Quelle "loxone" ohne Quell-Thema - ab Werk aus.
 * Wer ihr das Thema der angesehenen Pumpe mitgaebe, haette sofort zwei
 * Pumpen auf demselben Zaehler, und beide zaehlten.
 *
 * Rueckgabe: die Kennung der neuen Pumpe, oder null bei Schreibfehler.
 */
function pw_pumpe_anlegen($cfg = null, $name = null)
{
    $cfg = $cfg === null ? pw_config() : $cfg;
    $ids = pw_pumpe_ids($cfg);
    $n = count($ids) + 1;
    $id = 'pumpe' . $n;
    while (in_array($id, $ids, true)) { $id = 'pumpe' . (++$n); }

    $neu = pw_vorgaben_pumpe();
    $neu['id'] = $id;
    $neu['name'] = $name === null || trim((string) $name) === ''
                 ? 'Pumpe ' . $n : trim((string) $name);
    $neu['mqtt_topic'] = $id;
    $neu['quelle'] = 'loxone';
    $neu['quelle_topic'] = '';

    if (!isset($cfg['pumpen']) || !is_array($cfg['pumpen'])) {
        $cfg['pumpen'] = array();
    }
    $cfg['pumpen'][] = $neu;
    if (!pw_config_speichern($cfg)) { return null; }
    pw_log('Pumpe angelegt: ' . $id . ' (' . $neu['name'] . ').');
    return $id;
}

/**
 * Eine Pumpe entfernen - samt ihrem Zustand und ihrer Tagesbilanz.
 *
 * DIE LETZTE BLEIBT. Ohne Pumpe waere die Oberflaeche leer, und die
 * Wanderung legte beim naechsten Lesen ohnehin wieder eine an - das Loeschen
 * saehe aus wie ein Zuruecksetzen auf Werkseinstellung.
 *
 * Zustand und Tagesbilanz gehen MIT. Bliebe der Unterbaum liegen, so erbte
 * eine spaeter mit derselben Kennung angelegte Pumpe fremde Zaehlerstaende.
 *
 * Rueckgabe: true, oder ein Grund als Zeichenkette.
 */
function pw_pumpe_entfernen($id, $cfg = null)
{
    $cfg = $cfg === null ? pw_config() : $cfg;
    $ids = pw_pumpe_ids($cfg);
    if (!in_array((string) $id, $ids, true)) { return 'unbekannt'; }
    if (count($ids) < 2) { return 'letzte'; }

    $liste = array();
    foreach ($cfg['pumpen'] as $p) {
        if (isset($p['id']) && (string) $p['id'] === (string) $id) { continue; }
        $liste[] = $p;
    }
    $cfg['pumpen'] = $liste;
    if (!pw_config_speichern($cfg)) { return 'speichern'; }

    /* Zustand und Tagesbilanz nachziehen. Ein Fehlschlag hier ist KEIN
     * Fehlschlag des Entfernens - die Pumpe ist fort, und ein liegen
     * gebliebener Unterbaum ist ein Schoenheitsfehler, kein Datenverlust.
     * Er wird protokolliert, damit er auffaellt. */
    $voll = pw_stand_voll();
    if (isset($voll['pumpen'][$id])) {
        unset($voll['pumpen'][$id]);
        if (!pw_stand_voll_speichern($voll)) {
            pw_log('Pumpe ' . $id . ' entfernt, ihr Zustand blieb liegen.');
        }
    }
    $tv = pw_tage_voll();
    if (isset($tv['pumpen'][$id])) {
        unset($tv['pumpen'][$id]);
        if (!pw_json_schreiben(pw_paths()['tage'], $tv)) {
            pw_log('Pumpe ' . $id . ' entfernt, ihre Tagesbilanz blieb liegen.');
        }
    }
    pw_log('Pumpe entfernt: ' . $id . '.');
    return true;
}

/**
 * Waechter: hier gehoert die FLACHE Sicht einer Pumpe hin.
 *
 * Wer die volle Konfiguration uebergibt, bekommt sonst keine Fehlermeldung -
 * in der vollen Form gibt es kein trocken_w, und die Funktion nimmt ihre
 * Vorgabe. Genau so schrieb die Loxone-Vorlage Werksvorgaben in eine Datei,
 * die der Anwender in Config einliest.
 *
 * Repariert wird, nicht abgebrochen: diese Funktionen haengen am
 * unangemeldeten Endpunkt, und ein Absturz dort waere schlimmer als eine
 * falsche Zahl. Protokolliert wird trotzdem - eine stille Reparatur ist
 * das, was die Falle teuer macht.
 */
function pw_flach($cfg, $wer = '')
{
    if (is_array($cfg) && isset($cfg['pumpen'])) {
        pw_log('BAUFEHLER: ' . $wer . ' bekam die volle Konfiguration statt der '
             . 'flachen Sicht einer Pumpe - es wurde die erste genommen.');
        return pw_pumpe($cfg);
    }
    return $cfg;
}

/** Die Kennungen aller Pumpen, in ihrer Reihenfolge. */
function pw_pumpe_ids($cfg)
{
    $aus = array();
    foreach (isset($cfg['pumpen']) ? $cfg['pumpen'] : array() as $p) {
        if (isset($p['id'])) { $aus[] = (string) $p['id']; }
    }
    return $aus;
}

/**
 * Die FLACHE Sicht einer Pumpe - genau das, was der Rechenkern erwartet.
 *
 * Globales wird hineingemischt, damit eine Funktion, die bisher
 * $cfg['aktionstoken'] las, unveraendert weiterlaeuft. Ohne Kennung kommt
 * die erste Pumpe; das ist der Weg, auf dem alle bisherigen Aufrufstellen
 * ohne Aenderung weiterarbeiten.
 */
function pw_pumpe($cfg, $id = null)
{
    $liste = isset($cfg['pumpen']) && is_array($cfg['pumpen']) ? $cfg['pumpen'] : array();
    $gewaehlt = null;
    if ($id !== null) {
        foreach ($liste as $p) {
            if (isset($p['id']) && (string) $p['id'] === (string) $id) { $gewaehlt = $p; break; }
        }
    }
    if ($gewaehlt === null) { $gewaehlt = $liste ? $liste[0] : pw_vorgaben_pumpe(); }
    $oben = array();
    foreach (pw_global_schluessel() as $g) {
        if (array_key_exists($g, $cfg)) { $oben[$g] = $cfg[$g]; }
    }
    return array_merge(pw_vorgaben_pumpe(), $gewaehlt, $oben);
}

/**
 * Verschlucken sich zwei Themenpraefixe?
 *
 * Verglichen werden ABSCHNITTE, nicht Zeichenketten: "pumpe" und "pumpe2"
 * sind verschieden, "pumpe" und "pumpe/a" nicht. Wer hier auf strpos()
 * prueft, verbietet dem Anwender einen Namen, der nie kollidieren wuerde.
 *
 * Rueckgabe: Liste von Beanstandungen (leer = in Ordnung).
 */
function pw_praefixe_pruefen($cfg)
{
    $mangel = array();
    $liste = isset($cfg['pumpen']) ? $cfg['pumpen'] : array();
    $n = count($liste);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = trim((string) (isset($liste[$i]['mqtt_topic']) ? $liste[$i]['mqtt_topic'] : ''));
            $b = trim((string) (isset($liste[$j]['mqtt_topic']) ? $liste[$j]['mqtt_topic'] : ''));
            if ($a === '' || $b === '') { continue; }
            if ($a === $b || strpos($a . '/', $b . '/') === 0
                          || strpos($b . '/', $a . '/') === 0) {
                $mangel[] = $a . ' / ' . $b;
            }
        }
    }
    return $mangel;
}

function pw_config($erzeugen = true)
{
    $p = pw_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if ($erzeugen && ($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        /* NACHSEHEN statt das @ arbeiten lassen.
         *
         * Das @ unterdrueckt die AUSGABE, nicht das EREIGNIS - ein eigener
         * Fehler-Aufnehmer sieht die Warnung trotzdem, und auf dem LoxBerry
         * stuende sie dann mitten in einer fremden Seite. Im Normalfall
         * (Verzeichnis da, Konfiguration fehlt) loesten hier alle drei
         * Zeilen eine aus; gemessen von Werkzeuge/rendern.py am
         * 08.09.2026.
         *
         * Dieselbe Klasse wie bei pw_json_schreiben() und pw_log(). Das @
         * bleibt fuer den unvorhergesehenen Fall stehen, aber es ist nicht
         * mehr der Normalweg. */
        if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
        $vorher = $p['config'] . '.vorher';
        if (is_file($p['config'])) { @copy($p['config'], $vorher); }
        if (@copy($p['sicherung'], $p['config'])) {
            @chmod($p['config'], 0600);
            pw_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
        }
        if (is_file($vorher)) { @unlink($vorher); }
    }
    /* Gewandert wird bei JEDEM Lesen - nicht nur beim Schreiben. Sonst
     * haenge die Form davon ab, ob zufaellig einmal gespeichert wurde. */
    return pw_wandern(pw_json_lesen($p['config']));
}

function pw_config_speichern($cfg)
{
    $p = pw_paths();
    /* Fail closed: faellt EIN Wert durch, wird gar nichts geschrieben -
     * nicht "den einen weglassen".
     *
     * Seit 1.0.0 ist 'pumpen' ein Feld von Feldern, und pw_wert_taugt()
     * weist Felder ab. Ohne diese Unterscheidung haette der Schutz die
     * eigene neue Form abgelehnt - stumm, denn er gibt nur false zurueck.
     * Gefunden von pw10_wanderung.py, bevor es jemand am Geraet gemerkt
     * haette.
     *
     * Lockerer wird der Schutz dadurch NICHT: in einer Pumpe muss weiterhin
     * jeder Wert ein Skalar sein, eine zweite Verschachtelungsstufe faellt
     * durch, und ein 'pumpen', das gar kein Feld ist, ebenso.
     *
     * Die Schleifenvariable heisst $pumpe und NICHT $p: $p ist in dieser
     * Funktion pw_paths(). Beim ersten Versuch hiess sie $p, ueberschrieb
     * die Pfade, und danach lief $p['config'] ins Leere - "Undefined array
     * key config", und gespeichert wurde nichts. */
    foreach ($cfg as $k => $v) {
        if ($k === 'pumpen') {
            if (!is_array($v)) { return false; }
            foreach ($v as $pumpe) {
                if (!is_array($pumpe)) { return false; }
                foreach ($pumpe as $pv) {
                    if (!pw_wert_taugt($pv)) { return false; }
                }
            }
            continue;
        }
        if (!pw_wert_taugt($v)) { return false; }
    }
    if (!pw_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    if (@copy($p['config'], $p['sicherung'])) {
        /* Die Zweitschrift traegt dasselbe Geheimnis wie die Konfiguration
         * und bekommt deshalb dieselben Rechte. Bis 0.9.7 entstand sie durch
         * copy() mit den Vorgaberechten des Systems - ueblicherweise 0644. */
        @chmod($p['sicherung'], 0600);
    }
    return true;
}

/**
 * Die Konfiguration VERVOLLSTAENDIGEN, nicht nur beim Lesen ergaenzen.
 *
 * array_merge() beim Lesen macht "fehlt" von "steht auf dem Vorgabewert"
 * ununterscheidbar, und eine Umbenennung waere still. Diese Funktion
 * schreibt fehlende Schluessel EINMAL in die Datei und sagt es im Protokoll;
 * die Selbstpruefung zeigt danach "vollstaendig: 19 von 19".
 *
 * Rueckgabe: array(Konfiguration, fehlten[], fremd[]).
 */
function pw_cfg_vervollstaendigen()
{
    $p = pw_paths();
    $roh = pw_json_lesen($p['config']);
    $fehlten = array();
    $fremd = array();
    /* Global und je Pumpe getrennt zaehlen. Ein Schluessel, der bei Pumpe 2
     * fehlt, ist etwas anderes als einer, der oben fehlt - und "19 von 19"
     * waere eine Zahl ohne Aussage, wenn sie beides vermengte. */
    $cfg = pw_wandern($roh);
    foreach (pw_global_schluessel() as $g) {
        if (!array_key_exists($g, $roh)) { $fehlten[] = $g; }
    }
    $vp = pw_vorgaben_pumpe();
    $alt_flach = !isset($roh['pumpen']);
    /* Die Schleifenvariable heisst $pumpe, NICHT $p: $p ist in dieser
     * Funktion pw_paths(). Ich habe genau diesen Fehler in derselben Runde
     * ZWEIMAL gemacht - hier und in pw_config_speichern(). Beide Male lief
     * danach $p['config'] ins Leere, beide Male hat ein Werkzeug es
     * gefunden und nicht ich. */
    foreach ($cfg['pumpen'] as $i => $pumpe) {
        $quelle = $alt_flach ? $roh : (isset($roh['pumpen'][$i]) ? $roh['pumpen'][$i] : array());
        foreach ($vp as $k => $v) {
            if (!array_key_exists($k, $quelle)) { $fehlten[] = $pumpe['id'] . '.' . $k; }
        }
        foreach ($quelle as $k => $v) {
            if (!array_key_exists($k, $vp) && !in_array($k, pw_global_schluessel(), true)) {
                $fremd[] = $pumpe['id'] . '.' . $k;
            }
        }
    }
    if (!$alt_flach) {
        foreach ($roh as $k => $v) {
            if ($k !== 'pumpen' && !in_array($k, pw_global_schluessel(), true)) {
                $fremd[] = $k;
            }
        }
    }
    if ($fehlten && is_file($p['config'])) {
        if (pw_config_speichern($cfg)) {
            pw_log('Konfiguration vervollstaendigt: ' . implode(', ', $fehlten));
        }
    }
    return array($cfg, $fehlten, $fremd);
}

/**
 * Die Kennung der ERSTEN Pumpe - der Ort, an den ein alter flacher Zustand
 * wandert. Faellt auf 'pumpe' zurueck, wenn noch keine Konfiguration da ist.
 */
function pw_erste_id()
{
    $ids = pw_pumpe_ids(pw_config(false));
    return $ids ? $ids[0] : 'pumpe';
}

/**
 * Einen Zustandsbaum wandern: flach -> nach Pumpen getrennt.
 *
 * Festpunkt: was schon 'pumpen' traegt, bleibt unangetastet. Ein flacher
 * Zustand landet VOLLSTAENDIG unter der ersten Pumpe - er traegt laufende
 * Zahlen, die sich nicht rekonstruieren lassen.
 */
function pw_zustand_wandern($d, $erste = null)
{
    $d = is_array($d) ? $d : array();
    if (isset($d['pumpen']) && is_array($d['pumpen'])) { return $d; }
    if (!$d) { return array('pumpen' => array()); }
    $erste = $erste === null ? pw_erste_id() : $erste;
    return array('pumpen' => array($erste => $d));
}

/** Der ganze Zustandsbaum, alle Pumpen. */
function pw_stand_voll()
{
    return pw_zustand_wandern(pw_json_lesen(pw_paths()['stand']));
}

function pw_stand_voll_speichern($voll)
{
    return pw_json_schreiben(pw_paths()['stand'], $voll);
}

/**
 * Der FLACHE Zustand einer Pumpe - genau das, was der Rechenkern erwartet.
 *
 * Eine Pumpe ohne Unterbaum bekommt einen LEEREN Zustand, nicht den einer
 * anderen. Das ist die Eigenschaft, auf die es ankommt: sonst zaehlten zwei
 * Pumpen auf denselben Zaehler, und niemand saehe es.
 */
function pw_stand($id = null)
{
    $voll = pw_stand_voll();
    $id = $id === null ? pw_erste_id() : $id;
    return isset($voll['pumpen'][$id]) && is_array($voll['pumpen'][$id])
         ? $voll['pumpen'][$id] : array();
}

/**
 * Den flachen Zustand einer Pumpe zurueckschreiben.
 *
 * Liest die ganze Datei, legt den eigenen Unterbaum hinein, schreibt sie
 * zurueck. Der Aufrufer haelt dabei die Sperre - dieselbe wie bisher, denn
 * es ist dieselbe Datei.
 */
function pw_stand_speichern($stand, $id = null)
{
    $voll = pw_stand_voll();
    $id = $id === null ? pw_erste_id() : $id;
    if (!isset($voll['pumpen']) || !is_array($voll['pumpen'])) {
        $voll['pumpen'] = array();
    }
    $voll['pumpen'][$id] = $stand;
    return pw_stand_voll_speichern($voll);
}

/* ==================================================================
 * Sperre um lesen-rechnen-schreiben
 * ==================================================================
 *
 * Bis 0.9.7 las der Endpunkt den Stand, rechnete und schrieb zurueck - ohne
 * jede Sperre. Zwei Anlieferungen, die denselben Ausgangsstand lesen: die
 * eine setzt eine Trockenlaufsperre, die andere loescht sie, weil sie den
 * alten Stand fortschreibt. Und weil das Protokoll nur beim WECHSEL
 * schreibt, steht dort "SPERRE gesetzt" und nichts ueber ihr Verschwinden.
 *
 * Nicht blockierend nach dem Hausmuster (fer_sperre): wer nicht drankommt,
 * geht kommentarlos wieder - der naechste Takt kommt ohnehin gleich.
 */
function pw_sperre_holen()
{
    $p = pw_paths();
    @mkdir($p['datadir'], 0775, true);
    $fh = @fopen($p['sperre'], 'c');
    if (!$fh) {
        /* Bis 0.9.10 stand hier "return null" - und beide Aufrufer pruefen
         * nur auf === false. Bei null lief das ganze Lesen-Rechnen-Schreiben
         * OHNE Sperre durch, pw_sperre_geben(null) tat nichts, und der
         * Rueckgabewert meldete Erfolg. Gemessen 31.08.2026 auf beiden
         * PHP-Fassungen (Sperrpfad als Ordner angelegt): "grund='' , stand
         * geschrieben: true".
         *
         * Damit war genau der Zustand wieder da, den 0.9.8 beseitigt hat -
         * Cron-Takt, Zuhoerer und Oberflaeche schreiben gleichzeitig -, nur
         * ohne Meldung. Auf dem Geraet tritt das ein, wenn der Datenordner
         * nicht beschreibbar ist: falscher Eigentuemer nach einem Rueckspiel
         * von Hand, oder eine SD-Karte, die nach einem Dateisystemfehler
         * nur noch lesend eingehaengt ist.
         *
         * Jetzt: derselbe Rueckgabewert wie bei einer belegten Sperre, damit
         * die Aufrufer abbrechen - und EINE Zeile im Protokoll je Prozess,
         * denn stumm bleiben darf das nicht. */
        static $gemeldet = false;
        if (!$gemeldet) {
            $gemeldet = true;
            pw_log('SPERRE nicht zu oeffnen: ' . $p['sperre']
                   . ' - der Datenordner ist vermutlich nicht beschreibbar.'
                   . ' Es wird NICHTS geschrieben, solange das so ist.');
        }
        return false;
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) { @fclose($fh); return false; }
    return $fh;
}

function pw_sperre_geben($fh)
{
    if (is_resource($fh)) { @flock($fh, LOCK_UN); @fclose($fh); }
}

/**
 * Einen Messwert verarbeiten - lesen, rechnen, schreiben, unter Sperre.
 *
 * $watt === null heisst "kein neuer Messwert" (der Minutentakt). Dann wird
 * der letzte bekannte Wert fortgeschrieben, solange er nicht veraltet ist -
 * und sonst faellt der Zustand auf "unbekannt", und GENAU DAS geht dann auch
 * hinaus. Bis 0.9.7 wirkte das Veralten nur beim Lesen; in Loxone stand
 * weiter laeuft=1, befund=0.
 *
 * Gerechnet, gesendet und geschrieben wird HIER - unter EINER Sperre und
 * in EINEM Schreibvorgang. Bis zur Messung vom 28.08.2026 gab
 * pw_publizieren() die Signatur nur zurueck, und allein der Minutentakt
 * schrieb sie in den Zustand; am Endpunkt war sie deshalb bei jedem Aufruf
 * leer, und der Doppelt-senden-Filter griff nie. Gemessen: Lauf 1 20
 * Themen, Lauf 2 mit demselben Wert wieder 20.
 *
 * Rueckgabe: array(neuer Stand|null, 'grund', versucht, gescheitert)
 */
function pw_verarbeiten($watt, $cfg, $jetzt = null, $quelle = 'endpunkt',
                        $erzwingen = false, $neben = array())
{
    $cfg = pw_flach($cfg, 'pw_verarbeiten');
    $jetzt = $jetzt === null ? time() : $jetzt;
    $fh = pw_sperre_holen();
    if ($fh === false) { return array(null, 'belegt', 0, 0); }
    try {
        /* Die Kennung kommt aus der flachen Sicht - pw_pumpe() legt sie
         * hinein. Ohne sie schriebe jeder Durchlauf in die ERSTE Pumpe, und
         * zwei Pumpen zaehlten auf denselben Zaehler. */
        $pw_pid = isset($cfg['id']) ? (string) $cfg['id'] : null;
        $alt = pw_stand($pw_pid);
        $stale = (float) pw_zahl(isset($cfg['stale_s']) ? $cfg['stale_s'] : 300, 300.0);
        $letzte = pw_zahl(isset($alt['quelle_ts']) ? $alt['quelle_ts'] : 0, 0.0);

        if ($watt === null) {
            /* Kein neuer Messwert. Der letzte gilt weiter, solange er nicht
             * veraltet ist - damit laufen Laufzeit, Dauerlauf und
             * Trockenlauf auch dann weiter, wenn Loxone nur bei Aenderung
             * sendet. Ist er veraltet, wird NICHTS behauptet. */
            $frisch = ($letzte > 0 && ($jetzt - $letzte) <= $stale);
            $mess_watt = $frisch && isset($alt['watt']) ? $alt['watt'] : null;
        } else {
            $mess_watt = $watt;
        }

        $mess = array(
            'watt'      => $mess_watt,
            'tag'       => date('Y-m-d', (int) $jetzt),
            'tagbeginn' => (float) strtotime(date('Y-m-d', (int) $jetzt) . ' 00:00:00'),
        );
        $neu = pw_schritt($mess, $cfg, $alt, $jetzt);

        /* pw_schritt() baut den Zustand FRISCH auf und uebernimmt aus dem
         * alten nur, was es zum Rechnen braucht - das ist richtig so, der
         * Kern soll von Buchhaltung nichts wissen. Sie muss deshalb hier
         * hinweggerettet werden, wo beide Staende nebeneinanderliegen.
         *
         * Bis zur Messung vom 28.08.2026 geschah das nicht. Folge eins:
         * mqtt_sig und status_zaehler fielen bei jedem Durchlauf heraus,
         * der Doppelt-senden-Filter griff nie, und der Zaehler stand
         * dauerhaft auf 0 - ausgerechnet der Wert, an dem Loxone einen
         * Ausfall erkennen soll. Folge zwei, und die ist schwerer: ein
         * Klick auf "Wartung durchgefuehrt" war beim naechsten Minutentakt
         * wieder fort, und zwar lautlos - die Zaehler "seit der letzten
         * Wartung" standen danach einfach wieder bei den Gesamtzahlen. */
        foreach (array('wartung_ts', 'wartung_lauf_s', 'wartung_starts',
                       'mqtt_sig', 'status_zaehler',
                       'volt', 'ampere', 'hertz', 'quelle_online') as $pw_bk) {
            if (isset($alt[$pw_bk])) { $neu[$pw_bk] = $alt[$pw_bk]; }
        }

        /* Nebenwerte des MQTT-Weges. Sie kommen nur mit, wenn sie
         * mitgeschickt wurden - ein fehlender Wert loescht den bisherigen
         * NICHT. Der Shelly schickt in manchen Meldungen nur die Zaehler. */
        foreach (array($neben) as $pw_nb) {
            if (!is_array($pw_nb)) { continue; }
            foreach (array('volt', 'ampere', 'hertz', 'quelle_online') as $pw_k) {
                if (isset($pw_nb[$pw_k]) && $pw_nb[$pw_k] !== null) {
                    $neu[$pw_k] = $pw_nb[$pw_k];
                }
            }
        }
        if ($watt !== null) {
            $neu['watt'] = (float) $watt;
            $neu['quelle_ts'] = (float) $jetzt;
            $neu['anlieferungen'] = pw_anlieferung_merken(
                isset($alt['anlieferungen']) ? $alt['anlieferungen'] : array(), $jetzt);
        } else {
            $neu['watt'] = isset($alt['watt']) ? $alt['watt'] : null;
            $neu['quelle_ts'] = $letzte;
            /* Auch ohne neue Anlieferung wird die Liste ausgeduennt.
             * pw_anlieferung_merken() wirft Eintraege aelter als 24 h
             * heraus - bis 0.9.10 geschah das nur, wenn ein Messwert kam.
             * Blieb die Quelle dauerhaft stumm, fror der Reiter Bilanz auf
             * einem tage- oder wochenalten Bestand ein und zeigte weiter
             * "zyklisch, mittlerer Abstand N s", als waere das der
             * aktuelle Takt. */
            $neu['anlieferungen'] = pw_anlieferung_stutzen(
                isset($alt['anlieferungen']) ? $alt['anlieferungen'] : array(), $jetzt);
        }
        /* Einen abgeschlossenen Tag in die Bilanz legen und den Schluessel
         * wieder entfernen - er gehoert nicht in den laufenden Zustand. */
        if (isset($neu['vortag'])) {
            pw_tag_ablegen($neu['vortag'], isset($cfg['id']) ? $cfg['id'] : null);
            unset($neu['vortag']);
        }
        /* Protokoll: Befundwechsel und Sperren, wie bisher - und seit 0.9.8
         * auch das VERSCHWINDEN einer Sperre und ein Uhrsprung. */
        $alt_b = isset($alt['befund']) ? $alt['befund'] : '';
        if ($neu['befund'] !== $alt_b && $neu['befund'] !== PW_OK) {
            pw_log('Befund: ' . $neu['befund'] . ' (Beiwert ' . round($neu['beiwert'], 1)
                   . ', ' . ($mess_watt === null ? 'kein Messwert' : $mess_watt . ' W') . ')');
        }
        if (!empty($neu['sperre']) && empty($alt['sperre'])) {
            pw_log('SPERRE gesetzt: ' . $neu['sperrgrund']);
        }
        if (empty($neu['sperre']) && !empty($alt['sperre'])) {
            pw_log('Sperre aufgehoben (Befund unauffaellig, keine Quittungspflicht).');
        }
        if (!empty($neu['zeitsprung']) && empty($alt['zeitsprung'])) {
            pw_log('Zeitsprung erkannt: ' . (int) $neu['zeitsprung']
                   . ' Startzeitpunkt(e) liegen in der Zukunft. Die Schaltspielzaehlung '
                   . 'laesst sie aus, bis die Uhr sie eingeholt hat.');
        }
        /* Senden, BEVOR geschrieben wird - die Signatur und der Zaehler
         * gehoeren in denselben Schreibvorgang. Sonst stuende im Zustand
         * eine Signatur, die zu nichts gehoert, oder es braeuchte einen
         * zweiten Schreibvorgang neben der Sperre. */
        list($versucht, $fehl, $sig, $zaehler) =
            pw_publizieren($neu, $cfg, $jetzt, $erzwingen);
        $neu['mqtt_sig'] = $sig;
        $neu['status_zaehler'] = $zaehler;
        if (!pw_stand_speichern($neu, $pw_pid)) { return array(null, 'speichern', $versucht, $fehl); }
        return array($neu, '', $versucht, $fehl);
    } finally {
        pw_sperre_geben($fh);
    }
}

/**
 * Den Zustand unter Sperre aendern und das Ergebnis veroeffentlichen.
 *
 * Fuer Quittieren, Anforderung und Wartung: sie liefern keinen Messwert,
 * aendern aber den Zustand - und wer den Zustand aendert, sendet auch. Ohne
 * das stuende in Loxone bis zum naechsten Minutentakt eine Sperre, die
 * gerade aufgehoben wurde.
 *
 * $aendern bekommt den alten Stand und gibt den neuen zurueck.
 */
function pw_zustand_aendern($aendern, $cfg, $erzwingen = true, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : $jetzt;
    $fh = pw_sperre_holen();
    if ($fh === false) { return array(null, 'belegt', 0, 0); }
    try {
        $pw_pid = isset($cfg['id']) ? (string) $cfg['id'] : null;
        $neu = call_user_func($aendern, pw_stand($pw_pid));
        if (!is_array($neu)) { return array(null, 'speichern', 0, 0); }
        list($versucht, $fehl, $sig, $zaehler) =
            pw_publizieren($neu, $cfg, $jetzt, $erzwingen);
        $neu['mqtt_sig'] = $sig;
        $neu['status_zaehler'] = $zaehler;
        if (!pw_stand_speichern($neu, $pw_pid)) { return array(null, 'speichern', $versucht, $fehl); }
        return array($neu, '', $versucht, $fehl);
    } finally {
        pw_sperre_geben($fh);
    }
}

/**
 * Die Liste der Anlieferungen ausduennen, ohne eine neue einzutragen.
 *
 * Getrennt von pw_anlieferung_merken(), damit der Taktweg sie ebenfalls
 * kurz haelt - siehe dort.
 */
function pw_anlieferung_stutzen($liste, $jetzt, $hoechstens = 120)
{
    $aus = array();
    foreach ((array) $liste as $t) {
        $z = pw_zahl($t, 0.0);
        if ($z > 0 && ($jetzt - $z) <= 86400 && $z <= $jetzt + 1) { $aus[] = $z; }
    }
    if (count($aus) > $hoechstens) { $aus = array_slice($aus, -$hoechstens); }
    return $aus;
}

/** Die letzten Anlieferungszeitpunkte - Grundlage der Taktmessung. */
function pw_anlieferung_merken($liste, $jetzt, $hoechstens = 120)
{
    $out = array();
    foreach ((array) $liste as $t) {
        $t = pw_zahl($t, 0.0);
        if ($t > 0 && $t <= $jetzt && ($jetzt - $t) <= 86400) { $out[] = $t; }
    }
    $out[] = (float) $jetzt;
    sort($out);
    if (count($out) > $hoechstens) { $out = array_slice($out, -$hoechstens); }
    return $out;
}

/**
 * Wie liefert Loxone an - zyklisch oder bei Aenderung?
 *
 * Diese Frage laesst sich in Loxone Config nur muehsam beantworten, und eine
 * Zahl in einer Anleitung waere geraten. Deshalb misst das Plugin sie selbst
 * (REGELN_1: "statt einer Zahl in der Anleitung gehoert ein Knopf in die
 * Oberflaeche, der misst").
 *
 * Rueckgabe: anzahl, spanne_s, kuerzester, laengster, mittlerer (Median),
 * urteil ('zyklisch' | 'bei_aenderung' | 'zu_wenig').
 */
function pw_takt($stand = null)
{
    $stand = $stand === null ? pw_stand() : $stand;
    $l = array();
    foreach ((array) (isset($stand['anlieferungen']) ? $stand['anlieferungen'] : array()) as $t) {
        $t = pw_zahl($t, 0.0);
        if ($t > 0) { $l[] = $t; }
    }
    sort($l);
    $aus = array('anzahl' => count($l), 'spanne_s' => 0, 'kuerzester' => 0,
                 'laengster' => 0, 'mittlerer' => 0, 'urteil' => 'zu_wenig');
    if (count($l) < 5) { return $aus; }
    $abst = array();
    for ($i = 1; $i < count($l); $i++) { $abst[] = $l[$i] - $l[$i - 1]; }
    sort($abst);
    $aus['spanne_s']   = (int) round($l[count($l) - 1] - $l[0]);
    $aus['kuerzester'] = (int) round($abst[0]);
    $aus['laengster']  = (int) round($abst[count($abst) - 1]);
    $aus['mittlerer']  = (int) round($abst[intdiv(count($abst), 2)]);
    /* Das Urteil ist eine Faustregel, KEINE Messung - deshalb stehen die
     * drei Zahlen immer daneben, und der Anwender kann sie selbst lesen.
     * Zyklisch heisst: der laengste Abstand ist nicht wesentlich groesser
     * als der mittlere. */
    $m = max(1, $aus['mittlerer']);
    $aus['urteil'] = ($aus['laengster'] <= 2 * $m) ? 'zyklisch' : 'bei_aenderung';
    return $aus;
}

/* ---------------- Tagesbilanz ---------------- */

/**
 * Die Tageszahlen eines abgeschlossenen Tages ablegen, 60 Tage lang.
 *
 * Fuer eine Pumpe ist die Reihe ueber Wochen die eigentliche Auskunft: eine
 * Laufzeit, die langsam steigt, ist ein Mikroleck; eine Startzahl, die
 * steigt, ein wasserschlagendes Ventil.
 */
/** Die Tagesbilanz aller Pumpen, gewandert wie der Zustand. */
function pw_tage_voll()
{
    $d = pw_json_lesen(pw_paths()['tage']);
    if (isset($d['pumpen']) && is_array($d['pumpen'])) { return $d; }
    /* Alte Form: { "tage": [ ... ] } - sie gehoert der ersten Pumpe. */
    if (isset($d['tage']) && is_array($d['tage'])) {
        return array('pumpen' => array(pw_erste_id() => array('tage' => $d['tage'])));
    }
    return array('pumpen' => array());
}

function pw_tag_ablegen($vortag, $id = null, $hoechstens = 60)
{
    if (!is_array($vortag) || !isset($vortag['tag']) || $vortag['tag'] === '') { return false; }
    $p = pw_paths();
    $voll = pw_tage_voll();
    $id = $id === null ? pw_erste_id() : $id;
    $liste = isset($voll['pumpen'][$id]['tage']) && is_array($voll['pumpen'][$id]['tage'])
           ? $voll['pumpen'][$id]['tage'] : array();
    $ersetzt = false;
    foreach ($liste as $i => $e) {
        if (isset($e['tag']) && $e['tag'] === $vortag['tag']) {
            $liste[$i] = $vortag;
            $ersetzt = true;
            break;
        }
    }
    if (!$ersetzt) {
        $liste[] = $vortag;
        usort($liste, function ($a, $b) {
            return strcmp((string) $a['tag'], (string) $b['tag']);
        });
        if (count($liste) > $hoechstens) {
            $liste = array_slice($liste, -$hoechstens);
        }
    }
    $voll['pumpen'][$id] = array('tage' => $liste);
    return pw_json_schreiben($p['tage'], $voll);
}

function pw_tage($anzahl = 14, $id = null)
{
    $voll = pw_tage_voll();
    $id = $id === null ? pw_erste_id() : $id;
    $l = isset($voll['pumpen'][$id]['tage']) && is_array($voll['pumpen'][$id]['tage'])
       ? $voll['pumpen'][$id]['tage'] : array();
    return array_slice(array_reverse($l), 0, $anzahl);
}

function pw_vortag($gestern = null, $id = null)
{
    $l = pw_tage(1, $id);
    if (!$l) { return null; }
    $v = $l[0];
    /* Ohne Datumsangabe wie bisher: der juengste gespeicherte Tag. Mit
     * Datumsangabe wird geprueft, ob er wirklich gestern ist - solange der
     * Cron laeuft, wird taeglich ein Satz abgelegt und beides faellt
     * zusammen; nach einem Stillstand von Tagen nicht mehr. */
    if ($gestern !== null
        && (string) (isset($v['tag']) ? $v['tag'] : '') !== (string) $gestern) {
        return null;
    }
    return $v;
}

/* ---------------- Wartung ---------------- */

/**
 * Die Wartungsmarken setzen - unter der Sperre, ueber pw_zustand_aendern.
 *
 * Ohne die Sperre koennte ein Minutentakt dazwischenschreiben und die
 * Marke sofort wieder ueberschreiben. Und veroeffentlicht wird auch: die
 * Betriebsstunden gehen nach Loxone, und wer sie zurueckstellt, will das
 * dort sehen und nicht erst in einer Minute.
 */
function pw_wartung_setzen($cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    list($neu, ) = pw_zustand_aendern(function ($s) {
        $s['wartung_ts'] = time();
        $s['wartung_lauf_s'] = pw_zahl(isset($s['lauf_s_gesamt']) ? $s['lauf_s_gesamt'] : 0, 0.0);
        $s['wartung_starts'] = (int) pw_zahl(isset($s['starts_gesamt']) ? $s['starts_gesamt'] : 0, 0.0);
        return $s;
    }, $cfg);
    return $neu !== null;
}

function pw_wartung($stand = null)
{
    $s = $stand === null ? pw_stand() : $stand;
    $ges_s = pw_zahl(isset($s['lauf_s_gesamt']) ? $s['lauf_s_gesamt'] : 0, 0.0);
    $ges_n = (int) pw_zahl(isset($s['starts_gesamt']) ? $s['starts_gesamt'] : 0, 0.0);
    $ab_s  = pw_zahl(isset($s['wartung_lauf_s']) ? $s['wartung_lauf_s'] : 0, 0.0);
    $ab_n  = (int) pw_zahl(isset($s['wartung_starts']) ? $s['wartung_starts'] : 0, 0.0);
    return array(
        'gesamt_h'     => round($ges_s / 3600.0, 1),
        'gesamt_starts'=> $ges_n,
        'seit_h'       => round(max(0.0, $ges_s - $ab_s) / 3600.0, 1),
        'seit_starts'  => max(0, $ges_n - $ab_n),
        'wartung_ts'   => (int) pw_zahl(isset($s['wartung_ts']) ? $s['wartung_ts'] : 0, 0.0),
    );
}

/* ---------------- Protokoll ---------------- */

function pw_log($text)
{
    $p = pw_paths();
    /* Fragen statt anlegen - dieselbe Stelle wie in pw_json_schreiben(),
     * dort seit 0.9.11 behoben. Ein mkdir auf einen vorhandenen Ordner
     * meldet "File exists"; das @ unterdrueckt die ANZEIGE, ein eigener
     * Fehler-Aufnehmer sieht sie trotzdem. Hier fiel es nie auf, weil
     * pw_log() beim Rendern nicht lief - erst der Umbau auf mehrere Pumpen
     * hat die Zeile erreicht, und rendern.py hat sie sofort gemeldet. */
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* Kappung nach dem Hausmuster (fer_log, FerienFeiertage): ab 500 KiB
     * bleiben die letzten 200 Zeilen stehen. Ohne sie waechst die Datei
     * unbegrenzt - auf einem LoxBerry mit SD-Karte ist das kein
     * Schoenheitsfehler. */
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/* Der Name traegt "log_lesen", damit hausstandard_pruefen.py die Leseseite
 * erkennt - bis 0.9.7 hiess sie pw_log_ende(), und die Spalte "log" stand
 * deshalb auf einem Strich, obwohl geschrieben UND angezeigt wurde. Ein
 * Strich sieht aus wie eine Kleinigkeit. */
function pw_log_lesen($anzahl = 300)
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

/* ==================================================================
 * Der Wachposten am Formular
 * ==================================================================
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit.
 *
 * Bis 0.9.7 hatte dieses Plugin KEINEN Wachposten, obwohl der Kommentar ueber
 * dem Handlerblock einen zusicherte. Gemessen am 28.08.2026 an einem echten
 * Webserver: ein POST ohne jedes Merkmal hat das Protokoll geleert und die
 * vollstaendige Konfiguration SAMT AKTIONSTOKEN als Datei geliefert.
 *
 * Einen einzelnen Handler kann man beim Erweitern vergessen, einen
 * Wachposten am Eingang nicht.
 */
function pw_formtoken($cfg = null)
{
    if ($cfg === null) { $cfg = pw_pumpe(pw_config()); }
    /* Das Merkmal haengt an einem EIGENEN Geheimnis, nicht mehr am
     * Aktionstoken.
     *
     * Bis 0.9.10 stand hier hash_hmac(..., $cfg['aktionstoken']) - eine
     * reine Funktion des Aktionstokens. Dasselbe Aktionstoken steht aber in
     * JEDER Adresse, die der Miniserver aufruft, also alle paar Sekunden im
     * Klartext im LAN, ausserdem im Loxone-Projekt, in beiden erzeugten
     * Vorlagen und in der Sicherungsdatei. Der Wachposten schuetzte damit
     * gegen jeden, der den am breitesten gestreuten Wert des Plugins NICHT
     * kennt.
     *
     * Gemessen 31.08.2026 gegen einen laufenden Webserver: Merkmal aus dem
     * Aktionstoken nachgerechnet, POST damit -> HTTP 200 und die
     * vollstaendige Konfiguration samt Token als Download.
     *
     * Das Geheimnis geht nie hinaus: es steht in keiner Adresse, in keiner
     * Vorlage und wird auf der Seite nicht angezeigt. Fehlt es (alte
     * Konfiguration), faellt der Wachposten auf das Aktionstoken zurueck -
     * das ist der bisherige Stand und immer noch besser als gar nichts;
     * die Oberflaeche legt beim ersten Aufruf eines an. */
    $grund = isset($cfg['formgeheim']) ? (string) $cfg['formgeheim'] : '';
    if ($grund === '') {
        $grund = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    }
    if ($grund === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $grund);
}

function pw_formtoken_ok($cfg = null)
{
    $soll = pw_formtoken($cfg);
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return ($soll !== '' && hash_equals($soll, $ist));
}

/* ---------------- MQTT (Regelweg) ---------------- */

/**
 * Zustand und FASSUNG des LoxBerry-MQTT-Gateways - EINE Funktion.
 *
 * Bis 0.9.7 lasen zwei Funktionen dieselbe Datei auf zwei verschiedenen
 * Wegen. REGELN_2 verlangt ausdruecklich, die Autostart-Pruefung auf
 * dieselbe Funktion zu ziehen; MGiSmart 1.1.3 macht es so
 * (mg_mqtt_gateway_info).
 *
 * Die Fassung steht als Mqtt.Gatewayversion in config/system/general.json
 * (ab Werk 1) und entscheidet, was der Anwender eintragen muss:
 *   V1  Das Abo wird von Hand eingetragen - ohne den Eintrag kommt am
 *       Miniserver nichts an. Das ist die haeufigste Fehlerursache ueberhaupt.
 *   V2  Das Gateway erkennt die Themengruppe selbst; in den Subscriptions
 *       werden nur noch die gewuenschten Datenpunkte angehakt.
 *
 * 'gefunden' = false heisst "nicht feststellbar" - dann wird KEINE der
 * beiden Fassungen behauptet.
 */
function pw_mqtt_gateway_info()
{
    /* Der Zwischenspeicher bekommt eine Verfallszeit.
     *
     * Bis 0.9.10 galt er prozessweit. Fuer die kurzlebigen Prozesse (Web,
     * Cron-Takt) ist das richtig; der Zuhoerer lebt aber bis zu 86400 s.
     * Aendert sich dort der UDP-Eingang des Gateways oder wird das Gateway
     * abgeschaltet, veroeffentlichte er bis zum geplanten Tagesende weiter
     * an den alten Port - und meldete dabei fehl = 0, weil ein fwrite auf
     * ein UDP-Datagramm immer gelingt. Die Wache des Zuhoerers half nicht:
     * sie beobachtet pumpenwacht.json, nicht general.json. */
    static $m = null;
    static $bis = 0;
    if ($m !== null && time() < $bis) { return $m; }
    $bis = time() + 60;
    $p = pw_paths();
    $m = array('gefunden' => false, 'udpport' => 0, 'autostart' => false, 'fassung' => 0);
    if (!is_file($p['general'])) { return $m; }
    $d = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($d)) { return $m; }
    $ab = null;
    foreach (array('Mqtt', 'mqtt') as $k) {
        if (isset($d[$k]) && is_array($d[$k])) { $ab = $d[$k]; break; }
    }
    if ($ab === null) { return $m; }
    $m['gefunden'] = true;
    $m['udpport'] = isset($ab['Udpinport']) ? (int) $ab['Udpinport'] : 0;
    // NICHT 'Autostart' - den Schluessel gibt es nicht (Fehlerklasse ACTiKamera 1.9.2).
    $m['autostart'] = in_array((string) (isset($ab['Gatewayautostart']) ? $ab['Gatewayautostart'] : ''),
                               array('1', 'true'), true);
    foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
        if (isset($ab[$sl]) && (string) $ab[$sl] !== '') { $m['fassung'] = (int) $ab[$sl]; break; }
    }
    return $m;
}

/* pw_mqtt_zustand() gab es bis 0.9.7 als zweiten Leser derselben Datei. Sie
 * ist ersatzlos entfallen, nicht als Alias stehengeblieben: ein Helfer, den
 * niemand ruft, ist ein Zweig, den kein Fall erreicht - und beim naechsten
 * Lesen fragt sich jemand, welcher der beiden Leser der richtige ist. */
function pw_gateway_fassung() { $m = pw_mqtt_gateway_info(); return $m['fassung']; }

/** Zeilenumbrueche und Tabulatoren zerlegen den Gateway-UDP-Weg (Hausstandard). */
function pw_mqtt_wert_saeubern($v)
{
    $w = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $w));
}

/**
 * Das Thema saeubern - an EINER Stelle, und die ist hier.
 *
 * Bis 0.9.7 saeuberte nur der Speichern-Handler der Oberflaeche. Ueber eine
 * zurueckgespielte Sicherung ging ein Thema mit Zeilenumbruch durch, und das
 * UDP-Datagramm des Gateways enthielt danach drei Zeilen statt einer -
 * gemessen am 28.08.2026 mit einem echten UDP-Horcher:
 *
 *     publish pumpe/x
 *     steuerung/pumpe/schalten 1
 *     q/laeuft 1
 *
 * Die mittlere ist ein vollwertiger publish-Befehl auf ein frei gewaehltes
 * Thema. Wer eine Wache fuer die eine Haelfte einer Zeile baut, baut sie fuer
 * die andere gleich mit (REGELN_2).
 */
function pw_mqtt_thema_saeubern($t)
{
    $s = preg_replace('#[^\w/\-]#', '', (string) $t);
    $s = trim((string) $s, '/');
    return $s !== '' ? substr($s, 0, 64) : 'pumpe';
}

function pw_mqtt_thema($cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    return pw_mqtt_thema_saeubern(isset($cfg['mqtt_topic']) ? $cfg['mqtt_topic'] : 'pumpe');
}

/**
 * Ueber den UDP-Eingang des Gateways veroeffentlichen.
 *
 * OHNE socket_create(). Die Erweiterung sockets ist auf einem LoxBerry nicht
 * zugesichert, und ein fehlendes socket_create() ist KEIN abfangbarer
 * Fehler, sondern ein toedlicher: "Call to undefined function". Das @ davor
 * hilft nicht.
 *
 * Gemessen am 28.08.2026: derselbe Endpunktaufruf antwortete ohne die
 * Erweiterung mit HTTP 500 und 0 Byte, mit ihr mit OK=1. Ein Virtueller
 * Ausgang wertet die Antwort nicht aus - der Ausfall waere still, und zwar
 * auf dem Weg, der die Messwerte traegt.
 *
 * stream_socket_client() gehoert zum Kern und tut dasselbe (REGELN_2,
 * "socket_* nur mit php-sockets in dpkg/apt - besser gar nicht").
 *
 * Rueckgabe: array(versucht, gescheitert) - der Aufrufer zaehlt beides, und
 * die Schlussmeldung nennt beides getrennt.
 */
function pw_mqtt_publish($paare, $cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    if (empty($cfg['mqtt_ein'])) { return array(0, 0); }
    $m = pw_mqtt_gateway_info();
    if (!$m['udpport']) { return array(0, 0); }
    $topic = pw_mqtt_thema($cfg);
    $fp = @stream_socket_client('udp://127.0.0.1:' . (int) $m['udpport'],
                                $errno, $errstr, 2);
    if (!$fp) { return array(0, count($paare)); }
    @stream_set_timeout($fp, 2);
    $versucht = 0; $fehl = 0;
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') { continue; }
        $msg = 'publish ' . $topic . '/' . pw_mqtt_wert_saeubern($k)
             . ' ' . pw_mqtt_wert_saeubern($v);
        $versucht++;
        $n = @fwrite($fp, $msg);
        if ($n === false || $n < strlen($msg)) { $fehl++; }
    }
    @fclose($fp);
    return array($versucht, $fehl);
}

/**
 * Die Signatur ueber die Werte - Grundlage des Doppelt-senden-Filters.
 *
 * Das LEBENSZEICHEN gehoert NICHT hinein. Ein Wert, der sich jede Sekunde
 * aendert (ein Alter, ein Zeitstempel, ein Zaehler), macht den Filter
 * wirkungslos: jeder Durchgang schickte wieder alle Themen. Das steht in
 * REGELN_1 als eigener Fehler vom 27.08.2026.
 *
 * SPANNUNG, STROM UND FREQUENZ gehoeren aus demselben Grund nicht hinein,
 * und das ist gemessen, nicht vermutet. Am 28.08.2026 lieferte der Shelly
 * ueber zwei Minuten:
 *
 *     voltage 231.8 -> 232.0 -> 232.5      freq 50.05 -> 50.03 -> 50.01
 *
 * Die Netzspannung wandert dauernd. Stuende sie in der Signatur, waere
 * sie bei JEDER Minutenmeldung eine andere - und der Filter schickte
 * jedes Mal wieder den vollen Satz. Genau das ist die ALTER-Falle, nur
 * mit anderen Zahlen.
 *
 * Sie gehen deshalb NUR mit, wenn ohnehin gesendet wird - also wenn sich
 * am Zustand der Pumpe etwas geaendert hat. Das kostet nichts: sie sind
 * eine Diagnosehilfe (eine Unterspannung erklaert, warum eine Pumpe nicht
 * anlaeuft), keine Groesse, auf die eine Regelung wartet. Wer sie
 * fortlaufend braucht, abonniert das Thema des Zaehlers selbst - es liegt
 * ja auf demselben Broker.
 */
function pw_signatur($felder)
{
    $ohne = $felder;
    foreach (array('status_ts', 'status_zaehler', 'status_ok', 'quelle_ts',
                   'volt', 'ampere', 'hertz') as $k) {
        unset($ohne[$k]);
    }
    return md5(json_encode($ohne));
}

/**
 * Veroeffentlichen mit Doppelt-senden-Filter und Lebenszeichen.
 *
 * Bis 0.9.7 ging bei JEDER Anlieferung der volle Satz hinaus - bei
 * 10-Sekunden-Takt rund 95000 Nachrichten am Tag.
 *
 * Das Lebenszeichen geht bei JEDEM Durchgang hinaus, auch unveraendert: ein
 * virtueller Eingang behaelt seinen letzten Wert, bei MQTT mit Retain sogar
 * ueber jeden Neustart des Miniservers hinweg. Stirbt der Takt, steht in
 * Loxone weiter der Zustand vom Zeitpunkt des Ausfalls - das ist keine
 * fehlende Auskunft, sondern eine Falschaussage, und sie sieht aus wie eine
 * richtige (REGELN_2).
 *
 * Ueber MQTT gibt es KEIN Alter, nur einen Zeitstempel. Bis 0.9.7 ging ein
 * Thema "alter" hinaus, und weil pw_schritt() 'zeit' auf denselben
 * Augenblick setzt, trug es rechnerisch IMMER 0 - gemessen am 28.08.2026:
 * "publish pumpe/alter 0". Der Miniserver rechnet das Alter selbst:
 * Alter = (Loxone-Zeit + 1230768000) - ts.
 */
function pw_publizieren($stand, $cfg, $jetzt = null, $erzwingen = false)
{
    $jetzt = $jetzt === null ? time() : $jetzt;
    $felder = pw_felder($stand, $cfg, $jetzt);
    $sig = pw_signatur($felder);
    $alt_sig = isset($stand['mqtt_sig']) ? (string) $stand['mqtt_sig'] : '';
    $zaehler = ((int) pw_zahl(isset($stand['status_zaehler']) ? $stand['status_zaehler'] : -1, -1.0) + 1) % 1000;

    $senden = ($erzwingen || $sig !== $alt_sig) ? $felder : array();
    /* Das Lebenszeichen - immer. */
    $senden['status_ts'] = (int) $jetzt;
    $senden['status_zaehler'] = $zaehler;
    $senden['status_ok'] = ($felder['laeuft'] === -1) ? 0 : 1;
    $senden['status_quelle_ts'] = (int) pw_zahl(isset($stand['quelle_ts']) ? $stand['quelle_ts'] : 0, 0.0);

    list($versucht, $fehl) = pw_mqtt_publish($senden, $cfg);
    return array($versucht, $fehl, $sig, $zaehler);
}

/** Befund als Zahl fuer Loxone. Die Zuordnung steht im Reiter "Einbindung". */
function pw_befund_zahl($befund)
{
    $map = array(PW_OK => 0, PW_SCHALTSPIEL => 1, PW_DAUERLAUF => 2,
                 PW_TROCKEN => 3, PW_UEBERLAST => 4, PW_STILL => 5,
                 PW_KEIN_ANLAUF => 6, PW_RUHT => 7);
    return isset($map[$befund]) ? $map[$befund] : 5;
}

/** Umgekehrt: die Zahl auf den Sprachschluessel. EINE Quelle fuer beides. */
function pw_befund_schluessel()
{
    return array(0 => 'BEFUND.OK', 1 => 'BEFUND.SCHALTSPIEL', 2 => 'BEFUND.DAUERLAUF',
                 3 => 'BEFUND.TROCKENLAUF', 4 => 'BEFUND.UEBERLAST',
                 5 => 'BEFUND.STILL', 6 => 'BEFUND.KEIN_ANLAUF',
                 7 => 'BEFUND.RUHT');
}

/* ==================================================================
 * Die Feldliste - EINE Quelle fuer MQTT, Textzeilen, Vorlage und Tabelle
 * ==================================================================
 *
 * Bis 0.9.7 stand sie dreimal: in pw_felder(), noch einmal als Literal in
 * pw_vorlage_vi() und ein drittes Mal als HTML-Tabelle in der Oberflaeche.
 * Alle drei stimmten ueberein - aber ein zwoelftes Feld waere weder in der
 * Vorlage noch in der Tabelle erschienen, und der Kommentar behauptete "eine
 * Quelle". Der Reiter Test zaehlt die Uebereinstimmung jetzt nach.
 *
 * Je Feld: signed (fuer die Vorlage), min, max, einheit, Sprachschluessel
 * der Bedeutung.
 */
function pw_felderliste()
{
    return array(
        'laeuft'         => array('signed' => true,  'min' => -1, 'max' => 1,          'einheit' => '<v.0>',     'bed' => 'LOX.B_LAEUFT'),
        'befund'         => array('signed' => false, 'min' => 0,  'max' => 7,          'einheit' => '<v.0>',     'bed' => 'LOX.B_BEFUND'),
        'beiwert'        => array('signed' => true,  'min' => -1, 'max' => 86400,      'einheit' => '<v.1>',     'bed' => 'LOX.B_BEIWERT'),
        'sperre'         => array('signed' => false, 'min' => 0,  'max' => 1,          'einheit' => '<v.0>',     'bed' => 'LOX.B_SPERRE'),
        'sperrgrund'     => array('signed' => false, 'min' => 0,  'max' => 7,          'einheit' => '<v.0>',     'bed' => 'LOX.B_SPERRGRUND'),
        'quittung'       => array('signed' => false, 'min' => 0,  'max' => 1,          'einheit' => '<v.0>',     'bed' => 'LOX.B_QUITTUNG'),
        'watt'           => array('signed' => true,  'min' => -1, 'max' => 5000,       'einheit' => '<v.1> W',   'bed' => 'LOX.B_WATT'),
        'lauf_s'         => array('signed' => false, 'min' => 0,  'max' => 86400,      'einheit' => '<v.0> s',   'bed' => 'LOX.B_LAUF_S'),
        'letzter_lauf_s' => array('signed' => true,  'min' => -1, 'max' => 86400,      'einheit' => '<v.0> s',   'bed' => 'LOX.B_LETZTER'),
        'lauf_s_tag'     => array('signed' => false, 'min' => 0,  'max' => 86400,      'einheit' => '<v.0> s',   'bed' => 'LOX.B_TAG'),
        'starts_tag'     => array('signed' => false, 'min' => 0,  'max' => 1000,       'einheit' => '<v.0>',     'bed' => 'LOX.B_STARTS'),
        'laengster_tag'  => array('signed' => false, 'min' => 0,  'max' => 86400,      'einheit' => '<v.0> s',   'bed' => 'LOX.B_LAENGSTER'),
        'lauf_s_vortag'  => array('signed' => true,  'min' => -1, 'max' => 86400,      'einheit' => '<v.0> s',   'bed' => 'LOX.B_TAG_VOR'),
        'starts_vortag'  => array('signed' => true,  'min' => -1, 'max' => 1000,       'einheit' => '<v.0>',     'bed' => 'LOX.B_STARTS_VOR'),
        'betrieb_h'      => array('signed' => false, 'min' => 0,  'max' => 200000,     'einheit' => '<v.1> h',   'bed' => 'LOX.B_BETRIEB'),
        'zeitsprung'     => array('signed' => false, 'min' => 0,  'max' => 1000,       'einheit' => '<v.0>',     'bed' => 'LOX.B_ZEITSPRUNG'),
        /* Die drei kommen NUR ueber den MQTT-Weg mit - auf dem Weg ueber
         * Loxone traegt die Anlieferung nur Watt. Sie stehen dann auf -1,
         * und -1 heisst hier wie ueberall 'nicht bekannt', nicht 'null'.
         * Eine Unterspannung ist ein eigener Grund, warum eine Pumpe nicht
         * anlaeuft - und ohne diese Zahl waere er nicht zu sehen. */
        'volt'           => array('signed' => true,  'min' => -1, 'max' => 500,        'einheit' => '<v.1> V',   'bed' => 'LOX.B_VOLT'),
        'ampere'         => array('signed' => true,  'min' => -1, 'max' => 100,        'einheit' => '<v.2> A',   'bed' => 'LOX.B_AMPERE'),
        'hertz'          => array('signed' => true,  'min' => -1, 'max' => 100,        'einheit' => '<v.2> Hz',  'bed' => 'LOX.B_HERTZ'),
        'quelle_online'  => array('signed' => true,  'min' => -1, 'max' => 1,          'einheit' => '<v.0>',     'bed' => 'LOX.B_QUELLE_ONLINE'),
        /* NEU in 1.0.0, und ausdruecklich ANS ENDE: ein neues Feld in der
         * Mitte verschiebt die Reihenfolge in der Statuszeile, und jede beim
         * Anwender eingetragene Befehlserkennung zeigte danach auf den
         * falschen Wert (REGELN_2).
         *
         * -1 heisst "noch nie einen Lauf gesehen" - nicht "null Sekunden".
         * Genau diese Unterscheidung entscheidet, ob nach einer frischen
         * Installation ein Alarm kommt, den es nicht gibt. */
        'ruht_s'         => array('signed' => true,  'min' => -1, 'max' => 2678400,    'einheit' => '<v.0> s',   'bed' => 'LOX.B_RUHT_S'),
    );
}

/** Die Themen des Lebenszeichens - eigene Liste, eigene Vorlagenzeilen. */
function pw_statusliste()
{
    return array(
        'status_ok'        => array('signed' => false, 'min' => 0, 'max' => 1,          'einheit' => '<v.0>',   'bed' => 'LOX.B_ST_OK'),
        'status_ts'        => array('signed' => false, 'min' => 0, 'max' => 2147483647, 'einheit' => '<v.0>',   'bed' => 'LOX.B_ST_TS'),
        'status_zaehler'   => array('signed' => false, 'min' => 0, 'max' => 999,        'einheit' => '<v.0>',   'bed' => 'LOX.B_ST_ZAEHLER'),
        'status_quelle_ts' => array('signed' => false, 'min' => 0, 'max' => 2147483647, 'einheit' => '<v.0>',   'bed' => 'LOX.B_ST_QUELLE'),
    );
}

/** Die Felder, die nach aussen gehen. Namen und Reihenfolge aus pw_felderliste(). */
function pw_felder($stand, $cfg, $jetzt = null)
{
    $jetzt = $jetzt === null ? time() : $jetzt;
    $quelle = pw_zahl(isset($stand['quelle_ts']) ? $stand['quelle_ts']
                      : (isset($stand['zeit']) ? $stand['zeit'] : 0), 0.0);
    $alter = $quelle > 0 ? (int) ($jetzt - $quelle) : -1;
    $veraltet = ($alter < 0 || $alter > (int) pw_zahl(isset($cfg['stale_s']) ? $cfg['stale_s'] : 300, 300.0));
    // Veraltet heisst unbekannt - nie "steht" (siehe Kern, pw_laeuft).
    $laeuft = $veraltet ? -1 : (isset($stand['laeuft']) ? (int) $stand['laeuft'] : -1);
    $befund = $veraltet ? PW_STILL : (isset($stand['befund']) ? (string) $stand['befund'] : PW_STILL);
    $grund  = isset($stand['sperrgrund']) ? (string) $stand['sperrgrund'] : '';
    /* "Gestern" ist gestern - nicht der juengste gespeicherte Tag. Stand
     * der LoxBerry mehrere Tage, meldete LAUF_S_VORTAG bis 0.9.10 einen
     * alten Tag als gestrigen, ohne dass irgendwo etwas darauf hinwies. */
    /* MIT Kennung. Ohne sie kaeme die Tagesbilanz der ERSTEN Pumpe - und
     * in der Zeile der Sumpfpumpe stuende der gestrige Lauf des
     * Hauswasserwerks. Eine plausible Zahl am falschen Ort ist teurer als
     * gar keine: sie faellt nie auf. */
    $vt = pw_vortag(date('Y-m-d', (int) $jetzt - 86400),
                    isset($cfg['id']) ? (string) $cfg['id'] : null);
    /* Und ein Satz, dem die Werte fehlen, ist kein Satz: (int) null waere 0,
     * also "gestern 0 s, 0 Starts" statt "unbekannt". Unter PHP 8 kam dazu
     * ein "Undefined array key" - und weil error_reporting nur E_NOTICE
     * maskiert, seit PHP 8.0 aber E_WARNING gemeldet wird, stand der
     * Warntext MITTEN in der Antwortzeile fuer Loxone. Gemessen 31.08.2026
     * an aktion=zeile mit einem unvollstaendigen tage.json. */
    $vt_ok = (is_array($vt) && isset($vt['lauf_s']) && isset($vt['starts']));
    $w = pw_wartung($stand);
    return array(
        'laeuft'         => $laeuft,
        'befund'         => pw_befund_zahl($befund),
        'beiwert'        => $veraltet ? -1 : round(pw_zahl(isset($stand['beiwert']) ? $stand['beiwert'] : 0, 0.0), 1),
        'sperre'         => !empty($stand['sperre']) ? 1 : 0,
        'sperrgrund'     => $grund === '' ? 0 : pw_befund_zahl($grund),
        'quittung'       => !empty($stand['quittung']) ? 1 : 0,
        'watt'           => ($veraltet || !isset($stand['watt']) || $stand['watt'] === null)
                            ? -1 : round((float) $stand['watt'], 1),
        'lauf_s'         => isset($stand['lauf_s']) ? (int) $stand['lauf_s'] : 0,
        'letzter_lauf_s' => isset($stand['letzter_lauf_s']) ? (int) $stand['letzter_lauf_s'] : -1,
        'lauf_s_tag'     => isset($stand['lauf_s_tag']) ? (int) $stand['lauf_s_tag'] : 0,
        'starts_tag'     => isset($stand['starts_tag']) ? (int) $stand['starts_tag'] : 0,
        'laengster_tag'  => isset($stand['laengster_tag']) ? (int) $stand['laengster_tag'] : 0,
        'lauf_s_vortag'  => $vt_ok ? (int) $vt['lauf_s'] : -1,
        'starts_vortag'  => $vt_ok ? (int) $vt['starts'] : -1,
        'betrieb_h'      => $w['gesamt_h'],
        'zeitsprung'     => isset($stand['zeitsprung']) ? (int) $stand['zeitsprung'] : 0,
        'volt'           => ($veraltet || !isset($stand['volt']) || $stand['volt'] === null)
                            ? -1 : round((float) $stand['volt'], 1),
        'ampere'         => ($veraltet || !isset($stand['ampere']) || $stand['ampere'] === null)
                            ? -1 : round((float) $stand['ampere'], 2),
        'hertz'          => ($veraltet || !isset($stand['hertz']) || $stand['hertz'] === null)
                            ? -1 : round((float) $stand['hertz'], 2),
        /* Sekunden seit dem letzten Lauf. -1 = es wurde noch nie einer
         * gesehen; dann kann auch keiner vermisst werden. */
        'ruht_s'         => (isset($stand['starts_gesamt']) && (int) $stand['starts_gesamt'] > 0
                             && isset($stand['seit']) && $stand['seit'] > 0
                             && (int) $laeuft === 0)
                            ? (int) max(0, $jetzt - $stand['seit']) : -1,
        /* Die Anwesenheit der QUELLE - nicht des Messwerts. Sie veraltet
         * ausdruecklich NICHT mit: der Shelly meldet sein 'online'
         * aufbewahrt, und genau darin liegt der Wert. 'Seit Minuten kein
         * Messwert' heisst etwas anderes, wenn das Geraet sich
         * abgemeldet hat. -1 = keine Auskunft (Weg ueber Loxone). */
        'quelle_online'  => isset($stand['quelle_online']) ? (int) $stand['quelle_online'] : -1,
    );
}

/**
 * Textzeilen fuer die Befehlserkennung.
 *
 * Mit fuehrendem Semikolon je Feld. Ohne es traefe der Suchtext "LAUF_S="
 * auch "LAUF_S_TAG=" - dieselbe Klasse, die in REGELN_3 unter A11 steht. Der
 * Reiter Test prueft die Eindeutigkeit an der ECHTEN Antwortzeile nach, nicht
 * am Feldnamen.
 */
function pw_zeilen($felder)
{
    $aus = '';
    foreach ($felder as $k => $v) { $aus .= ';' . strtoupper($k) . '=' . $v . "\n"; }
    return $aus;
}

/**
 * Der Suchtext eines Feldes - EINE Stelle.
 *
 * REGELN_3, A11: "Es gibt deshalb EINE Funktion, und alle Stellen rufen
 * sie." Bis 0.9.10 entstand der Suchtext an drei Stellen unabhaengig
 * voneinander (Vorlage, pw_eine_zeile, pw_suchtext_stimmig). Alle drei
 * stimmten ueberein - aber die naechste Aenderung haette sie auseinander
 * laufen lassen, und die Pruefung haette es nicht gemerkt, weil sie ihren
 * eigenen Text baut.
 *
 * Das fuehrende Semikolon ist kein Schmuck: ohne es traefe LAUF_S= auch
 * LETZTER_LAUF_S=. Gemessen an der echten Antwortzeile.
 */
function pw_check($feld)
{
    return ';' . strtoupper($feld) . '=\v';
}

/** Eine Zeile, in der jedes Feld genau einmal vorkommt - fuer einen VI mit
 *  Befehlserkennung (HTTP-Weg ohne MQTT-Gateway). */
function pw_eine_zeile($felder)
{
    $aus = '';
    foreach ($felder as $k => $v) {
        /* substr(pw_check(), 0, -2) schneidet das  ab - der Rest ist der
         * Suchtext, den Loxone in der Zeile findet. Eine Stelle, drei
         * Benutzer: Vorlage, Antwortzeile, Pruefung. */
        $aus .= substr(pw_check($k), 0, -2) . $v;
    }
    return $aus . ';';
}

/* ---------------- Adressen: EINE Quelle ---------------- */

/**
 * Die Adresse des Endpunkts.
 *
 * Bis 0.9.7 entstand sie zweimal: die Anzeige aus $_SERVER['HTTP_HOST'], die
 * VO-Vorlage aus gethostname(). Gemessen am 28.08.2026 lief das auseinander
 * ("http://127.0.0.1:9013/..." gegen "http://<rechnername>"). Der Anwender
 * sah in Schritt 1 eine Adresse und bekam in der Vorlage eine andere.
 * Heimkino hat denselben Fehler schon behoben und aufgeschrieben.
 *
 * $host = null nimmt HTTP_HOST, faellt auf gethostname() zurueck und nennt
 * beides nicht, wenn keines zu haben ist.
 */
function pw_host($host = null)
{
    if ($host !== null && $host !== '') { return (string) $host; }
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return (string) $_SERVER['HTTP_HOST'];
    }
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

function pw_endpunkt($cfg = null, $host = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    $p = pw_paths();
    /* Die Kennung gehoert IN die Adresse. Sonst zeigt jede Vorlage und jede
     * Zeile auf der Seite auf die erste Pumpe, gleich welche gewaehlt ist -
     * und der Anwender traegt sie so in den Miniserver ein. */
    $id = isset($cfg['id']) ? trim((string) $cfg['id']) : '';
    return 'http://' . pw_host($host) . '/plugins/' . $p['plugin']
         . '/index.php?token=' . rawurlencode((string) $cfg['aktionstoken'])
         . ($id === '' ? '' : '&pumpe=' . rawurlencode($id));
}

/* ---------------- Vorlagen fuer Loxone Config ---------------- */

/**
 * Die kurze Beschriftung eines Feldes - der Name, unter dem der Baustein in
 * Loxone Config erscheint.
 *
 * Sie entsteht aus dem FELDNAMEN, nicht aus einer zweiten getippten Liste:
 * pw_felderliste() bleibt die eine Quelle. Fehlt der Schluessel, faellt es
 * auf den Feldnamen zurueck - das ist immer noch eine Beschriftung und nie
 * ein Fliesstext.
 */
function pw_kurz($feld, $texte = null)
{
    $sl = 'LOX.K_' . strtoupper($feld);
    $s = $texte ? $texte($sl) : $sl;
    return ($s === $sl) ? $feld : $s;
}

/** Eine Zeile <VirtualInHttpCmd> nach der Form einer echten Config-Ausfuhr.
 *
 *  $kommentar ist eine BESCHRIFTUNG, kein Fliesstext: Loxone Config zeigt
 *  ihn unter Visualisierung -> Anzeigename, das Feld Dokumentation bleibt
 *  leer (REGELN_2, am Geraet gemessen 18.08.2026). Bis 0.9.10 stand hier
 *  der volle Erklaertext der Sprachdatei - sieben der 24 Befehle trugen
 *  einen Namen von ueber 60 Zeichen, der laengste 202. */
function pw_vi_zeile($titel, $kommentar, $r, $check = ' ')
{
    return "\t" . '<VirtualInHttpCmd Title="' . pw_x($titel) . '" '
         . 'Comment="' . pw_x($kommentar) . '" Check="' . pw_x($check) . '" '
         . 'Signed="' . ($r['signed'] ? 'true' : 'false') . '" Analog="true" '
         . 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" '
         . 'DefVal="0" MinVal="' . $r['min'] . '" MaxVal="' . $r['max'] . '" '
         . 'Unit="' . pw_x($r['einheit']) . '" HintText=""/>' . "\r\n";
}

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Format wie Original-Export aus Loxone Config 17.1.
 *
 *  Unit steht als ATTRIBUT. Das <Display Unit="..." StateOnly="true"/>, das
 *  man in einer Projektdatei sieht, baut Config daraus selbst. */
function pw_vorlage_vi($cfg = null, $texte = null)
{
    /* ALLE Pumpen in EINER Datei. Das geht hier, weil die Eingangsnamen das
     * Themenpraefix der Pumpe tragen - "pumpe_watt" und "sumpfpumpe_watt"
     * sind von sich aus verschieden. Und es MUSS so gehen: das MQTT-Gateway
     * schickt alle Themen an denselben Miniserver, und zwei Dateien mit
     * demselben Titel liest Config uebereinander. */
    $pumpen = pw_vorlage_pumpen($cfg);
    $themen = array();
    foreach ($pumpen as $p) { $themen[] = pw_mqtt_thema($p) . '/#'; }
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Pumpenwächter" Comment="Erzeugt vom LoxBerry-Plugin Pumpenwächter (' . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo ' . pw_x(implode(' ', $themen)) . ' nötig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($pumpen as $p) {
        $topic = pw_mqtt_thema($p);
        /* Der Pumpenname steht im KOMMENTAR, nicht im Eingangsnamen: der
         * Name geht in Config in die Peripherie und muss kurz bleiben, das
         * Themenpraefix unterscheidet ohnehin schon. Bei nur einer Pumpe
         * gar kein Zusatz - dann ist die Datei dieselbe wie bisher. */
        $zusatz = count($pumpen) > 1 ? ' (' . pw_pumpe_name($p) . ')' : '';
        foreach (array_merge(pw_felderliste(), pw_statusliste()) as $k => $r) {
            $o .= pw_vi_zeile($topic . '_' . $k, pw_kurz($k, $texte) . $zusatz, $r);
        }
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_pumpenwaechter.xml', $o);
}

/**
 * Vorlage fuer den HTTP-Weg OHNE MQTT-Gateway.
 *
 * Der Endpunkt liefert die Zeile ohnehin; wer kein laufendes Gateway hat,
 * konnte das Plugin bis 0.9.7 gar nicht einbinden. Echte Adresse, echter
 * Abfragetakt, je Feld ein Check-Suchtext mit fuehrendem Semikolon.
 */
function pw_vorlage_vi_http($cfg = null, $host = null, $texte = null)
{
    /* JE PUMPE EINE DATEI. Ein VirtualInHttp hat GENAU EINE Abfrageadresse,
     * und die steht an der Wurzel - mehrere Pumpen brauchen mehrere
     * Wurzeln, und XML hat eine. Deshalb bekommt diese Vorlage eine flache
     * Sicht und baut genau sie.
     *
     * Die Adresse kommt aus pw_endpunkt() und NICHT mehr von Hand: dort
     * steht seit 1.0.0 das &pumpe=. Von Hand gebaut fragte jede Datei die
     * erste Pumpe ab, egal welche gemeint war. */
    $liste = pw_vorlage_pumpen($cfg);
    $cfg = $liste[0];
    $mehrere = count(pw_pumpe_ids(pw_config())) > 1;
    $id = isset($cfg['id']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $cfg['id'])) : '';
    $adr = pw_endpunkt($cfg, $host) . '&aktion=zeile';
    /* Dateiname, Titel und Eingangsnamen tragen die Kennung, sobald es mehr
     * als eine Pumpe gibt. Ohne das hiessen die Eingaenge beider Dateien
     * "pw_watt", und Config liesse sie im Projekt nicht nebeneinander
     * stehen. Bei einer Pumpe bleibt alles wie in 0.9.14. */
    $vor = $mehrere && $id !== '' ? 'pw_' . $id . '_' : 'pw_';
    $titel = 'Pumpenwächter (HTTP)' . ($mehrere ? ' - ' . pw_pumpe_name($cfg) : '');
    $name = 'VI_pumpenwaechter_http' . ($mehrere && $id !== '' ? '_' . $id : '') . '.xml';
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="' . pw_x($titel) . '" Comment="Erzeugt vom LoxBerry-Plugin Pumpenwächter (' . date('d.m.Y') . '). Fragt den Endpunkt selbst ab - ohne MQTT-Gateway. Bitte Adresse prüfen." Address="' . pw_x($adr) . '" PollingTime="30">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach (array_merge(pw_felderliste(), pw_statusliste()) as $k => $r) {
        $o .= pw_vi_zeile($vor . $k, pw_kurz($k, $texte), $r, pw_check($k));
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array($name, $o);
}

/** VO-Vorlage (Steuerbefehle) nach dem Heimkino-Muster: templateType 3,
 *  Wortzeichen eingesetzt.
 *
 *  Ein ANALOGER VirtualOutCmd traegt vier Attribute mehr als ein digitaler:
 *  SourceValLow, DestValLow, SourceValHigh, DestValHigh zwischen RepeatRate
 *  und HintText. Gemessen an der echten Config-Ausfuhr
 *  "VQU_Govee UDP-Ausgang_Test.xml": Zeile 5 (analog) traegt sie, Zeile 4
 *  (digital) nicht. Bis 0.9.7 fehlten sie am Watt-Befehl - also ausgerechnet
 *  an dem einen Befehl, der den Messwert traegt. */
function pw_vorlage_vo($cfg = null, $host = null)
{
    /* ALLE Pumpen in EINER Datei: ein VirtualOut hat eine Adresse, und
     * jeder Befehl traegt den vollen Pfad - der unterscheidet sich nur im
     * &pumpe= am Ende. */
    $pumpen = pw_vorlage_pumpen($cfg);
    $crlf = "\r\n";
    $skala = 'SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" ';
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Pumpenwächter (LoxBerry-Plugin)" Comment="Erzeugt vom LoxBerry-Plugin Pumpenwächter (' . date('d.m.Y') . '). Bitte Adresse prüfen. Der Befehl „Pumpe angefordert“ wird nur gebraucht, wenn Loxone die Pumpe schaltet - bei einer druckgesteuerten Pumpe bleibt er ungenutzt." Address="http://' . pw_x(pw_host($host)) . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($pumpen as $p) {
        $basis = substr(pw_endpunkt($p, $host), strlen('http://' . pw_host($host)));
        /* Bei einer Pumpe bleiben die Befehlsnamen genau die von 0.9.14 -
         * ein Zusatz erscheint erst, wenn es etwas zu unterscheiden gibt. */
        $zusatz = count($pumpen) > 1 ? ' - ' . pw_pumpe_name($p) : '';
        $o .= "\t" . '<VirtualOutCmd Title="Messwert liefern (Watt)' . pw_x($zusatz) . '" Comment="Messwert anliefern (Watt)' . pw_x($zusatz) . '" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . pw_x($basis . '&aktion=wert&watt=<v>') . '" CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="true" Repeat="0" RepeatRate="0" ' . $skala . 'HintText=""/>' . $crlf;
        $o .= "\t" . '<VirtualOutCmd Title="Sperre quittieren' . pw_x($zusatz) . '" Comment="Sperre quittieren' . pw_x($zusatz) . '" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . pw_x($basis . '&aktion=quittieren') . '" CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        $o .= "\t" . '<VirtualOutCmd Title="Pumpe angefordert' . pw_x($zusatz) . '" Comment="Pumpe angefordert (Ein/Aus)' . pw_x($zusatz) . '" ';
        $o .= 'CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . pw_x($basis . '&aktion=anforderung&an=1') . '" CmdOnHTTP="" CmdOnPost="" ';
        $o .= 'CmdOff="' . pw_x($basis . '&aktion=anforderung&an=0') . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
    }
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

/**
 * Der EINE Satz, der ohne Sprachdatei lesbar sein muss.
 *
 * Bis 0.9.7 holte die Warnung "Sprachdateien fehlen" ihren Text aus der
 * Sprachdatei, die gerade fehlt - im Warnkasten stand woertlich
 * "ALLG.SPRACHE_FEHLT", und auf der Seite standen 85 weitere rohe Schluessel.
 * Gemessen am 28.08.2026 mit leerem Sprachordner.
 */
function pw_sprache_notfall()
{
    return pw_sprache() === 'en'
        ? 'The language files were not found &mdash; the page shows the raw keys. Please reinstall the plugin.'
        : 'Die Sprachdateien wurden nicht gefunden &mdash; angezeigt werden die Schl&uuml;ssel. Bitte das Plugin neu installieren.';
}

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

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet. Bis 0.9.7 hing nur der Fliesstext daran;
 * Ueberschrift und Abo-Kasten forderten auch unter V2 zum Eintragen auf.
 * Seit 0.9.8 haengen alle drei an dieser einen Funktion.
 */
function pw_abo_lage()
{
    $m = pw_mqtt_gateway_info();
    $f = $m['fassung'];
    if (!$m['gefunden'] || $f <= 0) { return 'unbekannt'; }
    return $f >= 2 ? 'v2' : 'v1';
}

function pw_abo_text()
{
    $lage = pw_abo_lage();
    if ($lage === 'unbekannt') { return pw_t('LOX.ABO_UNBEKANNT'); }
    $f = pw_gateway_fassung();
    $gemessen = ' <span class="sm-mono">'
              . sprintf(pw_t('LOX.ABO_GEMESSEN'), $f) . '</span>';
    return pw_t($lage === 'v2' ? 'LOX.ABO_V2' : 'LOX.S2_TEXT') . $gemessen;
}

function pw_abo_titel()
{
    /* Drei Lagen, drei Ueberschriften - nicht zwei.
     *
     * Bis 0.9.11 fiel die Lage "unbekannt" auf S2_TITEL zurueck, also auf
     * "Schritt 2: Abo im MQTT-Gateway EINTRAGEN". Der Text darunter
     * (ABO_UNBEKANNT) nennt in diesem Fall ausdruecklich BEIDE Faelle -
     * "Fassung 1: ohne den Eintrag ... / Fassung 2: einzutragen ist nichts" -,
     * waehrend die Ueberschrift schon eine Anweisung gab. Fuer jede Anlage
     * mit Gateway V2, deren Fassung sich nicht lesen liess, stand dort das
     * Falsche, und zwar in der groesseren Schrift.
     *
     * Eine EINE neutrale Ueberschrift fuer alle drei Lagen waere die
     * schlechtere Antwort gewesen: bei bekannter Fassung darf und soll der
     * Reiter deutlich sein. Neutral wird nur der Fall, in dem wirklich
     * nichts bekannt ist. */
    $lage = pw_abo_lage();
    if ($lage === 'v2')        { return pw_t('LOX.S2_TITEL_V2'); }
    if ($lage === 'unbekannt') { return pw_t('LOX.S2_TITEL_UNBEKANNT'); }
    return pw_t('LOX.S2_TITEL');
}

/* ==================================================================
 * Sicherung
 * ================================================================== */

/**
 * Die Sicherungsdatei bauen - MIT Kopf.
 *
 * Der Kopf sagt, aus welchem Plugin und aus welcher Fassung die Datei
 * stammt. Ohne ihn ist eine Sicherung von einer beliebigen JSON-Datei mit
 * passenden Schluesseln nicht zu unterscheiden.
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 */
function pw_sicherung_bauen($cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    $kopf = array(
        '_plugin'  => 'pumpenwacht',
        '_fassung' => pw_fassung(),
        '_stand'   => date('Y-m-d H:i:s'),
        '_hinweis' => 'Sicherung des LoxBerry-Plugins Pumpenwaechter. '
                    . 'Sie enthaelt das Aktionstoken - wie ein Kennwort behandeln.',
    );
    return array_merge($kopf, $cfg);
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der Kern der Sache ist EIN Satz: Grundlage ist der LAUFENDE Stand, nicht
 * die Werkseinstellung. Ein Schluessel, den die Datei nicht nennt, behaelt
 * seinen bisherigen Wert und wird nicht auf Werk gesetzt.
 *
 * Bis 0.9.7 war es umgekehrt: die Funktion baute auf pw_vorgaben() auf und
 * setzte darauf, was in der Datei stand. Gemessen am 28.08.2026 wurde die
 * Datei {"mqtt_topic":"hauswasser"} mit "1 Werte uebernommen" quittiert und
 * setzte dabei modell auf "frei", trocken_w auf 0, sperren_ein auf 0 und
 * das aktionstoken auf "" - damit war jede Adresse in Loxone still tot, und
 * die Meldung war woertlich richtig.
 *
 * Die erste Fassung dieser Korrektur verlangte einen Kopf und lehnte jede
 * unvollstaendige Datei ab. Das erschlug den Befund - und dazu zwei Faelle,
 * die niemand erschlagen wollte: eine Sicherung aus 0.9.7 hat keinen Kopf,
 * und ihr fehlen die beiden Schluessel, die es erst in 0.9.8 gibt. Sie waere
 * ausgerechnet beim Aktualisieren abgelehnt worden. Gefunden hat das
 * Werkzeuge/sicherung_wirkung.py, das seine Probe ohne Kopf baut.
 *
 * Was bleibt:
 * 1. Der Kopf wird GELESEN, wenn er da ist - ein fremdes Plugin wird
 *    abgelehnt -, aber nicht mehr verlangt. Und er wird als Kopf behandelt,
 *    nicht als Einstellung: das ist die Falle vom 26.08.2026
 *    (WiFi-Scanner-NG), wo eine Lesefunktion ihren eigenen Kopf als
 *    fremden Schluessel abwies.
 * 2. Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust.
 * 3. Jeder WERT wird geprueft, nicht nur der Schluessel (pw_wert_pruefen).
 * 4. Eine Datei ohne einen einzigen bekannten Schluessel wird abgelehnt.
 * 5. Alle Beanstandungen werden gesammelt, nicht nur die erste - und wenn
 *    eine dabei ist, wird GAR NICHTS geschrieben.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene,
 *                  erwartete, unveraendert[]).
 */
function pw_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(pw_t('EINST.SICH_KEIN_JSON')), 0, 0, array());
    }
    /* Der Kopf wird VOR der Wanderung abgetrennt: '_plugin' und '_fassung'
     * sind keine Einstellungen und haben in keiner Pumpe etwas zu suchen. */
    foreach (array_keys($daten) as $k) {
        if (strncmp((string) $k, '_', 1) !== 0) { continue; }
        if ($k === '_plugin' && (string) $daten[$k] !== 'pumpenwacht') {
            $mangel[] = sprintf(pw_t('EINST.SICH_FREMDES_PLUGIN'),
                                pw_e((string) $daten[$k]));
        }
        unset($daten[$k]);
    }

    /* FREMDE SCHLUESSEL OBEN - VOR der Wanderung.
     *
     * Die Wanderung baut den oberen Teil NEU auf: Globales und 'pumpen',
     * sonst nichts. Was sie nicht kennt, wirft sie weg - und danach findet
     * die Pruefung weiter unten nichts mehr zu beanstanden. Eine Datei mit
     * {"boese": 1} galt als angenommen.
     *
     * NUR bei der neuen Form: in einer flachen Datei aus 0.9.14 stehen die
     * Pumpenschluessel oben, und dort sind sie voellig richtig. Sie werden
     * nach der Wanderung bei ihrer Pumpe geprueft. */
    $vorg_global = array_intersect_key(pw_vorgaben(),
                                       array_flip(pw_global_schluessel()));
    if (isset($daten['pumpen'])) {
        foreach (array_keys($daten) as $k) {
            if ($k === 'pumpen' || array_key_exists($k, $vorg_global)) { continue; }
            $mangel[] = sprintf(pw_t('EINST.SICH_FREMD'), pw_e((string) $k));
        }
    }

    /* Eine Sicherung aus der Zeit vor 1.0.0 ist flach. Sie wird GEWANDERT
     * gelesen, nicht abgewiesen - sonst waere jede vorhandene Sicherung
     * beim Umstieg wertlos. Geschrieben wird immer die neue Form.
     *
     * OHNE Vorgaben aufzufuellen: sonst erfindet die Wanderung ein leeres
     * formgeheim, und der Schutz weiter unten weist die eigene Erfindung
     * ab. Genau das ist beim ersten Versuch passiert. */
    $daten = pw_wandern($daten, false);
    $vorg_pumpe = pw_vorgaben_pumpe();
    /* Die Kennung ist die ADRESSE einer Pumpe, kein Wert: sie wird nie
     * "uebernommen", und solange sie mitgezaehlt wurde, war die Zahl
     * unerreichbar ("26 von 27" bei einer vollstaendigen eigenen
     * Sicherung). Und je Pumpe kommt ein voller Satz Werte mit - bei zwei
     * Pumpen war die Zahl ausserdem zu klein. */
    $je_pumpe = count($vorg_pumpe) - 1;
    $wieviele = isset($daten['pumpen']) ? max(1, count($daten['pumpen'])) : 1;
    $erwartet = count($vorg_global) + $je_pumpe * $wieviele;
    /* Der laufende Stand ist die Grundlage. */
    $neu = pw_config();

    /* Die globalen Schluessel. */
    $anzahl = 0;
    $gesehen = array();
    foreach ($daten as $k => $w) {
        if ($k === 'pumpen') { continue; }
        if (!array_key_exists($k, $vorg_global)) {
            $mangel[] = sprintf(pw_t('EINST.SICH_FREMD'), pw_e((string) $k));
            continue;
        }
        /* Ein LEERES Geheimnis nimmt die Sicherung nicht an, wenn eines
         * steht. Die Positivliste erlaubt die leere Zeichenkette (eine
         * frische Konfiguration hat noch keine), und der Warnhinweis am
         * Sicherungsknopf legt ausdruecklich nahe, die Datei vor der
         * Weitergabe zu entschaerfen. Wer sie danach zuruecksspielte, bekam
         * bis 0.9.10 "0 Beanstandungen, 21 von 21 uebernommen" - und der
         * Endpunkt antwortete auf jede Loxone-Adresse mit
         * KEIN_TOKEN_GESETZT. Beim naechsten Oeffnen der Oberflaeche wurde
         * ein NEUES Token erzeugt; die Adressen im Miniserver waren damit
         * endgueltig tot, ohne dass irgendwo etwas beanstandet worden
         * waere.
         *
         * Ueberschrieben wird deshalb nur, wenn die Datei wirklich etwas
         * mitbringt. Der leere Wert wird GENANNT, nicht verschwiegen. */
        if (in_array($k, array('aktionstoken', 'formgeheim'), true)
            && trim((string) $w) === ''
            && trim((string) (isset($neu[$k]) ? $neu[$k] : '')) !== '') {
            $mangel[] = sprintf(pw_t('EINST.SICH_LEER_GEHEIM'), pw_e($k));
            continue;
        }
        list($wert, $grund) = pw_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = sprintf(pw_t('EINST.SICH_WERT'), pw_e($k),
                                pw_e(is_scalar($w) ? substr((string) $w, 0, 60) : gettype($w)));
            continue;
        }
        $neu[$k] = $wert;
        $gesehen[$k] = true;
        $anzahl++;
    }

    /* Und jede Pumpe fuer sich. Die Kennung entscheidet, welche Pumpe im
     * laufenden Stand ersetzt wird; eine unbekannte Kennung kommt hinzu. */
    $pumpen_neu = array();
    foreach (isset($daten['pumpen']) ? $daten['pumpen'] : array() as $i => $p) {
        if (!is_array($p)) { continue; }
        $id = isset($p['id']) ? (string) $p['id'] : ('pumpe' . ($i + 1));
        $ziel = array();
        foreach (isset($neu['pumpen']) ? $neu['pumpen'] : array() as $vorhanden) {
            if (isset($vorhanden['id']) && (string) $vorhanden['id'] === $id) {
                $ziel = $vorhanden; break;
            }
        }
        $ziel = array_merge(pw_vorgaben_pumpe(), $ziel, array('id' => $id));
        foreach ($p as $k => $w) {
            if ($k === 'id') { continue; }
            if ($k === 'name') { $ziel['name'] = substr((string) $w, 0, 40); $anzahl++; continue; }
            if (!array_key_exists($k, $vorg_pumpe)) {
                $mangel[] = sprintf(pw_t('EINST.SICH_FREMD'), pw_e($id . '.' . $k));
                continue;
            }
            list($wert, $grund) = pw_wert_pruefen($k, $w);
            if ($grund !== '') {
                $mangel[] = sprintf(pw_t('EINST.SICH_WERT'), pw_e($id . '.' . $k),
                                    pw_e(is_scalar($w) ? substr((string) $w, 0, 60) : gettype($w)));
                continue;
            }
            $ziel[$k] = $wert;
            $anzahl++;
        }
        $pumpen_neu[] = $ziel;
    }
    if ($pumpen_neu) {
        $neu['pumpen'] = $pumpen_neu;
        $doppelt = pw_praefixe_pruefen($neu);
        foreach ($doppelt as $d) {
            $mangel[] = sprintf(pw_t('EINST.SICH_PRAEFIX'), pw_e($d));
        }
    }

    /* 4. Eine Datei ohne einen einzigen bekannten Schluessel ist keine. */
    if ($anzahl === 0) {
        $mangel[] = pw_t('EINST.SICH_LEER');
    }

    /* Was die Datei nicht genannt hat, bleibt stehen - und wird GENANNT. */
    $unveraendert = array();
    foreach ($vorg_global as $k => $v) {
        if (!isset($gesehen[$k])) { $unveraendert[] = $k; }
    }

    return array($mangel ? null : $neu, $mangel, $anzahl, $erwartet, $unveraendert);
}

/**
 * Warum ist der Zustand "unbekannt"? - die naechste Ursache, nicht die erste.
 *
 * "unbekannt" heisst immer dasselbe: es liegt kein brauchbarer Messwert vor.
 * WARUM keiner vorliegt, ist aber bekannt, und der Anwender kann es der Seite
 * bisher nicht ansehen. Am 29.08.2026 hat jemand daraus auf einen Fehler in
 * der Modellauswahl geschlossen - die Modellauswahl traegt nur Schwellen ein
 * und hat mit dem Messwert nichts zu tun.
 *
 * Rueckgabe: array($schluessel, $fertigerText) - oder array('', ''), wenn es
 * nichts zu erklaeren gibt.
 *
 * Der Text wird HIER fertiggestellt und nicht beim Aufrufer: ein
 * sprintf(pw_t($veraenderlich), ...) kann
 * Werkzeuge/sprachplatzhalter_pruefen.py nicht lesen - es versteht nur einen
 * festen Schluessel. Was ein Werkzeug nicht lesen kann, ist nicht geprueft,
 * und "es stimmt trotzdem" ist keine Messung. Der Schluessel steht daneben,
 * damit Pruefstuecke die Lage benennen koennen.
 *
 * Sie wird NUR gerufen, wenn wirklich "unbekannt" angezeigt wird - dann kostet
 * das command -v fuer mosquitto_sub einmal je Seitenaufbau, und nur in der
 * Lage, in der die Antwort zaehlt.
 */
function pw_unbekannt_grund($cfg = null, $stand = null, $jetzt = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    $stand = $stand === null ? pw_stand() : $stand;
    $jetzt = $jetzt === null ? time() : $jetzt;

    $quelle = (string) $cfg['quelle'];
    $mqtt = ($quelle === 'mqtt');
    $thema = trim((string) $cfg['quelle_topic']);

    /* Die Frist WIRD NICHT ZWEITAUSGERECHNET: dieselbe Zeile wie in
     * pw_felder(), samt des Rueckfalls auf 'zeit'. Rechnen beide getrennt,
     * koennen sie auseinanderlaufen - und ein Text, der etwas anderes sagt
     * als die Kachel darueber, ist schlimmer als gar keiner. */
    $letzte = pw_zahl(isset($stand['quelle_ts']) ? $stand['quelle_ts']
                      : (isset($stand['zeit']) ? $stand['zeit'] : 0), 0.0);
    $alt = $letzte > 0 ? (int) ($jetzt - $letzte) : -1;
    $frist = (int) pw_zahl(isset($cfg['stale_s']) ? $cfg['stale_s'] : 300, 300.0);
    $veraltet = ($alt < 0 || $alt > $frist);

    /* Liegt ein frischer Messwert vor, gibt es NICHTS zu erklaeren - dann
     * schweigt sie. Der erste Wurf erfand hier einen Grund ("seit 11
     * Sekunden kein Messwert"), obwohl der Wert 11 Sekunden alt und damit
     * tadellos frisch war. Eine Erklaerung, die es sich notfalls ausdenkt,
     * ist schlimmer als keine. Gefunden von pw99_grund.py. */
    if (!$veraltet) { return array('', ''); }

    /* ---- 1. Es kam noch NIE etwas. Das ist eine Einrichtungsfrage. ---- */
    if ($letzte <= 0) {
        if (!$mqtt) {
            return array('GRUND.NIE_LOXONE', pw_t('GRUND.NIE_LOXONE'));
        }
        if ($thema === '') {
            return array('GRUND.NIE_THEMA', pw_t('GRUND.NIE_THEMA'));
        }
        if (!pw_hat_mosquitto()) {
            return array('GRUND.NIE_MOSQUITTO', pw_t('GRUND.NIE_MOSQUITTO'));
        }
        if (pw_dienst_alter() < 0) {
            return array('GRUND.NIE_ZUHOERER', pw_t('GRUND.NIE_ZUHOERER'));
        }
        return array('GRUND.NIE_WARTEN',
                 sprintf(pw_t('GRUND.NIE_WARTEN'), pw_e($thema)));
    }

    /* ---- 2. Es kam lange nichts mehr. Das ist eine Betriebsfrage. ---- */
    $alt = (int) max(0, $alt);
    if (!$mqtt) {
        return array('GRUND.ALT_LOXONE',
                 sprintf(pw_t('GRUND.ALT_LOXONE'), (string) $alt));
    }
    /* Die Abmeldung des Zaehlers zuerst: sie ist die naehere Ursache. Ein
     * Zuhoerer, der laeuft, aber nichts hoert, weil das Geraet weg ist, ist
     * kein Fehler des Zuhoerers. */
    if (isset($stand['quelle_online']) && (int) $stand['quelle_online'] === 0) {
        return array('GRUND.ALT_ABGEMELDET',
                 sprintf(pw_t('GRUND.ALT_ABGEMELDET'), (string) $alt));
    }
    if (pw_dienst_alter() < 0) {
        return array('GRUND.ALT_ZUHOERER',
                 sprintf(pw_t('GRUND.ALT_ZUHOERER'), (string) $alt));
    }
    return array('GRUND.ALT_MQTT',
                 sprintf(pw_t('GRUND.ALT_MQTT'), (string) $alt));
}


/* ==================================================================
 * Selbstpruefung - beantwortet OHNE Loxone, ob die Einrichtung traegt
 * ==================================================================
 *
 * ok = 1 Haken, 0 Kreuz, 2 Strich ("nicht feststellbar"). Ein Strich ist
 * ausdruecklich KEIN Haken: was nicht gemessen werden konnte, sagt das. Und
 * die Zusammenfassung darf nicht besser aussehen als ihr schlechtester
 * Punkt - sie zaehlt Haken, Kreuze UND Striche getrennt.
 *
 * Die URSACHE steht vor der WIRKUNG: "Kommt ueberhaupt etwas an?" erklaert
 * das Ergebnis aller folgenden Zeilen und steht deshalb oben.
 */
function pw_selbstpruefung($cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    /* Der Zustand DIESER Pumpe. Ohne Kennung waere es der der ersten - und
     * bei der Sumpfpumpe stuende dann die Anlieferung des Hauswasserwerks:
     * ein gruener Haken, waehrend die Sumpfpumpe seit Tagen still ist.
     * Gefunden von pw10_reiter_test.py, nicht beim Lesen. */
    $stand = pw_stand(isset($cfg['id']) ? (string) $cfg['id'] : null);
    $m = pw_mqtt_gateway_info();
    $p = pw_paths();
    $z = array();
    $add = function ($schl, $ok, $text = '') use (&$z) {
        $z[] = array('bez' => $schl, 'ok' => (int) $ok, 'text' => (string) $text);
    };

    /* --- Ursache zuerst --- */
    $quelle = pw_zahl(isset($stand['quelle_ts']) ? $stand['quelle_ts'] : 0, 0.0);
    $alter = $quelle > 0 ? (int) (time() - $quelle) : -1;
    $stale = (int) pw_zahl($cfg['stale_s'], 300.0);
    /* Die wichtigste Zeile der Tabelle darf ihre Spalte nicht leer
     * lassen: ein Kreuz ohne Zahl laesst offen, ob nie ein Messwert kam
     * oder der letzte zu alt ist. Am Geraet stand am 28.08.2026 genau
     * das - Kreuz, Spalte leer. */
    $add('PRUEF.ANLIEFERUNG', $alter < 0 ? 0 : ($alter <= $stale ? 1 : 0),
         $alter < 0 ? pw_t('PRUEF.NIE')
                    : ($alter . ' s / ' . $stale . ' s'));

    $takt = pw_takt($stand);
    $add('PRUEF.TAKT', $takt['urteil'] === 'zyklisch' ? 1 : ($takt['urteil'] === 'zu_wenig' ? 2 : 0),
         $takt['urteil'] === 'zu_wenig' ? (string) $takt['anzahl']
             : ($takt['mittlerer'] . ' s / ' . $takt['laengster'] . ' s'));

    /* --- Der eigene Endpunkt --- */
    $add('PRUEF.TOKEN', trim((string) $cfg['aktionstoken']) !== '' ? 1 : 0, '');
    list($ok_e, $txt_e) = pw_endpunkt_probe($cfg);
    $add('PRUEF.ENDPUNKT', $ok_e, $txt_e);

    /* --- MQTT --- */
    $add('PRUEF.GATEWAY', $m['gefunden'] ? 1 : 2,
         $m['gefunden'] ? ('V' . ($m['fassung'] > 0 ? $m['fassung'] : '?')) : '');
    $add('PRUEF.AUTOSTART', !$m['gefunden'] ? 2 : ($m['autostart'] ? 1 : 0), '');
    $add('PRUEF.UDP', $m['udpport'] > 0 ? 1 : ($m['gefunden'] ? 0 : 2),
         $m['udpport'] > 0 ? (string) $m['udpport'] : '');
    $add('PRUEF.MQTT_EIN', !empty($cfg['mqtt_ein']) ? 1 : 2, pw_mqtt_thema($cfg));

    /* --- Die Pumpen gegeneinander ---
     *
     * Diese Zeile gilt fuer ALLE Pumpen, nicht fuer die gewaehlte: zwei
     * gleiche Themenpraefixe sind kein Mangel EINER Pumpe. Sie steht
     * trotzdem in jeder Tabelle - wer sie nur unter einer Pumpe zeigte,
     * versteckte sie vor dem, der zufaellig eine andere ansieht.
     *
     * pw_praefixe_pruefen() gab es seit dem Umbau, gefragt hat sie
     * niemand. Ein Praefix, das ein anderes verschluckt ("pumpe" und
     * "pumpe/sumpf"), faellt hier auf - in Loxone faellt es nicht auf,
     * dort steht dann ein Wert, der mal von der einen und mal von der
     * anderen Pumpe kommt. */
    $pw_kollision = pw_praefixe_pruefen(pw_config());
    $add('PRUEF.PRAEFIXE', $pw_kollision ? 0 : 1, implode('; ', $pw_kollision));

    /* Gehoert JEDE eintreffende Zeile einer Pumpe?
     *
     * Der Zuhoerer zaehlt die Zeilen, die zu keinem Quell-Thema passen.
     * Ohne seinen Bericht ist das ein STRICH: er laeuft nicht, oder er hat
     * noch nichts geschrieben. "Keine Auskunft" ist ausdruecklich nicht
     * "null fremde" - dieselbe Regel wie bei der Anwesenheit. */
    $pw_ber = pw_dienst_bericht();
    if (!$pw_ber) {
        $add('PRUEF.ZUORDNUNG', 2, '');
    } else {
        $pw_fremd_n = (int) pw_zahl(isset($pw_ber['fremd']) ? $pw_ber['fremd'] : 0, 0.0);
        $add('PRUEF.ZUORDNUNG', $pw_fremd_n > 0 ? 0 : 1,
             $pw_fremd_n > 0
             ? sprintf(pw_t('PRUEF.Z_FREMD'), $pw_fremd_n)
             : ((int) pw_zahl(isset($pw_ber['gesehen']) ? $pw_ber['gesehen'] : 0, 0.0)
                + (int) pw_zahl(isset($pw_ber['uebergangen']) ? $pw_ber['uebergangen'] : 0, 0.0))
               . ' ' . pw_t('PRUEF.Z_ZEILEN'));
    }

    /* --- Die Messwertquelle ---
     *
     * Steht sie auf "Loxone", sind die drei Zeilen ein STRICH, kein Haken:
     * sie treffen dann nicht zu. Ein Strich ist ausdruecklich kein Haken.
     * Ein Kreuz waere hier falsch - es gaebe nichts zu beheben. */
    $quelle = (string) $cfg['quelle'];
    $add('PRUEF.QUELLE', 1, pw_t($quelle === 'mqtt' ? 'PRUEF.Q_MQTT' : 'PRUEF.Q_LOXONE'));
    if ($quelle === 'mqtt') {
        $add('PRUEF.MOSQUITTO', pw_hat_mosquitto() ? 1 : 0,
             pw_hat_mosquitto() ? 'mosquitto_sub' : 'mosquitto-clients');
        $pid = pw_dienst_pid();
        $dalter = pw_dienst_alter();
        /* Ein Prozess kann dastehen und nichts tun. Deshalb wird nicht nur
         * die PID gefragt, sondern auch, wann er zuletzt etwas getan hat. */
        $add('PRUEF.ZUHOERER',
             ($pid > 0 && $dalter >= 0 && $dalter <= 180) ? 1 : ($pid > 0 ? 2 : 0),
             $pid > 0 ? ('PID ' . $pid . ', ' . ($dalter >= 0 ? $dalter . ' s' : '?')) : '');
        /* Das Quell-Thema durch DIESELBE Pruefung schicken, die auch das
         * Formular und die Sicherung benutzen. Ist das Muster selbst
         * kaputt, gibt preg_match false zurueck und hier steht ein Kreuz -
         * unabhaengig davon, was eingetragen wurde. Genau das ist am
         * 28.08.2026 passiert: die Raute stand als Trennzeichen UND in der
         * Zeichenklasse, jedes Thema galt als unzulaessig, und die
         * Sicherung liess sich nicht mehr zurueckspielen. */
        $th = trim((string) $cfg['quelle_topic']);
        list($th_wert, $th_grund) = pw_wert_pruefen('quelle_topic', $th);
        $add('PRUEF.Q_THEMA',
             ($th !== '' && $th_grund === '') ? 1 : 0,
             $th === '' ? pw_t('PRUEF.Q_LEER') : ($th_grund === '' ? $th : $th_grund));
        $add('PRUEF.QUELLE_ONLINE',
             isset($stand['quelle_online']) ? ((int) $stand['quelle_online'] === 1 ? 1 : 0) : 2,
             trim((string) $cfg['quelle_topic']));
    }

    /* --- Konfiguration --- */
    list(, $fehlten, $fremd) = pw_cfg_vervollstaendigen();
    $add('PRUEF.CFG_VOLL', ($fehlten || $fremd) ? 0 : 1,
         (count(pw_vorgaben()) - count($fehlten)) . '/' . count(pw_vorgaben())
         . ($fremd ? (' + ' . count($fremd) . ' fremd: ' . implode(', ', $fremd)) : ''));
    $add('PRUEF.ZWEITSCHRIFT', is_readable($p['sicherung']) ? 1 : 0, '');

    /* --- Schwellen, die einander widersprechen ---
     *
     * ACHTUNG, die Richtung ist nicht die, die man beim ersten Hinsehen
     * erwartet. Ein Trockenlauf wird gestellt, wenn die Pumpe LAEUFT
     * (watt >= an_w) und dabei WENIGER als trocken_w aufnimmt. Der
     * Bereich, in dem der Befund ueberhaupt erreichbar ist, lautet also
     *
     *        an_w  <=  watt  <  trocken_w
     *
     * und setzt trocken_w GROESSER als an_w voraus. trocken_w = 228 bei
     * an_w = 20 ist der Normalfall - genau das traegt der Modellknopf
     * fuer die SCALA1 3-45 selbst ein.
     *
     * Bis zur Messung am Geraet stand hier die umgekehrte Bedingung, und
     * die Selbstpruefung meldete auf einer richtig eingerichteten Anlage
     * ein Kreuz - und beanstandete damit, was der Knopf daneben einsetzt.
     * Ein rotes Kreuz, das nichts bedeutet, ist schlimmer als keine
     * Pruefung: man sucht dann dort. Gefunden hat es der Betreiber am
     * Bildschirm, nicht die Pruefkette. */
    $an = pw_zahl($cfg['an_w'], 20.0);
    $tr = pw_zahl($cfg['trocken_w'], 0.0);
    $ue = pw_zahl($cfg['ueberlast_w'], 0.0);
    $widerspruch = array();
    if ($tr > 0 && $tr <= $an) { $widerspruch[] = 'trocken_w <= an_w'; }
    if ($ue > 0 && $ue <= $an) { $widerspruch[] = 'ueberlast_w <= an_w'; }
    if ($tr > 0 && $ue > 0 && $tr >= $ue) { $widerspruch[] = 'trocken_w >= ueberlast_w'; }
    $add('PRUEF.SCHWELLEN', $widerspruch ? 0 : 1, implode(', ', $widerspruch));

    $grenze = (int) pw_zahl($cfg['starts_h'], 0.0);
    $add('PRUEF.STARTS_DECKEL', ($grenze <= pw_starts_deckel($cfg)) ? 1 : 0,
         $grenze . ' / ' . pw_starts_deckel($cfg));

    /* --- Zustand in sich stimmig --- */
    $sperre = !empty($stand['sperre']);
    $grund = isset($stand['sperrgrund']) ? (string) $stand['sperrgrund'] : '';
    $add('PRUEF.ZUSTAND', ($sperre === ($grund !== '')) ? 1 : 0,
         $sperre ? $grund : '');
    $add('PRUEF.ZEITSPRUNG', empty($stand['zeitsprung']) ? 1 : 0,
         (string) (int) (isset($stand['zeitsprung']) ? $stand['zeitsprung'] : 0));

    /* --- Die eigenen Vorlagen --- */
    if (function_exists('simplexml_load_string')) {
        $gut = 1; $schlecht = array();
        foreach (array('vi', 'vihttp', 'vo') as $art) {
            list($name, $inhalt) = pw_vorlage($art, $cfg);
            $vorher = libxml_use_internal_errors(true);
            if (@simplexml_load_string($inhalt) === false) { $gut = 0; $schlecht[] = $name; }
            libxml_clear_errors();
            libxml_use_internal_errors($vorher);
        }
        $add('PRUEF.VORLAGEN', $gut, implode(', ', $schlecht));
    } else {
        $add('PRUEF.VORLAGEN', 2, 'simplexml');
    }

    /* --- Reiter, Feldliste und Befunde gegeneinander --- */
    list($rok, $rtext) = pw_reiter_stimmig();
    $add('PRUEF.REITER', $rok, $rtext);
    $add('PRUEF.FELDER', pw_felder_stimmig($cfg) ? 1 : 0, (string) count(pw_felderliste()));
    $add('PRUEF.BEFUNDE', pw_befunde_stimmig() ? 1 : 0, (string) count(pw_befund_schluessel()));
    $add('PRUEF.SUCHTEXT', pw_suchtext_stimmig($cfg) ? 1 : 0, '');

    /* Tragen ALLE Formulare das Merkmal des Wachpostens?
     *
     * Alle dreizehn tun es - und genau deshalb faellt das Fehlen dieser
     * Zeile nicht auf. Sie steht wegen der NAECHSTEN Aenderung da: ein
     * Formular vergisst man beim Erweitern, einen Wachposten am Eingang
     * nicht (REGELN_2, "was in den Reiter Test gehoert", 20.08.2026). */
    list($fok, $ftext) = pw_formulare_stimmig();
    $add('PRUEF.FORMULAR', $fok, $ftext);

    /* Sind die Beschriftungen der Vorlagen kurz genug, um als NAME zu
     * taugen? Loxone Config zeigt den Comment eines Befehls unter
     * Visualisierung -> Anzeigename. Bis 0.9.10 waren sieben der 24
     * Kommentare Fliesstext, der laengste 202 Zeichen. */
    list($bok, $btext) = pw_beschriftung_stimmig($cfg);
    $add('PRUEF.BESCHRIFTUNG', $bok, $btext);

    /* --- Der Takt --- */
    $add('PRUEF.CRON', is_file(dirname(dirname(__DIR__)) . '/cron/cron.01min')
                       || is_file($p['home'] . '/system/cron/cron.01min/' . $p['plugin']) ? 1 : 2, '');

    /* --- Der Kern --- */
    list($kn, $kf) = pw_selbsttest(false);
    $add('PRUEF.KERN', $kf === 0 ? 1 : 0, $kn . ' / ' . $kf);

    return $z;
}

/**
 * Antwortet der eigene Endpunkt?
 *
 * Ein Aufruf auf 127.0.0.1 - auf dem Geraet bedient EIN Webserver beide
 * Baeume. Findet er die Bibliothek nicht, endet er mit HTTP 500, und genau
 * diese Klasse sieht keine Leseprufung.
 *
 * Das Ergebnis wird 300 s zwischengespeichert, damit das Aufschlagen des
 * Reiters nicht bei jedem Klick eine Anfrage ausloest. Und der Aufruf
 * loest NICHTS aus: aktion=selftest schreibt nicht.
 */
function pw_endpunkt_probe($cfg = null, $puffer_s = 300)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    $p = pw_paths();
    /* Je Pumpe ein Zwischenspeicher: die Probe prueft die Adresse DIESER
     * Pumpe, und eine gemeinsame Datei liesse die zuletzt geprueften fuenf
     * Minuten lang fuer alle gelten. */
    $pid = isset($cfg['id']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $cfg['id'])) : '';
    $datei = $p['datadir'] . '/endpunkt' . ($pid === '' ? '' : '_' . $pid) . '.json';
    $alt = pw_json_lesen($datei);
    /* Alle drei Schluessel, nicht nur der Zeitstempel: ein halb
     * geschriebener Zwischenspeicher ergab sonst ok = 0, also ein rotes
     * Kreuz "Endpunkt antwortet nicht", ohne dass irgendetwas gemessen
     * worden waere. Unter PHP 8 dazu zwei Warnungen in der Seite. */
    if (isset($alt['ts'], $alt['ok'], $alt['text'])
        && (time() - (int) $alt['ts']) < $puffer_s) {
        return array((int) $alt['ok'], (string) $alt['text']);
    }
    if (trim((string) $cfg['aktionstoken']) === '') { return array(2, ''); }
    /* Der Port wird GELESEN, nicht angenommen: 80 ist die Vorgabe des
     * LoxBerry, aber eben nur die Vorgabe. */
    $d = pw_json_lesen($p['general']);
    $port = 80;
    if (isset($d['Webserver']['Port']) && (int) $d['Webserver']['Port'] > 0) {
        $port = (int) $d['Webserver']['Port'];
    }
    /* Erst die TCP-Verbindung mit EINER Sekunde Schranke. Ohne sie wartet
     * das Aufschlagen des Reiters Test drei Sekunden vor einer halb
     * gerenderten Seite - und zwar genau dann, wenn etwas nicht stimmt.
     * Und file_get_contents() meldet in diesem Fall eine Warnung, die ein
     * eigener Fehler-Aufnehmer trotz @ sieht (gefunden von rendern.py).
     *
     * Dasselbe gilt fuer stream_socket_client selbst: das @ unterdrueckt
     * die AUSGABE, nicht das Ereignis. Auf dem LoxBerry hiesse das, dass
     * bei nicht erreichbarem Webserver eine PHP-Warnung mitten in der
     * Seite steht - genau dort, wo 'Endpunkt nicht erreichbar' stehen
     * soll. Deshalb fuer diese beiden Aufrufe ein Aufnehmer, der
     * schweigt; er wird unmittelbar danach wieder abgeraeumt, damit er
     * nichts verschluckt, was ihn nichts angeht. */
    set_error_handler(function () { return true; });
    $fp = stream_socket_client('tcp://127.0.0.1:' . $port, $e1, $e2, 1);
    restore_error_handler();
    if (!$fp) {
        pw_json_schreiben($datei, array('ts' => time(), 'ok' => 2,
                                        'text' => 'Port ' . $port));
        return array(2, 'Port ' . $port);
    }
    @fclose($fp);
    $adr = 'http://127.0.0.1:' . $port . '/plugins/' . $p['plugin']
         . '/index.php?token=' . rawurlencode((string) $cfg['aktionstoken'])
         . (isset($cfg['id']) && trim((string) $cfg['id']) !== ''
            ? '&pumpe=' . rawurlencode((string) $cfg['id']) : '')
         . '&aktion=selftest';
    $ktx = stream_context_create(array('http' => array(
        'timeout' => 3, 'ignore_errors' => true, 'method' => 'GET')));
    set_error_handler(function () { return true; });
    $antwort = file_get_contents($adr, false, $ktx);
    restore_error_handler();
    $ok = 2; $text = '';
    if ($antwort === false) {
        $ok = 2; $text = 'keine Antwort';
    } elseif (strpos($antwort, 'SELFTEST;OK=1') !== false) {
        $ok = 1;
    } else {
        $ok = 0;
        $text = substr(trim(preg_replace('/\s+/', ' ', $antwort)), 0, 60);
    }
    pw_json_schreiben($datei, array('ts' => time(), 'ok' => $ok, 'text' => $text));
    return array($ok, $text);
}

/**
 * Tragen alle Formulare der Oberflaeche das Merkmal des Wachpostens?
 *
 * Gezaehlt wird in der EIGENEN Datei, nicht behauptet: jedes <form> mit
 * method="post" braucht ein verstecktes Feld name="fmt". Rueckgabe wie bei
 * den anderen Zeilen: 1 = alle, 0 = es fehlt eines, 2 = Datei nicht lesbar
 * (ein Strich, kein Haken).
 */
function pw_formulare_stimmig()
{
    $datei = pw_oberflaechendatei();
    if ($datei === '') { return array(2, ''); }
    $q = (string) @file_get_contents($datei);
    if ($q === '') { return array(2, ''); }
    /* Nur die abschickenden Formulare zaehlen; ein GET-Formular kann keinen
     * Zustand aendern und braucht kein Merkmal. */
    $stuecke = preg_split('/<form\b/i', $q);
    array_shift($stuecke);
    $alle = 0; $ohne = 0;
    foreach ($stuecke as $s) {
        $ende = strpos($s, '</form>');
        $s = ($ende === false) ? $s : substr($s, 0, $ende);
        if (stripos($s, 'method="post"') === false) { continue; }
        $alle++;
        if (strpos($s, 'name="fmt"') === false) { $ohne++; }
    }
    return array($ohne === 0 ? 1 : 0, ($alle - $ohne) . '/' . $alle);
}

/**
 * Taugen die Beschriftungen der Vorlagen als NAME?
 *
 * Loxone Config zeigt den Comment eines Befehls unter Visualisierung ->
 * Anzeigename; das Feld Dokumentation bleibt leer (REGELN_2, am Geraet
 * gemessen 18.08.2026). Gezaehlt wird an den WIRKLICH erzeugten Dateien,
 * nicht an der Sprachdatei - sonst prueft man das Muster und nicht die
 * Aussage. Der Kommentar des WURZELelements ist ausgenommen: dort gehoert
 * die Erklaerung hin.
 */
function pw_beschriftung_stimmig($cfg = null, $hoechstens = 60)
{
    $lang = 0; $laengste = 0; $anzahl = 0;
    foreach (array('vi', 'vihttp', 'vo') as $art) {
        list(, $xml) = pw_vorlage($art, $cfg, null, 'pw_t');
        /* Das erste Comment= gehoert zum Wurzelelement und wird uebergangen. */
        if (!preg_match_all('/Comment="([^"]*)"/', $xml, $m)) { continue; }
        foreach (array_slice($m[1], 1) as $s) {
            $anzahl++;
            $n = function_exists('mb_strlen') ? mb_strlen(html_entity_decode(
                     $s, ENT_QUOTES, 'UTF-8'), 'UTF-8')
                 : strlen($s);
            if ($n > $laengste) { $laengste = $n; }
            if ($n > $hoechstens) { $lang++; }
        }
    }
    return array($lang === 0 ? 1 : 0,
                 $anzahl . ' / ' . $laengste . ' Z.');
}

/** Alle Vorlagen ueber EINEN Namen - so kann die Selbstpruefung sie zaehlen. */
/**
 * Die Pumpen, aus denen eine Vorlage gebaut wird - als FLACHE Sichten.
 *
 * Drei Faelle, und der mittlere ist der, der jede alte Aufrufstelle am
 * Leben haelt:
 *
 *   null            -> alle Pumpen der Konfiguration
 *   volle Form      -> alle Pumpen darin
 *   flache Sicht    -> genau diese eine
 *
 * Der dritte Fall ist nicht Nachsicht, sondern Absicht:
 * pw_beschriftung_stimmig() misst die drei Vorlagen fuer EINE bestimmte
 * Pumpe, und der Reiter Test tut dasselbe.
 */
function pw_vorlage_pumpen($cfg = null)
{
    if ($cfg === null) { $cfg = pw_config(); }
    if (isset($cfg['pumpen']) && is_array($cfg['pumpen'])) {
        $aus = array();
        foreach (pw_pumpe_ids($cfg) as $id) { $aus[] = pw_pumpe($cfg, $id); }
        return $aus ? $aus : array(pw_pumpe($cfg));
    }
    return array($cfg);
}

/**
 * Der Anzeigename einer Pumpe - Name, sonst Kennung.
 *
 * Eine namenlose Pumpe darf nicht als leerer Zusatz erscheinen: "Messwert
 * liefern (Watt) - " waere in Config nicht von "Messwert liefern (Watt) - "
 * einer anderen namenlosen zu unterscheiden.
 */
function pw_pumpe_name($p)
{
    $n = isset($p['name']) ? trim((string) $p['name']) : '';
    if ($n !== '') { return $n; }
    return isset($p['id']) ? (string) $p['id'] : 'Pumpe';
}

function pw_vorlage($art, $cfg = null, $host = null, $texte = null)
{
    if ($art === 'vo')     { return pw_vorlage_vo($cfg, $host); }
    if ($art === 'vihttp') { return pw_vorlage_vi_http($cfg, $host, $texte); }
    return pw_vorlage_vi($cfg, $texte);
}

/**
 * Stimmen Positivliste, Reiterleiste und Bereiche ueberein?
 *
 * Ausschreiben allein genuegt nicht: dazu gehoert eine Pruefzeile, die die
 * drei Stellen GEGENEINANDER zaehlt und sie aus derselben Datei liest -
 * sonst gibt es eine zweite Stelle, die man mitpflegen muss (REGELN_2).
 *
 * Der erklaerende Kommentar in index.php darf die gesuchte Form nicht im
 * Wortlaut enthalten; sonst zaehlt die Pruefung ihn mit. Deshalb steht dort
 * "siehe PRUEF.REITER" und kein Beispiel.
 *
 * Rueckgabe: array(1|0|2, 'Text'). 2 heisst "Datei nicht lesbar" - ein
 * Strich, kein Haken.
 */
function pw_oberflaechendatei()
{
    $kand = array();
    $home = pw_paths();
    if ($home['home'] !== '') {
        $kand[] = $home['home'] . '/webfrontend/htmlauth/plugins/'
                . $home['plugin'] . '/index.php';
    }
    /* Der installierte Zustand: die Bibliothek liegt in
     * <lbhome>/webfrontend/html/plugins/<ordner>/, die Oberflaeche in
     * <lbhome>/webfrontend/htmlauth/plugins/<ordner>/. */
    $kand[] = dirname(dirname(__DIR__)) . '/htmlauth/plugins/'
            . basename(__DIR__) . '/index.php';
    /* Der entpackte Archivbaum: <wurzel>/webfrontend/html/ neben
     * <wurzel>/webfrontend/htmlauth/. Bis 0.9.10 stand hier eine Ebene zu
     * viel (dirname(dirname(__DIR__)) . '/htmlauth/...'), und der Kandidat
     * zeigte auf <wurzel>/htmlauth/index.php - eine Datei, die es nie gab.
     * Beide Pruefungen, die diesen Weg benutzen, zeigten deshalb einen
     * STRICH statt eines Hakens; gemerkt hat es niemand, weil ein Strich
     * nicht rot ist. */
    $kand[] = dirname(__DIR__) . '/htmlauth/index.php';
    foreach ($kand as $k) { if (is_file($k)) { return $k; } }
    return '';
}

function pw_reiter_stimmig()
{
    $datei = pw_oberflaechendatei();
    if ($datei === '') { return array(2, ''); }
    $txt = (string) @file_get_contents($datei);
    if ($txt === '') { return array(2, ''); }

    /* Die Positivliste: der Inhalt des array(...) hinter $pw_reiter. */
    $liste = array();
    if (preg_match('/\$pw_reiter\s*=\s*array\((.*?)\);/s', $txt, $m)) {
        preg_match_all('/[\'"](tab-[a-z0-9\-]+)[\'"]/', $m[1], $mm);
        $liste = $mm[1];
    }
    /* Die Leiste: jedes data-ziel. */
    preg_match_all('/data-ziel="(tab-[a-z0-9\-]+)"/', $txt, $mb);
    $leiste = $mb[1];
    /* Die Bereiche: jede id einer sm-seite. */
    preg_match_all('/class="sm-seite[^"]*"\s+id="(tab-[a-z0-9\-]+)"/', $txt, $ms);
    $bereiche = $ms[1];

    sort($liste); sort($leiste); sort($bereiche);
    $gleich = ($liste === $leiste && $leiste === $bereiche && count($liste) > 0);
    return array($gleich ? 1 : 0,
                 count($liste) . '/' . count($leiste) . '/' . count($bereiche));
}

/** Stimmen Feldliste, erzeugte Vorlage und Namenstabelle ueberein? */
function pw_felder_stimmig($cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    $liste = array_keys(array_merge(pw_felderliste(), pw_statusliste()));
    $felder = array_keys(pw_felder(pw_stand(), $cfg));
    // pw_felder liefert die Nutzfelder; die Statusthemen kommen erst beim
    // Veroeffentlichen dazu.
    if ($felder !== array_keys(pw_felderliste())) { return false; }
    list(, $xml) = pw_vorlage_vi($cfg);
    $topic = pw_mqtt_thema($cfg);
    foreach ($liste as $k) {
        if (strpos($xml, 'Title="' . $topic . '_' . $k . '"') === false) { return false; }
    }
    return true;
}

/** Trägt jede Befundzahl einen Sprachschluessel, und umgekehrt? */
function pw_befunde_stimmig()
{
    $schl = pw_befund_schluessel();
    foreach (array(PW_OK, PW_SCHALTSPIEL, PW_DAUERLAUF, PW_TROCKEN,
                   PW_UEBERLAST, PW_STILL, PW_KEIN_ANLAUF) as $b) {
        $n = pw_befund_zahl($b);
        if (!isset($schl[$n])) { return false; }
    }
    return count($schl) === 7;
}

/**
 * Trifft jeder Suchtext der HTTP-Vorlage in der ECHTEN Antwortzeile genau
 * einmal?
 *
 * Geprueft wird die WIRKUNG, nicht die Schreibweise. Ein Vergleich der
 * blossen Namen waere zu streng UND zu milde: "LAUF_S" steckt in
 * "LAUF_S_TAG", aber ";LAUF_S=" nicht in ";LAUF_S_TAG=" - und genau dafuer
 * traegt der Suchtext das Semikolon.
 */
function pw_suchtext_stimmig($cfg = null)
{
    $cfg = $cfg === null ? pw_pumpe(pw_config()) : $cfg;
    $felder = pw_felder(pw_stand(), $cfg);
    $felder['status_ok'] = 1;
    $felder['status_ts'] = 1234567890;
    $felder['status_zaehler'] = 7;
    $felder['status_quelle_ts'] = 1234567890;
    $zeile = pw_eine_zeile($felder);
    foreach (array_keys($felder) as $k) {
        /* Gesucht wird mit DEMSELBEN Text, den die Vorlage schreibt - ohne
         * das Platzhalterzeichen am Ende. Bis 0.9.10 baute diese Pruefung
         * ihren Suchtext selbst; sie haette also nicht gemerkt, wenn die
         * Vorlage ihn eines Tages anders bildet. REGELN_3, A11. */
        $such = substr(pw_check($k), 0, -2);
        if (substr_count($zeile, $such) !== 1) { return false; }
    }
    return true;
}

/* ==================================================================
 * WEG B: den Messwert DIREKT vom MQTT-Broker lesen
 * ==================================================================
 *
 * Bis 0.9.8 gab es nur einen Weg: Loxone liefert den Watt-Wert ueber einen
 * Virtuellen Ausgang an den Endpunkt. Am 28.08.2026 hat sich am Geraet
 * gezeigt, dass der Zwischenzaehler ohnehin schon am Broker haengt:
 *
 *     shelly1pmg4-Pumpensumpf/online     true            (aufbewahrt)
 *     shelly1pmg4-Pumpensumpf/events/rpc {"method":"NotifyStatus", ...}
 *
 * Der Wert lief damit einmal durch den Miniserver hindurch, nur um
 * zurueckzukommen. Weg B nimmt den Umweg heraus.
 *
 * GEMESSEN, und nur darauf ist gebaut: der Shelly meldet JEDE VOLLE MINUTE
 * von selbst, auch wenn sich nichts geaendert hat. Der Zeitstempel der
 * gemessenen Meldung (1787950200) ist exakt durch 60 teilbar. Eine stehende
 * Pumpe sieht deshalb NICHT aus wie ein Ausfall - sie meldet apower 0.0.
 * Zwei Meldungen kamen, und nur EINE trug apower:
 *
 *   {"..","method":"NotifyStatus","params":{"ts":1787950200.00,
 *      "switch:0":{"counts":{"on_above_thr":510,"on_time":1912440,
 *                            "switch_on":0}}}}
 *   {"..","method":"NotifyStatus","params":{"ts":1787950200.02,
 *      "switch:0":{"aenergy":{...},"apower":0.0,"current":0.000,
 *                  "freq":50.05,"ret_aenergy":{...},"voltage":231.8}}}
 *
 * Eine Meldung OHNE apower ist deshalb kein Fehler, sondern der Normalfall -
 * sie wird uebergangen, nicht als Null gelesen. Das ist derselbe Satz wie im
 * Kern: eine fehlende Zahl ist keine Null.
 *
 * Die events/rpc sind NICHT aufbewahrt (gemessen: ein 30-Sekunden-Lauf ohne
 * Pumpenlauf brachte nur das aufbewahrte "online"). Ein Abruf im Minutentakt
 * muesste deshalb bis zu 65 Sekunden warten und den Takt blockieren -
 * darum ein mitlaufender Zuhoerer (bin/pw_dienst.php) statt eines Abrufs.
 * ================================================================== */

/**
 * Zugangsdaten des Brokers - aus der general.json, NICHT aus der eigenen
 * Konfiguration.
 *
 * Das ist Absicht und kein Sparen: ein zweites Exemplar eines Kennworts ist
 * ein zweiter Ort, an dem es veralten, verlorengehen oder in eine
 * Sicherungsdatei geraten kann. Die Sicherung dieses Plugins traegt ohnehin
 * schon das Aktionstoken; ein Broker-Kennwort gehoert nicht auch noch hinein.
 *
 * Der LoxBerry-Broker verlangt ab Werk eine Anmeldung - gemessen am
 * 28.08.2026: ohne Zugangsdaten antwortet er
 * "Connection Refused: not authorised".
 */
function pw_broker()
{
    $p = pw_paths();
    $gen = pw_json_lesen($p['general']);
    $m = array();
    foreach (array('Mqtt', 'mqtt') as $k) {
        if (isset($gen[$k]) && is_array($gen[$k])) { $m = $gen[$k]; break; }
    }
    $hol = function ($a, $b) use ($m) {
        if (isset($m[$a])) { return (string) $m[$a]; }
        return isset($m[$b]) ? (string) $m[$b] : '';
    };
    $host = $hol('Brokerhost', 'brokerhost');
    $port = (int) $hol('Brokerport', 'brokerport');
    return array(
        'host' => $host !== '' ? $host : 'localhost',
        'port' => $port > 0 ? $port : 1883,
        'user' => $hol('Brokeruser', 'brokeruser'),
        'pass' => $hol('Brokerpass', 'brokerpass'),
    );
}

/**
 * Eine Zeile fuer die Optionsdatei absichern.
 *
 * Die Datei ist ZEILENORIENTIERT - ein Zeilenumbruch im Wert erzeugt eine
 * zusaetzliche Option. Ein aus der Zwischenablage eingefuegtes Kennwort mit
 * angehaengtem Wagenruecklauf ergaebe sonst ein stilles Falschkennwort, und
 * das Plugin meldete danach nur noch "keine Werte vom Broker".
 * (Uebernommen aus MGiSmart, wo genau das aufgelaufen ist.)
 */
function pw_optionswert($v)
{
    return trim(str_replace(array("\r", "\n", "\t"), '', (string) $v));
}

/**
 * Die Optionsdatei fuer mosquitto_sub schreiben und ihren Ordner nennen.
 *
 * DIE ZUGANGSDATEN GEHOEREN NICHT AUF DIE KOMMANDOZEILE. /proc/<pid>/cmdline
 * hat die Rechte 444 - jeder lokale Benutzer liest dort mit, und der Zuhoerer
 * laeuft dauernd. mosquitto_sub liest Vorgabeoptionen aus
 * $XDG_CONFIG_HOME/mosquitto_sub, eine Option je Zeile; auf der Zeile steht
 * dann nur noch der Pfad.
 *
 * Die Einspeisebremse setzt sie in 0.9.15 noch als Argument und schreibt
 * dazu, das sei "nicht zu vermeiden". MGiSmart hat gezeigt, dass es das ist.
 */
/**
 * Wo die Optionsdatei liegt - ohne sie anzulegen.
 *
 * pw_broker_optionsdatei() erzeugt Ordner UND Datei, immer;
 * $erzwingen steuert nur, ob eine vorhandene neu geschrieben wird. Wer
 * bloss nachsehen will, darf sie nicht rufen: der Selbsttest hat damit
 * das Broker-Passwort auf die Platte geschrieben, auch auf einer Anlage,
 * die ueber Loxone laeuft und den MQTT-Weg nie benutzt.
 */
function pw_broker_optionspfad()
{
    $p = pw_paths();
    return $p['datadir'] . '/mosquitto';
}

function pw_broker_optionsdatei($erzwingen = false)
{
    $p = pw_paths();
    $ordner = pw_broker_optionspfad();
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        pw_log('FEHLER: Ordner fuer die Broker-Zugangsdaten nicht anlegbar: ' . $ordner);
        return '';
    }
    @chmod($ordner, 0700);

    $b = pw_broker();
    $zeilen = '';
    if (pw_optionswert($b['user']) !== '') {
        $zeilen .= '-u ' . pw_optionswert($b['user']) . "\n";
    }
    if (pw_optionswert($b['pass']) !== '') {
        $zeilen .= '-P ' . pw_optionswert($b['pass']) . "\n";
    }
    $datei = $ordner . '/mosquitto_sub';
    /* Neu schreiben, wenn sie fehlt oder aelter ist als die general.json -
     * sonst traegt sie nach einem Kennwortwechsel still das alte. */
    if ($erzwingen || !is_file($datei)
        || (is_file($p['general']) && filemtime($datei) < filemtime($p['general']))) {
        // Auch leer wird geschrieben: sonst bliebe eine alte Datei liegen.
        @file_put_contents($datei . '.neu', $zeilen);
        @chmod($datei . '.neu', 0600);
        @rename($datei . '.neu', $datei);
    }
    @chmod($datei, 0600);
    return $ordner;
}

/** Steht mosquitto_sub zur Verfuegung? */
function pw_hat_mosquitto()
{
    static $ja = null;
    if ($ja !== null) { return $ja; }
    if (!function_exists('proc_open') || !function_exists('shell_exec')) {
        $ja = false;
        return $ja;
    }
    $aus = @shell_exec('command -v mosquitto_sub 2>/dev/null');
    $ja = is_string($aus) && trim($aus) !== '';
    return $ja;
}

/**
 * Aus einer MQTT-Nachricht einen Messwert machen.
 *
 * Drei Formen werden angenommen, und alle drei sind belegt oder trivial:
 *
 *  1. Eine Shelly-Meldung "NotifyStatus"/"NotifyFullStatus". Gesucht wird
 *     NICHT nach einem festen Bauteilnamen wie "switch:0" - gesucht wird nach
 *     dem ERSTEN Unterobjekt in params, das ein numerisches "apower" traegt.
 *     Damit tragen auch "pm1:0", "em:0" und was der Hersteller sonst noch
 *     einfuehrt, ohne dass jemand eine Liste pflegen muss. Ein Name, den man
 *     raet, ist dasselbe wie eine Registeradresse, die man raet.
 *  2. Eine blanke Zahl - fuer jede andere Quelle, die schlicht Watt schickt.
 *  3. Ein JSON-Objekt, das unmittelbar "apower" oder "power" traegt.
 *
 * Rueckgabe: array(watt, volt, ampere, hertz) oder NULL.
 *
 * NULL heisst "diese Nachricht traegt keinen Messwert" - und das ist der
 * Normalfall, nicht ein Fehler: von zwei gemessenen Shelly-Meldungen trug
 * nur eine apower. Wer hier 0 zurueckgaebe, meldete eine stehende Pumpe.
 */
function pw_mqtt_messwert($nutzlast)
{
    $s = trim((string) $nutzlast);
    if ($s === '') { return null; }

    // 2. Eine blanke Zahl.
    $z = str_replace(',', '.', $s);
    if (is_numeric($z)) {
        return array('watt' => (float) $z, 'volt' => null,
                     'ampere' => null, 'hertz' => null);
    }

    $d = json_decode($s, true);
    if (!is_array($d)) { return null; }

    // 3. Unmittelbar am Objekt.
    foreach (array('apower', 'power', 'watt') as $k) {
        if (isset($d[$k]) && is_numeric($d[$k])) {
            return array('watt' => (float) $d[$k],
                         'volt' => isset($d['voltage']) && is_numeric($d['voltage']) ? (float) $d['voltage'] : null,
                         'ampere' => isset($d['current']) && is_numeric($d['current']) ? (float) $d['current'] : null,
                         'hertz' => isset($d['freq']) && is_numeric($d['freq']) ? (float) $d['freq'] : null);
        }
    }

    // 1. Shelly-Meldung.
    $methode = isset($d['method']) ? (string) $d['method'] : '';
    if ($methode !== '' && strncmp($methode, 'Notify', 6) !== 0) { return null; }
    if (!isset($d['params']) || !is_array($d['params'])) { return null; }
    foreach ($d['params'] as $teil) {
        if (!is_array($teil) || !isset($teil['apower']) || !is_numeric($teil['apower'])) {
            continue;
        }
        return array(
            'watt'   => (float) $teil['apower'],
            'volt'   => isset($teil['voltage']) && is_numeric($teil['voltage']) ? (float) $teil['voltage'] : null,
            'ampere' => isset($teil['current']) && is_numeric($teil['current']) ? (float) $teil['current'] : null,
            'hertz'  => isset($teil['freq']) && is_numeric($teil['freq']) ? (float) $teil['freq'] : null,
        );
    }
    return null;
}

/**
 * Sagt eine Nachricht etwas ueber die ANWESENHEIT der Quelle?
 *
 * Der Shelly veroeffentlicht "<praefix>/online" aufbewahrt - gemessen, es kam
 * im ersten Lauf sofort und als einziges. Das ist ein Lebenszeichen des
 * GERAETS, unabhaengig vom Alter des Messwerts, und damit die zweite,
 * unabhaengige Auskunft: "seit Minuten kein Wert" heisst etwas anderes,
 * wenn das Geraet sich abgemeldet hat.
 *
 * Rueckgabe: 1 anwesend, 0 abgemeldet, -1 "sagt dazu nichts".
 */
function pw_mqtt_anwesenheit($thema, $nutzlast)
{
    if (substr((string) $thema, -7) !== '/online') { return -1; }
    $s = strtolower(trim((string) $nutzlast));
    if ($s === 'true' || $s === '1' || $s === 'online') { return 1; }
    if ($s === 'false' || $s === '0' || $s === 'offline') { return 0; }
    return -1;
}

/**
 * EINE Zeile von mosquitto_sub verarbeiten: "<thema> <nutzlast>".
 *
 * Sie steht hier und nicht in der Schleife des Dauerlaeufers, damit sie
 * sich mit den echten gemessenen Zeilen pruefen laesst, ohne dass ein
 * Broker, ein mosquitto_sub und eine POSIX-Schale dasein muessen. Eine
 * Pruefung, die den Zweig nicht erreicht, ist keine Entlastung.
 *
 * $schreiben = false rechnet und schreibt NICHT - das ist der Probelauf.
 *
 * Rueckgabe: array(art, watt, neben, versucht, gescheitert) mit art aus
 *   messwert    - ein Watt-Wert war dabei, der Zustand wurde
 *                 fortgeschrieben und veroeffentlicht
 *   anwesenheit - nur die An-/Abmeldung der Quelle
 *   nichts      - die Zeile trug keines von beidem. DAS IST DER
 *                 NORMALFALL, kein Fehler: von zwei gemessenen
 *                 Shelly-Meldungen je Minute traegt nur eine apower.
 */
/**
 * Passt ein Thema auf einen MQTT-Filter?
 *
 * Nach den Regeln des Protokolls, nicht nach Zeichenketten:
 *   +   deckt genau EINEN Abschnitt
 *   #   deckt den Rest und darf nur am Ende stehen
 *
 * Der Unterschied ist in diesem Haus nicht theoretisch: es gibt
 * `shelly1pmg4/#` und `shelly1pmg4-Pumpensumpf/#` nebeneinander. Ein
 * strpos() schluege die Zeilen der zweiten der ersten zu.
 */
function pw_thema_passt($filter, $thema)
{
    $f = explode('/', trim((string) $filter));
    $s = explode('/', trim((string) $thema));
    $nf = count($f);
    for ($i = 0; $i < $nf; $i++) {
        if ($f[$i] === '#') { return $i === $nf - 1; }
        if (!isset($s[$i])) { return false; }
        if ($f[$i] === '+') { continue; }
        if ($f[$i] !== $s[$i]) { return false; }
    }
    return count($s) === $nf;
}

/**
 * Welcher Pumpe gehoert diese Zeile?
 *
 * Bei mehreren passenden Filtern gewinnt der SPEZIFISCHERE - gemessen an der
 * Zahl der Abschnitte ohne Platzhalter. Sonst haenge es von der Reihenfolge
 * in der Konfiguration ab, welche Pumpe eine Zeile bekommt, und das waere
 * eine stille Falle.
 *
 * Rueckgabe: die Kennung, oder null.
 */
function pw_pumpe_fuer_thema($cfg, $thema)
{
    $beste = null; $punkte = -1;
    foreach (isset($cfg['pumpen']) ? $cfg['pumpen'] : array() as $p) {
        if (!isset($p['quelle']) || $p['quelle'] !== 'mqtt') { continue; }
        $f = isset($p['quelle_topic']) ? trim((string) $p['quelle_topic']) : '';
        if ($f === '' || !pw_thema_passt($f, $thema)) { continue; }
        $wert = 0;
        foreach (explode('/', $f) as $a) {
            if ($a !== '#' && $a !== '+') { $wert++; }
        }
        if ($wert > $punkte) { $punkte = $wert; $beste = (string) $p['id']; }
    }
    return $beste;
}

/**
 * Das Anwesenheitsthema zu einem Quell-Filter.
 *
 * `shelly1pmg4-Pumpensumpf/#` -> `shelly1pmg4-Pumpensumpf/online`. Der
 * Platzhalter am Ende faellt weg; ohne Platzhalter wird angehaengt.
 */
function pw_online_thema($filter)
{
    $f = trim((string) $filter);
    if ($f === '') { return ''; }
    $a = explode('/', $f);
    $letzt = end($a);
    if ($letzt === '#' || $letzt === '+') { array_pop($a); }
    if (!$a) { return ''; }
    return implode('/', $a) . '/online';
}

function pw_zeile_verarbeiten($zeile, $cfg, $jetzt = null, $schreiben = true)
{
    $cfg = pw_flach($cfg, 'pw_zeile_verarbeiten');
    $jetzt = $jetzt === null ? time() : $jetzt;
    $z = rtrim((string) $zeile, "\r\n");
    $leer = array('art' => 'nichts', 'watt' => null, 'neben' => array(),
                  'versucht' => 0, 'gescheitert' => 0);
    if ($z === '') { return $leer; }
    $pos = strpos($z, ' ');
    if ($pos === false) { return $leer; }
    $thema = substr($z, 0, $pos);
    $last = substr($z, $pos + 1);

    $neben = array();
    $an = pw_mqtt_anwesenheit($thema, $last);
    if ($an >= 0) { $neben['quelle_online'] = $an; }

    $m = pw_mqtt_messwert($last);
    if ($m === null) {
        /* Kein Messwert - aber vielleicht die Anwesenheit. Sie wird
         * festgehalten, ohne zu rechnen: eine An- oder Abmeldung ist
         * keine Messung und darf die Laufzeit nicht fortschreiben. */
        if ($an >= 0 && $schreiben) {
            pw_zustand_aendern(function ($st) use ($an) {
                $st['quelle_online'] = $an;
                return $st;
            }, $cfg, false, $jetzt);
        }
        return array('art' => $an >= 0 ? 'anwesenheit' : 'nichts',
                     'watt' => null, 'neben' => $neben,
                     'versucht' => 0, 'gescheitert' => 0);
    }

    $neben['volt'] = $m['volt'];
    $neben['ampere'] = $m['ampere'];
    $neben['hertz'] = $m['hertz'];
    $versucht = 0; $fehl = 0;
    if ($schreiben) {
        list($stand, $grund, $versucht, $fehl) =
            pw_verarbeiten($m['watt'], $cfg, $jetzt, 'mqtt', false, $neben);
        if ($stand === null && $grund !== 'belegt') {
            pw_log('Zuhoerer: Zustand konnte nicht geschrieben werden (' . $grund . ').');
        }
    }
    return array('art' => 'messwert', 'watt' => $m['watt'], 'neben' => $neben,
                 'versucht' => $versucht, 'gescheitert' => $fehl);
}

/**
 * Ein Signal schicken - mit posix_kill, wenn es das gibt, sonst ueber die
 * Schale. Die Erweiterung posix ist auf einem LoxBerry nicht zugesichert,
 * und ein fehlendes posix_kill() waere kein abfangbarer Fehler, sondern
 * ein toedlicher. Die PID ist immer eine Zahl - da kann nichts
 * eingeschleust werden.
 */
function pw_signal($pid, $nr)
{
    $pid = (int) $pid;
    $nr = (int) $nr;
    if ($pid <= 1) { return false; }
    if (function_exists('posix_kill')) { return @posix_kill($pid, $nr); }
    if (function_exists('exec')) {
        @exec('kill -' . $nr . ' ' . $pid . ' 2>/dev/null');
        return true;
    }
    return false;
}

/**
 * Der Bericht des Zuhoerers.
 *
 * Er zaehlt seit seinem Start: Zeilen mit Messwert, Zeilen ohne, und Zeilen,
 * die zu KEINER Pumpe gehoeren. Die dritte Zahl ist die interessante - eine
 * Zeile ohne Pumpe ist ein Einrichtungsfehler, meistens ein von Hand
 * erweitertes Abo, und der Zuhoerer verwirft sie.
 *
 * Ohne Datei: ein leeres Feld. "Keine Auskunft" ist nicht "null fremde".
 */
function pw_dienst_bericht()
{
    $d = pw_json_lesen(pw_paths()['datadir'] . '/dienst.json');
    return is_array($d) && isset($d['ts']) ? $d : array();
}

/** Laeuft der Zuhoerer? Gemessen an der PID-Datei UND am Prozess. */
function pw_dienst_pid()
{
    $p = pw_paths();
    $datei = $p['datadir'] . '/dienst.pid';
    if (!is_file($datei)) { return 0; }
    $pid = (int) trim((string) @file_get_contents($datei));
    if ($pid <= 0) { return 0; }
    /* Ein Eintrag in der PID-Datei ist eine Behauptung, kein Befund - der
     * Prozess kann laengst tot sein. Nachgesehen wird in /proc. */
    if (is_dir('/proc/' . $pid)) { return $pid; }
    return 0;
}

/** Das Lebenszeichen des Zuhoerers: wann hat er zuletzt etwas getan? */
function pw_dienst_alter()
{
    $p = pw_paths();
    $datei = $p['datadir'] . '/dienst.ts';
    if (!is_file($datei)) { return -1; }
    $ts = (int) trim((string) @file_get_contents($datei));
    return $ts > 0 ? (time() - $ts) : -1;
}
