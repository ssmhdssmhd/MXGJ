<?php
/**
 * App - 应用核心
 *
 * 框架核心组件，负责初始化、路由分发与统一异常处理。
 * 版本：0.0.1
 */

namespace Core;

class App
{
    /** @var string 框架版本 */
    public const VERSION = '0.0.1';

    /** @var string 应用根目录 */
    protected $basePath;

    /** @var Router */
    protected $router;

    /** @var Request */
    protected $request;

    /** @var Response */
    protected $response;

    /** @var App|null 当前应用实例 */
    protected static $instance;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->router = new Router();
        $this->request = new Request();
        $this->response = new Response();
        self::$instance = $this;
    }

    /**
     * 获取当前应用实例
     *
     * @return App|null
     */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * 获取应用根目录
     *
     * @return string
     */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * 获取路由器
     *
     * @return Router
     */
    public function router(): Router
    {
        return $this->router;
    }

    /**
     * 获取请求对象
     *
     * @return Request
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * 获取响应对象
     *
     * @return Response
     */
    public function response(): Response
    {
        return $this->response;
    }

    /**
     * 注册路由（由入口文件调用）
     *
     * @param callable $routes 接收 $this 的回调
     * @return void
     */
    public function routes(callable $routes): void
    {
        $routes($this->router);
    }

    /**
     * 运行应用
     *
     * @return void
     */
    public function run(): void
    {
        try {
            // OPTIONS 预检请求
            if ($this->request->isMethod('OPTIONS')) {
                $this->response->preflight();
            }

            $result = $this->router->dispatch($this->request);

            if ($result === null) {
                $this->response->json([
                    'success' => false,
                    'error' => '404 Not Found',
                ], 404);
            }

            // 控制器已自行输出时，$result 为 null 且已 exit；此处兜底
            if (is_array($result)) {
                $this->response->json($result);
            }
        } catch (\Throwable $e) {
            $this->response->json([
                'success' => false,
                'error' => '服务异常: ' . $e->getMessage(),
            ], 500);
        }
    }
}
