'use strict';

const fs = require('fs');
const path = require('path');
const logger = require('./logger');

const CONFIG_DIR = path.resolve(__dirname, '../../config');
const RESOURCES_FILE = path.join(CONFIG_DIR, 'resources.json');
const NAMEMAP_FILE = path.join(CONFIG_DIR, 'nameMap.json');

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    logger.error(`读取配置文件失败(${file}): ${e.message}`);
    return null;
  }
}

function writeJson(file, data) {
  try {
    fs.writeFileSync(file, JSON.stringify(data, null, 2), 'utf8');
    return true;
  } catch (e) {
    logger.error(`写入配置文件失败(${file}): ${e.message}`);
    return false;
  }
}

class ConfigStore {
  constructor() {
    const resources = readJson(RESOURCES_FILE) || { concurrency: 8, defaultTimeout: 8000, sites: [] };
    this.resources = {
      concurrency: resources.concurrency || 8,
      defaultTimeout: resources.defaultTimeout || 8000,
      sites: Array.isArray(resources.sites) ? resources.sites : [],
    };
    this.nameMap = readJson(NAMEMAP_FILE) || {};
    logger.info(`配置加载完成: 资源站=${this.resources.sites.length} 个(启用 ${this.enabledSites().length} 个)`);
  }

  sites() {
    return this.resources.sites;
  }

  enabledSites() {
    return this.resources.sites.filter((s) => s.enabled !== false);
  }

  concurrency() {
    return this.resources.concurrency;
  }

  defaultTimeout() {
    return this.resources.defaultTimeout;
  }

  addSite(payload) {
    if (!payload || !payload.api) throw new Error('缺少 api 字段');
    const site = {
      id: payload.id || ('site_' + Date.now().toString(36)),
      name: payload.name || payload.id || '未命名资源站',
      api: String(payload.api).trim(),
      timeout: Number(payload.timeout) || 8000,
      enabled: payload.enabled !== false,
    };
    this.resources.sites.push(site);
    this.persist();
    return site;
  }

  updateSite(id, payload) {
    const site = this.resources.sites.find((s) => s.id === id);
    if (!site) return null;
    if (payload.api !== undefined) site.api = String(payload.api).trim();
    if (payload.name !== undefined) site.name = payload.name;
    if (payload.timeout !== undefined) site.timeout = Number(payload.timeout) || 8000;
    if (payload.enabled !== undefined) site.enabled = !!payload.enabled;
    this.persist();
    return site;
  }

  deleteSite(id) {
    const idx = this.resources.sites.findIndex((s) => s.id === id);
    if (idx === -1) return false;
    this.resources.sites.splice(idx, 1);
    this.persist();
    return true;
  }

  persist() {
    writeJson(RESOURCES_FILE, this.resources);
  }
}

module.exports = { ConfigStore, RESOURCES_FILE, NAMEMAP_FILE };
