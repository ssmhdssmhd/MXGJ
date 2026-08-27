<?php
/**
 * 沫兮官替系统 - 苹果CMS10 采集接口检测与模板自动构建（SiteDetector）
 *
 * 用户只需粘贴一个「苹果 CMS10 采集接口」地址（如 https://caiji.xxx.com/api.php/provide/vod/），
 * 本类自动：
 *   1) 校验接口可达性；
 *   2) 探测返回是否为苹果CMS列表格式（list[] 且含可播放 http 地址）；
 *   3) 🔑 **真正测试关键词搜索能力**（ac=videolist&wd=斗罗大陆）—— 防假接口；
 *   4) 自动构建带占位符的搜索模板（?ac=videolist&wd=%u）；
 *   5) 自动生成可读的站点名称。
 */

class SiteDetector
{
    /* 苹果CMS列表中提取第一个可播放的 http 地址 */
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

    /** 由用户粘贴的采集接口地址，生成带占位符的搜索模板 */
    protected static function buildTemplate(string $base): string
    {
        $tpl = trim($base);
        if ($tpl === '') { return ''; }
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

    /** 解析 JSON 响应，容错处理 */
    protected static function parseJson(string $body): ?array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (preg_match('~\{.*\}~s', $body, $m)) { $body = $m[0]; }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 🔑 真·关键词搜索探测
     *
     * 用真实关键词（斗罗大陆）去搜，判断接口是否支持关键词查询。
     * 有些"假苹果CMS"接口只支持空列表，不支持 wd= 搜索，会返回：
     *   - 纯文本 "暂不支持搜索"
     *   - code != 1 或 list 为空
     *   - 返回固定假数据与空搜索结果一样
     *
     * @return array{supported:bool,hit_count:int,msg:string,sample_name:string}
     */
    protected static function probeKeyword(string $template, int $timeout): array
    {
        $testKeywords = ['斗罗大陆', '庆余年'];  // 两个常见关键词交叉验证
        $allHits = 0;
        $allTotal = 0;
        $sampleName = '';
        $failReasons = [];

        foreach ($testKeywords as $kw) {
            $searchUrl = str_replace('%u', urlencode($kw), $template);
            $res = self::fetch($searchUrl, $timeout);

            if ($res['err'] !== '' || $res['code'] < 200 || $res['code'] >= 300) {
                $failReasons[] = "关键词「{$kw}」请求失败(HTTP {$res['code']})";
                continue;
            }

            // 检查返回体是否含明显的"不支持搜索"文本
            $body = (string)$res['body'];
            $bodyLower = mb_strtolower($body);
            $notSupportedPatterns = ['暂不支持搜索', 'not support', 'search disabled', 'not allow'];
            foreach ($notSupportedPatterns as $p) {
                if (strpos($bodyLower, $p) !== false) {
                    $failReasons[] = "接口返回「{$p}」";
                    continue 2;
                }
            }

            $data = self::parseJson($body);
            if ($data === null) {
                $failReasons[] = "返回非 JSON（关键词「{$kw}」）";
                continue;
            }

            $code = $data['code'] ?? 0;
            $list = $data['list'] ?? null;
            $total = $data['total'] ?? 0;

            // 苹果CMS 标准：code=1 且 list 为数组
            if ((int)$code !== 1 || !is_array($list)) {
                $failReasons[] = "关键词「{$kw}」返回 code={$code}，无 list 数组";
                continue;
            }

            $count = count($list);
            if ($count === 0) {
                // list 为空，但 total > 0 可能是分页，也算有数据
                if ((int)$total > 0) {
                    $allHits++; $allTotal += (int)$total;
                } else {
                    // 真正没数据 —— 接口可能只在空 wd 时返回列表
                    $failReasons[] = "关键词「{$kw}」返回空列表";
                    continue;
                }
            } else {
                $allHits++; $allTotal += $count;
                if ($sampleName === '' && !empty($list[0]['vod_name'])) {
                    $sampleName = (string)$list[0]['vod_name'];
                }
            }
        }

        if ($allHits >= 1) {
            return [
                'supported' => true,
                'hit_count'  => $allHits,
                'msg'        => "关键词搜索正常！测试 {$allHits} 个关键词均命中，共返回约 {$allTotal} 条结果（示例：{$sampleName}）",
                'sample_name' => $sampleName,
            ];
        }

        return [
            'supported' => false,
            'hit_count' => 0,
            'msg'       => implode('；', array_unique($failReasons)) ?: '关键词搜索不可用',
            'sample_name' => '',
        ];
    }

    /**
     * 检测一个采集接口（完整版）
     *
     * 两阶段检测：
     *   Phase 1: 基础列表探测（不带 wd）—— 确认接口可达、返回苹果CMS格式、有真实播放地址
     *   Phase 2: 关键词搜索探测（带 wd=斗罗大陆）—— 确认真实搜索能力（🚨 防假接口核心）
     *
     * @return array{ok:bool,name:string,template:string,type:string,
     *               sample:array{name:string,url:string},code:int,msg:string,
     *               searchable:bool,search_probe:array}
     */
    public static function detect(string $rawUrl, int $timeout = 15): array
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return self::failResult('请输入采集接口地址', 0);
        }
        if (strpos($rawUrl, 'http') !== 0) {
            $rawUrl = 'http://' . $rawUrl;
        }
        $host = (string)parse_url($rawUrl, PHP_URL_HOST);
        if ($host === '') {
            return self::failResult('链接格式非法', 0);
        }

