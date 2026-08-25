<?php
/**
 * 沫兮官替系统 - 日志记录
 *
 * 无数据库，按类型各存一个 JSON 文件（data/logs/{type}.json），最新在前。
 * 支持类型：login 登录 / operation 操作 / update 更新 / search 搜索调用 / config 配置 / error 错误。
 * 每类日志有最大条数上限（MAX_PER_TYPE），超出自动裁剪最旧记录，防止无限增长。
 */
class Logger
{
    /** 支持日志类型与中文名 */
    const TYPES = [
        'login'     => '登录日志',
        'operation' => '操作日志',
        'update'    => '更新日志',
        'search'    => '搜索调用日志',
        'config'    => '配置日志',
        'error'     => '错误日志',
    ];

    /** 每类日志最大保留条数 */
    const MAX_PER_TYPE = 500;

    /** 日志目录 */
    public static function dir(): string
    {
        $dir = MXGJ_DATA . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** 某类型的日志文件路径 */
    public static function file(string $type): string
    {
        $type = self::sanitize($type);
        return self::dir() . '/' . $type . '.json';
    }

    private static function sanitize(string $type): string
    {
        return isset(self::TYPES[$type]) ? $type : 'error';
    }

    /**
     * 写入一条日志
     *
     * @param string $type  日志类型（login/operation/update/search/config/error）
     * @param string $msg   日志内容
     * @param string $level 级别：info 提示 / success 成功 / warn 警告 / error 错误
     * @param array  $extra 附加结构化数据（如 剧名、集数、耗时、站点、返回码等）
     */
    public static function log(string $type, string $msg, string $level = 'info', array $extra = []): void
    {
        $type = self::sanitize($type);
        $file = self::file($type);

        $rows   = self::readAll($file);
        $rows[] = [
            'time'     => time(),
            'time_str' => date('Y-m-d H:i:s'),
            'level'    => $level,
            'msg'      => $msg,
            'extra'    => $extra,
        ];
        // 最新在前，超出上限裁剪最旧
        $rows = array_slice(array_reverse($rows), 0, self::MAX_PER_TYPE);

        @file_put_contents($file, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * 读取某类型日志（最新在前）
     */
    public static function read(string $type, int $limit = 100): array
    {
        $type = self::sanitize($type);
        $rows = self::readAll(self::file($type));
        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    private static function readAll(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $content = @file_get_contents($file);
        if ($content === false || trim($content) === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 清空某类型日志，返回删除条数
     */
    public static function clear(string $type): int
    {
        $type = self::sanitize($type);
        $file = self::file($type);
        $n    = count(self::readAll($file));
        @unlink($file);
        return $n;
    }

    /** 清空全部日志 */
    public static function clearAll(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $label) {
            $out[$type] = self::clear($type);
        }
        return $out;
    }

    /**
     * 各类日志条数统计
     */
    public static function counts(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $label) {
            $out[$type] = count(self::readAll(self::file($type)));
        }
        return $out;
    }
}
