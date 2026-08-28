<?php
/**
 * Player 自包含工具库
 * 定义常量 + JSON 读写 + 播放器 CRUD 函数
 * 不依赖 MXGJ_PLAYER 等 bootstrap 常量，保证独立
 */

if (!defined('PLAYER_ROOT')) {
    define('PLAYER_ROOT', __DIR__);
    define('PLAYER_DATA', PLAYER_ROOT . '/data');
    define('PLAYER_FILE', PLAYER_DATA . '/players.json');
}

/** 读 JSON，不存在或解析失败返回默认 */
function player_read_json(string $file, $default = [])
{
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return $default;
    $data = @json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/** 写 JSON */
function player_write_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
}

/** 获取全部播放器 */
function player_players(): array
{
    static $cache = null;
    if ($cache === null) {
        $data = player_read_json(PLAYER_FILE);
        $cache = isset($data['players']) && is_array($data['players']) ? $data['players'] : [];
    }
    return $cache;
}

/** 获取默认播放器（优先 is_default 且 enabled，否则第一个启用的） */
function player_default(): ?array
{
    $players = player_players();
    foreach ($players as $p) {
        if (!empty($p['enabled']) && !empty($p['is_default'])) return $p;
    }
    foreach ($players as $p) {
        if (!empty($p['enabled'])) return $p;
    }
    return $players[0] ?? null;
}

/** 按 ID 或 player_code 查单个 */
function player_get($key): ?array
{
    foreach (player_players() as $p) {
        if ((is_int($key) && (int)($p['id'] ?? 0) === $key)
            || (is_string($key) && ($p['player_code'] ?? '') === $key)) {
            return $p;
        }
    }
    return null;
}

/** 保存（重置静态缓存） */
function player_save(array $players): bool
{
    $result = player_write_json(PLAYER_FILE, ['players' => array_values($players)]);
    // 重置静态缓存
    $GLOBALS['_player_cache'] = null;
    return $result;
}
