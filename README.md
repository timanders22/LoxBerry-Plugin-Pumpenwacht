# Pumpenwächter

**Überwacht Pumpen ohne eigene Datenschnittstelle — an ihrer
Leistungsaufnahme.** Ein Zwischenzähler liefert die Watt-Zahl, das Plugin
stellt daraus einen Befund und meldet ihn nach Loxone. Seit 1.0.0 für
**mehrere Pumpen nebeneinander**, jede mit eigenen Schwellen, eigenem
MQTT-Thema und eigenem Zustand.

Version 1.0.0 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

---

## Neu in 1.0.0 — mehrere Pumpen

Bis 0.9.14 überwachte das Plugin **genau eine** Pumpe. Die Fassung 1.0.0
führt beliebig viele: in diesem Haus ein **Hauswasserwerk** (Grundfos SCALA1)
und eine **Sumpfpumpe** im Pumpensumpf, die grundverschieden zu behandeln
sind.

### Die Pumpenart entscheidet, ob gesperrt werden darf

| Art | sperrt? | typische Frist |
|---|---|---|
| **Hauswasserwerk** (Druckerhöhung) | **ja** | keine Ruhefrist |
| **Entwässerungspumpe** (Sumpf, Drainage) | **nie** | 48 h ohne Lauf → Meldung |

Eine trocken laufende Druckpumpe zerstört ihre Gleitringdichtung in Minuten —
sie *muss* abschaltbar sein. Eine gesperrte Sumpfpumpe dagegen bedeutet
Wasser im Keller. Deshalb ist das Sperrverbot bei der Entwässerungspumpe
**hart im Rechenkern** verankert und nicht nur ein Haken in der Oberfläche:
`pw_sperrt()` fragt zuerst die Art und gibt bei `entwaesserung` unabhängig
von jeder Einstellung `false` zurück.

Dafür bekommt sie den Befund, den ein Hauswasserwerk nicht braucht:
**„seit langem kein Lauf"**. Er entsteht gerade daraus, dass *nichts*
geschieht — eine Sumpfpumpe, die seit 48 Stunden nicht angesprungen ist, hat
einen gefallenen FI, einen klemmenden Schwimmer oder ein blockiertes Laufrad;
man merkt es sonst erst, wenn der Keller voll ist. Er meldet **nur**, wenn
schon einmal ein Lauf gesehen wurde: eine frische Installation soll nicht
Alarm geben, bevor sie etwas gemessen hat.

### Was je Pumpe getrennt geführt wird

Konfiguration, Zustand, Tagesbilanz, MQTT-Themenpräfix, Quell-Thema und die
Adresse für Loxone. Zwei Pumpen zählen **nie** auf denselben Zähler; der
Reiter *Test* prüft ausdrücklich nach, ob sich zwei Themenpräfixe
verschlucken könnten.

### Wie die Zuordnung funktioniert

Der MQTT-Zuhörer abonniert alle Quell-Themen in **einem** Prozess
(`mosquitto_sub` nimmt mehrere `-t`) und ordnet jede eintreffende Zeile nach
den **MQTT-Regeln** zu — abschnittweise, `+` deckt genau einen Abschnitt, `#`
den Rest. Ein Zeichenkettenvergleich wäre hier falsch: in diesem Haus stehen
`shelly1pmg4/#` und `shelly1pmg4-Pumpensumpf/#` nebeneinander, und ein
`strpos()` schlüge die Zeilen der zweiten der ersten zu. Passen mehrere
Filter, gewinnt der **spezifischere**. Eine Zeile, die zu keiner Pumpe
gehört, wird verworfen **und gezählt** — der Reiter *Test* zeigt die Zahl.

### Die Adresse für Loxone

Sie trägt jetzt die Kennung der Pumpe:

```
?token=…&aktion=wert&watt=<v>&pumpe=sumpf
```

**Ohne `&pumpe=` ist es die erste Pumpe — genau wie bisher.** Das ist
Absicht: in einem Miniserver stehen die Adressen in virtuellen Ausgängen,
Bausteinen und Formeln, und ein Update, das sie alle ungültig macht, hält die
Anlage an. Eine **unbekannte** Kennung wird dagegen abgewiesen
(`400 GRUND=PUMPE`) und nicht stillschweigend auf die erste umgebogen: hier
sitzt niemand davor, und ein Tippfehler in einer einzigen Adresse legte sonst
monatelang die Messwerte der einen Pumpe bei der anderen ab.

