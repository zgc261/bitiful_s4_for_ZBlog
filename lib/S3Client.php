<?php
/** Small, streaming, SigV4 client dedicated to Bitiful. PHP 7.4+. MIT. */
if (!defined('ZBP_PATH')) { exit('Access denied'); }

final class BitifulS4Client
{
    private $config;

    public function __construct(array $config)
    {
        $config = array_merge(array('endpoint' => 's3.bitiful.net'), $config);
        self::validate($config);
        $config['endpoint'] = strtolower($config['endpoint']);
        $this->config = $config;
    }

    public static function validate(array $c)
    {
        self::validateEndpoint(isset($c['endpoint']) ? $c['endpoint'] : 's3.bitiful.net');
        if (!isset($c['bucket']) || !preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $c['bucket'])) {
            throw new InvalidArgumentException('存储桶名应为 3–63 位小写字母、数字或短横线，不含点。');
        }
        if (!isset($c['region']) || !preg_match('/^[a-z0-9-]{1,40}$/D', $c['region'])) {
            throw new InvalidArgumentException('Region 格式不正确。');
        }
        if (strlen($c['bucket'] . '.' . (isset($c['endpoint']) ? $c['endpoint'] : 's3.bitiful.net')) > 253) {
            throw new InvalidArgumentException('桶名与上传 API 拼接后的域名过长。');
        }
        foreach (array('access_key', 'secret_key') as $name) {
            if (empty($c[$name]) || preg_match('/[\x00-\x20\x7f]/', $c[$name]) || strlen($c[$name]) > 256) {
                throw new InvalidArgumentException('请填写有效的 Access Key 和 Secret Key（保存在服务器，不发送到浏览器）。');
            }
        }
    }

    /** Accept a service domain, never a URL, IP literal, port or path. */
    public static function validateEndpoint($endpoint)
    {
        if (!is_string($endpoint) || strlen($endpoint) > 253 || strpos($endpoint, '.') === false
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $endpoint)
            || filter_var($endpoint, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('上传 API 只填写域名，例如 s3.bitiful.net；不包含 https://、桶名、端口、斜杠或路径。');
        }
    }

    public static function isPublicIPv4($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return false; }
        $n = ip2long($ip);
        foreach (array(array('0.0.0.0', 8), array('10.0.0.0', 8), array('100.64.0.0', 10),
            array('127.0.0.0', 8), array('169.254.0.0', 16), array('172.16.0.0', 12),
            array('192.0.0.0', 24), array('192.0.2.0', 24), array('192.168.0.0', 16),
            array('198.18.0.0', 15), array('198.51.100.0', 24), array('203.0.113.0', 24),
            array('224.0.0.0', 4), array('240.0.0.0', 4)) as $range) {
            $mask = -1 << (32 - $range[1]);
            if (($n & $mask) === (ip2long($range[0]) & $mask)) { return false; }
        }
        return true;
    }

    private static function resolvePublicHost($host)
    {
        $addresses = gethostbynamel($host);
        if (!$addresses) { throw new RuntimeException('上传 API 域名解析失败，请检查 API 域名、桶名及服务器 DNS。'); }
        foreach ($addresses as $ip) {
            if (!self::isPublicIPv4($ip)) {
                throw new RuntimeException('上传 API 必须解析到公网 IPv4 地址，不支持本机、内网或保留地址。');
            }
        }
        return $addresses[0];
    }

    public static function encodeKey($key)
    {
        if (!is_string($key) || $key === '' || $key[0] === '/' || strpos($key, '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new InvalidArgumentException('对象路径无效。');
        }
        $parts = explode('/', $key);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('对象路径不能包含空段或上级路径。');
            }
        }
        return implode('/', array_map('rawurlencode', $parts));
    }

    /** Pure signing routine; exposed for deterministic offline verification. */
    public static function sign($method, $host, $uri, $payloadHash, $contentType, $date, array $c, $query = '')
    {
        $headers = array(
            'content-type' => $contentType,
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
        );
        ksort($headers);
        $canonical = '';
        foreach ($headers as $name => $value) {
            $canonical .= $name . ':' . trim(preg_replace('/\s+/', ' ', $value)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $request = strtoupper($method) . "\n" . $uri . "\n" . $query . "\n" . $canonical . "\n" . $signedHeaders . "\n" . $payloadHash;
        $day = substr($date, 0, 8);
        $scope = $day . '/' . $c['region'] . '/s3/aws4_request';
        $toSign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $request);
        $key = hash_hmac('sha256', $day, 'AWS4' . $c['secret_key'], true);
        $key = hash_hmac('sha256', $c['region'], $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $toSign, $key);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $c['access_key'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        return array('headers' => $headers, 'canonical_request' => $request);
    }

    public function putFile($key, $path, $mime)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('服务器未启用 PHP cURL 扩展。');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('待上传文件不可读取。');
        }
        if (!preg_match('~^image/[a-z0-9.+-]+$~iD', $mime)) {
            throw new InvalidArgumentException('本插件只上传经过校验的图片。');
        }
        $uri = '/' . self::encodeKey($key);
        // Pin a validated public address to prevent private-network DNS rebinding.
        $host = $this->config['bucket'] . '.' . $this->config['endpoint'];
        $address = self::resolvePublicHost($host);
        $hash = hash_file('sha256', $path);
        if ($hash === false) { throw new RuntimeException('无法校验文件。'); }
        $signed = self::sign('PUT', $host, $uri, $hash, $mime, gmdate('Ymd\THis\Z'), $this->config);
        $httpHeaders = array('Expect:', 'Cache-Control: public, max-age=31536000');
        foreach ($signed['headers'] as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        $file = fopen($path, 'rb');
        if ($file === false) { throw new RuntimeException('无法打开待上传文件。'); }
        $curl = curl_init('https://' . $host . $uri);
        if ($curl === false) { fclose($file); throw new RuntimeException('无法初始化上传。'); }
        // Bound error body size; never expose raw XML or signed headers to the UI/log.
        $response = '';
        curl_setopt_array($curl, array(
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $file,
            CURLOPT_INFILESIZE => filesize($path),
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => array($host . ':443:' . $address),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$response) {
                if (strlen($response) < 8192) { $response .= substr($chunk, 0, 8192 - strlen($response)); }
                return strlen($chunk);
            },
        ));
        try {
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
            fclose($file);
        }
        if ($ok === false) {
            throw new RuntimeException('缤纷云连接失败（cURL ' . $errno . '）。检查服务器联网、CA 证书及系统时间。');
        }
        if ($status < 200 || $status >= 300) {
            $code = '';
            if (preg_match('~<Code>([A-Za-z0-9_.-]{1,80})</Code>~', $response, $m)) { $code = ' / ' . $m[1]; }
            throw new RuntimeException('缤纷云上传失败（HTTP ' . $status . $code . '）。检查桶名、Region、密钥及子账户写权限。');
        }
        return true;
    }

    public static function canonicalQuery(array $parameters)
    {
        $pairs = array();
        foreach ($parameters as $key => $value) {
            if (!is_string($value) && !is_int($value)) { throw new InvalidArgumentException('查询参数无效。'); }
            $pairs[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }
        ksort($pairs, SORT_STRING);
        $result = array();
        foreach ($pairs as $key => $value) { $result[] = $key . '=' . $value; }
        return implode('&', $result);
    }

    /** Signed GET/DELETE requests. Upload streaming remains separate. */
    private function controlRequest($method, $uri, array $parameters = array())
    {
        if (!in_array($method, array('GET', 'DELETE'), true)) { throw new InvalidArgumentException('不支持的管理操作。'); }
        if (!extension_loaded('curl')) { throw new RuntimeException('服务器未启用 PHP cURL 扩展。'); }
        $host = $this->config['bucket'] . '.' . $this->config['endpoint'];
        $address = self::resolvePublicHost($host);
        $query = self::canonicalQuery($parameters);
        $signed = self::sign($method, $host, $uri, hash('sha256', ''), 'application/xml', gmdate('Ymd\THis\Z'), $this->config, $query);
        $headers = array();
        foreach ($signed['headers'] as $name => $value) { $headers[] = $name . ': ' . $value; }
        $curl = curl_init('https://' . $host . $uri . ($query !== '' ? '?' . $query : ''));
        if ($curl === false) { throw new RuntimeException('无法初始化云端管理请求。'); }
        $response = ''; $tooLarge = false;
        try {
            curl_setopt_array($curl, array(
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RESOLVE => array($host . ':443:' . $address),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => $method === 'DELETE' ? 10 : 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$response, &$tooLarge) {
                    if (strlen($response) + strlen($chunk) > 2097152) { $tooLarge = true; return 0; }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ));
            $ok = curl_exec($curl);
            $errno = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally { curl_close($curl); }
        if ($tooLarge) { throw new RuntimeException('云端返回内容过大，请缩小管理目录范围。'); }
        if ($ok === false) { throw new RuntimeException('云端管理连接失败（cURL ' . $errno . '）。'); }
        if ($status < 200 || $status >= 300) {
            $code = '';
            if (preg_match('~<Code>([A-Za-z0-9_.-]{1,80})</Code>~', $response, $m)) { $code = ' / ' . $m[1]; }
            $hint = $method === 'GET' ? '请检查子账户对当前桶及目录的列举权限。' : '请检查子账户对当前对象的删除权限。';
            throw new RuntimeException('云端' . ($method === 'GET' ? '列表' : '删除') . '失败（HTTP ' . $status . $code . '）。' . $hint);
        }
        return $response;
    }

    public static function parseObjectList($body)
    {
        if (!function_exists('simplexml_load_string')) { throw new RuntimeException('云端列表需要启用 PHP SimpleXML 扩展。'); }
        if (strlen($body) > 2097152 || stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new RuntimeException('云端列表 XML 格式不安全或过大。');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false || $xml->getName() !== 'ListBucketResult') { throw new RuntimeException('云端未返回有效的图片列表 XML。'); }
            $namespaces = $xml->getDocNamespaces(true);
            $data = isset($namespaces['']) ? $xml->children($namespaces['']) : $xml;
            $urlEncoded = strtolower((string) $data->EncodingType) === 'url';
            $items = array();
            foreach ($data->Contents as $object) {
                $key = (string) $object->Key;
                if ($urlEncoded) { $key = rawurldecode($key); }
                $items[] = array('key' => $key, 'size' => max(0, (int) $object->Size), 'modified' => (string) $object->LastModified);
            }
            $truncated = in_array(strtolower((string) $data->IsTruncated), array('true', '1'), true);
            $next = (string) $data->NextContinuationToken;
            if ($truncated && ($next === '' || strlen($next) > 8192)) { throw new RuntimeException('云端列表缺少有效的后续分页标记。'); }
            return array('items' => $items, 'next' => $truncated ? $next : '', 'scanned' => count($items));
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    public function listObjects($prefix, $cursor = '')
    {
        if (!is_string($cursor) || strlen($cursor) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $cursor)) { throw new InvalidArgumentException('分页标记无效。'); }
        if ($prefix !== '') { self::encodeKey(rtrim($prefix, '/')); }
        $parameters = array('list-type' => '2', 'encoding-type' => 'url', 'max-keys' => '48', 'prefix' => $prefix);
        if ($cursor !== '') { $parameters['continuation-token'] = $cursor; }
        return self::parseObjectList($this->controlRequest('GET', '/', $parameters));
    }

    public function deleteImage($key)
    {
        // Re-check scope at the transport boundary, not only in the admin form.
        BitifulS4_ValidateManagedKey($this->config, $key);
        $this->controlRequest('DELETE', '/' . self::encodeKey($key));
        return true;
    }
}
