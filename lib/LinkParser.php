<?php
/**
 * 沫兮官替系统 - 官方链接解析
 *
 * 负责从主流视频平台播放链接中解析：平台、视频ID(vid)、剧集ID(cid)、剧名、集数。
 * 解析结果用于：本地映射表精确命中 → 资源站搜索关键词。
 */

class LinkParser
{
    /**
     * 解析官方链接
     *
     * @return array{platform:string,vid:string,cid:string,title:string,episode:int}
     */
    public static function parse(string $url): array
    {
        $url = trim($url);
        $base = ['platform' => '通用', 'vid' => '', 'cid' => '', 'title' => '', 'episode' => 0];

        // 腾讯视频
        if (self::host($url, ['v.qq.com', 'qq.com'])) {
            $query = self::query($url);
            $vid   = $query['vid'] ?? '';
            $cid   = $query['cid'] ?? '';

            // path 提取优先级高于 query（query 里可能为空）
            // /x/cover/{cid}.html        → cid
            // /x/cover/{cid}/{vid}.html  → cid + vid
            // /x/page/{vid}.html         → vid
            // /x/page/{vid}/{ep}.html    → vid + episode
            if ($cid === '') {
                if (preg_match('~/x/cover/([A-Za-z0-9]+)(?:\.html|/|$)~i', $url, $m)) {
                    $cid = $m[1];
                }
            }
            if ($vid === '') {
                if (preg_match('~/x/cover/[A-Za-z0-9]+/([A-Za-z0-9]+)\.html~i', $url, $m)) {
                    $vid = $m[1];
                } elseif (preg_match('~/x/page/([A-Za-z0-9]+)(?:\.html|/|$)~i', $url, $m)) {
                    $vid = $m[1];
                }
            }
            $ep = (int)(self::regex($url, '~/x/page/[A-Za-z0-9]+/(\d+)\.html~i') ?? 0);

            return array_merge($base, [
                'platform' => '腾讯视频',
                'vid'      => $vid,
                'cid'      => $cid,
                'episode'  => $ep,
            ]);
        }

        // 爱奇艺
        if (self::host($url, ['iqiyi.com'])) {
            return array_merge($base, ['platform' => '爱奇艺', 'vid' => self::regex($url, '~/(?:v_|w_|a_)([0-9a-zA-Z]+)~i') ?? '']);
        }

        // 优酷
        if (self::host($url, ['youku.com'])) {
            $vid = self::regex($url, '~/id_([0-9A-Za-z=_]+)~i') ?? '';
            $ep  = (int)(self::regex($url, '~(?:p|fid)[^0-9]*(\d{1,4})~i') ?? 0);
            return array_merge($base, ['platform' => '优酷', 'vid' => $vid, 'episode' => $ep]);
        }

        // 芒果TV（如 /b/{cid}/{vid}.html 或 /b/{cid}.html）
        if (self::host($url, ['mgtv.com', 'hunantv.com'])) {
            $cid = self::regex($url, '~/(?:b|v|h)/_?(\d+)(?:/\d+)?\.html~i') ?? '';
            $vid = self::regex($url, '~/(?:b|v|h)/_?\d+/(\d+)\.html~i') ?? '';
            return array_merge($base, ['platform' => '芒果TV', 'cid' => $cid, 'vid' => $vid]);
        }

        // 哔哩哔哩
        if (self::host($url, ['bilibili.com', 'b23.tv'])) {
            $vid = self::regex($url, '~/(av\d+|BV[0-9A-Za-z]+|ep\d+)~i') ?? '';
            $ep  = (int)(self::regex($url, '~[?&/]p=(\d{1,4})~i') ?? 0);
            return array_merge($base, ['platform' => '哔哩哔哩', 'vid' => $vid, 'episode' => $ep]);
        }

        // PPTV
        if (self::host($url, ['pptv.com'])) {
            $title = self::regex($url, '~/(?:vsudata/)?([^/]+?)(?:\.html)?$~i') ?? '';
            $title = preg_replace('/\.(html|htm)$/', '', $title);
            return array_merge($base, ['platform' => 'PPTV', 'vid' => $title, 'title' => $title]);
        }

        // 搜狐视频（链接里有 base64 编码的路径，如 /v/MjAyNTEyMjQvbjYyMDEzODAxMy5zaHRtbA==.html）
        if (self::host($url, ['sohu.com'])) {
            // 解码 base64 路径 → 拿到 vid
            $vid = '';
            $b64 = self::regex($url, '~/v/([A-Za-z0-9+/=]+)\.html~i');
            if ($b64 !== null) {
                $pad = 4 - strlen($b64) % 4;
                if ($pad !== 4) $b64 .= str_repeat('=', $pad);
                $decoded = base64_decode($b64, true);
                if ($decoded !== false) {
                    // 解码后类似 "20251224/n620138013.shtml" 或 "n620138013.shtml"
                    if (preg_match('~([a-zA-Z]\d{6,})~', $decoded, $vm)) {
                        $vid = $vm[1];
                    }
                }
            }
            return array_merge($base, ['platform' => '搜狐视频', 'vid' => $vid]);
        }

        return $base;
    }

    /**
     * 判断 URL 主机是否命中关键词
     */
    protected static function host(string $url, array $needles): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $low  = mxgj_lower($url);
        foreach ($needles as $n) {
            if ($host !== '' && strpos($host, $n) !== false) {
                return true;
            }
            if (strpos($low, $n) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析 URL 查询参数
     */
    protected static function query(string $url): array
    {
        $q = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    protected static function regex(string $subject, string $pattern): ?string
    {
        if (preg_match($pattern, $subject, $m)) {
            return $m[1] ?? $m[0];
        }
        return null;
    }
}