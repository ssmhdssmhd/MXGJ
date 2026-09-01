<?php
/**
 * 沫兮官替系统 - 文件缓存
 *
 * 用于缓存资源站搜索结果，避免频繁请求外部资源站。
 *
 * 缓存文件格式：
 *   expire = 绝对过期时间戳（time() + ttl），0 = 永不过期
 *   ttl    = 写入时使用的原始 ttl（用于 cache_ttl 变更时重新计算过期）
 *   time   = 写入时间戳
 *   value  = 缓存值
 *
 * 关键设计：
 *   - 如果用户改了后台 cache_ttl（比如从 600 改成 30），
 *     旧缓存文件存的是 expire = time_written + 600，
 *     但按新 ttl=30 算，expire 应该是 time_written + 30，
 *     如果新 expire < 当前时间 → 旧缓存立即失效。
 */

class Cache
{
    /**
     * 读取缓存，未命中或过期返回默认值
     * 支持 cache_ttl 设置变更感知 —— 改小 ttl 后旧缓存自动按新 ttl 算过期
     */
    public static function get(string $key, $default = null)
    {
        $file = self::file($key);
        if (!is_file($file)) {
            return $default;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return $default;
        }
        $data = @unserialize($raw);
        if (!is_array($data) || !isset($data['expire'], $data['value'])) {
            @unlink($file);
            return $default;
        }

        $now = time();

        // 基础过期检查
        if ($data['expire'] > 0 && $data['expire'] < $now) {
            @unlink($file);
            return $default;
        }

        // 🔴 cache_ttl 设置变更感知：
        // 如果当前 cache_ttl < 写入时的 ttl，用当前 ttl 重新算过期时间
        // 场景：用户后台把 cache_ttl 从 600 改成 30 → 旧缓存应该在 30 秒后就过期
        // 兼容：v1.17.13 及之前的旧格式缓存没有 ttl 字段 → 从 expire - value[time] 反推
        $original_ttl = 0;
        if (!empty($data['ttl']) && $data['ttl'] > 0) {
            $original_ttl = $data['ttl'];
        } elseif (!empty($data['value']['time']) && $data['expire'] > $data['value']['time']) {
            // 旧格式：expire = time_written + original_ttl → 反推 original_ttl
            $original_ttl = $data['expire'] - $data['value']['time'];
        }

        if ($original_ttl > 0) {
            try {
                $current_ttl = 600;
                if (function_exists('mxgj_settings')) {
                    $st = mxgj_settings();
                    $current_ttl = (int)($st['cache_ttl'] ?? 600);
                }
                if ($current_ttl > 0 && $current_ttl < $original_ttl) {
                    // 写入时间 = expire - 原始 ttl
                    $written_at = $data['expire'] - $original_ttl;
                    $new_expire = $written_at + $current_ttl;
                    if ($new_expire < $now) {
                        @unlink($file);
                        return $default;
                    }
                }
            } catch (\Throwable $e) {
                // mxgj_settings() 失败就跳过额外检查，用基础 expire 即可
            }
        }

        return $data['value'];
    }

    /**
     * 写入缓存，$ttl<=0 表示永不过期
     * 存写入时间 + 原始 ttl，供 get() 在 cache_ttl 变更时重新计算过期
     */
    public static function set(string $key, $value, int $ttl = 600): bool
    {
        if (!is_dir(MXGJ_CACHE)) {
            @mkdir(MXGJ_CACHE, 0755, true);
        }
        $now = time();
        $data = [
            'expire' => $ttl > 0 ? $now + $ttl : 0,
            'ttl'    => $ttl,          // 原始 ttl，供 cache_ttl 变更感知
            'time'   => $now,          // 写入时间
            'value'  => $value,
        ];
        return @file_put_contents(self::file($key), serialize($data), LOCK_EX) !== false;
    }

    /**
     * 计算缓存文件名
     */
    public static function file(string $key): string
    {
        return MXGJ_CACHE . '/' . md5($key) . '.cache';
    }

    /**
     * 清除所有缓存文件
     * @return int 清除数量
     */
    public static function clearAll(): int
    {
        $n = 0;
        foreach (glob(MXGJ_CACHE . '/*.cache') ?: [] as $f) {
            if (@unlink($f)) $n++;
        }
        return $n;
    }
}
