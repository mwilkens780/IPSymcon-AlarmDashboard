# Alarm Dashboard – IP-Symcon Modul

Dashboard für die Hausalarmanlage, im selben dunklen Kachel-Stil wie die anderen Dashboards (Room Dashboard, SunRiser 8, Heating/Energy/Weather Dashboard). Eine Instanz für die gesamte Anlage.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-AlarmDashboard
```

## Konfiguration

- **Alarmzonen**: frei erweiterbare Liste. Als Zone zählt jede Instanz mit den Datenpunkten `Active` und `Alert` -- das trifft automatisch auf das IPS-Kernmodul "Alerting" zu, unabhängig von der genauen Modul-Version. Scharf-/Unscharfschalten passiert direkt über den Schalter in der Kachel (schreibt auf die `Active`-Variable der Zone).
- **Somfy-Alarmanlage** (optional): zeigt aktuellen Modus, Ziel-Modus (falls gerade eine Umschaltung läuft, inkl. Sekunden-Countdown) und Einbruchserkennung der TaHoma/Somfy-Instanz an.
- **Batterie-Monitor** (optional): eine ProfileMonitor-Instanz -- zeigt den Sammelstatus, die Anzahl betroffener Geräte und bei Warnung die vom Monitor selbst gelieferte Geräteliste. Button "Jetzt prüfen" stößt eine manuelle Neuprüfung an.

## Bewusst nicht eingebaut: Somfy scharf-/unscharfschalten

Die Somfy-Anlage (TaHoma) lässt sich technisch nur über eine generische `TAHOMA_SendCommand(instanzID, befehl, parameter)`-Funktion steuern -- ohne fest hinterlegte Befehlsnamen fürs Scharfschalten, und ohne ein bestehendes Skript im System, das diese schon mal erfolgreich aufgerufen hätte. Auf gut Glück Befehle gegen eine echte Alarmanlage zu testen ist riskant (Fehlalarm oder ungeschütztes Haus), deshalb zeigt dieses Modul den Somfy-Status nur an. Sobald die exakten Befehlsnamen bekannt sind (z.B. durch Somfy-App-Recherche oder einen vorsichtigen, begleiteten Live-Test), lässt sich die Steuerung ergänzen.

## Alarm-Banner

Sobald irgendeine Zone `Alert = true` meldet oder die Somfy-Anlage einen Einbruch erkennt, erscheint oben in der Kachel ein rot pulsierender Banner mit den betroffenen Zonen -- unübersehbar, unabhängig vom Scroll-Zustand der übrigen Kachel.

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren.
