<?php
/**
 * Pumpenwaechter - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Protokoll
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
require_once dirname(__DIR__) . '/html/pw_lib.php';

$pw_saved = false; $pw_err = ''; $pw_note = '';

/* Reiter: EINE Quelle (Hausstandard-Vorlage, drei Stellen ziehen mit). */
$pw_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,                    // Eigenname, wird nicht uebersetzt
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
$pw_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($pw_reiter))) . ')$/';

$pw_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($pw_muster, (string) $_POST['activetab'])) {
    $pw_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($pw_muster, 'tab-' . (string) $_GET['form'])) {
    $pw_tab = 'tab-' . (string) $_GET['form'];
}

$pw_cfg = pw_config();

/* Wortzeichen beim ersten Oeffnen erzeugen, danach nie wieder anfassen. */
if (empty($pw_cfg['aktionstoken'])) {
    $pw_cfg['aktionstoken'] = pw_token_erzeugen();
    pw_config_speichern($pw_cfg);
}

/* ---------- Loxone-Vorlagen herunterladen (Hausstandard) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    list($pw_vname, $pw_vinhalt) = ($_POST['vorlage'] === 'vo') ? pw_vorlage_vo() : pw_vorlage_vi();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $pw_vname . '"');
    echo $pw_vinhalt;
    exit;
}

/* ---------- Einstellungen speichern ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['speichern'])) {
    $pw_neu = pw_config();
    $pw_modelle = pw_modelle();
    $pw_m = isset($_POST['modell']) ? (string) $_POST['modell'] : 'frei';
    $pw_neu['modell'] = isset($pw_modelle[$pw_m]) ? $pw_m : 'frei';
    foreach (array(
        'an_w'        => array(1, 5000),
        'trocken_w'   => array(0, 5000),
        'trocken_s'   => array(5, 3600),
        'ueberlast_w' => array(0, 10000),
        'dauerlauf_s' => array(0, 86400),
        'starts_h'    => array(0, 1000),
        'stale_s'     => array(30, 86400),
    ) as $pw_f => $pw_g) {
        $pw_w = isset($_POST[$pw_f]) ? str_replace(',', '.', trim((string) $_POST[$pw_f])) : '';
        if (!is_numeric($pw_w)) {
            $pw_err = sprintf(pw_t('EINST.FEHLER_ZAHL'), pw_t('EINST.L_' . strtoupper($pw_f)));
            continue;
        }
        if ((float) $pw_w < $pw_g[0] || (float) $pw_w > $pw_g[1]) {
            $pw_err = sprintf(pw_t('EINST.FEHLER_BEREICH'), pw_t('EINST.L_' . strtoupper($pw_f)), $pw_g[0], $pw_g[1]);
            continue;
        }
        $pw_neu[$pw_f] = (float) $pw_w;
    }
    foreach (array('sperren_ein', 'sperre_trockenlauf', 'sperre_dauerlauf',
                   'sperre_schaltspiel', 'sperre_ueberlast', 'quittung_noetig') as $pw_h) {
        $pw_neu[$pw_h] = isset($_POST[$pw_h]) ? 1 : 0;
    }
    if ($pw_err === '') {
        if (pw_config_speichern($pw_neu)) { $pw_saved = true; $pw_cfg = pw_config(); }
        else { $pw_err = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config'])); }
    }
    $pw_tab = 'tab-settings';
}

/* ---------- MQTT speichern (eigener Reiter, eigenes Formular) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mqtt_save'])) {
    $pw_neu = pw_config();
    $pw_neu['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $pw_neu['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'pumpe')) ?: 'pumpe';
    if (pw_config_speichern($pw_neu)) { $pw_saved = true; $pw_cfg = pw_config(); }
    else { $pw_err = sprintf(pw_t('EINST.FEHLER_SPEICHERN'), pw_e(pw_paths()['config'])); }
    $pw_tab = 'tab-mqtt';
}

/* ---------- Test-Aktionen ---------- */
$pw_testausgabe = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selbsttest'])) {
    ob_start();
    list($pw_tn, $pw_tf) = pw_selbsttest(true);
    $pw_testausgabe = ob_get_clean();
    $pw_note = ($pw_tf === 0) ? sprintf(pw_t('TEST.SELBSTTEST_OK'), $pw_tn)
                              : sprintf(pw_t('TEST.SELBSTTEST_FEHL'), $pw_tf, $pw_tn);
    $pw_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['testwert'])) {
    $pw_roh = str_replace(',', '.', trim((string) (isset($_POST['watt']) ? $_POST['watt'] : '')));
    if (!is_numeric($pw_roh)) {
        $pw_err = pw_t('TEST.FEHLER_WATT');
    } else {
        $pw_alt = pw_stand();
        $pw_jetzt = time();
        $pw_neu_stand = pw_schritt(array('watt' => (float) $pw_roh, 'tag' => date('Y-m-d', $pw_jetzt)), $pw_cfg, $pw_alt, $pw_jetzt);
        $pw_neu_stand['watt'] = (float) $pw_roh;
        if (pw_stand_speichern($pw_neu_stand)) {
            pw_mqtt_publish(pw_felder($pw_neu_stand, $pw_cfg, $pw_jetzt));
            $pw_note = sprintf(pw_t('TEST.WERT_OK'), pw_e($pw_roh), pw_e($pw_neu_stand['befund']));
        } else {
            $pw_err = pw_t('TEST.FEHLER_SPEICHERN');
        }
    }
    $pw_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quittieren'])) {
    $pw_neu_stand = pw_quittieren(pw_stand());
    if (pw_stand_speichern($pw_neu_stand)) {
        pw_log('Sperre quittiert (Oberflaeche).');
        pw_mqtt_publish(pw_felder($pw_neu_stand, $pw_cfg));
        $pw_note = pw_t('TEST.QUITTIERT');
    } else {
        $pw_err = pw_t('TEST.FEHLER_SPEICHERN');
    }
    $pw_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_leeren'])) {
    @file_put_contents(pw_paths()['log'], '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n");
    $pw_tab = 'tab-log';
}

/* ---------- Laden ---------- */
$pw_stand = pw_stand();
$pw_felder = pw_felder($pw_stand, $pw_cfg);
$pw_m = pw_mqtt_zustand();
$pw_p = pw_paths();
$pw_host = pw_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '<loxberry>');
$pw_topic = trim((string) $pw_cfg['mqtt_topic']) !== '' ? $pw_cfg['mqtt_topic'] : 'pumpe';
$pw_endpunkt = 'http://' . $pw_host . '/plugins/' . pw_e($pw_p['plugin']) . '/index.php?token=' . pw_e($pw_cfg['aktionstoken']);
$pw_modelle = pw_modelle();
$pw_befunde = array(0 => 'BEFUND.OK', 1 => 'BEFUND.SCHALTSPIEL', 2 => 'BEFUND.DAUERLAUF',
                    3 => 'BEFUND.TROCKENLAUF', 4 => 'BEFUND.UEBERLAST', 5 => 'BEFUND.STILL');

