<?php
if (!defined('ZBP_PATH')) { exit('Access denied'); }
require_once __DIR__ . '/lib/S3Client.php';
require_once __DIR__ . '/lib/References.php';
RegisterPlugin('bitiful_s4', 'ActivePlugin_bitiful_s4');

function ActivePlugin_bitiful_s4()
{
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveFile', 'BitifulS4_SaveFile');
    Add_Filter_Plugin('Filter_Plugin_Upload_SaveBase64File', 'BitifulS4_SaveBase64');
    foreach (array('Filter_Plugin_PostArticle_Succeed', 'Filter_Plugin_PostPage_Succeed', 'Filter_Plugin_DelArticle_Succeed', 'Filter_Plugin_DelPage_Succeed') as $hook) {
        Add_Filter_Plugin($hook, 'BitifulS4_InvalidateReferences');
    }
    // Keep resolving already-synced attachment URLs even when new uploads are paused.
    Add_Filter_Plugin('Filter_Plugin_Upload_Url', 'BitifulS4_Url');
    Add_Filter_Plugin('Filter_Plugin_Admin_UploadMng_Table', 'BitifulS4_LocalAttachmentRow');
}

function BitifulS4_Defaults()
{
    return array('enabled' => 0, 'endpoint' => 's3.bitiful.net', 'bucket' => '', 'region' => 'cn-east-1', 'access_key' => '', 'secret_key' => '',
        'public_url' => '', 'prefix' => 'blog', 'keep_local' => 1, 'max_mb' => 20);
}

function BitifulS4_Settings()
{
    global $zbp;
    $result = BitifulS4_Defaults();
    $config = $zbp->Config('bitiful_s4');
    foreach ($result as $key => $default) {
        if (isset($config->$key)) { $result[$key] = $config->$key; }
    }
    // Optional secrets in server-side configuration override database settings.
    if (defined('BITIFUL_S4_ACCESS_KEY')) { $result['access_key'] = BITIFUL_S4_ACCESS_KEY; }
    if (defined('BITIFUL_S4_SECRET_KEY')) { $result['secret_key'] = BITIFUL_S4_SECRET_KEY; }
    return $result;
}

function BitifulS4_ValidateSettings(array $c)
{
    BitifulS4Client::validate($c);
    $u = parse_url($c['public_url']);
    if (!$u || !isset($u['scheme'], $u['host']) || strtolower($u['scheme']) !== 'https'
        || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || isset($u['port'])
        || !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $u['host'])
        || strpos($u['host'], '.') === false || filter_var($u['host'], FILTER_VALIDATE_IP)
        || preg_match('/[\x00-\x20\x7f<>\x22\x27\\\\]/', $c['public_url'])) {
        throw new InvalidArgumentException('图片访问地址必须是完整的 HTTPS 域名地址，可带路径前缀，不带账号、端口、查询参数或片段。');
    }
    if ($c['prefix'] !== '') { BitifulS4Client::encodeKey($c['prefix']); }
    if (!preg_match('~^[A-Za-z0-9/_-]*$~D', $c['prefix']) || strlen($c['prefix']) > 120) {
        throw new InvalidArgumentException('对象前缀仅支持字母、数字、短横线、下划线及斜杠，最多 120 字符。');
    }
    if ((int) $c['max_mb'] < 1 || (int) $c['max_mb'] > 100) {
        throw new InvalidArgumentException('图片大小上限应为 1–100 MiB。');
    }
}

function BitifulS4_Eligible($upload, array $c)
{
    if (empty($c['enabled'])) { return false; }
    $ext = strtolower(pathinfo($upload->Name, PATHINFO_EXTENSION));
    // Exclude SVG/HTML and executable attachments. Their system upload flow is unchanged.
    return in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'), true);
}

function BitifulS4_ImageMime($path, $name)
{
    // Some hosts disable Fileinfo. PHP's image parser can still validate the
    // image type without trusting the browser's Content-Type or the filename.
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    } elseif (function_exists('getimagesize')) {
        $image = @getimagesize($path);
        $mime = is_array($image) && isset($image['mime']) ? $image['mime'] : false;
    } else {
        throw new RuntimeException('服务器无法校验图片类型，请启用 PHP Fileinfo 扩展。');
    }
    $types = array('jpg' => array('image/jpeg'), 'jpeg' => array('image/jpeg'),
        'png' => array('image/png'), 'gif' => array('image/gif'), 'webp' => array('image/webp'),
        'avif' => array('image/avif'), 'bmp' => array('image/bmp', 'image/x-ms-bmp'),
        'ico' => array('image/x-icon', 'image/vnd.microsoft.icon'));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset($types[$ext]) || !in_array($mime, $types[$ext], true)) {
        throw new RuntimeException('图片扩展名与实际内容不符，已拒绝上传。');
    }
    return $mime;
}

