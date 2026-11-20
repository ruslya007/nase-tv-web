<?php
/* Naše TV – vysílací API
   Studio zveřejní schválené scénáře (text), domácí výrobna k nim doplní hlas (MP3 s časy slov) a překlady,
   divácká stránka je přehraje. Chyby vracíme se stavem 200 a ok:false (ochrana hostingu by jinak odpověď nahradila).

   ?a=seznam            veřejné – zveřejněné položky posledních 48 h
   ?a=zvuk&id=          veřejné – MP3 hlasu
   ?a=publikuj   POST   studio (přihlášená relace) – {items:[{title,text,c,p,t,src,host,brk,mt}]}
   ?a=smaz       POST   studio – {id}
   ?a=stav              studio – přehled pro pult
   ?a=ukoly             výrobna (hlavička X-NTV-Key) – co chybí: &hlasy=marek,ondrej&jazyky=en,de
   ?a=hlas       POST   výrobna – {id, mp3 (base64), dur, words:[[slovo,čas],…], voice}
   ?a=preklad    POST   výrobna – {id, lang, title, text, engine}
*/
header('X-Robots-Tag: noindex');
const VYR_KEY_HASH='3756e8b573b3baba29a16ae38aa36008fb787d5e33473736b8686eda7ec2ea36';
const GUARD="<?php http_response_code(404);exit;?>\n";
const KEEP_DAYS=7, PUBLIC_HOURS=48, MAX_ITEMS=600, MAX_MP3=4194304;
$D=__DIR__.'/../data/vysilani';
if(!is_dir("$D/hlas"))@mkdir("$D/hlas",0755,true);

