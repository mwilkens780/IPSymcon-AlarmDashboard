<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileKit.php';

/**
 * Zeigt die Hausalarm-Komponenten in einer Kachel: beliebig viele
 * Statuspunkte (freie Liste, z.B. die per "HomeMatic Systemvariablen"-
 * Instanz aus der CCU3 gespiegelten Alarm-/Fenster-/Wasseralarm-Variablen),
 * der Batterie-Sammelstatus (ProfileMonitor) sowie optional eine Sirene
 * (HomeMatic-Geraeteinstanz, ACOUSTIC_ALARM_ACTIVE/OPTICAL_ALARM_ACTIVE
 * werden automatisch anhand ihres Idents gefunden, kein manuelles
 * Verdrahten einzelner Variablen noetig).
 *
 * Bewusst NICHT eingebaut: eine eigene, parallele Alarmlogik (wie das
 * IPS-Kernmodul "Alerting"). Die Alarmlogik lebt vollstaendig in den
 * CCU3-Programmen; die "HomeMatic Systemvariablen"-Instanz spiegelt deren
 * Zustand bidirektional nach IPS (verifiziert -- Schreiben von hier wirkt
 * auf die CCU3 zurueck). Scharf-/Unscharfschalten laeuft ueber die
 * gespiegelten Variablen "Alarm intern"/"Alarm extern" (vom Nutzer
 * bestaetigt) -- als Statuspunkt-Typ "arm" konfigurierbar.
 */
class AlarmDashboard extends IPSModule
{
    use \Fuchsbau\TileKit\AlarmDashboard\TileKit;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('statusItems', '[]');
        $this->RegisterPropertyInteger('battery_monitor', 0);
        $this->RegisterPropertyInteger('siren_instance', 0);
        $this->RegisterPropertyInteger('update_interval', 30);

