<?php
/* Naše TV – Agregátor zdrojů: stahování kanálů z registru, mezipaměť, rozbor RSS/Atom/RDF, slučování duplicit a výstup našich kanálů.
   Používají ho: studio/feed.php (načítač pro studio), zdroje/rss.php (naše RSS kanály), zdroje/index.php (přehled). */
if(basename($_SERVER['SCRIPT_FILENAME']??'')===basename(__FILE__)){http_response_code(404);exit;}
const NTV_NS='https://libertv.cz/ns/ntv';
const MAX_BYTES=3145728;
const GUARD="<?php http_response_code(404);exit;?>\n";
const CHANNEL_TTL=600;     /* jak často se kanály přestavují (s) */
const KEEP_HOURS=48;       /* jak staré zprávy kanály obsahují */
$BLOCKED=['rt.com','sputniknews','sputnikglobe','ria.ru','iz.ru','rg.ru','voice-of-europe','voiceofeurope'];
$REG=is_file(__DIR__.'/registry.local.php')?require __DIR__.'/registry.local.php':require __DIR__.'/registry.php';
$DATA=__DIR__.'/../data';
@mkdir("$DATA/feedcache",0750,true);@mkdir("$DATA/channels",0750,true);

/* ---------- naše kanály ---------- */
$KW=[
 'region'=>['liberec','jablon','česká lípa','české lípě','semil','turnov','frýdlant','ještěd','jizer','tanvald','nový bor','hrádek nad nisou','chrastav','raspenav','liberecký kraj','libereckém kraji'],
 'sport'=>['hokej','fotbal','extralig','nhl','tipsport','utkání','zápas','olympi','tenis','biatlon','mistrovství světa','reprezent','bílí tygři','slovan liberec','gól','trenér','hockey','football','soccer','match','championship','tournament','league'],
 'zdravi'=>['nemocnic','lékař','zdravot','očkov','súkl','pacient','chřipk','epidemi','léky','léčiv','hospital','health','vaccine','virus','ambulanc','záchrank','zzs'],
 'pocasi'=>['výstrah','počasí','sníh','vichřic','povod','čhmú','mráz','náledí','bouř','vítr','teplot','smog','weather','storm','flood'],
 'ekonomika'=>['inflac','hdp','koruna','ekonomik','rozpočet','daň','daně','ceny','mzd','úrok','čnb','burz','akci','firm','market','stocks','economy','inflation','gdp','shares','earnings'],
 'sachy'=>['šach','chess','fide','grandmaster','velmistr'],
];
$CHANNELS=[
 'hlavni'=>['g'=>'Zprávy','n'=>'Hlavní zprávy','d'=>'Nejdůležitější zprávy z Česka a kraje – řazené podle toho, kolik nezávislých zdrojů je potvrzuje.','rule'=>'top'],
 'domov'=>['g'=>'Zprávy','n'=>'Z domova','d'=>'Zprávy z Česka od veřejnoprávních i soukromých médií.','cats'=>['zpravy-cr'],'langs'=>['cs']],
 'liberecko'=>['g'=>'Zprávy','n'=>'Liberecký kraj','d'=>'Liberec, Jablonec, Českolipsko, Semilsko a Jizerské hory.','cats'=>['region'],'kw'=>'region','langs'=>['cs']],
 'svet'=>['g'=>'Zprávy','n'=>'Ze světa','d'=>'Zahraniční zpravodajství v původním jazyce zdroje.','cats'=>['svet']],
 'evropa'=>['g'=>'Svět podle regionů','n'=>'Evropa','d'=>'Německo, Francie, Velká Británie a evropské instituce.','regions'=>['Evropa','Německo','Francie','Velká Británie']],
 'usa'=>['g'=>'Svět podle regionů','n'=>'USA','d'=>'Americké agentury, deníky a rozhlas.','regions'=>['USA']],
 'vychod'=>['g'=>'Svět podle regionů','n'=>'Ukrajina a Rusko','d'=>'Ukrajinská a ruská média – státem řízená vždy označená.','regions'=>['Ukrajina','Rusko']],
 'asie'=>['g'=>'Svět podle regionů','n'=>'Asie a Blízký východ','d'=>'Japonsko, Korea, Čína, jihovýchodní Asie a arabský svět.','regions'=>['Japonsko','Čína','Jižní Korea','Jihovýchodní Asie','Arabský svět']],
 'ekonomika'=>['g'=>'Témata','n'=>'Ekonomika a trhy','d'=>'Ekonomika, trhy, ceny a firmy.','cats'=>['ekonomika'],'kw'=>'ekonomika'],
 'sport'=>['g'=>'Témata','n'=>'Sport','d'=>'Hokej, fotbal a další sporty, doma i ve světě.','cats'=>['sport'],'kw'=>'sport'],
 'zdravi'=>['g'=>'Témata','n'=>'Zdraví','d'=>'Zdravotnictví, léky a prevence.','cats'=>['zdravi'],'kw'=>'zdravi'],
 'pocasi'=>['g'=>'Témata','n'=>'Počasí a varování','d'=>'Výstrahy, počasí a doprava v zimě.','cats'=>['pocasi'],'kw'=>'pocasi','langs'=>['cs']],
 'sachy'=>['g'=>'Témata','n'=>'Šachy','d'=>'Turnaje, partie a šachový svět.','cats'=>['sachy'],'kw'=>'sachy'],
];

