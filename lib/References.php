<?php
if (!defined('ZBP_PATH')) { exit('Access denied'); }

final class BitifulS4References
{
    const TTL = 600;
    const MAX_CACHE = 16777216;

    private static function directory()
    {
        global $zbp;
        $dir = rtrim($zbp->usersdir, '/\\') . '/cache/bitiful_s4';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { throw new RuntimeException('引用检测缓存目录不可写。'); }
        return $dir;
    }
    private static function write($path, array $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_CACHE) { throw new RuntimeException('检测数据过大或编码无效，未生成可清理结果。'); }
        $encoded = base64_encode($json);
        if (strlen($encoded) > self::MAX_CACHE) { throw new RuntimeException('引用检测缓存达到大小上限。'); }
        $bytes = "<?php exit; ?>\n" . $encoded;
        if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('无法保存完整的引用检测结果。'); }
    }
    private static function read($path)
    {
        if (!is_file($path)) { return null; }
        $file = @fopen($path, 'rb');
        if (!$file) { return null; }
        try {
            if (!flock($file, LOCK_SH)) { return null; }
            $raw = stream_get_contents($file, self::MAX_CACHE + 100);
            if (!is_string($raw) || strlen($raw) > self::MAX_CACHE + 20 || strpos($raw, "<?php exit; ?>\n") !== 0) { return null; }
            $decoded = base64_decode(substr($raw, strlen("<?php exit; ?>\n")), true);
            $data = $decoded === false ? null : json_decode($decoded, true);
            return is_array($data) ? $data : null;
        } finally { fclose($file); }
    }
    public static function revision()
    {
        $path = self::directory() . '/revision.php';
        $data = self::read($path);
        if ($data && isset($data['revision'])) { return $data['revision']; }
        $value = bin2hex(random_bytes(16));
        self::write($path, array('revision' => $value));
        return $value;
    }
    public static function invalidate($unused = null)
    {
        try { self::write(self::directory() . '/revision.php', array('revision' => bin2hex(random_bytes(16)))); }
        catch (Throwable $e) { error_log('[bitiful_s4] reference cache invalidation failed'); }
    }
    private static function cachePath(array $c) { return self::directory() . '/references-' . BitifulS4_ManagementScope($c) . '.php'; }
    public static function load(array $c)
    {
        try {
            $data = self::read(self::cachePath($c));
            if (!$data || ($data['version'] ?? 0) !== 2 || !isset($data['created'], $data['revision'], $data['scope'], $data['id'])) { return null; }
            if ($data['created'] > time() || time() - $data['created'] > self::TTL
                || !hash_equals(BitifulS4_ManagementScope($c), $data['scope']) || !hash_equals(self::revision(), $data['revision'])) { return null; }
            return $data;
        } catch (Throwable $e) { return null; }
    }
    private static function query(array $select, $where = null, $order = null, $limit = null)
    {
        global $zbp;
        $rows = $zbp->db->Query($zbp->db->sql->Select($zbp->table['Post'], $select, $where, $order, $limit));
        if (!is_array($rows)) { throw new RuntimeException('文章查询失败，检测未完成，不能批量清理。'); }
        return $rows;
    }
    private static function snapshot()
    {
        $rows = self::query(array('COUNT(log_ID) AS bf_count', 'MAX(log_ID) AS bf_max', 'MAX(log_UpdateTime) AS bf_update'));
        if (count($rows) !== 1 || !array_key_exists('bf_count', $rows[0])) { throw new RuntimeException('无法确认文章总量，检测未完成。'); }
        return array('count' => (int) $rows[0]['bf_count'], 'max' => (int) $rows[0]['bf_max'], 'update' => (int) $rows[0]['bf_update']);
    }
    private static function record(array &$state, $group, $hash, $post)
    {
        if (!isset($state[$group][$hash])) { $state[$group][$hash] = array(); }
        // Keep up to 12 example references for each identity; existence is never lost.
        if (count($state[$group][$hash]) < 12) { $state[$group][$hash][$post['id']] = 1; }
        $state['posts'][$post['id']] = $post;
    }
    private static function nameHash($name) { return hash('sha256', strtolower($name)); }
    private static function token($token, array $post, array &$state, array $c)
    {
        $token = trim($token);
        $token = rtrim($token, '.,;:)]}');
        if ($token === '' || strlen($token) > 8192) { return; }
        $absolute = preg_match('~^(?:https?:)?//~i', $token);
        $url = parse_url(strpos($token, '//') === 0 ? 'https:' . $token : $token);
        if ($url === false || !isset($url['path'])) { return; }
        $path = rawurldecode($url['path']);
        $name = basename($path);
        if (!preg_match('/\.(?:jpe?g|png|gif|webp|avif|bmp|ico)$/iD', $name)) { return; }
        self::record($state, 'names', self::nameHash($name), $post);
        $public = parse_url($c['public_url']);
        $known = !$absolute || (isset($url['host']) && in_array(strtolower($url['host']), array(strtolower($public['host']), strtolower($c['bucket'] . '.' . $c['endpoint'])), true));
        if (!$known) { return; }
        $paths = array(ltrim($path, '/'));
        $base = rtrim(rawurldecode($public['path'] ?? ''), '/');
        if ($base !== '' && strpos($path, $base . '/') === 0) { $paths[] = substr($path, strlen($base) + 1); }
        if (strpos($path, '/' . $c['bucket'] . '/') === 0) { $paths[] = substr($path, strlen($c['bucket']) + 2); }
        foreach (array_unique($paths) as $key) {
            try { BitifulS4_ValidateManagedKey($c, $key); } catch (Throwable $e) { continue; }
            self::record($state, 'paths', hash('sha256', $key), $post);
        }
    }
    public static function collectText($text, array $post, array &$state, array $c)
    {
        if (!is_string($text) || $text === '') { return; }
        global $zbp;
        $text = str_replace(array('{$host}', '{#ZC_BLOG_HOST#}'), (string) ($zbp->host ?? ''), $text);
        $text = html_entity_decode(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace_callback('~\\\\u([0-9a-f]{4})~i', function ($m) { return html_entity_decode('&#x' . $m[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8'); }, $text);
        $text = str_replace('\\/', '/', $text);
        $text = preg_replace('~\\\\([_()\[\]{}#\~+\-])~', '$1', $text);
        $tokens = array();
        // Quoted attributes preserve spaces in image filenames; broad URL tokens cover Markdown/srcset/JSON/lazy-loading.
        if (preg_match_all('~\b(?:src|href|data-src|data-original|poster)\s*=\s*([\x22\x27])(.*?)\1~isu', $text, $m) === false) { $state['complete'] = false; return; }
        $tokens = array_merge($tokens, $m[2]);
        if (preg_match_all('~(?:(?:https?:)?//|[A-Za-z0-9_.%+\-]+/|/)[^\s<>\x22\x27\x60\[\]{}]+~iu', $text, $m) === false) { $state['complete'] = false; return; }
        $tokens = array_merge($tokens, $m[0]);
        // Filename-only mentions are deliberately ambiguous, never automatic deletion candidates.
        if (preg_match_all('~[^\s<>\x22\x27/\\\\()\[\]{}=]+\.(?:jpe?g|png|gif|webp|avif|bmp|ico)(?=[\s<>\x22\x27?#),;\]]|$)~iu', $text, $m) === false) { $state['complete'] = false; return; }
        $tokens = array_merge($tokens, $m[0]);
        foreach (array_unique($tokens) as $value) { self::token($value, $post, $state, $c); }
    }
    private static function collectMeta($value, array $post, array &$state, array $c, $depth = 0)
    {
        if ($depth > 16 || is_object($value)) { $state['complete'] = false; return; }
        if (is_array($value)) { foreach ($value as $part) { self::collectMeta($part, $post, $state, $c, $depth + 1); } }
        elseif (is_string($value)) {
            self::collectText($value, $post, $state, $c);
            $trim = ltrim($value);
            if ($trim !== '' && in_array($trim[0], array('{', '['), true)) {
                $json = json_decode($value, true, 32);
                if (is_array($json)) { self::collectMeta($json, $post, $state, $c, $depth + 1); }
            }
        }
    }
    public static function scan(array $c)
    {
        $cache = self::cachePath($c);
        if (is_file($cache)) { @unlink($cache); } // A failed new scan must not leave an old 'unused' result active.
        $revision = self::revision(); $snapshot = self::snapshot(); $start = microtime(true);
        $state = array('version' => 2, 'id' => bin2hex(random_bytes(16)), 'scope' => BitifulS4_ManagementScope($c), 'revision' => $revision,
            'created' => time(), 'complete' => true, 'count' => 0, 'paths' => array(), 'names' => array(), 'posts' => array());
        $last = 0;
        while ($last < $snapshot['max']) {
            $rows = self::query(array('log_ID', 'log_Type', 'log_Status', 'log_Title', 'log_Intro', 'log_Content', 'log_Meta'),
                array(array('>', 'log_ID', $last), array('<=', 'log_ID', $snapshot['max'])), array('log_ID' => 'ASC'), array(200));
            if (!$rows) { throw new RuntimeException('文章扫描不完整，已停止自动清理。'); }
            foreach ($rows as $row) {
                foreach (array('log_ID', 'log_Type', 'log_Status', 'log_Title', 'log_Intro', 'log_Content', 'log_Meta') as $field) {
                    if (!array_key_exists($field, $row)) { throw new RuntimeException('文章查询字段不完整，不能自动清理。'); }
                }
                $id = (int) $row['log_ID']; if ($id <= $last) { throw new RuntimeException('文章扫描顺序异常。'); } $last = $id;
                $post = array('id' => $id, 'title' => (string) $row['log_Title'], 'type' => (int) $row['log_Type'], 'status' => (int) $row['log_Status']);
                self::collectText($row['log_Content'], $post, $state, $c); self::collectText($row['log_Intro'], $post, $state, $c);
                $raw = (string) $row['log_Meta'];
                if ($raw !== '') {
                    self::collectText($raw, $post, $state, $c);
                    $meta = @unserialize($raw, array('allowed_classes' => false));
                    if ($meta === false && $raw !== 'b:0;') { $state['complete'] = false; }
                    else { self::collectMeta($meta, $post, $state, $c); }
                }
                ++$state['count'];
                if (microtime(true) - $start > 18 || memory_get_usage(true) > 100663296) { throw new RuntimeException('检测达到运行资源上限，未生成可清理结果。请勿直接删除；需进一步分段检测。'); }
            }
        }
        if ($state['count'] !== $snapshot['count'] || self::snapshot() !== $snapshot || !hash_equals(self::revision(), $revision)) {
            throw new RuntimeException('扫描期间文章发生变化，请重新检测。');
        }
        self::write($cache, $state);
        return $state;
    }
    public static function status($index, $key)
    {
        if (!is_array($index)) { return array('state' => 'unscanned', 'ids' => array()); }
        $hash = hash('sha256', $key);
        if (!empty($index['paths'][$hash])) { return array('state' => 'used', 'ids' => array_keys($index['paths'][$hash])); }
        if (empty($index['complete'])) { return array('state' => 'unknown', 'ids' => array()); }
        $name = self::nameHash(basename($key));
        if (!empty($index['names'][$name])) { return array('state' => 'unknown', 'ids' => array_keys($index['names'][$name])); }
        return array('state' => 'unused', 'ids' => array());
    }
    public static function unusedOrFail(array $index, array $keys)
    {
        if (empty($index['complete'])) { throw new RuntimeException('检测存在无法解析的文章数据，不能批量清理。'); }
        foreach ($keys as $key) { if (self::status($index, $key)['state'] !== 'unused') { throw new RuntimeException('选中图片的引用状态已变化或无法判断，请重新检测并选择。'); } }
    }
    public static function signManifest(array $c, array $index, array $items, $kind, $token)
    {
        $data = array('scope' => BitifulS4_ManagementScope($c), 'scan' => $index['id'], 'issued' => time(), 'kind' => $kind, 'items' => $items);
        $payload = base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
        return array('manifest' => $payload, 'mac' => hash_hmac('sha256', $payload, $token));
    }
    public static function verifyManifest(array $c, $payload, $mac, $kind, $token)
    {
        if (!is_string($payload) || strlen($payload) > 131072 || !is_string($mac) || !hash_equals(hash_hmac('sha256', $payload, $token), $mac)) { throw new RuntimeException('批量选择校验失败。'); }
        $decoded = base64_decode($payload, true); $data = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($data) || ($data['kind'] ?? '') !== $kind || ($data['scope'] ?? '') !== BitifulS4_ManagementScope($c)
            || !isset($data['issued'], $data['scan'], $data['items']) || !is_int($data['issued']) || $data['issued'] > time() || time() - $data['issued'] > self::TTL
            || !is_array($data['items']) || count($data['items']) > 48) { throw new RuntimeException('批量确认已过期或管理范围已变化，请重新检测。'); }
        foreach ($data['items'] as $key => $size) { BitifulS4_ValidateManagedKey($c, $key); if (!is_int($size) || $size < 0) { throw new RuntimeException('批量文件大小无效。'); } }
        return $data;
    }
}
