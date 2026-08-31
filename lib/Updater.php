<?php
/**
 * 沫兮官替系统 - 自动更新器
 *
 * 从 GitHub 拉取最新代码并替换当前版本。面向国内网络优化：
 * 内置多个 GitHub 加速镜像，自动测速选取最快节点下载。
 *
 * 更新策略：
 *  1. 对每个加速节点做小文件测速，选出最快节点
 *  2. 用最快节点下载仓库 main 分支的 zip 压缩包
 *  3. 解压后：删除当前代码文件（lib/ tests/ *.php 及文档），
 *     但【保留 config/ 与 data/】（避免覆盖用户配置与缓存）
 *  4. 从新包复制代码，并将所有文件与子目录权限设为 777
 *
 * 仅允许从本系统仓库拉取，用于自更新。
 */

class Updater
{
    /** 仓库信息（可从 settings 覆盖） */
    public static function repo(): array
    {
        $st  = mxgj_settings();
        return [
            'owner'  => $st['repo_owner']     ?: 'ssmhdssmhd',
            'repo'   => $st['repo_name']      ?: 'MXGJ',
            'branch' => $st['repo_branch']    ?: 'main',
        ];
    }

    /** 加速节点（前缀与直连 github 组合） */
    public static function mirrors(): array
    {
        return [
            '直连'           => 'https://github.com',
            'fast.top'       => 'https://ghfast.top/https://github.com',
            'ghproxy.net'    => 'https://ghproxy.net/https://github.com',
            'gh-proxy.com'   => 'https://gh-proxy.com/https://github.com',
            'mirror.ghproxy' => 'https://mirror.ghproxy.com/https://github.com',
            '>ghproxy.com'   => 'https://ghproxy.com/https://github.com',
        ];
    }

    /**
     * 执行一次完整更新
     *
     * @param string $logKey 升级密钥（必须匹配 settings.updater_key 或 admin_password）
     * @return array{ok:bool, applied:bool, msg:string, steps:array, speed:array}
     */
    public static function run(string $logKey = '', bool $dry = false): array
    {
        $steps = [];
        $now   = time();

        // 0) 鉴权
        $st    = mxgj_settings();
        $upKey = isset($st['updater_key']) && $st['updater_key'] !== '' ? $st['updater_key'] : ($st['admin_password'] ?? '');
        if ($upKey !== '' && ($logKey === '' || $logKey !== $upKey)) {
            return ['ok' => false, 'applied' => false, 'msg' => '升级密钥不合法', 'steps' => [], 'speed' => []];
        }

        if ($dry) {
            $steps[] = '[dry] 仅测速，不执行下载与替换';
        } else {
            // 1) 并发锁，防止重复执行
            $lockFile = MXGJ_DATA . '/update.lock';
            if (is_file($lockFile) && (int)file_get_contents($lockFile) > $now - 120) {
                return ['ok' => false, 'applied' => false, 'msg' => '已有更新正在进行，请稍候', 'steps' => ['并发锁命中'], 'speed' => []];
            }
            file_put_contents($lockFile, $now);
        }

        $repo   = self::repo();
        $mirrors = self::mirrors();
        $steps[] = '仓库：' . $repo['owner'] . '/' . $repo['repo'] . '@' . $repo['branch'];

        // 2) 测速，选出最快可用节点
        $probeUrl = "/{$repo['owner']}/{$repo['repo']}/raw/{$repo['branch']}/version.json";
        $speeds   = [];
        $usable   = [];
        foreach ($mirrors as $name => $prefix) {
            $ms = self::speedMeter($prefix . $probeUrl);
            if ($ms > 0) {
                $speeds[$name] = round($ms, 1);
                $usable[] = ['name' => $name, 'prefix' => $prefix, 'ms' => $ms];
            }
        }
        usort($usable, function ($a, $b) { return $a['ms'] <=> $b['ms']; });
        if ($usable === []) {
            if (!$dry) { @unlink(MXGJ_DATA . '/update.lock'); }
            return ['ok' => false, 'applied' => false, 'msg' => '所有加速节点均不可达，请检查网络', 'steps' => $steps, 'speed' => $speeds];
        }
        $steps[] = '测速完成：最快为 ' . $usable[0]['name'] . '（' . $speeds[$usable[0]['name']] . 'ms）';

        if ($dry) {
            return ['ok' => true, 'applied' => false, 'msg' => '测速完成（dry 模式，未执行更新）', 'steps' => $steps, 'speed' => $speeds];
        }

        // 3) 依次尝试各节点下载 zip（最快优先）
        $tmpDir  = MXGJ_DATA . '/updtmp';
        if (is_dir($tmpDir)) {
            self::rrmdir($tmpDir);
        }
        @mkdir($tmpDir, 0777, true);

        $zipFile = $tmpDir . '/src.zip';
        $ok = false;
        $usedNode = '';
        foreach ($usable as $node) {
            $zipUrl = $node['prefix'] . "/{$repo['owner']}/{$repo['repo']}/archive/refs/heads/{$repo['branch']}.zip";
            if (self::download($zipUrl, $zipFile, 120)) {
                if ((int)@filesize($zipFile) > 1000) {
                    $ok = true;
                    $usedNode = $node['name'];
                    break;
                }
            }
            @unlink($zipFile);
        }
        if (!$ok) {
            self::rrmdir($tmpDir);
            @unlink($lockFile);
            return ['ok' => false, 'applied' => false, 'msg' => '下载源码包失败', 'steps' => $steps, 'speed' => $speeds];
        }
        $steps[] = '由 ' . $usedNode . ' 下载成功，大小 ' . round(@filesize($zipFile) / 1024) . 'KB';

        // 4) 解压
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            self::rrmdir($tmpDir);
            @unlink($lockFile);
            return ['ok' => false, 'applied' => false, 'msg' => '解压失败', 'steps' => $steps, 'speed' => $speeds];
        }
        $zip->extractTo($tmpDir);
        $zip->close();
        $steps[] = '解压完成';

