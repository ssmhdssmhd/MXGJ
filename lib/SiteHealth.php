<?php
/**
 * 沫兮官替系统 - 资源站调用频率控制（SiteHealth）
 *
 * 职责：通过「搜索节流 + 心跳探测 + 轮训分批」三项策略，降低对资源站的调用频率，
 *      尽量避免因频繁/密集调用而被资源站屏蔽或禁用。
 *
 *  1) 搜索频率（search_interval）：同一资源站两次「实际网络调用」的最短间隔。
 *     间隔内再次需要该站时直接跳过（依赖搜索结果缓存兜底），从而限制单站 QPS。
 *  2) 心跳频率（heartbeat_interval）：周期性并发探测各资源站可达性，
 *     连续失败 heartbeat_max_fail 次自动禁用，cooldown_seconds 后自动恢复重试。
 *     前端搜索只对「存活且未在冷却期」的资源站发请求。
 *  3) 轮训（rotation_interval + max_sites_per_request）：每隔一个轮训周期推进一次
 *     命中顺序（round-robin），并可限制每次最多并发请求几个站，把压力分散到不同站。
 *
 * 运行状态存于 data/site_health.json（可被自动更新保留，属运行时数据）。
 */

class SiteHealth
{
    /* 状态文件 */
    protected static function file(): string
    {
        return MXGJ_DATA . '/site_health.json';
    }

    /* 站点稳定标识：以模板串去空格小写后的 md5 作为 key */
    protected static function keyFor(array $site): string
    {
        $tpl = (string)($site['template'] ?? ($site['url'] ?? ''));
        return md5(mxgj_lower(trim($tpl)));
    }

    /* 读取状态 */
    protected static function state(): array
    {
        $s = mxgj_read_json(self::file(), ['last_heartbeat' => 0, 'sites' => []]);
        if (!isset($s['last_heartbeat'])) { $s['last_heartbeat'] = 0; }
        if (!isset($s['sites']) || !is_array($s['sites'])) { $s['sites'] = []; }
        return $s;
    }

    /* 写入状态 */
    protected static function save(array $state): void
    {
        @mkdir(MXGJ_DATA, 0755, true);
        mxgj_write_json(self::file(), $state);
    }

    /* 站点默认健康值 */
    protected static function defaultHealth(): array
    {
        return [
            'enabled'     => true, // 是否可用
            'fail'        => 0,    // 连续失败次数
            'disabled_at' => 0,    // 被禁用时刻
            'last_ok'     => 0,    // 最近成功
            'last_fail'   => 0,    // 最近失败
            'last_active' => 0,    // 最近一次被实际调用(用于节流)
            'ms'          => 0,    // 最近平均响应毫秒
        ];
    }

    /* 频率控制参数（合并默认值） */
    protected static function control(): array
    {
        $sc = mxgj_settings()['site_control'] ?? [];
        return array_merge([
            'search_interval'   => 10,
            'heartbeat_enable'  => true,
            'heartbeat_interval'=> 300,
            'heartbeat_timeout' => 5,
            'heartbeat_max_fail'=> 3,
            'cooldown_seconds'  => 600,
            'rotation_enable'   => true,
            'rotation_interval'=> 300,
            'max_sites_per_request' => 0,
        ], is_array($sc) ? $sc : []);
    }

