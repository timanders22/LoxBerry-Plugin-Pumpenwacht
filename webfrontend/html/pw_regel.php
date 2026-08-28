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
 * Deshalb misst dieses Plugin UM die Pumpe herum, naemlich an der
 * LEISTUNGSAUFNAHME. Eine Kreiselpumpe verraet ihren Zustand daran.
 *
 * Bis 0.9.7 nannte dieser Kopf auch Druck und Durchfluss, und pw_schritt()
 * fuehrte "druck" und "fluss" in seiner Parameterliste. Ausgewertet wurde
 * beides nie. Am 28.08.2026 gestrichen: der Betreiber dieser Anlage hat
 * keinen Druck- und keinen Durchflussgeber, und eine Zusage im Kommentar,
 * die der Code nicht haelt, ist eine Falschaussage. Kommt ein Geber dazu,
 * wird die Auswertung gebaut UND der Satz wieder hingeschrieben - in dieser
 * Reihenfolge.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

define('PW_KERN', '1.1.0');

/* Die Befunde. Diese Woerter erscheinen uebersetzt in der Oberflaeche; wer
 * hier eines hinzufuegt, muss es in beiden Sprachdateien unter [BEFUND]
 * nachtragen UND in pw_befund_zahl() sowie in der Namenstabelle des Reiters
 * "Einbindung in Loxone". Der Reiter Test zaehlt die drei Stellen
 * gegeneinander. */
define('PW_OK',          'ok');
define('PW_SCHALTSPIEL', 'schaltspiel');
define('PW_DAUERLAUF',   'dauerlauf');
define('PW_TROCKEN',     'trockenlauf');
define('PW_UEBERLAST',   'ueberlast');
define('PW_STILL',       'still');
/* Neu in 0.9.8: die Pumpe ist angefordert und nimmt trotzdem keine Leistung
 * auf - blockiertes Laufrad, defekter Kondensator, ausgefallener Schuetz.
 * Der Befund ist nur erreichbar, wenn Loxone die Anforderung meldet
 * (aktion=anforderung); ohne sie bleibt der Zweig aus. */
define('PW_KEIN_ANLAUF', 'kein_anlauf');

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
 *
 * DIE UNTERE SCHRANKE IST PFLICHT. Bis 0.9.7 hiess die Bedingung nur
 * "$jetzt - $t < $fenster_s". Liegt ein Startzeitpunkt in der ZUKUNFT - und
 * das passiert, sobald die Uhr zurueckspringt (NTP-Korrektur, falsch
 * gestellte Echtzeituhr, Neustart ohne Netz) -, ist die Differenz negativ und
 * damit immer kleiner als das Fenster. Es zaehlten dann ALLE gespeicherten
 * Starts als "in der letzten Stunde".
 *
 * Gemessen am 28.08.2026: 40 Starts im Abstand von 65 Minuten, vorher 0 im
 * Fenster und Befund "ok"; nach einem Ruecksprung um 100000 s meldete
 * derselbe Bestand 26 Starts, Befund "schaltspiel", Sperre gesetzt. Mit
 * Quittungspflicht (Vorgabe) haette die Pumpe bis zum Eingriff von Hand
 * gesperrt gestanden.
 */
function pw_starts_im_fenster($starts, $jetzt, $fenster_s = 3600)
{
    $n = 0;
    foreach ((array) $starts as $t) {
        $d = $jetzt - pw_zahl($t, 0.0);
        if ($d >= 0 && $d < $fenster_s) { $n++; }
    }
    return $n;
}

/**
 * Zeitstempel, die in der Zukunft liegen - der Nachweis eines Uhrsprungs.
 *
 * Eine Sekunde Spielraum, damit ein Start, der im selben Augenblick
 * eingetragen wurde, nicht als Sprung gilt.
 */
function pw_zeitsprung($starts, $jetzt)
{
    $n = 0;
    foreach ((array) $starts as $t) {
        if (pw_zahl($t, 0.0) - $jetzt > 1.0) { $n++; }
    }
    return $n;
}