        $this->RegisterTimer('UpdateTimer', 0, 'ALD_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $items = json_decode($this->ReadPropertyString('statusItems'), true) ?: [];
        $hasAnything = count($items) > 0
            || $this->ReadPropertyInteger('battery_monitor') > 0
            || $this->ReadPropertyInteger('siren_instance') > 0;

        if (!$hasAnything) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);
        $this->Refresh();
    }

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    public function Refresh(): void
    {
        try {
            $this->tkPush('__all__', $this->decorate($this->collectData()));
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('AlarmDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── IPS action handler ─────────────────────────────────────────────────────

    public function RequestAction($Ident, $Value): void
    {
        try {
            // TileKit-Schalter senden '<ident>_toggle'
            $Ident = (string) preg_replace('/_toggle$/', '', (string) $Ident);
            if (strpos($Ident, 'item_') === 0) {
                $this->forwardStatusItemAction((int) substr($Ident, strlen('item_')), (bool) $Value);
                return;
            }
            if ($Ident === 'battery_rescan') {
                $this->forwardBatteryRescan();
                return;
            }
            if ($Ident === 'siren_acoustic' || $Ident === 'siren_optical') {
                $this->forwardSirenAction($Ident === 'siren_acoustic' ? 'ACOUSTIC_ALARM_ACTIVE' : 'OPTICAL_ALARM_ACTIVE', (bool) $Value);
                return;
            }
            $this->LogMessage("AlarmDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        } catch (\Throwable $e) {
            $this->LogMessage('AlarmDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /**
     * Scharf-/Unscharfschalten -- nur fuer Statuspunkte vom Typ "arm"
     * (z.B. "Alarm intern"/"Alarm extern"). Schreibt direkt auf die
     * konfigurierte, von der CCU3 gespiegelte Variable; die Rueckrichtung
     * (IPS -> CCU3) ist vom Nutzer bestaetigt.
     */
    private function forwardStatusItemAction(int $index, bool $value): void
    {
        $rows = json_decode($this->ReadPropertyString('statusItems'), true) ?: [];
        if (!isset($rows[$index]['variable']) || ($rows[$index]['type'] ?? '') !== 'arm') {
            return;
        }
        $varId = (int) $rows[$index]['variable'];
        if ($varId <= 0 || !@IPS_VariableExists($varId)) {
            return;
        }
        RequestAction($varId, $value);
    }

    /** Stoesst eine manuelle Neupruefung des Batterie-Monitors an. */
    private function forwardBatteryRescan(): void
    {
        $monitorId = $this->ReadPropertyInteger('battery_monitor');
        if ($monitorId <= 0 || !@IPS_InstanceExists($monitorId)) {
            return;
        }
        $triggerId = $this->varIdByIdent($monitorId, 'RemoteTrigger');
        if ($triggerId > 0) {
            RequestAction($triggerId, true);
        }
    }

    /**
     * Schaltet die Sirene aus/ein -- schreibt direkt auf den
     * ACOUSTIC_ALARM_ACTIVE/OPTICAL_ALARM_ACTIVE-Kanal der konfigurierten
     * HomeMatic-Sirenen-Instanz (Standardverhalten von HM-Sec-SFA-SM:
     * "false" schreiben stoppt Ton/Blitz sofort).
     */
    private function forwardSirenAction(string $ident, bool $value): void
    {
        $nodeId = $this->ReadPropertyInteger('siren_instance');
        $varId  = $this->varIdByIdent($nodeId, $ident);
        if ($varId <= 0) {
            return;
        }
        RequestAction($varId, $value);
    }

    // ─── Data collection ──────────────────────────────────────────────────────

    private function collectData(): array
    {
        return [
            'items'   => $this->collectStatusItems(),
            'battery' => $this->collectBattery(),
            'siren'   => $this->collectSiren(),
            'updated' => date('d.m. H:i'),
        ];
    }

    /**
     * Frei konfigurierbare Liste beliebiger Variablen (z.B. die von der
     * CCU3 gespiegelten Alarm-Systemvariablen). Typ steuert nur die
     * Darstellung -- Alarm/Fenster nehmen an, dass "true"/"Ein" den
     * auffaelligen Zustand bedeutet (Standardpolung); falls eine konkrete
     * CCU3-Variable umgekehrt gepolt ist, faellt das beim ersten Live-Test
     * auf und kann dann nachgebessert werden.
     */
    private function collectStatusItems(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('statusItems'), true) ?: [] as $i => $row) {
            $varId = (int) ($row['variable'] ?? 0);
            if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                continue;
            }
            $type = (string) ($row['type'] ?? 'text');
            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $raw = GetValue($varId);

            $out[] = [
                'ident' => 'item_' . $i,
                'name'  => $nameOverride ?? $this->deviceName($varId),
                'type'  => $type,
                'bool'  => in_array($type, ['alarm', 'window', 'arm'], true) ? (bool) $raw : null,
                'raw'   => $raw,
            ];
        }
        return $out;
    }

    private function collectBattery(): ?array
    {
        $nodeId = $this->ReadPropertyInteger('battery_monitor');
        if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
            return null;
        }
        $warningId = $this->varIdByIdent($nodeId, 'Warning');
        $countId   = $this->varIdByIdent($nodeId, 'Devices_With_Empty_Battery');
        $boxId     = $this->varIdByIdent($nodeId, 'Webfront_Message_Box');
        if ($warningId <= 0) {
            return null;
        }
        return [
            'warning' => (bool) $this->readVarById($warningId),
            'count'   => $countId > 0 ? (int) $this->readVarById($countId) : 0,
            'box'     => $boxId > 0 ? (string) $this->readVarById($boxId) : '',
        ];
    }

    /**
     * Liest Sirenen-Status von der konfigurierten HomeMatic-Geraeteinstanz --
     * kein manuelles Verdrahten einzelner Variablen noetig, die bekannten
     * Kanal-Idents (ACOUSTIC_ALARM_ACTIVE/OPTICAL_ALARM_ACTIVE, Standard bei
     * HomeMatic-Sirenen wie HM-Sec-SFA-SM) werden automatisch gefunden --
     * gleiches Prinzip wie collectBattery() beim ProfileMonitor.
     */
    private function collectSiren(): ?array
    {
        $nodeId = $this->ReadPropertyInteger('siren_instance');
        if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
            return null;
        }
        $acousticId = $this->varIdByIdent($nodeId, 'ACOUSTIC_ALARM_ACTIVE');
        $opticalId  = $this->varIdByIdent($nodeId, 'OPTICAL_ALARM_ACTIVE');
        if ($acousticId <= 0 && $opticalId <= 0) {
            return null;
        }
        return [
            'acoustic' => $acousticId > 0 ? (bool) $this->readVarById($acousticId) : null,
            'optical'  => $opticalId > 0 ? (bool) $this->readVarById($opticalId) : null,
        ];
    }

    // ─── Rendering (TileKit, siehe docs/ui-kachel.md im Workspace) ─────────────

    /**
     * Ergaenzt die Rohdaten um die Anzeige (Text, Icon, Zustand), damit
     * Erstrender und Live-Push dieselbe Logik nutzen und das JS nur noch
     * Texte/Klassen setzt.
     */
    private function decorate(array $d): array
    {
        $alerts = [];
        foreach ($d['items'] as &$item) {
            $on = $item['bool'] === true;
            switch ($item['type']) {
                case 'arm':
                    $item['view'] = ['text' => $this->Translate($on ? 'Scharf' : 'Unscharf'), 'icon' => $on ? '🔒' : '🔓', 'on' => $on];
                    break;
                case 'alarm':
                    $icon = self::tkTopicIcon($item['name'], $on) ?: ($on ? '🚨' : '🔕');
                    $item['view'] = ['text' => $on ? $this->Translate('ALARM') : 'OK', 'icon' => $icon, 'state' => $on ? 'alarm' : 'ok'];
                    if ($on) {
                        $alerts[] = $item['name'];
                    }
                    break;
                case 'window':
                    $item['view'] = ['text' => $this->Translate($on ? 'Offen' : 'Geschlossen'), 'icon' => $on ? '🔓' : '🔒', 'state' => $on ? 'warn' : ''];
                    break;
                case 'timestamp':
                    $ts = (int) $item['raw'];
                    $item['view'] = ['text' => $ts > 0 ? date('d.m.Y H:i', $ts) : '–', 'icon' => '', 'state' => ''];
                    break;
                default:
                    $text = trim((string) $item['raw']);
                    $item['view'] = ['text' => $text !== '' ? $text : '–', 'icon' => '', 'state' => ''];
            }
            unset($item['raw']);
        }
        unset($item);
        $d['alerts'] = $alerts;

        if ($d['battery'] !== null) {
            $b = &$d['battery'];
            $b['text']    = $b['warning'] ? $b['count'] . ' ' . $this->Translate('schwach') : 'OK';
            $b['icon']    = $b['warning'] ? '🪫' : '🔋';
            $b['state']   = $b['warning'] ? 'warn' : 'ok';
            $b['showBox'] = $b['warning'] && strpos($b['box'], 'Keine Komponenten') === false;
            unset($b);
        }
        if ($d['siren'] !== null) {
            foreach (['acoustic', 'optical'] as $ch) {
                $on = $d['siren'][$ch];
                $d['siren'][$ch] = $on === null ? null : ['on' => $on, 'text' => $this->Translate($on ? 'Aktiv' : 'Inaktiv'), 'icon' => $on ? '🔊' : '🔇'];
            }
        }
        return $d;
    }

    private function buildDashboardHTML(): string
    {
        $d    = $this->decorate($this->collectData());
        $body = '';

        // Aktiver Alarm: eine rote, pulsierende Kachel ueber die volle Breite – bewusst die einzige Breitkachel.
        $alertText = count($d['alerts']) > 0 ? '🚨 ' . $this->Translate('ALARM') . ': ' . implode(', ', $d['alerts']) : '';
        $body .= '<div id="alarm_banner" class="tk-tile tk-is-alarm tk-full alarm-banner"' . ($alertText === '' ? ' hidden' : '') . '>'
            . '<span id="alarm_banner_val" class="tk-status">' . self::tkEsc($alertText) . '</span></div>';

        if (count($d['items']) > 0) {
            $body .= self::tkGroup($this->Translate('Status'));
            foreach ($d['items'] as $item) {
                $v = $item['view'];
                $body .= $item['type'] === 'arm'
                    ? self::tkToggleTile($item['ident'], $item['name'], $v['on'], $v['text'], $v['icon'])
                    : self::tkStatusTile($item['ident'], $item['name'], $v['text'], $v['icon'], $v['state']);
            }
        }

        if ($d['siren'] !== null) {
            $body .= self::tkGroup($this->Translate('Sirene'));
            foreach (['acoustic' => 'Akustisch', 'optical' => 'Optisch'] as $ch => $label) {
                $c = $d['siren'][$ch];
                if ($c !== null) {
                    $body .= self::tkToggleTile('siren_' . $ch, $this->Translate($label), $c['on'], $c['text'], $c['icon'], $c['on'] ? 'tk-is-alarm siren-on' : '');
                }
            }
        }

        if ($d['battery'] !== null) {
            $b     = $d['battery'];
            $body .= self::tkGroup($this->Translate('Batterien'));
            $body .= self::tkStatusTile('battery', $this->Translate('Status'), $b['text'], $b['icon'], $b['state']);
            $body .= self::tkActionTile('battery_rescan', 'battery_rescan', $this->Translate('Jetzt prüfen'), '🔄');
            $body .= '<div id="battery_box_wrap" class="tk-full"' . ($b['showBox'] ? '' : ' hidden') . '>'
                . self::tkHtmlTile('battery_box', $this->Translate('Schwache Batterien'), $b['box']) . '</div>';
        }

        $css = <<<'CSS'
.alarm-banner{animation:tk-pulse 1.4s ease-in-out infinite}
.alarm-banner .tk-status{font-size:var(--tk-fs-text)}
.siren-on{animation:tk-pulse 1.4s ease-in-out infinite}
@keyframes tk-pulse{0%,100%{opacity:1}50%{opacity:.72}}
[hidden]{display:none !important}
CSS;
        $js = <<<'JS'
var states = ['ok', 'warn', 'alarm'];
function setState(ident, st) {
  var card = tk.el(ident + '_card'), el = tk.el(ident + '_status');
  if (card) { card.classList.toggle('tk-is-alarm', st === 'alarm'); card.classList.toggle('tk-is-warn', st === 'warn'); }
  if (el) states.forEach(function (s) { el.classList.toggle('tk-' + s, s === st); });
}
var lastBox = state && state.battery ? state.battery.box : '';
tk.on('__all__', function (v) {
  state = v;
  var banner = tk.el('alarm_banner');
  if (banner) { banner.hidden = v.alerts.length === 0; tk.setText('alarm_banner_val', v.alerts.length ? '🚨 ALARM: ' + v.alerts.join(', ') : ''); }
  v.items.forEach(function (it) {
    tk.setText(it.ident + '_val', it.view.text);
    tk.setText(it.ident + '_icon', it.view.icon);
    if (it.type === 'arm') tk.setOn(it.ident + '_card', it.view.on); else setState(it.ident, it.view.state);
  });
  if (v.siren) ['acoustic', 'optical'].forEach(function (ch) {
    var c = v.siren[ch]; if (!c) return;
    var id = 'siren_' + ch;
    tk.setOn(id + '_card', c.on); tk.setClass(id + '_card', 'tk-is-alarm', c.on); tk.setClass(id + '_card', 'siren-on', c.on);
    tk.setText(id + '_val', c.text); tk.setText(id + '_icon', c.icon);
  });
  if (v.battery) {
    tk.setText('battery_val', v.battery.text); tk.setText('battery_icon', v.battery.icon); setState('battery', v.battery.state);
    var wrap = tk.el('battery_box_wrap'); if (wrap) wrap.hidden = !v.battery.showBox;
    var f = tk.el('battery_box_frame');
    if (f && v.battery.box !== lastBox) { lastBox = v.battery.box; f.srcdoc = f.srcdoc.replace(/<\/style>[\s\S]*$/, '</style>') + v.battery.box; }
  }
});
JS;
        return $this->tkPage($this->Translate('Alarm'), '🚨', $d['updated'], "<div class=\"tk-grid\">{$body}</div>", $css, $js, json_encode($d));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function readVarById(int $id)
    {
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    /** Resolves an instance's own variable ID by ident. */
    private function varIdByIdent(int $instanceId, string $ident): int
    {
        if ($instanceId <= 0) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return $id ?: 0;
    }

    private const GENERIC_NAMES = [
        'state', 'level', 'zustand', 'wert', 'status', 'value', 'variable',
        'unbenannt', 'unnamed', 'neues objekt', 'new object',
    ];

    private function isGenericName(string $name): bool
    {
        return $name === '' || in_array(mb_strtolower(trim($name)), self::GENERIC_NAMES, true);
    }

    /** The name a user would actually recognise, walking up the tree past generic idents like "STATE". */
    private function deviceName(int $nodeId): string
    {
        if ($nodeId <= 0) {
            return '';
        }
        $name = IPS_GetName($nodeId);
        if (!$this->isGenericName($name)) {
            return $name;
        }
        $parentId = IPS_GetParent($nodeId);
        for ($depth = 0; $parentId > 0 && $depth < 4; $depth++) {
            $parentName = IPS_GetName($parentId);
            if (!$this->isGenericName($parentName)) {
                return $parentName;
            }
            $parentId = IPS_GetParent($parentId);
        }
        return $name;
    }
}
