<?php
/* Naše TV – načítač pro Programové studio (jednotlivé zdroje z registru i naše kanály z agregátoru)
   přístup: přihlášená relace studia, nebo klíč (?key=) pro plánovanou úlohu (cron) */
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex');
const FEED_KEY_HASH='6066c842407cebe6339b8b2a0daa460ec8e1082fbb25999c2239fa752098c9df';
function out($a,$code=200){http_response_code($code);echo json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$auth=!empty($_SESSION['ntv_ok'])||(isset($_GET['key'])&&hash_equals(FEED_KEY_HASH,hash('sha256',(string)$_GET['key'])));
session_write_close();
if(!$auth)out(['ok'=>false,'error'=>'nepřihlášeno'],403);
require __DIR__.'/../lib/feeds.php';
if(isset($_GET['ping']))out(['ok'=>true,'sources'=>count($REG),'channels'=>array_map(function($c){return $c['n'];},$CHANNELS),'curl'=>function_exists('curl_init')]);
if(isset($_GET['cron'])){@set_time_limit(60);ignore_user_abort(true);$n=refresh_stale(20);build_channels(0);out(['ok'=>true,'refreshed'=>$n]);}
$force=!empty($_GET['force']);
$ch=preg_replace('/[^a-z]/','',(string)($_GET['ch']??''));
if($ch!==''){ /* náš kanál z agregátoru */
  if(!isset($CHANNELS[$ch]))out(['ok'=>false,'error'=>'kanál neexistuje']);
  $j=$force?channels_index(0,12):channels_index_fast();
  echo json_encode(['ok'=>true,'id'=>'ntv-'.$ch,'status'=>'new','http'=>200,'fetched'=>gmdate('c',$j['built']??time()),'lastModified'=>gmdate(DATE_RFC7231,$j['built']??time()),'xml'=>rd("$DATA/channels/$ch.xml.php")],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);finish_and_rebuild();exit;}
$id=preg_replace('/[^a-z0-9_-]/i','',(string)($_GET['id']??''));
if($id==='')out(['ok'=>false,'error'=>'chybí id'],400);
$o=fetch_one($id,$force);
if(isset($_GET['meta']))unset($o['xml']);
out($o); /* chyby zdroje vracíme se stavem 200 a ok:false – ochrana hostingu by jinak odpověď nahradila vlastní stránkou */