        // 5) 定位源码根目录（zip 内通常为 MXGJ-main/）
        $srcRoot = null;
        $dirs = glob($tmpDir . '/[!.]*') ?: [];
        foreach ($dirs as $d) {
            if (is_dir($d) && is_file($d . '/index.php')) {
                $srcRoot = $d;
                break;
            }
        }
        if ($srcRoot === null) {
            self::rrmdir($tmpDir);
            @unlink($lockFile);
            return ['ok' => false, 'applied' => false, 'msg' => '未在源码包中找到项目根目录', 'steps' => $steps, 'speed' => $speeds];
        }

        // 6) 替换：保留 config/、data/、.git，其余代码文件整体覆盖
        $srcItems = array_diff(scandir($srcRoot) ?: [], ['.', '..']);
        foreach ($srcItems as $item) {
            $lt = MXGJ_ROOT . '/' . $item;      // 本地同名路径
            $rt = $srcRoot . '/' . $item;       // 新源码路径
            if ($item === '.git') {
                continue;
            }
            if (in_array($item, ['config', 'data'], true)) {
                continue; // 保留本地配置与缓存
            }
            // 删除旧版本该文件/目录
            if (file_exists($lt)) {
                if (is_dir($lt) && !is_link($lt)) { self::rrmdir($lt); } else { @unlink($lt); }
            }
            // 复制新版本
            if (is_dir($rt)) {
                self::rcopy($rt, $lt);
            } else {
                @mkdir(dirname($lt), 0777, true);
                @copy($rt, $lt);
            }
        }
        $steps[] = '已替换代码文件（保留 config/ 与 data/）';

        // 7) 授予 777 权限
        self::chmodAll(MXGJ_ROOT);
        $steps[] = '已将文件与子目录权限设为 0777';

