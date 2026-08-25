<?php
/**
 * 沫兮官替系统 - 定时自动采集映射（cron）
 *
 * 作用：周期性自动访问「官方链接种子」与「资源站采集接口」，自动识别剧名+集数，
 *      并将缺失的映射自动写入 config/mapping.json，省去人工一条条添加。
 *
 * 触发方法（任选其一）：
 *   1) Linux 定时任务（crontab），例如每小时一次（cron 第5位为星号）：
 *      0 * * * *  php /path/to/mxgj/cron/mapping.php key=你的定时密钥 quiet
 *      （或 curl -s "http://你的域名/cron/mapping.php?key=定时密钥" >/dev/null）
 *   2) 手动访问测试：
 *      http://你的域名/cron/mapping.php?key=定时密钥&dry=1
 *      - dry=1 仅预览本次将要新增的映射，不写库
 *      - quiet  不在命令行显示明细（适合 crontab）
 *
 * 鉴权：key 依次取 config/settings.json -> cron.key（未设置则用 updater_key，再回退 admin_password）。
 * 详细使用说明见「后台 -> 帮助 -> 定时访问功能」。
 */

require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
$isCli = PHP_SAPI === 'cli';

// 参数：通过命令行 key=... 或 URL ?key=...
$params = [];
if ($isCli) {
    parse_str(implode('&', array_slice($argv, 1)), $params);
} else {
    $params = $_GET;
}
$key  = trim($params['key'] ?? '');
$dry  = !empty($params['dry']);
$loud = empty($params['quiet']);

$st = mxgj_settings();
$cronCfg = is_array($st['cron'] ?? null) ? $st['cron'] : [];
$cronKey = isset($cronCfg['key']) && trim((string)$cronCfg['key']) !== ''
    ? trim((string)$cronCfg['key'])
    : (isset($st['updater_key']) && trim((string)$st['updater_key']) !== ''
        ? trim((string)$st['updater_key'])
        : (string)($st['admin_password'] ?? ''));

// 鉴权
if (($cronKey !== '' && $key !== $cronKey) || ($cronKey === '' && $key === '')) {
    out(($dry ? '[dry] ' : '') . "错误：定时密钥不合法（请在 URL/crontab 携带 key=定时密钥）\n");
    exit(1);
}

$dry  = $dry || !empty($params['dry']);
$report = CronMapping::run($dry);
$dry  = $report['dry'] ?? $dry;

// 命令行/页面输出
out("\n===== 沫兮官替系统 - 定时映射采集 " . ($dry ? '(dry 预览)' : '') . " =====\n");
out('触发时间: ' . date('Y-m-d H:i:s') . "\n\n");
foreach ($report['steps'] as $i => $step) {
    out(($i + 1) . ") {$step}\n");
}
out("\n汇总: 种子链接=" . $report['seed_total'] . " 新增映射=" . $report['seed_added']
    . " 已存在=" . $report['seed_skip'] . " 识别失败=" . $report['seed_fail']
    . " 资源站盘点=" . $report['stock_sites'] . "\n");
if (!empty($report['rollback'])) {
    out("已回滚新增映射: " . implode(', ', $report['rollback']) . "\n");
}
out(($report['ok'] ? '✔ ' : '✘ ') . $report['msg'] . "\n");

// 追加运行日志
appendLog($report, $dry);

exit($report['ok'] ? 0 : 1);

// ---------- 辅助 ----------

function out(string $s): void
{
    if (PHP_SAPI === 'cli') {
        echo $s;
    } else {
        echo nl2br(htmlspecialchars($s));
    }
}

function appendLog(array $report, bool $dry): void
{
    $logFile = MXGJ_DATA . '/cron_mapping.log';
    @mkdir(MXGJ_DATA, 0755, true);
    $lines = [];
    if (is_file($logFile)) {
        $lines = array_filter(array_map('trim', @file($logFile) ?: []));
    }
    $entry = json_encode([
        'time'   => date('Y-m-d H:i:s'),
        'dry'    => $dry,
        'ok'     => $report['ok'],
        'added'  => $report['seed_added'],
        'skip'   => $report['seed_skip'],
        'fail'   => $report['seed_fail'],
        'seed_total' => $report['seed_total'],
        'steps'  => $report['steps'],
    ], JSON_UNESCAPED_UNICODE);
    array_unshift($lines, $entry);
    $lines = array_slice($lines, 0, 200); // 最多保留 200 条
    @file_put_contents($logFile, implode("\n", $lines) . "\n");
}

