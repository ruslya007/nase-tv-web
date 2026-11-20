<?php
/* Naše TV – serverový načítač RSS/Atom pro Programové studio
   - stahuje jen kanály z registru (feed_sources.php), zakázaná média blokuje
   - podmíněný dotaz (If-None-Match / If-Modified-Since), mezipaměť v ../data/feeds, interval podle registru
   - přístup: přihlášená relace studia, nebo klíč (?key=) pro plánovanou úlohu (cron) */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
const FEED_KEY_HASH='6066c842407cebe6339b8b2a0daa460ec8e1082fbb25999c2239fa752098c9df';
const MAX_BYTES=3145728;
$BLOCKED=['rt.com','sputniknews','sputnikglobe','ria.ru','iz.ru','rg.ru','voice-of-europe','voiceofeurope'];
function out($a,$code=200){http_response_code($code);echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$auth=!empty($_SESSION['ntv_ok'])||(isset($_GET['key'])&&hash_equals(FEED_KEY_HASH,hash('sha256',(string)$_GET['key'])));
session_write_close();
if(!$auth)out(['ok'=>false,'error'=>'nepřihlášeno'],403);
$SRC=require __DIR__.'/feed_sources.php';
if(isset($_GET['ping']))out(['ok'=>true,'sources'=>count($SRC),'curl'=>function_exists('curl_init')]);
$dir=__DIR__.'/../data/feedcache';
if(!is_dir($dir))@mkdir($dir,0750,true);
/* soubory mezipaměti jsou .php s úvodním exit – z webu je nikdo nepřečte, i když server ignoruje .htaccess */
const GUARD="<?php http_response_code(404);exit;?>\n";
function rd($f){if(!is_file($f))return null;$s=file_get_contents($f);return substr($s,strlen(GUARD));}
function wr($f,$s){file_put_contents($f,GUARD.$s,LOCK_EX);}
function blocked($u,$B){$h=strtolower(preg_replace('/^www\./','',(string)parse_url($u,PHP_URL_HOST)));foreach($B as $d){if($h===$d||str_ends_with($h,'.'.$d)||(strpos($d,'.')===false&&strpos($h,$d)!==false))return true;}return false;}
function fetch_one($id,$force,$SRC,$dir,$B){
  if(!isset($SRC[$id]))return ['ok'=>false,'id'=>$id,'error'=>'zdroj není v registru povolených kanálů'];
  [$url,$every]=$SRC[$id];
  if(blocked($url,$B))return ['ok'=>false,'id'=>$id,'error'=>'zakázané médium'];
  $mf="$dir/$id.meta.php"; $xf="$dir/$id.xml.php";
  $meta=json_decode((string)rd($mf),true)?:[];
  $age=isset($meta['fetched'])?time()-$meta['fetched']:PHP_INT_MAX;
  if(!$force&&is_file($xf)&&$age<$every*60)
    return ['ok'=>true,'id'=>$id,'status'=>'cache','http'=>$meta['http']??200,'fetched'=>gmdate('c',$meta['fetched']),'lastModified'=>$meta['lastmod']??null,'xml'=>rd($xf)];
  if(!$force&&!is_file($xf)&&!empty($meta['err'])&&$age<max(10,$every)*60) /* zdroj nedávno selhal – nezkoušet ho při každém volání */
    return ['ok'=>false,'id'=>$id,'http'=>$meta['http']??0,'error'=>$meta['err'].' (další pokus za '.ceil((max(10,$every)*60-$age)/60).' min)'];
  $h=['User-Agent: NaseTV-FeedBot/1.0 (nezavisly verejny portal; kontakt pres web)','Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.5'];
  if(is_file($xf)){if(!empty($meta['etag']))$h[]='If-None-Match: '.$meta['etag'];if(!empty($meta['lastmod']))$h[]='If-Modified-Since: '.$meta['lastmod'];}
  $resp=[];$c=curl_init($url);
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_HTTPHEADER=>$h,CURLOPT_ENCODING=>'',CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
    CURLOPT_NOPROGRESS=>false,CURLOPT_PROGRESSFUNCTION=>function($r,$dt,$d){return $d>MAX_BYTES?1:0;},
    CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$resp){$p=explode(':',$line,2);if(count($p)==2)$resp[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}]);
  $body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$err=curl_error($c);$final=curl_getinfo($c,CURLINFO_EFFECTIVE_URL);curl_close($c);
  if($final&&blocked($final,$B))return ['ok'=>false,'id'=>$id,'error'=>'přesměrování na zakázané médium'];
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
$force=!empty($_GET['force']);
if(isset($_GET['cron'])){ /* plánovaná úloha: obnoví mezipaměť všech kanálů, kterým uplynul interval */
  $r=['ok'=>true,'new'=>0,'unchanged'=>0,'cache'=>0,'errors'=>[]];@set_time_limit(60);ignore_user_abort(true);$t0=time();
  foreach($SRC as $id=>$x){if(time()-$t0>20)break;$o=fetch_one($id,false,$SRC,$dir,$BLOCKED);
    if(!$o['ok'])$r['errors'][$id]=$o['error'];else $r[$o['status']]=($r[$o['status']]??0)+1;}
  out($r);}
$id=preg_replace('/[^a-z0-9_-]/i','',(string)($_GET['id']??''));
if($id==='')out(['ok'=>false,'error'=>'chybí id'],400);
$o=fetch_one($id,$force,$SRC,$dir,$BLOCKED);
if(isset($_GET['meta']))unset($o['xml']);
out($o); /* chyby zdroje vracíme se stavem 200 a ok:false – ochrana hostingu by jinak odpověď nahradila vlastní stránkou */
