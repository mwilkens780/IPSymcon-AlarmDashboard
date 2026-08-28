<?php

declare(strict_types=1);

/**
 * Zeigt und steuert die Hausalarm-Komponenten in einer Kachel: beliebig
 * viele Alarmzonen (IPS-Kernmodul "Alerting" -- erkannt an den Datenpunkten
 * Active/Alert/ActiveSensors, unabhaengig von der konkreten Instanz-GUID),
 * optional die Somfy/TaHoma-Alarmanlage (nur Anzeige) und der
 * Batterie-Sammelstatus (ProfileMonitor).
 *
 * Die Somfy-Anlage selbst (scharf/unscharf schalten) wird bewusst NICHT
 * gesteuert: TaHoma-Alarme laufen nur ueber eine generische
 * TAHOMA_SendCommand()-Funktion ohne dokumentierte Befehlsnamen fuers
 * Scharfschalten, und ein Blindversuch gegen eine echte Alarmanlage waere
 * unverantwortlich. Sobald die Befehle bekannt sind, kann das ergaenzt
 * werden (siehe README).
 */
class AlarmDashboard extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('zones', '[]');
        $this->RegisterPropertyInteger('somfy_instance', 0);
        $this->RegisterPropertyInteger('battery_monitor', 0);
        $this->RegisterPropertyInteger('update_interval', 30);

        $this->RegisterTimer('UpdateTimer', 0, 'ALD_Refresh($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $zones = json_decode($this->ReadPropertyString('zones'), true) ?: [];
        $hasAnything = count($zones) > 0
            || $this->ReadPropertyInteger('somfy_instance') > 0
            || $this->ReadPropertyInteger('battery_monitor') > 0;

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
            $this->pushValue('__all__', $this->collectData());
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
            if (strpos($Ident, 'zone_') === 0) {
                $this->forwardZoneAction((int) substr($Ident, strlen('zone_')), (bool) $Value);
                return;
            }
            if ($Ident === 'battery_rescan') {
                $this->forwardBatteryRescan();
                return;
            }
            $this->LogMessage("AlarmDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        } catch (\Throwable $e) {
            $this->LogMessage('AlarmDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    /** Scharf-/Unscharfschalten einer einzelnen Alarmzone -- schreibt direkt auf deren eigene "Active"-Variable. */
    private function forwardZoneAction(int $index, bool $active): void
    {
        $zones = json_decode($this->ReadPropertyString('zones'), true) ?: [];
        if (!isset($zones[$index]['variable'])) {
            return;
        }
        $nodeId = (int) $zones[$index]['variable'];
        if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
            return;
        }
        $activeId = $this->varIdByIdent($nodeId, 'Active');
        if ($activeId > 0) {
            RequestAction($activeId, $active);
        }
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

    // ─── Data collection ──────────────────────────────────────────────────────

    private function collectData(): array
    {
        return [
            'zones'   => $this->collectZones(),
            'somfy'   => $this->collectSomfy(),
            'battery' => $this->collectBattery(),
            'updated' => date('d.m. H:i'),
        ];
    }

    /**
     * Eine Zone gilt als solche, sobald die Ziel-Instanz sowohl "Active"
     * als auch "Alert" hat -- damit funktioniert das unabhaengig von der
     * konkreten Modul-GUID des IPS-Kernmoduls "Alerting".
     */
    private function collectZones(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('zones'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['variable'] ?? 0);
            if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
                continue;
            }
            $activeId = $this->varIdByIdent($nodeId, 'Active');
            $alertId  = $this->varIdByIdent($nodeId, 'Alert');
            if ($activeId <= 0 || $alertId <= 0) {
                continue;
            }
            $sensorsId      = $this->varIdByIdent($nodeId, 'ActiveSensors');
            $delayId        = $this->varIdByIdent($nodeId, 'DelayDisplay');
            $triggerDelayId = $this->varIdByIdent($nodeId, 'TriggerDelayDisplay');

            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $out[] = [
                'ident'         => 'zone_' . $i,
                'name'          => $nameOverride ?? $this->deviceName($nodeId),
                'active'        => (bool) $this->readVarById($activeId),
                'alert'         => (bool) $this->readVarById($alertId),
                'activeSensors' => $sensorsId > 0 ? (string) $this->readVarById($sensorsId) : '',
                'delay'         => $delayId > 0 ? (string) $this->readVarById($delayId) : '',
                'triggerDelay'  => $triggerDelayId > 0 ? (string) $this->readVarById($triggerDelayId) : '',
            ];
        }
        return $out;
    }

    /** Nur Anzeige -- siehe Klassenkommentar, warum hier bewusst nicht gesteuert wird. */
    private function collectSomfy(): ?array
    {
        $nodeId = $this->ReadPropertyInteger('somfy_instance');
        if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
            return null;
        }
        $currentId   = $this->varIdByIdent($nodeId, 'internal_CurrentAlarmModeState');
        $targetId    = $this->varIdByIdent($nodeId, 'internal_TargetAlarmModeState');
        $intrusionId = $this->varIdByIdent($nodeId, 'internal_IntrusionDetectedState');
        $delayId     = $this->varIdByIdent($nodeId, 'internal_AlarmDelayState');
        if ($currentId <= 0) {
            return null;
        }
        return [
            'current'   => (string) $this->readVarById($currentId),
            'target'    => $targetId > 0 ? (string) $this->readVarById($targetId) : '',
            'intrusion' => $intrusionId > 0 ? (string) $this->readVarById($intrusionId) : '',
            'delay'     => $delayId > 0 ? (int) $this->readVarById($delayId) : 0,
        ];
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

    // ─── Rendering ──────────────────────────────────────────────────────────────

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $anyAlert = false;
        $alertNames = [];
        foreach ($d['zones'] as $zone) {
            if ($zone['alert']) {
                $anyAlert = true;
                $alertNames[] = $zone['name'];
            }
        }
        $somfyIntrusion = $d['somfy'] !== null && $d['somfy']['intrusion'] === 'detected';
        if ($somfyIntrusion) {
            $anyAlert = true;
            $alertNames[] = $this->Translate('Somfy-Alarmanlage');
        }

        $alarmBanner = '';
        if ($anyAlert) {
            $namesEsc = htmlspecialchars(implode(', ', $alertNames), ENT_QUOTES);
            $alarmBanner = '<div class="alarm-banner">🚨 ' . $this->Translate('ALARM') . ': ' . $namesEsc . '</div>';
        }

        $zonesHtml = '';
        foreach ($d['zones'] as $zone) {
            $zonesHtml .= $this->renderZoneTile($zone);
        }
        $zonesBlock = $zonesHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">🛡️ ' . $this->Translate('Alarmzonen') . '</div><div class="tile-grid">' . $zonesHtml . '</div></div>'
            : '';

        $somfyBlock = $this->renderSomfyPanel($d['somfy']);
        $batteryBlock = $this->renderBatteryPanel($d['battery']);

        $updatedEsc = htmlspecialchars($d['updated'], ENT_QUOTES);
        $initJson = json_encode($d);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:10px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.updated{font-size:10px;color:#3a5a7a;font-weight:400}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;white-space:nowrap}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-on{background:#12405a;border-color:#2a7aa0;color:#7ec8f0}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.status-row{display:flex;gap:6px;flex-wrap:wrap;flex:none}
.current-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:none}
.tile-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;flex:none}
.cur-tile{display:flex;flex-direction:column;gap:1px;background:#131f33;border-radius:8px;padding:6px 8px}
.cur-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.cur-value{font-size:15px;font-weight:700;color:#d0e8ff}
.pv-block{display:flex;flex-direction:column;gap:8px;flex:none;background:#0f1c30;border-radius:10px;padding:8px}
.pv-title{font-size:12px;font-weight:700;color:#d0e8ff}
.toggle{position:relative;width:44px;height:24px;flex:none;display:inline-block}
.toggle input{opacity:0;position:absolute;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}
.toggle-track{position:absolute;inset:0;background:#1a2535;border:1px solid #2a3a50;border-radius:12px;transition:.15s}
.toggle-thumb{position:absolute;top:2px;left:2px;width:18px;height:18px;background:#8aa8c8;border-radius:50%;transition:.15s}
.toggle input:checked ~ .toggle-track{background:#12405a;border-color:#2a7aa0}
.toggle input:checked ~ .toggle-track .toggle-thumb{transform:translateX(20px);background:#7ec8f0}
.zone-tile{display:flex;flex-direction:column;gap:6px;background:#131f33;border-radius:8px;padding:8px}
.zone-head{display:flex;justify-content:space-between;align-items:center;gap:6px}
.zone-name{font-size:12px;font-weight:600;color:#d0e8ff}
.zone-sensors{font-size:10px;color:#f08060}
.zone-delay{font-size:10px;color:#4a6a8a}
.alarm-banner{background:#5a1010;border:1px solid #b03030;color:#ffb0a0;border-radius:10px;padding:10px 12px;font-size:13px;font-weight:700;flex:none;animation:alarm-pulse 1.4s ease-in-out infinite}
@keyframes alarm-pulse{0%,100%{opacity:1}50%{opacity:.7}}
.mini-btn{background:#1a2535;border:1px solid #2a3a50;color:#8aa8c8;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer}
.battery-box table{width:100%;border-collapse:collapse;font-size:11px;color:#8aa8c8}
.battery-box th,.battery-box td{text-align:left;padding:2px 4px}
</style>
</head>
<body>
<div class="header">
  <span>🚨 {$this->Translate('Alarm')} <span id="updated" class="updated">{$this->Translate('Stand')} {$updatedEsc}</span></span>
</div>

{$alarmBanner}
{$zonesBlock}
{$somfyBlock}
{$batteryBlock}

<script>
var state = {$initJson};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  if (msg.key !== '__all__') return;
  var val = msg.value;
  state = val;
  setText('updated', val.updated);
  // Volle Neu-Darstellung (Alarm-Banner, Zonen, Somfy, Batterie) erfolgt
  // beim naechsten Kachel-Reload -- bewusst kein Live-Patch dieser Felder,
  // gleiches Prinzip wie bei Sensoren/Rauchmeldern im Room Dashboard.
};
</script>
</body>
</html>
HTML;
    }

    private function renderZoneTile(array $zone): string
    {
        $ident = $zone['ident'];
        $nameEsc = htmlspecialchars($zone['name'], ENT_QUOTES);
        $checked = $zone['active'] ? ' checked' : '';

        $alertBadge = $zone['alert']
            ? '<span class="badge badge-warn">' . $this->Translate('ALARM') . '</span>'
            : '<span class="badge badge-off">OK</span>';

        $sensorsHtml = ($zone['alert'] && $zone['activeSensors'] !== '')
            ? '<div class="zone-sensors">' . htmlspecialchars($zone['activeSensors'], ENT_QUOTES) . '</div>'
            : '';

        $delayParts = [];
        if ($zone['delay'] !== '' && $zone['delay'] !== '0:00') {
            $delayParts[] = $this->Translate('Aktivierung in') . ' ' . htmlspecialchars($zone['delay'], ENT_QUOTES);
        }
        if ($zone['triggerDelay'] !== '' && $zone['triggerDelay'] !== '0:00') {
            $delayParts[] = $this->Translate('Alarm in') . ' ' . htmlspecialchars($zone['triggerDelay'], ENT_QUOTES);
        }
        $delayHtml = $delayParts !== [] ? '<div class="zone-delay">' . implode(' · ', $delayParts) . '</div>' : '';

        return <<<HTML
<div class="zone-tile">
  <div class="zone-head">
    <span class="zone-name">{$nameEsc}</span>
    <label class="toggle"><input type="checkbox"{$checked} onchange="requestAction('{$ident}', this.checked)"><span class="toggle-track"><span class="toggle-thumb"></span></span></label>
  </div>
  <div class="status-row">{$alertBadge}</div>
  {$sensorsHtml}
  {$delayHtml}
</div>
HTML;
    }

    private function renderSomfyPanel(?array $somfy): string
    {
        if ($somfy === null) {
            return '';
        }

        $modeLabels = [
            'off'          => $this->Translate('Unscharf'),
            'notDetected'  => $this->Translate('Kein Einbruch erkannt'),
            'detected'     => $this->Translate('EINBRUCH ERKANNT'),
        ];

        $currentLabel = $modeLabels[$somfy['current']] ?? $somfy['current'];
        $currentEsc = htmlspecialchars($currentLabel, ENT_QUOTES);

        $targetHtml = '';
        if ($somfy['target'] !== '' && $somfy['target'] !== $somfy['current']) {
            $targetLabel = $modeLabels[$somfy['target']] ?? $somfy['target'];
            $targetEsc = htmlspecialchars($targetLabel, ENT_QUOTES);
            $delaySuffix = $somfy['delay'] > 0 ? ' (' . $somfy['delay'] . 's)' : '';
            $targetHtml = '<div class="zone-delay">' . $this->Translate('wird gestellt auf') . ': ' . $targetEsc . $delaySuffix . '</div>';
        }

        $intrusionEsc = htmlspecialchars($modeLabels[$somfy['intrusion']] ?? $somfy['intrusion'], ENT_QUOTES);
        $intrusionCls = $somfy['intrusion'] === 'detected' ? 'badge-warn' : 'badge-off';

        return <<<HTML
<div class="pv-block">
  <div class="pv-title">🏠 {$this->Translate('Somfy-Alarmanlage')}</div>
  <div class="current-grid">
    <div class='cur-tile'><span class='cur-label'>{$this->Translate('Modus')}</span><span class='cur-value'>{$currentEsc}</span></div>
    <div class='cur-tile'><span class='cur-label'>{$this->Translate('Einbruch')}</span><span class='badge {$intrusionCls}'>{$intrusionEsc}</span></div>
  </div>
  {$targetHtml}
</div>
HTML;
    }

    private function renderBatteryPanel(?array $battery): string
    {
        if ($battery === null) {
            return '';
        }

        $badge = $battery['warning']
            ? '<span class="badge badge-warn">' . $battery['count'] . ' ' . $this->Translate('schwach') . '</span>'
            : '<span class="badge badge-off">OK</span>';

        $boxHtml = '';
        if ($battery['warning'] && strpos($battery['box'], 'Keine Komponenten') === false) {
            $boxHtml = '<div class="battery-box">' . $battery['box'] . '</div>';
        }

        return <<<HTML
<div class="pv-block">
  <div class="pv-title" style="display:flex;justify-content:space-between;align-items:center">
    <span>🔋 {$this->Translate('Batterien')}</span>
    {$badge}
  </div>
  {$boxHtml}
  <button type="button" class="mini-btn" onclick="requestAction('battery_rescan', 1)">{$this->Translate('Jetzt prüfen')}</button>
</div>
HTML;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

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
