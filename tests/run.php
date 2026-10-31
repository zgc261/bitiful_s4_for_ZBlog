<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ZBP_PATH', __DIR__);
require __DIR__.'/../lib/S3Client.php';
function check($test,$message) { if (!$test) { throw new RuntimeException($message); } }
$v=json_decode(file_get_contents(__DIR__.'/signature-vector.json'),true);
$r=BitifulS4Client::sign('PUT',$v['host'],$v['uri'],$v['digest'],'image/png',$v['date'],$v['config']);
check($r['canonical_request']===$v['canonical'],'Canonical request mismatch');
check($r['headers']['authorization']===$v['authorization'],'SigV4 signature mismatch');
check(BitifulS4Client::encodeKey('blog/中文 image.png')==='blog/%E4%B8%AD%E6%96%87%20image.png','UTF-8 URL encoding');
foreach (array('/etc/passwd','../x','a//b','a/../b','a\\b',"a\0b") as $bad) {
 $thrown=false;try { BitifulS4Client::encodeKey($bad); }catch(InvalidArgumentException $e){$thrown=true;}
 check($thrown,'Unsafe object key accepted');
}
foreach (array('a','bad.bucket','https://bad','-invalid','local/host') as $bucket) {
 $c=$v['config'];$c['bucket']=$bucket;$thrown=false;
 try {BitifulS4Client::validate($c);}catch(InvalidArgumentException $e){$thrown=true;}
 check($thrown,'Invalid bucket accepted');
}
foreach (array('s3.bitiful.net','S3.BITIFUL.NET','s3.storage.example.com') as $endpoint) { BitifulS4Client::validateEndpoint($endpoint); }
foreach (array('https://s3.bitiful.net','http://s3.bitiful.net','s3.bitiful.net/','user:pass@s3.bitiful.net','s3.bitiful.net:443','s3.bitiful.net/path','127.0.0.1','localhost','a..com','-a.com',"a.com\n") as $endpoint) {
 $thrown=false;try{BitifulS4Client::validateEndpoint($endpoint);}catch(InvalidArgumentException $e){$thrown=true;}check($thrown,'Invalid API domain accepted: '.$endpoint);
}
foreach (array('0.0.0.0','10.2.3.4','100.64.0.1','127.0.0.1','169.254.169.254','172.20.0.1','192.168.2.1','192.0.2.1','198.18.0.1','203.0.113.1','224.0.0.1','255.255.255.255','::1') as $ip) { check(!BitifulS4Client::isPublicIPv4($ip),'Private/reserved endpoint accepted'); }
foreach (array('1.1.1.1','8.8.8.8','93.184.216.34') as $ip) { check(BitifulS4Client::isPublicIPv4($ip),'Public address rejected'); }
echo "PASS: independent SigV4 vector, path safety, editable endpoint validation and public-IP policy.\n";
