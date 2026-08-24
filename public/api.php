<?php
/**
 * API 入口
 *
 * 外部可调用接口，支持跨域（CORS）与 JSONP。
 *
 * 路由：
 *   GET/POST /api.php?url=<视频链接>        解析视频
 *   GET      /api.php/health                健康检查
 *   GET      /api.php/check?url=<视频链接>  检测播放源接口（供配置前验证）
 */

require __DIR__ . '/../bootstrap.php';

$app = new \Core\App(dirname(__DIR__));

$app->routes(function ($router) {
    $router->any('/', 'ParseController@parse');
    $router->get('/health', 'ParseController@health');
    $router->get('/check', 'ParseController@check');
});

$app->run();
