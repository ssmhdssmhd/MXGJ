<?php
/**
 * 沫兮官替系统 - 多线程资源站搜索
 *
 * 使用 PHP cURL 并发向后台配置的所有资源站同时搜索同一部剧对应集数，
 * 并在命中结果中优先返回分辨率更高的 m3u8 地址。
 */

class SiteSearcher
{
    /**
     * 多线程搜索所有资源站
     *
     * @param array  $sites    资源站配置列表
     * @param string $title    剧名
     * @param int    $episode  集数
     * @param int    $timeout  单请求超时
     *
     * @return array{code:int,url:string,site:string,msg:string,episode:int}
     */
    public static function search(array $sites, string $title, int $episode, int $timeout = 15): array
    {
        $title   = trim($title);
        $episode = max(0, (int)$episode);
        $errors  = [];

        if ($sites === []) {
            return ['code' => 501, 'url' => '', 'site' => '', 'msg' => '后台尚未配置任何资源站', 'episode' => $episode];
        }
        if ($title === '') {
            return ['code' => 502, 'url' => '', 'site' => '', 'msg' => '无法识别该链接对应的剧名', 'episode' => $episode];
        }

        // 频率控制：心跳探测 + 搜索节流 + 轮训分批（见 lib/SiteHealth.php）
        $pool = SiteHealth::usable($sites, $timeout);
        if ($pool === []) {
            return ['code' => 503, 'url' => '', 'site' => '', 'msg' => '所有资源站当前均不可用/在冷却期/被节流', 'episode' => $episode];
        }

        // 为每个通过频率控制的资源站生成请求句柄
        $handles = [];
        foreach ($pool as $item) {
            $site = $item['site'];
            $url  = self::buildUrl($site, $title, $episode);
            if ($url === '') {
                continue;
            }
            $handles[] = [
                'ch'    => self::makeHandle($url, $timeout),
                'site'  => $site,
                'key'   => $item['key'],
                't0'    => microtime(true),
                'url'   => $url,
            ];
        }

        if ($handles === []) {
            return ['code' => 503, 'url' => '', 'site' => '', 'msg' => '资源站 URL 模板无法生成', 'episode' => $episode];
        }

        // 多线程并发执行
        $multi = curl_multi_init();
        foreach ($handles as $h) {
            curl_multi_add_handle($multi, $h['ch']);
        }
        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.3);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // 汇总结果
        $candidates = [];
        foreach ($handles as $h) {
            $body = curl_multi_getcontent($h['ch']);
            $err  = curl_error($h['ch']);
            $ms   = (int)((microtime(true) - $h['t0']) * 1000);
            $ok   = false;
            if ($err !== '') {
                $errors[] = ($h['site']['name'] ?? $h['url']) . '：' . $err;
            }
            if ($body !== false && $body !== '') {
                $parsed = self::parseBody($body, $episode);
                if ($parsed['url'] !== '') {
                    $parsed['site'] = $h['site']['name'] ?? $h['url'];
                    $parsed['url']  = self::finalizeUrl($parsed['url'], $h['site']);
                    $parsed['w']    = (int)($parsed['w'] ?? 0);
                    $candidates[]   = $parsed;
                    $ok = true;
                } elseif ($parsed['msg'] !== '') {
                    $errors[] = ($h['site']['name'] ?? $h['url']) . '：' . $parsed['msg'];
                }
            }
            curl_multi_remove_handle($multi, $h['ch']);
            @curl_close($h['ch']);
            // 上报频率控制健康状态（成功/失败）
            SiteHealth::record($ok, $h['key'], $ms);
        }
        curl_multi_close($multi);

        if ($candidates === []) {
            return [
                'code'    => 404,
                'url'     => '',
                'site'    => '',
                'msg'     => '未找到《' . $title . '》第' . $episode . '集资源'
                          . ($errors ? '，详情：' . implode('；', array_slice($errors, 0, 3)) : ''),
                'episode' => $episode,
            ];
        }

        // 优先分辨率更高，其次按资源站顺序
        usort($candidates, function ($a, $b) {
            return ($b['w'] ?? 0) <=> ($a['w'] ?? 0);
        });
        $best = $candidates[0];