/** A compact 12-character random name using only ASCII letters and digits. */
function BitifulS4_NewImageName($extension)
{
    $extension = strtolower($extension);
    if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'), true)) {
        throw new InvalidArgumentException('不支持的图片扩展名。');
    }
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $name = '';
    while (strlen($name) < 12) {
        foreach (unpack('C*', random_bytes(16)) as $byte) {
            // Rejection sampling avoids bias when mapping bytes to 62 symbols.
            if ($byte >= 248) { continue; }
            $name .= $alphabet[$byte % 62];
            if (strlen($name) === 12) { break; }
        }
    }
    return $name . '.' . $extension;
}

/** Use one directory rule for editor uploads and the upload test. */
function BitifulS4_ObjectKey(array $c, $name, $timestamp = null)
{
    $prefix = $c['prefix'] !== '' ? $c['prefix'] . '/' : '';
    return $prefix . date('Y/m/', $timestamp === null ? time() : (int) $timestamp) . $name;
}

function BitifulS4_LocalTarget($upload)
{
    global $zbp;
    $root = realpath($zbp->usersdir . 'upload');
    if ($root === false) {
        if (!is_dir($zbp->usersdir . 'upload') && !@mkdir($zbp->usersdir . 'upload', 0755, true)) {
            throw new RuntimeException('无法创建本地 upload 目录。');
        }
        $root = realpath($zbp->usersdir . 'upload');
    }
    $dir = str_replace('\\', '/', $upload->Dir);
    if (!preg_match('~^upload/(?:[0-9]{4}/[0-9]{2}/(?:[0-9]{2}/)?)$~D', $dir)
        || basename($upload->Name) !== $upload->Name || strpos($upload->Name, '\\') !== false
        || preg_match('/[\x00-\x1f\x7f]/', $upload->Name)) {
        throw new RuntimeException('上传目录被其他插件改写，无法安全保存。请关闭其他存储插件。');
    }
    $folder = $zbp->usersdir . $dir;
    if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
        throw new RuntimeException('无法创建本地图片目录。');
    }
    $resolved = realpath($folder);
    $base = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $check = rtrim(str_replace('\\', '/', $resolved), '/') . '/';
    if (DIRECTORY_SEPARATOR === '\\') { $base = strtolower($base); $check = strtolower($check); }
    if (!$root || !$resolved || strpos($check, $base) !== 0) {
        throw new RuntimeException('本地保存路径超出 upload 目录。');
    }
    // New ASCII filename avoids Windows encoding problems and accidental overwrites.
    $upload->Name = BitifulS4_NewImageName(pathinfo($upload->Name, PATHINFO_EXTENSION));
    return $resolved . DIRECTORY_SEPARATOR . $upload->Name;
}

function BitifulS4_Finish($upload, $local, $mime, array $c)
{
    try {
        BitifulS4_ValidateSettings($c);
        $key = BitifulS4_ObjectKey($c, $upload->Name, $upload->PostTime);
        $client = new BitifulS4Client($c);
        $client->putFile($key, $local, $mime);
        // Persist the exact object location; future config changes do not rewrite old files.
        $upload->Metas->bitiful_s4_url = rtrim($c['public_url'], '/') . '/' . BitifulS4Client::encodeKey($key);
        $upload->Metas->bitiful_s4_key = $key;
        $upload->Metas->bitiful_s4_bucket = $c['bucket'];
        $upload->Metas->bitiful_s4_endpoint = $c['endpoint'];
        $upload->Metas->bitiful_s4_error = '';
        if (empty($c['keep_local'])) { @unlink($local); }
    } catch (Throwable $e) {
        // Local file already exists. Do not let the editor insert an unverified cloud URL.
        $upload->Metas->bitiful_s4_url = '';
        $upload->Metas->bitiful_s4_error = $e->getMessage();
        error_log('[bitiful_s4] ' . $e->getMessage());
    }
    return true;
}

function BitifulS4_SaveFile($tmp, $upload)
{
    $c = BitifulS4_Settings();
    if (!BitifulS4_Eligible($upload, $c)) { return; }
    $GLOBALS['hooks']['Filter_Plugin_Upload_SaveFile'][__FUNCTION__] = PLUGIN_EXITSIGNAL_RETURN;
    try {
        $upload->Metas->bitiful_s4_save_error = '';
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('上传临时文件无效或已丢失，请检查 PHP 临时目录及其他图片处理插件。');
        }
        if (!$upload->CheckExtName()) {
            throw new RuntimeException('该图片扩展名未在 Z-Blog 的允许上传类型中启用。');
        }
        $size = filesize($tmp);
        if ($size === false || $size <= 0) { throw new RuntimeException('上传的图片为空或无法读取。'); }
        $upload->Size = $size;
        if (!$upload->CheckSize()) { throw new RuntimeException('图片超过 Z-Blog 系统设置的上传大小上限。'); }
        if ($size > (int) $c['max_mb'] * 1048576) {
            throw new RuntimeException('图片超过缤纷云插件设置的上传大小上限。');
        }
        $mime = BitifulS4_ImageMime($tmp, $upload->Name);
        $local = BitifulS4_LocalTarget($upload);
        if (!move_uploaded_file($tmp, $local)) { throw new RuntimeException('图片无法保存到本地，请检查目录权限。'); }
        $upload->Size = filesize($local);
        $upload->MimeType = $mime;
        return BitifulS4_Finish($upload, $local, $mime, $c);
    } catch (Throwable $e) {
        error_log('[bitiful_s4] ' . $e->getMessage());
        $upload->Metas->bitiful_s4_save_error = $e instanceof RuntimeException || $e instanceof InvalidArgumentException
            ? $e->getMessage() : '图片保存失败，请查看服务器日志中的 [bitiful_s4] 错误记录。';
        return false;
    }
}

