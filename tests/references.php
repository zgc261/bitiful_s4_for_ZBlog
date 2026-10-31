<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ZBP_PATH', __DIR__);
function RegisterPlugin($id, $callback) {}
require __DIR__ . '/../include.php';
function checkReference($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
$c = array_merge(BitifulS4_Defaults(), array('bucket' => 'testbucket', 'public_url' => 'https://img.example.com', 'access_key' => 'test-access', 'secret_key' => 'test-secret'));
$zbp = new stdClass(); $zbp->host = 'https://blog.example.com/';
$index = array('id' => 'fixture', 'complete' => true, 'paths' => array(), 'names' => array(), 'posts' => array());
$post = array('id' => 1, 'title' => 'Draft page', 'type' => 1, 'status' => 1);
BitifulS4References::collectText('<img data-src="https://img.example.com/blog/2026/10/one.png?w=400&amp;fmt=webp" srcset="//img.example.com/blog/2026/10/two.png 2x"> ![md](https://img.example.com/blog/2026/10/escaped\_name.png)', $post, $index, $c);
foreach (array('one.png', 'two.png', 'escaped_name.png') as $name) { checkReference(BitifulS4References::status($index, 'blog/2026/10/' . $name)['state'] === 'used', 'Missing normalized reference'); }
BitifulS4References::collectText('![old](https://old-domain.example.com/blog/2026/10/old.png)', $post, $index, $c);
checkReference(BitifulS4References::status($index, 'blog/2026/10/old.png')['state'] === 'unknown', 'Old-domain ambiguity must be retained');
checkReference(BitifulS4References::status($index, 'blog/2026/10/fresh.png')['state'] === 'unused', 'No age restriction on new unused images');
$manifest = BitifulS4References::signManifest($c, $index, array('blog/2026/10/fresh.png' => 42), 'page', 'test-token');
checkReference(BitifulS4References::verifyManifest($c, $manifest['manifest'], $manifest['mac'], 'page', 'test-token')['items']['blog/2026/10/fresh.png'] === 42, 'Signed manifest');
$denied = false; try { BitifulS4References::verifyManifest($c, $manifest['manifest'] . 'x', $manifest['mac'], 'page', 'test-token'); } catch (Throwable $e) { $denied = true; } checkReference($denied, 'Tampered page selection accepted');
$denied = false; try { BitifulS4References::unusedOrFail($index, array('blog/2026/10/one.png')); } catch (Throwable $e) { $denied = true; } checkReference($denied, 'Used image accepted for cleanup');
$index['complete'] = false;
checkReference(BitifulS4References::status($index, 'blog/new.png')['state'] === 'unknown', 'Incomplete scan must not report unused');
$denied = false; try { BitifulS4References::unusedOrFail($index, array('blog/new.png')); } catch (Throwable $e) { $denied = true; } checkReference($denied, 'Incomplete scan permits cleanup');
echo "PASS: normalized HTML/Markdown references, draft/page identities, conservative ambiguity, no age threshold, signed selections and deletion guards.\n";
