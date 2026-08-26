# Pumpenwaechter

**Null- oder begrenzte Einspeisung für mehrere Wechselrichter und Hybrid-Speicher.**
Misst am Netzzähler, füllt erst den Speicher, regelt erst dann ab.

Version 0.9.6 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

---

## Neu in 0.9.5

**Die Bedienoberflaeche brach auf dem installierten LoxBerry ab.**
`webfrontend/htmlauth/index.php` suchte `pw_lib.php` ueber
`dirname(__DIR__) . '/html/…'`. Im entpackten Archiv liegen `html/` und
`htmlauth/` nebeneinander, installiert in getrennten Baeumen — dort zeigte der
Pfad ins Leere, und die Seite blieb weiss.

Die Bibliothek wird jetzt ueber eine Kandidatenliste gesucht; findet keiner sie,
steht auf der Seite, **welche Datei wo erwartet wurde**, statt eines leeren
Fensters.

## Wofür

Die Bremse hält die Einspeisung auf dem Wert, der erlaubt ist — null bei
einer Nulleinspeisung, 70 Prozent der Modulleistung bei einer
70-Prozent-Regelung. Sie liest den Netzzähler, rechnet den Überschuss aus
und gibt eine Grenze an die Wechselrichter weiter.

Die Reihenfolge ist der eigentliche Gewinn: Was in den Speicher passt,
wandert in den Speicher. Abgeregelt wird nur, was dort nicht mehr
hineingeht. Eingelagerter Strom kostet keinen Ertrag, abgeregelter schon.

## Was sie nicht tut

**Sie erfindet keine Register.** Welche Adresse ein Wechselrichter für
einen Fernsollwert annimmt, weiß der Hersteller und sonst niemand. Ein
geratenes Modbus-Register schreibt im besten Fall ins Leere und im
schlechtesten in eine Werkseinstellung, die nachher niemand mehr findet.
Deshalb gibt es keine eingebaute Geräteliste: Sie tragen Adresse und Inhalt
ein und setzen einen Platzhalter dorthin, wo der Wert hingehört —
`{W}`, `{KW}` oder `{PROZENT}`.

**Sie nimmt keinen Sollwert von außen entgegen.** Aus Loxone lässt sich die
Regelung ein- und ausschalten, mehr nicht. Das Wortzeichen steht offen in
der Adresse; wer damit die Grenze setzen könnte, könnte die Anlage
abschalten. Der Schalter dagegen ist im schlimmsten denkbaren Fall harmlos:
er *gibt frei*, er drosselt nicht.

**Sie greift nicht von selbst ein.** Nach der Installation läuft der Dienst
und misst — die Regelung selbst ist aus. Erst wenn Sie sie einschalten,
geht ein Befehl hinaus. Und einschalten lässt sie sich nur, wenn nichts
Wesentliches fehlt; die Liste steht im Reiter *Test* unter „Einstellung
prüfen“.

## Drei Dinge, die schiefgehen und hier nicht schiefgehen sollen

**Das Vorzeichen.** Im ganzen Plugin gilt *plus = Bezug, minus =
Einspeisung*. Umgedreht wird genau einmal, beim Einlesen. Steht der Haken
falsch, regelt die Bremse exakt verkehrt herum — deshalb zeigt der Reiter
*Test* den Zählerwert so, wie die Regelung ihn sieht.

**Der ausgefallene Zähler.** Kein Messwert heißt nicht „alles in Ordnung“.
Nach der eingestellten Zeit fährt die Anlage auf den Notwert; der darf die
Grenze nur senken, nie anheben. Ein Wert jenseits jedes Hausanschlusses
wird **verworfen**, nicht auf null gebogen — eine Null hieße „keine
Einspeisung“, und daraufhin gäbe die Regelung frei.

**Die Quittung, die keine Wirkung ist.** Ein Wechselrichter, der den
Sollwert mit HTTP 200 quittiert und dann ignoriert, ist der unangenehmste
Fall: alles meldet Erfolg, und die Auflage ist trotzdem verletzt. Nach der
eingestellten Wartezeit wird deshalb am Zähler nachgesehen, ob die
Einspeisung wirklich gefallen ist. Ist sie das nicht, steht es oben als
Warnung und geht als `WIRKUNG = -1` nach Loxone.

## Beim Ausschalten

Wird die Regelung ausgeschaltet — in der Oberfläche, über Loxone oder beim
Deinstallieren —, wird die Anlage **einmal freigegeben**. Sonst bliebe sie
auf der zuletzt gestellten Grenze stehen, und der fehlende Ertrag fiele
erst Wochen später auf. Erreicht die Freigabe nicht alle Geräte, wird sie
im nächsten Durchlauf wiederholt und der Fehlschlag protokolliert.

Beim *Beenden des Dienstes* bleibt die Grenze dagegen bestehen. Ein Dienst,
der beim Beenden alles freigibt, hebt genau in dem Augenblick eine Auflage
auf, in dem niemand mehr hinsieht — beim Neustart, beim Update, beim
Absturz.

## Mehrere Wechselrichter

Die Gesamtgrenze wird im Verhältnis der Anteile aufgeteilt, gedeckelt durch
die eingetragene Spitzenleistung. Stößt ein Gerät an seine Spitze, wandert
der Rest zu den anderen — so lange, bis nichts mehr übrig ist oder niemand
mehr Luft hat. Ohne diese Runden summierten sich die gestellten Grenzen auf
weniger als die erlaubte, und die Anlage bliebe dauerhaft zu scharf
abgeregelt.

## Prüfstand

* `php bin/eb_dienst.php --selbsttest` — 62 Fälle des Regelkerns, ohne
  Anlage und ohne Netz.
* `php bin/eb_dienst.php --probe` — die Messwerte einmal lesen und zeigen.
* `php bin/eb_dienst.php --einmal` — ein Durchlauf im Vordergrund.
* Reiter *Test*, **Trockenlauf** — was die Regelung jetzt täte, samt der
  vollständigen Befehle, die dabei hinausgingen. Ohne dass etwas gestellt
  wird.

Die Oberfläche ist gegen PHP 7.4.33 und 8.2.32 gerendert worden: alle fünf
Reiter, ohne Meldung, ohne unübersetzten Schlüssel, `sm-active`
serverseitig gesetzt.

## Ordner

```
bin/            Dienst und Startskript
cron/           Minutentakt — startet den Dienst, falls er steht
dpkg/apt        mosquitto-clients (wird von LoxBerry als root installiert)
templates/      Sprachdateien und Hilfe
webfrontend/    html = Regelkern, Bibliothek und Endpunkt; htmlauth = Oberfläche
uninstall/      gibt die Anlage frei, bevor das Plugin verschwindet
```
