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
            return array_merge($base, [
                'platform' => '腾讯视频',
                'vid' => $query['vid'] ?? '',
                'cid' => $query['cid'] ?? '',
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

        // 芒果TV
        if (self::host($url, ['mgtv.com', 'hunantv.com'])) {
            $vid = self::regex($url, '~/(?:b|v)/_?(\d+)\.html~i') ?? '';
            return array_merge($base, ['platform' => '芒果TV', 'vid' => $vid]);
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