/* ---------- pomocné ---------- */
function rd($f){if(!is_file($f))return null;$s=file_get_contents($f);return substr($s,strlen(GUARD));}
function wr($f,$s){$t=$f.'.tmp'.getmypid();file_put_contents($t,GUARD.$s,LOCK_EX);rename($t,$f);}
function blocked($u,$B){$h=strtolower(preg_replace('/^www\./','',(string)parse_url($u,PHP_URL_HOST)));foreach($B as $d){if($h===$d||str_ends_with($h,'.'.$d)||(strpos($d,'.')===false&&strpos($h,$d)!==false))return true;}return false;}
function x($s){return htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');}
function base_url(){$s=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https'?'https':'http';
  $h=preg_replace('/[^a-z0-9.:-]/i','',$_SERVER['HTTP_HOST']??'localhost');return $s.'://'.$h;}

/* ---------- stažení jednoho zdroje (podmíněný dotaz, mezipaměť, útlum při chybě) ---------- */
function fetch_one($id,$force=false){
  global $REG,$BLOCKED,$DATA;$dir="$DATA/feedcache";
  if(!isset($REG[$id]))return ['ok'=>false,'id'=>$id,'error'=>'zdroj není v registru povolených kanálů'];
  $url=$REG[$id]['url'];$every=$REG[$id]['every'];
  if(blocked($url,$BLOCKED))return ['ok'=>false,'id'=>$id,'error'=>'zakázané médium'];
  $mf="$dir/$id.meta.php"; $xf="$dir/$id.xml.php";
  $meta=json_decode((string)rd($mf),true)?:[];
  $age=isset($meta['fetched'])?time()-$meta['fetched']:PHP_INT_MAX;
  if(!$force&&is_file($xf)&&$age<$every*60)
    return ['ok'=>true,'id'=>$id,'status'=>'cache','http'=>$meta['http']??200,'fetched'=>gmdate('c',$meta['fetched']),'lastModified'=>$meta['lastmod']??null,'xml'=>rd($xf)];
  if(!$force&&!is_file($xf)&&!empty($meta['err'])&&$age<max(10,$every)*60)
    return ['ok'=>false,'id'=>$id,'http'=>$meta['http']??0,'error'=>$meta['err'].' (další pokus za '.ceil((max(10,$every)*60-$age)/60).' min)'];
  $h=['User-Agent: NaseTV-FeedBot/1.0 (nezavisly verejny portal; kontakt pres web)','Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5'];
  if(is_file($xf)){if(!empty($meta['etag']))$h[]='If-None-Match: '.$meta['etag'];if(!empty($meta['lastmod']))$h[]='If-Modified-Since: '.$meta['lastmod'];}
  $resp=[];$c=curl_init($url);
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_HTTPHEADER=>$h,CURLOPT_ENCODING=>'',CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
    CURLOPT_NOPROGRESS=>false,CURLOPT_PROGRESSFUNCTION=>function($r,$dt,$d){return $d>MAX_BYTES?1:0;},
    CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$resp){$p=explode(':',$line,2);if(count($p)==2)$resp[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);
  $body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$err=curl_error($c);$final=curl_getinfo($c,CURLINFO_EFFECTIVE_URL);curl_close($c);
  if($final&&blocked($final,$BLOCKED))return ['ok'=>false,'id'=>$id,'error'=>'přesměrování na zakázané médium'];
  $meta['fetched']=time();$meta['http']=$code;
  if($code==304&&is_file($xf)){wr($mf,json_encode($meta));
    return ['ok'=>true,'id'=>$id,'status'=>'unchanged','http'=>304,'fetched'=>gmdate('c'),'lastModified'=>$meta['lastmod']??null,'xml'=>rd($xf)];}
  if($body===false||$code<200||$code>=300){$meta['err']=$err?:('HTTP '.$code);wr($mf,json_encode($meta));
    $o=['ok'=>false,'id'=>$id,'http'=>$code,'error'=>$meta['err']];
    if(is_file($xf)){$o['ok']=true;$o['status']='cache';$o['stale']=true;$o['xml']=rd($xf);$o['lastModified']=$meta['lastmod']??null;}
    return $o;}
  if(!preg_match('/<([a-z0-9]+:)?(rss|feed|RDF|alert)[\s>]/i',substr($body,0,4000))){$meta['err']='odpověď není RSS/Atom';wr($mf,json_encode($meta));return ['ok'=>false,'id'=>$id,'http'=>$code,'error'=>$meta['err']];}
  unset($meta['err']);$meta['etag']=$resp['etag']??null;$meta['lastmod']=$resp['last-modified']??null;
  wr($xf,$body);wr($mf,json_encode($meta));
  return ['ok'=>true,'id'=>$id,'status'=>'new','http'=>$code,'fetched'=>gmdate('c'),'lastModified'=>$meta['lastmod'],'xml'=>$body];
}
/* obnoví zdroje, kterým uplynul interval – nejvýš $budget sekund (pomalé zdroje dojdou příště) */
function refresh_stale($budget=15){global $REG,$DATA;$t0=microtime(true);$n=0;
  /* nejdřív ty, které jsou nejdéle nenačtené – ať se na pomalé konce seznamu dostane řada */
  $age=[];foreach($REG as $id=>$r){$m=json_decode((string)rd("$DATA/feedcache/$id.meta.php"),true)?:[];$age[$id]=$m['fetched']??0;}asort($age);
  foreach(array_keys($age) as $id){if(microtime(true)-$t0>$budget)break;$o=fetch_one($id);if(($o['status']??'')==='new'||($o['status']??'')==='unchanged')$n++;}return $n;}

/* ---------- rozbor RSS / Atom / RDF ---------- */
function clean_text($s,$max=320){$s=html_entity_decode(strip_tags(html_entity_decode((string)$s,ENT_QUOTES|ENT_HTML5,'UTF-8')),ENT_QUOTES|ENT_HTML5,'UTF-8'); /* některé zdroje kódují entity dvakrát */$s=trim(preg_replace('/\s+/u',' ',$s));
  if(mb_strlen($s)>$max){$s=mb_substr($s,0,$max);$s=preg_replace('/\s+\S*$/u','',$s).'…';}return $s;}
function child($n,$names){foreach($n->childNodes as $c){if($c->nodeType===1&&in_array($c->localName,$names,true))return $c;}return null;}
function parse_items($xml){
  $out=[];if(!$xml)return $out;$d=new DOMDocument();
  if(!@$d->loadXML($xml,LIBXML_NONET|LIBXML_NOCDATA|LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT))return $out;
  $nodes=[];foreach(['item','entry'] as $t)foreach($d->getElementsByTagNameNS('*',$t) as $n)$nodes[]=$n;
  if(!$nodes)foreach(['item','entry'] as $t)foreach($d->getElementsByTagName($t) as $n)$nodes[]=$n;
  foreach(array_slice($nodes,0,80) as $n){
    $t=child($n,['title']);$title=$t?clean_text($t->textContent,240):'';if($title==='')continue;
    $link='';foreach($n->childNodes as $c){if($c->nodeType===1&&$c->localName==='link'){$href=$c->getAttribute('href');$rel=$c->getAttribute('rel');
        if($href&&($rel===''||$rel==='alternate')){$link=$href;break;}if(!$href&&trim($c->textContent)){$link=trim($c->textContent);break;}}}
    if(!$link&&($g=child($n,['guid']))&&preg_match('#^https?://#',trim($g->textContent)))$link=trim($g->textContent);
    $ds=child($n,['pubDate','published','updated','date']);$ts=$ds?strtotime(trim($ds->textContent)):false;
    $de=child($n,['description','summary','encoded','content']);
    $out[]=['title'=>$title,'link'=>$link,'desc'=>$de?clean_text($de->textContent):'','ts'=>$ts?:null];
  }
  return $out;
}

/* ---------- slučování stejných zpráv z různých zdrojů ---------- */
function norm_tokens($s){$s=mb_strtolower($s,'UTF-8');
  $s=strtr($s,['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','ä'=>'a','ö'=>'o','ü'=>'u','ľ'=>'l','ĺ'=>'l','ô'=>'o']);
  $w=preg_split('/[^\p{L}\p{N}]+/u',$s,-1,PREG_SPLIT_NO_EMPTY);$t=[];
  foreach($w as $x){if(mb_strlen($x)<4)continue;$t[mb_substr($x,0,6)]=1;} /* kmen ~ prvních 6 znaků (pády, časy) */
  return array_keys($t);}
function kw_hit($text,$list){$t=mb_strtolower($text,'UTF-8');foreach($list as $k)if(mb_strpos($t,$k)!==false)return true;return false;}

/* ---------- sestavení všech našich kanálů ---------- */
function build_channels($refreshBudget=15){
  global $REG,$CHANNELS,$KW,$DATA;
  $lock=fopen("$DATA/channels/.lock",'c');if(!flock($lock,LOCK_EX|LOCK_NB)){return false;} /* právě staví jiný požadavek */
  @set_time_limit(60);ignore_user_abort(true);
  $refreshed=$refreshBudget>0?refresh_stale($refreshBudget):0;
  $now=time();$min=$now-KEEP_HOURS*3600;$items=[];$srcStat=[];
  foreach($REG as $id=>$r){$xml=rd("$DATA/feedcache/$id.xml.php");$meta=json_decode((string)rd("$DATA/feedcache/$id.meta.php"),true)?:[];
    $L=parse_items($xml);$cnt=0;
    foreach($L as $it){$ts=$it['ts']??$meta['fetched']??$now;if($ts<$min||$ts>$now+3600)continue;$it['ts']=min($ts,$now);$it['src']=$id;$it['tok']=norm_tokens($it['title']);$items[]=$it;$cnt++;}
    $srcStat[$id]=['items'=>$cnt,'fetched'=>$meta['fetched']??null,'err'=>$meta['err']??null];}
  usort($items,function($a,$b){return $b['ts']<=>$a['ts'];});
  /* shluky: stejný jazyk, sdílí ≥3 kmeny a Jaccard ≥ 0,45 */
  $cl=[];$idx=[];
  foreach($items as $it){$lang=$REG[$it['src']]['lang'];$cand=[];
    foreach($it['tok'] as $k)foreach($idx[$lang.'|'.$k]??[] as $ci)$cand[$ci]=($cand[$ci]??0)+1;
    $best=-1;$bs=0;foreach($cand as $ci=>$sh){if($sh<3)continue;$u=count($it['tok'])+count($cl[$ci]['tok'])-$sh;$j=$u?$sh/$u:0;if($j>=0.45&&$j>$bs){$bs=$j;$best=$ci;}}
    if($best>=0){$c=&$cl[$best];if(!in_array($it['src'],$c['srcs'],true)){$c['srcs'][]=$it['src'];$c['links'][$it['src']]=$it['link'];}
      if($REG[$it['src']]['trust']>$REG[$c['main']]['trust']){$c['main']=$it['src'];$c['title']=$it['title'];$c['link']=$it['link'];if($it['desc'])$c['desc']=$it['desc'];}
      $c['first']=min($c['first'],$it['ts']);$c['last']=max($c['last'],$it['ts']);unset($c);continue;}
    $ci=count($cl);$cl[]=['title'=>$it['title'],'link'=>$it['link'],'desc'=>$it['desc'],'main'=>$it['src'],'srcs'=>[$it['src']],'links'=>[$it['src']=>$it['link']],'first'=>$it['ts'],'last'=>$it['ts'],'tok'=>$it['tok'],'lang'=>$lang];
    foreach($it['tok'] as $k)$idx[$lang.'|'.$k][]=$ci;}
  /* zařazení do kanálů */
  $pubs=function($c)use($REG){$p=[];foreach($c['srcs'] as $s)$p[$REG[$s]['pub']?:$REG[$s]['name']]=1;return count($p);};
  $out=[];
  foreach($CHANNELS as $k=>$ch){$sel=[];
    foreach($cl as $c){$m=$REG[$c['main']];$text=$c['title'].' '.$c['desc'];$hit=false;
      if(($ch['rule']??'')==='top'){ if($c['lang']==='cs'&&in_array($m['cat'],['zpravy-cr','region'],true)&&$now-$c['last']<18*3600)$hit=true; }
      else{ foreach($c['srcs'] as $s){$r=$REG[$s];if(in_array($r['cat'],$ch['cats']??[],true)||in_array($r['region'],$ch['regions']??[],true)){$hit=true;break;}}
        if(!$hit&&!empty($ch['kw'])&&kw_hit($text,$KW[$ch['kw']]))$hit=true;
        if($hit&&!empty($ch['langs'])&&!in_array($c['lang'],$ch['langs'],true))$hit=false;
        if($hit&&$k==='liberecko'&&$m['cat']!=='region'&&!kw_hit($text,$KW['region']))$hit=false;}
      if($hit)$sel[]=$c;}
    if(($ch['rule']??'')==='top')usort($sel,function($a,$b)use($pubs,$REG,$now){$sa=$pubs($a)*3+$REG[$a['main']]['trust']-($now-$a['last'])/10800;$sb=$pubs($b)*3+$REG[$b['main']]['trust']-($now-$b['last'])/10800;return $sb<=>$sa;});
    else usort($sel,function($a,$b){return $b['last']<=>$a['last'];});
    $sel=array_slice($sel,0,($ch['rule']??'')==='top'?30:60);
    $out[$k]=['n'=>count($sel),'top'=>array_map(function($c){return $c['title'];},array_slice($sel,0,3)),'srcs'=>[]];
    foreach($sel as $c)foreach($c['srcs'] as $s)$out[$k]['srcs'][$s]=1;$out[$k]['srcs']=array_keys($out[$k]['srcs']);
    wr("$DATA/channels/$k.xml.php",channel_xml($k,$ch,$sel,$pubs));}
  wr("$DATA/channels/index.json.php",json_encode(['built'=>$now,'refreshed'=>$refreshed,'items'=>count($items),'clusters'=>count($cl),'channels'=>$out,'sources'=>$srcStat],JSON_UNESCAPED_UNICODE));
  flock($lock,LOCK_UN);return true;
}
function channel_xml($k,$ch,$sel,$pubs){global $REG;$b=base_url();
  $s='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:ntv="'.NTV_NS.'"><channel>';
  $s.='<title>'.x('Naše TV – '.$ch['n']).'</title><link>'.x($b.'/zdroje/').'</link><description>'.x($ch['d']).'</description><language>cs</language>';
  $s.='<atom:link href="'.x($b.'/zdroje/rss.php?k='.$k).'" rel="self" type="application/rss+xml"/><lastBuildDate>'.gmdate(DATE_RSS).'</lastBuildDate><ttl>10</ttl>';
  $s.='<generator>Agregátor zdrojů Naše TV</generator>';
  foreach($sel as $c){$m=$REG[$c['main']];$also=[];foreach($c['srcs'] as $sid)if($sid!==$c['main'])$also[]=$REG[$sid]['name'];
    $state=(mb_stripos($m['owner'],'stát')!==false)?1:0;
    $s.='<item><title>'.x($c['title']).'</title><link>'.x($c['link']).'</link>';
    if($c['desc'])$s.='<description>'.x($c['desc']).'</description>';
    $s.='<pubDate>'.gmdate(DATE_RSS,$c['last']).'</pubDate><guid isPermaLink="false">ntv-'.substr(md5($c['main'].'|'.$c['title']),0,16).'</guid>';
    $s.='<category>'.x($ch['n']).'</category><source url="'.x($m['url']).'">'.x($m['name']).'</source>';
    $s.='<ntv:source id="'.x($c['main']).'" trust="'.$m['trust'].'" owner="'.x($m['owner']).'" lang="'.x($c['lang']).'" region="'.x($m['region']).'" state="'.$state.'"/>';
    $s.='<ntv:confirmed>'.$pubs($c).'</ntv:confirmed>';
    if($also)$s.='<ntv:also>'.x(implode(', ',$also)).'</ntv:also>';
    $s.='</item>';}
  return $s.'</channel></rss>';
}
function channels_index($maxAge=CHANNEL_TTL,$budget=15){global $DATA;$f="$DATA/channels/index.json.php";$j=json_decode((string)rd($f),true);
  if(!$j||time()-($j['built']??0)>$maxAge){build_channels($budget);$j=json_decode((string)rd($f),true);}return $j?:[];}
/* veřejné stránky: hned vrátí poslední stav a kanály přestaví až po odeslání odpovědi (návštěvník nečeká) */
$NTV_LATE=false;
function channels_index_fast(){global $DATA,$NTV_LATE;$j=json_decode((string)rd("$DATA/channels/index.json.php"),true);
  if(!$j)return channels_index(CHANNEL_TTL,8);
  if(time()-($j['built']??0)>CHANNEL_TTL)$NTV_LATE=true;return $j;}
function finish_and_rebuild(){global $NTV_LATE;if(!$NTV_LATE)return;
  if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();elseif(function_exists('litespeed_finish_request'))litespeed_finish_request();else{@ob_end_flush();flush();}
  ignore_user_abort(true);build_channels(25);}