/**
 * Die Liste der Startzeitpunkte kurz halten - sie steht in einer Datei.
 *
 * Zeitstempel aus der Zukunft bleiben ausdruecklich STEHEN: sie werden von
 * pw_starts_im_fenster() nicht gezaehlt und von pw_zeitsprung() gemeldet.
 * Wer sie hier wegwuerfe, verloere nach einem Uhrsprung den ganzen Bestand -
 * und damit die Schaltspielerkennung fuer die naechsten zwei Tage.
 */
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
 * Wie viele Startzeitpunkte muss die Liste fassen, damit die eingestellte
 * Grenze ueberhaupt erreichbar ist?
 *
 * Bis 0.9.7 galt fest 400, waehrend die Oberflaeche bis 1000 zuliess: jeder
 * eingetragene Wert zwischen 401 und 1000 schaltete den Schaltspiel-Befund
 * still ab. Gemessen 28.08.2026: 600 Starts geliefert, 400 gespeichert,
 * starts_h=500 ergab Befund "ok". Ein stiller Vorgabewert, der genau dann
 * bedient, wenn er nicht soll.
 */
function pw_starts_deckel($cfg)
{
    $g = (int) pw_zahl(isset($cfg['starts_h']) ? $cfg['starts_h'] : 0, 0.0);
    return max(400, $g + 50);
}

/**
 * Den Befund stellen.
 *
 * Rueckgabe: (Befund, Beiwert). Der Beiwert ist die Zahl, die den Befund
 * traegt - Starts je Stunde, Laufsekunden, Watt -, damit die Oberflaeche
 * nicht nur sagt, DASS etwas ist, sondern woran es festgemacht wurde. Seit
 * 0.9.8 geht er auch nach Loxone hinaus.
 *
 * Die Reihenfolge ist Absicht: Trockenlauf zuerst, denn er zerstoert die
 * Wellendichtung innerhalb von Minuten. Dauerlauf danach, Schaltspiel
 * zuletzt - ein Mikroleck kostet Wasser, kein Bauteil.
 *
 * $anfrage: array('seit' => Zeitpunkt der Anforderung, 0 = keine) - nur
 * belegt, wenn Loxone die Anforderung meldet.
 */
