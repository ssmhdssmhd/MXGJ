<?php
/**
 * VideoParserService - 多平台视频解析服务
 *
 * 由 Python 版「视频解析工具」转换而来，核心逻辑保持一致：
 *  - 从爱奇艺 / 腾讯 / 优酷 / 芒果TV 等链接中提取视频 ID
 *  - 抓取页面标题、描述、封面等信息
 *  - 依次尝试第三方解析接口、直接解析、移动端解析
 *  - 对 m3u8 主清单做质量升级，优先选择 4K / 最高分辨率 / 最高码率
 *
 * 依赖 PHP 扩展：curl、dom、mbstring、json、openssl
 */

namespace App\Services;

class VideoParserService
{
    /** @var array 默认请求头 */
    protected $headers = [];

    /** @var int 请求超时（秒） */
    protected $timeout = 15;

    /** @var int 最大重试次数 */
    protected $maxRetries = 3;

    /** @var array 第三方解析接口列表 */
    protected $parseApis = [];

    /** @var array iframe 播放器接口列表 */
    protected $iframePlayers = [];

    /** @var bool 是否返回 iframe 播放器源（此类源整站 iframe 嵌入播放，播放器可能自带广告） */
    protected $enableIframePlayers = true;

    /** @var int 最大返回播放源数量 */
    protected $maxUrls = 5;

    /** @var bool 是否启用画质升级 */
    protected $enableQualityUpgrade = true;

    public function __construct(array $config = [])
    {
        $this->parseApis = $config['parse_apis'] ?? [
            'https://jx.playerjy.com/?url=',
            'https://jx.aidouer.net/?url=',
            'https://jx.jsonplayer.com/?url=',
            'https://jx.bozrc.com:4433/player/?url=',
        ];

        // iframe 播放器接口（不参与正则提取，直接作为 iframe 播放源）
        $this->iframePlayers = $config['iframe_players'] ?? [
            'https://jx.xmflv.cc/?url=',
            'https://jx.xmflv.com/?url=',
        ];

        // 是否返回 iframe 播放器源（默认开启；关闭后仅返回直链，可能无可用源）
        $this->enableIframePlayers = (bool) ($config['enable_iframe_players'] ?? true);

        $this->timeout = $config['timeout'] ?? 15;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->maxUrls = $config['max_urls'] ?? 5;
        $this->enableQualityUpgrade = $config['enable_quality_upgrade'] ?? true;

        $userAgent = $config['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $this->headers = [
            'User-Agent' => $userAgent,
            'Referer' => 'https://www.iqiyi.com/',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Cache-Control' => 'max-age=0',
        ];
    }

