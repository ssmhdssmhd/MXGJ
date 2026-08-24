<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 资源站多线程搜索器。
 * 使用 curl_multi 并发请求所有后台资源站（苹果CMS/海洋CMS provide/vod 接口），
 * 匹配剧名与集数后返回可直链播放地址（m3u8/mp4）。
 */
class ResourceSearcher
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    public static function buildSearchUrl(array $site, string $keyword): string
    {
        $api = rtrim((string)($site['api'] ?? ''), '/');
        return $api . '?ac=detail&wd=' . urlencode($keyword);
    }

    /**
     * 解析播放列表字符串："第01集$https://a.m3u8#第02集$https://b.m3u8"
     * 兼容无集数标签、按顺序编号的情况。
     *
     * @return array<int,array{label:string,ep:int,url:string}>
     */
    public static function parsePlayList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $items = array_filter(array_map('trim', explode('#', $raw)));
        $result = [];
        $idx = 0;
        foreach ($items as $item) {
            $idx++;
            $pos = strpos($item, '$');
            if ($pos === false) {
                $result[] = ['label' => '', 'ep' => $idx, 'url' => $item];
                continue;
            }
            $label = trim(substr($item, 0, $pos));
            $url = trim(substr($item, $pos + 1));
            $ep = extract_episode($label);
            $result[] = ['label' => $label, 'ep' => $ep ?? $idx, 'url' => $url];
        }
        return $result;
    }

    /** 在播放列表中挑选目标集数地址 */
    public static function pickPlayUrl(array $playList, ?int $targetEp): ?array
    {
        if ($playList === []) {
            return null;
        }
        if ($targetEp !== null && $targetEp > 0) {
            foreach ($playList as $p) {
                if ($p['ep'] === $targetEp) {
                    return $p;
                }
            }
            $pad = str_pad((string)$targetEp, 2, '0', STR_PAD_LEFT);
            foreach ($playList as $p) {
                if (str_contains($p['label'], $pad) || str_contains($p['label'], (string)$targetEp)) {
                    return $p;
                }
            }
        }
        return $playList[0];
    }

    /** 从资源站返回列表中挑选与关键词最匹配的剧集 */
    private static function bestVideo(array $list, string $keyword): ?array
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($list as $v) {
            $vn = (string)($v['vod_name'] ?? $v['name'] ?? '');
            $score = name_score($vn, $keyword);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $v;
            }
        }
        if ($best === null || $bestScore < 0.5) {
            return null;
        }
        return $best;
    }

    /**
     * 多线程搜索所有启用资源站
     *
     * @param array $sites   资源站列表
     * @param string $name   剧名
     * @param int|null $targetEp 目标集数
     * @param array $opts    {concurrency:int, timeout:int}
     * @return array{found:array, results:array, count:int, enabledCount:int}
     */
    public static function searchAll(string $name, ?int $targetEp, array $sites, array $opts = []): array
    {
        $enabled = array_values(array_filter($sites, fn($s) => (int)($s['enabled'] ?? 1) === 1));
        if ($enabled === []) {
            return ['found' => [], 'results' => [], 'count' => 0, 'enabledCount' => 0];
        }

        $timeout = (int)($opts['timeout'] ?? 8);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($enabled as $i => $site) {
            $url = self::buildSearchUrl($site, $name);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeout * 1000,
                CURLOPT_CONNECTTIMEOUT_MS => 3000,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => self::UA,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING => '',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        // 并发执行（curl_multi 天然多线程请求）
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $i => $ch) {
            $site = $enabled[$i];
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string)curl_multi_getcontent($ch);
            $err = curl_error($ch);
            $totalMs = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $results[$i] = self::buildResult($site, $name, $targetEp, $httpCode, $body, $err, $totalMs);
        }
        curl_multi_close($mh);

        $found = array_values(array_filter($results, fn($r) => ($r['ok'] ?? false) === true));
        return ['found' => $found, 'results' => $results, 'count' => count($results), 'enabledCount' => count($enabled)];
    }

    private static function buildResult(
        array $site,
        string $name,
        ?int $targetEp,
        int $httpCode,
        string $body,
        string $err,
        int $costMs
    ): array {
        $base = [
            'site' => ['id' => $site['site_id'] ?? '', 'name' => $site['name'] ?? '', 'api' => $site['api'] ?? ''],
            'costMs' => $costMs,
        ];

        if ($err !== '') {
            return $base + ['ok' => false, 'reason' => '请求失败: ' . $err];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return $base + ['ok' => false, 'reason' => 'HTTP ' . $httpCode];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $base + ['ok' => false, 'reason' => '返回非JSON'];
        }
        $list = $data['list'] ?? $data['data'] ?? [];
        if (!is_array($list) || $list === []) {
            return $base + ['ok' => false, 'reason' => '无搜索结果'];
        }

        $video = self::bestVideo($list, $name);
        if ($video === null) {
            $candidates = [];
            foreach (array_slice($list, 0, 3) as $v) {
                $candidates[] = $v['vod_name'] ?? $v['name'] ?? '';
            }
            return $base + ['ok' => false, 'reason' => '未匹配到剧名', 'candidates' => $candidates];
        }

        $playUrl = (string)($video['vod_play_url'] ?? $video['play_url'] ?? '');
        $playList = self::parsePlayList($playUrl);
        $picked = self::pickPlayUrl($playList, $targetEp);
        if ($picked === null) {
            return $base + ['ok' => false, 'reason' => '无可播放地址'];
        }

        return $base + [
            'ok' => true,
            'vodName' => $video['vod_name'] ?? $video['name'] ?? '',
            'playFrom' => $video['vod_play_from'] ?? $video['play_from'] ?? '',
            'episode' => $picked['ep'],
            'label' => $picked['label'],
            'url' => $picked['url'],
            'totalEpisodes' => count($playList),
        ];
    }
}