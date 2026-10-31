<?php
require dirname(__DIR__, 3) . '/zb_system/function/c_system_base.php';
$zbp->Load();
if (!$zbp->CheckRights('root')) { $zbp->ShowError(6); die(); }
if (!$zbp->CheckPlugin('bitiful_s4')) { $zbp->ShowError(48); die(); }
require_once __DIR__ . '/include.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function BitifulS4_MediaEscape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function BitifulS4_MediaValue(array $source, $key, $limit = 8192)
{
    if (!isset($source[$key]) || !is_string($source[$key])) { return ''; }
    if (strlen($source[$key]) > $limit) { throw new InvalidArgumentException('请求参数过长。'); }
    return $source[$key];
}
function BitifulS4_MediaSize($bytes)
{
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MiB'; }
    if ($bytes >= 1024) { return round($bytes / 1024, 1) . ' KiB'; }
    return $bytes . ' B';
}

function BitifulS4_BulkKeys(array $source, $field, $max)
{
    $value = $source[$field] ?? array();
    if (!is_array($value) || count($value) < 1 || count($value) > $max) { throw new RuntimeException('请选择有效数量的图片。'); }
    $result = array();
    foreach ($value as $key) { if (!is_string($key) || strlen($key) > 1024) { throw new RuntimeException('图片选择无效。'); } $result[$key] = true; }
    return array_keys($result);
}
function BitifulS4_BulkResponse($ok, array $deleted, $error)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => $ok, 'deleted' => $deleted, 'error' => $error), JSON_UNESCAPED_UNICODE);
    exit;
}
$c = BitifulS4_Settings();
$token = $zbp->GetCSRFToken('bitiful_s4_media');
$scope = BitifulS4_ManagementScope($c);
$error = ''; $message = ''; $confirmKey = ''; $confirmCode = ''; $bulkConfirmation = null;
$month = ''; $cursor = ''; $next = ''; $scanned = 0; $images = array(); $refIndex = BitifulS4References::load($c);
$isBulkJson = $_SERVER['REQUEST_METHOD'] === 'POST' && BitifulS4_MediaValue($_POST, 'action', 32) === 'bulk_run';
$deletedKeys = array();
try {
    BitifulS4_ValidateSettings($c);
    if (!function_exists('simplexml_load_string')) { throw new RuntimeException('请先在服务器 PHP 中启用 SimpleXML 扩展，再使用云端图片管理。'); }
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $month = BitifulS4_MediaValue($isPost ? $_POST : $_GET, 'month', 7);
    if ($month !== '' && !preg_match('/^[12][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) { throw new InvalidArgumentException('月份格式应为 YYYY-MM。'); }
    $client = new BitifulS4Client($c);
    if ($isPost) {
        if (!$zbp->VerifyCSRFToken(BitifulS4_MediaValue($_POST, 'csrf'), 'bitiful_s4_media')) { http_response_code(403); throw new RuntimeException('页面校验已过期，请刷新后重新选择图片。'); }
        if (!hash_equals($scope, BitifulS4_MediaValue($_POST, 'scope', 64))) { throw new RuntimeException('存储桶或目录配置已变化。请刷新列表，确认当前管理范围后再操作。'); }
        $action = BitifulS4_MediaValue($_POST, 'action', 32);
        if ($action === 'scan_references') {
            $refIndex = BitifulS4References::scan($c);
            $message = '检测完成，已扫描 ' . $refIndex['count'] . ' 条文章及页面（含草稿等全部状态）。无图片上传时间限制。';
            if (empty($refIndex['complete'])) { $message .= ' 部分扩展数据无法可靠解析，未引用图片将标为无法判断，批量清理已禁用。'; }
        } elseif ($action === 'bulk_prepare') {
            $manifest = BitifulS4References::verifyManifest($c, BitifulS4_MediaValue($_POST, 'manifest', 131072), BitifulS4_MediaValue($_POST, 'mac', 64), 'page', $token);
            $manual = BitifulS4_MediaValue($_POST, 'selection_mode', 16) === 'manual';
            if (!$manual && (!$refIndex || !hash_equals($refIndex['id'], $manifest['scan']))) { throw new RuntimeException('检测结果已更新或失效，请刷新列表并重新选择。'); }
            $keys = BitifulS4_BulkKeys($_POST, 'bulk_keys', 48); $items = array();
            foreach ($keys as $key) { if (!array_key_exists($key, $manifest['items'])) { throw new RuntimeException('只允许选择本页图片。'); } $items[$key] = $manifest['items'][$key]; }
            if (!$manual) {
                BitifulS4References::unusedOrFail($refIndex, $keys);
                $refIndex = BitifulS4References::scan($c);
                BitifulS4References::unusedOrFail($refIndex, $keys);
            }
            $bulkConfirmation = BitifulS4References::signManifest($c, $refIndex ?: array('id' => 'manual'), $items, $manual ? 'manual_confirm' : 'confirm', $token);
            $bulkConfirmation['items'] = $items;
            $bulkConfirmation['manual'] = $manual;
        } elseif ($action === 'bulk_confirm' || $action === 'bulk_run') {
            $manual = BitifulS4_MediaValue($_POST, 'selection_mode', 16) === 'manual';
            $manifest = BitifulS4References::verifyManifest($c, BitifulS4_MediaValue($_POST, 'manifest', 131072), BitifulS4_MediaValue($_POST, 'mac', 64), $manual ? 'manual_confirm' : 'confirm', $token);
            $keys = $action === 'bulk_run' ? BitifulS4_BulkKeys($_POST, 'batch_keys', 3) : array_slice(array_keys($manifest['items']), 0, 3);
            foreach ($keys as $key) { if (!array_key_exists($key, $manifest['items'])) { throw new RuntimeException('图片不在已确认的批量清单中。'); } }
            $started = microtime(true);
            if (!$manual) {
                $refIndex = BitifulS4References::scan($c);
                BitifulS4References::unusedOrFail($refIndex, $keys);
            }
            foreach ($keys as $key) {
                if (microtime(true) - $started > 18) { $error = '本批处理达到时间上限，已停止后续删除。请刷新列表后重新检测未处理图片。'; break; }
                if (!$manual && !hash_equals(BitifulS4References::revision(), $refIndex['revision'])) { $error = '文章在删除过程中发生变化，已停止后续删除，请重新检测。'; break; }
                try { $client->deleteImage($key); $deletedKeys[] = $key; }
                catch (Throwable $e) { $error = $e->getMessage(); break; }
            }
            if ($isBulkJson) { BitifulS4_BulkResponse($error === '', $deletedKeys, $error); }
            $remaining = array_diff_key($manifest['items'], array_fill_keys($deletedKeys, true));
            if ($remaining) {
                $bulkConfirmation = BitifulS4References::signManifest($c, $refIndex ?: array('id' => 'manual'), $remaining, $manual ? 'manual_confirm' : 'confirm', $token); $bulkConfirmation['items'] = $remaining; $bulkConfirmation['manual'] = $manual;
                $message = '本批已删除 ' . count($deletedKeys) . ' 张；剩余 ' . count($remaining) . ' 张可继续确认处理。';
            } else {
                $count = count($manifest['items']); $proof = hash_hmac('sha256', 'bulk-done|' . $scope . '|' . $count, $token);
                header('Location: manager.php?' . http_build_query(array('month' => $month, 'bulk_done' => $count, 'proof' => $proof), '', '&', PHP_QUERY_RFC3986), true, 303); exit;
            }
        } else {
            $key = BitifulS4_MediaValue($_POST, 'key', 1024); BitifulS4_ValidateManagedKey($c, $key);
            if ($action === 'prepare_delete') { $confirmKey = $key; $confirmCode = hash_hmac('sha256', 'delete|' . $scope . '|' . $key, $token); }
            elseif ($action === 'delete') {
                $expected = hash_hmac('sha256', 'delete|' . $scope . '|' . $key, $token);
                if (!hash_equals($expected, BitifulS4_MediaValue($_POST, 'confirm', 64))) { throw new RuntimeException('删除确认已失效，请重新选择图片并确认。'); }
                $client->deleteImage($key); $proof = hash_hmac('sha256', 'deleted|' . $scope . '|' . $key, $token);
                header('Location: manager.php?' . http_build_query(array('month' => $month, 'deleted' => $key, 'proof' => $proof), '', '&', PHP_QUERY_RFC3986), true, 303); exit;
            } else { throw new InvalidArgumentException('无效操作。'); }
        }
    } else {
        $deleted = BitifulS4_MediaValue($_GET, 'deleted', 1024); $proof = BitifulS4_MediaValue($_GET, 'proof', 64); $done = BitifulS4_MediaValue($_GET, 'bulk_done', 3);
        if ($deleted !== '' && hash_equals(hash_hmac('sha256', 'deleted|' . $scope . '|' . $deleted, $token), $proof)) { $message = '云端图片已删除：' . basename($deleted) . '。本地副本和 Z-Blog 附件记录未作修改；CDN 可能仍有缓存。'; }
        if (ctype_digit($done) && hash_equals(hash_hmac('sha256', 'bulk-done|' . $scope . '|' . $done, $token), $proof)) { $message = '本批云端图片已删除，本地副本和附件记录保持不变。'; }
        $cursor = BitifulS4_MediaValue($_GET, 'cursor');
    }
    $prefix = $c['prefix'] !== '' ? $c['prefix'] . '/' : ''; if ($month !== '') { $prefix .= str_replace('-', '/', $month) . '/'; }
    $page = $client->listObjects($prefix, $cursor); $scanned = $page['scanned']; $next = $page['next'];
    foreach ($page['items'] as $item) {
        try { BitifulS4_ValidateManagedKey($c, $item['key']); } catch (InvalidArgumentException $e) { continue; }
        if ($prefix !== '' && strpos($item['key'], $prefix) !== 0) { continue; }
        $item['url'] = rtrim($c['public_url'], '/') . '/' . BitifulS4Client::encodeKey($item['key']);
        $item['reference'] = BitifulS4References::status($refIndex, $item['key']); $images[] = $item;
    }
} catch (Throwable $e) {
    $error = $e->getMessage(); $refIndex = BitifulS4References::load($c);
    if ($isBulkJson) { BitifulS4_BulkResponse(false, $deletedKeys, $error); }
}
$pageItems = array(); foreach ($images as $item) { $pageItems[$item['key']] = $item['size']; }
$pageManifest = BitifulS4References::signManifest($c, $refIndex ?: array('id' => 'manual'), $pageItems, 'page', $token);
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>附件管理（云端） · 缤纷云 S4</title><link rel="icon" href="assets/favicon.ico?v=1.3.6" type="image/x-icon"><link rel="stylesheet" href="assets/admin.css?v=1.3.6"><script src="assets/media.js?v=1.3.6" defer></script></head><body class="media-page">
<main class="wrap"><nav class="plugin-tabs manager-tabs" aria-label="插件功能">
<div class="plugin-tab-links"><a href="main.php">存储设置</a><a href="manager.php" aria-current="page">附件管理（云端）</a></div>
<div class="plugin-nav-actions"><a href="main.php">← 返回插件设置</a><span>图片存储 / 附件管理（云端）</span><a href="<?php echo BitifulS4_MediaEscape($zbp->host); ?>zb_system/cmd.php?act=PluginMng">插件管理</a></div>
</nav><header class="page-heading"><div><div class="eyebrow"><img class="brand-mark" src="logo.png?v=1.3.6" width="30" height="30" alt="缤纷云">云端媒体库 <span class="version">v1.3.6</span></div><h1>云端图片，一处管理。</h1><p>直接浏览当前桶与目录中的图片，包括旧版本上传的图片和测试图片。</p></div><a class="media-link" href="manager.php">刷新列表 ↻</a></header>
<?php if ($error): ?><div class="notice error" role="alert"><strong>无法完成操作</strong><p><?php echo BitifulS4_MediaEscape($error); ?></p><p>请确认子账户拥有当前桶和目录的列举权限；删除图片还需要相应的删除权限。</p></div><?php endif; ?>
<?php if ($message): ?><div class="notice success" role="status"><p><?php echo BitifulS4_MediaEscape($message); ?></p></div><?php endif; ?>
<?php if ($confirmKey !== ''): ?><section class="notice error media-confirm" role="alert" aria-labelledby="confirm-heading"><strong id="confirm-heading">确认删除这张云端图片？</strong><p><code><?php echo BitifulS4_MediaEscape($confirmKey); ?></code></p><p>如果文章正在引用该图片，删除后会导致图片无法显示。此操作只删除云端对象，不会删除本地副本、附件记录或自动替换文章链接，单张手动删除不执行引用复查；未引用批量清理会在执行前复查。</p><form method="post" action="manager.php"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?php echo BitifulS4_MediaEscape($token); ?>"><input type="hidden" name="scope" value="<?php echo BitifulS4_MediaEscape($scope); ?>"><input type="hidden" name="key" value="<?php echo BitifulS4_MediaEscape($confirmKey); ?>"><input type="hidden" name="confirm" value="<?php echo BitifulS4_MediaEscape($confirmCode); ?>"><input type="hidden" name="month" value="<?php echo BitifulS4_MediaEscape($month); ?>"><button type="submit" class="danger">确认删除云端图片</button><a class="media-link" href="manager.php?<?php echo BitifulS4_MediaEscape(http_build_query(array('month' => $month))); ?>">取消</a></form></section><?php endif; ?>
<section class="media-controlbar media-panels" aria-label="云端附件筛选与检测">
<section class="media-control-region" aria-labelledby="bucket-heading"><h2 id="bucket-heading">当前存储桶</h2>
<div class="media-current-bucket"><strong><?php echo BitifulS4_MediaEscape($c['bucket'] ?: '未设置'); ?></strong><span class="bucket-count">本页 <?php echo count($images); ?> 张</span></div>
<p class="control-note">管理范围：<?php echo BitifulS4_MediaEscape($c['prefix'] !== '' ? $c['prefix'] . '/' : '整个存储桶'); ?>。删除仅影响云端图片，保留本地副本。</p></section>
<section class="media-control-region" aria-labelledby="filter-heading"><h2 id="filter-heading">筛选</h2>
<form method="get" action="manager.php" class="media-filter"><input id="month" name="month" type="month" aria-label="筛选月份" value="<?php echo BitifulS4_MediaEscape($month); ?>"><button type="submit" class="secondary">筛选</button><a href="manager.php">全部</a></form>
<p class="control-note">按文件路径中的年月筛选，每页最多读取 48 个对象。</p></section>
<section class="media-control-region" aria-labelledby="reference-heading"><h2 id="reference-heading">检测文章引用</h2>
<form method="post" action="manager.php" class="scan-form"><input type="hidden" name="action" value="scan_references"><input type="hidden" name="csrf" value="<?php echo BitifulS4_MediaEscape($token); ?>"><input type="hidden" name="scope" value="<?php echo BitifulS4_MediaEscape($scope); ?>"><input type="hidden" name="month" value="<?php echo BitifulS4_MediaEscape($month); ?>"><button type="submit">检测文章引用</button><span class="muted"><?php echo $refIndex ? '更新于 ' . BitifulS4_MediaEscape(date('H:i', $refIndex['created'])) : '尚未检测'; ?></span></form>
<p class="control-note">包含文章、页面和草稿；无时间限制，结果缓存 10 分钟。</p>
<details class="media-help"><summary>检测与删除说明</summary><p>手动多选无需先检测，可能删除已引用图片；“仅删除未引用”会在执行前复核。主题、侧栏及其他网站引用不在检测范围内。删除仅影响云端图片。</p></details></section>
</section>
<?php if ($bulkConfirmation): ?><section class="notice error bulk-confirmation" id="bulk-confirmation" aria-labelledby="bulk-confirm-heading"><strong id="bulk-confirm-heading">确认批量删除 <?php echo count($bulkConfirmation['items']); ?> 张云端图片？</strong><p>预计释放 <?php echo BitifulS4_MediaEscape(BitifulS4_MediaSize(array_sum($bulkConfirmation['items']))); ?>。<?php echo !empty($bulkConfirmation['manual']) ? '手动删除会按你确认的清单执行，即使图片已被文章引用，也会删除并导致相关图片无法显示。' : '已重新检测文章引用，执行时还会按批次复核。'; ?>主题、侧栏及其他网站也可能引用这些图片。只删除云端图片，保留本地副本与附件记录。</p><ul class="bulk-file-list"><?php foreach ($bulkConfirmation['items'] as $bulkKey => $bulkSize): ?><li data-confirm-key="<?php echo BitifulS4_MediaEscape($bulkKey); ?>"><code><?php echo BitifulS4_MediaEscape($bulkKey); ?></code><span><?php echo BitifulS4_MediaEscape(BitifulS4_MediaSize($bulkSize)); ?></span></li><?php endforeach; ?></ul><form method="post" action="manager.php" id="bulk-confirm-form" data-manual="<?php echo !empty($bulkConfirmation['manual']) ? '1' : '0'; ?>"><input type="hidden" name="selection_mode" value="<?php echo !empty($bulkConfirmation['manual']) ? 'manual' : 'unused'; ?>"><input type="hidden" name="action" value="bulk_confirm"><input type="hidden" name="csrf" value="<?php echo BitifulS4_MediaEscape($token); ?>"><input type="hidden" name="scope" value="<?php echo BitifulS4_MediaEscape($scope); ?>"><input type="hidden" name="month" value="<?php echo BitifulS4_MediaEscape($month); ?>"><input type="hidden" name="manifest" value="<?php echo BitifulS4_MediaEscape($bulkConfirmation['manifest']); ?>"><input type="hidden" name="mac" value="<?php echo BitifulS4_MediaEscape($bulkConfirmation['mac']); ?>"><button type="submit" class="danger"><?php echo !empty($bulkConfirmation['manual']) ? '确认删除所选云端图片' : '确认删除未引用图片'; ?></button><a href="manager.php?<?php echo BitifulS4_MediaEscape(http_build_query(array('month' => $month))); ?>">取消 / 刷新列表</a></form><p class="bulk-progress" role="status" aria-live="polite"></p><small>每批最多 3 张，失败后停止，不自动重试。只删除云端对象，保留本地副本及附件记录。禁用 JavaScript 时，每次确认处理最多 3 张，剩余图片需要再次确认。</small></section><?php endif; ?>
<form method="post" action="manager.php" id="bulk-selection" class="bulk-toolbar"><input type="hidden" name="action" value="bulk_prepare"><input type="hidden" name="csrf" value="<?php echo BitifulS4_MediaEscape($token); ?>"><input type="hidden" name="scope" value="<?php echo BitifulS4_MediaEscape($scope); ?>"><input type="hidden" name="month" value="<?php echo BitifulS4_MediaEscape($month); ?>"><input type="hidden" name="manifest" value="<?php echo BitifulS4_MediaEscape($pageManifest['manifest'] ?? ''); ?>"><input type="hidden" name="mac" value="<?php echo BitifulS4_MediaEscape($pageManifest['mac'] ?? ''); ?>"><button type="button" class="secondary" id="select-unused" <?php echo !$refIndex || empty($refIndex['complete']) ? 'disabled' : ''; ?>>选中本页未发现引用的图片</button><button type="button" class="secondary" id="select-all">本页全选</button><button type="button" class="secondary" id="clear-selected">取消选择</button><span id="selected-count">已选 0 张</span><button type="submit" name="selection_mode" value="manual" class="danger">删除所选图片</button><button type="submit" name="selection_mode" value="unused" class="secondary" <?php echo !$refIndex || empty($refIndex['complete']) ? 'disabled' : ''; ?>>仅删除未引用</button></form>
<?php if (count($images)): ?><div class="media-grid">
<?php foreach ($images as $item): $modified = strtotime($item['modified']); $reference = $item['reference']; $refLabels = array('used' => '已引用', 'unused' => '未发现引用', 'unknown' => '无法判断', 'unscanned' => '未检测'); ?>
<article class="media-card"><label class="media-select"><input type="checkbox" name="bulk_keys[]" form="bulk-selection" value="<?php echo BitifulS4_MediaEscape($item['key']); ?>" data-bulk-candidate="<?php echo $reference['state'] === 'unused' ? '1' : '0'; ?>" aria-label="选择 <?php echo BitifulS4_MediaEscape(basename($item['key'])); ?>"><span>选择</span></label><a class="media-thumb" href="<?php echo BitifulS4_MediaEscape($item['url']); ?>" target="_blank" rel="noopener noreferrer"><img loading="lazy" decoding="async" src="<?php echo BitifulS4_MediaEscape($item['url']); ?>" alt="<?php echo BitifulS4_MediaEscape(basename($item['key'])); ?>" width="280" height="170"><span class="media-image-error" hidden>图片暂时无法加载，点击查看原地址</span><span class="reference-badge ref-<?php echo BitifulS4_MediaEscape($reference['state']); ?>"><?php echo $refLabels[$reference['state']]; ?></span></a><div class="media-info"><p class="media-meta"><span><?php echo BitifulS4_MediaEscape(BitifulS4_MediaSize($item['size'])); ?></span><span><?php echo BitifulS4_MediaEscape($modified !== false ? date('Y-m-d H:i', $modified) : '时间未知'); ?></span></p><?php if ($reference['ids']): ?><details class="media-ref-list"><summary><?php echo $reference['state'] === 'used' ? '查看引用文章' : '查看同名或疑似引用'; ?></summary><ul><?php foreach ($reference['ids'] as $refId): $refPost = $refIndex['posts'][$refId] ?? array('title' => '文章 #' . (int) $refId, 'type' => 0, 'status' => 0); ?><li><a href="<?php echo BitifulS4_MediaEscape($zbp->host . 'zb_system/cmd.php?act=' . ((int) $refPost['type'] === 1 ? 'PageEdt' : 'ArticleEdt') . '&id=' . (int) $refId); ?>" target="_blank" rel="noopener noreferrer"><?php echo BitifulS4_MediaEscape($refPost['title']); ?>（ID <?php echo (int) $refId; ?>）</a></li><?php endforeach; ?></ul></details><?php endif; ?><div class="media-actions"><a href="<?php echo BitifulS4_MediaEscape($item['url']); ?>" target="_blank" rel="noopener noreferrer">预览 ↗</a><button type="button" class="media-copy" data-copy-url="<?php echo BitifulS4_MediaEscape($item['url']); ?>">复制链接</button><form method="post" action="manager.php"><input type="hidden" name="action" value="prepare_delete"><input type="hidden" name="csrf" value="<?php echo BitifulS4_MediaEscape($token); ?>"><input type="hidden" name="scope" value="<?php echo BitifulS4_MediaEscape($scope); ?>"><input type="hidden" name="key" value="<?php echo BitifulS4_MediaEscape($item['key']); ?>"><input type="hidden" name="month" value="<?php echo BitifulS4_MediaEscape($month); ?>"><button type="submit" class="media-delete">删除</button></form></div></div></article>
<?php endforeach; ?></div>
<?php elseif ($error === ''): ?><section class="card media-empty"><h2>本页没有可显示的图片</h2><p><?php echo $next !== '' ? '本页读取到的可能是其他类型文件，可以继续查看下一页。' : '可以更换月份，或在编辑器中上传图片后刷新。'; ?></p><p class="muted">本页读取 <?php echo (int) $scanned; ?> 个对象。私有图片或图片域名配置不正确时，列表可能有记录但无法预览。</p></section><?php endif; ?>
<nav class="media-pagination" aria-label="云端图片分页"><a class="media-link" href="manager.php?<?php echo BitifulS4_MediaEscape(http_build_query(array('month' => $month))); ?>">回到第一页</a><span>本页读取 <?php echo (int) $scanned; ?> 个对象，显示 <?php echo count($images); ?> 张图片</span><?php if ($next !== ''): ?><a class="media-link" href="manager.php?<?php echo BitifulS4_MediaEscape(http_build_query(array('month' => $month, 'cursor' => $next), '', '&', PHP_QUERY_RFC3986)); ?>">下一页 →</a><?php endif; ?></nav>
<footer class="page-footer"><span>缤纷云 S4 图片存储 · v1.3.6 · MIT</span><span>直接操作云端；本地文件与附件记录保持不变</span></footer></main></body></html>
