<?php

declare(strict_types=1);

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

    // ─── Rendering ──────────────────────────────────────────────────────────────

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $alertNames = [];
        foreach ($d['items'] as $item) {
            if ($item['type'] === 'alarm' && $item['bool'] === true) {
                $alertNames[] = $item['name'];
            }
        }

        $alarmBanner = $this->renderAlarmBanner($alertNames);

        $itemsHtml = '';
        foreach ($d['items'] as $item) {
            $itemsHtml .= $this->renderStatusItem($item);
        }
        $itemsBlock = $itemsHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">🛡️ ' . $this->Translate('Status') . '</div><div id="items-grid" class="current-grid">' . $itemsHtml . '</div></div>'
            : '';

        $batteryBlock = $this->renderBatteryPanel($d['battery']);
        $sirenBlock   = $this->renderSirenPanel($d['siren']);

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
.current-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:none}
.cur-tile{display:flex;flex-direction:column;gap:3px;background:#131f33;border-radius:8px;padding:6px 8px}
.cur-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.cur-value{font-size:15px;font-weight:700;color:#d0e8ff}
.pv-block{display:flex;flex-direction:column;gap:8px;flex:none;background:#0f1c30;border-radius:10px;padding:8px}
.pv-title{font-size:12px;font-weight:700;color:#d0e8ff}
/* Klickbare Statuspunkte (Scharf/Unscharf, Sirenenkanaele): die ganze Kachel ist der Button, kein eingebetteter Zweit-Schalter. */
.cur-tile.clickable{cursor:pointer;-webkit-tap-highlight-color:transparent;user-select:none;transition:background-color .15s,transform .08s;border:1px solid #1e3a5f}
.cur-tile.clickable:active{transform:scale(.97)}
.cur-tile.on{background:#1a3448;border-color:#2a7aa0}
.alarm-banner{background:#5a1010;border:1px solid #b03030;color:#ffb0a0;border-radius:10px;padding:10px 12px;font-size:13px;font-weight:700;flex:none;animation:alarm-pulse 1.4s ease-in-out infinite}
@keyframes alarm-pulse{0%,100%{opacity:1}50%{opacity:.7}}
.siren-on{background:#5a1010;animation:alarm-pulse 1.4s ease-in-out infinite}
.mini-btn{background:#1a2535;border:1px solid #2a3a50;color:#8aa8c8;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer}
.battery-box table{width:100%;border-collapse:collapse;font-size:11px;color:#8aa8c8}
.battery-box th,.battery-box td{text-align:left;padding:2px 4px}
</style>
</head>
<body>
<div class="header">
  <span>🚨 {$this->Translate('Alarm')} <span id="updated" class="updated">{$this->Translate('Stand')} {$updatedEsc}</span></span>
</div>

<div id="alarm-banner-wrap">{$alarmBanner}</div>
{$itemsBlock}
{$sirenBlock}
{$batteryBlock}

<script>
var state = {$initJson};
var i18n = {
  alarm: {$this->jsStr($this->Translate('ALARM'))},
  ok: 'OK',
  offen: {$this->jsStr($this->Translate('Offen'))},
  geschlossen: {$this->jsStr($this->Translate('Geschlossen'))},
  scharf: {$this->jsStr($this->Translate('Scharf'))},
  unscharf: {$this->jsStr($this->Translate('Unscharf'))},
  sirenOn: {$this->jsStr($this->Translate('Aktiv'))},
  sirenOff: {$this->jsStr($this->Translate('Inaktiv'))}
};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

function escapeHtml(s) {
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function updateBanner(items) {
  var names = [];
  for (var i = 0; i < items.length; i++) {
    if (items[i].type === 'alarm' && items[i].bool === true) names.push(items[i].name);
  }
  var wrap = document.getElementById('alarm-banner-wrap');
  if (!wrap) return;
  wrap.innerHTML = names.length > 0
    ? '<div class="alarm-banner">🚨 ' + i18n.alarm + ': ' + escapeHtml(names.join(', ')) + '</div>'
    : '';
}

function toggleCurTile(ident) {
  var input = document.getElementById(ident + '_input');
  var card = document.getElementById(ident);
  var next = input ? !input.checked : true;
  if (input) input.checked = next;
  if (card) card.classList.toggle('on', next);
  requestAction(ident, next);
}

function updateItem(item) {
  if (item.type === 'arm') {
    var icon = item.bool ? '🔒' : '🔓';
    setText(item.ident + '_text', icon + ' ' + (item.bool ? i18n.scharf : i18n.unscharf));
    var input = document.getElementById(item.ident + '_input');
    if (input) input.checked = !!item.bool;
    var card = document.getElementById(item.ident);
    if (card) card.classList.toggle('on', !!item.bool);
    return;
  }
  if (item.type === 'alarm' || item.type === 'window') {
    var badge = document.getElementById(item.ident + '_badge');
    if (!badge) return;
    var on = item.bool === true;
    badge.className = 'badge ' + (on ? 'badge-warn' : 'badge-off');
    if (item.type === 'alarm') {
      badge.textContent = (on ? '🚨 ' : '🔕 ') + (on ? i18n.alarm : i18n.ok);
    } else {
      badge.textContent = (on ? '🔓 ' : '🔒 ') + (on ? i18n.offen : i18n.geschlossen);
    }
    return;
  }
  // timestamp/text: reines Anzeigefeld, unveraendert seit Erstrender ok
}

function setSirenChannel(ident, on) {
  var tile = document.getElementById(ident);
  if (!tile) return;
  tile.classList.toggle('siren-on', on === true);
  tile.classList.toggle('on', on === true);
  setText(ident + '_text', (on ? '🔊 ' : '🔇 ') + (on ? i18n.sirenOn : i18n.sirenOff));
  var input = document.getElementById(ident + '_input');
  if (input) input.checked = on === true;
}

function updateSiren(siren) {
  if (!siren) return;
  if (siren.acoustic !== null) setSirenChannel('siren_acoustic', siren.acoustic);
  if (siren.optical !== null) setSirenChannel('siren_optical', siren.optical);
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  if (msg.key !== '__all__') return;
  var val = msg.value;
  state = val;
  setText('updated', val.updated);
  updateBanner(val.items);
  for (var i = 0; i < val.items.length; i++) {
    updateItem(val.items[i]);
  }
  updateSiren(val.siren);
};
</script>
</body>
</html>
HTML;
    }

    private function renderStatusItem(array $item): string
    {
        $nameEsc = htmlspecialchars($item['name'], ENT_QUOTES);

        $identEsc = htmlspecialchars($item['ident'], ENT_QUOTES);

        switch ($item['type']) {
            case 'arm':
                $on = $item['bool'] === true;
                $checked = $on ? ' checked' : '';
                $onClass = $on ? ' on' : '';
                $icon = $on ? '🔒' : '🔓';
                $text = $on ? $this->Translate('Scharf') : $this->Translate('Unscharf');
                return <<<HTML
<div id='{$identEsc}' class='cur-tile clickable{$onClass}' onclick="toggleCurTile('{$item['ident']}')">
  <span class='cur-label'>{$nameEsc}</span>
  <span id='{$identEsc}_text' class='cur-value' style="font-size:12px">{$icon} {$text}</span>
  <input id='{$identEsc}_input' type="checkbox"{$checked} style="display:none">
</div>
HTML;
            case 'alarm':
                $on = $item['bool'] === true;
                $cls = $on ? 'badge-warn' : 'badge-off';
                $icon = $on ? '🚨' : '🔕';
                $text = $on ? $this->Translate('ALARM') : 'OK';
                return $this->renderBadgeTile($identEsc, $nameEsc, $cls, $icon . ' ' . htmlspecialchars($text, ENT_QUOTES));
            case 'window':
                $on = $item['bool'] === true;
                $cls = $on ? 'badge-warn' : 'badge-off';
                $icon = $on ? '🔓' : '🔒';
                $text = $on ? $this->Translate('Offen') : $this->Translate('Geschlossen');
                return $this->renderBadgeTile($identEsc, $nameEsc, $cls, $icon . ' ' . htmlspecialchars($text, ENT_QUOTES));
            case 'timestamp':
                $ts = (int) $item['raw'];
                $text = $ts > 0 ? date('d.m.Y H:i', $ts) : '–';
                return $this->renderValueTile($nameEsc, htmlspecialchars($text, ENT_QUOTES));
            default:
                $text = trim((string) $item['raw']);
                return $this->renderValueTile($nameEsc, htmlspecialchars($text !== '' ? $text : '–', ENT_QUOTES));
        }
    }

    private function renderBadgeTile(string $identEsc, string $nameEsc, string $badgeClass, string $textEsc): string
    {
        return "<div id='{$identEsc}' class='cur-tile'><span class='cur-label'>{$nameEsc}</span><span id='{$identEsc}_badge' class='badge {$badgeClass}' style='align-self:flex-start'>{$textEsc}</span></div>";
    }

    private function renderAlarmBanner(array $alertNames): string
    {
        if (count($alertNames) === 0) {
            return '';
        }
        $namesEsc = htmlspecialchars(implode(', ', $alertNames), ENT_QUOTES);
        return '<div class="alarm-banner">🚨 ' . $this->Translate('ALARM') . ': ' . $namesEsc . '</div>';
    }

    /** Encodes a translated string for safe embedding as a JS literal. */
    private function jsStr(string $s): string
    {
        return json_encode($s, JSON_UNESCAPED_UNICODE);
    }

    private function renderValueTile(string $nameEsc, string $valueEsc): string
    {
        return "<div class='cur-tile'><span class='cur-label'>{$nameEsc}</span><span class='cur-value'>{$valueEsc}</span></div>";
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

    private function renderSirenPanel(?array $siren): string
    {
        if ($siren === null) {
            return '';
        }

        $rows = '';
        if ($siren['acoustic'] !== null) {
            $rows .= $this->renderSirenChannel('siren_acoustic', $this->Translate('Akustisch'), $siren['acoustic']);
        }
        if ($siren['optical'] !== null) {
            $rows .= $this->renderSirenChannel('siren_optical', $this->Translate('Optisch'), $siren['optical']);
        }
        if ($rows === '') {
            return '';
        }

        return <<<HTML
<div class="pv-block">
  <div class="pv-title">🔊 {$this->Translate('Sirene')}</div>
  <div class="current-grid">{$rows}</div>
</div>
HTML;
    }

    private function renderSirenChannel(string $ident, string $nameEsc, bool $on): string
    {
        $checked = $on ? ' checked' : '';
        $icon    = $on ? '🔊' : '🔇';
        $text    = $on ? $this->Translate('Aktiv') : $this->Translate('Inaktiv');
        $tileCls = $on ? 'cur-tile clickable siren-on on' : 'cur-tile clickable';
        return <<<HTML
<div id='{$ident}' class='{$tileCls}' onclick="toggleCurTile('{$ident}')">
  <span class='cur-label'>{$nameEsc}</span>
  <span id='{$ident}_text' class='cur-value' style="font-size:12px">{$icon} {$text}</span>
  <input id='{$ident}_input' type="checkbox"{$checked} style="display:none">
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
