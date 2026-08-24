<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 官替核心业务：
 *   官方链接 -> 解析 -> 识别剧名/集数 -> 资源站多线程搜索 -> 返回替换结果并写日志
 */
class VodService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param array $params {url:string, name:string, ep:mixed}
     * @return array JSON 响应
     */
    public function handle(array $params): array
    {
        $startMs = microtime_ms();
        $urlStr = trim((string)($params['url'] ?? ''));
        if ($urlStr === '') {
            return ['code' => 400, 'msg' => '缺少参数 url（官方视频链接）'];
        }

        $parsed = UrlParser::parse($urlStr);
        if (!($parsed['ok'] ?? false)) {
            return ['code' => 400, 'msg' => $parsed['error'] ?? '链接解析失败'];
        }

        $overrideEp = (isset($params['ep']) && $params['ep'] !== '' && $params['ep'] !== null)
            ? extract_episode((string)$params['ep'])
            : null;

        $resolver = new NameResolver($this->db);
        $resolved = $resolver->resolve($parsed, (string)($params['name'] ?? ''), $overrideEp);

        if ($resolved['name'] === null || $resolved['name'] === '') {
            return [
                'code' => 404,
                'msg' => '无法识别该链接对应的剧名，请在后台「剧名映射」中配置，或请求参数显式指定 name/ep',
                'data' => [
                    'platform' => $parsed['platform'],
                    'platformLabel' => $parsed['platformLabel'],
                    'ids' => $parsed['ids'],
                    'rawUrl' => $parsed['rawUrl'],
                ],
            ];
        }

        $targetEp = $resolved['episode'];
        $sites = $this->db->select('SELECT * FROM mxgj_sites ORDER BY id ASC');
        $search = ResourceSearcher::searchAll($resolved['name'], $targetEp, $sites, [
            'timeout' => (int)$this->db->setting('default_timeout', config('timeout', 8)),
        ]);

        $costMs = microtime_ms() - $startMs;
        $found = $search['found'] ?? [];

        if ($found === []) {
            $this->log($parsed, $resolved['name'], $targetEp, 404, null, null, $costMs, '未找到替换资源');
            return [
                'code' => 404,
                'msg' => sprintf('未在资源站中找到「%s」%s的替换资源', $resolved['name'], $targetEp ? '第' . $targetEp . '集' : ''),
                'data' => [
                    'name' => $resolved['name'],
                    'episode' => $targetEp,
                    'platform' => $parsed['platform'],
                    'platformLabel' => $parsed['platformLabel'],
                    'searched' => count($search['results'] ?? []),
                    'details' => array_map(
                        fn($r) => [
                            'site' => $r['site']['name'] ?? '',
                            'ok' => (bool)($r['ok'] ?? false),
                            'reason' => $r['reason'] ?? '',
                            'candidates' => $r['candidates'] ?? null,
                        ],
                        $search['results'] ?? []
                    ),
                ],
            ];
        }

        // 命中：按耗时升序取最快站点
        usort($found, fn($a, $b) => (int)($a['costMs'] ?? 0) - (int)($b['costMs'] ?? 0));
        $top = $found[0];

        $this->log($parsed, $resolved['name'], $top['episode'] ?? $targetEp, 200, $top['url'], $top['site']['name'] ?? '', $costMs, 'success');

        return [
            'code' => 200,
            'url' => $top['url'],
            'msg' => 'success',
            'data' => [
                'name' => $resolved['name'],
                'episode' => $top['episode'] ?? $targetEp,
                'platform' => $parsed['platform'],
                'platformLabel' => $parsed['platformLabel'],
                'matchedSite' => $top['site']['name'] ?? '',
                'playFrom' => $top['playFrom'] ?? '',
                'totalEpisodes' => $top['totalEpisodes'] ?? 0,
                'candidates' => array_map(
                    fn($f) => [
                        'site' => $f['site']['name'] ?? '',
                        'url' => $f['url'] ?? '',
                        'episode' => $f['episode'] ?? null,
                        'costMs' => $f['costMs'] ?? null,
                    ],
                    $found
                ),
                'costMs' => $costMs,
            ],
        ];
    }

    private function log(array $parsed, ?string $name, ?int $episode, int $code, ?string $url, ?string $site, int $costMs, string $msg): void
    {
        try {
            $this->db->insert('mxgj_logs', [
                'source_url' => $parsed['rawUrl'] ?? '',
                'platform' => $parsed['platform'] ?? '',
                'vod_name' => $name ?? '',
                'episode' => $episode,
                'code' => $code,
                'result_url' => $url ?? '',
                'matched_site' => $site ?? '',
                'msg' => $msg,
                'cost_ms' => $costMs,
            ]);
        } catch (\Throwable $e) {
            // 日志写失败不影响主流程
        }
    }
}