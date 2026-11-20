<?php
/* Naše TV – přehled vlastních RSS kanálů (agregátor zdrojů) */
require __DIR__.'/../lib/feeds.php';
$J=channels_index_fast();$B=base_url();
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
if(isset($_GET['opml'])){header('Content-Type: text/x-opml; charset=utf-8');header('Content-Disposition: attachment; filename="nase-tv-kanaly.opml"');
  echo '<?xml version="1.0" encoding="UTF-8"?><opml version="2.0"><head><title>Kanály Naší TV</title></head><body>';
  $g=null;foreach($CHANNELS as $k=>$c){if($c['g']!==$g){if($g!==null)echo '</outline>';$g=$c['g'];echo '<outline text="'.h($g).'">';}
    echo '<outline type="rss" text="'.h($c['n']).'" title="'.h('Naše TV – '.$c['n']).'" xmlUrl="'.h($B.'/zdroje/rss.php?k='.$k).'" htmlUrl="'.h($B.'/zdroje/').'"/>';}
  echo '</outline></body></opml>';exit;}
$built=$J['built']??time();$S=$J['sources']??[];
$ok=0;$err=0;foreach($S as $s){if(!empty($s['err'])&&empty($s['items']))$err++;else $ok++;}
function ago($t){$d=time()-$t;return $d<90?'před chvílí':($d<3600?'před '.round($d/60).' min':'před '.round($d/3600).' h');}
?><!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>RSS kanály Naší TV</title><meta name="description" content="Vlastní RSS kanály Naší TV – zprávy z ověřených zdrojů roztříděné podle témat a regionů.">
<link rel="alternate" type="application/rss+xml" title="Naše TV – Hlavní zprávy" href="<?=h($B)?>/zdroje/rss.php?k=hlavni">
<style>
:root{--paper:#F2F6F5;--card:#fff;--ink:#1E2440;--mut:#5A6380;--line:#DCE4E6;--blue:#3255A4;--pink:#FF5A8A;--yellow:#FFC93C;--green:#3E9A5B}
@media (prefers-color-scheme:dark){:root{--paper:#151A33;--card:#1E2547;--ink:#ECEFF8;--mut:#A4ACC8;--line:#2C355E;--blue:#8EA8EA}}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:17px/1.55 "Atkinson Hyperlegible",system-ui,-apple-system,"Segoe UI",sans-serif}
h1,h2,h3{font-family:"Bricolage Grotesque","Avenir Next","Segoe UI",system-ui,sans-serif;letter-spacing:-.01em;margin:0}
a{color:var(--blue)}.wrap{max-width:1080px;margin:0 auto;padding:0 20px}
header{padding:56px 0 30px}header .home{font-weight:800;text-decoration:none;color:var(--ink)}
h1{font-size:clamp(38px,6vw,68px);line-height:1;margin:22px 0 14px}.lead{max-width:62ch;font-size:1.1em;margin:0}
.meta{color:var(--mut);font-size:.9em;margin-top:14px}.meta b{color:var(--ink)}
.group{margin:46px 0}.group h2{font-size:1.6em;margin-bottom:14px}
.ch{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,360px);gap:10px 26px;background:var(--card);border-radius:8px;padding:16px 18px;margin-bottom:10px;border-left:6px solid var(--c,var(--blue))}
.ch h3{font-size:1.25em}.ch p{margin:4px 0 8px;color:var(--mut);font-size:.95em}
.ch ul{margin:0;padding-left:18px;font-size:.93em}.ch li{margin:2px 0}
.url{display:flex;gap:6px;align-items:center}.url input{flex:1;min-width:0;font:13px/1.2 ui-monospace,Menlo,Consolas,monospace;padding:8px 9px;border:1px solid var(--line);border-radius:6px;background:var(--paper);color:var(--ink)}
.url button,.btn{font:inherit;font-size:.9em;font-weight:700;border:0;border-radius:999px;padding:7px 14px;background:var(--ink);color:var(--paper);cursor:pointer;text-decoration:none;display:inline-block}
.side{display:flex;flex-direction:column;gap:8px;justify-content:center}.n{font-size:.9em;color:var(--mut)}.n b{color:var(--ink);font-size:1.15em}
.empty{color:var(--mut);font-style:italic;font-size:.93em}
table{width:100%;border-collapse:collapse;font-size:.9em;background:var(--card);border-radius:8px;overflow:hidden}th,td{text-align:left;padding:7px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--mut);font-weight:700}
.st-ok{color:var(--green);font-weight:700}.st-err{color:#C8364F;font-weight:700}.tag{font-size:.8em;background:color-mix(in srgb,var(--pink) 18%,transparent);border-radius:4px;padding:1px 6px}
.rules{background:var(--card);border-radius:8px;padding:16px 20px;margin:46px 0}.rules li{margin:5px 0}
footer{padding:30px 0 50px;color:var(--mut);font-size:.9em}
:focus-visible{outline:3px solid var(--pink);outline-offset:2px}
@media(max-width:760px){.ch{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<header><a class="home" href="../">← Naše TV</a>
<h1>RSS kanály Naší TV</h1>
<p class="lead">Zprávy z <?=count($REG)?> ověřených zdrojů z registru, roztříděné do vlastních kanálů. Stejnou zprávu z více médií sloučíme do jedné a uvedeme, kolik zdrojů ji potvrzuje. Každá položka odkazuje na originál.</p>
<p class="meta">Aktualizováno <b><?=h(date('j. n. Y H:i',$built))?></b> · <?=intval($J['clusters']??0)?> zpráv za posledních <?=KEEP_HOURS?> hodin · zdroje v pořádku: <?=$ok?>, s chybou: <?=$err?> · <a href="?opml=1">Všechny kanály jako OPML</a></p>
</header>
<main>
<?php $colors=['Zprávy'=>'#3255A4','Svět podle regionů'=>'#1F8A8A','Témata'=>'#6B4FBB'];$g=null;
foreach($CHANNELS as $k=>$c){ if($c['g']!==$g){ if($g!==null)echo '</section>'; $g=$c['g']; echo '<section class="group"><h2>'.h($g).'</h2>'; }
  $info=$J['channels'][$k]??['n'=>0,'top'=>[],'srcs'=>[]];$url=$B.'/zdroje/rss.php?k='.$k; ?>
  <article class="ch" style="--c:<?=$colors[$c['g']]??'#3255A4'?>">
    <div><h3><?=h($c['n'])?></h3><p><?=h($c['d'])?></p>
      <?php if($info['top']){echo '<ul>';foreach($info['top'] as $t)echo '<li>'.h($t).'</li>';echo '</ul>';} else echo '<div class="empty">Kanál zatím nemá zprávy – zdroje se načítají.</div>'; ?></div>
    <div class="side"><div class="n"><b><?=intval($info['n'])?></b> zpráv z <?=count($info['srcs'])?> zdrojů</div>
      <div class="url"><input readonly value="<?=h($url)?>" aria-label="Adresa RSS kanálu <?=h($c['n'])?>"><button type="button" data-copy>Kopírovat</button></div>
      <div><a class="btn" href="<?=h($url)?>">Otevřít kanál</a></div></div>
  </article>
<?php } if($g!==null)echo '</section>'; ?>
<section class="rules"><h2>Pravidla agregátoru</h2><ul>
<li>Přebíráme jen titulek, krátký úryvek a odkaz na originál. Celý článek čtete u zdroje.</li>
<li>Média řízená státem jsou v kanálu označená (<code>ntv:source state="1"</code>) a ve vysílání je doplňujeme o další pohled.</li>
<li>Média zakázaná v EU nestahujeme – blokuje je i server.</li>
<li>Zdroje se obnovují podle intervalu v registru (nejčastěji 15 minut), kanály se přestavují nejvýš jednou za <?=CHANNEL_TTL/60?> minut.</li></ul></section>
<section class="group"><h2>Zdroje v registru</h2><div style="overflow-x:auto"><table><thead><tr><th>Zdroj</th><th>Vydavatel</th><th>Region</th><th>Jazyk</th><th>Vlastník</th><th>Zpráv (<?=KEEP_HOURS?> h)</th><th>Stav</th></tr></thead><tbody>
<?php foreach($REG as $id=>$r){$s=$S[$id]??[];$bad=!empty($s['err'])&&empty($s['items']);
  echo '<tr><td><b>'.h($r['name']).'</b></td><td>'.h($r['pub']).'</td><td>'.h($r['region']).'</td><td>'.h($r['lang']).'</td><td>'.h($r['owner']).(mb_stripos($r['owner'],'stát')!==false?' <span class="tag">označeno</span>':'').'</td><td>'.intval($s['items']??0).'</td><td>'.
  ($bad?'<span class="st-err" title="'.h($s['err']).'">nedostupný</span>':(!empty($s['fetched'])?'<span class="st-ok">v pořádku</span> <span class="n">'.h(ago($s['fetched'])).'</span>':'<span class="n">čeká</span>')).'</td></tr>';} ?>
</tbody></table></div></section>
</main>
<footer>Agregátor zdrojů Naše TV · kanály ve formátu RSS 2.0 s rozšířením <code><?=h(NTV_NS)?></code> (zdroj, důvěryhodnost, vlastník, počet potvrzení).</footer>
</div>
<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-copy]");if(!b)return;var i=b.previousElementSibling;i.select();
try{navigator.clipboard.writeText(i.value).then(function(){b.textContent="Zkopírováno";setTimeout(function(){b.textContent="Kopírovat";},1500);});}catch(err){document.execCommand("copy");}});</script>
</body></html>
<?php finish_and_rebuild(); ?>
