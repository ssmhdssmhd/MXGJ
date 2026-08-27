<?php
/**
 * 沫兮官替系统 - 苹果CMS10 采集接口检测与模板自动构建（SiteDetector v3）
 *
 * 两阶段 + 多策略探测：
 *   Phase 1:  基础列表探测（不带 wd）—— 确认可达、苹果CMS格式、真实播放地址
 *   Phase 1.5: 会话预热 + API 指纹扫描 —— 建立 cookie、测试多 ac / 多 search param
 *   Phase 2:  智能关键词搜索探测 —— 多策略重试 + 结果 Diff 对比
 *
 * 🔑 防误伤机制（解决"真接口被误判为假"）：
 *   1) 会话预热：先拉空列表建立 cookie/session，再发搜索请求
 *   2) 完整浏览器头：UA + Referer + Accept + Accept-Language
 *   3) 多搜索参数尝试：wd → keyword → q → name → search（5 个常用参数名）
 *   4) 多 ac 端点尝试：ac=videolist → ac=search
 *   5) 结果 Diff：关键词搜索返回的 vod_id 集 vs 空列表 vod_id 集，重合度 < 70% 才算真搜索
 *   6) 指数退避重试：rate-limited 接口第一次可能 429，等一下再试
 *   7) 验证码/风控检测：识别 Cloudflare challenge、403、HTML 拦截页
 */

class SiteDetector
{
    /* ========= HTTP 工具 ========= */

    /** 带 cookie jar 的 HTTP GET（浏览器级 headers） */
    protected static function fetch(string $url, int $timeout, ?string $cookieFile = null): array
    {
        $jar = $cookieFile ?? tempnam(sys_get_temp_dir(), 'sd_cj_');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER     => true,
            CURLOPT_TIMEOUT            => $timeout,
            CURLOPT_CONNECTTIMEOUT     => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION     => true,
            CURLOPT_MAXREDIRS          => 5,
            CURLOPT_SSL_VERIFYPEER     => false,
            CURLOPT_SSL_VERIFYHOST     => false,
            CURLOPT_COOKIEJAR          => $jar,
            CURLOPT_COOKIEFILE         => $jar,
            CURLOPT_USERAGENT          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate',
                'Connection: keep-alive',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ef   = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        @curl_close($ch);
        return [
            'body'        => $body,
            'code'        => (int)$code,
            'err'         => $err,
            'cookie_file' => $jar,
            'effective'   => $ef,
            'content_type'=> $ctype,
        ];
    }

    /** 构造浏览器 Referer（指向 API 的 host） */
    protected static function browserHeaders(string $apiHost): array
    {
        return [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Referer: https://' . $apiHost . '/',
            'Origin: https://' . $apiHost,
            'X-Requested-With: XMLHttpRequest',
        ];
    }

