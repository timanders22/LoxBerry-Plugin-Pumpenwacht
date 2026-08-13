<?php
/**
 * Pumpenwacht - der Rechenkern
 *
 * Hier steht ausschliesslich Rechnung. Kein Netz, keine Datei, keine Uhr,
 * die nicht uebergeben wurde. Deshalb laesst sich dieser Teil ohne Pumpe,
 * ohne Wasser und ohne Steckdose pruefen.
 *
 * WAS DIESES PLUGIN NICHT KANN, UND WARUM:
 * Die Grundfos SCALA1 hat genau eine Datenschnittstelle, naemlich Bluetooth
 * LE fuer die App "Grundfos GO Remote". Kein Ethernet, kein Modbus, kein
 * GENIbus-Anschluss - die Montage- und Betriebsanleitung fuehrt unter
 * "Elektrische Daten" nichts dergleichen, und der Schaltplan kennt nur
 * Stromeingang, Schwimmerschalter und Dreiwegeventil. Ein offenes
 * BLE-Protokoll ist fuer die SCALA nicht veroeffentlicht; es gibt eines fuer
 * die Alpha3 Model B, und Grundfos schreibt dort ausdruecklich, dass schon
 * die Alpha2 und die Alpha3 Model A ein ANDERES Protokoll sprechen. Von der
 * einen auf die andere zu schliessen waere Verdacht, kein Befund.
 *
 * Deshalb misst dieses Plugin UM die Pumpe herum: an der Steckdose, am
 * Druck, am Durchfluss. Daraus laesst sich ueberraschend viel ablesen - eine
 * Kreiselpumpe verraet ihren Zustand an der Leistungsaufnahme.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

define('PW_KERN', '1.0.0');

/* Die Befunde. Diese Woerter erscheinen uebersetzt in der Oberflaeche; wer
 * hier eines hinzufuegt, muss es in beiden Sprachdateien unter [BEFUND]
 * nachtragen. */
define('PW_OK',          'ok');
define('PW_SCHALTSPIEL', 'schaltspiel');
define('PW_DAUERLAUF',   'dauerlauf');
define('PW_TROCKEN',     'trockenlauf');
define('PW_UEBERLAST',   'ueberlast');
define('PW_STILL',       'still');

function pw_zahl($wert, $vorgabe = 0.0)
{
    if (is_bool($wert) || $wert === null) { return (float) $vorgabe; }
    if (is_int($wert) || is_float($wert)) {
        return is_finite((float) $wert) ? (float) $wert : (float) $vorgabe;
    }
    $s = str_replace(',', '.', trim((string) $wert));
    if ($s === '' || !is_numeric($s)) { return (float) $vorgabe; }
    $f = (float) $s;
    return is_finite($f) ? $f : (float) $vorgabe;
}

/**
 * Die Werte aus dem Datenblatt - NICHT geraten.
 *
 * Sie stammen aus Abschnitt 8.3 "Elektrische Daten" und 8.1
 * "Betriebsbedingungen" der Montage- und Betriebsanleitung. Sie dienen
 * ausschliesslich als VORSCHLAG fuer die Schwellen; wer misst, traegt seine
 * eigenen Zahlen ein. Die Schalthaeufigkeit von 25 je Stunde ist die vom
 * Hersteller angegebene zulaessige, nicht eine von mir gewaehlte Grenze.
 */
function pw_modelle()
{
    return array(
        'frei'   => array('p1' => 0,   'standby' => 0.0, 'starts_h' => 25, 'name' => 'MODELL.FREI'),
        '3-35'   => array('p1' => 720, 'standby' => 1.5, 'starts_h' => 25, 'name' => 'MODELL.3_35'),
        '3-45'   => array('p1' => 910, 'standby' => 1.5, 'starts_h' => 25, 'name' => 'MODELL.3_45'),
    );
}

/**
 * Laeuft die Pumpe?
 *
 * Rueckgabe: 1 laeuft, 0 steht, -1 unbekannt.
 *
 * -1 ist kein Schoenheitsfehler, sondern der wichtigste der drei Werte: ohne
 * Messwert ist NICHT bekannt, dass die Pumpe steht. Wer hier 0 zurueckgibt,
 * meldet nach einem Ausfall des Zwischenzaehlers eine ruhende Anlage - und
 * niemand sieht mehr nach.
 */