function BitifulS4_SaveBase64($data, $upload)
{
    $c = BitifulS4_Settings();
    if (!BitifulS4_Eligible($upload, $c)) { return; }
    $GLOBALS['hooks']['Filter_Plugin_Upload_SaveBase64File'][__FUNCTION__] = PLUGIN_EXITSIGNAL_RETURN;
    try {
        $upload->Metas->bitiful_s4_save_error = '';
        $limit = (int) $c['max_mb'] * 1048576;
        if (!is_string($data) || strlen($data) > (int) ceil($limit / 3) * 4 + 4) {
            throw new RuntimeException('Base64 图片过大。');
        }
        $bytes = base64_decode($data, true);
        if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > $limit) {
            throw new RuntimeException('Base64 图片无效或过大。');
        }
        $upload->Size = strlen($bytes);
        if (!$upload->CheckExtName() || !$upload->CheckSize()) { throw new RuntimeException('图片未通过系统类型或大小检查。'); }
        // Validate MIME before writing into any web-accessible directory.
        $temp = tmpfile();
        if ($temp === false) { throw new RuntimeException('无法创建临时图片。'); }
        try {
            if (fwrite($temp, $bytes) !== strlen($bytes)) { throw new RuntimeException('临时图片写入失败。'); }
            $path = stream_get_meta_data($temp)['uri'];
            $mime = BitifulS4_ImageMime($path, $upload->Name);
            $local = BitifulS4_LocalTarget($upload);
            if (!copy($path, $local)) { throw new RuntimeException('本地图片写入失败。'); }
        } finally { fclose($temp); }
        $upload->MimeType = $mime;
        return BitifulS4_Finish($upload, $local, $mime, $c);
    } catch (Throwable $e) {
        error_log('[bitiful_s4] ' . $e->getMessage());
        $upload->Metas->bitiful_s4_save_error = $e instanceof RuntimeException || $e instanceof InvalidArgumentException
            ? $e->getMessage() : '图片保存失败，请查看服务器日志中的 [bitiful_s4] 错误记录。';
        return false;
    }
}

function BitifulS4_Url($upload)
{
    $url = $upload->Metas->bitiful_s4_url;
    // Hook must return null for local/other-provider attachments (Z-Blog >= 1.7.3).
    if (is_string($url) && strpos($url, 'https://') === 0 && !preg_match('/[\x00-\x20\x7f]/', $url)) { return $url; }
    return null;
}

/** Show the local copy in native attachment management (also used by Uplist).
 * Keep Upload::Url unchanged for editor upload responses and published content.
 */
function BitifulS4_LocalAttachmentRow($upload, &$row, &$headers)
{
    global $zbp;
    if (!is_string($upload->Metas->bitiful_s4_url) || $upload->Metas->bitiful_s4_url === '' || !isset($row[3])) { return; }
    $localUrl = $zbp->host . 'zb_users/' . $upload->Dir . rawurlencode($upload->Name);
    $row[3] = '<td><a href="' . htmlspecialchars($localUrl) . '" target="_blank"><i class="icon-link-45deg"></i></a> ' . htmlspecialchars($upload->Name) . '</td>';
}

function InstallPlugin_bitiful_s4()
{
    global $zbp;
    $c = $zbp->Config('bitiful_s4');
    foreach (BitifulS4_Defaults() as $k => $v) { if (!isset($c->$k)) { $c->$k = $v; } }
    $zbp->SaveConfig('bitiful_s4');
}
function UninstallPlugin_bitiful_s4()
{
    // Preserve configuration, attachment metadata, local copies and cloud objects.
}

/** Restrict cloud management to images under the currently configured prefix. */
function BitifulS4_ValidateManagedKey(array $c, $key)
{
    if (!is_string($key) || strlen($key) > 1024) { throw new InvalidArgumentException('图片路径无效或过长。'); }
    BitifulS4Client::encodeKey($key);
    $prefix = $c['prefix'] !== '' ? $c['prefix'] . '/' : '';
    if ($prefix !== '' && strpos($key, $prefix) !== 0) {
        throw new InvalidArgumentException('该图片不在当前配置的管理目录内。');
    }
    if (!in_array(strtolower(pathinfo($key, PATHINFO_EXTENSION)), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'), true)) {
        throw new InvalidArgumentException('只允许管理支持的图片格式。');
    }
    return true;
}

function BitifulS4_ManagementScope(array $c)
{
    return hash('sha256', json_encode(array($c['endpoint'], $c['bucket'], $c['prefix'], $c['public_url'])));
}

function BitifulS4_InvalidateReferences($unused = null) { BitifulS4References::invalidate(); }
