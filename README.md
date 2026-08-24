# 沫兮官替系统 (MXGJ)

输入**官方视频链接**，自动多线程检索后台配置的资源站（苹果CMS/海洋CMS `provide/vod` 接口），返回匹配到的可替换直链（`m3u8`/`mp4`），以 JSON 形式输出用于"官替"。

```
输入:  https://m.v.qq.com/x/m/play?cid=mzc00200zx8psx0&vid=k4102szvyce   (庆余年 第2集)
输出:  { "code": 200, "url": "http://资源站域名/play/.../02.m3u8", ... }
```

## 功能特性

- **官方链接解析**：自动识别腾讯视频 / 爱奇艺 / 优酷 / 芒果TV / 哔哩哔哩 / 搜狐 / PPTV / 乐视 等平台，抽取 `cid` / `vid` / `BV号` / 专辑ID 等标识
- **剧名与集数识别**（三级策略）：
  1. 请求参数显式指定 `name` / `ep`
  2. `config/nameMap.json` 标识→剧名+集数映射表
  3. 抓取官方页面 `<title>` 兜底解析
- **多线程搜索**：对后台配置的所有资源站**并发**检索（可配置并发数，I/O 密集型异步多线程池，避免打爆资源站）
- **集数精确匹配**：解析资源站播放列表 `第02集$https://...m3u8#第03集$https://...m3u8`，精准命中目标集数
- **JSON 输出**：命中返回 `code=200` 与 `url`，未命中返回 `code=404` 并附带各资源站检索明细
- **资源站管理接口**：增 / 删 / 改 / 测连通性，运行时修改自动落盘到配置文件
- **Web 前端**：浏览器打开即用，输入官方链接一键官替

## 快速开始

环境要求：Node.js >= 18（无需任何第三方依赖，原生 `fetch` + `http`）。

```bash
npm start          # 启动服务，默认 http://0.0.0.0:3000
npm run mock       # (可选) 启动本地 Mock 资源站，用于离线体验/测试
npm test           # 运行端到端测试（Mock 资源站 + 官替接口 + 断言）
```

启动后浏览器访问 http://127.0.0.1:3000 使用前端页面。

## 接口文档

### GET/POST `/api/vod` — 官替核心接口

参数（GET query 或 POST JSON body 均支持）：

| 参数 | 必填 | 说明 |
| --- | --- | --- |
| `url` | 是 | 官方视频链接 |
| `name` | 否 | 剧名，覆盖自动识别 |
| `ep` | 否 | 集数，覆盖自动识别 |

命中返回：

```json
{
  "code": 200,
  "url": "http://127.0.0.1:26531/play/qingyu/02.m3u8",
  "msg": "success",
  "data": {
    "name": "庆余年",
    "episode": 2,
    "platform": "tencent",
    "platformLabel": "腾讯视频",
    "matchedSite": "本地Mock资源站(测试用)",
    "playFrom": "m3u8",
    "totalEpisodes": 4,
    "candidates": [
      { "site": "本地Mock资源站(测试用)", "url": "http://.../02.m3u8", "episode": 2, "costMs": 19 }
    ],
    "costMs": 19
  }
}
```

未命中返回 `code=404`（附各资源站失败原因）；参数错误返回 `code=400`。

示例：

```bash
curl "http://127.0.0.1:3000/api/vod?url=https%3A%2F%2Fm.v.qq.com%2Fx%2Fm%2Fplay%3Fcid%3Dmzc00200zx8psx0%26vid%3Dk4102szvyce"
```

### 资源站配置管理

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/config/resources` | 查看全部资源站配置 |
| POST | `/api/config/resources` | 新增资源站（body: `{api,name,timeout,enabled}`） |
| PUT | `/api/config/resources/:id` | 修改资源站 |
| DELETE | `/api/config/resources/:id` | 删除资源站 |
| GET | `/api/sites/test` | 测试所有启用资源站的连通性 |
| GET | `/api/health` | 健康检查 |

## 配置说明

### 资源站 `config/resources.json`

```json
{
  "concurrency": 8,
  "defaultTimeout": 8000,
  "sites": [
    {
      "id": "site_a",
      "name": "我的资源站",
      "api": "https://你的资源站域名.com/api.php/provide/vod/",
      "timeout": 8000,
      "enabled": true
    }
  ]
}
```

- `api`：苹果CMS / 海洋CMS 的 `provide/vod` 接口地址，系统自动追加 `?ac=detail&wd=剧名`
- `enabled: false` 的资源站不参与搜索
- 内置了一个指向本地 Mock 的测试资源站；两个示例资源站默认为关闭状态，把域名替换成你的真实资源站后改为 `enabled: true` 即可

### 剧名映射 `config/nameMap.json`

将官方链接标识（`cid`/`vid`/`BV号` 等）映射到剧名与集数，命中后无需抓取官方页面：

```json
{
  "tencent": {
    "mzc00200zx8psx0": { "name": "庆余年", "episode": 2 }
  }
}
```

`episode` 留空或为 0 时默认取第一集。

## 项目结构

```
moxi-guantis/
├── config/
│   ├── resources.json      # 资源站配置
│   └── nameMap.json        # 官方标识 → 剧名/集数 映射
├── public/
│   └── index.html          # Web 前端页面
├── src/
│   ├── server.js           # HTTP 服务 / 路由入口
│   └── lib/
│       ├── parser.js           # 官方链接解析（多平台）
│       ├── nameResolver.js     # 剧名/集数识别
│       ├── resourceSearcher.js # 资源站多线程搜索 + 播放列表解析
│       ├── concurrency.js      # 并发池 / 带超时 fetch
│       ├── configStore.js      # 配置读写
│       └── utils.js / logger.js
└── test/
    ├── mock-resource.js    # 本地 Mock 资源站（苹果CMS 接口模拟）
    └── run-test.js         # 端到端测试
```

## 版本日志

详见 [CHANGELOG.md](./CHANGELOG.md)。

## License

MIT