    /**
     * 返回本次搜索实际应请求的资源站列表（心跳+节流+轮训后）
     *
     * @param array $sites 全部配置的资源站
     * @param int   $timeout 搜索超时（透传给心跳判断，仅控制探测时延的一部分）
     * @return array 元素为 ['site'=>原始站点,'key'=>站点key] 的列表（已按轮训排序、可按限量截取）
     */
    public static function usable(array $sites, int $timeout): array
    {
        $ctl = self::control();

        // 必要时先做一次心跳探测（懒执行，受 heartbeat_interval 控制）
        if (!empty($ctl['heartbeat_enable'])) {
            self::heartbeatIfDue($sites, $ctl);
        }

        $state = self::state();
        $now   = time();
        $pool  = [];

        foreach ($sites as $site) {
            $k = self::keyFor($site);
            $h = $state['sites'][$k] ?? self::defaultHealth();
            unset($state['sites'][$k]);

            // 冷却期禁用：未到恢复时间则跳过
            if (empty($h['enabled']) && $now - (int)$h['disabled_at'] < (int)$ctl['cooldown_seconds']) {
                continue;
            }
            // 搜索频率节流：同一站间隔未到则跳过（依赖结果缓存兜底）
            $si = (int)$ctl['search_interval'];
            if ($si > 0 && $now - (int)$h['last_active'] < $si) {
                continue;
            }

            $h['last_active'] = $now;
            $state['sites'][$k] = $h;
            $pool[] = ['site' => $site, 'key' => $k, 'health' => $h];
        }

        // 轮训：按轮训周期推进命中顺序（round-robin）
        if (!empty($ctl['rotation_enable']) && count($pool) > 1) {
            $shift = (int)$ctl['rotation_interval'] > 0
                ? intdiv($now, (int)$ctl['rotation_interval']) % count($pool)
                : 0;
            if ($shift > 0) {
                $pool = array_merge(array_slice($pool, $shift), array_slice($pool, 0, $shift));
            }
        }

        // 限量：每次最多并发请求几个站（负载分散到不同站）
        $max = (int)$ctl['max_sites_per_request'];
        if ($max > 0) {
            $pool = array_slice($pool, 0, $max);
        }

        self::save($state);
        return $pool;
    }

    /**
     * 上报某个站的本次请求结果（成功/失败）
     */
    public static function record(bool $ok, string $key, int $ms = 0): void
    {
        $state = self::state();
        $ctl   = self::control();
        $h     = $state['sites'][$key] ?? self::defaultHealth();
        $now   = time();

        if ($ok) {
            $h['enabled']     = true;
            $h['fail']        = 0;
            $h['last_ok']     = $now;
            if ($ms > 0) { $h['ms'] = (int)($h['ms'] ? ($h['ms'] * 3 + $ms) / 4 : $ms); }
        } else {
            $h['last_fail'] = $now;
            $h['fail']      = (int)$h['fail'] + 1;
            if ((int)$h['fail'] >= (int)$ctl['heartbeat_max_fail']) {
                $h['enabled']     = false;
                $h['disabled_at'] = $now;
            }
        }
        $state['sites'][$key] = $h;
        self::save($state);
    }

    /* 立即并发探测全部资源站并写回状态，返回可展示的汇总 */
    public static function probeAllNow(): array
    {
        $ctl   = self::control();
        $sites = mxgj_sites();
        if ($sites === []) {
            return ['ok' => false, 'msg' => '后台未配置资源站'];
        }
        $probes = self::probe($sites, (int)$ctl['heartbeat_timeout']);
        $state  = self::state();
        $now    = time();
        $rows   = [];
        foreach ($sites as $site) {
            $k = self::keyFor($site);
            $h = $state['sites'][$k] ?? self::defaultHealth();
            $r = $probes[$k] ?? ['ok' => false, 'ms' => 0];
            if (!empty($r['ok'])) {
                $h['enabled'] = true; $h['fail'] = 0; $h['last_ok'] = $now;
            } else {
                $h['last_fail'] = $now; $h['fail'] = (int)$h['fail'] + 1;
                if ((int)$h['fail'] >= (int)$ctl['heartbeat_max_fail']) {
                    $h['enabled'] = false; $h['disabled_at'] = $now;
                }
            }
            if (!empty($r['ms'])) { $h['ms'] = (int)$r['ms']; }
            $state['sites'][$k] = $h;
            $rows[] = [
                'name'    => $site['name'] ?? $site['url'],
                'ok'      => !empty($r['ok']),
                'ms'      => (int)($r['ms'] ?? 0),
                'enabled' => !empty($h['enabled']),
                'fail'    => (int)$h['fail'],
            ];
        }
        $state['last_heartbeat'] = $now;
        self::save($state);
        return ['ok' => true, 'rows' => $rows, 'at' => date('Y-m-d H:i:s'), 'msg' => '心跳检测完成'];
    }

    /* 重置全部资源站健康状态（复用于全部站点） */
    public static function reset(): void
    {
        $state = ['last_heartbeat' => 0, 'sites' => []];
        self::save($state);
    }