/**
 * 定时映射采集主逻辑（独立类便于复用/测试）
 */
class CronMapping
{
    /**
     * 执行一次采集
     */
    public static function run(bool $dry = false): array
    {
        $steps  = [];
        $st     = mxgj_settings();
        $cronCfg = is_array($st['cron'] ?? null) ? $st['cron'] : [];
        $timeout = (int)($cronCfg['timeout'] ?? 20);
        if ($timeout <= 0) { $timeout = 20; }

        $added = 0; $skip = 0; $fail = 0;
        $rollback = [];

        /* ---------- Step A：官方种子链接 -> 自动写映射 ---------- */
        $seeds = $cronCfg['seed_links'] ?? [];
        if (!is_array($seeds)) { $seeds = []; }
        $seeds = array_values(array_filter(array_map('trim', $seeds), fn($s) => $s !== ''));

        if ($seeds === []) {
            $steps[] = '未配置官方种子链接（settings.json -> cron.seed_links 为空）';
        } else {
            $steps[] = '官方种子链接 ' . count($seeds) . ' 条，逐个解析/抓取：';
            foreach ($seeds as $seed) {
                $parsed = LinkParser::parse($seed);
                $mapping = mxgj_read_json(MXGJ_CONFIG . '/mapping.json', []);
                $epMap = isset($mapping['episode']) && is_array($mapping['episode']) ? $mapping['episode'] : [];
                $key = $parsed['vid'] !== ''
                    ? 'vid:' . $parsed['vid']
                    : ($parsed['cid'] !== '' ? 'cid:' . $parsed['cid'] : '');

                // 已有映射则跳过（保护人工配置）
                if ($key !== '' && isset($epMap[$key]) && !empty($epMap[$key]['name'])) {
                    $skip++;
                    $steps[] = '  已映射，跳过  ' . $key . ' -> ' . $epMap[$key]['name']
                        . ($dry ? '' : '（含集数）');
                    continue;
                }

                $res = PageResolver::resolve($seed, 1, $timeout);
                if ($res['title'] === '' || $key === '') {
                    $fail++;
                    $steps[] = '  识别失败  ' . $seed . '（key=' . ($key ?: '空') . '）';
                    continue;
                }

                if (!$dry) {
                    $ok = mxgj_auto_mapping($parsed, $res['title'], (int)$res['episode']);
                    if ($ok) { $added++; $rollback[] = $key; }
                } else {
                    $added++;
                }
                $steps[] = ($dry ? '[dry] 将新增 ' : '已新增 ') . $key . ' -> '
                    . $res['title'] . ' 第' . $res['episode'] . '集';
            }
        }

        /* ---------- Step B：资源站库存盘点（自动记录有哪些剧可选） ---------- */
        $stockSites = 0;
        if (!empty($cronCfg['scan_sites'])) {
            $list = self::scanResourceStations($timeout, $steps, $stockSites);
            if (!$dry && $list !== []) {
                self::mergeStock($list);
            }
        }

        return [
            'ok' => ($fail > 0) ? true : true, // 整体视为成功，失败明细单独统计
            'dry' => $dry,
            'seed_total' => count($seeds),
            'seed_added' => $added,
            'seed_skip'  => $skip,
            'seed_fail'  => $fail,
            'stock_sites'=> $stockSites,
            'rollback'   => $dry ? [] : $rollback,
            'steps'      => $steps,
            'msg' => "采集完成（新增 {$added} / 跳过 {$skip} / 失败 {$fail}）",
        ];
    }