### Die Vorlagen

| Vorlage | Umfang |
|---|---|
| **MQTT** (`VI_pumpenwaechter.xml`) | **alle** Pumpen in einer Datei |
| **Ausgang** (`VQ_pumpenwaechter.xml`) | **alle** Pumpen, drei Befehle je Pumpe |
| **HTTP** (`VI_pumpenwaechter_http_<id>.xml`) | **je Pumpe eine** |

Die HTTP-Vorlage kann nicht anders: ein `VirtualInHttp` hat genau **eine**
Abfrageadresse, und die steht an der Wurzel. Mehrere Pumpen brauchen mehrere
Wurzeln, und XML hat eine.

### Bei einer Pumpe ändert sich nichts

Kein Namenszusatz in den Vorlagen, keine Pumpenwahl mit einem Knopf, kein
anderer Dateiname, dieselben Adressen. Der Zusatz erscheint erst, wenn es
etwas zu unterscheiden gibt. Eine vorhandene Konfiguration aus 0.9.14 wandert
beim ersten Lesen in die neue Form; eine Sicherung aus 0.9.14 lässt sich
weiterhin zurückspielen.

### Behoben, gefunden beim Umbau

* Die **Loxone-Vorlage** bekam nach der Wanderung die volle Konfiguration
  statt der flachen Sicht einer Pumpe — und schrieb damit **Werksvorgaben**
  in eine Datei, die der Anwender in Config einliest.
* Eine **Sicherung aus 0.9.14 mit einem einzigen Schlüssel** setzte alles
  Übrige auf Werksvorgabe zurück: Modell, Trockenlaufschwelle und Sperren
  fielen still zurück, und die Meldung sprach von „23 von 27 übernommen".
* Ein **fremder Schlüssel** im oberen Teil einer Sicherung wurde von der
  Wanderung weggeworfen, bevor die Prüfung ihn sehen konnte — die Datei galt
  als angenommen.
* `lauf_s_vortag` und `starts_vortag` meldeten für **jede** Pumpe die
  Tagesbilanz der ersten. Eine plausible Zahl am falschen Ort.
* Der **Minutentakt** lief nur für die erste Pumpe. Damit fehlten der zweiten
  Veralten-Erkennung, Ruhefrist, Lebenszeichen und Tagesbuchung.
* Die **Selbstprüfung** las den Zustand der ersten Pumpe, während sie die
  gewählte prüfte.

## Neu in 0.9.14

- **Nur Schreibweise.** Die Sprachdateien führten für sichtbare Zeichen
  noch HTML-Entitäten (`&mdash;`, `&auml;`, `&bdquo;`); jetzt stehen dort die
  Zeichen selbst — in dieser Fassung **10** Stück. Das ist der Hausbeschluss
  vom 14.08.2026: mit direkten Zeichen darf `htmlspecialchars` folgenlos
  zweimal laufen, und die Doppelmaskierung fällt als Fehlerklasse weg.
  `&nbsp;` und `&shy;` bleiben Entität (unsichtbares Zeichen im Quelltext ist
  eine Wartungsfalle), ebenso die bedeutungstragenden `&amp;`, `&lt;`, `&gt;`,
  `&quot;` und `&apos;`. **Am Verhalten ändert sich nichts.**

## Neu in 0.9.13

### Die Fehlerausgabe des Zuhörers ging ins Protokoll — und hielt es fest

`cron/cron.01min` startete den MQTT-Zuhörer mit
`nohup php … >/dev/null 2>>"$LOG"` — und `$LOG` ist `pumpenwacht.log`, also
genau das Protokoll, das die Oberfläche anzeigt. Der Zuhörer läuft danach
stunden- bis tagelang und hält diesen Deskriptor die ganze Zeit. Verschwindet
die Datei darunter — `log/plugins` liegt auf einer Ramdisk, und LoxBerrys
`log_maint` räumt zusätzlich auf —, schreibt er in einen gelöschten Inode:
keine Fehlermeldung, keine Zeile, nichts.

