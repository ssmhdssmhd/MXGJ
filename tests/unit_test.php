<?php
/**
 * 核心逻辑自测脚本（不依赖外部网络）
 * 用法：php tests/unit_test.php
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Services\VideoParserService;

$pass = 0;
$fail = 0;

function check($name, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] $name\n";
    } else {
        $fail++;
        echo "  [FAIL] $name $extra\n";
    }
}

echo "== 框架版本 ==\n";
check('App 版本 0.0.1', \Core\App::VERSION === '0.0.1', 'got ' . \Core\App::VERSION);

echo "== extractVideoId ==\n";
$p = new VideoParserService();
check('爱奇艺', $p->extractVideoId('https://www.iqiyi.com/v_1re8v439zmw.html') === 'iqiyi_1re8v439zmw');
check('腾讯路径', $p->extractVideoId('https://v.qq.com/x/cover/mzc00200abc.html') === 'qq_mzc00200abc');
check('腾讯vid参数', $p->extractVideoId('https://v.qq.com/x/player.html?vid=abc123') === 'qq_abc123');
check('优酷', $p->extractVideoId('https://v.youku.com/v_show/id_XNDYyMDAw.html') === 'youku_XNDYyMDAw');
check('芒果', $p->extractVideoId('https://www.mgtv.com/v/123456.html') === 'mgtv_123456');
check('通用id参数', $p->extractVideoId('https://example.com/watch?id=xyz789') === 'generic_xyz789');

echo "== isValidVideoUrl ==\n";
check('m3u8', $p->isValidVideoUrl('https://a.com/play/1.m3u8') === true);
check('mp4', $p->isValidVideoUrl('https://a.com/video.mp4') === true);
check('短链接', $p->isValidVideoUrl('abc') === false);
check('含play关键词', $p->isValidVideoUrl('https://a.com/player?x=1') === true);

echo "== parseMasterM3u8Variants ==\n";
$m3u8 = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1280000,RESOLUTION=1280x720,NAME=\"720p\"\n720/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=4000000,RESOLUTION=3840x2160,NAME=\"4K\"\n4k/index.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=2000000,RESOLUTION=1920x1080,NAME=\"1080p\"\n1080/index.m3u8\n";
$variants = $p->parseMasterM3u8Variants($m3u8);
check('解析出3个变体', count($variants) === 3, 'got ' . count($variants));
check('变体带宽', $variants[0]['bandwidth'] === 1280000);
check('变体分辨率', $variants[1]['resolution'] === [3840, 2160]);
check('变体URL', $variants[2]['url'] === '1080/index.m3u8');

echo "== selectBestM3u8Variant (mock via subclass) ==\n";
$mock = new class() extends VideoParserService {
    public $mockBody = '';
    public function httpGet($url, $timeout = null, array $extraHeaders = []) {
        return $this->mockBody;
    }
};
$mock->mockBody = $m3u8;
$best = $mock->selectBestM3u8Variant('https://cdn.example.com/master.m3u8', 'https://www.iqiyi.com/');
check('选择4K变体', $best === 'https://cdn.example.com/4k/index.m3u8', 'got ' . $best);

echo "== resolveUrl (via selectBestM3u8Variant relative) ==\n";
$mock->mockBody = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000,RESOLUTION=1920x1080\n/path/1080.m3u8\n";
$best2 = $mock->selectBestM3u8Variant('https://cdn.example.com/v/master.m3u8', '');
check('根相对路径', $best2 === 'https://cdn.example.com/path/1080.m3u8', 'got ' . $best2);

$mock->mockBody = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000,RESOLUTION=1920x1080\nsub/1080.m3u8\n";
$best3 = $mock->selectBestM3u8Variant('https://cdn.example.com/v/master.m3u8', '');
check('相对路径', $best3 === 'https://cdn.example.com/v/sub/1080.m3u8', 'got ' . $best3);

$mock->mockBody = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000,RESOLUTION=1920x1080\n//cdn2.example.com/x.m3u8\n";
$best4 = $mock->selectBestM3u8Variant('https://cdn.example.com/v/master.m3u8', '');
check('协议相对路径', $best4 === 'https://cdn2.example.com/x.m3u8', 'got ' . $best4);

echo "== extractPlayUrls ==\n";
$html = '<html><body><script>var x={"url":"https://a.com/1.m3u8","playUrl":"https://b.com/2.mp4"};</script><iframe src="https://p.com/player?id=1"></iframe><video src="https://c.com/3.mp4"></video></body></html>';
$urls = $p->extractPlayUrls($html);
check('提取到播放源', count($urls) >= 3, 'got ' . count($urls));

echo "\n结果: $pass 通过, $fail 失败\n";
exit($fail > 0 ? 1 : 0);