function pw_befund($mess, $cfg, $lauf_s, $starts, $jetzt, $anfrage = null)
{
    $laeuft = isset($mess['laeuft']) ? (int) $mess['laeuft'] : -1;
    $watt = isset($mess['watt']) ? $mess['watt'] : null;

    if ($laeuft < 0) { return array(PW_STILL, 0.0); }

    /* Angefordert und nimmt keine Leistung auf: blockiertes Laufrad,
     * defekter Kondensator, ausgefallener Schuetz. Der Zweig ist nur
     * erreichbar, wenn eine Anforderung gemeldet wurde UND anlauf_s > 0
     * eingestellt ist - ab Werk ist beides nicht der Fall. */
    $anlauf_s = pw_zahl(isset($cfg['anlauf_s']) ? $cfg['anlauf_s'] : 0, 0.0);
    $anf_seit = pw_zahl(isset($anfrage['seit']) ? $anfrage['seit'] : 0, 0.0);
    if ($laeuft === 0 && $anlauf_s > 0 && $anf_seit > 0
        && ($jetzt - $anf_seit) >= $anlauf_s) {
        return array(PW_KEIN_ANLAUF, $jetzt - $anf_seit);
    }

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
 * $mess:  watt, tag (Datum als Text), tagbeginn (Unix-Sekunde von 00:00 des
 *         Tages, aus dem die Uhr kommt - der Kern hat selbst keine)
 * $cfg:   an_w, trocken_w, trocken_s, ueberlast_w, dauerlauf_s, starts_h,
 *         anlauf_s, sperren_ein, sperre_<befund>, quittung_noetig
 * $alt:   der Stand des letzten Durchlaufs
 *
 * Rueckgabe: der neue Stand. Endet ein Tag, traegt der neue Stand
 * zusaetzlich 'vortag' mit den abgeschlossenen Tageszahlen - die Bibliothek
 * legt sie in die Tagesbilanz und entfernt den Schluessel wieder.
 */
function pw_schritt($mess, $cfg, $alt, $jetzt)
{
    $laeuft = pw_laeuft(isset($mess['watt']) ? $mess['watt'] : null,
                        isset($cfg['an_w']) ? $cfg['an_w'] : 20);

    $alt_laeuft = isset($alt['laeuft']) ? (int) $alt['laeuft'] : -1;
    $seit = pw_zahl(isset($alt['seit']) ? $alt['seit'] : 0, 0.0);
    $starts = isset($alt['starts']) ? (array) $alt['starts'] : array();
    $vorher = pw_zahl(isset($alt['zeit']) ? $alt['zeit'] : 0, 0.0);
    $tagbeginn = pw_zahl(isset($mess['tagbeginn']) ? $mess['tagbeginn'] : 0, 0.0);

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
        'lauf_s_gesamt' => pw_zahl(isset($alt['lauf_s_gesamt']) ? $alt['lauf_s_gesamt'] : 0, 0.0),
        'starts_gesamt' => (int) pw_zahl(isset($alt['starts_gesamt']) ? $alt['starts_gesamt'] : 0, 0.0),
        'tag'           => isset($alt['tag']) ? $alt['tag'] : (isset($mess['tag']) ? $mess['tag'] : ''),
        'befund'        => PW_OK,
        'beiwert'       => 0.0,
        'sperre'        => !empty($alt['sperre']) ? 1 : 0,
        'sperrgrund'    => isset($alt['sperrgrund']) ? $alt['sperrgrund'] : '',
        'quittung'      => !empty($alt['quittung']) ? 1 : 0,
        'anfrage_seit'  => pw_zahl(isset($alt['anfrage_seit']) ? $alt['anfrage_seit'] : 0, 0.0),
        'zeitsprung'    => 0,
    );

    /* Ein Uhrsprung wird GEMELDET, nicht verrechnet. Der Reiter Test zeigt
     * ihn, und die Startliste bleibt unangetastet - sie wird nur nicht
     * gezaehlt (siehe pw_starts_im_fenster). */
    $neu['zeitsprung'] = pw_zeitsprung($starts, $jetzt);

    /* Tageswechsel: die Tageszahlen beginnen von vorn. Das Datum kommt von
     * aussen herein, damit der Kern keine Uhr braucht und die Zeitzone dort
     * bleibt, wo sie hingehoert.
     *
     * Bis 0.9.7 lief der Ruecksetzblock VOR dem Fortschreiben, und das
     * angebrochene Intervall landete vollstaendig auf dem NEUEN Tag.
     * Gemessen 28.08.2026 mit 300-s-Takt und Tageswechsel 120 s vor dem
     * Takt: der neue Tag begann mit lauf_s_tag = 300 statt 120. Seit 0.9.8
     * wird am Tagesbeginn geteilt - dazu muss der Aufrufer 'tagbeginn'
     * mitgeben. Fehlt die Angabe, faellt es auf das alte Verhalten zurueck,
     * und der Reiter Test sagt es. */
    $tag_neu = isset($mess['tag']) ? (string) $mess['tag'] : $neu['tag'];
    $tagwechsel = ($tag_neu !== '' && $tag_neu !== $neu['tag'] && $neu['tag'] !== '');
    $anrechnen_ab = $vorher;
    if ($tag_neu !== '' && $neu['tag'] === '') { $neu['tag'] = $tag_neu; }
    if ($tagwechsel) {
        if ($alt_laeuft === 1 && $laeuft === 1 && $vorher > 0
            && $tagbeginn > $vorher && $tagbeginn <= $jetzt) {
            $neu['lauf_s_tag'] += max(0.0, min(3600.0, $tagbeginn - $vorher));
        }
        /* Den abgeschlossenen Tag herausreichen, BEVOR er geleert wird. */
        $neu['vortag'] = array(
            'tag'       => $neu['tag'],
            'lauf_s'    => round($neu['lauf_s_tag'], 1),
            'starts'    => $neu['starts_tag'],
            'laengster' => round($neu['laengster_tag'], 1),
        );
        $neu['tag'] = $tag_neu;
        $neu['lauf_s_tag'] = 0.0;
        $neu['starts_tag'] = 0;
        $neu['laengster_tag'] = 0.0;
        $anrechnen_ab = ($tagbeginn > $vorher && $tagbeginn <= $jetzt) ? $tagbeginn : $jetzt;
    }

    /* Zustandswechsel */
    if ($laeuft !== $alt_laeuft) {
        if ($laeuft === 1) {
            $starts[] = $jetzt;
            $neu['starts_tag']++;
            $neu['starts_gesamt']++;
            $neu['anfrage_seit'] = 0.0;   // die Anforderung ist erfuellt
        } elseif ($alt_laeuft === 1) {
            // Ein Lauf ist zu Ende - seine Dauer wird festgehalten.
            $dauer = max(0.0, $jetzt - $seit);
            $neu['letzter_lauf_s'] = $dauer;
            /* Der laengste Lauf DES TAGES zaehlt nur den Teil, der in diesem
             * Tag lag. Sonst waere laengster_tag nach einem Lauf ueber
             * Mitternacht groesser als lauf_s_tag - arithmetisch unmoeglich,
             * und beides steht nebeneinander in Loxone. */
            $imtag = $dauer;
            if ($tagbeginn > 0 && $seit < $tagbeginn) {
                $imtag = max(0.0, $jetzt - $tagbeginn);
            }
            $neu['laengster_tag'] = max($neu['laengster_tag'], $imtag);
            /* Das ANGEBROCHENE Intervall gehoert zur Tageslaufzeit. Bis
             * 0.9.7 wurde nur fortgeschrieben, wenn BEIDE Takte "laeuft"
             * sahen - der Takt, in dem die Pumpe ausgeht, zaehlte gar nicht.
             * Bei Laeufen, die zwischen zwei Takte fallen, blieb "Laufzeit
             * heute" damit auf 0, waehrend "laengster Lauf heute" eine Zahl
             * trug. Gemessen 28.08.2026: 60-s-Takt, 20 Laeufe,
             * starts_tag=20, lauf_s_tag=0. */
            if ($anrechnen_ab > 0) {
                $zu = max(0.0, min(3600.0, $jetzt - $anrechnen_ab));
                $neu['lauf_s_tag'] += $zu;
                $neu['lauf_s_gesamt'] += $zu;
            }
        }
        $neu['seit'] = $jetzt;
    }
    $neu['starts'] = pw_starts_stutzen($starts, $jetzt, 172800, pw_starts_deckel($cfg));

    if ($laeuft === 1) {
        $neu['lauf_s'] = max(0.0, $jetzt - $neu['seit']);
        // Laufzeit des Tages fortschreiben - anhand des Abstands zum letzten
        // Durchlauf, nicht anhand des Takts: ein ausgefallener Durchlauf
        // soll die Bilanz nicht verfaelschen.
        if ($anrechnen_ab > 0 && $alt_laeuft === 1) {
            $zu = max(0.0, min(3600.0, $jetzt - $anrechnen_ab));
            $neu['lauf_s_tag'] += $zu;
            $neu['lauf_s_gesamt'] += $zu;
        }
    }

    list($befund, $beiwert) = pw_befund(
        array('laeuft' => $laeuft, 'watt' => isset($mess['watt']) ? $mess['watt'] : null),
        $cfg, $neu['lauf_s'], $neu['starts'], $jetzt,
        array('seit' => $neu['anfrage_seit']));
    $neu['befund'] = $befund;
    $neu['beiwert'] = $beiwert;

    /* Sperren und Freigeben.
     *
     * Gesperrt wird, sobald ein sperrender Befund vorliegt. Aufgehoben wird
     * NICHT von selbst, wenn "quittieren" verlangt ist - genau so haelt es
     * die SCALA1 mit ihrem Alarm "maximale Laufzeit ueberschritten", und aus
     * gutem Grund: ein Trockenlauf, der sich von selbst zurueckstellt,
     * wiederholt sich, bis die Wellendichtung hin ist.
     *
     * Freigegeben wird NUR bei ausdruecklicher Entwarnung, also wenn ein
     * Messwert vorliegt UND er unauffaellig ist (PW_OK). Nicht schon dann,
     * wenn gerade kein sperrender Befund gestellt werden KANN: sonst gaebe
     * ausgerechnet der Ausfall des Zwischenzaehlers die Pumpe wieder frei.
     *
     * Bis 0.9.7 wurde 'quittung' NUR im Sperrzweig gesetzt. Wer den Haken
     * nachtraeglich entfernte, behielt das geerbte quittung=1 - und die
     * Selbstfreigabe war fuer immer blockiert, waehrend die Hilfe das
     * Gegenteil versprach. Seit 0.9.8 wird die Quittungspflicht in JEDEM
     * Durchlauf aus der Konfiguration abgeleitet. Gemessen 28.08.2026. */
    if (pw_sperrt($befund, $cfg)) {
        if (!$neu['sperre']) {
            $neu['sperre'] = 1;
            $neu['sperrgrund'] = $befund;
        }
    } elseif ($neu['sperre'] && empty($cfg['quittung_noetig']) && $befund === PW_OK) {
        $neu['sperre'] = 0;
        $neu['sperrgrund'] = '';
    }
    $neu['quittung'] = ($neu['sperre'] && !empty($cfg['quittung_noetig'])) ? 1 : 0;

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

/** Loxone meldet, dass die Pumpe angefordert wurde (optional, siehe oben). */
function pw_anfordern($stand, $an, $jetzt)
{
    $stand['anfrage_seit'] = $an ? (float) $jetzt : 0.0;
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

    // ---- Uhrsprung (0.9.8) ----
    $zukunft = array($j + 100, $j + 200, $j + 300);
    $pruef('Starts aus der Zukunft zaehlen NICHT im Fenster',
           pw_starts_im_fenster($zukunft, $j, 3600), 0);
    $pruef('und werden als Zeitsprung gemeldet', pw_zeitsprung($zukunft, $j), 3);
    $pruef('ohne Sprung meldet der Zaehler nichts', pw_zeitsprung($st, $j), 0);
    $pruef('Starts aus der Zukunft bleiben in der Liste',
           count(pw_starts_stutzen($zukunft, $j)), 3);

    // ---- Deckel der Startliste (0.9.8) ----
    $pruef('Deckel bei kleiner Grenze', pw_starts_deckel(array('starts_h' => 25)), 400);
    $pruef('Deckel waechst mit der Grenze', pw_starts_deckel(array('starts_h' => 900)), 950);

    $cfg = array('an_w' => 20, 'trocken_w' => 300, 'trocken_s' => 30, 'ueberlast_w' => 1100,
                 'dauerlauf_s' => 1800, 'starts_h' => 25, 'sperren_ein' => 1,
                 'sperre_trockenlauf' => 1, 'sperre_dauerlauf' => 0,
                 'sperre_schaltspiel' => 0, 'sperre_ueberlast' => 0,
                 'sperre_kein_anlauf' => 1, 'anlauf_s' => 0, 'quittung_noetig' => 1);

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

    // ---- Anlaufueberwachung (0.9.8) ----
    $cfg_an = array_merge($cfg, array('anlauf_s' => 20));
    list($b, $w) = pw_befund(array('laeuft' => 0, 'watt' => 1.5), $cfg_an, 0, array(), $j,
                             array('seit' => $j - 30));
    $pruef('angefordert und laeuft nicht an', $b, PW_KEIN_ANLAUF);
    list($b, $w) = pw_befund(array('laeuft' => 0, 'watt' => 1.5), $cfg_an, 0, array(), $j,
                             array('seit' => $j - 5));
    $pruef('kurz nach der Anforderung noch kein Befund', $b, PW_OK);
    list($b, $w) = pw_befund(array('laeuft' => 0, 'watt' => 1.5), $cfg, 0, array(), $j,
                             array('seit' => $j - 300));
    $pruef('ohne anlauf_s bleibt der Zweig aus', $b, PW_OK);
    list($b, $w) = pw_befund(array('laeuft' => 0, 'watt' => 1.5), $cfg_an, 0, array(), $j,
                             array('seit' => 0));
    $pruef('ohne Anforderung bleibt der Zweig aus', $b, PW_OK);

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
    /* Der abschliessende Takt zaehlt seit 0.9.8 mit: bis dahin blieb die
     * Tageslaufzeit bei 120, waehrend der laengste Lauf 150 meldete. */
    $pruef('Tageslaufzeit enthaelt den letzten Takt', $stand['lauf_s_tag'], 150);
    $pruef('laengster Lauf ist nie groesser als die Tageslaufzeit',
           $stand['laengster_tag'] <= $stand['lauf_s_tag'] ? 1 : 0, 1);
    $pruef('Gesamtlaufzeit mitgefuehrt', $stand['lauf_s_gesamt'], 150);
    $pruef('Gesamtstarts mitgefuehrt', $stand['starts_gesamt'], 1);

    /* Zwanzig Laeufe, die je zwischen zwei Takte fallen. Bis 0.9.7 ergab das
     * lauf_s_tag = 0 bei starts_tag = 20 - "Laufzeit heute: 0 s" an einem Tag
     * mit zwanzig Laeufen. Gemessen 28.08.2026. */
    $s20 = array(); $t20 = 200000.0;
    for ($i = 0; $i < 20; $i++) {
        $s20 = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $s20, $t20); $t20 += 60;
        $s20 = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11'), $cfg, $s20, $t20); $t20 += 60;
    }
    $pruef('20 kurze Laeufe: 20 Starts', $s20['starts_tag'], 20);
    $pruef('20 kurze Laeufe: Tageslaufzeit 1200 s', $s20['lauf_s_tag'], 1200);

    // Ein ausgefallener Durchlauf darf die Bilanz nicht sprengen.
    $stand2 = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $stand, $t + 10);
    $stand3x = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $stand2, $t + 100000);
    $pruef('Luecke wird auf eine Stunde gedeckelt',
           $stand3x['lauf_s_tag'] - $stand2['lauf_s_tag'], 3600);

    // Tageswechsel
    $stand3 = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-12'), $cfg, $stand, $t + 200);
    $pruef('neuer Tag: Laufzeit zurueckgesetzt', $stand3['lauf_s_tag'], 0);
    $pruef('neuer Tag: Starts zurueckgesetzt', $stand3['starts_tag'], 0);
    $pruef('neuer Tag: Startliste bleibt (sie traegt das Schaltspiel)',
           count($stand3['starts']), count($stand['starts']));
    $pruef('neuer Tag: der alte wird herausgereicht',
           isset($stand3['vortag']) ? $stand3['vortag']['tag'] : '', '2026-08-11');
    $pruef('der herausgereichte Tag traegt seine Laufzeit',
           isset($stand3['vortag']) ? $stand3['vortag']['lauf_s'] : -1, 150);
    $pruef('Gesamtlaufzeit ueberlebt den Tageswechsel', $stand3['lauf_s_gesamt'], 150);

    /* Tageswechsel MITTEN im Lauf: nur der Teil nach Mitternacht gehoert auf
     * den neuen Tag. Bis 0.9.7 wanderte das ganze Intervall hinueber -
     * gemessen 300 statt 120. */
    $sw = array(); $tw = 500000.0;    // Mitternacht liegt bei 500700
    $mitte = 500700.0;
    $sw = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11', 'tagbeginn' => 400000.0), $cfg, $sw, $tw);
    $tw += 300; $sw = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11', 'tagbeginn' => 400000.0), $cfg, $sw, $tw);
    $tw += 300; $sw = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11', 'tagbeginn' => 400000.0), $cfg, $sw, $tw);
    $pruef('vor Mitternacht: 300 s auf dem alten Tag', $sw['lauf_s_tag'], 300);
    $tw += 300;   // jetzt 500900, also 200 s nach Mitternacht
    $sw = pw_schritt(array('watt' => 600, 'tag' => '2026-08-12', 'tagbeginn' => $mitte), $cfg, $sw, $tw);
    $pruef('nach Mitternacht: nur der Anteil des neuen Tages', $sw['lauf_s_tag'], 200);
    $pruef('der alte Tag bekam seinen Anteil',
           isset($sw['vortag']) ? $sw['vortag']['lauf_s'] : -1, 400);

    /* Ein Lauf, der ueber Mitternacht geht und im NEUEN Tag endet.
     *
     * Der laengste Lauf DES TAGES darf nur den Anteil zaehlen, der in
     * diesem Tag lag - sonst waere laengster_tag groesser als lauf_s_tag,
     * und das ist fuer echte Groessen unmoeglich. Beide stehen in Loxone
     * nebeneinander.
     *
     * Diese Zeilen fehlten bis zur Eichung vom 28.08.2026: der Rueckbau
     * der Korrektur liess den Selbsttest GRUEN. Eine Korrektur ohne eine
     * Zeile, die ihren Rueckbau merkt, ist keine gepruefte Korrektur.
     * Die Dauer des LETZTEN Laufs bleibt dagegen die volle - sie gehoert
     * keinem Tag, sondern dem Lauf. */
    $M = 600000.0;                       // Mitternacht
    $sn = array();
    $sn = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11', 'tagbeginn' => $M - 86400), $cfg, $sn, $M - 600);
    $sn = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11', 'tagbeginn' => $M - 86400), $cfg, $sn, $M - 300);
    $sn = pw_schritt(array('watt' => 600, 'tag' => '2026-08-12', 'tagbeginn' => $M), $cfg, $sn, $M + 100);
    $sn = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-12', 'tagbeginn' => $M), $cfg, $sn, $M + 200);
    $pruef('Lauf ueber Mitternacht: die volle Dauer als letzter Lauf',
           $sn['letzter_lauf_s'], 500);
    $pruef('Lauf ueber Mitternacht: nur der Anteil im neuen Tag',
           $sn['laengster_tag'], 200);
    $pruef('Lauf ueber Mitternacht: Tageslaufzeit passt dazu',
           $sn['lauf_s_tag'], 200);
    $pruef('Lauf ueber Mitternacht: laengster nie groesser als die Tageslaufzeit',
           $sn['laengster_tag'] <= $sn['lauf_s_tag'] ? 1 : 0, 1);

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

    /* Die Quittungspflicht NACHTRAEGLICH abschalten. Bis 0.9.7 blieb die
     * Sperre danach fuer immer stehen, weil quittung=1 aus dem Altstand
     * geerbt und nie neu abgeleitet wurde. Die Hilfe versprach das
     * Gegenteil. Gemessen 28.08.2026. */
    $s = array('laeuft' => 1, 'seit' => 5000, 'starts' => array(), 'zeit' => 5000);
    $s = pw_schritt(array('watt' => 180, 'tag' => '2026-08-11'), $cfg, $s, 5100);
    $pruef('erst mit Quittungspflicht gesperrt', $s['quittung'], 1);
    $s = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg2, $s, 5200);
    $pruef('Haken entfernt: Quittung faellt weg', $s['quittung'], 0);
    $pruef('Haken entfernt: Sperre loest sich', $s['sperre'], 0);

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
    // Und auch ohne Quittungspflicht gibt eine Stoerung nicht frei.
    $s = array('laeuft' => 1, 'seit' => 5000, 'starts' => array(), 'zeit' => 5000);
    $s = pw_schritt(array('watt' => 180, 'tag' => '2026-08-11'), $cfg2, $s, 5100);
    $s = pw_schritt(array('watt' => null, 'tag' => '2026-08-11'), $cfg2, $s, 5200);
    $pruef('Stoerung gibt auch ohne Quittungspflicht nicht frei', $s['sperre'], 1);

    // ---- Uhrsprung im Durchlauf (0.9.8) ----
    $sz = array(); $tz = 1000000.0;
    for ($i = 0; $i < 40; $i++) {
        $sz = pw_schritt(array('watt' => 600, 'tag' => '2026-08-11'), $cfg, $sz, $tz); $tz += 60;
        $sz = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11'), $cfg, $sz, $tz); $tz += 3840;
    }
    $pruef('40 Starts im 65-Minuten-Abstand: kein Schaltspiel', $sz['befund'], PW_OK);
    $szz = pw_schritt(array('watt' => 1.5, 'tag' => '2026-08-11'), $cfg, $sz, $tz - 100000.0);
    $pruef('Uhrsprung erzeugt KEIN Schaltspiel', $szz['befund'], PW_OK);
    $pruef('Uhrsprung wird gemeldet', $szz['zeitsprung'] > 0 ? 1 : 0, 1);

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