Am Gerät gemessen (06.09.2026): PID 924218 hielt `pumpenwacht.log` auf
Deskriptor 2 offen, auf der gelöschten Datei. Sieben Dienste dieser Anlage
taten dasselbe im selben Moment.

Die Fehlerausgabe des Zuhörers geht jetzt nach `pumpenwacht_start.log`, das
vor jedem echten Start geleert wird — dorthin kommt nur, wer wirklich startet,
also trägt die Datei genau einen Lauf. **Der Minutentakt behält das
Protokoll:** `pw_takt.php` läuft eine Sekunde und ist wieder weg, hält also
nichts fest, und was er meldet, gehört ins Protokoll.

Im Sandkasten am Gerät geprüft, in beide Richtungen: mit der alten Zeile steht
die Ausgabe des Zuhörers in `pumpenwacht.log`, mit der neuen in
`pumpenwacht_start.log`. **Am PHP-Programm ist nichts geändert** — es schreibt
sein Protokoll Zeile für Zeile und war nie betroffen.

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

Jeder dieser Aufrufe nimmt zusätzlich `&pumpe=<kennung>`. **Ohne die Angabe
ist es die erste Pumpe** — Adressen aus 0.9.14 tun damit unverändert dasselbe.
Eine unbekannte Kennung wird mit `400 FEHLER;OK=0;GRUND=PUMPE` abgewiesen.

Auch die Wertlieferung ist tokenpflichtig, obwohl sie „nur" einen Messwert
trägt: wer beliebige Watt-Zahlen einliefern könnte, könnte eine Sperre ohne
Quittungspflicht durch erfundene Normalwerte aufheben.

## Prüfstand

* `php webfrontend/html/pw_regel.php` — 119 Fälle des Rechenkerns, ohne
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
* Reiter *Test*, **Selbstprüfung** — bis zu 30 Zeilen mit Haken, Kreuz und
  Strich, für die **gewählte** Pumpe; darüber bei mehreren Pumpen eine
  Übersicht über **alle**.
  Zwei der Zeilen gelten allen Pumpen gemeinsam: ob sich zwei MQTT-Themen
  verschlucken, und ob jede eintreffende Zeile einer Pumpe gehört.
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

## Fassung 0.9.12 — die Überschrift greift der Gateway-Fassung nicht mehr vor

Der Reiter *Einbindung in Loxone* wählt die Überschrift von Schritt 2 nach der
erkannten Gateway-Fassung. Bei **V1** heißt sie „Abo im MQTT-Gateway
eintragen", bei **V2** „Datenpunkte in den Abonnements anhaken" — beides
richtig. Die dritte Lage, *Fassung nicht lesbar*, fiel bis 0.9.11 auf die
V1-Überschrift zurück.

