<?php
declare(strict_types=1);

namespace Core;

/**
 * 真实 IP 检测类
 *
 * 多层 IP 检测策略，能够穿透 CDN 代理获取客户端真实 IP。
 * 支持多种 CDN 提供商的头部检测，以及腾讯云和阿里云 API 回源检测。
 * 同时提供浏览器信息和操作系统检测功能。
 */
class IpDetector
{
    /** @var string|null 缓存的真实 IP */
    private ?string $realIp = null;

    /** @var array|null 缓存的浏览器信息 */
    private ?array $browserInfo = null;

    /** @var array 缓存的 IP 地理信息 */
    private array $ipInfoCache = [];

    /**
     * 获取客户端真实 IP 地址
     *
     * 按优先级检测 CDN 代理头，直接信任所有 CDN 头获取真实 IP。
     *
     * @return string 客户端真实 IP 地址
     */
    public function getRealIp(): string
    {
        if ($this->realIp !== null) {
            return $this->realIp;
        }

        // 从 CDN 代理头中检测真实 IP
        $cdnIp = $this->detectCdnIp();
        if ($cdnIp !== null && $this->isValidIp($cdnIp)) {
            $this->realIp = $cdnIp;
            return $this->realIp;
        }

        // 回退：使用 REMOTE_ADDR
        $this->realIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return $this->realIp;
    }