    /* 后台展示用：返回每个资源站的健康状态行 */
    public static function healthTable(): array
    {
        $state = self::state();
        $sites = mxgj_sites();
        $rows  = [];
        foreach ($sites as $site) {
            $k    = self::keyFor($site);
            $h    = $state['sites'][$k] ?? self::defaultHealth();
            $rows[] = [
                'name'    => $site['name'] ?? $site['url'],
                'enabled' => !empty($h['enabled']),
                'reachable'=> (empty($h['enabled']) ? ((int)$h['last_ok'] > (int)$h['last_fail']) : true),
                'ms'      => (int)$h['ms'],
                'fail'    => (int)$h['fail'],
                'last_heartbeat' => (int)$h['last_ok'] ?: (int)$h['last_fail'],
            ];
        }
        return $rows;
    }

    /* 懒执行心跳：距上次心跳超过 heartbeat_interval 才探测一次 */
    protected static function heartbeatIfDue(array $sites, array $ctl): void
    {
        $state = self::state();
        $now   = time();
        if ($now - (int)$state['last_heartbeat'] < (int)$ctl['heartbeat_interval']) {
            return;
        }
        // 并发锁：避免高并发下同时探测
        $lock = MXGJ_DATA . '/heartbeat.lock';
        if (is_file($lock) && $now - (int)@file_get_contents($lock) < 10) {
            return;
        }
        @mkdir(MXGJ_DATA, 0755, true);
        @file_put_contents($lock, $now);

        $probes = self::probe($sites, (int)$ctl['heartbeat_timeout']);
        $hstate = self::state(); // 重新加载（可能与上一步略有竞争，可接受）
        foreach ($probes as $k => $r) {
            $h = $hstate['sites'][$k] ?? self::defaultHealth();
            if (!empty($r['ok']) && $r['ok'] === true) {
                $h['enabled'] = true;
                $h['fail']    = 0;
                $h['last_ok'] = $now;
            } else {
                $h['last_fail'] = $now;
                $h['fail']      = (int)$h['fail'] + 1;
                if ((int)$h['fail'] >= (int)$ctl['heartbeat_max_fail']) {
                    $h['enabled']     = false;
                    $h['disabled_at'] = $now;
                }
            }
            if (!empty($r['ms'])) { $h['ms'] = (int)$r['ms']; }
            $hstate['sites'][$k] = $h;
        }
        $hstate['last_heartbeat'] = $now;
        self::save($hstate);
        @unlink($lock);
    }

    /* 并发探测所有站点可达性 */
    protected static function probe(array $sites, int $timeout): array
    {
        if ($timeout <= 0) { $timeout = 5; }
        $handles = [];
        foreach ($sites as $site) {
            $u = self::probeUrl($site);
            if ($u === '') { continue; }
            $ch = self::makeHandle($u, $timeout);
            $handles[] = ['key' => self::keyFor($site), 'ch' => $ch, 't0' => microtime(true)];
        }
        if ($handles === []) { return []; }

        $multi = curl_multi_init();
        foreach ($handles as $h) { curl_multi_add_handle($multi, $h['ch']); }
        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) { curl_multi_select($multi, 0.2); }
        } while ($running > 0 && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $h) {
            $code = curl_getinfo($h['ch'], CURLINFO_RESPONSE_CODE);
            $body = curl_multi_getcontent($h['ch']);
            $err  = curl_error($h['ch']);
            $ms   = (int)((microtime(true) - $h['t0']) * 1000);
            curl_multi_remove_handle($multi, $h['ch']);
            @curl_close($h['ch']);
            $ok = ($err === '' && $code >= 200 && $code < 300 && $body !== false && strlen($body) > 0);
            $out[$h['key']] = ['ok' => $ok, 'ms' => $ms];
        }
        curl_multi_close($multi);
        return $out;
    }

    /* 由站点模板生成一个轻量探测地址（去掉搜索词，仅保留列表接口） */
    protected static function probeUrl(array $site): string
    {
        $tpl = (string)($site['template'] ?? ($site['url'] ?? ''));
        if ($tpl === '') { return ''; }
        $tpl = str_replace(['%u', '%U', '%t', '%T'], '', $tpl); // 去掉剧名占位（wd=）
        $tpl = str_replace(['%s', '%S'], '', $tpl);
        $tpl = str_replace('%p', '1', $tpl);
        return $tpl;
    }

    protected static function makeHandle(string $url, int $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'MXGJ-Health/1.0',
        ]);
        return $ch;
    }
}