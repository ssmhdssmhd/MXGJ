<?php
/**
 * 沫兮官替系统 - 配置文件
 *
 * 数据库支持两种方式：
 *   - MySQL/MariaDB（推荐生产环境）：配置 host/name/user/pass/port
 *   - SQLite（零配置、本地测试）：配置 sqlite 路径即可，驱动自动选择
 * 同时把 $db_driver 设为 'sqlite' 且 sqlite 路径有效时，优先使用 SQLite。
 */

return [
    'app' => [
        'name'      => '沫兮官替系统',
        'version'   => '2.0.0',
        'timezone'  => 'Asia/Shanghai',
    ],

    'db' => [
        // 可选：'mysql' | 'sqlite'
        'driver' => getenv('MXGJ_DB_DRIVER') ?: 'mysql',

        // MySQL / MariaDB 配置
        'host' => getenv('MXGJ_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MXGJ_DB_PORT') ?: 3306),
        'name' => getenv('MXGJ_DB_NAME') ?: 'mxgj',
        'user' => getenv('MXGJ_DB_USER') ?: 'mxgj',
        'pass' => getenv('MXGJ_DB_PASS') ?: 'mxgj123',

        // SQLite 配置（driver=sqlite 时使用），自动在项目根目录创建 data/mxgj.sqlite
        'sqlite' => __DIR__ . '/../data/mxgj.sqlite',
    ],

    // 资源站多线程搜索并发数
    'concurrency' => (int)(getenv('MXGJ_CONCURRENCY') ?: 8),

    // 请求超时(秒)
    'timeout' => (int)(getenv('MXGJ_TIMEOUT') ?: 8),

    // 后台登录
    'admin' => [
        // 默认账号/密码，首次使用请修改。修改后务必同时更新后的哈希。
        'username' => getenv('MXGJ_ADMIN_USER') ?: 'admin',
        'password_hash' => getenv('MXGJ_ADMIN_PASS_HASH') ?: 'mxgj2024',
    ],

    // Git 自动更新
    'updater' => [
        'branch'    => getenv('MXGJ_GIT_BRANCH') ?: 'main',
        'enabled'   => true,
        // webhook 安全密钥，触发 /api/webhook 时校验?token=
        'webhook_token' => getenv('MXGJ_WEBHOOK_TOKEN') ?: 'change-me-webhook-token',
        // 自动更新锁文件
        'lock_file' => __DIR__ . '/../runtime/update.lock',
        // 更新日志落盘目录
        'log_dir'   => __DIR__ . '/../runtime',
    ],
];