<?php
/**
 * 沫兮官替系统 - 官方页面抓取解析
 *
 * 当从链接字符串中解析不出剧名/集数时，直接访问官方播放页抓取信息。
 *
 * 策略：
 *  1. 用移动端 UA 请求官方页面（移动页 title 通常含「剧名第N集」）
 *  2. 提取 <title>，清洗掉平台站点后缀，得到剧名
 *  3. 从标题中正则提取集数（第N集/话/期/回）
 *
 * 实测：https://m.iqiyi.com/v_cix2gal5d8.html
 *   <title>蝉第1集-电视剧全集-完整版视频在线观看-爱奇艺</title>
 *   → 剧名「蝉」、第1集
 */

class PageResolver
{
    /** 平台/后缀关键词（清洗 title 时移除） */
    public const SITE_WORDS = [
        '爱奇艺', '腾讯视频', 'v.qq.com', '优酷', '芒果TV', '芒果tv', 'hunantv',
        '哔哩哔哩', 'bilibili', 'pptv', '搜狐视频', '土豆视频',
        '在线观看', '完整版', '在线视频', '手机在线', '手机版', '高清全集',
        '全集', '高清', '正版', '电视剧', '电影', '综艺', '动漫', 'MV',
        '预告片', '花絮', '独播', 'BD版', 'DVD版',
    ];

    /** 明显无效/通用标题的拒绝词（命中则不采用，回退 502） */
    public const REJECT_WORDS = [
        '页面不存在', '不存在', '未找到', '找不到', '错误', '出错了', '404', 'not found',
        '回首页', '首页', '网站维护', '加载失败', '请求失败', '获取失败', '无权',
        '无法访问', '信息不存在', '该内容', '已下线', '在线视频网站', '网络电视',
        '视频网站', '播放器', '登录', '注册',
    ];

    /**
     * 抓取并解析官方页面
     *
     * @return array{title:string, episode:int, from:string}
     */
    public static function resolve(string $url, int $fallbackEpisode = 0, int $timeout = 15): array
    {
        // 优先原链接；若拿不到有效标题，再尝试移动子域（桌面站常只给平台名，移动端才给“剧名第N集”）
        $attempts = [$url];
        $m = self::mobileVariant($url);
        if ($m !== null && $m !== $url) {
            $attempts[] = $m;
        }

        foreach ($attempts as $u) {
            $html = self::fetch($u, $timeout);
            if ($html === '') {
                continue;
            }
            $title = self::extractTitle($html);
            if ($title === '' || self::isRejected($title)) {
                continue;
            }
            $episode = 0;
            $cleaned = self::clean($title, $episode);
            // 清洗结果为空或本身就是平台/站点词时，视为无效标题，回退
            if ($cleaned === '' || in_array($cleaned, self::SITE_WORDS, true)) {
                continue;
            }
            return [
                'title' => $cleaned,
                'episode' => $episode > 0 ? $episode : $fallbackEpisode,
                'from' => 'page',
            ];
        }
        return ['title' => '', 'episode' => 0, 'from' => ''];
    }

    /**
     * 生成同一链接的移动子域版本（若无映射则返回 null）
     */
    protected static function mobileVariant(string $url): ?string
    {
        $parts = parse_url($url);
        if (!isset($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        static $map = [
            'iqiyi.com'      => 'm.iqiyi.com',
            'www.iqiyi.com'  => 'm.iqiyi.com',
            'youku.com'      => 'm.youku.com',
            'www.youku.com'  => 'm.youku.com',
            'v.youku.com'    => 'm.youku.com',
            'mgtv.com'       => 'm.mgtv.com',
            'www.mgtv.com'   => 'm.mgtv.com',
            'bilibili.com'   => 'm.bilibili.com',
            'www.bilibili.com' => 'm.bilibili.com',
            'v.qq.com'       => 'm.v.qq.com',
            'qq.com'         => 'm.v.qq.com',
        ];
        if (!isset($map[$host])) {
            return null;
        }
        $parts['host'] = $map[$host];
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '//';
        $auth = '';
        if (isset($parts['user'])) {
            $auth = $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $url2 = $scheme . $auth . $parts['host'];
        $url2 .= isset($parts['port']) ? ':' . $parts['port'] : '';
        $url2 .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $url2 .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url2 .= '#' . $parts['fragment'];
        }
        return $url2;
    }

    /**
     * 判断标题是否明显无效（404 页 / 通用首页 / 站点提示）
     */
    protected static function isRejected(string $title): bool
    {
        $low = mxgj_lower($title);
        foreach (self::REJECT_WORDS as $w) {
            if (strpos($low, mxgj_lower($w)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 抓取页面正文
     */
    protected static function fetch(string $url, int $timeout): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        if ($err !== '' || !is_string($body)) {
            return '';
        }
        return $body;
    }

    /**
     * 提取 <title>
     */
    protected static function extractTitle(string $html): string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = trim(html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
            return trim($title, " \t\r\n-|") ?: '';
        }
        return '';
    }

    /**
     * 清洗标题并提取集数
     */
    protected static function clean(string $raw, int &$episode): string
    {
        $s = $raw;

        // 1) 提取集数：第N集 / 第N话 / 第N期 / 第N回
        if (preg_match('~第\s*(\d{1,4})\s*[集话期回场]~u', $s, $m)) {
            $episode = (int)$m[1];
            $s = str_replace($m[0], '', $s);
        } elseif (preg_match('~(?:EP|ep|E)\s*(\d{1,4})~', $s, $m2)) {
            $episode = (int)$m2[1];
            $s = str_replace($m2[0], '', $s);
        }

        // 2) 按常见分隔符拆段，取正文片段（跳过纯平台后缀段）
        $segments = preg_split('#[-_|,，、&~()\[\]【】\s]+#u', $s, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($segments as $seg) {
            $seg = trim($seg ?? '');
            if ($seg === '') {
                continue;
            }
            // 跳过纯站点关键词段
            if (in_array($seg, self::SITE_WORDS, true)) {
                continue;
            }
            if (preg_match('/\p{Han}/u', $seg)) {
                // 仅裁剪片段尾部的常见修饰词，避免误删剧名内部的字
                $clean = self::stripTailWords($seg);
                if ($clean !== '' && preg_match('/\p{Han}/u', $clean)) {
                    return mb_substr($clean, 0, 60);
                }
            }
        }

        // 3) 兜底：去掉纯符号后返回
        $s = trim(preg_replace('#[-_|,，、&~\s]+#u', '', $s));
        return mb_substr($s, 0, 60);
    }

    /** 逐步裁掉片段尾部的修饰词（视频/全集/高清等） */
    protected static function stripTailWords(string $seg): string
    {
        $tails = ['视频', '全集', '高清', '完整版', '完整', '在线', '剧场版', '国语版', '粤语版', '国语', '粤语', '超清', '会员版', '官方', '中字', '无水印', '蓝光', 'HD版'];
        $prev = '';
        while ($seg !== $prev) {
            $prev = $seg;
            foreach ($tails as $tw) {
                if ($seg !== '' && mb_strlen($seg) > mb_strlen($tw) && mb_substr($seg, -mb_strlen($tw)) === $tw) {
                    $seg = mb_substr($seg, 0, -mb_strlen($tw));
                }
            }
        }
        return trim($seg, " \t\r\n-|,，、&");
    }
}