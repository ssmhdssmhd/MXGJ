# 支持 · Support

## ❓ FAQ

**Q: 后台默认密码是什么？**
A: `moxi123`。**登录后请立即修改**（设置 → 管理员密码）。

**Q: 部署需要数据库吗？**
A: 不需要。纯 JSON 文件存储（`config/*.json` + `data/`），PHP 环境即可。

**Q: 支持哪些官方视频平台？**
A: 腾讯视频 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / PPTV。其他平台可自行扩展 `lib/LinkParser.php`。

**Q: 前台返回的 m3u8 地址会过期吗？**
A: 会，通常几分钟到几小时。这是资源站的反爬策略，系统每次会实时向资源站拉取最新地址。

**Q: 为什么搜索不到结果？**
A: 常见原因：
  1. 资源站心跳健康检查没过（后台看「资源站健康状态」）
  2. 该剧集还未在资源站上架
  3. 某些资源站需要验证码（v1.16.7 已支持手动解锁）

**Q: 如何让 APP（如 TVBox）调用？**
A: 打开后台「🎬 App接口」tab，开启接口后使用预览区的路径即可。key 放在 URL 最前面。

**Q: 可以离线部署吗？**
A: 可以。整个项目不依赖外部 CDN，所有 JS/CSS 都在 admin.php 内。

## 🐛 报 Bug

- [Bug Report 模板](https://github.com/ssmhdssmhd/MXGJ/issues/new?template=bug_report.md)
- 请附上：PHP 版本 / 环境（Nginx / Apache / 内置）/ 报错截图或日志 / 复现步骤

## ✨ 提新功能

- [Feature Request 模板](https://github.com/ssmhdssmhd/MXGJ/issues/new?template=feature_request.md)

## 💬 讨论

直接在 [Discussions](https://github.com/ssmhdssmhd/MXGJ/discussions) 发帖。

## 💰 赞助

如果本项目对你有帮助，可以点仓库主页的 **Sponsor** 按钮（GitHub Sponsors）。

感谢每一位支持 MXGJ 的朋友 ❤️