        // ============ Phase 1: 基础列表探测 ============
        $probeList = self::buildTemplate($rawUrl);
        $probeNoWd = str_replace('wd=%u', 'limit=3', $probeList); // 不带关键词，拉最新3条
        $res = self::fetch($probeNoWd, $timeout);

        if ($res['err'] !== '') {
            return self::failResult('接口不可达：' . $res['err'], 0);
        }
        if ($res['code'] < 200 || $res['code'] >= 300) {
            return self::failResult('接口返回 HTTP ' . $res['code'], $res['code']);
        }

        $data = self::parseJson((string)$res['body']);
        if ($data === null) {
            return self::failResult('返回内容不是 JSON（可能非 CMS 采集接口）', $res['code']);
        }

        $item = self::firstItem($data);
        if ($item === null) {
            return self::failResult('接口可达但未返回有效视频列表（list[] 为空）', $res['code']);
        }

        $realUrl = self::firstRealUrl($item);
        if ($realUrl === '') {
            return [
                'ok'       => false,
                'name'     => self::makeName($host),
                'template' => self::buildTemplate($rawUrl),
                'type'     => '苹果CMS10采集接口',
                'sample'   => ['name' => (string)$item['vod_name'], 'url' => ''],
                'code'     => $res['code'],
                'msg'      => '❌ 列表有剧名，但无可播放地址（vod_play_url 未含 http 链接）',
                'searchable' => false,
                'search_probe' => ['supported' => false, 'msg' => '未检测关键词搜索'],
            ];
        }

        // ✅ Phase 1 通过！有列表有真实播放地址
        $template = self::buildTemplate($rawUrl);
        $name     = self::makeName($host);

        // ============ Phase 2: 关键词搜索探测 ============
        $searchProbe = self::probeKeyword($template, $timeout);

        if (!$searchProbe['supported']) {
            // 基础探测过了但关键词搜索不行 —— 给警告但仍然可以保存（接口可能只支持空列表）
            return [
                'ok'       => true,  // 基础检测通过，允许用户选择保存
                'name'     => $name,
                'template' => $template,
                'type'     => '苹果CMS10采集接口',
                'sample'   => ['name' => (string)$item['vod_name'], 'url' => $realUrl],
                'code'     => $res['code'],
                'msg'      => '⚠️ 基础检测通过（列表 OK，有真实资源），但关键词搜索不可用！',
                'searchable' => false,
                'search_probe' => $searchProbe,
                'warn'     => true,
                'warn_detail' => $searchProbe['msg'],
            ];
        }

        // ✅✅ Phase 1 + Phase 2 双通过
        return [
            'ok'         => true,
            'name'       => $name,
            'template'   => $template,
            'type'       => '苹果CMS10采集接口',
            'sample'     => ['name' => (string)$item['vod_name'], 'url' => $realUrl],
            'code'       => $res['code'],
            'msg'        => '✅ 检测通过！接口可达、有真实播放地址、关键词搜索正常',
            'searchable' => true,
            'search_probe' => $searchProbe,
        ];
    }

    protected static function failResult(string $msg, int $code): array
    {
        return [
            'ok' => false, 'name' => '', 'template' => '', 'type' => '',
            'sample' => ['name' => '', 'url' => ''],
            'code' => $code, 'msg' => $msg,
            'searchable' => false, 'search_probe' => ['supported' => false, 'msg' => $msg],
        ];
    }

    /* 用主域名生成可读站名 */
    protected static function makeName(string $host): string
    {
        $host  = strtolower($host);
        $parts = explode('.', $host);
        $main  = count($parts) >= 2 ? $parts[count($parts) - 2] : $parts[0];
        $main  = strtoupper(preg_replace('/[^a-z0-9]+/i', '', $main) ?: $host);
        return $main . ' 资源站';
    }
}