        // 7.5) ⭐ 关键：清 opcache + 运行时数据
        // PHP-FPM 的 opcache 是"更新了代码但不生效"的头号元凶
        // 文件替换完立刻清，让下一次 PHP 请求加载新代码
        if (function_exists('mxgj_purge_runtime')) {
            $purged = mxgj_purge_runtime();
            $steps[] = '已清理缓存 + opcache reset（items=' . ($purged['items'] ?? 0) . ')';
        }
        if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
            @opcache_reset();
            $steps[] = 'PHP opcache 已重置';
        }

        // 8) 清理
        @unlink($zipFile);
        self::rrmdir($tmpDir);
        @unlink($lockFile);

        return [
            'ok' => true, 'applied' => true,
            'msg' => '更新成功，已替换为新版本',
            'steps' => $steps, 'speed' => $speeds,
        ];
    }

    /* ---------------- 版本检测 ---------------- */

    /**
     * 获取本地当前版本
     * 优先读 version.json，fallback 到 MXGJ_VERSION 常量
     */
    public static function currentVersion(): string
    {
        $vfile = MXGJ_ROOT . '/version.json';
        if (is_file($vfile)) {
            $data = json_decode(@file_get_contents($vfile), true);
            if (is_array($data) && !empty($data['version'])) {
                return (string)$data['version'];
            }
        }
        return defined('MXGJ_VERSION') ? MXGJ_VERSION : '0.0.0';
    }

    /**
     * 从 GitHub 拉取最新版本号（依次尝试多个加速节点）
     *
     * @return array{ok:bool,version:string,release?:string,msg?:string,node?:string}
     */
    public static function latestVersion(): array
    {
        $repo   = self::repo();
        $mirrors = self::mirrors();
        $probe  = "/{$repo['owner']}/{$repo['repo']}/raw/{$repo['branch']}/version.json";

        // 直连优先（测速用的是 mirror，但 version.json 体积很小直连也快，
        // 避免 mirror 挂了就完全没法检查更新）
        $order = ['直连' => $mirrors['直连']];
        foreach ($mirrors as $k => $v) { if ($k !== '直连') $order[$k] = $v; }

        foreach ($order as $name => $prefix) {
            $url  = $prefix . $probe;
            $body = self::fetchRaw($url, 5);
            if ($body === '' || $body === null) continue;
            $json = json_decode($body, true);
            if (!is_array($json)) continue;
            $v = trim((string)($json['version'] ?? ''));
            if ($v === '') continue;
            return [
                'ok'      => true,
                'version' => $v,
                'release' => trim((string)($json['release'] ?? '')),
                'node'    => $name,
                'data'    => $json,
            ];
        }

        return [
            'ok'      => false,
            'version' => '',
            'msg'     => '所有加速节点均不可达，无法获取最新版本',
        ];
    }

    /**
     * 比较本地与 GitHub 版本，判断是否需要更新
     *
     * @return array{local:string,latest:string,has_update:bool,need_update:bool|'same'|'older'|'newer',meta:array,msg:string}
     */
    public static function check(): array
    {
        $local  = self::currentVersion();
        $remote = self::latestVersion();

        $result = [
            'local'      => $local,
            'latest'     => $remote['ok'] ? $remote['version'] : $local,
            'has_update' => false,
            'need_update' => 'same',
            'meta'       => [
                'release' => $remote['release'] ?? '',
                'node'    => $remote['node'] ?? '',
                'ok'      => $remote['ok'],
                'err'     => $remote['msg'] ?? '',
            ],
            'msg'        => '',
        ];

        if (!$remote['ok']) {
            $result['msg'] = '⚠️ 无法连接 GitHub（' . ($remote['msg'] ?? '未知错误') . '），当前 v' . $local;
            return $result;
        }

        // 版本比较：把 v1.17.9 转为 1.17.9 再 version_compare
        $l = ltrim($local, 'vV');
        $r = ltrim($remote['version'], 'vV');
        $cmp = version_compare($l, $r);
        if ($cmp === -1) {
            $result['has_update'] = true;
            $result['need_update'] = 'older';
            $result['msg'] = '🎉 有新版本！当前 v' . $local . ' → 最新 v' . $remote['version'];
        } elseif ($cmp === 1) {
            $result['need_update'] = 'newer';
            $result['msg'] = '🚀 当前 v' . $local . ' 已是最新（比 GitHub main 更新）';
        } else {
            $result['msg'] = '✅ 当前已是最新版本 v' . $local;
        }
        return $result;
    }

    /** 直接 fetch 一个 URL 返回 body（失败返回空串） */
    protected static function fetchRaw(string $url, int $timeout = 5): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'MXGJ-Updater/' . self::currentVersion(),
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return ($err === '' && is_string($body)) ? $body : '';
    }

    /* ---------------- 工具方法 ---------------- */

    /** 测速：请求小文件并返回耗时(ms)，失败返回 -1 */
    protected static function speedMeter(string $url): float
    {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'MXGJ-Updater',
            CURLOPT_NOBODY         => false,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '' || !is_string($body) || $body === '') {
            return -1;
        }
        return (microtime(true) - $t0) * 1000;
    }

    /** 下载文件到目标 */
    protected static function download(string $url, string $dest, int $timeout = 120): bool
    {
        $fp = fopen($dest, 'w');
        if ($fp === false) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 5,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_SSL_VERIFYHOST  => false,
            CURLOPT_USERAGENT       => 'MXGJ-Updater',
            CURLOPT_ENCODING        => '',
        ]);
        $ok    = curl_exec($ch);
        $err   = curl_error($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        return $ok !== false && $err === '' && $http === 200;
    }

    /** 递归删除 */
    protected static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            if (is_dir($p) && !is_link($p)) { self::rrmdir($p); } else { @unlink($p); }
        }
        @rmdir($dir);
    }

    /** 递归复制 */
    protected static function rcopy(string $src, string $dst): void
    {
        @mkdir($dst, 0777, true);
        foreach (array_diff(scandir($src) ?: [], ['.', '..']) as $f) {
            $s = $src . '/' . $f;
            $d = $dst . '/' . $f;
            if (is_dir($s)) {
                self::rcopy($s, $d);
            } else {
                @mkdir(dirname($d), 0777, true);
                @copy($s, $d);
            }
        }
    }

    /** 递归设置 777 权限（跳过 .git） */
    protected static function chmodAll(string $dir): void
    {
        @chmod($dir, 0777);
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            if ($f === '.git' || $f === '.gitignore') {
                continue;
            }
            if (is_dir($p)) {
                self::chmodAll($p);
            } else {
                @chmod($p, 0777);
            }
        }
    }
}