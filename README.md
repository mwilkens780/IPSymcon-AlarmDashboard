# Alarm Dashboard – IP-Symcon Modul

Dashboard für die Hausalarmanlage, im selben dunklen Kachel-Stil wie die anderen Dashboards (Room Dashboard, SunRiser 8, Heating/Energy/Weather Dashboard). Eine Instanz für die gesamte Anlage.

## Architektur-Entscheidung

Die eigentliche Alarmlogik (Sensoren auswerten, scharf-/unscharf schalten, Sirene ansteuern) läuft vollständig als Programme auf der HomeMatic CCU3 -- das bleibt bewusst die einzige Quelle der Wahrheit. Dieses Modul baut **keine** eigene, parallele Alarmlogik in IPS auf (ursprünglich mit dem IPS-Kernmodul "Alerting" geplant, dann verworfen), sondern liest nur die Systemvariablen, die eine "HomeMatic Systemvariablen"-Instanz bereits bidirektional aus der CCU3 nach IPS spiegelt. Grund: zwei unabhängige Zustandsmaschinen synchron zu halten (ohne Verzug oder Verlust bei Aktivierung/Deaktivierung/Alarmierung) ist ein Risiko, das sich bei einer echten Alarmanlage nicht lohnt -- ein Anzeige-/Fernbedienungs-Layer über der bestehenden, bewährten CCU3-Logik ist die robustere Wahl.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-AlarmDashboard
```

## Konfiguration

- **Statuspunkte**: frei erweiterbare Liste beliebiger Variablen -- typischerweise die von der "HomeMatic Systemvariablen"-Instanz gespiegelten CCU3-Alarmvariablen (z.B. `Alarm intern`, `Alarm extern`, `Alarm Feuer`, `Alarm Wasser`, `Alarm Batterie`, `Fensteröffnung oben/unten`, `Wasseralarm Raum`). Typ steuert Darstellung und Verhalten:
  - **Scharf/Unscharf (schaltbar)**: für die Aktivierungsvariablen `Alarm intern`/`Alarm extern` -- ein Schalter, der direkt auf die Variable schreibt und damit auf die CCU3 zurückwirkt.
  - **Alarm (Ja/Nein)**: nur Anzeige, roter ALARM-Badge bei "Ein", löst den pulsierenden Banner oben aus.
  - **Fenster/Tür (Auf/Zu)**: nur Auf/Zu-Anzeige, kein Alarm-Banner.
  - **Zeitstempel**: als Datum/Uhrzeit formatiert.
  - **Text**: unverändert angezeigt.

  Die Polung (Ein = scharf/Alarm/offen) folgt der üblichen Konvention -- falls eine konkrete CCU3-Variable umgekehrt gepolt ist, fällt das beim ersten Live-Test auf.
- **Batterie-Monitor** (optional): eine ProfileMonitor-Instanz -- zeigt den Sammelstatus, die Anzahl betroffener Geräte und bei Warnung die vom Monitor selbst gelieferte Geräteliste. Button "Jetzt prüfen" stößt eine manuelle Neuprüfung an.

## Alarm-Banner

Sobald ein als "Alarm" typisierter Statuspunkt auf "Ein" steht, erscheint oben in der Kachel ein rot pulsierender Banner mit den betroffenen Punkten -- unübersehbar, unabhängig vom Scroll-Zustand der übrigen Kachel.

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren.
