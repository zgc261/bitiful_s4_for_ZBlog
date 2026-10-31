<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ZBP_PATH', __DIR__);
function RegisterPlugin($id, $function) {}
require __DIR__ . '/../include.php';
function verifyMedia($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
function rejectsMedia($fn, $label) { $rejected = false; try { $fn(); } catch (Throwable $e) { $rejected = true; } verifyMedia($rejected, $label); }
$fixture = json_decode(file_get_contents(__DIR__ . '/media-vectors.json'), true);
$c = array_merge(BitifulS4_Defaults(), $fixture['config']);
foreach ($fixture['vectors'] as $v) {
    $query = BitifulS4Client::canonicalQuery($v['params']);
    verifyMedia($query === $v['query'], 'Query encoding');
    $signed = BitifulS4Client::sign($v['method'], 'testbucket.s3.bitiful.net', $v['uri'], $v['digest'], 'application/xml', $v['date'], $c, $query);
    verifyMedia($signed['canonical_request'] === $v['canonical'], 'Canonical query request');
    verifyMedia($signed['headers']['authorization'] === $v['authorization'], 'Independent GET/DELETE signature');
}
$xml = '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><EncodingType>url</EncodingType><Contents><Key>blog%2F2026%2F10%2F%E4%B8%AD%E6%96%87%20%26.png</Key><Size>42</Size><LastModified>2026-10-30T01:00:00Z</LastModified></Contents><IsTruncated>true</IsTruncated><NextContinuationToken>next+/= token</NextContinuationToken></ListBucketResult>';
$list = BitifulS4Client::parseObjectList($xml);
verifyMedia($list['items'][0]['key'] === 'blog/2026/10/中文 &.png' && $list['items'][0]['size'] === 42 && $list['next'] === 'next+/= token', 'Namespaced XML, URL decoding and cursor');
$list = BitifulS4Client::parseObjectList('<ListBucketResult><Contents><Key>blog/a&amp;b.png</Key><Size>1</Size></Contents><IsTruncated>false</IsTruncated></ListBucketResult>');
verifyMedia($list['items'][0]['key'] === 'blog/a&b.png' && $list['next'] === '', 'Unencoded XML key');
foreach (array('<broken>', '<Error><Code>AccessDenied</Code></Error>', '<!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><ListBucketResult/>', '<ListBucketResult><IsTruncated>true</IsTruncated></ListBucketResult>') as $bad) {
    rejectsMedia(function () use ($bad) { BitifulS4Client::parseObjectList($bad); }, 'Unsafe or malformed XML accepted');
}
foreach (array('private/a.png', 'blogger/a.png', 'blog/../private/a.png', 'blog//a.png', 'blog/a.php', 'blog/a.svg', '/blog/a.png') as $key) {
    rejectsMedia(function () use ($c, $key) { BitifulS4_ValidateManagedKey($c, $key); }, 'Out-of-scope/non-image deletion accepted');
}
verifyMedia(BitifulS4_ValidateManagedKey($c, 'blog/_plugin-test/a.png'), 'Legacy test images');
verifyMedia(BitifulS4_ValidateManagedKey($c, 'blog/2026/10/a.PNG'), 'Uppercase extension');
$name = BitifulS4_NewImageName('PNG'); verifyMedia((bool) preg_match('/^[A-Za-z0-9_-]{12}\.png$/D', $name), 'Short random name');
date_default_timezone_set('UTC');
verifyMedia(BitifulS4_ObjectKey($c, $name, gmmktime(0,0,0,10,30,2026)) === 'blog/2026/10/' . $name, 'Year/month path');
$other = $c; $other['bucket'] = 'changed-bucket'; verifyMedia(BitifulS4_ManagementScope($other) !== BitifulS4_ManagementScope($c), 'Changed bucket scope');
echo "PASS: independent GET/DELETE SigV4, canonical queries, safe XML, opaque pagination, directory/image boundaries, legacy tests and short names.\n";
