'use strict';
// 预加载脚本：安全地暴露 IPC 能力给渲染进程
const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('api', {
  parseVideo: (url) => ipcRenderer.invoke('parse:video', url),
  parseGui: (url) => ipcRenderer.invoke('parse:gui', url),
  openExternal: (url) => ipcRenderer.invoke('shell:open', url),
});