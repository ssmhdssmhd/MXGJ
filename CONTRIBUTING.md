# 贡献指南 · Contributing to MXGJ

首先感谢你花时间为 MXGJ 做出贡献！❤️ 任何形式的贡献都欢迎。

## 🛣️ 贡献路径

| 类型 | 操作 |
|------|------|
| 🐛 发现 Bug | [Issue · Bug Report](https://github.com/ssmhdssmhd/MXGJ/issues/new?template=bug_report.md) |
| ✨ 新功能 | [Issue · Feature Request](https://github.com/ssmhdssmhd/MXGJ/issues/new?template=feature_request.md) |
| 📖 文档 | 直接提 PR |
| 🔧 代码修复 | Fork → 改 → PR（见下） |

## 🚀 本地开发环境

```bash
# 1. Clone
git clone https://github.com/ssmhdssmhd/MXGJ.git
cd MXGJ

# 2. PHP 内置服务器（PHP 7.4+，推荐 8.1+）
php -S 0.0.0.0:8080 -t .

# 3. 自测
php tests/run_test.php
```

## 📝 提交规范（Conventional Commits）

```
feat:     新功能
fix:      Bug 修复
docs:     文档更新
style:    代码格式（不影响功能）
refactor: 重构（既不是 feat 也不是 fix）
perf:     性能优化
test:     新增或更新测试
chore:    构建/工具变更
```

示例：`feat: 新增 App 接口配置 tab` / `fix: resolve cache TTL not applied`

## 🔀 PR 流程

1. Fork 本仓库
2. 创建功能分支：`git checkout -b feat/amazing-feature`
3. 提交变更：`git commit -m 'feat: add amazing feature'`
4. 推送分支：`git push origin feat/amazing-feature`
5. 开 PR，目标 `main` 分支

## ✅ PR Checklist

- [ ] 代码通过 `php -l` 语法检查
- [ ] 本地 `php tests/run_test.php` 自测全部 PASS
- [ ] 新增功能有必要的注释
- [ ] 破坏性变更在 CHANGELOG.md 里记录
- [ ] `git diff` 不包含 `.bak` 文件或临时数据（如 `data/cookies/*.txt`）

## 🎨 代码风格

- PHP 8.1+ 可用类型声明则用
- 缩进 **4 空格**（`.editorconfig` 已配置）
- 所有入口文件 `require_once __DIR__ . '/../lib/bootstrap.php';`
- bootstrap 防重复加载守卫：`MXGJ_BOOTSTRAP_LOADED`

## ❓ 需要帮助？

先看 [SUPPORT.md](SUPPORT.md)，或直接 [开 Issue](https://github.com/ssmhdssmhd/MXGJ/issues)。
