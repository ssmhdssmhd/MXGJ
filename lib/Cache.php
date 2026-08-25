<?php
/**
 * 沫兮官替系统 - 文件缓存
 *
 * 用于缓存资源站搜索结果，避免频繁请求外部资源站。
 */

class Cache
{
    /**
     * 读取缓存，未命中或过期返回默认值
     */
    public static function get(string $key, $default = null)
    {
        $file = self::file($key);
        if (!is_file($file)) {
            return $default;
        }
        $data = @unserialize(@file_get_contents($file));
        if (!is_array($data) || !isset($data['expire'], $data['value'])) {
            return $default;
        }
        if ($data['expire'] > 0 && $data['expire'] < time()) {
            @unlink($file);
            return $default;
        }
        return $data['value'];
    }

    /**
     * 写入缓存，$ttl<=0 表示永不过期
     */
    public static function set(string $key, $value, int $ttl = 600): bool
    {
        if (!is_dir(MXGJ_CACHE)) {
            @mkdir(MXGJ_CACHE, 0755, true);
        }
        $data = [
            'expire' => $ttl > 0 ? time() + $ttl : 0,
            'value'  => $value,
        ];
        return @file_put_contents(self::file($key), serialize($data)) !== false;
    }

    /**
     * 计算缓存文件名
     */
    public static function file(string $key): string
    {
        return MXGJ_CACHE . '/' . md5($key) . '.cache';
    }
}