$pw_frame = class_exists('LBWeb', false);
if ($pw_frame) { LBWeb::lbheader(pw_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html'); }
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
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
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
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
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
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
</style>
<div class="sm-wrap">
<h1 style="font-size:1.4em;margin:10px 0 0;"><?= pw_e(pw_t('ALLG.TITEL')) ?></h1>

<?php if (pw_sprache_fehlt()) { ?><div class="sm-warnung"><?= pw_t('ALLG.SPRACHE_FEHLT') ?></div><?php } ?>
<?php if ($pw_saved) { ?><div class="sm-hinweis"><?= pw_t('ALLG.GESPEICHERT') ?></div><?php } ?>
<?php if ($pw_note !== '') { ?><div class="sm-hinweis"><?= $pw_note ?></div><?php } ?>
<?php if ($pw_err !== '') { ?><div class="sm-warnung"><b><?= pw_t('ALLG.FEHLER') ?></b> <?= $pw_err ?></div><?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= pw_t('KOPF.PUMPE') ?><b class="<?= $pw_felder['laeuft'] === 1 ? 'sm-an' : ($pw_felder['laeuft'] === 0 ? '' : 'sm-aus') ?>"><?=
      $pw_felder['laeuft'] === 1 ? pw_t('KOPF.LAEUFT') : ($pw_felder['laeuft'] === 0 ? pw_t('KOPF.STEHT') : pw_t('KOPF.UNBEKANNT')) ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.BEFUND') ?><b><?= pw_e(pw_t($pw_befunde[$pw_felder['befund']])) ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.SPERRE') ?><b class="<?= $pw_felder['sperre'] ? 'sm-aus' : 'sm-an' ?>"><?= $pw_felder['sperre'] ? pw_t('ALLG.EIN') : pw_t('ALLG.AUS') ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.LEISTUNG') ?><b><?= $pw_felder['watt'] >= 0 ? pw_e($pw_felder['watt']) . ' W' : '&mdash;' ?></b></div>
  <div class="sm-kachel"><?= pw_t('KOPF.STARTS_HEUTE') ?><b><?= (int) $pw_felder['starts_tag'] ?></b></div>
</div>

<div class="sm-tabs">
<?php foreach ($pw_reiter as $pw_k => $pw_schl): $pw_id = 'tab-' . $pw_k; ?>
	<a class="sm-tab<?= $pw_tab === $pw_id ? ' sm-active' : '' ?>" data-ziel="<?= pw_e($pw_id) ?>"
	   href="index.php?form=<?= pw_e($pw_k) ?>"><?= $pw_schl === null ? 'MQTT' : pw_e(pw_t($pw_schl)) ?></a>
<?php endforeach; ?>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= pw_e(pw_t('EINST.H_MODELL')) ?></h2>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_MODELL')) ?></label>
  <select data-role="none" name="modell" id="pw_modell" onchange="pwModell()">
<?php foreach ($pw_modelle as $pw_mk => $pw_mv) { ?>
    <option value="<?= pw_e($pw_mk) ?>" data-p1="<?= (int) $pw_mv['p1'] ?>" data-starts="<?= (int) $pw_mv['starts_h'] ?>"<?= $pw_cfg['modell'] === $pw_mk ? ' selected' : '' ?>><?= pw_e(pw_t($pw_mv['name'])) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= pw_t('EINST.H_MODELL_TEXT') ?></div>
</div>

<h2><?= pw_e(pw_t('EINST.H_SCHWELLEN')) ?></h2>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_AN_W')) ?></label>
  <input data-role="none" type="text" name="an_w" value="<?= pw_e($pw_cfg['an_w']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_AN_W') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_TROCKEN_W')) ?></label>
  <input data-role="none" type="text" name="trocken_w" value="<?= pw_e($pw_cfg['trocken_w']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_TROCKEN_W') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_TROCKEN_S')) ?></label>
  <input data-role="none" type="text" name="trocken_s" value="<?= pw_e($pw_cfg['trocken_s']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_TROCKEN_S') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_UEBERLAST_W')) ?></label>
  <input data-role="none" type="text" name="ueberlast_w" value="<?= pw_e($pw_cfg['ueberlast_w']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_UEBERLAST_W') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_DAUERLAUF_S')) ?></label>
  <input data-role="none" type="text" name="dauerlauf_s" value="<?= pw_e($pw_cfg['dauerlauf_s']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_DAUERLAUF_S') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_STARTS_H')) ?></label>
  <input data-role="none" type="text" name="starts_h" value="<?= pw_e($pw_cfg['starts_h']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_STARTS_H') ?></div>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('EINST.L_STALE_S')) ?></label>
  <input data-role="none" type="text" name="stale_s" value="<?= pw_e($pw_cfg['stale_s']) ?>">
  <div class="sm-hilfe"><?= pw_t('EINST.H_STALE_S') ?></div>
</div>

<h2><?= pw_e(pw_t('EINST.H_SPERREN')) ?></h2>
<div class="sm-warnung"><?= pw_t('EINST.SPERREN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="sperren_ein" value="1" <?= !empty($pw_cfg['sperren_ein']) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_SPERREN_EIN')) ?>
  </label>
</div>
<?php foreach (array('trockenlauf', 'dauerlauf', 'schaltspiel', 'ueberlast') as $pw_b) { ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="sperre_<?= $pw_b ?>" value="1" <?= !empty($pw_cfg['sperre_' . $pw_b]) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_SPERRE_' . strtoupper($pw_b))) ?>
  </label>
</div>
<?php } ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="quittung_noetig" value="1" <?= !empty($pw_cfg['quittung_noetig']) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('EINST.L_QUITTUNG')) ?>
  </label>
  <div class="sm-hilfe"><?= pw_t('EINST.H_QUITTUNG') ?></div>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= pw_e(pw_t('ALLG.SPEICHERN')) ?></button>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span></div>
</form>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<?php if ($pw_m['gefunden'] && !$pw_m['autostart']) { ?><div class="sm-warnung"><b>MQTT:</b> <?= pw_t('MQTT.W_AUTOSTART') ?></div><?php } ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($pw_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= pw_e(pw_t('MQTT.L_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label><?= pw_e(pw_t('MQTT.L_TOPIC')) ?></label>
  <input data-role="none" type="text" name="mqtt_topic" value="<?= pw_e($pw_cfg['mqtt_topic']) ?>" placeholder="pumpe">
  <div class="sm-hilfe"><?= pw_t('MQTT.H_TOPIC') ?></div>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= pw_e(pw_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<h2><?= pw_e(pw_t('MQTT.H_ZUSTAND')) ?></h2>
<table class="sm-tbl">
<tr><td><?= pw_e(pw_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $pw_m['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $pw_m['autostart'] ? pw_e(pw_t('ALLG.EIN')) : pw_e(pw_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= pw_e(pw_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $pw_m['udpport'] ?></span></td></tr>
</table>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= pw_e(pw_t('LOX.H')) ?></h2>

<div class="sm-step"><b><?= pw_t('LOX.S1_TITEL') ?></b><br><br>
<?= pw_t('LOX.S1_TEXT') ?>
<pre class="sm-pre"><?= $pw_endpunkt ?>&amp;aktion=wert&amp;watt=&lt;v&gt;</pre>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S2_TITEL') ?></b><br><br>
<?= pw_t('LOX.S2_TEXT') ?>
<pre class="sm-pre"><?= pw_e($pw_topic) ?>/#</pre>
</div>

<div class="sm-step"><b><?= pw_t('LOX.S3_TITEL') ?></b><br><br>
<?= pw_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= pw_t('LOX.SP_TITEL') ?></th><th style="width:16%"><?= pw_t('LOX.SP_EINHEIT') ?></th><th style="width:38%"><?= pw_t('LOX.SP_BEDEUTUNG') ?></th></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_laeuft</td><td>&lt;v.0&gt;</td><td><?= pw_t('LOX.B_LAEUFT') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_befund</td><td>&lt;v.0&gt;</td><td><?= pw_t('LOX.B_BEFUND') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_sperre</td><td>&lt;v.0&gt;</td><td><?= pw_t('LOX.B_SPERRE') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_quittung</td><td>&lt;v.0&gt;</td><td><?= pw_t('LOX.B_QUITTUNG') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_watt</td><td>&lt;v.1&gt;&nbsp;W</td><td><?= pw_t('LOX.B_WATT') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_lauf_s</td><td>&lt;v.0&gt;&nbsp;s</td><td><?= pw_t('LOX.B_LAUF_S') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_letzter_lauf_s</td><td>&lt;v.0&gt;&nbsp;s</td><td><?= pw_t('LOX.B_LETZTER') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_lauf_s_tag</td><td>&lt;v.0&gt;&nbsp;s</td><td><?= pw_t('LOX.B_TAG') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_starts_tag</td><td>&lt;v.0&gt;</td><td><?= pw_t('LOX.B_STARTS') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_laengster_tag</td><td>&lt;v.0&gt;&nbsp;s</td><td><?= pw_t('LOX.B_LAENGSTER') ?></td></tr>
<tr><td class="sm-mono"><?= pw_e($pw_topic) ?>_alter</td><td>&lt;v.0&gt;&nbsp;s</td><td><?= pw_t('LOX.B_ALTER') ?></td></tr>
</table>
<div class="sm-hilfe"><?= pw_t('LOX.BEFUND_LEGENDE') ?></div>
</div>

<h2><?= pw_t('LOX.H_VORLAGE') ?></h2>
<div class="sm-hinweis"><?= pw_t('LOX.H_VORLAGE_TEXT') ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="vorlage" value="vi">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= pw_t('LOX.K_VORLAGE_VI') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="vorlage" value="vo">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= pw_t('LOX.K_VORLAGE_VO') ?></button>
</form>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= pw_t('LEGENDE.TECHNIK') ?></span></div>

<div class="sm-step"><b><?= pw_t('LOX.S4_TITEL') ?></b><br><br>
<?= pw_t('LOX.S4_TEXT') ?>
<pre class="sm-pre"><?= $pw_endpunkt ?>&amp;aktion=quittieren</pre>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= pw_e(pw_t('REITER.TEST')) ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= pw_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= pw_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span>
</div>

<h3><?= pw_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= $pw_endpunkt ?>&amp;aktion=stand" target="_blank"><?= pw_t('TEST.K_STAND') ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= $pw_endpunkt ?>&amp;aktion=json" target="_blank"><?= pw_t('TEST.K_JSON') ?></a>
</div>

<h3><?= pw_t('TEST.H_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= pw_t('TEST.K_SELBSTTEST') ?></button>
</form>
</div>

<h3><?= pw_t('TEST.H_AKTION') ?></h3>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;display:flex;gap:10px;align-items:center;">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <input data-role="none" type="text" name="watt" placeholder="z. B. 600" style="max-width:140px;">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="testwert" value="1"><?= pw_t('TEST.K_WERT') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="quittieren" value="1"><?= pw_t('TEST.K_QUITTIEREN') ?></button>
</form>
</div>
<div class="sm-hilfe"><?= pw_t('TEST.HINWEIS') ?></div>

<?php if ($pw_testausgabe !== '') { ?>
<pre class="sm-pre"><?= pw_e($pw_testausgabe) ?></pre>
<?php } ?>
</div>

<!-- ================= Reiter: Protokoll ================= -->
<div class="sm-seite<?= $pw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= pw_e(pw_t('REITER.LOG')) ?></h2>
<div class="sm-hilfe"><?= pw_t('LOG.TEXT') ?> <span class="sm-mono"><?= pw_e($pw_p['log']) ?></span></div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <div class="sm-knopfreihe">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= pw_t('LOG.K_LEEREN') ?></button>
  </div>
  <div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= pw_t('LEGENDE.AKTION') ?></span></div>
</form>
<pre class="sm-pre"><?php foreach (pw_log_ende() as $pw_z) { echo pw_e($pw_z) . "\n"; } ?></pre>
</div>

</div><!-- sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
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
