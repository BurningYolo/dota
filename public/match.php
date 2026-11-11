<?php
declare(strict_types=1);

/** Local parsed-match report. This page reads saved files only; it makes no API requests. */
$dataDir = rtrim((string)(getenv('DATA_DIR') ?: dirname(__DIR__) . '/data'), '/\\');
$matchId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$error = null;
$match = null;
$filePath = $matchId ? $dataDir . '/final/' . $matchId . '.json' : '';
$constantsDir = __DIR__ . '/assets/constants';
$heroes = is_file($constantsDir . '/heroes.json') ? (json_decode((string)file_get_contents($constantsDir . '/heroes.json'), true) ?: []) : [];
$itemsRaw = is_file($constantsDir . '/items.json') ? (json_decode((string)file_get_contents($constantsDir . '/items.json'), true) ?: []) : [];
$abilities = is_file($constantsDir . '/abilities.json') ? (json_decode((string)file_get_contents($constantsDir . '/abilities.json'), true) ?: []) : [];
$abilityIds = is_file($constantsDir . '/ability_ids.json') ? (json_decode((string)file_get_contents($constantsDir . '/ability_ids.json'), true) ?: []) : [];
$items = [];
foreach ($itemsRaw as $key => $item) if (is_array($item) && isset($item['id'])) $items[(int)$item['id']] = $item + ['key' => $key];

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmtTime(mixed $seconds): string
{
    if (!is_numeric($seconds)) return '—';
    $seconds = (int)$seconds;
    $sign = $seconds < 0 ? '-' : '';
    $seconds = abs($seconds);
    return $sign . intdiv($seconds, 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}
function numberValue(array $row, string $key): string
{
    return isset($row[$key]) && is_numeric($row[$key]) ? number_format((float)$row[$key]) : '—';
}
function jsonText(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?: 'null';
}
function iconUrl(mixed $image): string
{
    if (!is_string($image) || $image === '' || $image[0] !== '/') return '';
    return 'https://cdn.cloudflare.steamstatic.com' . strtok($image, '?');
}

if ($matchId === false || $matchId === null) {
    $error = 'Enter a valid match ID from the dashboard.';
} elseif (!is_file($filePath)) {
    $error = 'No saved final JSON exists for this match yet.';
} else {
    $match = json_decode((string)file_get_contents($filePath), true);
    if (!is_array($match)) $error = 'The saved final match file is not valid JSON.';
    elseif (empty($match['players']) || !is_array($match['players'])) $error = 'This final response has no player data.';
}

$players = is_array($match['players'] ?? null) ? $match['players'] : [];
$parsed = false;
foreach ($players as $player) if (!empty($player['gold_t']) || !empty($player['xp_t'])) { $parsed = true; break; }
$duration = (int)($match['duration'] ?? 0);
$radiantScore = $match['radiant_score'] ?? null;
$direScore = $match['dire_score'] ?? null;
$winner = isset($match['radiant_win']) ? ($match['radiant_win'] ? 'Radiant victory' : 'Dire victory') : 'Result unavailable';
$radiantName = (string)($match['radiant_team']['name'] ?? 'Radiant');
$direName = (string)($match['dire_team']['name'] ?? 'Dire');
$series = ['players' => [], 'advantage' => ['gold' => $match['radiant_gold_adv'] ?? [], 'xp' => $match['radiant_xp_adv'] ?? []]];
foreach ($players as $i => $player) {
    $slot = (int)($player['player_slot'] ?? -1);
    $radiant = array_key_exists('isRadiant', $player) ? (bool)$player['isRadiant'] : $slot < 128;
    $position = $radiant ? $slot : $slot - 128;
    if ($position < 0 || $position > 4) $position = $i % 5;
    $series['players'][] = [
        'name' => ($radiant ? 'Radiant Player ' : 'Dire Player ') . ($position + 1),
        'side' => $radiant ? 'Radiant' : 'Dire',
        'gold' => $player['gold_t'] ?? [], 'xp' => $player['xp_t'] ?? [], 'times' => $player['times'] ?? [],
    ];
}
$hasAdvantage = is_array($series['advantage']['gold']) && count($series['advantage']['gold']) > 1;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $match ? 'Match ' . h($matchId) . ' · Research report' : 'Match report' ?> · Dota Observer</title>
<style>
:root{color-scheme:dark;--bg:#101214;--panel:#171a1d;--line:#292e32;--muted:#929ba2;--text:#edf0f2;--red:#dc675e;--green:#74d6a2;--gold:#e4bd70;--blue:#83b9ed;--violet:#bea4f2}*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse at 80% -15%,#39211f 0,transparent 38%),var(--bg);color:var(--text);font:14px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{max-width:1240px;margin:auto;padding:28px 22px 60px}.top{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}.brand{display:flex;gap:12px;align-items:center}.mark{width:40px;height:40px;border-radius:12px;background:linear-gradient(145deg,#e8564b,#872a27);display:grid;place-items:center;font-weight:900;font-size:18px}.eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#cf827b;font-weight:700}.title{font-size:21px;font-weight:700}.links{display:flex;gap:8px}.link{color:#f0b0a7;text-decoration:none;border:1px solid #55332f;border-radius:8px;padding:8px 11px;font-size:12px}.link:hover{background:#281c1b}h1{font-size:30px;letter-spacing:-.045em;margin:0 0 5px}.sub{color:var(--muted);margin:0}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:15px;margin-bottom:19px}.winner{font-size:12px;color:var(--green);border:1px solid #315543;background:#19251f;border-radius:99px;padding:7px 11px}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:11px;margin-bottom:15px}.card,.panel,.player{background:linear-gradient(145deg,#1a1d20,#151719);border:1px solid var(--line);border-radius:13px}.card{padding:15px 16px}.label{font-size:11px;color:var(--muted)}.value{font-size:21px;font-weight:700;letter-spacing:-.03em;margin-top:5px}.subvalue{font-size:11px;color:var(--muted);margin-top:2px}.panel{overflow:hidden;margin:14px 0}.panelhead{padding:15px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:14px}.panelhead strong{font-size:15px}.panelbody{padding:16px 18px}.hint{font-size:11px;color:var(--muted)}.charttools{display:flex;align-items:center;gap:9px}select{background:#101214;color:var(--text);border:1px solid var(--line);border-radius:7px;padding:8px 10px}canvas{width:100%;height:330px;display:block}.chartnote{font-size:11px;color:var(--muted);margin-top:8px}.teams{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:15px 0}.team{border:1px solid var(--line);border-radius:12px;overflow:hidden}.teamhead{padding:12px 14px;background:#1b1e21;display:flex;justify-content:space-between;font-weight:650}.teamhead.r{color:#9cd9b5}.teamhead.d{color:#e99b94}.player{margin:10px;padding:14px}.playerhead{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;padding-bottom:10px;border-bottom:1px solid var(--line)}.playername{font-weight:700;font-size:15px}.heroid{font-size:11px;color:var(--muted)}.kda{font-size:15px;font-weight:700;font-variant-numeric:tabular-nums}.statgrid{display:grid;grid-template-columns:repeat(6,1fr);gap:7px;margin:11px 0}.stat{background:#111416;border:1px solid #24282b;border-radius:8px;padding:8px}.stat span{display:block;color:var(--muted);font-size:10px}.stat b{font-size:12px}.sectionlabel{font-size:10px;color:#cfaaa5;text-transform:uppercase;letter-spacing:.11em;margin:12px 0 6px}.chips{display:flex;gap:5px;flex-wrap:wrap}.chip{font-size:11px;background:#22272a;color:#d4dcdf;border:1px solid #30373b;border-radius:6px;padding:4px 7px}.items .chip{color:#e7c87e}.empty{color:var(--muted);padding:28px;text-align:center}.facts{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.fact{padding:9px 10px;border-radius:8px;background:#111416;border:1px solid #24282b;overflow-wrap:anywhere}.fact b{display:block;font-size:11px;color:#c8d0d4;margin-bottom:2px}.fact span{font-size:11px;color:var(--muted)}.alert{padding:15px 17px;border:1px solid #55482c;border-radius:12px;background:#241f15;color:#e5c985}.timeline{max-height:270px;overflow:auto}.timeline table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #25292c;font-size:11px}th{color:var(--muted);text-transform:uppercase;font-size:9px;letter-spacing:.1em}.foot{color:var(--muted);font-size:11px;text-align:right;margin-top:14px}@media(max-width:850px){.cards{grid-template-columns:repeat(3,1fr)}.statgrid{grid-template-columns:repeat(3,1fr)}}@media(max-width:620px){.shell{padding:19px 12px}.hero{align-items:flex-start;flex-direction:column}.cards{grid-template-columns:repeat(2,1fr)}.teams{grid-template-columns:1fr}.panelhead{align-items:flex-start;flex-direction:column}.charttools{width:100%}.charttools select{flex:1;min-width:0}.facts{grid-template-columns:repeat(2,1fr)}.links{flex-direction:column}.top{align-items:flex-start}}
</style><link rel="stylesheet" href="assets/theme.css"></head><body><main class="shell">
<header class="top"><div class="brand"><div class="mark">D</div><div><div class="eyebrow">Dota 2 · Research tools</div><div class="title">Dota Observer</div></div></div><nav class="links"><a class="link" href="index.php">← Dashboard</a><?php if ($matchId): ?><a class="link" href="data.php?match=<?= h($matchId) ?>">Raw JSON &amp; snapshots</a><?php endif; ?></nav></header>
<?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php else: ?>
<section class="hero"><div><h1><?= h($radiantName) ?> <span style="color:var(--muted);font-size:19px">vs</span> <?= h($direName) ?></h1><p class="sub">Match <?= h($matchId) ?> · <?= h($parsed ? 'Parsed replay data available' : 'Final match data; parsed time series unavailable') ?></p></div><span class="winner <?= isset($match['radiant_win']) && !$match['radiant_win'] ? 'dire' : '' ?>"><?= h($winner) ?></span></section>
<section class="cards">
  <div class="card"><div class="label">Final score</div><div class="value"><?= h($radiantScore ?? '—') ?> : <?= h($direScore ?? '—') ?></div><div class="subvalue">Radiant : Dire</div></div>
  <div class="card"><div class="label">Duration</div><div class="value"><?= h(fmtTime($duration)) ?></div><div class="subvalue"><?= number_format($duration) ?> seconds</div></div>
  <div class="card"><div class="label">Game mode</div><div class="value"><?= h($match['game_mode'] ?? '—') ?></div><div class="subvalue">OpenDota mode ID</div></div>
  <div class="card"><div class="label">Patch</div><div class="value"><?= h($match['patch'] ?? '—') ?></div><div class="subvalue">OpenDota patch ID</div></div>
  <div class="card"><div class="label">League</div><div class="value" style="font-size:15px"><?= h($match['league']['name'] ?? $match['leagueid'] ?? 'Public match') ?></div><div class="subvalue"><?= h($match['region'] ?? 'Region unavailable') ?></div></div>
</section>
<section class="panel"><div class="panelhead"><div><strong>Match comparison</strong><div class="hint">Choose several team or player series to compare on one graph</div></div><div class="charttools"><select id="series"><option value="adv_gold" <?= empty($match['radiant_gold_adv']) ? 'disabled' : '' ?>>Radiant gold advantage</option><option value="adv_xp" <?= empty($match['radiant_xp_adv']) ? 'disabled' : '' ?>>Radiant XP advantage</option><?php foreach ($players as $i => $p): $side = (!empty($p['isRadiant']) || (int)($p['player_slot'] ?? 0) < 128) ? 'Radiant' : 'Dire'; $slotNum = (int)($p['player_slot'] ?? -1); $pos = $side === 'Radiant' ? $slotNum : $slotNum - 128; if ($pos < 0 || $pos > 4) $pos = $i % 5; $name = $side . ' Player ' . ($pos + 1); if (!empty($p['gold_t'])): ?><option value="gold_<?= (int)$i ?>"><?= h($name) ?> · gold</option><?php endif; if (!empty($p['xp_t'])): ?><option value="xp_<?= (int)$i ?>"><?= h($name) ?> · XP</option><?php endif; endforeach; ?></select></div></div><div class="panelbody"><div id="series-toggles" class="series-toggles"></div><canvas id="chart" aria-label="Combined parsed match comparison chart"></canvas><div id="chartnote" class="chartnote"></div></div></section>
<section class="panel"><div class="panelhead"><div><strong>Player performance</strong><div class="hint">Core stats, final inventory, and replay-derived ability upgrades</div></div><span class="hint"><?= count($players) ?> players</span></div>
<div class="teams">
<?php foreach ([['Radiant', true, 'r'], ['Dire', false, 'd']] as [$teamName, $isRadiant, $teamClass]): ?><section class="team"><div class="teamhead <?= $teamClass ?>"><span><?= $teamName ?></span><span><?= (int)($isRadiant ? $radiantScore : $direScore) ?> kills</span></div>
<?php foreach ($players as $i => $p): $slot = (int)($p['player_slot'] ?? -1); $onRadiant = array_key_exists('isRadiant', $p) ? (bool)$p['isRadiant'] : $slot < 128; if ($onRadiant !== $isRadiant) continue; $playerOrder = $onRadiant ? $slot : $slot - 128; if ($playerOrder < 0 || $playerOrder > 4) $playerOrder = $i % 5; $name = ($onRadiant ? 'Radiant Player ' : 'Dire Player ') . ($playerOrder + 1); $hero = $heroes[(string)($p['hero_id'] ?? '')] ?? null; $heroName = $hero['localized_name'] ?? ('Unknown hero ' . ($p['hero_id'] ?? '')); ?>
<article class="player"><div class="playerhead"><div style="display:flex;gap:12px;align-items:center"><?php if ($hero && ($heroUrl = iconUrl($hero['img'] ?? '')) !== ''): ?><img src="<?= h($heroUrl) ?>" alt="<?= h($heroName) ?>" width="82" height="46" style="object-fit:cover;border-radius:6px" loading="lazy"><?php endif; ?><div><div class="playername"><?= h($name) ?> · <?= h($heroName) ?></div><div class="heroid">Level <?= h($p['level'] ?? '—') ?></div></div></div><div class="kda"><?= h($p['kills'] ?? '—') ?> / <?= h($p['deaths'] ?? '—') ?> / <?= h($p['assists'] ?? '—') ?></div></div>
<div class="statgrid"><?php foreach ([['GPM','gold_per_min'],['XPM','xp_per_min'],['Last hits','last_hits'],['Denies','denies'],['Net worth','net_worth'],['Hero damage','hero_damage'],['Tower damage','tower_damage'],['Healing','hero_healing'],['Damage taken','damage_taken'],['Camps stacked','camps_stacked'],['Runes','rune_pickups'],['Buybacks','buyback_count']] as [$label,$key]): ?><div class="stat"><span><?= h($label) ?></span><b><?= h(is_array($p[$key] ?? null) ? array_sum($p[$key]) : numberValue($p, $key)) ?></b></div><?php endforeach; ?></div>
<div class="sectionlabel">Final inventory</div><div class="chips items"><?php $itemCount = 0; foreach (range(0, 5) as $slot): $itemId = (int)($p['item_' . $slot] ?? 0); if ($itemId > 0): $itemCount++; $itemInfo = $items[$itemId] ?? null; $itemName = $itemInfo['dname'] ?? ('Item ' . $itemId); $itemUrl = iconUrl($itemInfo['img'] ?? ''); ?><span class="chip" title="<?= h($itemName) ?> · inventory slot <?= $slot + 1 ?>"><?php if ($itemUrl !== ''): ?><img src="<?= h($itemUrl) ?>" alt="" width="32" height="24" style="object-fit:contain;vertical-align:middle;margin-right:4px" loading="lazy"><?php endif; ?><?= h($itemName) ?></span><?php endif; endforeach; if (!$itemCount): ?><span class="hint">No inventory data</span><?php endif; ?></div>
<div class="sectionlabel">Ability upgrade sequence</div><div class="chips"><?php $upgrades = $p['ability_upgrades_arr'] ?? []; if (is_array($upgrades) && $upgrades): foreach ($upgrades as $level => $abilityId): $abilityKey = $abilityIds[(string)$abilityId] ?? ''; $abilityInfo = is_string($abilityKey) ? ($abilities[$abilityKey] ?? null) : null; $abilityName = $abilityInfo['dname'] ?? ('Ability ' . $abilityId); $abilityUrl = iconUrl($abilityInfo['img'] ?? ''); ?><span class="chip" title="<?= h($abilityName) ?> · skill level <?= $level + 1 ?>"><?php if ($abilityUrl !== ''): ?><img src="<?= h($abilityUrl) ?>" alt="" width="30" height="30" style="object-fit:cover;vertical-align:middle;margin-right:4px;border-radius:4px" loading="lazy"><?php endif; ?><?= h($abilityName) ?></span><?php endforeach; else: ?><span class="hint">No ability upgrade data</span><?php endif; ?></div>
<?php if (!empty($p['purchase_log']) && is_array($p['purchase_log'])): ?><details style="margin-top:12px"><summary class="hint" style="cursor:pointer">Purchase log · <?= count($p['purchase_log']) ?> events</summary><div class="timeline"><table><thead><tr><th>Game time</th><th>Item</th><th>Cost</th></tr></thead><tbody><?php foreach ($p['purchase_log'] as $purchase): $purchasedKey = (string)($purchase['key'] ?? ''); $purchasedName = $itemsRaw[$purchasedKey]['dname'] ?? ($purchasedKey !== '' ? ucwords(str_replace('_', ' ', $purchasedKey)) : 'Unknown item'); $purchasedImg = iconUrl($itemsRaw[$purchasedKey]['img'] ?? ''); ?><tr><td><?= h(fmtTime($purchase['time'] ?? null)) ?></td><td><?php if ($purchasedImg !== ''): ?><img src="<?= h($purchasedImg) ?>" alt="" width="26" height="20" style="object-fit:contain;vertical-align:middle;margin-right:5px" loading="lazy"><?php endif; ?><?= h($purchasedName) ?></td><td><?= h($purchase['cost'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
</article><?php endforeach; ?></section><?php endforeach; ?>
</div></section>
<section class="panel"><div class="panelhead"><div><strong>Match context</strong><div class="hint">Additional fields useful for research filtering</div></div></div><div class="panelbody"><div class="facts"><?php foreach (['start_time'=>'Start time (Unix)','lobby_type'=>'Lobby type ID','skill'=>'Skill bracket ID','cluster'=>'Server cluster','region'=>'Region ID','human_players'=>'Human players','first_blood_time'=>'First blood time (seconds)','radiant_win'=>'Radiant won','match_seq_num'=>'Match sequence','game_mode'=>'Game mode ID','patch'=>'Patch ID','series_id'=>'Series ID'] as $key=>$label): ?><div class="fact"><b><?= h($label) ?></b><span><?= h(isset($match[$key]) ? (is_bool($match[$key]) ? ($match[$key] ? 'Yes' : 'No') : (is_scalar($match[$key]) ? $match[$key] : json_encode($match[$key]))) : 'Unavailable') ?></span></div><?php endforeach; ?></div></div></section>
<div class="foot">Loaded from local final JSON · No OpenDota API calls are made. Hero, item, and ability labels come from the bundled local constants; icons load from the Steam CDN.</div>
<script>
const matchSeries = <?= jsonText($series) ?>;
const teamSeries = {gold:{radiant:[],dire:[]},xp:{radiant:[],dire:[]}};
for (const player of matchSeries.players) {
  for (const metric of ['gold','xp']) {
    const values=Array.isArray(player[metric])?player[metric]:[];
    for(let i=0;i<values.length;i++){
      const n=Number(values[i]);if(!Number.isFinite(n))continue;
      const side=player.side==='Radiant'?'radiant':'dire';teamSeries[metric][side][i]=(teamSeries[metric][side][i]||0)+n;
    }
  }
}
const teamCanvas=document.getElementById('teamChart'), teamCtx=teamCanvas.getContext('2d'), teamMetric=document.getElementById('teamMetric'), teamNote=document.getElementById('teamNote');
function drawTeams(){
 const rect=teamCanvas.getBoundingClientRect(),dpr=window.devicePixelRatio||1;teamCanvas.width=Math.max(1,Math.floor(rect.width*dpr));teamCanvas.height=Math.max(1,Math.floor(rect.height*dpr));teamCtx.setTransform(dpr,0,0,dpr,0,0);
 const w=rect.width,h=rect.height,p={l:65,r:20,t:26,b:38},metric=teamMetric.value,teams=teamSeries[metric];
 const n=Math.max(teams.radiant.length,teams.dire.length);teamCtx.clearRect(0,0,w,h);teamCtx.font='11px system-ui';
 const vals=[...teams.radiant,...teams.dire].filter(Number.isFinite);if(n<2||!vals.length){teamNote.textContent='Team time series are missing or incomplete in this saved parse.';return;}
 const min=Math.min(0,...vals),max=Math.max(...vals),span=Math.max(1,max-min),x=i=>p.l+i/(n-1)*(w-p.l-p.r),y=v=>p.t+(max-v)/span*(h-p.t-p.b);
 teamCtx.strokeStyle='#292e32';teamCtx.fillStyle='#929ba2';for(let g=0;g<=4;g++){const v=min+span*g/4,yy=y(v);teamCtx.beginPath();teamCtx.moveTo(p.l,yy);teamCtx.lineTo(w-p.r,yy);teamCtx.stroke();teamCtx.fillText(Math.round(v).toLocaleString(),4,yy+4);}
 for(const [side,color] of [['radiant','#74d6a2'],['dire','#e97970']]){const a=teams[side];teamCtx.strokeStyle=color;teamCtx.lineWidth=2;teamCtx.beginPath();let started=false;for(let i=0;i<a.length;i++){if(!Number.isFinite(a[i]))continue;if(!started){teamCtx.moveTo(x(i),y(a[i]));started=true;}else teamCtx.lineTo(x(i),y(a[i]));}teamCtx.stroke();}
 teamCtx.fillStyle='#74d6a2';teamCtx.fillRect(p.l,7,9,9);teamCtx.fillText('Radiant',p.l+14,16);teamCtx.fillStyle='#e97970';teamCtx.fillRect(p.l+85,7,9,9);teamCtx.fillText('Dire',p.l+99,16);teamCtx.fillStyle='#929ba2';teamCtx.fillText('0 min',p.l,h-9);teamCtx.fillText((n-1)+' min',w-p.r-45,h-9);teamNote.textContent=(metric==='gold'?'Gold':'Experience')+' per minute · total of the five saved player series · '+n+' points';
}
teamMetric.addEventListener('change',drawTeams);window.addEventListener('resize',drawTeams);drawTeams();
const select = document.getElementById('series');
const canvas = document.getElementById('chart');
const ctx = canvas.getContext('2d');
const note = document.getElementById('chartnote');
function draw(){
  const rect=canvas.getBoundingClientRect(), dpr=window.devicePixelRatio||1;
  canvas.width=Math.max(1,Math.floor(rect.width*dpr));canvas.height=Math.max(1,Math.floor(rect.height*dpr));ctx.setTransform(dpr,0,0,dpr,0,0);
  const w=rect.width,h=rect.height,p={l:62,r:18,t:20,b:36}, key=select.value;let values=[],label='';
  if(key==='adv_gold'){values=matchSeries.advantage.gold;label='Radiant gold advantage';}
  else if(key==='adv_xp'){values=matchSeries.advantage.xp;label='Radiant XP advantage';}
  else {const [type,index]=key.split('_');const player=matchSeries.players[Number(index)];values=player?.[type]||[];label=(player?.name||'Player')+' · '+(type==='gold'?'Gold':'XP');}
  values=Array.isArray(values)?values.filter(v=>Number.isFinite(Number(v))).map(Number):[];
  ctx.clearRect(0,0,w,h);ctx.font='11px system-ui';ctx.fillStyle='#929ba2';ctx.strokeStyle='#292e32';ctx.lineWidth=1;
  if(values.length<2){note.textContent='No usable time-series values are saved for this selection. The parsed match may have only summary statistics.';return;}
  const min=Math.min(0,...values),max=Math.max(0,...values),span=Math.max(1,max-min),x=i=>p.l+(i/(values.length-1))*(w-p.l-p.r),y=v=>p.t+(max-v)/span*(h-p.t-p.b);
  for(let g=0;g<=4;g++){const v=min+span*g/4, yy=y(v);ctx.beginPath();ctx.moveTo(p.l,yy);ctx.lineTo(w-p.r,yy);ctx.stroke();ctx.fillText(Math.round(v).toLocaleString(),4,yy+4);}
  ctx.strokeStyle=key.startsWith('adv_')?'#e4bd70':'#83b9ed';ctx.lineWidth=2;ctx.beginPath();values.forEach((v,i)=>i?ctx.lineTo(x(i),y(v)):ctx.moveTo(x(i),y(v)));ctx.stroke();
  ctx.fillStyle='#929ba2';ctx.fillText('0 min',p.l,h-9);ctx.fillText((values.length-1)+' min',w-p.r-50,h-9);note.textContent=label+' · '+values.length+' points · minute intervals';
  canvas.onmousemove=e=>{const b=canvas.getBoundingClientRect(),i=Math.max(0,Math.min(values.length-1,Math.round((e.clientX-b.left-p.l)/(w-p.l-p.r)*(values.length-1))));note.textContent=label+' · '+i+' min · '+values[i].toLocaleString();};
}
select.addEventListener('change',draw);window.addEventListener('resize',draw);draw();
</script>
<script>
// Replace the former separate team chart and single-series selector with one multi-series graph.
document.getElementById('teamChart')?.closest('.panel')?.remove();
const combinedCanvas=document.getElementById('chart');
const combinedCtx=combinedCanvas.getContext('2d');
const combinedNote=document.getElementById('chartnote');
const toggleHost=document.getElementById('series-toggles');
const oldSelector=document.getElementById('series');
if(oldSelector)oldSelector.parentElement.remove();
toggleHost.style.cssText='display:flex;flex-wrap:wrap;gap:7px;max-height:135px;overflow:auto;margin-bottom:12px';
const palette=['#74d6a2','#e97970','#83b9ed','#bea4f2','#e4bd70','#65c8cf','#ee9bcd','#a9d476','#f29b55','#9cafff','#d1d968','#e6a5a0'];
const plotSeries=[];
function addPlot(key,label,values){
  if(!Array.isArray(values)||values.length<2)return;
  plotSeries.push({key,label,color:palette[plotSeries.length%palette.length],values:values.map(v=>v===null?null:(Number.isFinite(Number(v))?Number(v):null))});
}
addPlot('team_gold_radiant','Radiant team gold',teamSeries.gold.radiant);
addPlot('team_gold_dire','Dire team gold',teamSeries.gold.dire);
addPlot('team_xp_radiant','Radiant team XP',teamSeries.xp.radiant);
addPlot('team_xp_dire','Dire team XP',teamSeries.xp.dire);
addPlot('adv_gold','Radiant gold advantage',matchSeries.advantage.gold);
addPlot('adv_xp','Radiant XP advantage',matchSeries.advantage.xp);
for(let i=0;i<matchSeries.players.length;i++){
  const player=matchSeries.players[i];
  addPlot('gold_'+i,player.name+' gold',player.gold);
  addPlot('xp_'+i,player.name+' XP',player.xp);
}
const defaultKeys=new Set(['team_gold_radiant','team_gold_dire','adv_gold']);
for(const line of plotSeries){
  const label=document.createElement('label');label.style.cssText='display:flex;align-items:center;gap:6px;padding:5px 8px;border:1px solid #30373b;border-radius:7px;background:#111416;color:#d4dcdf;font-size:11px;cursor:pointer';
  const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=defaultKeys.has(line.key);checkbox.dataset.series=line.key;checkbox.style.margin='0';checkbox.addEventListener('change',drawCombined);
  const swatch=document.createElement('span');swatch.style.cssText='width:9px;height:9px;border-radius:50%;background:'+line.color;
  const text=document.createElement('span');text.textContent=line.label;
  label.append(checkbox,swatch,text);toggleHost.append(label);
}
function drawCombined(){
  const rect=combinedCanvas.getBoundingClientRect(),dpr=window.devicePixelRatio||1;
  combinedCanvas.width=Math.max(1,Math.floor(rect.width*dpr));combinedCanvas.height=Math.max(1,Math.floor(rect.height*dpr));combinedCtx.setTransform(dpr,0,0,dpr,0,0);
  const active=plotSeries.filter(s=>toggleHost.querySelector('input[data-series="'+s.key+'"]').checked),w=rect.width,h=rect.height,p={l:70,r:18,t:16,b:36};
  const n=Math.max(0,...active.map(s=>s.values.length)),all=active.flatMap(s=>s.values.filter(Number.isFinite));
  combinedCtx.clearRect(0,0,w,h);combinedCtx.font='11px system-ui';combinedCtx.fillStyle='#929ba2';combinedCtx.strokeStyle='#292e32';combinedCtx.lineWidth=1;
  if(n<2||!all.length){combinedNote.textContent='Enable one or more available series. This match may not have parsed time-series data.';return;}
  const min=Math.min(0,...all),max=Math.max(0,...all),span=Math.max(1,max-min),x=i=>p.l+i/(n-1)*(w-p.l-p.r),y=v=>p.t+(max-v)/span*(h-p.t-p.b);
  for(let g=0;g<=4;g++){const value=min+span*g/4,yy=y(value);combinedCtx.beginPath();combinedCtx.moveTo(p.l,yy);combinedCtx.lineTo(w-p.r,yy);combinedCtx.stroke();combinedCtx.fillText(Math.round(value).toLocaleString(),4,yy+4);}
  for(const line of active){combinedCtx.strokeStyle=line.color;combinedCtx.lineWidth=2;combinedCtx.beginPath();let started=false;for(let i=0;i<line.values.length;i++){const value=line.values[i];if(!Number.isFinite(value)){started=false;continue;}if(!started){combinedCtx.moveTo(x(i),y(value));started=true;}else combinedCtx.lineTo(x(i),y(value));}combinedCtx.stroke();}
  combinedCtx.fillStyle='#929ba2';combinedCtx.fillText('0 min',p.l,h-9);combinedCtx.fillText((n-1)+' min',w-p.r-50,h-9);
  const summary=active.length+' series enabled · hover to inspect · '+n+' minute points';combinedNote.textContent=summary;
  combinedCanvas.onmousemove=e=>{const b=combinedCanvas.getBoundingClientRect(),i=Math.max(0,Math.min(n-1,Math.round((e.clientX-b.left-p.l)/(w-p.l-p.r)*(n-1))));const values=active.map(s=>Number.isFinite(s.values[i])?s.label+': '+s.values[i].toLocaleString():null).filter(Boolean);combinedNote.textContent='Minute '+i+' · '+(values.join(' | ')||'No values at this minute');};
  combinedCanvas.onmouseleave=()=>{combinedNote.textContent=summary;};
}
window.addEventListener('resize',drawCombined);drawCombined();
</script>
<?php endif; ?>
</main></body></html>
