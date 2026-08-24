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
                return [
                    'success' => true,
                    'title' => ($videoInfo && !empty($videoInfo['title'])) ? $videoInfo['title'] : '解析成功',
                    'description' => ($videoInfo && !empty($videoInfo['description'])) ? $videoInfo['description'] : '',
                    'urls' => $playUrls,
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
     * @param string $url
     * @return array
     */
    public function generatePlayUrls($url)
    {
        try {
            // 方法1：第三方接口解析（主要方法）
            $playUrls = $this->tryThirdPartyParse($url);

            // 方法2：直接解析
            if (!$playUrls) {
                $playUrls = $this->tryDirectParse($url);
            }

            // 方法3：移动端解析
            if (!$playUrls) {
                $playUrls = $this->tryMobileParse($url);
            }

            // 方法4：生成通用播放器链接（备用方案）
            if (!$playUrls) {
                foreach ($this->parseApis as $api) {
                    $playUrls[] = $api . $url;
                }
            }

            // 方法5：iframe 播放器源（如虾米播放器，整站 iframe 嵌入播放）
            foreach ($this->iframePlayers as $api) {
                $playUrls[] = $api . $url;
            }

            // 去重（保持顺序）
            $playUrls = array_values(array_unique($playUrls));

            // 升级为最高清晰度（优先 4K / HDR / 最高码率）
            if ($this->enableQualityUpgrade) {
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
     * @param string $url
     * @return array
     */
    protected function tryThirdPartyParse($url)
    {
        $playUrls = [];
        foreach ($this->parseApis as $api) {
            $parseUrl = $api . $url;
            $body = $this->httpGet($parseUrl, 10);
            if ($body !== null) {
                $newUrls = $this->extractPlayUrls($body);
                if ($newUrls) {
                    $playUrls = array_merge($playUrls, $newUrls);
                    break;
                }
            }
            usleep(500000);
        }
        return $playUrls;
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
    public function extractPlayUrls($htmlContent)
    {
        $playUrls = [];
        $patterns = [
            '#"url":"([^"]+\.m3u8[^"]*)"#',
            '#"url":"([^"]+\.mp4[^"]*)"#',
            '#"playUrl":"([^"]+)"#',
            '#"src":"([^"]+\.m3u8[^"]*)"#',
            '#"src":"([^"]+\.mp4[^"]*)"#',
            '#<iframe[^>]+src="([^"]+)"#i',
            '#<video[^>]+src="([^"]+)"#i',
            '#<source[^>]+src="([^"]+)"#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $htmlContent, $matches)) {
                foreach ($matches[1] as $match) {
                    if (!in_array($match, $playUrls, true) && $this->isValidVideoUrl($match)) {
                        $playUrls[] = $match;
                    }
                }
            }
        }

        return $playUrls;
    }

    /**
     * 验证是否为有效的视频 URL
     *
     * @param string $url
     * @return bool
     */
    public function isValidVideoUrl($url)
    {
        if ($url === null || $url === '' || strlen($url) < 10) {
            return false;
        }

        $videoExtensions = ['.m3u8', '.mp4', '.flv', '.avi', '.mkv', '.mov', '.wmv'];
        $lower = strtolower($url);
        foreach ($videoExtensions as $ext) {
            if (strpos($lower, $ext) !== false) {
                return true;
            }
        }

        $videoKeywords = ['video', 'player', 'play', 'stream', 'media'];
        foreach ($videoKeywords as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                return true;
            }
        }

        return false;
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
}