        return [
            'code'    => 200,
            'url'     => $best['url'],
            'site'    => $best['site'],
            'msg'     => 'success',
            'episode' => $episode,
        ];
    }

    /**
     * 根据资源站配置构造搜索地址（模板含占位符 %s/%u/%t/%p）
     */
    protected static function buildUrl(array $site, string $title, int $episode): string
    {
        $tpl = $site['template'] ?? ($site['url'] ?? '');
        if (!is_string($tpl) || $tpl === '') {
            return '';
        }
        if (strpos($tpl, '%u') !== false || strpos($tpl, '%t') !== false || strpos($tpl, '%U') !== false || strpos($tpl, '%T') !== false) {
            $tpl = str_replace(['%U', '%T'], [rawurlencode($title), $title], $tpl);
            $tpl = str_replace(['%u', '%t'], [urlencode($title), $title], $tpl);
        }
        $tpl = str_replace(['%s', '%S', '%p'], [$title, $title, $episode], $tpl);
        return $tpl;
    }

    /**
     * 生成 curl 句柄
     */
    protected static function makeHandle(string $url, int $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain, */*', 'Referer: ' . self::origin($url)],
        ]);
        return $ch;
    }

    /**
     * 获取链接来源域名
     */
    protected static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        return $parts['scheme'] . '://' . $parts['host'] . ($parts['port'] ?? '') . '/';
    }

    /**
     * 解析资源站返回内容（JSON / JSONP / 纯文本 / 苹果CMS列表 / 未匹配）
     *
     * @param string $body    响应正文
     * @param int    $episode 目标集数（用于苹果CMS列表内按集数提取）
     */
    protected static function parseBody(string $body, int $episode = 0): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if ($body === '') {
            return ['url' => '', 'msg' => '返回为空', 'w' => 0];
        }
        // 兼容 JSONP：xx({...})
        if (preg_match('~\{.*\}~s', $body, $m)) {
            $body = $m[0];
        }
        $data = json_decode($body, true);
        if (is_array($data)) {
            $url = $data['url'] ?? $data['m3u8'] ?? ($data['data']['url'] ?? '');
            $w   = $data['w'] ?? $data['width'] ?? ($data['data']['w'] ?? 0);
            $msg = $data['msg'] ?? $data['message'] ?? '';
            if ($url !== '' && $url !== null) {
                return ['url' => (string)$url, 'msg' => '', 'w' => (int)$w];
            }
            // 苹果CMS 采集接口：list[] + vod_play_url（第N集$地址#第N集$地址...）
            if (isset($data['list']) && is_array($data['list'])) {
                $hit = self::extractAppleList($data['list'], $episode);
                if ($hit !== '') {
                    return ['url' => $hit, 'msg' => '', 'w' => 0];
                }
            }
            return ['url' => '', 'msg' => (string)$msg, 'w' => (int)$w];
        }
        // 纯文本：以 http 开头或以 .m3u8 结尾
        $body = trim($body);
        if (strpos($body, 'http') === 0 || substr($body, -5) === '.m3u8') {
            return ['url' => $body, 'msg' => '', 'w' => 0];
        }
        return ['url' => '', 'msg' => '非标准返回', 'w' => 0];
    }

    /**
     * 从苹果CMS 的 list 中提取目标集数的播放地址
     *
     * vod_play_url 格式：第1集$http://...#第2集$http://...#...
     * 优先返回“集数 == 目标集数”的地址；找不到目标集数时返回第一条。
     */
    protected static function extractAppleList(array $list, int $episode): string
    {
        $all    = [];   // 全部候选 [ep, url]
        $target = '';   // 目标集数地址
        foreach ($list as $item) {
            $playUrl = (string)($item['vod_play_url'] ?? '');
            if ($playUrl === '') {
                continue;
            }
            foreach (explode('#', $playUrl) as $seg) {
                $seg = trim($seg);
                if ($seg === '') {
                    continue;
                }
                $parts = array_pad(explode('$', $seg, 2), 2, '');
                $label = $parts[0];
                $u     = $parts[1] !== '' ? $parts[1] : $parts[0];
                if (strpos($u, 'http') !== 0) {
                    continue;
                }
                $ep = 0;
                if (preg_match('~第?\s*(\d{1,4})\s*集~u', $label, $m)) {
                    $ep = (int)$m[1];
                }
                if ($ep > 0 && $episode > 0 && $ep === $episode) {
                    $target = $u; // 命中目标集
                }
                $all[] = ['ep' => $ep, 'u' => $u];
            }
        }
        if ($target !== '') {
            return $target;
        }
        if ($all !== []) {
            // 无目标集时，优先取离目标集最近的一集，否则取第一条
            $best = $all[0];
            if ($episode > 0) {
                $bestDiff = PHP_INT_MAX;
                foreach ($all as $c) {
                    $diff = abs($c['ep'] - $episode);
                    if ($c['ep'] > 0 && $diff < $bestDiff) {
                        $bestDiff = $diff;
                        $best     = $c;
                    }
                }
            }
            return $best['u'];
        }
        return '';
    }

    /**
     * 域名替换 / 中转前缀（后台「设置」中配置）
     */
    protected static function finalizeUrl(string $url, array $site): string
    {
        // 1) 全局域名替换/中转前缀
        $replace = mxgj_settings()['replace_domain'] ?? '';
        if (is_string($replace) && $replace !== '') {
            $replace = rtrim($replace, '/');
            if (preg_match('~^(https?://[^/]+)~i', $url, $m)) {
                $url = $replace . '/' . ltrim(substr($url, strlen($m[1])), '/');
            }
        }
        // 2) 站点级「跟随播放链接」中转前缀（仅配置了 proxy 的资源站命中时生效）
        //    支持 {host} 占位符 → 自动替换为当前域名（如 {host}/player.php?url=）
        $proxy = mxgj_resolve_proxy_prefix(trim((string)($site['proxy'] ?? '')));
        if ($proxy !== '') {
            if (substr($proxy, -1) === '=') {
                // proxy 已自带参数名（如 https://vv00.xyz?url=），直接拼接
                $url = $proxy . $url;
            } else {
                // 否则统一以 ?url= 形式拼接
                $url = rtrim($proxy, '?&') . '?url=' . $url;
            }
        }
        return $url;
    }
}