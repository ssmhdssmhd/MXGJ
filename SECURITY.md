# 安全策略 · Security Policy

如果发现了安全漏洞，请**不要**直接开 Issue（会暴露漏洞），联系维护者：

## 报告一个漏洞

请通过以下任一方式联系我们：

1. **GitHub Security Advisories**（推荐）：在仓库主页点 `Security` → `Report a vulnerability`
2. 或在 [Issue](https://github.com/ssmhdssmhd/MXGJ/issues) 前加 **[Security]** 标签

报告请包含：
- 漏洞类型与严重程度（Critical / High / Medium / Low）
- 影响的文件/组件/版本
- 复现步骤（PoC 如果方便）
- 可能的修复方向（可选）

我们会在 **48 小时内** 确认收到并回复初步处理计划。

## 已知的安全模型

| 组件 | 鉴权 | 说明 |
|------|------|------|
| `admin.php` | session + 密码（默认 `moxi123`，**务必修改**） | 后台入口 |
| `index.php` (v1.13 前) | 可选 `config/key.php` | `key.php` 返回空串则跳过 |
| `index.php` (当前) | 可选 `?key=` 参数 | 第一个 query 参数位置 |
| `player/api.php` | App 接口内 `require_key` + `api_key` | 后台「🎬 App接口」配置 |
| `player/index.php` | 无鉴权（播放器页面） | 纯前端 iframe 渲染 |
| `update.php` | `?key=` 升级密钥（默认 = admin 密码） | 可在后台单独配置 |

## 依赖与供应链

- 项目零 composer 依赖，无第三方 PHP 库
- GitHub Actions CI 会在 PR 时运行 `php -l` 语法检查 + 自测
- 建议生产环境启用 `disable_functions` 关闭 `exec/system/shell_exec` 等

## 安全版本历史

| 版本 | 修复 |
|------|------|
| v1.17.2 | 补齐 `player/api.php` 完整 key 鉴权（之前仅注释声明，实际无校验） |
| v1.17.0 | `mxgj_settings('app_api')` 参数被静默忽略，配置不生效 |

## 免责声明

本项目提供的媒体解析功能可能受到法律限制。请在合法合规的范围内使用。