    /**
     * 并发拉取各资源站最新采集列表，汇总「剧名 -> 可用站点 + 集数范围」
     */
    protected static function scanResourceStations(int $timeout, array &$steps, int &$siteCount): array
    {
        $sites   = mxgj_sites();
        if ($sites === []) {
            $steps[] = '资源站盘点：后台未配置资源站，跳过';
            return [];
        }
        $st     = mxgj_settings();
        $cronCfg = $st['cron'] ?? [];
        $pages  = (int)($cronCfg['scan_pages'] ?? 1);
        if ($pages < 1) { $pages = 1; }

        $handles = [];
        foreach ($sites as $site) {
            // 只采集苹果CMS 采集接口类资源站（含 ac=videolist 的模板）
            $tpl = $site['template'] ?? ($site['url'] ?? '');
            if (strpos((string)$tpl, 'ac=videolist') === false && strpos((string)$tpl, 'provide/vod') === false) {
                continue;
            }
            $u = self::listUrl($site, $pages);
            if ($u === '') { continue; }
            $handles[] = ['ch' => self::makeHandle($u, $timeout), 'site' => $site];
        }
        if ($handles === []) { return []; }

        $multi = curl_multi_init();
        foreach ($handles as $h) { curl_multi_add_handle($multi, $h['ch']); }
        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) { curl_multi_select($multi, 0.3); }
        } while ($running > 0 && $status === CURLM_OK);

        $aggregate = [];
        $siteCount = 0;
        foreach ($handles as $h) {
            $body = curl_multi_getcontent($h['ch']);
            $err  = curl_error($h['ch']);
            curl_multi_remove_handle($multi, $h['ch']);
            @curl_close($h['ch']);
            if ($err !== '' || $body === false || $body === '') { continue; }
            $json = self::stripJsonP($body);
            $data = json_decode($json, true);
            if (!is_array($data) || !isset($data['list']) || !is_array($data['list'])) { continue; }
            $siteName = $h['site']['name'] ?? $h['site']['url'];
            $siteCount++;
            foreach ($data['list'] as $item) {
                $name = trim((string)($item['vod_name'] ?? ''));
                if ($name === '') { continue; }
                $agg = &$aggregate[$name];
                if (!isset($agg)) { $agg = ['sites' => [], 'eps' => []]; }
                $agg['sites'][$siteName] = true;
                $eps = self::episodeCount((string)($item['vod_play_url'] ?? ''), (string)($item['vod_play_note'] ?? ''));
                if ($eps > 0) { $agg['eps'][$eps] = true; }
                unset($agg);
            }
        }
        curl_multi_close($multi);

        $steps[] = '资源站盘点：共获取 ' . $siteCount . ' 个资源站的采集列表，整理出 '
            . count($aggregate) . ' 部在库剧名';
        return $aggregate;
    }

    /**
     * 构造采集列表地址（ac=videolist，不携带 wd）
     */
    protected static function listUrl(array $site, int $pages): string
    {
        $tpl = $site['template'] ?? ($site['url'] ?? '');
        // 去掉可能存在的 wd=.. 搜索参数（盘点用全量列表）
        $tpl = preg_replace('~([?&])wd=[^&]*~i', '$1', $tpl);
        // 确保是 ac=videolist 的列表页
        if (strpos($tpl, 'ac=') === false) {
            $tpl .= (strpos($tpl, '?') === false ? '?' : '&') . 'ac=videolist';
        }
        if (strpos($tpl, 'h=') === false) {
            $tpl .= (strpos($tpl, '?') === false ? '?' : '&') . 'h=24&pg=1';
        }
        return $tpl;
    }

    /**
     * 统计 vod_play_url 内集数（第N集$地址#... 与 第01集）
     */
    protected static function episodeCount(string $playUrl, string $note = ''): int
    {
        $n = 0;
        if (strpos($playUrl, '#') !== false) {
            foreach (explode('#', $playUrl) as $seg) {
                if (preg_match('~第?\s*(\d+)\s*集~u', $seg, $m)) {
                    $n = max($n, (int)$m[1]);
                }
            }
            if ($n > 0) { return $n; }
        }
        // 播放备注「更新至X集」兜底
        if (preg_match('~(\d{1,4})\s*集~u', $note, $m)) {
            return (int)$m[1];
        }
        return $n;
    }

    /**
     * 把资源站盘点结果合并进 mapping.json 的 stock（剧名在库情况）
     */
    protected static function mergeStock(array $aggregate): void
    {
        $file = MXGJ_CONFIG . '/mapping.json';
        $map  = mxgj_read_json($file, []);
        if (!isset($map['stock']) || !is_array($map['stock'])) { $map['stock'] = []; }
        foreach ($aggregate as $name => $info) {
            $map['stock'][$name] = [
                'sites' => array_keys($info['sites']) ?: [],
                'eps'   => array_keys($info['eps']) ?: [],
            ];
        }
        // 控制体积：最多保留 2000 部
        $map['stock'] = array_slice($map['stock'], 0, 2000, true);
        mxgj_write_json($file, $map);
    }

    protected static function stripJsonP(string $body): string
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if (preg_match('~\{.*\}~s', $body, $m)) { return $m[0]; }
        return $body;
    }

    protected static function makeHandle(string $url, int $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0',
        ]);
        return $ch;
    }
}