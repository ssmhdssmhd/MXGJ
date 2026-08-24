<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 数据库封装（PDO），同时支持 MySQL/MariaDB 与 SQLite。
 */
class Database
{
    private PDO $pdo;
    private string $driver;

    public function __construct(array $dbConfig)
    {
        $driver = strtolower((string)($dbConfig['driver'] ?? 'mysql'));
        $this->driver = $driver;

        try {
            if ($driver === 'sqlite') {
                $file = $dbConfig['sqlite'] ?? '';
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $this->pdo = new PDO('sqlite:' . $file, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                // 开启外键/并发支持
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $dbConfig['host'] ?? '127.0.0.1',
                    (int)($dbConfig['port'] ?? 3306),
                    $dbConfig['name'] ?? 'mxgj'
                );
                $this->pdo = new PDO($dsn, $dbConfig['user'] ?? '', $dbConfig['pass'] ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $this->pdo->exec('SET NAMES utf8mb4');
            }
        } catch (PDOException $e) {
            throw new RuntimeException('数据库连接失败: ' . $e->getMessage());
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** 执行 SQL（返回受影响行数） */
    public function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** 查询多行 */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** 查询单行 */
    public function first(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 查询单值 */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->first($sql, $params);
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    /** 插入并返回自增 ID */
    public function insert(string $table, array $data): int
    {
        $fields = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`,`', $fields),
            implode(',', array_fill(0, count($fields), '?'))
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /** 更新 */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $field) {
            $sets[] = "`$field` = ?";
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $stmt = $this->pdo->prepare(sprintf('DELETE FROM `%s` WHERE %s', $table, $where));
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** 读取系统设置 */
    public function setting(string $key, mixed $default = null): mixed
    {
        $v = $this->value('SELECT `v` FROM mxgj_settings WHERE `k` = ?', [$key]);
        return $v === null ? $default : $v;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $exists = $this->value('SELECT COUNT(*) FROM mxgj_settings WHERE `k` = ?', [$key]) > 0;
        if ($exists) {
            $this->update('mxgj_settings', ['v' => (string)$value], '`k` = ?', [$key]);
        } else {
            $this->insert('mxgj_settings', ['k' => $key, 'v' => (string)$value]);
        }
    }
}