<?php
/**
 * 全局辅助函数
 */

declare(strict_types=1);

if (!function_exists('e')) {
    /** HTML 转义输出 */
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('json_out')) {
    /** 输出 JSON 并结束 */
    function json_out(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Update-Token');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('request_json')) {
    /** 解析请求体 JSON */
    function request_json(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('normalize_name')) {
    /** 规范化剧名：去空白/标点，统一小写 */
    function normalize_name(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }
        $s = mb_strtolower($name, 'UTF-8');
        $s = preg_replace('/\s+/u', '', $s);
        $s = preg_replace('/[《》【】\[\]()（）:：·,，.。!！?？\'"“”\-—_]/u', '', $s);
        return $s ?? '';
    }
}

if (!function_exists('name_score')) {
    /** 剧名相似度打分 0~1 */
    function name_score(string $a, string $b): float
    {
        $na = normalize_name($a);
        $nb = normalize_name($b);
        if ($na === '' || $nb === '') {
            return 0;
        }
        if ($na === $nb) {
            return 1.0;
        }
        if (str_contains($na, $nb) || str_contains($nb, $na)) {
            return 0.9;
        }
        $strip = function (string $s): string {
            return (string)preg_replace('/第?[一二三四五六七八九十\d]+[季部]?$/u', '', $s);
        };
        $sa = $strip($na);
        $sb = $strip($nb);
        if ($sa === $sb) {
            return 0.85;
        }
        if (str_contains($sa, $sb) || str_contains($sb, $sa)) {
            return 0.7;
        }
        return 0.0;
    }
}

if (!function_exists('extract_episode')) {
    /** 从文本中提取集数（第2集 / EP02 / 02 / 第2话 等），取不到返回 null */
    function extract_episode(mixed $text): ?int
    {
        if ($text === null || $text === '') {
            return null;
        }
        $s = (string)$text;
        if (preg_match('/第\s*(\d+)\s*[集话]|(\d+)\s*[集话]/u', $s, $m)) {
            return (int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]);
        }
        if (preg_match('/\bep\.?\s*(\d+)\b|\bE(\d+)\b/iu', $s, $m)) {
            return (int)(($m[1] ?? '') !== '' ? $m[1] : $m[2]);
        }
        if (preg_match('/^\s*(\d{1,4})\s*$/', $s, $m)) {
            return (int)$m[1];
        }
        return null;
    }
}

if (!function_exists('random_token')) {
    function random_token(int $len = 24): string
    {
        return substr(bin2hex(random_bytes((int)ceil($len / 2))), 0, $len);
    }
}

if (!function_exists('now_sql')) {
    function now_sql(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('microtime_ms')) {
    function microtime_ms(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}

if (!function_exists('flash')) {
    /** 设置一次性提示 */
    function flash_set(string $type, string $msg): void
    {
        $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    }

    function flash_take(): ?array
    {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}