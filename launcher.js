'use strict';
/**
 * Electron/Node 统一启动入口
 * - 带 --gui 参数：启动桌面 GUI（Electron）
 * - 否则：启动视频解析 API 服务（Express）
 *
 * 用法：
 *   api:  node src/server.js  或  npm start
 *   gui:  npx electron . --gui 或  npm run gui
 */
if (process.argv.includes('--gui')) {
  require('./gui/main');
} else {
  require('./src/server');
}