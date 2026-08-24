'use strict';
/**
 * 视频解析桌面工具 (Electron 主进程)
 * 由 Python 版 iqiyi_gui_modern.py / iqiyi_gui_simple.py 重写而来
 */

const { app, BrowserWindow, ipcMain, shell } = require('electron');
const path = require('path');
const VideoParser = require('../src/parser');

let mainWindow = null;
const parser = new VideoParser();

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1000,
    height: 720,
    minWidth: 760,
    minHeight: 560,
    title: '视频解析工具',
    backgroundColor: '#0f1419',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  mainWindow.loadFile(path.join(__dirname, 'index.html'));

  // 外部链接用系统浏览器打开
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

// IPC：解析视频（单条）
ipcMain.handle('parse:video', async (_event, url) => {
  const result = await parser.parseVideo(url, true);
  return result;
});

// IPC：解析视频（GUI 全量播放源）
ipcMain.handle('parse:gui', async (_event, url) => {
  return await parser.parseForGui(url);
});

// IPC：打开外部链接
ipcMain.handle('shell:open', (_event, url) => {
  shell.openExternal(url);
});

app.whenReady().then(() => {
  createWindow();

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});