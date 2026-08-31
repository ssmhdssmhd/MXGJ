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
        // 实测验证过：每个节点 download 完都会先验 PK 魔术头，非 zip 自动跳过
        return [
            '直连'           => 'https://github.com',
            'gh-proxy.com'   => 'https://gh-proxy.com/https://github.com',
            'fast.top'       => 'https://ghfast.top/https://github.com',
            'mirror.ghproxy' => 'https://mirror.ghproxy.com/https://github.com',
            'gh-proxy.net'   => 'https://gh-proxy.net/https://github.com',
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
        $skipReasons = [];
        foreach ($usable as $node) {
            $zipUrl = $node['prefix'] . "/{$repo['owner']}/{$repo['repo']}/archive/refs/heads/{$repo['branch']}.zip";
            if (self::download($zipUrl, $zipFile, 120)) {
                $sz = (int)@filesize($zipFile);
                if ($sz < 1000) {
                    $skipReasons[] = $node['name'] . ': 文件过小(' . $sz . 'B)';
                    @unlink($zipFile); continue;
                }
                // 关键：验 PK\x03\x04 魔术头（很多 mirror 会返回 HTML 错误页）
                if (!self::isValidZip($zipFile)) {
                    $skipReasons[] = $node['name'] . ': 非 zip（' . $sz . 'B，魔术头不对，可能是 HTML 错误页）';
                    @unlink($zipFile); continue;
                }
                $ok = true;
                $usedNode = $node['name'];
                break;
            }
            $skipReasons[] = $node['name'] . ': download() 返回 false';
            @unlink($zipFile);
        }
        if (!$ok) {
            $detail = $skipReasons !== [] ? ' · 尝试过的节点: ' . implode('; ', array_slice($skipReasons, 0, 3)) : '';
            self::rrmdir($tmpDir);
            @unlink($lockFile);
            return ['ok' => false, 'applied' => false, 'msg' => '下载源码包失败（所有 mirror 返回的都不是有效 zip）' . $detail, 'steps' => $steps, 'speed' => $speeds];
        }
        $steps[] = '由 ' . $usedNode . ' 下载成功，大小 ' . round(@filesize($zipFile) / 1024) . 'KB';

        // 4) 解压（多策略：ZipArchive → shell unzip 回退）
        $extract = self::extractZip($zipFile, $tmpDir);
        if (!$extract['ok']) {
            self::rrmdir($tmpDir);
            @unlink($lockFile);
            return ['ok' => false, 'applied' => false,
                    'msg' => '解压失败：' . $extract['msg'] . ($extract['details'] ? ' — ' . $extract['details'] : ''),
                    'steps' => $steps, 'speed' => $speeds];
        }
        $steps[] = '解压完成（' . $extract['method'] . '）：' . $extract['msg'];

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
            'env'        => self::diagnoseEnv(),
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

    /* ---------------- zip 解压加固 ---------------- */

    /** 检查文件是不是有效的 zip（PK\x03\x04 魔术头） */
    protected static function isValidZip(string $path): bool
    {
        if (!is_file($path) || (int)@filesize($path) < 20) return false;
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        $sig = @fread($fh, 4);
        @fclose($fh);
        return $sig === "PK\x03\x04";
    }

    /** ZipArchive 错误码 → 可读名称（未定义时返回 unknown-N） */
    protected static function zipErrorName(int $code): string
    {
        static $map = null;
        if ($map === null && class_exists('ZipArchive', false)) {
            $ref = new \ReflectionClass('ZipArchive');
            foreach ($ref->getConstants() as $n => $v) {
                if (strpos($n, 'ER_') === 0) { $map[(int)$v] = str_replace('ER_', '', $n); }
            }
        }
        return $map[$code] ?? ('unknown-' . $code);
    }

    /**
     * 解压 zip 到指定目录（多策略回退）
     *
     * 策略：
     *   1) 先验 PK\x03\x04 魔术头，非 zip 直接报错（通常是 mirror 返回了 HTML 错误页）
     *   2) ZipArchive（若 PHP 扩展可用）
     *   3) shell unzip（若系统有 unzip 命令）
     *
     * @param string $zipFile  zip 文件绝对路径
     * @param string $toDir    解压目标目录（会自动创建）
     * @return array{ok:bool, method:string, msg:string, details:string}
     */
    protected static function extractZip(string $zipFile, string $toDir): array
    {
        // --- 0) 魔术头验证 ---
        if (!self::isValidZip($zipFile)) {
            $sz = @filesize($zipFile);
            $head = '';
            if (is_file($zipFile) && $fh = @fopen($zipFile, 'rb')) {
                $head = bin2hex(@fread($fh, 8)); @fclose($fh);
            }
            // 尝试提取错误页面的 title（如果是 HTML）
            $htmlHint = '';
            if ($sz > 0 && ($fh = @fopen($zipFile, 'rb'))) {
                $chunk = @fread($fh, 2048); @fclose($fh);
                if (stripos($chunk, '<html') !== false || stripos($chunk, '<!doctype') !== false) {
                    if (preg_match('#<title[^>]*>([^<]{1,80})#i', $chunk, $m)) {
                        $htmlHint = ' · mirror 返回了 HTML 错误页：' . trim($m[1]);
                    } else {
                        $htmlHint = ' · mirror 返回了非 zip 内容（可能是 HTML 错误页）';
                    }
                }
            }
            return [
                'ok' => false, 'method' => 'magic-check',
                'msg' => 'zip 文件头无效（不是 PK 魔术头），大小 ' . $sz . ' 字节' . $htmlHint,
                'details' => 'first-8-bytes: ' . $head,
            ];
        }

        // --- 1) ZipArchive ---
        if (class_exists('ZipArchive', false)) {
            $zip = new \ZipArchive();
            $code = $zip->open($zipFile);
            if ($code === true) {
                @mkdir($toDir, 0777, true);
                if ($zip->extractTo($toDir)) {
                    $count = $zip->numFiles;
                    $zip->close();
                    return [
                        'ok' => true, 'method' => 'ZipArchive',
                        'msg' => "ZipArchive 解压成功（{$count} files）",
                        'details' => '',
                    ];
                }
                $extractErr = 'extractTo 返回 false';
                $zip->close();
            } else {
                $extractErr = 'ZipArchive::open 返回 ' . self::zipErrorName($code) . " (code={$code})";
            }
            // ZipArchive 失败 → 尝试 shell unzip
            if (self::shellUnzip($zipFile, $toDir)) {
                return [
                    'ok' => true, 'method' => 'shell-unzip(fallback)',
                    'msg' => 'ZipArchive 失败(' . $extractErr . ')，shell unzip 回退成功',
                    'details' => '',
                ];
            }
            return [
                'ok' => false, 'method' => 'ZipArchive+shell-unzip',
                'msg' => '解压全部失败。ZipArchive: ' . $extractErr . '；shell unzip 也失败',
                'details' => '',
            ];
        }

        // --- 2) 只有 shell unzip ---
        if (self::shellUnzip($zipFile, $toDir)) {
            return [
                'ok' => true, 'method' => 'shell-unzip',
                'msg' => 'shell unzip 解压成功（无 ZipArchive 扩展）',
                'details' => '',
            ];
        }

        return [
            'ok' => false, 'method' => 'shell-unzip',
            'msg' => '解压失败：PHP 无 ZipArchive 扩展且 shell unzip 不可用/失败',
            'details' => '请安装 php-zip 扩展: apt install php-zip 或 yum install php-pecl-zip',
        ];
    }

    /** shell unzip 回退解压（成功返回 true） */
    protected static function shellUnzip(string $zipFile, string $toDir): bool
    {
        if (!function_exists('exec')) return false;
        $unzipPath = self::findUnzipBinary();
        if ($unzipPath === '') return false;
        @mkdir($toDir, 0777, true);
        $cmd = escapeshellarg($unzipPath) . ' -oq ' . escapeshellarg($zipFile) . ' -d ' . escapeshellarg($toDir) . ' 2>&1';
        exec($cmd, $out, $retcode);
        return $retcode === 0;
    }

    /** 找系统 unzip 命令路径（找不到返回空串） */
    protected static function findUnzipBinary(): string
    {
        static $path = null;
        if ($path !== null) return $path;
        foreach (['/usr/bin/unzip', '/bin/unzip', '/usr/local/bin/unzip'] as $cand) {
            if (is_file($cand) && is_executable($cand)) { $path = $cand; return $path; }
        }
        if (function_exists('exec')) {
            $found = @exec('command -v unzip 2>/dev/null');
            if ($found && is_file($found)) { $path = $found; return $path; }
        }
        $path = '';
        return $path;
    }

    /**
     * 诊断 PHP 环境是否支持在线解压
     * admin.php 的 check_update 会把这段返回给前端展示
     *
     * @return array{ziparchive:bool,shell_unzip:bool,data_writable:bool,php_version:string,
     *             all_ok:bool,notes:string}
     */
    public static function diagnoseEnv(): array
    {
        $ziparchive = class_exists('ZipArchive', false);
        $shellUnzip = self::findUnzipBinary() !== '';
        $dataWritable = is_dir(MXGJ_DATA) && @is_writable(MXGJ_DATA);
        $allOk = $ziparchive || $shellUnzip;
        $notes = '';
        if (!$ziparchive && !$shellUnzip) {
            $notes = '❌ PHP 未安装 ZipArchive 扩展，系统也没有 unzip 命令 — 无法解压 zip，请安装 php-zip 或 unzip';
        } elseif (!$ziparchive && $shellUnzip) {
            $notes = '⚠️ PHP 未安装 ZipArchive 扩展，但系统有 unzip 命令 — 会用 shell 回退解压（可用）';
        } elseif ($ziparchive && !$shellUnzip) {
            $notes = '✅ ZipArchive 扩展可用';
        } else {
            $notes = '✅ ZipArchive + shell unzip 都可用（双保险）';
        }
        if (!$dataWritable) {
            $notes .= ' · ⚠️ data/ 目录不可写，更新时可能失败';
        }
        return [
            'ziparchive'     => $ziparchive,
            'shell_unzip'    => $shellUnzip,
            'data_writable'  => $dataWritable,
            'php_version'    => PHP_VERSION,
            'all_ok'         => $allOk && $dataWritable,
            'notes'          => $notes,
        ];
    }

    /* ---------------- 差异文件对比 ---------------- */

    /**
     * 下载远程 zip → 解压 → 对比本地与远程的文件清单
     *
     * 对比逻辑（与 Updater::run() 一致）：
     *   - 仅对比会被 update 覆盖的代码文件
     *   - 保留 config/、data/、.git（这些不参与对比）
     *   - 用 sha1 做内容对比（哈希相同视为无变化）
     *
     * @param int $maxFiles 最多返回多少条差异（避免文件太大超时）
     * @return array{ok:bool,added:array,modified:array,removed:array,
     *              total_local:int,total_remote:int,speed:array,msg:string}
     */
    public static function diffLocalRemote(int $maxFiles = 500): array
    {
        $mirrors = self::mirrors();
        $repo    = self::repo();
        $ignored = ['config', 'data', '.git']; // 对比中要跳过的目录

        // 1) 测速选节点
        $probeUrl = "/{$repo['owner']}/{$repo['repo']}/raw/{$repo['branch']}/version.json";
        $speeds   = [];
        $usable   = [];
        foreach ($mirrors as $name => $prefix) {
            $ms = self::speedMeter($prefix . $probeUrl);
            if ($ms > 0) { $speeds[$name] = round($ms, 1); $usable[] = ['name' => $name, 'prefix' => $prefix, 'ms' => $ms]; }
        }
        usort($usable, fn($a, $b) => $a['ms'] <=> $b['ms']);
        if ($usable === []) {
            return ['ok' => false, 'added' => [], 'modified' => [], 'removed' => [],
                    'total_local' => 0, 'total_remote' => 0, 'speed' => $speeds,
                    'msg' => '所有加速节点均不可达'];
        }

        // 2) 下载到临时目录（与 run() 不同，不设并发锁）
        $tmpDir  = MXGJ_DATA . '/upddiff_' . substr(bin2hex(random_bytes(4)), 0, 8);
        @mkdir($tmpDir, 0777, true);
        $zipFile = $tmpDir . '/src.zip';
        $ok      = false;
        foreach ($usable as $node) {
            $zipUrl = $node['prefix'] . "/{$repo['owner']}/{$repo['repo']}/archive/refs/heads/{$repo['branch']}.zip";
            if (self::download($zipUrl, $zipFile, 90)) {
                $sz = (int)@filesize($zipFile);
                if ($sz < 1000) { @unlink($zipFile); continue; }
                // 验 PK\x03\x04 魔术头（mirror 可能返回 HTML 错误页）
                if (!self::isValidZip($zipFile)) { @unlink($zipFile); continue; }
                $ok = true; break;
            }
            @unlink($zipFile);
        }
        if (!$ok) {
            self::rrmdir($tmpDir);
            return ['ok' => false, 'added' => [], 'modified' => [], 'removed' => [],
                    'total_local' => 0, 'total_remote' => 0, 'speed' => $speeds,
                    'msg' => '下载源码包失败（所有 mirror 返回的都不是有效 zip）'];
        }

        // 3) 解压（多策略：ZipArchive → shell unzip 回退）
        $extract = self::extractZip($zipFile, $tmpDir);
        if (!$extract['ok']) {
            self::rrmdir($tmpDir);
            return ['ok' => false, 'added' => [], 'modified' => [], 'removed' => [],
                    'total_local' => 0, 'total_remote' => 0, 'speed' => $speeds,
                    'msg' => '解压失败：' . $extract['msg'] . ($extract['details'] ? ' — ' . $extract['details'] : '')];
        }

        // 4) 找源码根（zip 内通常为 MXGJ-main/）
        $srcRoot = null;
        foreach (glob($tmpDir . '/[!.]*') ?: [] as $d) {
            if (is_dir($d) && is_file($d . '/index.php')) { $srcRoot = $d; break; }
        }
        if ($srcRoot === null) {
            self::rrmdir($tmpDir);
            return ['ok' => false, 'added' => [], 'modified' => [], 'removed' => [],
                    'total_local' => 0, 'total_remote' => 0, 'speed' => $speeds,
                    'msg' => '未在源码包中找到项目根目录'];
        }

        // 5) 收集文件清单（相对路径 + sha1）
        $localMap  = self::collectFileMap(MXGJ_ROOT, $ignored);
        $remoteMap = self::collectFileMap($srcRoot, $ignored);

        // 6) 对比
        $added    = [];  // 远程有、本地没有
        $modified = [];  // 两边都有但 hash 不同
        $removed  = [];  // 本地有、远程没有
        foreach ($remoteMap as $rel => $rh) {
            if (!isset($localMap[$rel])) {
                $added[] = ['path' => $rel, 'size' => $rh['size'], 'date' => $rh['date']];
            } elseif ($localMap[$rel]['hash'] !== $rh['hash']) {
                $modified[] = [
                    'path'         => $rel,
                    'local_size'   => $localMap[$rel]['size'],
                    'remote_size'  => $rh['size'],
                    'local_date'   => $localMap[$rel]['date'],
                    'remote_date'  => $rh['date'],
                ];
            }
        }
        foreach ($localMap as $rel => $lh) {
            if (!isset($remoteMap[$rel])) {
                $removed[] = ['path' => $rel, 'size' => $lh['size'], 'date' => $lh['date']];
            }
        }

        // 排序（路径字典序）
        $sort = function (&$arr) { usort($arr, fn($a, $b) => strcmp($a['path'], $b['path'])); };
        $sort($added); $sort($modified); $sort($removed);

        // 限制条数，避免返回太大
        $truncated = false;
        $totalDiff = count($added) + count($modified) + count($removed);
        if ($totalDiff > $maxFiles) {
            $added    = array_slice($added, 0, (int)($maxFiles * 0.35));
            $modified = array_slice($modified, 0, (int)($maxFiles * 0.5));
            $removed  = array_slice($removed, 0, $maxFiles - count($added) - count($modified));
            $truncated = true;
        }

        // 7) 清理临时目录
        self::rrmdir($tmpDir);

        return [
            'ok'              => true,
            'added'           => $added,
            'modified'        => $modified,
            'removed'         => $removed,
            'total_local'     => count($localMap),
            'total_remote'    => count($remoteMap),
            'total_diff'      => $totalDiff,
            'truncated'       => $truncated,
            'speed'           => $speeds,
            'msg'             => sprintf(
                '📁 差异预览：%d 个新增 / %d 个修改 / %d 个删除（本地共 %d 文件，远程共 %d 文件）%s',
                count($added), count($modified), count($removed),
                count($localMap), count($remoteMap),
                $truncated ? ' · 已截断（差异过多）' : ''
            ),
        ];
    }

    /**
     * 递归收集目录下所有文件：返回 [相对路径 => [hash, size, date]]
     *
     * @param string $root     根目录
     * @param array  $ignored  要跳过的顶层目录名（config、data、.git 等）
     * @return array<string, array{hash:string,size:int,date:string}>
     */
    protected static function collectFileMap(string $root, array $ignored = []): array
    {
        $map  = [];
        $root = rtrim($root, '/\\');
        if (!is_dir($root)) return $map;

        // 顶层先跳过 ignored 目录，不深入
        foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $top) {
            if (in_array($top, $ignored, true)) continue;
            self::walkFiles($root . '/' . $top, strlen($root) + 1, $map);
        }
        return $map;
    }

    /** walkFiles 递归辅助：把文件写入 $map */
    protected static function walkFiles(string $path, int $rootLen, array &$map): void
    {
        if (!file_exists($path)) return;
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
                self::walkFiles($path . '/' . $child, $rootLen, $map);
            }
        } elseif (is_file($path)) {
            $rel = substr($path, $rootLen);
            // 远程 zip 里是正斜杠，统一一下
            $rel = str_replace('\\', '/', $rel);
            $stat = @stat($path);
            // sha1_file 很快（1MB 文件 ~5ms），足够精确
            $map[$rel] = [
                'hash' => substr(@sha1_file($path) ?: md5($rel), 0, 16),  // 16 位足够区分
                'size' => (int)($stat['size'] ?? 0),
                'date' => date('Y-m-d H:i', $stat['mtime'] ?? 0),
            ];
        }
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