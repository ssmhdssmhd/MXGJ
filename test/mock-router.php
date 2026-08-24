<?php
/**
 * 本地 Mock 资源站（模拟苹果CMS provide/vod 接口），用于无外网环境下测试。
 * 运行：php -S 127.0.0.1:26531 test/mock-router.php
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_contains($path, 'provide/vod')) {
    header('Content-Type: application/json; charset=utf-8');
    $ac = $_GET['ac'] ?? 'list';
    $wd = trim((string)($_GET['wd'] ?? ''));

    if ($ac === 'detail') {
        // 模拟库：庆余年 共 4 集
        $db = [[
            'vod_id' => '1001',
            'vod_name' => '庆余年',
            'type_name' => '剧情',
            'vod_play_from' => 'm3u8',
            'vod_play_url' => implode('#', [
                '第01集$http://127.0.0.1:26531/play/qingyu/01.m3u8',
                '第02集$http://127.0.0.1:26531/play/qingyu/02.m3u8',
                '第03集$http://127.0.0.1:26531/play/qingyu/03.m3u8',
                '第04集$http://127.0.0.1:26531/play/qingyu/04.m3u8',
            ]),
        ]];

        $list = [];
        if ($wd !== '') {
            foreach ($db as $v) {
                $vn = preg_replace('/\s+/u', '', $v['vod_name']) ?? $v['vod_name'];
                $kw = preg_replace('/\s+/u', '', $wd) ?? $wd;
                if (mb_strpos($vn, $kw) !== false || mb_strpos($kw, $vn) !== false) {
                    $list[] = $v;
                }
            }
        }

        echo json_encode([
            'code' => 1,
            'msg' => $list !== [] ? '数据列表' : '暂无数据',
            'page' => 1,
            'pagecount' => 1,
            'limit' => '20',
            'total' => count($list),
            'list' => $list,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 0, 'msg' => 'not found']);
