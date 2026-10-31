<?php
require dirname(__DIR__, 3) . '/zb_system/function/c_system_base.php';
$zbp->Load();
if (!$zbp->CheckRights('root')) { $zbp->ShowError(6); die(); }
if (!$zbp->CheckPlugin('bitiful_s4')) { $zbp->ShowError(48); die(); }
require_once __DIR__ . '/include.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function BitifulS4_Escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function BitifulS4_Post($key) { return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : ''; }
$c = BitifulS4_Settings();
$savedEnabled = !empty($c['enabled']);
$message = ''; $error = ''; $testUrl = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$zbp->VerifyCSRFToken(BitifulS4_Post('csrf'), 'bitiful_s4')) {
        http_response_code(403);
        $error = '页面校验已过期。请刷新本页后再操作。';
    } else {
        try {
            $action = BitifulS4_Post('action');
            if ($action === 'save') {
                $next = $c;
                foreach (array('bucket', 'endpoint', 'region', 'public_url', 'prefix') as $k) { $next[$k] = BitifulS4_Post($k); }
                $next['endpoint'] = strtolower($next['endpoint']);
                $next['public_url'] = rtrim($next['public_url'], '/');
                $next['prefix'] = trim($next['prefix'], '/');
                $next['enabled'] = BitifulS4_Post('enabled') === '1' ? 1 : 0;
                $next['keep_local'] = BitifulS4_Post('keep_local') === '1' ? 1 : 0;
                if (!ctype_digit(BitifulS4_Post('max_mb'))) { throw new InvalidArgumentException('大小上限请填写整数。'); }
                $next['max_mb'] = (int) BitifulS4_Post('max_mb');
                if (!defined('BITIFUL_S4_ACCESS_KEY') && BitifulS4_Post('access_key') !== '') { $next['access_key'] = BitifulS4_Post('access_key'); }
                if (!defined('BITIFUL_S4_SECRET_KEY') && BitifulS4_Post('secret_key') !== '') { $next['secret_key'] = BitifulS4_Post('secret_key'); }
                BitifulS4_ValidateSettings($next);
                $config = $zbp->Config('bitiful_s4');
                foreach ($next as $k => $v) {
                    if ($k === 'access_key' && defined('BITIFUL_S4_ACCESS_KEY')) { continue; }
                    if ($k === 'secret_key' && defined('BITIFUL_S4_SECRET_KEY')) { continue; }
                    $config->$k = $v;
                }
                if (!$zbp->SaveConfig('bitiful_s4')) { throw new RuntimeException('配置保存失败。'); }
                $c = $next;
                $savedEnabled = !empty($c['enabled']);
                $message = '配置已保存。先测试上传，再到编辑器上传一张新图片检查预览和文章效果。';
            } elseif ($action === 'test') {
                BitifulS4_ValidateSettings($c);
                $temp = tmpfile();
                if (!$temp) { throw new RuntimeException('无法创建临时测试文件。'); }
                try {
                    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
                    if (fwrite($temp, $png) !== strlen($png)) { throw new RuntimeException('无法写入测试图片。'); }
                    $path = stream_get_meta_data($temp)['uri'];
                    $key = BitifulS4_ObjectKey($c, BitifulS4_NewImageName('png'));
                    (new BitifulS4Client($c))->putFile($key, $path, 'image/png');
                    $testUrl = $c['public_url'] . '/' . BitifulS4Client::encodeKey($key);
                    $message = '云端写入成功。请点击下方链接确认图片可以公开读取；此处尚未验证访问域名、证书或读权限。测试图按与正式图片相同的年月目录保存，可通过此链接定位并在缤纷云控制台删除。';
                } finally { fclose($temp); }
            } else {
                throw new InvalidArgumentException('无效操作。');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (isset($next) && isset($action) && $action === 'save') { $c = $next; }
        }
    }
}
$token = $zbp->GetCSRFToken('bitiful_s4');
?><!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>缤纷云 S4 图片存储 · 插件设置</title>
<link rel="icon" href="assets/favicon.ico?v=1.3.6" type="image/x-icon"><link rel="stylesheet" href="assets/admin.css?v=1.3.6">
</head><body>
<div class="topbar"><div class="topbar-inner"><a class="back" href="<?php echo BitifulS4_Escape($zbp->host); ?>zb_system/cmd.php?act=PluginMng">← 插件管理</a><span>图片存储 / 设置</span><a href="https://docs.bitiful.com/bitiful-s4/intro" target="_blank" rel="noopener noreferrer">缤纷云文档 ↗</a></div></div>
<main class="wrap">
<nav class="plugin-tabs" aria-label="插件功能"><a href="main.php" aria-current="page">存储设置</a><a href="manager.php">附件管理（云端）</a></nav>
<header class="page-heading"><div><div class="eyebrow"><img class="brand-mark" src="logo.png?v=1.3.6" width="30" height="30" alt="缤纷云"> Z-BlogPHP 插件 <span class="version">v1.3.6</span></div><h1>让图片存储，更轻松。</h1><p>将编辑器新上传的图片保存至缤纷云，使用自己的图片域名，让写作流程保持熟悉。</p></div><div class="heading-meta"><span class="badge">MIT 免费开源</span><span class="status <?php echo $savedEnabled ? 'is-on' : ''; ?>"><i aria-hidden="true"></i><?php echo $savedEnabled ? '新图片自动上传：开启' : '新图片自动上传：暂停'; ?></span></div></header>
<nav class="page-nav" aria-label="设置导航"><a href="#connection">连接设置</a><a href="#policy">上传策略</a><a href="#test">上传测试</a><a href="#guide">功能介绍与常见问题</a><a href="manager.php">附件管理（云端） ↗</a></nav>
<?php if ($message): ?><div class="notice success" role="status"><strong>操作成功</strong><p><?php echo BitifulS4_Escape($message); ?></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice error" role="alert"><strong>请检查以下内容</strong><p><?php echo BitifulS4_Escape($error); ?></p></div><?php endif; ?>
<?php if ($testUrl): ?><p class="test-link"><a href="<?php echo BitifulS4_Escape($testUrl); ?>" target="_blank" rel="noopener noreferrer">打开测试图片，确认读取正常 ↗</a></p><?php endif; ?>
<div class="workspace"><div class="settings-column">
<form method="post" action="main.php" autocomplete="off" id="settings-form">
<input type="hidden" name="csrf" value="<?php echo BitifulS4_Escape($token); ?>"><input type="hidden" name="action" value="save">
<section class="card" id="connection"><div class="section-heading"><span class="section-number">01</span><div><h2>连接到存储桶</h2><p>填写存储服务和子账户信息。已有密钥留空即可保留。</p></div></div>
<div class="field-grid">
<div class="field"><label for="bucket">存储桶名称 <span>Bucket</span></label><input id="bucket" name="bucket" type="text" required maxlength="63" placeholder="my-blog-images" value="<?php echo BitifulS4_Escape($c['bucket']); ?>" aria-describedby="bucket-help"><small id="bucket-help">仅填写桶名，不包含域名；支持小写字母、数字和短横线。</small></div>
<div class="field"><label for="region">区域 <span>Region</span></label><input id="region" name="region" type="text" required maxlength="40" value="<?php echo BitifulS4_Escape($c['region']); ?>" aria-describedby="region-help"><small id="region-help">默认 cn-east-1，以存储桶控制台显示为准。</small></div>
<div class="field full"><label for="endpoint">上传 API <span>Endpoint</span></label><div class="input-addon"><span aria-hidden="true">https://</span><input id="endpoint" name="endpoint" type="text" required maxlength="253" spellcheck="false" autocapitalize="none" placeholder="s3.bitiful.net" value="<?php echo BitifulS4_Escape($c['endpoint']); ?>" aria-describedby="endpoint-help"></div><small id="endpoint-help"><strong>不包含 https://</strong>，例如 s3.bitiful.net。不要填写桶名、端口、斜杠或路径。程序自动使用 HTTPS，并按「桶名.API 域名」访问。</small></div>
<div class="field"><label for="access_key">Access Key <span class="field-state"><?php echo $c['access_key'] !== '' ? '已配置' : '待填写'; ?></span></label><input id="access_key" name="access_key" type="password" autocomplete="new-password" maxlength="256" value="" <?php echo defined('BITIFUL_S4_ACCESS_KEY') ? 'disabled' : ''; ?> aria-describedby="ak-help"><small id="ak-help"><?php echo defined('BITIFUL_S4_ACCESS_KEY') ? '由服务器常量配置，此处无需填写。' : '子账户 Access Key；留空保留原值，输入新值则替换。'; ?></small></div>
<div class="field"><label for="secret_key">Secret Key <span class="field-state"><?php echo $c['secret_key'] !== '' ? '已配置' : '待填写'; ?></span></label><input id="secret_key" name="secret_key" type="password" autocomplete="new-password" maxlength="256" value="" <?php echo defined('BITIFUL_S4_SECRET_KEY') ? 'disabled' : ''; ?> aria-describedby="sk-help"><small id="sk-help"><?php echo defined('BITIFUL_S4_SECRET_KEY') ? '由服务器常量配置，此处无需填写。' : '对应 Secret Key；留空保留原值，密钥不会回显。'; ?></small></div>
<div class="field full"><label for="public_url">图片访问地址 <span>Public URL</span></label><input id="public_url" name="public_url" type="text" required placeholder="https://img.example.com" value="<?php echo BitifulS4_Escape($c['public_url']); ?>" aria-describedby="url-help"><small id="url-help">这里<strong>需要包含 https://</strong>。填写可长期公开读图的域名，支持已配置的自定义域名或 CDN 地址，不使用临时签名链接。</small></div>
</div><div class="inline-note"><strong>两个地址，用途不同</strong><span>上传 API 用于服务器写入；图片访问地址用于文章显示。二者通常不同。</span></div></section>
<section class="card" id="policy"><div class="section-heading"><span class="section-number">02</span><div><h2>上传策略</h2><p>以下选项只影响保存设置后新上传的图片。</p></div></div>
<label class="option-row" for="enabled"><span class="switch"><input id="enabled" type="checkbox" name="enabled" value="1" <?php echo $c['enabled'] ? 'checked' : ''; ?>><span aria-hidden="true"></span></span><span class="option-copy"><strong>启用新图片自动上传</strong><small>开启后，编辑器新上传的图片自动转存至云端，并插入云端链接；关闭后恢复 Z-Blog 本地上传。</small></span></label>
<label class="option-row" for="keep_local"><span class="switch"><input id="keep_local" type="checkbox" name="keep_local" value="1" <?php echo $c['keep_local'] ? 'checked' : ''; ?>><span aria-hidden="true"></span></span><span class="option-copy"><strong>保留本地图片副本 <em>建议开启</em></strong><small>开启后，即使云端上传成功，也在网站服务器保留副本；关闭后，仅在云端上传成功时删除本次本地副本。</small></span></label>
<div class="field-grid policy-fields"><div class="field"><label for="prefix">对象目录前缀</label><input id="prefix" name="prefix" type="text" maxlength="120" value="<?php echo BitifulS4_Escape($c['prefix']); ?>" aria-describedby="prefix-help"><small id="prefix-help">例如 blog；允许留空。按年月分目录，文件名为 12 位随机字符，例如 blog/2026/10/a7Kp2mQ9xB4r.png。</small></div><div class="field"><label for="max_mb">图片大小上限 <span>MiB</span></label><input id="max_mb" name="max_mb" type="number" min="1" max="100" required value="<?php echo (int) $c['max_mb']; ?>" aria-describedby="size-help"><small id="size-help">1–100 MiB，同时受 PHP、Web 服务器和 Z-Blog 限制。</small></div></div>
<div class="inline-note"><strong>失败时自动回退</strong><span>云端同步失败时，无论是否保留副本，都保留本次本地图片并返回本地链接；错误原因写入 PHP 日志。</span></div>
</section><div class="save-bar"><p>保存不会迁移、补回或清理历史图片。</p><button type="submit">保存设置</button></div>
</form>
<section class="card" id="test"><div class="section-heading"><span class="section-number">03</span><div><h2>验证上传</h2><p>保存设置后，上传一张极小的 PNG，检查云端写入是否正常。</p></div></div>
<form method="post" action="main.php" class="test-actions"><input type="hidden" name="csrf" value="<?php echo BitifulS4_Escape($token); ?>"><input type="hidden" name="action" value="test"><button class="secondary" type="submit">上传测试图片 ↗</button><span>使用已保存的配置</span></form><p class="muted test-note">成功后打开返回的图片链接，确认域名、证书和读权限正常。测试不会修改文章，测试图片也按「前缀/年份/月份/12位随机名称.png」保存，可在云端控制台手动删除。</p></section>
</div>
<aside class="side-column"><section class="card compact"><h2>快速开始</h2><ol class="steps"><li><strong>准备存储空间</strong><span>创建桶和有写权限的子账户，配置长期可读取的图片域名。</span></li><li><strong>填写并保存</strong><span>输入 API 域名、桶名和密钥，建议保留本地副本。</span></li><li><strong>验证再使用</strong><span>打开测试图片，再到编辑器上传新图，检查预览与发布效果。</span></li></ol></section>
<section class="card compact"><h2>运行环境</h2><dl class="environment"><div><dt>Z-BlogPHP</dt><dd>1.7.3+</dd></div><div><dt>PHP</dt><dd><?php echo BitifulS4_Escape(PHP_VERSION); ?><span class="<?php echo version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'missing'; ?>"><?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '可用' : '需 7.4+'; ?></span></dd></div><?php foreach (array('curl' => 'cURL', 'fileinfo' => 'Fileinfo', 'ctype' => 'Ctype', 'simplexml' => 'SimpleXML') as $ext => $label): ?><div><dt><?php echo BitifulS4_Escape($label); ?></dt><dd class="<?php echo extension_loaded($ext) ? 'ok' : 'missing'; ?>"><?php echo extension_loaded($ext) ? '已启用' : '未启用'; ?></dd></div><?php endforeach; ?></dl><p class="muted">服务器需可通过 HTTPS 访问存储 API；本地 upload 目录需可写。Fileinfo 建议开启，未开启时使用 PHP 图片类型解析校验。</p></section>
<section class="card compact side-summary"><h2>保持你的写作习惯</h2><p>在调用 Z-Blog 系统上传接口的 Markdown 或富文本编辑器中，照常点击上传图片即可。</p><div class="mini-tags"><span>HTTPS</span><span>S3 V4</span><span>MIT 许可</span></div></section></aside></div>
<section class="card guide" id="guide"><div class="section-heading"><span class="section-number">04</span><div><h2>功能介绍与常见问题</h2><p>先了解开关的生效范围，使用时更放心。</p></div></div>
<div class="feature-grid"><article><span aria-hidden="true">01 / 上传</span><h3>系统级图片转存</h3><p>接管 Z-Blog 图片上传接口，服务器将图片发送到云端，编辑器获得图片链接。无需为每个兼容编辑器分别配置。</p></article><article><span aria-hidden="true">02 / 保障</span><h3>本地副本与失败回退</h3><p>云端成功后按设置保留或删除本次本地副本；上传失败时始终保留本地图片，并使用本地链接。</p></article><article><span aria-hidden="true">03 / 地址</span><h3>独立域名与云端图片管理</h3><p>支持自己的 HTTPS 图片域名。<a href="manager.php">附件管理（云端）</a>可浏览、检测文章引用、预览、复制链接及清理本页未引用图片。</p></article></div>
<div class="table-scroll" role="region" aria-label="本地副本行为对照" tabindex="0"><table><caption>“保留本地图片副本”行为对照</caption><thead><tr><th scope="col">设置与结果</th><th scope="col">本次本地副本</th><th scope="col">插入文章的链接</th><th scope="col">以前上传的图片</th></tr></thead><tbody><tr><th scope="row">开启 · 云端成功</th><td>保留</td><td>云端链接</td><td>不变</td></tr><tr><th scope="row">关闭 · 云端成功</th><td>删除</td><td>云端链接</td><td>不变</td></tr><tr><th scope="row">开启或关闭 · 云端失败</th><td>保留</td><td>本地链接</td><td>不变</td></tr></tbody></table></div>
<div class="faq-list">
<details open><summary>“启用新图片自动上传”是什么意思？</summary><div><p>开启后，在编辑器中新上传的图片会自动转存到云端，云端成功后将图片访问链接插入文章。关闭后，新图片按 Z-Blog 原来的方式保存在网站服务器上。</p><p>它不会迁移旧图片，也不会自动转存粘贴在 Markdown 中的已有远程图片链接。通过系统上传接口提交的 Base64 图片会处理，直接写入正文的 data: 图片不会触发上传。</p></div></details>
<details><summary>开启“保留本地图片副本”后，云端上传成功也会保存本地吗？</summary><div><p>会。开启后，云端和网站服务器各保留一份，文章使用云端链接，本地副本位于 <code>zb_users/upload/</code> 下。</p><p>关闭后，仅在云端上传成功时删除本次本地副本；若云端失败，仍会保留本地图片并使用本地链接。</p></div></details>
<details><summary>切换这两个开关，会影响以前已经上传的图片吗？</summary><div><p><strong>只影响保存设置后新上传的图片。</strong>开启本地副本不会补回以前已删除的副本；关闭不会清理以前保留的副本；切换自动上传不会迁移旧图片。已有图片及文章链接保持原样。</p></div></details>
<details><summary>修改 API、桶名或图片域名，会重写旧图片链接吗？</summary><div><p>不会。这些配置只用于新上传；已转存附件保存它自己的云端地址。旧图片仍依赖原来的桶、域名和对象存在。需要迁移时，应单独规划文件同步和链接替换。</p></div></details>
<details><summary>停用插件或删除文章附件，会删除云端图片吗？</summary><div><p>从 Z-Blog 删除附件或停用插件时，不会自动删除云端图片。需要手动删除时，可进入“云端图片管理”，选择图片并二次确认；也可先检测文章引用，再批量选择本页“未发现引用”的图片进行清理。批量删除没有上传时间限制，执行前会重新复查。已经写入文章的链接不会自动替换。</p><p>完全停用插件后，附件管理回到本地地址；如果此前没有保留本地副本，相关附件在后台可能无法查看。初次使用建议开启本地副本。</p></div></details>
<details><summary>可以用在其他 Z-Blog 网站和 S3 服务上吗？</summary><div><p>可以免费在多个 Z-BlogPHP 网站安装使用，无付费激活限制。免费的是插件本身，云存储和流量按服务商规则计费。要求 Z-BlogPHP 1.7.3+、PHP 7.4+ 和相应扩展。</p><p>本插件以缤纷云为主要适配对象。可自定义 API 域名，但仍采用 HTTPS、V4 签名和虚拟主机式寻址；其他 S3 服务需满足这些条件并自行测试，不代表已全面适配。当前仅支持公网 IPv4 上传端点。</p></div></details>
<details><summary>支持哪些图片？会迁移历史附件或远程图片吗？</summary><div><p>处理 JPG/JPEG、PNG、GIF、WebP、AVIF、BMP、ICO，并检查实际文件类型。SVG 和其他附件仍交给系统原有流程。云端图片管理按当前桶和目录列举已有图片，但不执行历史迁移、远程图片抓取、压缩或水印。删除前应确认图片没有被文章引用。</p><p>不要同时启用其他会接管附件上传、路径或 URL 的存储插件。密钥只在服务器端使用，后台不回显；浏览器不直传到 API，通常无需为此配置上传 CORS。</p></div></details>
</div></section>
<footer class="page-footer"><span>缤纷云 S4 图片存储 · v1.3.6 · MIT</span><span>独立开源插件，非缤纷云或 Z-Blog 官方产品</span></footer>
</main></body></html>
