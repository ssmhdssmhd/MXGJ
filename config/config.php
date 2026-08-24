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

    // 第三方解析接口列表（依次请求，提取直链或 iframe 播放器源）
    // 注：免费解析接口对爱奇艺等平台通常返回 iframe 播放器页（可能含广告），
    //     仅当接口返回 m3u8 / mp4 等直链时才会作为「直链」返回。
    'parse_apis' => [
        'https://jx.playerjy.com/?url=',
        'https://jx.xmflv.cc/?url=',
        'https://jx.xmflv.com/?url=',
        'https://jx.77flv.cc/?url=',
    ],

    // 兜底 iframe 播放器接口（默认开启；整站 iframe 嵌入播放，播放器可能自带广告）
    'iframe_players' => [
        'https://jx.xmflv.cc/?url=',
        'https://jx.xmflv.com/?url=',
    ],

    // 是否返回 iframe 播放器源（默认开启；关闭后仅返回直链，可能无可用源）
    'enable_iframe_players' => true,

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
