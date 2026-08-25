<?php
/**
 * 沫兮官替系统 - 苹果CMS10 采集接口检测与模板自动构建（SiteDetector）
 *
 * 用户只需粘贴一个「苹果 CMS10 采集接口」地址（如 https://caiji.xxx.com/api.php/provide/vod/），
 * 本类自动：
 *   1) 校验接口可达性；
 *   2) 探测返回是否为苹果CMS列表格式（list[] 且含可播放 http 地址），证明能拿到真实资源；
 *   3) 自动构建带占位符的搜索模板（?ac=videolist&wd=%u）；
 *   4) 自动生成可读的站点名称。
 * 后台「资源站」页用它做「检测并自动添加/修改」，使前端能正常并发调用这些资源站。
 */

class SiteDetector
{
    /* 苹果CMS列表中提取第一个可播放的 http 地址（第N集$地址#...） */
    protected static function firstRealUrl(array $item): string
    {
        $playUrl = (string)($item['vod_play_url'] ?? '');
        if ($playUrl === '') { return ''; }
        foreach (explode('#', $playUrl) as $seg) {
            $seg = trim($seg);
            if ($seg === '') { continue; }
            $parts = array_pad(explode('$', $seg, 2), 2, '');
            $u = $parts[1] !== '' ? $parts[1] : $parts[0];
            if (strpos($u, 'http') === 0) { return $u; }
        }
        return '';
    }

    /* 从苹果CMS返回体里取出第一个有效条目 */
    protected static function firstItem(array $data): ?array
    {
        if (isset($data['list']) && is_array($data['list']) && $data['list'] !== []) {
            foreach ($data['list'] as $it) {
                if (!empty($it['vod_name'])) { return $it; }
            }
        }
        return null;
    }

    /**
     * 由用户粘贴的采集接口地址，生成带占位符的搜索模板
     *
     * 例：https://caiji.xgzyapi.com/api.php/provide/vod/
     *  -> https://caiji.xgzyapi.com/api.php/provide/vod/?ac=videolist&wd=%u
     */
    protected static function buildTemplate(string $base): string
    {
        $tpl = trim($base);
        if ($tpl === '') { return ''; }
        // 去掉已有的 ac/wd/pg 等查询参数，避免重复
        $tpl = preg_replace('~([?&])(ac|wd|pg|h|t|limit)=[^&]*~i', '$1', $tpl);
        $tpl = preg_replace('~[?&]+$~', '', $tpl);
        $sep = (strpos($tpl, '?') === false) ? '?' : '&';
        return $tpl . $sep . 'ac=videolist&wd=%u';
    }

    /* HTTP GET */
    protected static function fetch(string $url, int $timeout): array
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
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        @curl_close($ch);
        return ['body' => $body, 'code' => (int)$code, 'err' => $err];
    }

    /**
     * 检测一个采集接口
     *
     * @param string $rawUrl  用户粘贴的采集接口地址
     * @param int    $timeout
     * @return array{ok:bool,name:string,template:string,type:string,
     *               sample:array{name:string,url:string},code:int,msg:string}
     */
    public static function detect(string $rawUrl, int $timeout = 15): array
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => '', 'url' => ''], 'code' => 0, 'msg' => '请输入采集接口地址'];
        }
        if (strpos($rawUrl, 'http') !== 0) {
            $rawUrl = 'http://' . $rawUrl;
        }
        $host = (string)parse_url($rawUrl, PHP_URL_HOST);
        if ($host === '') {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => '', 'url' => ''], 'code' => 0, 'msg' => '链接格式非法'];
        }

        // 探测列表接口（不携带 wd，拉最新列表）
        $probe = self::probeListUrl($rawUrl);
        $res   = self::fetch($probe, $timeout);
        if ($res['err'] !== '') {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => '', 'url' => ''], 'code' => 0, 'msg' => '接口不可达：' . $res['err']];
        }
        if ($res['code'] < 200 || $res['code'] >= 300) {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => '', 'url' => ''], 'code' => $res['code'], 'msg' => '接口返回 HTTP ' . $res['code']];
        }
        $body = ltrim((string)$res['body'], "\xEF\xBB\xBF \t\r\n");
        if (preg_match('~\{.*\}~s', $body, $m)) { $body = $m[0]; }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => '', 'url' => ''], 'code' => $res['code'], 'msg' => '返回内容不是 JSON（可能非 CMS 采集接口）'];
        }
        $item = self::firstItem($data);
        if ($item === null) {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => '', 'url' => ''], 'code' => $res['code'], 'msg' => '接口可达但未返回有效视频列表（list[] 为空）'];
        }
        $realUrl = self::firstRealUrl($item);
        if ($realUrl === '') {
            return ['ok' => false, 'name' => '', 'template' => '', 'type' => '', 'sample' => ['name' => (string)$item['vod_name'], 'url' => ''], 'code' => $res['code'], 'msg' => '列表有剧名，但无可播放地址（vod_play_url 未含 http 链接）'];
        }

        return [
            'ok'       => true,
            'name'     => self::makeName($host),
            'template' => self::buildTemplate($rawUrl),
            'type'     => '苹果CMS10采集接口',
            'sample'   => ['name' => (string)$item['vod_name'], 'url' => $realUrl],
            'code'     => $res['code'],
            'msg'      => '检测成功：可正常获取真实资源',
        ];
    }

    /* 列表探测地址：base + ac=videolist（去掉 wd） */
    protected static function probeListUrl(string $base): string
    {
        $tpl = self::buildTemplate($base);
        return str_replace('wd=%u', '', $tpl); // 仅保留 ac=videolist
    }

    /* 用主域名生成可读站名，如 caiji.xgzyapi.com -> XGZYAPI 资源站 */
    protected static function makeName(string $host): string
    {
        $host  = strtolower($host);
        $parts = explode('.', $host);
        $main  = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];
        $main  = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $main) ?: $host);
        return $main . ' 资源站';
    }
}