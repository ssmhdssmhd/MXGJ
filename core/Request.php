<?php
/**
 * Request - HTTP 请求封装
 *
 * 框架核心组件，统一处理 GET / POST / JSON 请求参数。
 */

namespace Core;

class Request
{
    /** @var array 请求参数 */
    protected $params = [];

    /** @var string 请求方法 */
    protected $method;

    /** @var string 请求路径 */
    protected $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->resolvePath();
        $this->collectParams();
    }

    /**
     * 解析请求路径（剥离入口脚本名前缀）
     *
     * @return string
     */
    protected function resolvePath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // 剥离入口脚本名：/api.php -> /，/api.php/health -> /health
        if ($scriptName !== '' && $scriptName !== '/' && strpos($path, $scriptName) === 0) {
            $path = substr($path, strlen($scriptName));
        }

        if ($path === '' || $path === false) {
            $path = '/';
        }

        return $path;
    }

    /**
     * 收集请求参数（GET + POST + JSON body）
     */
    protected function collectParams(): void
    {
        $this->params = $_GET;

        if ($this->method === 'POST') {
            $raw = file_get_contents('php://input');
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $this->params = array_merge($this->params, $json);
            } else {
                $this->params = array_merge($this->params, $_POST);
            }
        }
    }

    /**
     * 获取请求参数
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function input(string $key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * 获取所有请求参数
     *
     * @return array
     */
    public function all(): array
    {
        return $this->params;
    }

    /**
     * 请求方法
     *
     * @return string
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * 是否为指定请求方法
     *
     * @param string $method
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * 请求路径
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * 是否为 AJAX / 期望 JSON 响应
     *
     * @return bool
     */
    public function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'application/json') !== false
            || isset($_GET['format']) && $_GET['format'] === 'json';
    }
}
