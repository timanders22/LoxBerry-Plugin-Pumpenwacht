# Pumpenwächter

**Überwacht eine Pumpe ohne eigene Datenschnittstelle — an ihrer
Leistungsaufnahme.** Loxone liefert die Watt-Zahl des Zwischenzählers an, das
Plugin stellt daraus einen Befund und meldet ihn zurück.

Version 0.9.9 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

---

## Wofür

Eine Kreiselpumpe verrät ihren Zustand an der Leistungsaufnahme. Aus einer
einzigen Zahl — den Watt an der Steckdose — lassen sich vier Störungen
erkennen, die sonst erst auffallen, wenn etwas kaputt ist:

* **Trockenlauf.** Eine Pumpe, die Luft statt Wasser fördert, nimmt
  **weniger** Leistung auf, nicht mehr: es ist nichts da, was beschleunigt
  werden müsste. Das ist der eine Fall, in dem eine ungewöhnlich niedrige
  Zahl die schlimmere Nachricht ist. Er zerstört die Wellendichtung innerhalb
  von Minuten und wird deshalb zuerst geprüft.
* **Überlast.** Dauerhaft zu hohe Aufnahme deutet auf Schwergang oder
  Blockade.
* **Dauerlauf.** Ein Lauf ohne Ende deutet auf ein Leck oder einen offenen
  Verbraucher.
* **Schaltspiel.** Mehr Starts je Stunde, als der Hersteller erlaubt, deuten
  auf ein Mikroleck oder ein wasserschlagendes Ventil.

Dazu kommt eine fünfte Lage, die keine Störung der Pumpe ist und trotzdem
gemeldet gehört: **es kommt kein Messwert mehr an.**

## Was es nicht tut

**Es spricht nicht mit der Pumpe.** Die Grundfos SCALA1 hat genau eine
Datenschnittstelle, nämlich Bluetooth LE für die App „Grundfos GO Remote".
Kein Ethernet, kein Modbus, kein GENIbus — die Montage- und
Betriebsanleitung führt unter „Elektrische Daten" nichts dergleichen, und der
Schaltplan kennt nur Stromeingang, Schwimmerschalter und Dreiwegeventil. Ein
offenes BLE-Protokoll ist für die SCALA nicht veröffentlicht; es gibt eines
für die Alpha3 Model B, und Grundfos schreibt dort ausdrücklich, dass schon
die Alpha2 und die Alpha3 Model A ein *anderes* Protokoll sprechen. Von der
einen auf die andere zu schließen wäre Verdacht, kein Befund.

**Es misst weder Druck noch Durchfluss.** Bis 0.9.7 nannte der Kopf des
Rechenkerns beides, und die Parameterliste führte `druck` und `fluss` mit —
ausgewertet wurde nie etwas davon. Seit 0.9.8 steht dort nur noch, was das
Plugin wirklich tut. Kommt ein Geber dazu, wird erst die Auswertung gebaut und
dann der Satz geschrieben, nicht umgekehrt.

**Es schaltet nichts.** Die Sperre ist ein Ausgang (`sperre`), den Loxone auf
die Steckdose der Pumpe legt — ob und wie, entscheidet die Loxone-Logik. Ab
Werk ist das Sperren **aus**: der Wächter misst und meldet, bis Sie ihn
scharf schalten.

**Es erfindet keine Zahlen.** Die Vorschlagswerte der Modellauswahl stammen
aus Abschnitt 8 der Montage- und Betriebsanleitung. Die 25 Starts je Stunde
sind die vom Hersteller angegebene zulässige Schalthäufigkeit, keine von mir
gewählte Grenze. Wer misst, trägt seine eigenen Zahlen ein.

## Drei Dinge, die schiefgehen und hier nicht schiefgehen sollen

**Der ausgefallene Zwischenzähler.** Kein Messwert heißt **nicht** „Pumpe
steht". Der Kern gibt in diesem Fall `-1` zurück, und der Befund heißt „keine
Meldung" — denn eine 0 sähe in Loxone aus wie eine ruhende Anlage, und
niemand sähe mehr nach. Damit das auch dann in Loxone ankommt, wenn gerade
niemand die Oberfläche aufschlägt, schreibt ein **Minutentakt** den Zustand
fort und schickt vier Lebenszeichen-Themen. Ein virtueller Eingang behält
sonst seinen letzten Wert — bei MQTT mit Retain sogar über einen Neustart des
Miniservers hinweg.

**Die Sperre, die sich von selbst löst.** Ein Trockenlauf, der sich
zurückstellt, wiederholt sich, bis die Dichtung hin ist. Deshalb bleibt die
Sperre bestehen, bis sie quittiert wird (einstellbar). Und freigegeben wird
nur bei ausdrücklicher Entwarnung: wenn ein Messwert vorliegt **und** er
unauffällig ist. Nicht schon dann, wenn gerade kein Befund gestellt werden
*kann* — sonst gäbe ausgerechnet der Ausfall des Zählers die Pumpe wieder
frei.

