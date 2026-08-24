<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Git 自动更新器：
 *   从当前仓库远程 origin 拉取最新代码并合并（--ff-only），
 *   支持后台按钮 / CLI / cron / webhook 四种触发方式，操作结果写入 mxgj_update_logs。
 */
class Updater
{
    private Database $db;
    private string $root;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->root = dirname(__DIR__, 2);
    }

    private function git(string $args, ?string $cwd = null): array
    {
        $cmd = 'git -C ' . escapeshellarg($cwd ?? $this->root) . ' ' . $args . ' 2>&1';
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        return ['code' => $code, 'output' => implode("\n", $output)];
    }

    public function gitAvailable(): bool
    {
        exec('git --version 2>&1', $out, $code);
        return $code === 0;
    }

    public function isRepo(): bool
    {
        return is_dir($this->root . '/.git');
    }

    public function currentBranch(): string
    {
        $r = $this->git('rev-parse --abbrev-ref HEAD');
        $branch = trim($r['output']);
        return $branch === '' || $branch === 'HEAD' ? '' : $branch;
    }

    public function head(): string
    {
        $r = $this->git('rev-parse --short HEAD');
        return trim($r['output']);
    }

    public function remote(): string
    {
        $r = $this->git('remote get-url origin');
        return trim($r['output']);
    }

    /**
     * 检查更新（fetch 并对比本地与远程）
     */
    public function check(): array
    {
        $branch = $this->branchForUpdate();
        if ($branch === '') {
            return ['status' => 'failed', 'message' => '无法确定当前分支，请检查 git 配置'];
        }
        $fetch = $this->git('fetch origin --quiet');
        if ($fetch['code'] !== 0) {
            return ['status' => 'failed', 'message' => 'git fetch 失败: ' . $fetch['output']];
        }
        $local = $this->head();
        $r = $this->git('rev-parse --short origin/' . $branch);
        $remote = trim($r['output']);

        if ($local === $remote) {
            return ['status' => 'up-to-date', 'message' => '已是最新版本', 'local' => $local, 'remote' => $remote];
        }
        return ['status' => 'outdated', 'message' => '检测到远程有新版本', 'local' => $local, 'remote' => $remote];
    }

    private function branchForUpdate(): string
    {
        $saved = (string)$this->db->setting('git_branch', '');
        if ($saved !== '') {
            return $saved;
        }
        return $this->currentBranch();
    }

    /**
     * 执行更新
     * @param string $trigger admin|cli|webhook|cron
     */
    public function update(string $trigger = 'admin'): array
    {
        if (!file_exists(config('updater.lock_file'))) {
            @mkdir(dirname(config('updater.lock_file')), 0775, true);
        }
        $lock = fopen(config('updater.lock_file'), 'c');
        if (!$lock) {
            return ['status' => 'failed', 'message' => '无法创建更新锁'];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['status' => 'failed', 'message' => '已有更新任务正在进行中'];
        }

        $before = $this->head();
        try {
            if (!$this->gitAvailable()) {
                return ['status' => 'failed', 'message' => '服务器未安装 git'];
            }
            if (!$this->isRepo()) {
                return ['status' => 'failed', 'message' => '当前目录不是 git 仓库（缺少 .git）'];
            }

            $branch = $this->branchForUpdate();
            if ($branch === '') {
                return ['status' => 'failed', 'message' => '无法确定更新分支'];
            }

            $fetch = $this->git('fetch origin --quiet');
            if ($fetch['code'] !== 0) {
                $this->record('failed', 'fetch 失败: ' . $fetch['output'], $before, '', $trigger);
                return ['status' => 'failed', 'message' => 'git fetch 失败: ' . $fetch['output']];
            }

            $pull = $this->git('pull --ff-only origin ' . escapeshellarg($branch));
            $after = $this->head();

            if ($pull['code'] === 0) {
                $this->record('success', $pull['output'], $before, $after, $trigger);
                return ['status' => 'success', 'message' => '更新成功', 'before' => $before, 'after' => $after, 'output' => $pull['output']];
            }

            $this->record('failed', '合并失败(可能有本地改动或冲突): ' . $pull['output'], $before, $after, $trigger);
            return ['status' => 'failed', 'message' => '合并失败(可能有本地改动或冲突): ' . $pull['output']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function record(string $status, string $message, string $before, string $after, string $trigger): void
    {
        try {
            $this->db->insert('mxgj_update_logs', [
                'status' => $status,
                'message' => mb_substr($message, 0, 2000),
                'before_commit' => $before,
                'after_commit' => $after,
                'trigger' => $trigger,
            ]);
        } catch (\Throwable $e) {
            // 忽略日志写入失败
        }
    }

    /** 供后台展示的 git 环境信息 */
    public function info(): array
    {
        return [
            'gitAvailable' => $this->gitAvailable(),
            'isRepo' => $this->isRepo(),
            'branch' => $this->currentBranch(),
            'remote' => $this->remote(),
            'head' => $this->head(),
            'configuredBranch' => (string)$this->db->setting('git_branch', ''),
        ];
    }
}