    /** 带自定义 headers 的 fetch */
    protected static function fetchWithHeaders(string $url, int $timeout, array $headers, string $cookieFile): array
    {
        $ch = curl_init($url);
        mxgj_apply_proxy($ch, $url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER     => true,
            CURLOPT_TIMEOUT            => $timeout,
            CURLOPT_CONNECTTIMEOUT     => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION     => true,
            CURLOPT_MAXREDIRS          => 5,
            CURLOPT_SSL_VERIFYPEER     => false,
            CURLOPT_SSL_VERIFYHOST     => false,
            CURLOPT_COOKIEJAR          => $cookieFile,
            CURLOPT_COOKIEFILE         => $cookieFile,
            CURLOPT_USERAGENT          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER         => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        @curl_close($ch);
        return ['body'=>$body,'code'=>(int)$code,'err'=>$err,'content_type'=>$ctype,'cookie_file'=>$cookieFile];
    }

    /** 指数退避重试 */
    protected static function fetchRetry(string $url, int $timeout, array $headers, string $cookieFile, int $maxRetries = 2): array
    {
        $last = self::fetchWithHeaders($url, $timeout, $headers, $cookieFile);
        for ($i = 0; $i < $maxRetries; $i++) {
            // 重试条件：网络错误 / 429 / 5xx
            if ($last['err'] === '' && $last['code'] !== 429 && $last['code'] < 500) break;
            usleep((int)(300000 * pow(2, $i))); // 300ms → 600ms
            $last = self::fetchWithHeaders($url, $timeout, $headers, $cookieFile);
        }
        return $last;
    }

    /* ========= 苹果CMS 解析工具 ========= */

    protected static function parseJson(string $body): ?array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (preg_match('~\{.*\}~s', $body, $m)) { $body = $m[0]; }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    protected static function firstRealUrl(array $item): string
    {
        $playUrl = (string)($item['vod_play_url'] ?? '');
        if ($playUrl === '') { return ''; }
        foreach (explode('#', $playUrl) as $seg) {
            $seg = trim($seg ?? '');
            if ($seg === '') { continue; }
            $parts = array_pad(explode('$', $seg, 2), 2, '');
            $u = $parts[1] !== '' ? $parts[1] : $parts[0];
            if (strpos($u, 'http') === 0) { return $u; }
        }
        return '';
    }

    protected static function firstItem(array $data): ?array
    {
        if (isset($data['list']) && is_array($data['list']) && $data['list'] !== []) {
            foreach ($data['list'] as $it) {
                if (!empty($it['vod_name'])) { return $it; }
            }
        }
        return null;
    }

    /** 从响应中提取 vod_id 集合（用于 Diff 对比） */
    protected static function extractVodIds(array $data): array
    {
        $ids = [];
        foreach (($data['list'] ?? []) as $item) {
            $id = $item['vod_id'] ?? null;
            if ($id !== null) $ids[] = (int)$id;
        }
        return $ids;
    }

    /** 计算两个数组的重合度（0~1） */
    protected static function overlapRatio(array $a, array $b): float
    {
        $setA = array_flip(array_unique($a));
        $setB = array_flip(array_unique($b));
        if ($setA === [] || $setB === []) return 1.0; // 空集视为完全相同（保守策略）
        $intersect = count(array_intersect_key($setA, $setB));
        $minSize = min(count($setA), count($setB));
        return $minSize > 0 ? $intersect / $minSize : 1.0;
    }

    /* ========= 构建工具 ========= */

    public static function buildTemplate(string $base): string
    {
        $tpl = trim($base ?? '');
        if ($tpl === '') { return ''; }

        // 判断路径类型
        $isIndexSearch = (strpos($tpl, 'index.php/vod/search') !== false);
        $isApiProvide  = (strpos($tpl, 'api.php') !== false);

        // 去掉已有的搜索参数
        $tpl = preg_replace('~([?&])(wd|keyword|q|name|pg|h|t|limit|ac)=[^&]*~i', '$1', $tpl);
        $tpl = preg_replace('~[?&]+$~', '', $tpl);
        $sep = (strpos($tpl, '?') === false) ? '?' : '&';

        // index.php/vod/search.html 路径：只加 wd=%u（苹果CMS10 前端搜索）
        if ($isIndexSearch) {
            return $tpl . $sep . 'wd=%u';
        }

        // api.php 路径：加 ac + wd
        if ($isApiProvide) {
            return $tpl . $sep . 'ac=videolist&wd=%u';
        }

        // 其他路径：保守做法
        $tpl .= $sep . 'ac=videolist';
        return $tpl . '&wd=%u';
    }

    protected static function makeName(string $host): string
    {
        $host  = strtolower($host);
        $parts = explode('.', $host);
        $main  = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];
        $main  = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $main) ?: $host);
        return $main . ' 资源站';
    }

    protected static function failResult(string $msg, int $code): array
    {
        return [
            'ok' => false, 'name' => '', 'template' => '', 'type' => '',
            'sample' => ['name' => '', 'url' => ''],
            'code' => $code, 'msg' => $msg,
            'searchable' => false, 'search_probe' => ['supported' => false, 'msg' => $msg, 'strategy_used' => 'none', 'overlap' => null],
        ];
    }

    /* ========= 风控 / 验证码检测 ========= */

    /** 检查响应是否被风控拦截（Cloudflare challenge / 403 / 非 JSON HTML 拦截页） */
    protected static function detectBlocked(array $resp): ?string
    {
        $code = $resp['code'];
        $body = strtolower((string)$resp['body']);
        $ctype = strtolower((string)($resp['content_type'] ?? ''));

        if ($code === 403) return '请求被服务器拒绝 (HTTP 403)';
        if ($code === 429) return '触发频率限制 (HTTP 429 Rate Limit)';
        if ($code >= 500) return '服务器错误 (HTTP ' . $code . ')';

        // Cloudflare challenge 页
        if (strpos($body, 'cf-challenge') !== false || strpos($body, 'cloudflare') !== false && strpos($body, 'just a moment') !== false) {
            return '触发 Cloudflare 人机验证 — 检测脚本被拦截';
        }
        // 通用验证码
        if (strpos($body, 'captcha') !== false || strpos($body, 'verify') !== false && strpos($body, 'code') !== false) {
            return '接口返回验证码 / 人机验证页面';
        }
        // 返回的是 HTML 而不是 JSON（排除 JSON 响应被 HTML 包装）
        if (strpos($ctype, 'html') !== false && strpos($body, '<!doctype') !== false) {
            return '接口返回 HTML 页面而非 JSON — 可能是拦截页或需要浏览器访问';
        }

        return null;
    }


    /** 🔐 从被拦截的响应中提取验证码图片 URL */
    public static function extractCaptchaUrl(string $body, string $apiHost): ?string
    {
        $patterns = [
            "~(?:verify|captcha|checkcode|check_code|code)\.(?:html|png|gif|jpg|jpeg)~i",
            "~/(?:captcha|verify|code)/[^\\s\"'<>]+(?:png|gif|jpg)~i",
            "~src=[\"']([^\"']*(?:verify|captcha|checkcode)[^\"']*)[\"']~i",
            "~(?:verify|captcha|code)=(\\d+)~i",
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $body, $m)) {
                $url = $m[1];
                if (strpos($url, 'http') !== 0) {
                    $url = 'https://' . $apiHost . (strpos($url, '/') === 0 ? '' : '/') . $url;
                }
                return $url;
            }
        }
        return 'https://' . $apiHost . '/index.php/vod/verify.html';
    }

    /**
     * 🔐 判断接口是否需要搜索验证（SiteDetector Phase 2.5）
     * 空列表能通但关键词搜索被拦截 → 可能是搜索验证
     */
    public static function needsSearchCaptcha(string $apiHost, string $template, int $timeout): ?array
    {
        $jar = tempnam(sys_get_temp_dir(), 'sd_cap_');

        // ⚠️ 关键：请求 HTML search 页面时不能用 XMLHttpRequest header！
        // 苹果CMS 看到 X-Requested-With: XMLHttpRequest 会跳过验证码拦截直接返回 JSON
        $htmlHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Referer: https://' . $apiHost . '/',
            'Cache-Control: no-cache',
        ];

        // 苹果CMS10 可能有双重拦截：
        //   ① 第一次访问 → 验证码拦截（系统安全验证）
        //   ② 两次请求间隔 < 3秒 → 频率限制（请不要频繁操作）
        // 所以：第一步就检测验证码信号，命中立刻返回，不等第二步

        $basePath = preg_replace('~wd=%u[^&]*&?~i', '', $template);
        $basePath = preg_replace('~[?&]+$~', '', $basePath);
        $first = self::fetchWithHeaders($basePath, $timeout, $htmlHeaders, $jar);

        $captchaSignals = [
            '系统安全验证',       // 苹果CMS10 标准验证页标题
            'mac_verify',         // 验证码输入框 class
            'verify_check',       // 验证接口路径
            '请输入验证码',
            '人机验证',
        ];

        // 第一步命中验证码 → 确认需要过验证码
        $low1 = strtolower((string)$first['body']);
        foreach ($captchaSignals as $sig) {
            if (strpos($low1, strtolower($sig)) !== false) {
                return [
                    'need_captcha' => true,
                    'captcha_img'  => self::extractCaptchaUrl((string)$first['body'], $apiHost),
                    'api_host'     => $apiHost,
                    'cookie_jar'   => $jar,
                    'signal'       => $sig,
                ];
            }
        }

        // 频率限制信号（探测太快可能被这个拦）：跳过，不是验证码
        if (strpos($low1, '请不要频繁操作') !== false) {
            return null; // 频率限制，不是验证码
        }

        // 等 3.5 秒（苹果CMS 默认 3 秒间隔），再用关键词测一次
        usleep(3500000);
        $kwUrl = str_replace('wd=%u', 'wd=' . urlencode('斗罗大陆'), $template);
        $second = self::fetchWithHeaders($kwUrl, $timeout, $htmlHeaders, $jar);

        $low2 = strtolower((string)$second['body']);
        foreach ($captchaSignals as $sig) {
            if (strpos($low2, strtolower($sig)) !== false) {
                return [
                    'need_captcha' => true,
                    'captcha_img'  => self::extractCaptchaUrl((string)$second['body'], $apiHost),
                    'api_host'     => $apiHost,
                    'cookie_jar'   => $jar,
                    'signal'       => $sig,
                ];
            }
        }

        return null;
    }
    /* ========= Phase 1.5: 会话预热 + 空列表 vod_id 基线 ========= */

    /** 拉空列表，拿到 cookie + vod_id 基线集合（用于后续 Diff） */
    protected static function warmup(string $template, int $timeout, string $apiHost): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'sd_warmup_');
        $listUrl = str_replace('wd=%u', 'limit=10', $template);

        $headers = self::browserHeaders($apiHost);
        $resp = self::fetchRetry($listUrl, $timeout, $headers, $cookieFile);

        if ($resp['err'] !== '') {
            return ['ok' => false, 'msg' => '空列表请求失败：' . $resp['err']];
        }

        $blocked = self::detectBlocked($resp);
        if ($blocked !== null) {
            return ['ok' => false, 'msg' => $blocked];
        }

        $data = self::parseJson((string)$resp['body']);
        if ($data === null) {
            return ['ok' => false, 'msg' => '空列表返回非 JSON'];
        }
        if ((int)($data['code'] ?? 0) !== 1 || !is_array($data['list'] ?? null)) {
            return ['ok' => false, 'msg' => '空列表 code=' . ($data['code'] ?? '?') . ' 无 list'];
        }

        $item = self::firstItem($data);
        if ($item === null) {
            return ['ok' => false, 'msg' => '空列表 list[] 为空'];
        }

        $realUrl = self::firstRealUrl($item);
        if ($realUrl === '') {
            return ['ok' => false, 'msg' => '空列表有剧名但无可播放 vod_play_url'];
        }

        $emptyIds = self::extractVodIds($data);

        return [
            'ok'          => true,
            'cookie_file' => $cookieFile,
            'empty_ids'   => $emptyIds,
            'sample_name' => (string)$item['vod_name'],
            'sample_url'  => $realUrl,
        ];
    }

    /* ========= Phase 2: 智能关键词搜索探测 ========= */

    /**
     * 多策略探测关键词搜索能力
     *
     * 策略（按优先级）：
     *   S1: ac=videolist + wd=关键词       （标准苹果CMS 10）
     *   S2: ac=search + wd=关键词           （部分站用独立搜索端点）
     *   S3: ac=videolist + keyword=关键词   （兼容别名）
     *   S4: ac=videolist + q=关键词         （通用搜索参数）
     *   S5: ac=videolist + name=关键词      （通用搜索参数）
     *
     * 每个策略返回的 vod_id 集合与 Phase 1.5 的空列表做 Diff：
     *   - 重合度 < 70% → 搜索真的生效了 ✓（返回结果明显不同）
     *   - 重合度 ≥ 70% → 参数被忽略了或搜索太弱 ✗
     */
    protected static function probeKeywordSmart(string $template, int $timeout, string $apiHost, string $cookieFile, array $emptyIds): array
    {
        $probeTimeout = max(5, min($timeout, 10));
        $headers = self::browserHeaders($apiHost);
        $strategies = [
            'S1 wd=param'       => function($tpl, $kw){ return str_replace('wd=%u', 'wd=' . urlencode($kw), $tpl); },
            'S2 ac=search'      => function($tpl, $kw){ return preg_replace('/ac=videolist/', 'ac=search', str_replace('wd=%u', 'wd=' . urlencode($kw), $tpl)); },
            'S3 keyword=param'  => function($tpl, $kw){ return str_replace('wd=%u', 'keyword=' . urlencode($kw), $tpl); },
            'S4 q=param'        => function($tpl, $kw){ return str_replace('wd=%u', 'q=' . urlencode($kw), $tpl); },
            'S5 name=param'     => function($tpl, $kw){ return str_replace('wd=%u', 'name=' . urlencode($kw), $tpl); },
        ];

        $successHit = null;
        $failReasons = [];
        $bestOverlap = 1.0;
        $bestSample  = '';

        foreach (['斗罗大陆', '庆余年'] as $kw) {
            // 并发发 5 个策略请求（用 curl_multi）
            $mh = curl_multi_init();
            $handles = [];
            foreach ($strategies as $sname => $builder) {
                $url = $builder($template, $kw);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $probeTimeout,
                    CURLOPT_CONNECTTIMEOUT => min(5, $probeTimeout),
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122',
                    CURLOPT_HTTPHEADER => $headers,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$sname] = $ch;
            }
            // 执行并发
            do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
            // 收集结果 + 立即判定
            foreach ($handles as $sname => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err  = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                // 快速跳过失败
                if ($err !== '' || $code !== 200) { $failReasons[] = "{$sname} HTTP {$code}"; continue; }
                $low = strtolower((string)$body);
                if (strpos($low, '暂不支持搜索') !== false || strpos($low, 'not support') !== false || strpos($low, 'search disabled') !== false) {
                    $failReasons[] = "{$sname} 暂不支持搜索"; continue;
                }
                $data = self::parseJson((string)$body);
                if ($data === null || (int)($data['code'] ?? 0) !== 1 || !is_array($data['list'] ?? null)) {
                    $failReasons[] = "{$sname} code=" . ($data['code'] ?? '?'); continue;
                }
                $searchIds = self::extractVodIds($data);
                $overlap = self::overlapRatio($searchIds, $emptyIds);
                $total = (int)($data['total'] ?? 0);
                $hasResult = ($total > 0 || count($searchIds) > 0);
                $diffEnough = $overlap < 0.70;
                if ($hasResult && $diffEnough) {
                    $successHit = ['strategy'=>$sname,'keyword'=>$kw,'overlap'=>$overlap,'count'=>count($searchIds),'total'=>$total];
                    if ($bestSample === '' && !empty($data['list'][0]['vod_name'])) $bestSample = (string)$data['list'][0]['vod_name'];
                    break 2; // 🎯 找到！跳出两层
                }
                if ($overlap < $bestOverlap) $bestOverlap = $overlap;
            }
            curl_multi_close($mh);
            // 如果这个关键词所有策略都返回"暂不支持搜索"，后续关键词也不会好到哪儿去，直接 break
            if (count(array_filter($failReasons, fn($r)=>strpos($r,'暂不支持搜索')!==false)) >= count($strategies)) break;
        }

        if ($successHit !== null) {
            return ['supported'=>true,'strategy'=>$successHit['strategy'],'overlap'=>$successHit['overlap'],
                'msg'=>"✅ 关键词搜索生效！策略：{$successHit['strategy']}，「{$successHit['keyword']}」返回约 {$successHit['count']} 条，与空列表重合度仅 " . round($successHit['overlap']*100,1) . "%",
                'sample_name'=>$bestSample];
        }
        return ['supported'=>false,'strategy'=>'none','overlap'=>$bestOverlap,
            'msg'=>implode('；', array_unique($failReasons)) ?: '所有搜索策略均未生效（最低重合度 ' . round($bestOverlap*100,1) . '%）','sample_name'=>''];
    }

    /* ========= 对外入口 ========= */

    /**
     * 完整检测入口（v3 三阶段）
     */
    public static function detect(string $rawUrl, int $timeout = 15): array
    {
        $rawUrl = trim($rawUrl ?? '');
        if ($rawUrl === '') return self::failResult('请输入采集接口地址', 0);
        if (strpos($rawUrl, 'http') !== 0) $rawUrl = 'http://' . $rawUrl;
        $host = (string)parse_url($rawUrl, PHP_URL_HOST);
        if ($host === '') return self::failResult('链接格式非法', 0);

        $template = self::buildTemplate($rawUrl);
        $name     = self::makeName($host);

        // ============== Phase 1 + 1.5: 会话预热 + 空列表探测 ==============
        $warmup = self::warmup($template, $timeout, $host);
        if (!$warmup['ok']) {
            return self::failResult('❌ Phase 1 失败：' . $warmup['msg'], 0);
        }

        // ============== Phase 2: 智能关键词搜索探测 ==============
        $probe = self::probeKeywordSmart(
            $template, $timeout, $host,
            $warmup['cookie_file'], $warmup['empty_ids']
        );

        if ($probe['supported']) {
            // ✅ 真·可用接口
            return [
                'ok'         => true,
                'name'       => $name,
                'template'   => $template,
                'type'       => '苹果CMS10 采集接口',
                'sample'     => ['name' => $warmup['sample_name'], 'url' => $warmup['sample_url']],
                'code'       => 200,
                'msg'        => '✅ 接口可达 · 真实播放地址 OK · 关键词搜索正常',
                'searchable' => true,
                'search_probe' => $probe,
                'phase'      => ['phase1' => '✅ 空列表 OK', 'phase1.5' => '✅ 会话已建立', 'phase2' => '✅ ' . $probe['strategy']],
            ];
        }

        // Phase 1 通过但 Phase 2 未通过 —— 可能是：
        //   (a) 接口真不支持搜索（如 WSYZY）
        //   (b) 接口被风控误伤
        //   (c) 探测策略还不够全
        // 我们标记为 ok=true 但 searchable=false，让用户自己决定
        return [
            'ok'         => true,
            'name'       => $name,
            'template'   => $template,
            'type'       => '苹果CMS10 采集接口',
            'sample'     => ['name' => $warmup['sample_name'], 'url' => $warmup['sample_url']],
            'code'       => 200,
            'msg'        => '⚠️ Phase 1 通过（空列表 OK，有真实资源），但 Phase 2 关键词搜索未生效',
            'searchable' => false,
            'search_probe' => $probe,
            'warn'       => true,
            'warn_detail' => $probe['msg'],
            'phase'      => ['phase1' => '✅ 空列表 OK', 'phase1.5' => '✅ 会话已建立', 'phase2' => '❌ ' . substr($probe['msg'], 0, 80)],
        ];
    }
}
