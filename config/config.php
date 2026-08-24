<?php
/**
 * 应用配置
 *
 * 框架版本：0.0.1
 */

return [
    // 应用信息
    'app' => [
        'name' => 'MXGJ 视频解析工具',
        'version' => '0.0.1',
        'env' => 'production',
        'debug' => false,
    ],

    // 第三方解析接口列表
    'parse_apis' => [
        'https://jx.playerjy.com/?url=',
        'https://jx.aidouer.net/?url=',
        'https://jx.jsonplayer.com/?url=',
        'https://jx.bozrc.com:4433/player/?url=',
    ],

    // 请求配置
    'http' => [
        'timeout' => 15,
        'max_retries' => 3,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ],

    // 解析配置
    'parser' => [
        'max_urls' => 5,
        'enable_quality_upgrade' => true,
    ],

    // SSRF 防护：允许的视频域名白名单
    'allowed_domains' => [
        'iqiyi.com', 'v.qq.com', 'qq.com', 'youku.com', 'mgtv.com',
        'bilibili.com', 'b23.tv', 'sohu.com', 'letv.com', '163.com',
        'miguvideo.com', 'wasu.cn', 'cctv.com', 'cntv.cn', 'douyin.com',
    ],
];
