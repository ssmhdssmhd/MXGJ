<?php
/**
 * ParseController - 解析控制器
 *
 * 处理视频解析请求：参数校验、SSRF 防护、调用解析服务并返回结果。
 */

namespace App\Controllers;

use Core\Request;
use Core\Response;
use App\Services\VideoParserService;

class ParseController
{
    /** @var array 应用配置 */
    protected $config;

    public function __construct()
    {
        $this->config = require dirname(__DIR__, 2) . '/config/config.php';
    }

    /**
     * 解析视频（API 入口）
     *
     * @param Request $request
     * @param array   $params
     * @return void
     */
    public function parse(Request $request, array $params): void
    {
        $response = new Response();
        $url = trim((string) $request->input('url', ''));

        // 参数校验
        if ($url === '') {
            $response->json([
                'success' => false,
                'error' => '缺少参数 url，请传入视频链接，例如：/api/parse.php?url=https://www.iqiyi.com/v_xxx.html',
                'usage' => [
                    'method' => 'GET / POST',
                    'params' => [
                        'url' => '必填，视频页面链接',
                        'callback' => '可选，JSONP 回调函数名',
                    ],
                    'example' => '/api/parse.php?url=https://www.iqiyi.com/v_1re8v439zmw.html',
                ],
            ], 400);
        }

        if (!preg_match('#^https?://#i', $url)) {
            $response->json([
                'success' => false,
                'error' => 'url 格式不正确，必须以 http:// 或 https:// 开头',
            ], 400);
        }

        // SSRF 防护：域名白名单校验
        if (!$this->isAllowedDomain($url)) {
            $response->json([
                'success' => false,
                'error' => '暂不支持该域名，支持平台：爱奇艺、腾讯视频、优酷、芒果TV、哔哩哔哩等',
                'supported_domains' => $this->config['allowed_domains'],
            ], 400);
        }

        // 执行解析
        try {
            $service = new VideoParserService([
                'parse_apis' => $this->config['parse_apis'],
                'iframe_players' => $this->config['iframe_players'],
                'timeout' => $this->config['http']['timeout'],
                'max_retries' => $this->config['http']['max_retries'],
                'user_agent' => $this->config['http']['user_agent'],
                'max_urls' => $this->config['parser']['max_urls'],
                'enable_quality_upgrade' => $this->config['parser']['enable_quality_upgrade'],
            ]);

            $result = $service->parseVideo($url);

            // JSONP 支持
            $callback = trim((string) $request->input('callback', ''));
            $response->json($result, 200, $callback !== '' ? $callback : null);
        } catch (\Throwable $e) {
            $response->json([
                'success' => false,
                'error' => '服务异常: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 健康检查
     *
     * @param Request $request
     * @param array   $params
     * @return void
     */
    public function health(Request $request, array $params): void
    {
        $response = new Response();
        $response->json([
            'success' => true,
            'app' => $this->config['app']['name'],
            'version' => $this->config['app']['version'],
            'framework_version' => \Core\App::VERSION,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 校验域名是否在白名单内
     *
     * @param string $url
     * @return bool
     */
    protected function isAllowedDomain(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = strtolower($host);
        foreach ($this->config['allowed_domains'] as $domain) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }

        return false;
    }
}