**Die Uhr, die zurückspringt.** Ein Raspberry ohne Echtzeituhr springt beim
ersten Zeitabgleich. Bis 0.9.7 zählten danach *alle* gespeicherten Starts als
„in der letzten Stunde", und die Pumpe wurde wegen Schaltspiel gesperrt.
Gemessen: 40 Starts im 65-Minuten-Abstand, vorher 0 im Fenster, nach einem
Rücksprung 26. Seit 0.9.8 werden Zeitstempel aus der Zukunft nicht gezählt,
bleiben aber in der Liste — und der Sprung steht im Protokoll und in der
Selbstprüfung.

## Woher der Messwert kommt — zwei Wege

Ab Werk steht die Quelle auf **Loxone**: ein Virtueller Ausgang liefert die
Watt-Zahl an den Endpunkt. Eine Aktualisierung ändert daran nichts.

Seit 0.9.9 gibt es einen zweiten Weg: **MQTT**. Hängt der Zwischenzähler
ohnehin schon am Broker — bei einem Shelly ist das der Normalfall —, hört das
Plugin dort unmittelbar mit. Der Umweg über den Miniserver entfällt damit
ganz:

```
vorher:  Zähler -> Broker -> Gateway -> Miniserver -> Virt. Ausgang
                -> HTTP -> Plugin -> Gateway -> Miniserver
jetzt:   Zähler -> Broker -> Plugin -> Gateway -> Miniserver
```

Das bringt dreierlei: der Wächter arbeitet weiter, wenn der Miniserver neu
startet; es braucht keinen Virtuellen Ausgang für den Messwert mehr; und
**Spannung, Strom und Frequenz** kommen mit — eine Unterspannung ist ein
eigener Grund, warum eine Pumpe nicht anläuft.

Dafür läuft ein Zuhörer mit (`bin/pw_dienst.php`), den der Minutentakt
nachstartet, falls er stehen sollte. Er braucht `mosquitto_sub`; das Paket
steht in `dpkg/apt`. **Eigene Zugangsdaten braucht er keine:** Benutzer und
Kennwort des Brokers liest er aus `config/system/general.json`. Damit gibt es
kein zweites Exemplar eines Kennworts, und die Sicherungsdatei enthält keines.

Sie stehen auch **nicht auf der Kommandozeile** — `/proc/<pid>/cmdline` ist
für jeden lokalen Benutzer lesbar, und dieser Prozess läuft dauernd. Sie gehen
über eine Optionsdatei mit den Rechten 0600.

## Zwei Wege nach Loxone

Für den Rückweg — die Befunde zum Miniserver — brauchen Sie ebenfalls nur
einen; der Reiter *Einbindung in Loxone* führt durch beide.

* **MQTT** ist der Regelweg. Das Plugin schiebt jede Änderung über den
  UDP-Eingang des LoxBerry-MQTT-Gateways zum Miniserver. Der Reiter erkennt,
  ob Ihr Gateway die Fassung 1 oder 2 hat, und sagt entsprechend, ob ein Abo
  von Hand einzutragen ist oder nicht.
* **HTTP** ist die Alternative ohne Gateway. Der Miniserver fragt den
  Endpunkt zyklisch ab und zerlegt eine Antwortzeile mit Suchtexten. Dafür
  gibt es eine eigene Vorlage.

Beide Vorlagen entstehen auf Knopfdruck, mit eingesetztem Wortzeichen.
Dazu kommt eine Baustein-Liste zum 1:1-Nachbauen — Schwellwertschalter auf die
Steckdose, Statusbaustein für den Befundtext, Benachrichtigung hinter einem
ODER, Quittiertaster und ein Wächter auf den Lebenszeichen-Zähler.

## Der Endpunkt

Er liegt im unangemeldeten Bereich und ist deshalb durch ein Wortzeichen
geschützt (`hash_equals`, fail closed).

| Aufruf | was er tut |
|---|---|
| `?token=…&aktion=wert&watt=<v>` | Messwert anliefern, rechnen, speichern, melden |
| `?token=…&aktion=stand` | alle Werte als Textzeilen |
| `?token=…&aktion=zeile` | dieselben Werte in *einer* Zeile (Befehlserkennung) |
| `?token=…&aktion=json` | dasselbe als JSON |
| `?token=…&aktion=quittieren` | Sperre von Hand aufheben |
| `?token=…&aktion=anforderung&an=1|0` | Pumpe angefordert (optional) |
| `?token=…&aktion=selftest` | nur das Wortzeichen prüfen, löst **nichts** aus |

Auch die Wertlieferung ist tokenpflichtig, obwohl sie „nur" einen Messwert
trägt: wer beliebige Watt-Zahlen einliefern könnte, könnte eine Sperre ohne
Quittungspflicht durch erfundene Normalwerte aufheben.

