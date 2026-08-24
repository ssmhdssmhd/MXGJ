<?php

declare(strict_types=1);

namespace App\Core;

/**
 * 后台登录认证（Session）。
 */
class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('MXGJ_ADMIN');
            session_start();
        }
    }

    public static function login(Database $db, string $username, string $password): bool
    {
        $row = $db->first('SELECT * FROM mxgj_admin WHERE username = ? LIMIT 1', [$username]);
        if ($row === null) {
            return false;
        }
        // 兼容：已加密哈希 / 明文默认密码（首次部署引导用户修改）
        $ok = password_verify($password, $row['password_hash']);
        if (!$ok && hash_equals($row['password_hash'], $password)) {
            $ok = true;
        }
        if (!$ok) {
            return false;
        }
        $_SESSION['admin_id'] = (int)$row['id'];
        $_SESSION['admin_name'] = $row['username'];
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    public static function user(): string
    {
        return (string)($_SESSION['admin_name'] ?? '');
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** 修改后台密码 */
    public static function changePassword(Database $db, string $newPass): bool
    {
        if (strlen($newPass) < 6) {
            return false;
        }
        return $db->update('mxgj_admin', ['password_hash' => password_hash($newPass, PASSWORD_DEFAULT)], 'id = ?', [$_SESSION['admin_id']]) >= 0;
    }
}