function out($a,$cache='no-store'){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: '.$cache);echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function fail($msg){out(['ok'=>false,'error'=>$msg]);}
function rd($f){if(!is_file($f))return null;return substr(file_get_contents($f),strlen(GUARD));}
function wr($f,$s){$t=$f.'.tmp'.getmypid();file_put_contents($t,GUARD.$s,LOCK_EX);rename($t,$f);}
function body(){$j=json_decode((string)file_get_contents('php://input'),true);return is_array($j)?$j:[];}
function clean($s,$max){$s=trim(preg_replace('/\s+/u',' ',strip_tags((string)$s)));return mb_substr($s,0,$max,'UTF-8');}
function slug($s,$max=24){return substr(preg_replace('/[^a-z0-9_-]/i','',(string)$s),0,$max);}

/* index: celé pole položek v jednom souboru; zápis pod zámkem */
function with_index($fn){global $D;$lock=fopen("$D/index.lock",'c');flock($lock,LOCK_EX);
  $idx=json_decode((string)rd("$D/index.json.php"),true);if(!is_array($idx))$idx=['items'=>[],'vyrobna'=>null];
  $r=$fn($idx);if($r!==false){$cut=time()-KEEP_DAYS*86400;
    foreach($idx['items'] as $id=>$it){if($it['ts']<$cut){unset($idx['items'][$id]);@unlink("$D/hlas/$id.mp3.php");}}
    if(count($idx['items'])>MAX_ITEMS){uasort($idx['items'],function($a,$b){return $b['ts']<=>$a['ts'];});
      foreach(array_slice(array_keys($idx['items']),MAX_ITEMS) as $id){unset($idx['items'][$id]);@unlink("$D/hlas/$id.mp3.php");}}
    wr("$D/index.json.php",json_encode($idx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
  flock($lock,LOCK_UN);fclose($lock);return $r;}
function read_index(){global $D;$idx=json_decode((string)rd("$D/index.json.php"),true);return is_array($idx)?$idx:['items'=>[],'vyrobna'=>null];}

$a=(string)($_GET['a']??'seznam');

/* ---------- veřejné ---------- */
if($a==='seznam'){
  $idx=read_index();$cut=time()-PUBLIC_HOURS*3600;$L=[];
  foreach($idx['items'] as $it){if($it['ts']<$cut)continue;
    $o=['id'=>$it['id'],'ts'=>$it['ts'],'c'=>$it['c'],'p'=>$it['p'],'t'=>$it['t'],'title'=>$it['title'],'text'=>$it['text'],'src'=>$it['src'],'host'=>$it['host']];
    if(!empty($it['brk']))$o['brk']=1;if(!empty($it['mt']))$o['mt']=$it['mt'];
    if(!empty($it['audio']))$o['audio']=['url'=>'api/vysilani.php?a=zvuk&id='.$it['id'].'&v='.$it['audio']['v'],'dur'=>$it['audio']['dur'],'words'=>$it['audio']['words']];
    if(!empty($it['tr']))$o['tr']=$it['tr'];
    $L[]=$o;}
  usort($L,function($x,$y){return $y['ts']<=>$x['ts'];});
  out(['ok'=>true,'now'=>time(),'items'=>array_slice($L,0,80)],'public, max-age=60');
}
if($a==='zvuk'){
  $id=slug($_GET['id']??'');$f="$D/hlas/$id.mp3.php";
  if($id===''||!is_file($f)){http_response_code(404);exit;}
  $off=strlen(GUARD);$len=filesize($f)-$off;$start=0;$end=$len-1;
  header('Content-Type: audio/mpeg');header('Accept-Ranges: bytes');header('Cache-Control: public, max-age=604800, immutable');header('Access-Control-Allow-Origin: *');
  if(preg_match('/bytes=(\d*)-(\d*)/',(string)($_SERVER['HTTP_RANGE']??''),$m)){
    if($m[1]!==''){$start=(int)$m[1];if($m[2]!=='')$end=min($end,(int)$m[2]);}elseif($m[2]!==''){$start=max(0,$len-(int)$m[2]);}
    if($start>$end){http_response_code(416);header("Content-Range: bytes */$len");exit;}
    http_response_code(206);header("Content-Range: bytes $start-$end/$len");}
  header('Content-Length: '.($end-$start+1));
  $h=fopen($f,'rb');fseek($h,$off+$start);$left=$end-$start+1;while($left>0&&!feof($h)){$c=fread($h,min(65536,$left));echo $c;$left-=strlen($c);}fclose($h);exit;
}

/* ---------- přihlášení: relace studia nebo klíč výrobny ---------- */
$key=(string)($_SERVER['HTTP_X_NTV_KEY']??'');
$vyr=$key!==''&&hash_equals(VYR_KEY_HASH,hash('sha256',$key));
$studio=false;if(!$vyr){session_start();$studio=!empty($_SESSION['ntv_ok']);session_write_close();}

/* ---------- studio ---------- */
if($a==='publikuj'){
  if(!$studio)fail('Publikovat může jen přihlášené studio.');
  $in=body();$items=array_slice((array)($in['items']??[]),0,80);if(!$items)fail('Žádné položky.');
  $C=['domaci','zahranici','region','zdravi','ekonomika','kultura','sport','pocasi','ostatni'];
  $n=0;$new=0;
  with_index(function(&$idx)use($items,$C,&$n,&$new){
    foreach($items as $x){$title=clean($x['title']??'',300);$text=clean($x['text']??'',3000);if($title==='')continue;
      $host=slug($x['host']??'tereza',20)?:'tereza';$id=substr(sha1($title."\n".$text."\n".$host),0,16);
      $c=in_array($x['c']??'',$C,true)?$x['c']:'ostatni';
      $old=$idx['items'][$id]??null;if(!$old)$new++;
      $idx['items'][$id]=['id'=>$id,'ts'=>$old['ts']??time(),'c'=>$c,'p'=>slug($x['p']??'info',16)?:'info','t'=>slug($x['t']??'neutral',16)?:'neutral',
        'title'=>$title,'text'=>$text,'src'=>clean($x['src']??'',300),'host'=>$host,'brk'=>!empty($x['brk']),'mt'=>clean($x['mt']??'',40),
        'audio'=>$old['audio']??null,'tr'=>$old['tr']??new stdClass()];$n++;}
  });
  out(['ok'=>true,'n'=>$n,'new'=>$new]);
}
if($a==='smaz'){
  if(!$studio)fail('Jen pro studio.');$id=slug(body()['id']??'');
  with_index(function(&$idx)use($id,$D){unset($idx['items'][$id]);@unlink("$D/hlas/$id.mp3.php");});out(['ok'=>true]);
}
if($a==='stav'){
  if(!$studio&&!$vyr)fail('Jen pro studio.');
  $idx=read_index();$n=count($idx['items']);$au=0;$tr=[];
  foreach($idx['items'] as $it){if(!empty($it['audio']))$au++;foreach((array)$it['tr'] as $l=>$_)$tr[$l]=($tr[$l]??0)+1;}
  out(['ok'=>true,'polozek'=>$n,'s_hlasem'=>$au,'preklady'=>$tr,'vyrobna'=>$idx['vyrobna']]);
}

/* ---------- výrobna ---------- */
if(!$vyr)fail('Neplatný klíč výrobny.');
if($a==='ukoly'){
  $hl=array_filter(array_map('trim',explode(',',(string)($_GET['hlasy']??''))));
  $jz=array_filter(array_map(function($x){return preg_replace('/[^a-z]/','',$x);},explode(',',(string)($_GET['jazyky']??''))));
  $L=[];
  with_index(function(&$idx)use($hl,$jz,&$L){
    $idx['vyrobna']=['kdy'=>time(),'verze'=>substr((string)($_SERVER['HTTP_X_NTV_VERZE']??''),0,40),'hlasy'=>array_values($hl),'jazyky'=>array_values($jz)];
    foreach($idx['items'] as $it){$needA=empty($it['audio'])&&in_array($it['host'],$hl,true);$needT=array_values(array_filter($jz,function($l)use($it){return !isset(((array)$it['tr'])[$l]);}));
      if($needA||$needT)$L[]=['id'=>$it['id'],'ts'=>$it['ts'],'title'=>$it['title'],'text'=>$it['text'],'host'=>$it['host'],'hlas'=>$needA,'jazyky'=>$needT];}});
  usort($L,function($x,$y){return $y['ts']<=>$x['ts'];});
  out(['ok'=>true,'ukoly'=>array_slice($L,0,40)]);
}
if($a==='hlas'){
  $in=body();$id=slug($in['id']??'');$mp3=base64_decode((string)($in['mp3']??''),true);
  if($mp3===false||strlen($mp3)<200||strlen($mp3)>MAX_MP3)fail('Zvuk chybí nebo je moc velký.');
  if(substr($mp3,0,3)!=='ID3'&&!(ord($mp3[0])===0xFF&&(ord($mp3[1])&0xE0)===0xE0))fail('Zvuk není MP3.');
  $words=[];foreach(array_slice((array)($in['words']??[]),0,1500) as $w){if(is_array($w)&&count($w)>=2)$words[]=[clean($w[0],60),round((float)$w[1],3)];}
  $dur=round((float)($in['dur']??0),2);if($dur<=0||$dur>900)fail('Neplatná délka.');
  $ok=with_index(function(&$idx)use($id,$mp3,$words,$dur,$in){global $D;if(!isset($idx['items'][$id]))return false;
    wr("$D/hlas/$id.mp3.php",$mp3);$idx['items'][$id]['audio']=['v'=>substr(md5($mp3),0,8),'dur'=>$dur,'words'=>$words,'voice'=>clean($in['voice']??'',60)];return true;});
  $ok?out(['ok'=>true]):fail('Položka už neexistuje.');
}
if($a==='preklad'){
  $in=body();$id=slug($in['id']??'');$l=preg_replace('/[^a-z]/','',(string)($in['lang']??''));
  if(strlen($l)!==2)fail('Neplatný jazyk.');
  $t=['title'=>clean($in['title']??'',400),'text'=>clean($in['text']??'',4000),'engine'=>clean($in['engine']??'',30)];
  if($t['title']==='')fail('Prázdný překlad.');
  $ok=with_index(function(&$idx)use($id,$l,$t){if(!isset($idx['items'][$id]))return false;$tr=(array)$idx['items'][$id]['tr'];$tr[$l]=$t;$idx['items'][$id]['tr']=$tr;return true;});
  $ok?out(['ok'=>true]):fail('Položka už neexistuje.');
}
fail('Neznámá akce.');
