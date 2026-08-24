<?php
/**
 * IndexController - 首页控制器
 *
 * 渲染 Web 播放界面视图。
 */

namespace App\Controllers;

use Core\Request;
use Core\Response;
use Core\App;

class IndexController
{
    /**
     * 渲染首页
     *
     * @param Request $request
     * @param array   $params
     * @return void
     */
    public function index(Request $request, array $params): void
    {
        $app = App::instance();
        $response = new Response();

        ob_start();
        include dirname(__DIR__) . '/Views/index.php';
        $html = ob_get_clean();

        $response->html($html);
    }
}
