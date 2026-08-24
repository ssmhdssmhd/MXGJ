<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 剧名/集数识别：
 *   1. 请求参数显式指定（name / ep）
 *   2. mxgj_name_map 表（平台+标识 -> 剧名/集数）
 *   3. 抓取官方页面 <title> 兜底
 */
class NameResolver
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** 从标题解析集数 */
    public static function titleEpisode(string $title): ?int
    {
        return extract_episode($title);
    }

    /** 去除标题中的平台后缀 */
    public static function cleanTitle(string $title): string
    {
        $t = trim($title);
        $t = preg_replace(
            '/-{1,3}\s*(腾讯视频|爱奇艺|优酷|芒果TV|哔哩哔哩|搜狐视频|PPTV|乐视|高清完整版|免费在线观看|在线观看).*$/iu',
            '',
            $t
        );
        return trim($t ?? '');
    }

    /**
     * @param array  $parsed     parse() 结果
     * @param string $overrideName
     * @param mixed  $overrideEp
     * @return array{name:?string, episode:?int, source:string}
     */
    public function resolve(array $parsed, string $overrideName = '', mixed $overrideEp = null): array
    {
        $ids = $parsed['ids'] ?? [];
        $platform = $parsed['platform'] ?? 'unknown';

        // 1. 参数覆盖
        if ($overrideName !== '') {
            return [
                'name' => trim($overrideName),
                'episode' => $overrideEp !== null && $overrideEp !== '' ? extract_episode($overrideEp) : null,
                'source' => 'param',
            ];
        }

        // 2. 数据库映射表
        foreach ($ids as $key => $vid) {
            $row = $this->db->first(
                'SELECT * FROM mxgj_name_map WHERE platform = ? AND vid = ? LIMIT 1',
                [$platform, (string)$vid]
            );
            if ($row === null) {
                $row = $this->db->first(
                    'SELECT * FROM mxgj_name_map WHERE platform = ? AND vid = ? LIMIT 1',
                    ['misc', (string)$vid]
                );
            }
            if ($row !== null) {
                return [
                    'name' => $row['name'],
                    'episode' => $row['episode'] > 0 ? (int)$row['episode'] : null,
                    'source' => "nameMap($key=$vid)",
                ];
            }
        }

        // 3. 页面标题兜底
        if (!empty($parsed['rawUrl'])) {
            $html = $this->fetchPage($parsed['rawUrl']);
            if ($html !== null && preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $html, $m)) {
                $title = self::cleanTitle(trim($m[1]));
                if ($title !== '') {
                    return [
                        'name' => $title,
                        'episode' => self::titleEpisode($title),
                        'source' => 'pageTitle',
                    ];
                }
            }
        }

        return ['name' => null, 'episode' => null, 'source' => 'none'];
    }

    private function fetchPage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 8000,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        return $errno === 0 && is_string($body) && $body !== '' ? $body : null;
    }
}