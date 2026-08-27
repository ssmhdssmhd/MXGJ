<?php
/**
 * 沫兮官替系统 - 播放器数据 API
 *
 * 提供 JSON 接口，供前端或其他系统读取播放器列表。
 *
 * GET  /player/api.php              → 所有启用播放器列表
 * GET  /player/api.php?all=1        → 全部播放器（含禁用）
 * GET  /player/api.php?code=xxx     → 指定播放器详情
 * GET  /player/api.php?default=1    → 默认播放器详情
 */

require __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$players = mxgj_players();

// 单个查询
$code = trim((string)($_GET['code'] ?? ''));
if ($code !== '') {
    $p = mxgj_get_player($code);
    if (!$p || empty($p['enabled'])) {
        echo json_encode(['code' => 404, 'msg' => '播放器不存在或已禁用'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'code'         => 200,
        'player_code'  => $p['player_code'],
        'player_name'  => $p['player_name'],
        'player_from'  => $p['player_from'] ?? '',
        'player_remark'=> $p['player_remark'] ?? '',
        'player_code_content' => $p['player_code_content'],
        'is_default'   => !empty($p['is_default']),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 默认播放器
if (!empty($_GET['default'])) {
    $p = mxgj_default_player();
    if (!$p) {
        echo json_encode(['code' => 404, 'msg' => '无可用播放器'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'code' => 200,
        'player' => [
            'player_code'  => $p['player_code'],
            'player_name'  => $p['player_name'],
            'player_from'  => $p['player_from'] ?? '',
            'player_code_content' => $p['player_code_content'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 全部/启用列表
$showAll = !empty($_GET['all']);
$list = [];
foreach ($players as $p) {
    if (!$showAll && empty($p['enabled'])) continue;
    $list[] = [
        'id'            => (int)($p['id'] ?? 0),
        'player_code'   => $p['player_code'],
        'player_name'   => $p['player_name'],
        'player_from'   => $p['player_from'] ?? '',
        'player_remark' => $p['player_remark'] ?? '',
        'enabled'       => !empty($p['enabled']),
        'is_default'    => !empty($p['is_default']),
    ];
}

echo json_encode([
    'code'     => 200,
    'total'    => count($players),
    'enabled'  => count($list),
    'players'  => $list,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
