<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 数据库结构迁移 + 首次安装种子数据（幂等）。
 */
class Migration
{
    /** 建表（幂等），兼容 MySQL 与 SQLite */
    public static function ensure(): void
    {
        $db = db();
        $isSqlite = $db->driver() === 'sqlite';

        // 各表结构（兼容双数据库）
        $tables = [
            'mxgj_sites' => [
                'pk' => $isSqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY',
                'columns' => [
                    'site_id'   => 'VARCHAR(64) NOT NULL' . ($isSqlite ? '' : ''),
                    'name'      => 'VARCHAR(128) NOT NULL',
                    'api'       => 'VARCHAR(512) NOT NULL',
                    'timeout'   => 'INT NOT NULL DEFAULT 8000',
                    'enabled'   => 'TINYINT NOT NULL DEFAULT 1',
                    'created_at'=> 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'=> 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'unique' => $isSqlite ? 'site_id TEXT' : 'UNIQUE KEY uq_site_id (site_id)',
            ],
            'mxgj_name_map' => [
                'pk' => $isSqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY',
                'columns' => [
                    'platform'  => 'VARCHAR(32) NOT NULL',
                    'vid'       => 'VARCHAR(128) NOT NULL',
                    'name'      => 'VARCHAR(128) NOT NULL',
                    'episode'   => 'INT NOT NULL DEFAULT 0',
                    'created_at'=> 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'unique' => $isSqlite ? 'platform TEXT, vid TEXT' : 'UNIQUE KEY uq_platform_vid (platform, vid)',
            ],
            'mxgj_logs' => [
                'pk' => $isSqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY',
                'columns' => [
                    'source_url'   => 'TEXT',
                    'platform'     => 'VARCHAR(32)',
                    'vod_name'     => 'VARCHAR(128)',
                    'episode'      => 'INT',
                    'code'         => 'INT',
                    'result_url'   => 'TEXT',
                    'matched_site' => 'VARCHAR(128)',
                    'msg'          => 'TEXT',
                    'cost_ms'      => 'INT',
                    'created_at'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'unique' => '',
            ],
            'mxgj_update_logs' => [
                'pk' => $isSqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY',
                'columns' => [
                    'status'     => 'VARCHAR(32) NOT NULL',
                    'message'    => 'TEXT',
                    'before_commit' => 'VARCHAR(64)',
                    'after_commit'  => 'VARCHAR(64)',
                    'trigger'    => 'VARCHAR(32)',
                    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'unique' => '',
            ],
            'mxgj_settings' => [
                'pk' => $isSqlite ? 'k TEXT PRIMARY KEY' : 'k VARCHAR(64) PRIMARY KEY',
                'columns' => [
                    'v' => 'TEXT',
                ],
                'unique' => '',
            ],
            'mxgj_admin' => [
                'pk' => $isSqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY',
                'columns' => [
                    'username'      => 'VARCHAR(64) NOT NULL' . ($isSqlite ? ' UNIQUE' : ''),
                    'password_hash' => 'VARCHAR(255) NOT NULL',
                    'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'unique' => $isSqlite ? '' : 'UNIQUE KEY uq_username (username)',
            ],
        ];

        foreach ($tables as $name => $def) {
            $cols = [];
            $cols[] = $def['pk'];
            foreach ($def['columns'] as $col => $type) {
                $cols[] = "`$col` $type";
            }
            if ($def['unique'] !== '') {
                $cols[] = $def['unique'];
            }
            $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $sql = 'CREATE TABLE IF NOT EXISTS `' . $name . '` (' . implode(', ', $cols) . ')' . $engine;
            $db->exec($sql);
        }

        self::seed($db);
    }

    /** 首次安装种子数据 */
    private static function seed(Database $db): void
    {
        // 管理员
        $count = (int)$db->value('SELECT COUNT(*) FROM mxgj_admin');
        if ($count === 0) {
            $db->insert('mxgj_admin', [
                'username'      => 'admin',
                'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            ]);
        }

        // 默认设置
        $defaults = [
            'concurrency'      => (string)config('concurrency', 8),
            'default_timeout'  => (string)config('timeout', 8),
            'update_token'     => random_token(24),
            'git_branch'       => config('updater.branch', 'main'),
            'update_enabled'   => config('updater.enabled') ? '1' : '0',
        ];
        foreach ($defaults as $k => $v) {
            if ($db->setting($k) === null) {
                $db->setSetting($k, $v);
            }
        }

        // 默认资源站（含本地 Mock 用于测试）
        $count = (int)$db->value('SELECT COUNT(*) FROM mxgj_sites');
        if ($count === 0) {
            $db->insert('mxgj_sites', [
                'site_id' => 'mock_local',
                'name'    => '本地Mock资源站(测试用)',
                'api'     => 'http://127.0.0.1:26531/api.php/provide/vod/',
                'timeout' => 8000,
                'enabled' => 1,
            ]);
            $db->insert('mxgj_sites', [
                'site_id' => 'demo_site_a',
                'name'    => '示例资源站A(苹果CMS)',
                'api'     => 'https://替换为你的资源站域名.com/api.php/provide/vod/',
                'timeout' => 8000,
                'enabled' => 0,
            ]);
            $db->insert('mxgj_sites', [
                'site_id' => 'demo_site_b',
                'name'    => '示例资源站B(海洋CMS)',
                'api'     => 'https://替换为你的资源站域名.com/api.php/provide/vod/',
                'timeout' => 8000,
                'enabled' => 0,
            ]);
        }

        // 示例剧名映射
        $count = (int)$db->value('SELECT COUNT(*) FROM mxgj_name_map');
        if ($count === 0) {
            $db->insert('mxgj_name_map', [
                'platform' => 'tencent',
                'vid'      => 'mzc00200zx8psx0',
                'name'     => '庆余年',
                'episode'  => 2,
            ]);
        }
    }
}