function pw_laeuft($watt, $schwelle_w)
{
    if ($watt === null || $watt === '') { return -1; }
    return (pw_zahl($watt, 0.0) >= max(0.5, pw_zahl($schwelle_w, 20.0))) ? 1 : 0;
}

/**
 * Wie viele Starts liegen im Fenster?
 *
 * $starts ist eine Liste von Zeitstempeln, aufsteigend.
 */
function pw_starts_im_fenster($starts, $jetzt, $fenster_s = 3600)
{
    $n = 0;
    foreach ((array) $starts as $t) {
        if ($jetzt - pw_zahl($t, 0.0) < $fenster_s) { $n++; }
    }
    return $n;
}

/** Die Liste der Startzeitpunkte kurz halten - sie steht in einer Datei. */
function pw_starts_stutzen($starts, $jetzt, $behalten_s = 172800, $hoechstens = 400)
{
    $out = array();
    foreach ((array) $starts as $t) {
        $t = pw_zahl($t, 0.0);
        if ($t > 0 && $jetzt - $t <= $behalten_s) { $out[] = $t; }
    }
    sort($out);
    if (count($out) > $hoechstens) { $out = array_slice($out, -$hoechstens); }
    return $out;
}

/**
 * Den Befund stellen.
 *
 * Rueckgabe: (Befund, Beiwert). Der Beiwert ist die Zahl, die den Befund
 * traegt - Starts je Stunde, Laufsekunden, Watt -, damit die Oberflaeche
 * nicht nur sagt, DASS etwas ist, sondern woran es festgemacht wurde.
 *
 * Die Reihenfolge ist Absicht: Trockenlauf zuerst, denn er zerstoert die
 * Wellendichtung innerhalb von Minuten. Dauerlauf danach, Schaltspiel
 * zuletzt - ein Mikroleck kostet Wasser, kein Bauteil.
 */
function pw_befund($mess, $cfg, $lauf_s, $starts, $jetzt)
{
    $laeuft = isset($mess['laeuft']) ? (int) $mess['laeuft'] : -1;
    $watt = isset($mess['watt']) ? $mess['watt'] : null;

    if ($laeuft < 0) { return array(PW_STILL, 0.0); }

    if ($laeuft === 1) {
        $w = pw_zahl($watt, 0.0);
        $trocken_w = pw_zahl(isset($cfg['trocken_w']) ? $cfg['trocken_w'] : 0, 0.0);
        $trocken_s = max(5.0, pw_zahl(isset($cfg['trocken_s']) ? $cfg['trocken_s'] : 30, 30.0));
        /* Eine Kreiselpumpe, die Luft statt Wasser foerdert, nimmt WENIGER
         * Leistung auf, nicht mehr: es ist nichts da, was beschleunigt
         * werden muesste. Das ist der eine Fall, in dem eine ungewoehnlich
         * niedrige Zahl die schlimmere Nachricht ist. */
        if ($trocken_w > 0 && $w > 0 && $w < $trocken_w && $lauf_s >= $trocken_s) {
            return array(PW_TROCKEN, $w);
        }
        $ueber_w = pw_zahl(isset($cfg['ueberlast_w']) ? $cfg['ueberlast_w'] : 0, 0.0);
        if ($ueber_w > 0 && $w > $ueber_w && $lauf_s >= 10) {
            return array(PW_UEBERLAST, $w);
        }
        $dauer_s = pw_zahl(isset($cfg['dauerlauf_s']) ? $cfg['dauerlauf_s'] : 0, 0.0);
        if ($dauer_s > 0 && $lauf_s >= $dauer_s) {
            return array(PW_DAUERLAUF, $lauf_s);
        }
    }

    $grenze = (int) pw_zahl(isset($cfg['starts_h']) ? $cfg['starts_h'] : 0, 0.0);
    if ($grenze > 0) {
        $n = pw_starts_im_fenster($starts, $jetzt, 3600);
        if ($n >= $grenze) { return array(PW_SCHALTSPIEL, (float) $n); }
    }

    return array(PW_OK, 0.0);
}