Der Text darunter nennt in diesem Fall ausdrücklich **beide** Möglichkeiten
(„Fassung 1: ohne den Eintrag … / Fassung 2: einzutragen ist nichts") — die
Überschrift darüber gab aber schon eine Anweisung. Für jede Anlage mit
Gateway V2, deren Fassung sich nicht lesen ließ, stand dort das Falsche, und
zwar in der größeren Schrift.

Jetzt gibt es drei Überschriften statt zwei. **Eine neutrale für alle Lagen
wäre die schlechtere Antwort gewesen:** ist die Fassung bekannt, darf und soll
der Reiter deutlich sein. Neutral wird nur der Fall, in dem wirklich nichts
bekannt ist.

Dazu sagt der Text jetzt, **wo** die Zeile hingehört — und dass danach zu
speichern ist. Zur Beschriftung des Abschnitts wird nichts behauptet: im
Bestand stehen neun „Subscriptions" gegen vier „Abonnements", und das ist
eine Angabe, kein Messwert. Belegt ist aus dem LoxBerry-Kern nur, dass das
Formular `subscriptions` heißt und die Einstellungen in
`config/system/subscriptions.json` liegen; das sichtbare Etikett hängt an der
Sprache der Oberfläche. Der Satz nennt deshalb **beide** Formen — wer nach
einem Wort sucht, das seine Oberfläche nicht kennt, sucht länger als einer,
der zwei liest.

Hinterlegt als `pw12_abo.py` im Prüfstand: alle drei Lagen einzeln
hergestellt, je Lage die richtige Überschrift verlangt, im Unbekannt-Fall
ausdrücklich **keine Anweisung** — und geeicht, das heißt der alte Zweizeiler
wird zurückgebaut und die Prüfung muss dann rot werden.

## Fassung 0.9.11 — drei Rechenfehler, ein offener Wachposten, ein Reiter, der falsche Bausteine nannte

Eine Durchsicht mit vier unabhängigen Blickwinkeln (Reiter, Rechenkern,
Hausstandard, Projektdatei der Anlage) hat 33 Befunde ergeben. Die
gewichtigsten:

**Im Rechenkern.** Ein Uhrsprung nach *vorn* — der Regelfall auf einem
Raspberry ohne Echtzeituhr — traf `lauf_s` ungebremst: 60 s Lauf, dann
NTP +3 h, und der Befund lautete *Dauerlauf*, die Sperre stand, `zeitsprung`
meldete nichts. Der Rücksprung war seit 0.9.8 abgefangen, der Vorwärtssprung
nie. `lauf_s` wird jetzt summiert und je Durchgang an `stale_s` gemessen.

Jede Lücke im Messwertstrom zählte als zusätzlicher Pumpenstart: zehn
Ausfälle des Zwischenzählers bei durchlaufender Pumpe ergaben zwölf Starts
statt einem, dazu zehn erfundene Laufdauern. Ein Übergang von oder zu
„unbekannt“ ist jetzt keiner.

Jede Mitternacht verlor die Gesamtlaufzeit einen Takt (863 940 statt
864 540 s über zehn Tage), und ein Lauf, der im Mitternachtstakt endete,
verlor seinen ganzen Anteil vor Mitternacht.

**Am Wachposten.** Das Formularmerkmal war aus dem Aktionstoken berechenbar —
und dasselbe Token steht in jeder Adresse, die der Miniserver aufruft, im
Loxone-Projekt und in beiden Vorlagen. Es hängt jetzt an einem eigenen
Geheimnis, das nirgendwohin geht. Und eine Sicherungsdatei mit geleertem
Token wird nicht mehr mit „21 von 21 übernommen“ angenommen.

**Im Reiter *Einbindung in Loxone*.** Vier der sieben Bausteinnamen gibt es in
Loxone Config nicht, obwohl der Reiter verspricht, man finde sie mit F5 unter
genau diesem Namen. Der schwerste Fall war die Ausfallerkennung: gesucht
werden musste die **Analogwertvalidierung** (Parameter *Tmc*: der Wert *muss*
sich in diesem Intervall ändern), nicht die ähnlich klingende
Analogwertüberwachung — die ist ein Bereichswächter und hätte nie gemeldet,
dass ein Zähler stehenbleibt.

Dazu: die Kommentare der Vorlagen werden in Config zum **Anzeigenamen**; sieben
der 24 waren Fließtext, der längste 202 Zeichen. Sie sind jetzt Beschriftungen
von höchstens 30 Zeichen, und eine neue Prüfzeile zählt das nach.

**Zwei Prüfungen, die nie gelaufen sind.** Der Suchweg zur Oberflächendatei
traf weder den Archiv- noch den Installationsbaum. Beide Prüfzeilen, die ihn
benutzen, zeigten deshalb einen Strich statt eines Hakens — und ein Strich ist
nicht rot, also fiel es nicht auf. Aufgefallen ist es erst, als eine dritte
Zeile denselben Weg benutzte.

Der Kern trägt jetzt 98 Prüffälle (vorher 79), die Selbstprüfung 24 Zeilen
(vorher 22). Neun Korrekturen sind geeicht: jede wird einzeln zurückgebaut,
und die Prüfung muss dann rot werden.

## Fassung 0.9.10 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/pw_lib.php:724`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. **Hier war der
Fehler wirksam, nicht nur latent**: `bin/pw_dienst.php` ruft `pw_log()` in
seiner Warteschleife. Das Protokoll wuchs auf der Ramdisk unbegrenzt weiter,
und niemand sah es.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe `LICENSE`.
