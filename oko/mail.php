<?php
// Odesílač alarmových e-mailů pro Oko. Token se ověřuje přes hash; příjemce zadává aplikace.
header('Content-Type: application/json; charset=utf-8');
$HASH='d39bc35c5dfaacdf41fa3d5689d6ef2ba12fe66c8fc555c5066f56f9a3b6b313';
$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in)||empty($in['token'])||!hash_equals($HASH,hash('sha256',(string)$in['token']))){ http_response_code(401); echo '{"ok":false,"err":"token"}'; exit; }
$to=trim((string)($in['to']??''));
if(!filter_var($to,FILTER_VALIDATE_EMAIL)||strlen($to)>120){ http_response_code(400); echo '{"ok":false,"err":"prijemce"}'; exit; }
$f=sys_get_temp_dir().'/oko-mail-'.date('Ymd').'.cnt'; $c=@intval(@file_get_contents($f));
if($c>=40){ http_response_code(429); echo '{"ok":false,"err":"denni limit"}'; exit; }
@file_put_contents($f,$c+1);
$subj=mb_substr((string)($in['subject']??'Oko'),0,150); $text=mb_substr((string)($in['text']??''),0,4000);
$host=preg_replace('/[^a-z0-9.-]/i','',$_SERVER['SERVER_NAME']??'localhost');
$b='oko'.md5(uniqid('',true)); $h="From: Oko <oko@$host>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
$body="--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($text))."\r\n";
$t=(string)($in['thumb']??'');
if(preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#',$t,$m)&&strlen($m[1])<400000){ $body.="--$b\r\nContent-Type: image/jpeg; name=\"oko.jpg\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"oko.jpg\"\r\n\r\n".chunk_split($m[1])."\r\n"; }
$body.="--$b--";
$ok=mail($to,'=?UTF-8?B?'.base64_encode($subj).'?=',$body,$h);
echo json_encode(['ok'=>(bool)$ok]);