/** Soll dieser Befund die Pumpe sperren? */
function pw_sperrt($befund, $cfg)
{
    if ($befund === PW_OK || $befund === PW_STILL) { return false; }
    $k = 'sperre_' . $befund;
    return !empty($cfg['sperren_ein']) && !empty($cfg[$k]);
}

/**
 * Ein Durchlauf: Zustand fortschreiben und Befund stellen.
 *
 * $mess:  watt, druck, fluss, tag (Datum als Text)
 * $cfg:   an_w, trocken_w, trocken_s, ueberlast_w, dauerlauf_s, starts_h,
 *         sperren_ein, sperre_<befund>, quittung_noetig
 * $alt:   der Stand des letzten Durchlaufs
 *
 * Rueckgabe: der neue Stand.
 */
function pw_schritt($mess, $cfg, $alt, $jetzt)
{
    $laeuft = pw_laeuft(isset($mess['watt']) ? $mess['watt'] : null,
                        isset($cfg['an_w']) ? $cfg['an_w'] : 20);

    $alt_laeuft = isset($alt['laeuft']) ? (int) $alt['laeuft'] : -1;
    $seit = pw_zahl(isset($alt['seit']) ? $alt['seit'] : 0, 0.0);
    $starts = isset($alt['starts']) ? (array) $alt['starts'] : array();

    $neu = array(
        'zeit'          => $jetzt,
        'laeuft'        => $laeuft,
        'seit'          => $seit > 0 ? $seit : $jetzt,
        'starts'        => $starts,
        'lauf_s'        => 0.0,
        'letzter_lauf_s'=> pw_zahl(isset($alt['letzter_lauf_s']) ? $alt['letzter_lauf_s'] : -1, -1.0),
        'lauf_s_tag'    => pw_zahl(isset($alt['lauf_s_tag']) ? $alt['lauf_s_tag'] : 0, 0.0),
        'starts_tag'    => (int) pw_zahl(isset($alt['starts_tag']) ? $alt['starts_tag'] : 0, 0.0),
        'laengster_tag' => pw_zahl(isset($alt['laengster_tag']) ? $alt['laengster_tag'] : 0, 0.0),
        'tag'           => isset($alt['tag']) ? $alt['tag'] : (isset($mess['tag']) ? $mess['tag'] : ''),
        'befund'        => PW_OK,
        'beiwert'       => 0.0,
        'sperre'        => !empty($alt['sperre']) ? 1 : 0,
        'sperrgrund'    => isset($alt['sperrgrund']) ? $alt['sperrgrund'] : '',
        'quittung'      => !empty($alt['quittung']) ? 1 : 0,
    );

    /* Tageswechsel: die Tageszahlen beginnen von vorn. Das Datum kommt von
     * aussen herein, damit der Kern keine Uhr braucht und die Zeitzone dort
     * bleibt, wo sie hingehoert. */
    $tag_neu = isset($mess['tag']) ? (string) $mess['tag'] : $neu['tag'];
    if ($tag_neu !== '' && $tag_neu !== $neu['tag']) {
        $neu['tag'] = $tag_neu;
        $neu['lauf_s_tag'] = 0.0;
        $neu['starts_tag'] = 0;
        $neu['laengster_tag'] = 0.0;
    }

    /* Zustandswechsel */
    if ($laeuft !== $alt_laeuft) {
        if ($laeuft === 1) {
            $starts[] = $jetzt;
            $neu['starts_tag']++;
        } elseif ($alt_laeuft === 1) {
            // Ein Lauf ist zu Ende - seine Dauer wird festgehalten.
            $dauer = max(0.0, $jetzt - $seit);
            $neu['letzter_lauf_s'] = $dauer;
            $neu['laengster_tag'] = max($neu['laengster_tag'], $dauer);
        }
        $neu['seit'] = $jetzt;
    }
    $neu['starts'] = pw_starts_stutzen($starts, $jetzt);

    if ($laeuft === 1) {
        $neu['lauf_s'] = max(0.0, $jetzt - $neu['seit']);
        // Laufzeit des Tages fortschreiben - anhand des Abstands zum letzten
        // Durchlauf, nicht anhand des Takts: ein ausgefallener Durchlauf
        // soll die Bilanz nicht verfaelschen.
        $vorher = pw_zahl(isset($alt['zeit']) ? $alt['zeit'] : 0, 0.0);
        if ($vorher > 0 && $alt_laeuft === 1) {
            $neu['lauf_s_tag'] += max(0.0, min(3600.0, $jetzt - $vorher));
        }
    }

    list($befund, $beiwert) = pw_befund(
        array('laeuft' => $laeuft, 'watt' => isset($mess['watt']) ? $mess['watt'] : null),
        $cfg, $neu['lauf_s'], $neu['starts'], $jetzt);
    $neu['befund'] = $befund;
    $neu['beiwert'] = $beiwert;

    /* Sperren und Freigeben.
     *
     * Gesperrt wird, sobald ein sperrender Befund vorliegt. Aufgehoben wird
     * NICHT von selbst, wenn "quittieren" verlangt ist - genau so haelt es
     * die SCALA1 mit ihrem Alarm "maximale Laufzeit ueberschritten", und aus
     * gutem Grund: ein Trockenlauf, der sich von selbst zurueckstellt,
     * wiederholt sich, bis die Wellendichtung hin ist. */
    if (pw_sperrt($befund, $cfg)) {
        if (!$neu['sperre']) {
            $neu['sperre'] = 1;
            $neu['sperrgrund'] = $befund;
        }
        $neu['quittung'] = !empty($cfg['quittung_noetig']) ? 1 : 0;
    } elseif ($neu['sperre'] && !$neu['quittung'] && $befund === PW_OK) {
        /* Freigegeben wird NUR bei ausdruecklicher Entwarnung, also wenn ein
         * Messwert vorliegt UND er unauffaellig ist. Nicht schon dann, wenn
         * gerade kein sperrender Befund gestellt werden KANN: sonst gaebe
         * ausgerechnet der Ausfall des Zwischenzaehlers die Pumpe wieder
         * frei - eine stille Freigabe durch eine Stoerung. */
        $neu['sperre'] = 0;
        $neu['sperrgrund'] = '';
    }

    return $neu;
}

