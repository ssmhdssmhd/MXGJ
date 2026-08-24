<?php
/**
 * Router - 轻量级路由器
 *
 * 框架核心组件，将请求路由到对应控制器方法。
 * 支持 GET / POST 与路径参数（:param）。
 */

namespace Core;

class Router
{
    /** @var array 路由表 [method][pattern] => [controller, action] */
    protected $routes = [];

    /** @var string 控制器命名空间前缀 */
    protected $namespace = 'App\\Controllers\\';

    /**
     * 注册 GET 路由
     *
     * @param string $pattern
     * @param string $handler 如 "ParseController@parse"
     * @return void
     */
    public function get(string $pattern, string $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * 注册 POST 路由
     *
     * @param string $pattern
     * @param string $handler
     * @return void
     */
    public function post(string $pattern, string $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * 注册任意方法路由
     *
     * @param string $pattern
     * @param string $handler
     * @return void
     */
    public function any(string $pattern, string $handler): void
    {
        $this->add('ANY', $pattern, $handler);
    }

    /**
     * 添加路由
     *
     * @param string $method
     * @param string $pattern
     * @param string $handler
     * @return void
     */
    protected function add(string $method, string $pattern, string $handler): void
    {
        $this->routes[$method][$pattern] = $handler;
    }

    /**
     * 分发请求
     *
     * @param Request $request
     * @return mixed 控制器返回值
     */
    public function dispatch(Request $request)
    {
        $path = $request->path();
        $method = $request->method();

        // 遍历当前方法的路由
        $candidates = [];
        if (isset($this->routes[$method])) {
            $candidates = array_merge($candidates, $this->routes[$method]);
        }
        if (isset($this->routes['ANY'])) {
            $candidates = array_merge($candidates, $this->routes['ANY']);
        }

        foreach ($candidates as $pattern => $handler) {
            $params = $this->match($pattern, $path);
            if ($params !== null) {
                return $this->callHandler($handler, $params, $request);
            }
        }

        return null; // 未匹配
    }

    /**
     * 匹配路由模式与路径
     *
     * @param string $pattern
     * @param string $path
     * @return array|null 匹配到的参数，未匹配返回 null
     */
    protected function match(string $pattern, string $path): ?array
    {
        // 将 :param 转换为正则捕获组
        $regex = preg_replace('#:([A-Za-z0-9_]+)#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            // 仅保留命名捕获组
            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return null;
    }

    /**
     * 调用控制器方法
     *
     * @param string $handler
     * @param array  $params
     * @param Request $request
     * @return mixed
     */
    protected function callHandler(string $handler, array $params, Request $request)
    {
        list($controllerName, $action) = explode('@', $handler);

        $class = $this->namespace . $controllerName;
        if (!class_exists($class)) {
            throw new \RuntimeException("控制器不存在: {$class}");
        }

        $controller = new $class();
        if (!method_exists($controller, $action)) {
            throw new \RuntimeException("控制器方法不存在: {$class}@{$action}");
        }

        return $controller->{$action}($request, $params);
    }
}
