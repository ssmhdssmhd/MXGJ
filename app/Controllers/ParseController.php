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
                'enable_iframe_players' => $this->config['enable_iframe_players'],
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
     * 检测播放源接口（供配置前验证解析接口是否可用）
     *
     * 用法：
     *   GET /api.php/check?url=<视频链接>                    检测配置中所有接口
     *   GET /api.php/check?url=<视频链接>&api=<接口地址>      检测单个接口
     *   GET /api.php/check?url=<视频链接>&api=<接口1>,<接口2>  检测多个接口
     *
     * @param Request $request
     * @param array   $params
     * @return void
     */
    public function check(Request $request, array $params): void
    {
        $response = new Response();
        $url = trim((string) $request->input('url', ''));

        if ($url === '') {
            $response->json([
                'success' => false,
                'error' => '缺少参数 url，请传入测试视频链接',
                'usage' => [
                    'method' => 'GET',
                    'params' => [
                        'url' => '必填，测试视频页面链接',
                        'api' => '可选，待检测接口地址（多个用逗号分隔）；不传则检测配置中所有接口',
                    ],
                    'examples' => [
                        '/api.php/check?url=https://www.iqiyi.com/v_1re8v439zmw.html',
                        '/api.php/check?url=https://www.iqiyi.com/v_1re8v439zmw.html&api=https://jx.xxx.com/?url=',
                        '/api.php/check?url=https://www.iqiyi.com/v_1re8v439zmw.html&api=https://jx.a.com/?url=,https://jx.b.com/?url=',
                    ],
                ],
            ], 400);
        }

        if (!preg_match('#^https?://#i', $url)) {
            $response->json([
                'success' => false,
                'error' => 'url 格式不正确，必须以 http:// 或 https:// 开头',
            ], 400);
        }

        try {
            $service = new VideoParserService([
                'parse_apis' => $this->config['parse_apis'],
                'iframe_players' => $this->config['iframe_players'],
                'timeout' => $this->config['http']['timeout'],
                'max_retries' => 1,
                'user_agent' => $this->config['http']['user_agent'],
            ]);

            // 待检测接口：优先取 api 参数，否则检测配置中全部接口
            $apiInput = trim((string) $request->input('api', ''));
            if ($apiInput !== '') {
                $apis = array_values(array_filter(array_map('trim', explode(',', $apiInput))));
            } else {
                $apis = array_merge($this->config['parse_apis'], $this->config['iframe_players']);
            }

            if (!$apis) {
                $response->json([
                    'success' => false,
                    'error' => '没有可检测的接口（api 参数为空且配置中无接口）',
                ], 400);
            }

            $results = $service->detectApis($url, $apis);

            $summary = [
                'total' => count($results),
                'parse' => 0,
                'iframe' => 0,
                'unknown' => 0,
                'error' => 0,
            ];
            foreach ($results as $r) {
                $summary[$r['type']]++;
            }

            $response->json([
                'success' => true,
                'test_url' => $url,
                'summary' => $summary,
                'results' => $results,
                'time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $response->json([
                'success' => false,
                'error' => '服务异常: ' . $e->getMessage(),
            ], 500);
        }
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
