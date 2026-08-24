<?php
/**
 * Response - HTTP 响应封装
 *
 * 框架核心组件，支持 JSON / JSONP 输出与跨域（CORS）。
 */

namespace Core;

class Response
{
    /** @var int HTTP 状态码 */
    protected $status = 200;

    /** @var array 响应头 */
    protected $headers = [];

    public function __construct()
    {
        // 默认开启跨域支持（外部可调用）
        $this->header('Access-Control-Allow-Origin', '*');
        $this->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $this->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    }

    /**
     * 设置响应头
     *
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * 设置 HTTP 状态码
     *
     * @param int $status
     * @return $this
     */
    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * 输出 JSON（支持 JSONP 回调）
     *
     * @param array $data
     * @param int   $status
     * @param string|null $callback JSONP 回调函数名
     * @return never
     */
    public function json(array $data, int $status = 200, ?string $callback = null): void
    {
        $this->status($status);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode(['success' => false, 'error' => 'JSON 编码失败'], JSON_UNESCAPED_UNICODE);
        }

        if ($callback !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_\.]*$/', $callback)) {
            $this->header('Content-Type', 'application/javascript; charset=utf-8');
            $this->send($callback . '(' . $json . ');');
        }

        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->send($json);
    }

    /**
     * 输出 HTML
     *
     * @param string $html
     * @param int    $status
     * @return never
     */
    public function html(string $html, int $status = 200): void
    {
        $this->status($status);
        $this->header('Content-Type', 'text/html; charset=utf-8');
        $this->send($html);
    }

    /**
     * 处理 OPTIONS 预检请求
     *
     * @return never
     */
    public function preflight(): void
    {
        http_response_code(204);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        exit;
    }

    /**
     * 发送响应并结束
     *
     * @param string $body
     * @return never
     */
    protected function send(string $body): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }
}