## Prüfstand

* `php webfrontend/html/pw_regel.php` — 79 Fälle des Rechenkerns, ohne
  Anlage und ohne Netz. Der Kern kennt kein Netz, keine Datei und keine Uhr,
  die ihm nicht übergeben wurde; deshalb lässt er sich vollständig ohne Pumpe
  prüfen.
* `php bin/pw_takt.php --probe` — den Zustand rechnen und zeigen, ohne etwas
  zu schreiben und ohne etwas zu senden.
* `php bin/pw_takt.php --einmal` — ein Durchlauf im Vordergrund, mit Ausgabe.
* `php bin/pw_dienst.php --selbsttest` — 20 Fälle gegen die **echten**
  Shelly-Meldungen, die am 28.08.2026 aus `mosquitto_sub` kamen: welche
  Nachricht einen Messwert trägt, welche nicht, was bei kaputtem JSON
  passiert, ob der Bauteilname (`switch:0`, `pm1:0`, `em:0`) etwas ändert.
  **Ohne Broker, ohne Zugangsdaten, ohne dass die Pumpe laufen muss** — und
  ohne zu schreiben. Läuft auch, wenn die Quelle noch auf Loxone steht:
  so lässt sich der MQTT-Weg prüfen, **bevor** man auf ihn umstellt.
  Am Ende steht ausdrücklich, was dabei *nicht* geprüft ist.
* `php bin/pw_dienst.php --probe` — zwei Minuten am Broker mithören und
  zeigen, was ankommt: welche Nachricht einen Messwert trägt und welche
  nicht. Schreibt nichts und sendet nichts. Das ist der Schritt, den der
  Selbsttest **nicht** ersetzen kann — er erreicht den Broker.
* Reiter *Test*, **Selbstprüfung** — bis zu 26 Zeilen mit Haken, Kreuz und Strich.
  Ein Strich ist ausdrücklich *kein* Haken: er heißt „konnte hier nicht
  gemessen werden".
* Reiter *Bilanz* — misst, ob Ihr virtueller Ausgang zyklisch oder nur bei
  Änderung sendet. Das steht in keiner Einstellung von Loxone Config.

## Ordner

```
bin/            der Minutentakt (pw_takt.php) und der MQTT-Zuhörer (pw_dienst.php)
cron/           cron.01min — Wächter für den Zuhörer, danach der Takt
dpkg/apt        mosquitto-clients (nur für den MQTT-Weg)
templates/      Sprachdateien und Hilfe
webfrontend/    html = Rechenkern, Bibliothek und Endpunkt; htmlauth = Oberfläche
uninstall/      entfernt die Zweitschrift — sie trägt das Aktionstoken
```

Das **Veröffentlichen** braucht kein zusätzliches Paket: es läuft über den
UDP-Eingang des Gateways, und dafür genügt `stream_socket_client()` aus dem
PHP-Kern. Bis 0.9.7 stand dort `socket_create()`; ohne die Erweiterung
`sockets` starb der Endpunkt mit HTTP 500 und null Byte Ausgabe. Ein
virtueller Ausgang liest die Antwort nicht — der Ausfall wäre still gewesen.

`mosquitto-clients` wird **nur** für den MQTT-Weg gebraucht. Fehlt das Paket
und steht die Quelle trotzdem auf MQTT, sagt es der Reiter *Test* in einer
eigenen Zeile, statt dass der Zuhörer stumm nicht anläuft.

## Wo die Einstellungen liegen

```
config/plugins/<ordner>/pumpenwacht.json   die Konfiguration (0600)
config/plugins/<ordner>.backup.json        die Zweitschrift (0600)
data/plugins/<ordner>/stand.json           der laufende Zustand
data/plugins/<ordner>/tage.json            die Tagesbilanz, 60 Tage
log/plugins/<ordner>/pumpenwacht.log       das Protokoll
```

Die Zweitschrift liegt **neben** dem Konfigordner, nicht darin: LoxBerry
entfernt `config/plugins/<ordner>/` bei Deinstallation und Neuinstallation,
und eine Sicherung im Ordner stürbe genau in dem Fall mit, für den es sie
gibt. Sie trägt das Aktionstoken und wird deshalb von `uninstall/uninstall`
gelöscht.

Die Sicherungsdatei aus dem Reiter *Einstellungen* enthält dasselbe Token.
Ohne es stünden nach dem Zurückspielen alle Felder richtig, und der
Miniserver käme trotzdem nicht an das Plugin — deshalb ist es darin, und
deshalb ist die Datei wie ein Kennwort zu behandeln. Ist sie einmal aus der
Hand gegangen, entwertet der Knopf *Neues Aktionstoken erzeugen* sie.

## Lizenz

MIT — siehe `LICENSE`.