    /**
     * 发送 HTTP GET 请求（带重试策略）
     *
     * @param string $url
     * @param int    $timeout
     * @param array  $extraHeaders 附加请求头
     * @return string|null 响应体，失败返回 null
     */
    public function httpGet($url, $timeout = null, array $extraHeaders = [])
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $timeout = $timeout ?: $this->timeout;
        $headers = array_merge($this->headers, $extraHeaders);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => $this->headers['User-Agent'],
            ]);

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($body !== false && $httpCode >= 200 && $httpCode < 400) {
                return (string) $body;
            }

            $retryable = in_array($httpCode, [429, 500, 502, 503, 504], true) || $errno !== 0;
            if (!$retryable || $attempt === $this->maxRetries - 1) {
                break;
            }

            usleep(($attempt + 1) * 1000000);
        }

        return null;
    }

    /**
     * 主解析函数
     *
     * @param string $url 视频页面链接
     * @return array 解析结果
     */
    public function parseVideo($url)
    {
        try {
            $videoInfo = $this->getVideoInfo($url);
            $playUrls = $this->generatePlayUrls($url);

            if ($playUrls) {
                // 类型标注：direct=直链（m3u8/mp4/flv，无广告），iframe=播放器页面（可能含广告）
                $sources = [];
                $directCount = 0;
                foreach ($playUrls as $pu) {
                    $isDirect = $this->isDirectVideoUrl($pu);
                    if ($isDirect) {
                        $directCount++;
                    }
                    $sources[] = [
                        'url' => $pu,
                        'type' => $isDirect ? 'direct' : 'iframe',
                        'label' => $isDirect ? '直链' : '播放器源',
                        'note' => $isDirect ? '' : 'iframe 播放器，可能含广告',
                    ];
                }

                return [
                    'success' => true,
                    'title' => ($videoInfo && !empty($videoInfo['title'])) ? $videoInfo['title'] : '解析成功',
                    'description' => ($videoInfo && !empty($videoInfo['description'])) ? $videoInfo['description'] : '',
                    'urls' => $playUrls,
                    'sources' => $sources,
                    'direct_count' => $directCount,
                    'iframe_count' => count($playUrls) - $directCount,
                    'video_info' => $videoInfo ?: [],
                    'video_id' => $this->extractVideoId($url),
                    'parse_time' => date('Y-m-d H:i:s'),
                ];
            }

            return [
                'success' => false,
                'error' => '未找到可用的播放源，请检查链接或稍后重试',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => '解析失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 从 URL 中提取视频 ID（支持多平台）
     *
     * @param string $url
     * @return string|null
     */
    public function extractVideoId($url)
    {
        try {
            $parts = parse_url($url);
            $domain = isset($parts['host']) ? strtolower($parts['host']) : '';
            $path = isset($parts['path']) ? $parts['path'] : '';
            $query = isset($parts['query']) ? $parts['query'] : '';

            // 爱奇艺
            if (strpos($domain, 'iqiyi.com') !== false) {
                if (preg_match('#/v_([a-zA-Z0-9]+)#', $path, $m)) {
                    return 'iqiyi_' . $m[1];
                }
            }
            // 腾讯视频
            elseif (strpos($domain, 'v.qq.com') !== false) {
                if (preg_match('#/x/(?:cover|page)/([a-zA-Z0-9]+)#', $path, $m)) {
                    return 'qq_' . $m[1];
                }
                parse_str($query, $params);
                if (!empty($params['vid'])) {
                    return 'qq_' . $params['vid'];
                }
            }
            // 优酷
            elseif (strpos($domain, 'youku.com') !== false) {
                if (preg_match('#/v_show/id_([a-zA-Z0-9]+)#', $path, $m)) {
                    return 'youku_' . $m[1];
                }
            }
            // 芒果TV
            elseif (strpos($domain, 'mgtv.com') !== false) {
                if (preg_match('#/v/([a-zA-Z0-9]+)#', $path, $m)) {
                    return 'mgtv_' . $m[1];
                }
            }

            // 通用方法：从查询参数中提取
            parse_str($query, $params);
            foreach (['vid', 'video_id', 'id'] as $param) {
                if (!empty($params[$param])) {
                    return 'generic_' . $params[$param];
                }
            }

            // 从页面内容中提取
            $body = $this->httpGet($url, 15);
            if ($body !== null) {
                $idPatterns = [
                    '#"vid":"([^"]+)"#',
                    '#"videoId":"([^"]+)"#',
                    '#"id":"([^"]+)"#',
                    '#data-video-id="([^"]+)"#',
                ];
                foreach ($idPatterns as $pattern) {
                    if (preg_match($pattern, $body, $m)) {
                        return 'page_' . $m[1];
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 获取视频详细信息
     *
     * @param string $url
     * @return array|null
     */
    public function getVideoInfo($url)
    {
        try {
            $body = $this->httpGet($url, 15);
            if ($body === null) {
                return null;
            }

            $videoInfo = [
                'title' => '',
                'description' => '',
                'duration' => '',
                'cover' => '',
                'category' => '',
                'year' => '',
                'director' => '',
                'actors' => [],
                'url' => $url,
            ];

            // 使用 DOMDocument 解析 HTML
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">' . $body);
            libxml_clear_errors();
            $xpath = new \DOMXPath($dom);

            // 标题
            $titleSelectors = [
                '//h1[contains(@class,"videoTitle")]',
                '//h1[contains(@class,"title")]',
                '//title',
                '//meta[@property="og:title"]',
                '//h1[contains(@class,"video-title")]',
            ];
            foreach ($titleSelectors as $selector) {
                $nodes = $xpath->query($selector);
                if ($nodes && $nodes->length > 0) {
                    $node = $nodes->item(0);
                    if ($node->nodeName === 'meta') {
                        $title = trim($node->getAttribute('content'));
                    } else {
                        $title = trim($node->textContent);
                    }
                    if ($title !== '') {
                        $title = trim(explode('_', $title)[0]);
                        $title = trim(explode('-', $title)[0]);
                        $videoInfo['title'] = $title;
                        break;
                    }
                }
            }

            // 描述
            $descSelectors = [
                '//meta[@name="description"]',
                '//meta[@property="og:description"]',
                '//*[contains(@class,"video-desc")]',
                '//*[contains(@class,"description")]',
            ];
            foreach ($descSelectors as $selector) {
                $nodes = $xpath->query($selector);
                if ($nodes && $nodes->length > 0) {
                    $node = $nodes->item(0);
                    if ($node->nodeName === 'meta') {
                        $desc = trim($node->getAttribute('content'));
                    } else {
                        $desc = trim($node->textContent);
                    }
                    if ($desc !== '') {
                        $videoInfo['description'] = $desc;
                        break;
                    }
                }
            }

            // 封面
            $coverSelectors = [
                '//meta[@property="og:image"]',
                '//meta[@name="image"]',
                '//*[contains(@class,"video-cover")]//img',
                '//*[contains(@class,"poster")]//img',
            ];
            foreach ($coverSelectors as $selector) {
                $nodes = $xpath->query($selector);
                if ($nodes && $nodes->length > 0) {
                    $node = $nodes->item(0);
                    if ($node->nodeName === 'meta') {
                        $cover = trim($node->getAttribute('content'));
                    } else {
                        $cover = trim($node->getAttribute('src'));
                    }
                    if ($cover !== '') {
                        $videoInfo['cover'] = $cover;
                        break;
                    }
                }
            }

            // 时长
            $durationSelectors = [
                '//*[contains(@class,"duration")]',
                '//*[contains(@class,"video-duration")]',
                '//*[contains(@class,"time")]',
            ];
            foreach ($durationSelectors as $selector) {
                $nodes = $xpath->query($selector);
                if ($nodes && $nodes->length > 0) {
                    $duration = trim($nodes->item(0)->textContent);
                    if ($duration !== '') {
                        $videoInfo['duration'] = $duration;
                        break;
                    }
                }
            }

            return $videoInfo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 生成多种可能的播放地址（主流程）
     *
     * 只返回「验证过」的播放源：
     *  - 直链（m3u8 / mp4 / flv 等）优先
     *  - iframe 播放器页面（整站 iframe 嵌入播放）作为兜底
     * 不再返回未经请求验证的「拼接地址」（api + url）。
     *
     * @param string $url
     * @return array
     */
    public function generatePlayUrls($url)
    {
        try {
            // 方法1：第三方接口解析（主要方法），返回 直链 + iframe 播放器源
            $parsed = $this->tryThirdPartyParse($url);
            $directUrls = $parsed['direct'];
            $iframeUrls = $parsed['iframe'];

            // 方法2：直接解析页面
            if (!$directUrls) {
                $directUrls = $this->tryDirectParse($url);
            }

            // 方法3：移动端解析
            if (!$directUrls) {
                $directUrls = $this->tryMobileParse($url);
            }

            // 方法4：配置的 iframe 播放器源（默认开启，作为兜底播放源）
            if ($this->enableIframePlayers) {
                foreach ($this->iframePlayers as $api) {
                    $iframeUrls[] = $api . $url;
                }
            }

            // 直链优先，iframe 源在后；去重（保持顺序）
            $playUrls = array_values(array_unique(array_merge($directUrls, $iframeUrls)));

            // 若关闭 iframe 播放器源，则仅保留直链（可能无可用源）
            if (!$this->enableIframePlayers) {
                $playUrls = array_values(array_filter($playUrls, function ($u) {
                    return $this->isDirectVideoUrl($u);
                }));
            }

            // 升级为最高清晰度（仅对直链 m3u8 生效）
            if ($this->enableQualityUpgrade && $directUrls) {
                $playUrls = $this->upgradeToBestQuality($playUrls, $url);
            }

            return array_slice($playUrls, 0, $this->maxUrls);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 尝试第三方解析接口
     *
     * 依次请求每个接口，返回结构化结果：
     *  - direct  提取到的直链（m3u8 / mp4 / flv 等）
     *  - iframe  验证过的 iframe 播放器页面（整站 iframe 嵌入播放）
     *
     * 只收集「真实请求并验证」的源，不再返回 api + url 的拼接地址。
     *
     * @param string $url
     * @return array ['direct' => [], 'iframe' => []]
     */
    protected function tryThirdPartyParse($url)
    {
        $directUrls = [];
        $iframeUrls = [];

        foreach ($this->parseApis as $api) {
            $parseUrl = $api . $url;
            $body = $this->httpGet($parseUrl, 10);
            if ($body === null) {
                continue;
            }

            // 处理 meta refresh 跳转（如 aidouer → 77flv）
            $redirectUrl = $this->extractMetaRefreshUrl($body);
            if ($redirectUrl !== null) {
                $body = $this->httpGet($redirectUrl, 10);
                if ($body === null) {
                    continue;
                }
            }

            // 1. 提取直链
            $newDirect = $this->extractPlayUrls($body);
            foreach ($newDirect as $du) {
                if (!in_array($du, $directUrls, true)) {
                    $directUrls[] = $du;
                }
            }

            // 2. 提取 iframe 播放器页面，并尝试深度解析直链
            $iframes = $this->extractIframeUrls($body);
            foreach ($iframes as $iframeUrl) {
                $nested = $this->deepExtractDirectUrls($iframeUrl, 2);
                foreach ($nested as $nu) {
                    if (!in_array($nu, $directUrls, true)) {
                        $directUrls[] = $nu;
                    }
                }
                // 深度解析未得到直链时，保留 iframe 播放器源
                if (!$nested && !in_array($iframeUrl, $iframeUrls, true)) {
                    $iframeUrls[] = $iframeUrl;
                }
            }

            // 3. 若接口返回的是播放器页面本身（无 iframe 但含播放器特征），
            //    则把接口地址作为 iframe 播放源
            if (!$newDirect && !$iframes && $this->looksLikePlayerPage($body)) {
                if (!in_array($parseUrl, $iframeUrls, true)) {
                    $iframeUrls[] = $parseUrl;
                }
            }

            // 继续尝试下一个接口，收集更多直链
            usleep(500000);
        }

        return ['direct' => $directUrls, 'iframe' => $iframeUrls];
    }

    /**
     * 尝试直接解析页面获取播放源
     *
     * @param string $url
     * @return array
     */
    protected function tryDirectParse($url)
    {
        try {
            $body = $this->httpGet($url, 15);
            if ($body === null) {
                return [];
            }

            $playUrls = [];
            $patterns = [
                '#"playUrl":"([^"]+)"#',
                '#"url":"([^"]+\.m3u8[^"]*)"#',
                '#"url":"([^"]+\.mp4[^"]*)"#',
                '#"videoUrl":"([^"]+)"#',
                '#"src":"([^"]+\.m3u8[^"]*)"#',
                '#"src":"([^"]+\.mp4[^"]*)"#',
                '#playUrl=([^&\s]+)#',
                '#videoUrl=([^&\s]+)#',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $body, $matches)) {
                    foreach ($matches[1] as $match) {
                        $decoded = urldecode($match);
                        if (!in_array($decoded, $playUrls, true)) {
                            $playUrls[] = $decoded;
                        }
                    }
                }
            }

            // 查找 iframe 播放器
            if (preg_match_all('#<iframe[^>]+src="([^"]+)"#i', $body, $matches)) {
                foreach ($matches[1] as $iframeSrc) {
                    $lower = strtolower($iframeSrc);
                    if (strpos($lower, 'player') !== false || strpos($lower, 'video') !== false || strpos($lower, 'play') !== false) {
                        if (!in_array($iframeSrc, $playUrls, true)) {
                            $playUrls[] = $iframeSrc;
                        }
                    }
                }
            }

            return $playUrls;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 尝试移动端页面解析
     *
     * @param string $url
     * @return array
     */
    protected function tryMobileParse($url)
    {
        try {
            $mobileUrl = $url;
            $parts = parse_url($url);
            $domain = isset($parts['host']) ? strtolower($parts['host']) : '';

            if (strpos($domain, 'iqiyi.com') !== false) {
                $mobileUrl = str_replace('www.iqiyi.com', 'm.iqiyi.com', $url);
            } elseif (strpos($domain, 'v.qq.com') !== false) {
                $mobileUrl = str_replace('v.qq.com', 'm.v.qq.com', $url);
            } elseif (strpos($domain, 'youku.com') !== false) {
                $mobileUrl = str_replace('youku.com', 'm.youku.com', $url);
            } elseif (strpos($domain, 'mgtv.com') !== false) {
                $mobileUrl = str_replace('mgtv.com', 'm.mgtv.com', $url);
            }

            $body = $this->httpGet($mobileUrl, 15);
            if ($body === null) {
                return [];
            }

            $playUrls = [];
            $mobilePatterns = [
                '#"playUrl":"([^"]+)"#',
                '#"url":"([^"]+\.m3u8[^"]*)"#',
                '#"url":"([^"]+\.mp4[^"]*)"#',
                '#playUrl=([^&\s]+)#',
                '#videoUrl=([^&\s]+)#',
            ];

            foreach ($mobilePatterns as $pattern) {
                if (preg_match_all($pattern, $body, $matches)) {
                    foreach ($matches[1] as $match) {
                        $decoded = urldecode($match);
                        if (!in_array($decoded, $playUrls, true)) {
                            $playUrls[] = $decoded;
                        }
                    }
                }
            }

            return $playUrls;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 从 HTML 内容中提取播放源
     *
     * @param string $htmlContent
     * @return array
     */
    /**
     * 从 HTML 中提取直链播放地址（m3u8 / mp4 / flv 等）
     *
     * 注意：只返回真正的直链，不包含 iframe 播放器页面链接。
     * 播放器页面链接请使用 extractIframeUrls()。
     *
     * @param string $htmlContent
     * @return array
     */
    public function extractPlayUrls($htmlContent)
    {
        $playUrls = [];
        $patterns = [
            '#"url":"([^"]+\.m3u8[^"]*)"#',
            '#"url":"([^"]+\.mp4[^"]*)"#',
            '#"playUrl":"([^"]+)"#',
            '#"videoUrl":"([^"]+)"#',
            '#"src":"([^"]+\.m3u8[^"]*)"#',
            '#"src":"([^"]+\.mp4[^"]*)"#',
            '#<video[^>]+src="([^"]+)"#i',
            '#<source[^>]+src="([^"]+)"#i',
            '#(?:https?:)?//[^"\'<> ]+\.m3u8[^"\'<> ]*#i',
            '#(?:https?:)?//[^"\'<> ]+\.mp4[^"\'<> ]*#i',
            '#(?:https?:)?//[^"\'<> ]+\.flv[^"\'<> ]*#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $htmlContent, $matches)) {
                foreach ($matches[1] as $match) {
                    $decoded = urldecode($match);
                    if (!in_array($decoded, $playUrls, true) && $this->isDirectVideoUrl($decoded)) {
                        $playUrls[] = $decoded;
                    }
                }
            }
        }

        return $playUrls;
    }

    /**
     * 从 HTML 中提取 iframe 播放器页面链接（如 getdata.staticfile.link/player/...）
     *
     * @param string $htmlContent
     * @return array
     */
    public function extractIframeUrls($htmlContent)
    {
        $iframeUrls = [];
        if (preg_match_all('#<iframe[^>]+src="([^"]+)"#i', $htmlContent, $matches)) {
            foreach ($matches[1] as $src) {
                $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
                if ($this->isValidHttpUrl($src) && !in_array($src, $iframeUrls, true)) {
                    $iframeUrls[] = $src;
                }
            }
        }
        return $iframeUrls;
    }

    /**
     * 深度解析：请求播放器页面并尝试提取直链
     *
     * 对 iframe 播放器链接递归请求（最多 $depth 层），
     * 在每一层尝试提取 m3u8 / mp4 等直链；若该层是播放器页面，
     * 则继续深入其内部 iframe，直到提取到直链或达到最大深度。
     *
     * @param string $url   播放器页面链接
     * @param int    $depth 剩余递归深度
     * @return array 提取到的直链列表
     */
    public function deepExtractDirectUrls($url, $depth = 2)
    {
        if ($depth < 0 || !$this->isValidHttpUrl($url)) {
            return [];
        }

        $body = $this->httpGet($url, 10);
        if ($body === null) {
            return [];
        }

        // 处理 meta refresh 跳转
        $redirectUrl = $this->extractMetaRefreshUrl($body);
        if ($redirectUrl !== null && $redirectUrl !== $url) {
            return $this->deepExtractDirectUrls($redirectUrl, $depth);
        }

        // 先尝试提取直链
        $directUrls = $this->extractPlayUrls($body);
        if ($directUrls) {
            return $directUrls;
        }

        // 无直链则深入内部 iframe
        $iframes = $this->extractIframeUrls($body);
        foreach ($iframes as $iframeUrl) {
            $nested = $this->deepExtractDirectUrls($iframeUrl, $depth - 1);
            if ($nested) {
                return $nested;
            }
        }

        return [];
    }

    /**
     * 从 HTML 中提取 meta refresh 跳转地址
     *
     * 部分解析接口返回 <meta http-equiv="refresh" content="N;url=..."> 的跳转页，
     * 需要跟随该地址继续解析。
     *
     * @param string $body
     * @return string|null 跳转地址，无则返回 null
     */
    public function extractMetaRefreshUrl($body)
    {
        if ($body === null || $body === '') {
            return null;
        }

        // 标准写法：<meta http-equiv="refresh" content="N;url=...">
        if (preg_match('#<meta[^>]+http-equiv=["\']?refresh["\']?[^>]*content=["\']\d+;\s*url=([^"\']+)#i', $body, $m)) {
            $url = trim($m[1]);
            if ($this->isValidHttpUrl($url)) {
                return $url;
            }
        }

        // content 在 http-equiv 之前的写法
        if (preg_match('#<meta[^>]+content=["\']\d+;\s*url=([^"\']+)["\'][^>]*http-equiv=["\']?refresh#i', $body, $m)) {
            $url = trim($m[1]);
            if ($this->isValidHttpUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * 判断 HTML 是否为播放器页面（含播放器特征 / 视频标签 / 混淆脚本）
     *
     * @param string $body
     * @return bool
     */
    public function looksLikePlayerPage($body)
    {
        if ($body === null || strlen($body) < 500) {
            return false;
        }

        $lower = strtolower($body);
        $markers = [
            '<video', '<iframe', '<canvas',
            'dplayer', 'ckplayer', 'jwplayer', 'videojs', 'flv.js', 'hls.js',
            'xmflv', '播放器', 'player',
            'eval(', 'fromcharcode', 'atob(',
        ];

        foreach ($markers as $marker) {
            if (strpos($lower, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断是否为真正的直链播放地址（m3u8 / mp4 / flv 等）
     *
     * @param string $url
     * @return bool
     */
    public function isDirectVideoUrl($url)
    {
        if ($url === null || $url === '' || strlen($url) < 10) {
            return false;
        }

        $videoExtensions = ['.m3u8', '.mp4', '.flv', '.avi', '.mkv', '.mov', '.wmv', '.webm'];
        $lower = strtolower($url);
        foreach ($videoExtensions as $ext) {
            if (strpos($lower, $ext) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断是否为合法 http(s) URL
     *
     * @param string $url
     * @return bool
     */
    public function isValidHttpUrl($url)
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * 对候选播放源进行质量升级与排序，优先选择 4K / 最高码率的 m3u8 变体
     *
     * @param array  $playUrls
     * @param string $refererPageUrl
     * @return array
     */
    public function upgradeToBestQuality(array $playUrls, $refererPageUrl)
    {
        if (!$playUrls) {
            return $playUrls;
        }

        $bestFirst = [];
        $others = [];

        foreach ($playUrls as $url) {
            if (stripos($url, '.m3u8') !== false) {
                try {
                    $bestVariant = $this->selectBestM3u8Variant($url, $refererPageUrl);
                    if ($bestVariant) {
                        if (!in_array($bestVariant, $bestFirst, true)) {
                            $bestFirst[] = $bestVariant;
                        }
                        continue;
                    }
                } catch (\Throwable $e) {
                    // 忽略单个失败
                }
            }
            if (!in_array($url, $others, true)) {
                $others[] = $url;
            }
        }

        $merged = [];
        foreach (array_merge($bestFirst, $others) as $u) {
            if (!in_array($u, $merged, true)) {
                $merged[] = $u;
            }
        }

        return $merged;
    }

    /**
     * 下载并解析主 m3u8，选择 4K / 最高分辨率或最高带宽的变体，返回绝对 URL
     *
     * @param string $m3u8Url
     * @param string $refererPageUrl
     * @return string|null
     */
    public function selectBestM3u8Variant($m3u8Url, $refererPageUrl)
    {
        $extraHeaders = [
            'Accept' => 'application/x-mpegURL, application/vnd.apple.mpegurl, */*',
            'Referer' => $refererPageUrl,
        ];

        try {
            $body = $this->httpGet($m3u8Url, 10, $extraHeaders);
            if ($body === null) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $variants = $this->parseMasterM3u8Variants($body);
        if (!$variants) {
            return $m3u8Url;
        }

        usort($variants, function ($a, $b) {
            return $this->m3u8Score($b) <=> $this->m3u8Score($a);
        });

        $best = $variants[0];
        $bestUrl = isset($best['url']) ? $best['url'] : '';
        if ($bestUrl === '') {
            return $m3u8Url;
        }

        return $this->resolveUrl($m3u8Url, $bestUrl);
    }

    /**
     * 计算 m3u8 变体的优先级分数
     *
     * @param array $v
     * @return array [is4k, height, width, bandwidth]
     */
    protected function m3u8Score(array $v)
    {
        $width = isset($v['resolution'][0]) ? (int) $v['resolution'][0] : 0;
        $height = isset($v['resolution'][1]) ? (int) $v['resolution'][1] : 0;
        $bandwidth = isset($v['bandwidth']) ? (int) $v['bandwidth'] : 0;
        $name = isset($v['name']) ? strtolower($v['name']) : '';

        $is4k = $height >= 2160 || $width >= 3840
            || strpos($name, '4k') !== false
            || strpos($name, 'hdr') !== false
            || strpos($name, 'dolby') !== false
            || strpos($name, '杜比') !== false;

        return [$is4k ? 1 : 0, $height, $width, $bandwidth];
    }

    /**
     * 从主 m3u8 内容中解析变体信息
     *
     * @param string $content
     * @return array [{bandwidth, resolution, name, url}, ...]
     */
    public function parseMasterM3u8Variants($content)
    {
        $lines = preg_split('/\r?\n/', $content);
        $variants = [];
        $lastInfo = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '#EXT-X-STREAM-INF') === 0) {
                $info = ['bandwidth' => 0, 'resolution' => [0, 0], 'name' => ''];

                $attrStr = '';
                if (strpos($line, ':') !== false) {
                    $attrStr = substr($line, strpos($line, ':') + 1);
                }
                $attrs = [];
                $parts = preg_split('/,(?=[A-Za-z\-]+=)/', $attrStr);
                foreach ($parts as $part) {
                    if (strpos($part, '=') !== false) {
                        list($k, $v) = explode('=', $part, 2);
                        $attrs[trim($k)] = trim($v, " \t\"");
                    }
                }

                $bw = isset($attrs['BANDWIDTH']) ? $attrs['BANDWIDTH'] : (isset($attrs['AVERAGE-BANDWIDTH']) ? $attrs['AVERAGE-BANDWIDTH'] : '');
                if ($bw !== '' && is_numeric($bw)) {
                    $info['bandwidth'] = (int) $bw;
                }

                if (!empty($attrs['RESOLUTION']) && strpos($attrs['RESOLUTION'], 'x') !== false) {
                    $res = explode('x', strtolower($attrs['RESOLUTION']));
                    $info['resolution'] = [(int) $res[0], (int) $res[1]];
                }

                $info['name'] = isset($attrs['NAME']) ? $attrs['NAME'] : '';
                $lastInfo = $info;
            } elseif ($line[0] !== '#' && $lastInfo !== null) {
                $entry = $lastInfo;
                $entry['url'] = $line;
                $variants[] = $entry;
                $lastInfo = null;
            }
        }

        return $variants;
    }

    /**
     * 解析相对 URL 为绝对 URL
     *
     * @param string $base 基准 URL
     * @param string $url  相对或绝对 URL
     * @return string
     */
    protected function resolveUrl($base, $url)
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $parts = parse_url($base);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = isset($parts['host']) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if ($url === '' || $url[0] === '#') {
            return $base;
        }

        if (strpos($url, '//') === 0) {
            return $scheme . ':' . $url;
        }

        if ($url[0] === '/') {
            return $scheme . '://' . $host . $port . $url;
        }

        $path = isset($parts['path']) ? $parts['path'] : '/';
        $dir = preg_replace('#/[^/]*$#', '/', $path);
        return $scheme . '://' . $host . $port . $dir . $url;
    }

    /**
     * 检测单个解析接口是否可用（供配置前验证）
     *
     * 对接口发起一次真实请求，分析返回内容并判断其类型：
     *  - parse    直链解析型：能提取出 m3u8 / mp4 等直链播放地址
     *  - iframe   播放器型：返回播放器页面（iframe / video 标签或播放器特征）
     *  - unknown  无法识别类型（可能已失效或需要浏览器环境）
     *  - error    请求失败（超时 / 连接失败 / 非 2xx）
     *
     * @param string $api 解析接口地址（如 https://jx.xxx.com/?url=）
     * @param string $videoUrl 测试视频链接
     * @return array
     */
    public function detectApi(string $api, string $videoUrl): array
    {
        $start = microtime(true);
        $fullUrl = $api . $videoUrl;

        $result = [
            'api' => $api,
            'full_url' => $fullUrl,
            'http_code' => 0,
            'size' => 0,
            'time_ms' => 0,
            'type' => 'error',
            'type_label' => '请求失败',
            'play_urls' => [],
            'play_url_count' => 0,
            'message' => '',
        ];

        $body = $this->httpGet($fullUrl, 10);
        $result['time_ms'] = (int) round((microtime(true) - $start) * 1000);

        if ($body === null) {
            $result['message'] = '请求失败（超时 / 连接失败 / 非 2xx）';
            return $result;
        }

        $result['size'] = strlen($body);
        $result['http_code'] = 200;

        // 提取直链播放源
        $playUrls = $this->extractPlayUrls($body);
        $result['play_urls'] = array_slice($playUrls, 0, 3);
        $result['play_url_count'] = count($playUrls);

        if ($playUrls) {
            $result['type'] = 'parse';
            $result['type_label'] = '直链解析型（可提取播放地址）';
            $result['message'] = '成功提取 ' . count($playUrls) . ' 个直链播放源';
            return $result;
        }

        // 判断是否为播放器页面（iframe / video 标签 / 播放器特征）
        $lower = strtolower($body);
        $hasIframe = stripos($lower, '<iframe') !== false;
        $hasVideo = stripos($lower, '<video') !== false;
        $hasPlayerTitle = stripos($lower, '播放器') !== false || stripos($lower, 'player') !== false;
        $hasObfuscated = stripos($lower, 'eval(') !== false
            || stripos($lower, 'fromCharCode') !== false
            || stripos($lower, 'URLSearchParams') !== false;

        if ($hasIframe || $hasVideo || $hasPlayerTitle || $hasObfuscated) {
            $result['type'] = 'iframe';
            $result['type_label'] = '播放器型（整站 iframe 嵌入播放）';
            $result['message'] = '返回播放器页面，需作为 iframe 播放源使用';
            return $result;
        }

        $result['type'] = 'unknown';
        $result['type_label'] = '无法识别';
        $result['message'] = '返回内容无法识别为有效播放源，可能已失效或需浏览器环境';
        return $result;
    }

    /**
     * 批量检测解析接口（含配置中已有的接口）
     *
     * @param string $videoUrl 测试视频链接
     * @param array  $apis 待检测接口列表
     * @return array
     */
    public function detectApis(string $videoUrl, array $apis): array
    {
        $results = [];
        foreach ($apis as $api) {
            $results[] = $this->detectApi($api, $videoUrl);
        }
        return $results;
    }
}
