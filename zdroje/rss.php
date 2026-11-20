<?php
/* Naše TV – vlastní RSS kanál: /zdroje/rss.php?k=hlavni (seznam kanálů na /zdroje/) */
require __DIR__.'/../lib/feeds.php';
$k=preg_replace('/[^a-z]/','',(string)($_GET['k']??'hlavni'));
if(!isset($CHANNELS[$k])){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo "Kanál neexistuje. Seznam kanálů: /zdroje/";exit;}
channels_index_fast();
$xml=rd("$DATA/channels/$k.xml.php");
header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');
echo $xml?:'<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Naše TV</title><link>/zdroje/</link><description>Kanál se připravuje</description></channel></rss>';
finish_and_rebuild();
