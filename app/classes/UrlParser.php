<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 官方视频链接解析器：识别平台并抽取用于反查剧名/集数的标识（cid/vid/BV号 等）。
 */
class UrlParser
{
    private const PLATFORMS = [
        'tencent'   => ['hosts' => ['v.qq.com', 'm.v.qq.com', 'film.qq.com'], 'label' => '腾讯视频'],
        'iqiyi'     => ['hosts' => ['v.iqiyi.com', 'www.iqiyi.com', 'm.iqiyi.com'], 'label' => '爱奇艺'],
        'youku'     => ['hosts' => ['v.youku.com', 'www.youku.com'], 'label' => '优酷'],
        'mgtv'      => ['hosts' => ['www.mgtv.com', 'm.mgtv.com'], 'label' => '芒果TV'],
        'bilibili'  => ['hosts' => ['www.bilibili.com', 'm.bilibili.com', 'bilibili.com'], 'label' => '哔哩哔哩'],
        'sohu'      => ['hosts' => ['tv.sohu.com', 'v.sohu.com'], 'label' => '搜狐视频'],
        'pptv'      => ['hosts' => ['v.pptv.com'], 'label' => 'PPTV'],
        'leshi'     => ['hosts' => ['www.le.com'], 'label' => '乐视'],
    ];

    public static function detectPlatform(string $host): string
    {
        $h = strtolower($host);
        foreach (self::PLATFORMS as $key => $info) {
            foreach ($info['hosts'] as $cand) {
                if ($h === $cand || str_ends_with($h, '.' . $cand)) {
                    return $key;
                }
            }
        }
        return 'unknown';
    }

    public static function platformLabel(string $key): string
    {
        return self::PLATFORMS[$key]['label'] ?? '未知平台';
    }

    /**
     * @return array{ok:bool, platform:string, platformLabel:string, host:string, ids:array, rawUrl:string, error?:string}
     */
    public static function parse(string $urlStr): array
    {
        $urlStr = trim($urlStr);
        $parts = parse_url($urlStr);
        if ($parts === false || empty($parts['host'])) {
            return ['ok' => false, 'error' => '无效的URL: ' . $urlStr];
        }

        $host = strtolower($parts['host']);
        $platform = self::detectPlatform($host);
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $ids = [];

        if ($platform === 'tencent') {
            $ids['cid'] = $query['cid'] ?? '';
            $ids['vid'] = $query['vid'] ?? '';
        } elseif ($platform === 'iqiyi') {
            $ids['vid'] = $query['vid'] ?? '';
            $ids['aid'] = $query['aid'] ?? '';
            if (preg_match('/([a-zA-Z0-9]{10,})\.html/', $path, $m)) {
                $ids['albumId'] = $m[1];
            }
        } elseif ($platform === 'youku') {
            if (preg_match('/id_([A-Za-z0-9_=]+)/', $path, $m)) {
                $ids['vid'] = $m[1];
            }
            $ids['showid'] = $query['showid'] ?? '';
        } elseif ($platform === 'mgtv') {
            $ids['vid'] = $query['vid'] ?? '';
            $ids['cid'] = $query['cid'] ?? '';
            if (preg_match('/(\d{6,})\.html/', $path, $m)) {
                $ids['vid'] = $ids['vid'] !== '' ? $ids['vid'] : $m[1];
            }
            $segs = array_values(array_filter(explode('/', $path)));
            if (isset($segs[2]) && preg_match('/^\d+$/', $segs[2])) {
                $ids['cid'] = $ids['cid'] !== '' ? $ids['cid'] : $segs[2];
            }
        } elseif ($platform === 'bilibili') {
            if (preg_match('/BV[0-9A-Za-z]+/', $path, $m)) {
                $ids['bvid'] = $m[0];
            }
            $ids['av'] = $query['av'] ?? '';
            if (preg_match('/\/av(\d+)/i', $path, $m)) {
                $ids['av'] = $m[1];
            }
        } elseif ($platform === 'sohu') {
            if (preg_match('/(\d{6,})\.shtml/', $path, $m)) {
                $ids['vid'] = $m[1];
            }
        } elseif ($platform === 'pptv') {
            if (preg_match('/(\d+)\//', $path, $m)) {
                $ids['vid'] = $m[1];
            }
        } elseif ($platform === 'leshi') {
            if (preg_match('/\/play\/?([A-Za-z0-9]+)/', $path, $m)) {
                $ids['vid'] = $m[1];
            }
        }

        // 去掉空值，保留主标识优先顺序
        $ids = array_filter($ids, fn($v) => $v !== '');
        if ($ids === []) {
            $ids['raw'] = $urlStr;
        }

        return [
            'ok' => true,
            'platform' => $platform,
            'platformLabel' => self::platformLabel($platform),
            'host' => $host,
            'ids' => $ids,
            'rawUrl' => $urlStr,
        ];
    }
}