/** Die Sperre von Hand aufheben. */
function pw_quittieren($stand)
{
    $stand['sperre'] = 0;
    $stand['sperrgrund'] = '';
    $stand['quittung'] = 0;
    return $stand;
}

/* ==================================================================
 * Selbsttest
 * ================================================================== */

function pw_selbsttest($ausgabe = true)
{
    $n = 0; $f = 0;
    $pruef = function ($name, $ist, $soll, $genau = 0.5) use (&$n, &$f, $ausgabe) {
        $n++;
        $ok = is_string($soll) ? ($ist === $soll) : (abs($ist - $soll) <= $genau);
        if (!$ok) { $f++; }
        if ($ausgabe) {
            echo ($ok ? '[ OK ] ' : '[FEHL] ') . $name;
            if (!$ok) { echo '  -> ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true); }
            echo "\n";
        }
    };

    // ---- Laeuft die Pumpe? ----
    $pruef('600 W: laeuft', pw_laeuft(600, 20), 1);
    $pruef('1,5 W Standby: steht', pw_laeuft(1.5, 20), 0);
    $pruef('kein Messwert: unbekannt, nicht "steht"', pw_laeuft(null, 20), -1);
    $pruef('Leerstring: unbekannt', pw_laeuft('', 20), -1);
    $pruef('Text als Zahl', pw_laeuft('612,5', 20), 1);

    // ---- Starts zaehlen ----
    $j = 100000.0;
    $st = array($j - 7100, $j - 1800, $j - 600, $j - 60);
    $pruef('Starts in der letzten Stunde', pw_starts_im_fenster($st, $j, 3600), 3);
    $pruef('Starts in den letzten zwei Stunden', pw_starts_im_fenster($st, $j, 7200), 4);
    $pruef('alte Starts fallen heraus', count(pw_starts_stutzen($st, $j, 3600)), 3);
    // Der Rand gehoert NICHT mehr dazu - sonst zaehlte ein Start je nach
    // Rundung der Uhr mal mit und mal nicht, und die Grenze waere zufaellig.
    $pruef('genau am Rand zaehlt nicht mehr', pw_starts_im_fenster(array($j - 3600), $j, 3600), 0);
    $pruef('eine Sekunde davor zaehlt', pw_starts_im_fenster(array($j - 3599), $j, 3600), 1);

    $cfg = array('an_w' => 20, 'trocken_w' => 300, 'trocken_s' => 30, 'ueberlast_w' => 1100,
                 'dauerlauf_s' => 1800, 'starts_h' => 25, 'sperren_ein' => 1,
                 'sperre_trockenlauf' => 1, 'sperre_dauerlauf' => 0,
                 'sperre_schaltspiel' => 0, 'sperre_ueberlast' => 0, 'quittung_noetig' => 1);

    // ---- Befunde ----
    list($b, $w) = pw_befund(array('laeuft' => 1, 'watt' => 600), $cfg, 60, array(), $j);
    $pruef('normaler Lauf', $b, PW_OK);
    list($b, $w) = pw_befund(array('laeuft' => 1, 'watt' => 180), $cfg, 60, array(), $j);
    $pruef('wenig Leistung, lange genug: Trockenlauf', $b, PW_TROCKEN);
    /* Der Anlaufaugenblick zaehlt nicht: beim Ansaugen ist die Leistung
     * niedrig, ohne dass etwas kaputt waere. Das Handbuch raeumt der Pumpe
     * dafuer bis zu fuenf Minuten ein. */
    list($b, $w) = pw_befund(array('laeuft' => 1, 'watt' => 180), $cfg, 10, array(), $j);
    $pruef('wenig Leistung, aber gerade erst an: kein Befund', $b, PW_OK);
    list($b, $w) = pw_befund(array('laeuft' => 1, 'watt' => 1300), $cfg, 60, array(), $j);
    $pruef('zu viel Leistung: Ueberlast', $b, PW_UEBERLAST);
    list($b, $w) = pw_befund(array('laeuft' => 1, 'watt' => 600), $cfg, 2000, array(), $j);
    $pruef('lange ununterbrochen: Dauerlauf', $b, PW_DAUERLAUF);
    $pruef('Dauerlauf nennt die Sekunden', $w, 2000);

    $viele = array();
    for ($i = 0; $i < 26; $i++) { $viele[] = $j - $i * 100; }
    list($b, $w) = pw_befund(array('laeuft' => 0, 'watt' => 1.5), $cfg, 0, $viele, $j);
    $pruef('26 Starts in der Stunde: Schaltspiel', $b, PW_SCHALTSPIEL);
    $pruef('Schaltspiel nennt die Anzahl', $w, 26);

    list($b, $w) = pw_befund(array('laeuft' => -1, 'watt' => null), $cfg, 0, $viele, $j);
    $pruef('ohne Messwert kein Befund, sondern Stille', $b, PW_STILL);

    // Trockenlauf geht vor Dauerlauf - er zerstoert schneller.
    list($b, $w) = pw_befund(array('laeuft' => 1, 'watt' => 180), $cfg, 3000, array(), $j);
    $pruef('Trockenlauf vor Dauerlauf', $b, PW_TROCKEN);

    // ---- Zustandsfolge ----
    $stand = array();
    $t = 1000.0;
    $stand = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11'), $cfg, $stand, $t);
    $pruef('erster Durchlauf: steht', $stand['laeuft'], 0);
    $pruef('noch kein Start gezaehlt', $stand['starts_tag'], 0);

    $t += 10;
    $stand = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $stand, $t);
    $pruef('Pumpe laeuft an', $stand['laeuft'], 1);
    $pruef('ein Start gezaehlt', $stand['starts_tag'], 1);
    $pruef('Laufzeit beginnt bei null', $stand['lauf_s'], 0);

    $t += 120;
    $stand = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $stand, $t);
    $pruef('nach 120 s laeuft sie 120 s', $stand['lauf_s'], 120);
    $pruef('Tageslaufzeit waechst mit', $stand['lauf_s_tag'], 120);
    $pruef('kein zweiter Start', $stand['starts_tag'], 1);

    $t += 30;
    $stand = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11'), $cfg, $stand, $t);
    $pruef('Pumpe geht aus', $stand['laeuft'], 0);
    $pruef('Dauer des letzten Laufs festgehalten', $stand['letzter_lauf_s'], 150);
    $pruef('laengster Lauf des Tages', $stand['laengster_tag'], 150);

    // Ein ausgefallener Durchlauf darf die Bilanz nicht sprengen.
    $stand2 = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $stand, $t + 10);
    $stand2 = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $stand2, $t + 100000);
    $pruef('Luecke wird auf eine Stunde gedeckelt', $stand2['lauf_s_tag'] - $stand['lauf_s_tag'], 3600);

    // Tageswechsel
    $stand3 = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-12'), $cfg, $stand, $t + 200);
    $pruef('neuer Tag: Laufzeit zurueckgesetzt', $stand3['lauf_s_tag'], 0);
    $pruef('neuer Tag: Starts zurueckgesetzt', $stand3['starts_tag'], 0);
    $pruef('neuer Tag: Startliste bleibt (sie traegt das Schaltspiel)',
           count($stand3['starts']), count($stand['starts']));

    // ---- Sperren ----
    $s = array('laeuft' => 1, 'seit' => 5000, 'starts' => array(), 'zeit' => 5000);
    $s = pw_schritt(array('watt' => 180, 'tag' => '2026-08-11'), $cfg, $s, 5100);
    $pruef('Trockenlauf sperrt', $s['sperre'], 1);
    $pruef('Sperrgrund wird genannt', $s['sperrgrund'], PW_TROCKEN);
    // Sie bleibt gesperrt, auch wenn der Befund verschwindet - sonst liefe
    // sie wieder trocken, sobald sie kurz Wasser bekommt.
    $s = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $s, 5200);
    $pruef('Sperre bleibt bis zum Quittieren', $s['sperre'], 1);
    $s = pw_quittieren($s);
    $pruef('nach dem Quittieren frei', $s['sperre'], 0);
    $s = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $s, 5300);
    $pruef('bleibt frei, solange nichts anliegt', $s['sperre'], 0);

    // Ohne Quittungspflicht loest sich die Sperre von selbst.
    $cfg2 = array_merge($cfg, array('quittung_noetig' => 0));
    $s = array('laeuft' => 1, 'seit' => 5000, 'starts' => array(), 'zeit' => 5000);
    $s = pw_schritt(array('watt' => 180, 'tag' => '2026-08-11'), $cfg2, $s, 5100);
    $pruef('sperrt auch ohne Quittungspflicht', $s['sperre'], 1);
    $s = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg2, $s, 5200);
    $pruef('und gibt von selbst wieder frei', $s['sperre'], 0);

    // Ein abgeschaltetes Sperren sperrt nicht.
    $cfg3 = array_merge($cfg, array('sperren_ein' => 0));
    $s = array('laeuft' => 1, 'seit' => 5000, 'starts' => array(), 'zeit' => 5000);
    $s = pw_schritt(array('watt' => 180, 'tag' => '2026-08-11'), $cfg3, $s, 5100);
    $pruef('Sperren aus: Befund ja, Sperre nein', $s['befund'], PW_TROCKEN);
    $pruef('Sperren aus: nicht gesperrt', $s['sperre'], 0);

    /* Der gefaehrlichste Fall: der Zwischenzaehler faellt aus, waehrend die
     * Pumpe gesperrt ist. Ohne Messwert darf die Sperre NICHT von selbst
     * fallen - sonst gibt ausgerechnet ein Ausfall die Pumpe frei. */
    $s = array('laeuft' => 1, 'seit' => 5000, 'starts' => array(), 'zeit' => 5000);
    $s = pw_schritt(array('watt' => 180, 'tag' => '2026-08-11'), $cfg, $s, 5100);
    $s = pw_schritt(array('watt' => null, 'tag' => '2026-08-11'), $cfg, $s, 5200);
    $pruef('Messwert faellt aus: Sperre bleibt', $s['sperre'], 1);
    $pruef('Messwert faellt aus: Zustand unbekannt', $s['laeuft'], -1);

    if ($ausgabe) {
        echo sprintf("\nPumpenwacht-Kern %s: %d Faelle geprueft, %d Fehlschlaege.\n",
                     PW_KERN, $n, $f);
    }
    return array($n, $f);
}

/* Nur beim direkten Aufruf. Wird die Datei eingebunden, passiert nichts. */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    list($n, $f) = pw_selbsttest(true);
    exit($f === 0 ? 0 : 1);
}
