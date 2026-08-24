<?php
/**
 * 首页入口
 *
 * 访问 / 渲染 Web 播放界面。
 */

require __DIR__ . '/../bootstrap.php';

$app = new \Core\App(dirname(__DIR__));

$app->routes(function ($router) {
    $router->get('/', 'IndexController@index');
});

$app->run();