    /**
     * 从 CDN 代理头中检测真实 IP
     *
     * 按优先级检查以下头部，直接信任所有 CDN 头：
     * - EO-Connecting-IP（腾讯云 Edge ONE）
     * - CF-Connecting-IP（Cloudflare）
     * - True-Client-IP（Akamai）
     * - X-TencentCDN-Real-IP / X-Tencent-Real-IP（腾讯云）
     * - X-Real-IP（Nginx 代理）
     * - X-Forwarded-For（通用代理）
     *
     * @return string|null 检测到的 IP，未检测到返回 null
     */
    public function detectCdnIp(): ?string
    {
        // 腾讯云 Edge ONE（EO）
        if (!empty($_SERVER['HTTP_EO_CONNECTING_IP'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_EO_CONNECTING_IP']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // Cloudflare 专用头
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_CF_CONNECTING_IP']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // Akamai / Cloudflare Enterprise
        if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_TRUE_CLIENT_IP']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // 腾讯云 CDN
        if (!empty($_SERVER['HTTP_X_TENCENTCDN_REAL_IP'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_X_TENCENTCDN_REAL_IP']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // 腾讯云
        if (!empty($_SERVER['HTTP_X_TENCENT_REAL_IP'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_X_TENCENT_REAL_IP']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // Nginx 代理常用头
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_X_REAL_IP']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // X-Forwarded-For（取第一个 IP）
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $this->extractFirstIp($_SERVER['HTTP_X_FORWARDED_FOR']);
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * 调用腾讯云 API 获取真实 IP
     *
     * 通过腾讯云 API 接口获取请求来源的真实 IP 地址。
     * 需要在 config.php 中配置 TENCENT_CLOUD_ENABLED、TENCENT_SECRET_ID 和 TENCENT_SECRET_KEY。
     *
     * @return string|null 检测到的真实 IP，失败返回 null
     */
    public function detectTencentCloudIp(): ?string
    {
        if (!defined('TENCENT_SECRET_ID') || !defined('TENCENT_SECRET_KEY') || empty(TENCENT_SECRET_ID)) {
            return null;
        }

        try {
            $endpoint = defined('TENCENT_API_ENDPOINT') ? TENCENT_API_ENDPOINT : 'https://cns.api.qcloud.com/v2/';

            // 构建请求参数
            $params = [
                'Action'    => 'GetRealClientIp',
                'SecretId'  => TENCENT_SECRET_ID,
                'Timestamp' => time(),
                'Nonce'     => random_int(10000, 99999),
            ];

            // 生成签名（简化版，实际应使用腾讯云 SDK）
            $signature = $this->generateTencentSignature($params, TENCENT_SECRET_KEY);
            $params['Signature'] = $signature;

            // 发送请求
            $url = $endpoint . '?' . http_build_query($params);
            $response = $this->httpGet($url);

            if ($response !== null) {
                $data = json_decode($response, true);
                if (isset($data['Response']['ClientIp']) && $this->isValidIp($data['Response']['ClientIp'])) {
                    return $data['Response']['ClientIp'];
                }
            }
        } catch (\Throwable) {
            // API 调用失败，静默处理
        }

        return null;
    }

    /**
     * 调用阿里云 ESA API 获取真实 IP
     *
     * 通过阿里云 ESA（边缘安全加速）API 获取请求来源的真实 IP 地址。
     * 需要在 config.php 中配置 ALIYUN_ESA_ENABLED、ALIYUN_ACCESS_KEY_ID 和 ALIYUN_ACCESS_KEY_SECRET。
     *
     * @return string|null 检测到的真实 IP，失败返回 null
     */
    public function detectAliyunIp(): ?string
    {
        if (!defined('ALIYUN_ACCESS_KEY_ID') || !defined('ALIYUN_ACCESS_KEY_SECRET') || empty(ALIYUN_ACCESS_KEY_ID)) {
            return null;
        }

        try {
            $endpoint = defined('ALIYUN_ESA_ENDPOINT') ? ALIYUN_ESA_ENDPOINT : 'https://esa.aliyuncs.com/';

            // 构建请求参数
            $params = [
                'Action'     => 'GetRealClientIp',
                'AccessKeyId' => ALIYUN_ACCESS_KEY_ID,
                'Timestamp'  => gmdate('Y-m-d\TH:i:s\Z'),
                'SignatureMethod'  => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'SignatureNonce'   => uniqid((string) random_int(1000, 9999), true),
            ];

            // 生成签名
            $signature = $this->generateAliyunSignature($params, ALIYUN_ACCESS_KEY_SECRET);
            $params['Signature'] = $signature;

            // 发送请求
            $url = $endpoint . '?' . http_build_query($params);
            $response = $this->httpGet($url);

            if ($response !== null) {
                $data = json_decode($response, true);
                if (isset($data['ClientIp']) && $this->isValidIp($data['ClientIp'])) {
                    return $data['ClientIp'];
                }
            }
        } catch (\Throwable) {
            // API 调用失败，静默处理
        }

        return null;
    }

    /**
     * 获取 IP 的地理位置信息
     *
     * 通过 IP 查询获取地理位置、ISP 等信息。
     * 结果会被缓存以减少重复查询。
     *
     * @param string|null $ip IP 地址，为 null 时使用检测到的真实 IP
     * @return array 包含 country, province, city, isp 等信息的数组
     */
    public function getIpInfo(?string $ip = null): array
    {
        $ip = $ip ?? $this->getRealIp();

        if (isset($this->ipInfoCache[$ip])) {
            return $this->ipInfoCache[$ip];
        }

        // L-13: 基于APCu/文件的简单缓存，同一IP查询结果缓存1小时
        $cacheKey = 'lighthouse_ipinfo_' . md5($ip);
        $cachedInfo = false;
        if (function_exists('apcu_fetch')) {
            $cachedInfo = apcu_fetch($cacheKey);
        } else {
            // 文件缓存降级方案
            $cacheFile = sys_get_temp_dir() . '/lighthouse_ip_' . md5($ip);
            if (file_exists($cacheFile)) {
                $cacheData = @json_decode(@file_get_contents($cacheFile), true);
                if ($cacheData && isset($cacheData['expires']) && $cacheData['expires'] > time()) {
                    $cachedInfo = $cacheData['info'];
                }
            }
        }
        if ($cachedInfo !== false && is_array($cachedInfo)) {
            $this->ipInfoCache[$ip] = $cachedInfo;
            return $cachedInfo;
        }

        // 默认返回值
        $defaultInfo = [
            'ip'       => $ip,
            'country'  => '未知',
            'province' => '未知',
            'city'     => '未知',
            'isp'      => '未知',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ];

        // SEC-035: SSRF防护 - 拒绝查询内网IP，防止SSRF攻击
        if ($this->isPrivateIp($ip)) {
            return [
                'ip'       => $ip,
                'country'  => '内网',
                'province' => '局域网',
                'city'     => '局域网',
                'isp'      => '内网地址',
                'latitude' => 0.0,
                'longitude' => 0.0,
            ];
        }

        try {
            // M-13: 使用HTTPS加密连接ip-api.com，防止中间人攻击窃取查询数据
            $url = "https://ip-api.com/json/" . urlencode($ip) . "?lang=zh-CN&fields=66846719";
            $response = $this->httpGet($url, timeout: 3);

            if ($response !== null) {
                $data = json_decode($response, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $info = [
                        'ip'        => $ip,
                        'country'   => $data['country'] ?? '未知',
                        'province'  => $data['regionName'] ?? '未知',
                        'city'      => $data['city'] ?? '未知',
                        'isp'       => $data['isp'] ?? '未知',
                        'latitude'  => (float) ($data['lat'] ?? 0),
                        'longitude' => (float) ($data['lon'] ?? 0),
                        'timezone'  => $data['timezone'] ?? '',
                        'as'        => $data['as'] ?? '',
                    ];
                    $this->ipInfoCache[$ip] = $info;

                    // 写入缓存（1小时有效期）
                    if (function_exists('apcu_store')) {
                        apcu_store($cacheKey, $info, 3600);
                    } else {
                        $cacheFile = sys_get_temp_dir() . '/lighthouse_ip_' . md5($ip);
                        @file_put_contents($cacheFile, json_encode(['expires' => time() + 3600, 'info' => $info]), LOCK_EX);
                    }

                    return $info;
                }
            }
        } catch (\Throwable) {
            // 查询失败，返回默认值
        }

        $this->ipInfoCache[$ip] = $defaultInfo;
        return $defaultInfo;
    }

    /**
     * 获取浏览器信息
     *
     * 解析 User-Agent 字符串，提取浏览器名称、版本和引擎信息。
     *
     * @return array 包含 name, version, engine, platform 信息的数组
     */
    public function getBrowserInfo(): array
    {
        if ($this->browserInfo !== null) {
            return $this->browserInfo;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $info = [
            'name'      => '未知浏览器',
            'version'   => '',
            'engine'    => '未知',
            'platform'  => '',
            'userAgent' => $userAgent,
            'isMobile'  => false,
            'isBot'     => false,
        ];

        // 检测是否为移动设备
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod', 'Windows Phone', 'BlackBerry'];
        $info['isMobile'] = (bool) preg_match('/(' . implode('|', $mobileKeywords) . ')/i', $userAgent);

        // 检测是否为爬虫/机器人
        $botKeywords = ['bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'feedfetcher', 'curl', 'wget'];
        $info['isBot'] = (bool) preg_match('/(' . implode('|', $botKeywords) . ')/i', $userAgent);

        // 浏览器检测（按优先级排序）
        $browsers = [
            'Edg'        => ['name' => 'Microsoft Edge',    'engine' => 'Blink'],
            'OPR'        => ['name' => 'Opera',             'engine' => 'Blink'],
            'Opera'      => ['name' => 'Opera',             'engine' => 'Blink'],
            'Vivaldi'    => ['name' => 'Vivaldi',           'engine' => 'Blink'],
            'YaBrowser'  => ['name' => 'Yandex Browser',    'engine' => 'Blink'],
            'Firefox'    => ['name' => 'Firefox',           'engine' => 'Gecko'],
            'Safari'     => ['name' => 'Safari',            'engine' => 'WebKit'],
            'Chrome'     => ['name' => 'Google Chrome',     'engine' => 'Blink'],
            'MSIE'       => ['name' => 'Internet Explorer', 'engine' => 'Trident'],
            'Trident'    => ['name' => 'Internet Explorer', 'engine' => 'Trident'],
        ];

        foreach ($browsers as $keyword => $browser) {
            if (preg_match('#(' . $keyword . ')[/ ]+(\d+[\.\d]*)#i', $userAgent, $matches)) {
                $info['name']    = $browser['name'];
                $info['version'] = $matches[2];
                $info['engine']  = $browser['engine'];
                break;
            }
        }

        // 操作系统检测
        $info['platform'] = $this->detectOperatingSystem($userAgent);

        $this->browserInfo = $info;
        return $this->browserInfo;
    }

    /**
     * 获取操作系统信息
     *
     * @return array 包含 name, version, arch 信息的数组
     */
    public function getOsInfo(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $osName = $this->detectOperatingSystem($userAgent);

        $info = [
            'name'    => $osName,
            'version' => '',
            'arch'    => '',
        ];

        // 提取操作系统版本
        $osVersionPatterns = [
            'Windows NT (\d+\.\d+)' => 'Windows',
            'Mac OS X (\d+[._]\d+[._]?\d*)' => 'macOS',
            'Android (\d+[\.\d]*)' => 'Android',
            'iPhone OS (\d+[_\d]*)' => 'iOS',
            'iPad.*OS (\d+[_\d]*)' => 'iPadOS',
            'Linux ([\d\.]+)' => 'Linux',
        ];

        foreach ($osVersionPatterns as $pattern => $os) {
            if (preg_match('#' . $pattern . '#i', $userAgent, $matches)) {
                $info['version'] = str_replace('_', '.', $matches[1]);
                break;
            }
        }

        // 架构检测
        if (str_contains($userAgent, 'x86_64') || str_contains($userAgent, 'x64') || str_contains($userAgent, 'Win64')) {
            $info['arch'] = 'x86_64';
        } elseif (str_contains($userAgent, 'x86') || str_contains($userAgent, 'i686') || str_contains($userAgent, 'Win32')) {
            $info['arch'] = 'x86';
        } elseif (str_contains($userAgent, 'aarch64') || str_contains($userAgent, 'arm64')) {
            $info['arch'] = 'ARM64';
        } elseif (str_contains($userAgent, 'arm')) {
            $info['arch'] = 'ARM';
        }

        return $info;
    }

    /**
     * 获取客户端完整信息摘要
     *
     * 一次性获取 IP、浏览器、操作系统等信息。
     *
     * @return array 包含所有客户端信息的数组
     */
    public function getClientInfo(): array
    {
        return [
            'ip'         => $this->getRealIp(),
            'ip_info'    => $this->getIpInfo(),
            'browser'    => $this->getBrowserInfo(),
            'os'         => $this->getOsInfo(),
            'referer'    => $_SERVER['HTTP_REFERER'] ?? '',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        ];
    }

    // ==================== 私有辅助方法 ====================

    /**
     * 从可能包含多个 IP 的字符串中提取第一个有效 IP
     *
     * @param string $ipString 可能包含逗号分隔的多个 IP 的字符串
     * @return string 第一个 IP 地址
     */
    private function extractFirstIp(string $ipString): string
    {
        $ips = explode(',', $ipString);
        return trim($ips[0]);
    }

    /**
     * 验证 IP 地址是否有效
     *
     * @param string $ip 待验证的 IP 地址
     * @return bool 有效返回 true
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * 判断是否为内网/私有 IP
     *
     * @param string $ip IP 地址
     * @return bool 是内网 IP 返回 true
     */
    private function isPrivateIp(string $ip): bool
    {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * 检测操作系统名称
     *
     * @param string $userAgent User-Agent 字符串
     * @return string 操作系统名称
     */
    private function detectOperatingSystem(string $userAgent): string
    {
        $osList = [
            'Windows 11'    => 'Windows NT 10.0',
            'Windows 10'    => 'Windows NT 10.0',
            'Windows 8.1'   => 'Windows NT 6.3',
            'Windows 8'     => 'Windows NT 6.2',
            'Windows 7'     => 'Windows NT 6.1',
            'Windows Vista' => 'Windows NT 6.0',
            'Windows XP'    => 'Windows NT 5.1',
            'Windows 2000'  => 'Windows NT 5.0',
            'Windows'       => 'Windows',
            'macOS'         => 'Mac OS X',
            'iPadOS'        => 'iPad',
            'iOS'           => 'iPhone',
            'Android'       => 'Android',
            'Ubuntu'        => 'Ubuntu',
            'Linux'         => 'Linux',
            'CrOS'          => 'Chrome OS',
            'FreeBSD'       => 'FreeBSD',
        ];

        foreach ($osList as $name => $keyword) {
            if (str_contains($userAgent, $keyword)) {
                return $name;
            }
        }

        return '未知系统';
    }

    /**
     * 生成腾讯云 API 签名
     *
     * @param array  $params    请求参数
     * @param string $secretKey 密钥
     * @return string 签名字符串
     */
    private function generateTencentSignature(array $params, string $secretKey): string
    {
        ksort($params);
        $signStr = '';
        foreach ($params as $key => $value) {
            $signStr .= "{$key}={$value}&";
        }
        $signStr = rtrim($signStr, '&');
        return base64_encode(hash_hmac('sha1', $signStr, $secretKey, true));
    }

    /**
     * 生成阿里云 API 签名
     *
     * @param array  $params    请求参数
     * @param string $secretKey 密钥
     * @return string 签名字符串
     */
    private function generateAliyunSignature(array $params, string $secretKey): string
    {
        ksort($params);
        $canonicalizedQueryString = '';
        foreach ($params as $key => $value) {
            $canonicalizedQueryString .= rawurlencode($key) . '=' . rawurlencode($value) . '&';
        }
        $canonicalizedQueryString = rtrim($canonicalizedQueryString, '&');

        $stringToSign = "GET&%2F&" . rawurlencode($canonicalizedQueryString);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey . '&', true));
        return $signature;
    }

    /**
     * 发送 HTTP GET 请求
     *
     * SEC-H02: 使用cURL替代file_get_contents，通过CURLOPT_RESOLVE绑定DNS解析结果，
     * 防止DNS Rebinding攻击（TOCTOU：先解析DNS验证IP，再发请求时DNS已指向不同IP）。
     *
     * @param string      $url     请求 URL
     * @param int         $timeout 超时时间（秒）
     * @param bool        $verifySsl 是否验证SSL证书（默认true）
     * @return string|null 响应内容，失败返回 null
     */
    private function httpGet(string $url, int $timeout = 5, bool $verifySsl = true): ?string
    {
        // SSRF防护：解析目标URL的域名，验证解析后的IP不为内网地址
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) {
            return null;
        }

        $host = $parsedUrl['host'];

        // 仅允许 HTTP/HTTPS 协议
        $scheme = strtolower($parsedUrl['scheme'] ?? 'http');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // 解析域名获取IP列表
        $resolvedIps = gethostbynamel($host);
        if ($resolvedIps === false || empty($resolvedIps)) {
            return null;
        }

        // 验证所有解析到的IP均不为内网地址
        foreach ($resolvedIps as $resolvedIp) {
            if ($this->isPrivateIp($resolvedIp)) {
                return null;
            }
        }

        // 使用第一个解析到的IP，通过 CURLOPT_RESOLVE 绑定DNS解析结果
        // 确保实际请求使用的IP与验证通过的IP一致，防止DNS Rebinding
        $resolvedIp = $resolvedIps[0];

        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        // 构建CURLOPT_RESOLVE格式：host:port:ip
        $port = isset($parsedUrl['port']) ? (int) $parsedUrl['port'] : ($scheme === 'https' ? 443 : 80);
        $resolveEntry = "{$host}:{$port}:{$resolvedIp}";

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$resolveEntry],
            CURLOPT_USERAGENT      => 'LighthouseDNS/1.0',
            CURLOPT_HEADER         => false,
        ]);

        if ($verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return null;
        }

        return $response;
    }
}
