'use strict';
/**
 * 视频解析核心类 (Node.js 版)
 * 由 Python 版 video_parser_api.py / iqiyi_gui_*.py 的 VideoParser 转换而来
 */

const axios = require('axios');
const cheerio = require('cheerio');

// 网络代理支持：Node 默认不读取 HTTP_PROXY/HTTPS_PROXY 环境变量。
// 若存在则自动走系统代理（沙箱 / 内网环境友好），否则直连。
let HttpsProxyAgent = null;
function getProxyAgent() {
  if (HttpsProxyAgent === undefined) {
    try {
      const mod = require('https-proxy-agent');
      HttpsProxyAgent = mod.HttpsProxyAgent || mod.default || null;
    } catch (e) {
      HttpsProxyAgent = null;
    }
  }
  const proxyUrl = process.env.HTTPS_PROXY || process.env.https_proxy || process.env.HTTP_PROXY || process.env.http_proxy;
  if (HttpsProxyAgent && proxyUrl) {
    return new HttpsProxyAgent(proxyUrl);
  }
  return undefined;
}

class VideoParser {
  constructor() {
    // 统一请求头（与 Python 版 / GUI 版保持一致）
    this.headers = {
      'User-Agent':
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      Referer: 'https://www.iqiyi.com/',
      Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
      'Accept-Language': 'zh-CN,zh;q=0.9,en;q=0.8',
      Connection: 'keep-alive',
    };

    this.timeout = 15000;
    this.maxRedirects = 5;

    // 多平台解析接口列表（与 GUI 版一致）
    this.parse_apis = [
      'https://jx.playerjy.com/?url=',
      'https://jx.aidouer.net/?url=',
      'https://jx.jsonplayer.com/?url=',
      'https://jx.bozrc.com:4433/player/?url=',
    ];

    this.httpsAgent = getProxyAgent();
  }

  /** 底层 HTTP GET：返回 { status, text } */
  async httpGet(url, timeout = this.timeout) {
    const resp = await axios.get(url, {
      timeout,
      maxRedirects: this.maxRedirects,
      headers: this.headers,
      // 优先使用代理 agent，否则用 axios 默认并发连接
      ...(this.httpsAgent && this.parseUrlHost(url) ? { httpsAgent: this.httpsAgent, httpAgent: this.httpsAgent, proxy: false, maxRedirects: 0 } : {}),
    });
    return { status: resp.status, text: resp.data };
  }

  parseUrlHost() {
    return true;
  }

  /** 主解析函数：返回标准 JSON（与 Flask API 结构一致） */
  async parseVideo(url, showUrlOnError = true) {
    const start = Date.now();

    try {
      const videoInfo = await this.getVideoInfo(url);
      const videoTitle = (videoInfo && videoInfo.title) || '未知视频';

      const playUrls = await this.generatePlayUrls(url);
      const elapsed = (Date.now() - start) / 1000;

      if (playUrls && playUrls.length) {
        const playUrl = playUrls[0];
        return {
          code: 200,
          msg: '获取成功',
          title: videoTitle,
          type: this.detectVideoType(playUrl),
          url: playUrl,
          from: url,
          time: Math.round(elapsed * 1000000) / 1000000,
        };
      }

      return {
        code: 404,
        msg: '解析失败，未找到播放源',
        title: videoTitle,
        type: '',
        url: showUrlOnError ? url : '',
        from: url,
        time: Math.round(elapsed * 1000000) / 1000000,
      };
    } catch (e) {
      const elapsed = (Date.now() - start) / 1000;
      return {
        code: 500,
        msg: `解析异常: ${e.message || String(e)}`,
        title: '',
        type: '',
        url: showUrlOnError ? url : '',
        from: url,
        time: Math.round(elapsed * 1000000) / 1000000,
      };
    }
  }

  /** GUI 专用：返回全部播放源（Python 桌面版 parse_video 的等价） */
  async parseForGui(url) {
    try {
      const videoInfo = await this.getVideoInfo(url);
      const title = (videoInfo && videoInfo.title) || '解析成功';
      const urls = await this.generatePlayUrls(url);
      if (urls && urls.length) {
        return { success: true, title, description: videoInfo ? videoInfo.description : '', urls, video_info: videoInfo };
      }
      return { success: false, error: '未找到可用的播放源，请检查链接或稍后重试', title };
    } catch (e) {
      return { success: false, error: `解析失败: ${e.message || String(e)}` };
    }
  }

  /** 获取视频信息（标题等） */
  async getVideoInfo(url) {
    try {
      const resp = await this.httpGet(url, 15000);
      const $ = cheerio.load(resp.text);

      const info = { title: '', description: '', duration: '', url };

      const selectors = [
        ['meta[property="og:title"]', 'content'],
        ['meta[name="title"]', 'content'],
        ['h1.videoTitle', 'text'],
        ['h1.title', 'text'],
        ['h1[class*="title"]', 'text'],
        ['.video-title', 'text'],
        ['title', 'text'],
      ];

      for (const [sel, type] of selectors) {
        try {
          const node = $(sel).first();
          if (node && node.length) {
            let title = type === 'content' ? (node.attr('content') || '').trim() : node.text().trim();
            if (title) {
              info.title = title;
              break;
            }
          }
        } catch (e) {
          continue;
        }
      }

      if (info.title) {
        info.title = info.title.split('_')[0].split('-')[0].trim();
        for (const site of ['爱奇艺', '腾讯视频', '优酷', '芒果TV', 'iQIYI', 'Tencent', '在线观看', '高清']) {
          info.title = info.title.replace(site, '');
        }
        info.title = info.title.trim();
      } else {
        info.title = '未知视频';
      }

      return info;
    } catch (e) {
      return { title: '未知视频', description: '', url };
    }
  }

  /** 生成多种可能的播放地址 */
  async generatePlayUrls(url) {
    try {
      let playUrls = [];

      // 方法1：第三方接口解析（主要方法）
      for (const api of this.parse_apis) {
        try {
          const parseUrl = api + url;
          const resp = await this.httpGet(parseUrl, 10000);
          if (resp.status === 200) {
            const newUrls = this.extractPlayUrls(resp.text);
            if (newUrls && newUrls.length) {
              playUrls.push(...newUrls);
              break;
            }
          }
          await this.sleep(500);
        } catch (e) {
          continue;
        }
      }

      // 方法2：直接解析
      if (!playUrls.length) {
        playUrls = await this.tryDirectParse(url);
      }

      // 方法3：生成通用播放器链接（备用方案）
      if (!playUrls.length) {
        for (const api of this.parse_apis) {
          playUrls.push(api + url);
        }
      }

      // 去重（保持顺序）
      playUrls = [...new Set(playUrls)];
      return playUrls.slice(0, 5);
    } catch (e) {
      return [];
    }
  }

  /** 从 HTML 中提取播放源 */
  extractPlayUrls(html) {
    const patterns = [
      '"url":"([^"]+\\.m3u8[^"]*)"',
      '"url":"([^"]+\\.mp4[^"]*)"',
      '"playUrl":"([^"]+)"',
      '"src":"([^"]+\\.m3u8[^"]*)"',
      '"src":"([^"]+\\.mp4[^"]*)"',
      '<iframe[^>]+src="([^"]+)"',
      '<video[^>]+src="([^"]+)"',
      '<source[^>]+src="([^"]+)"',
    ];
    const result = [];
    for (const p of patterns) {
      const re = new RegExp(p, 'g');
      let m;
      while ((m = re.exec(html)) !== null) {
        const u = m[1];
        if (!result.includes(u) && this.isValidVideoUrl(u)) {
          result.push(u);
        }
      }
    }
    return result;
  }

  /** 尝试直接解析 */
  async tryDirectParse(url) {
    try {
      const resp = await this.httpGet(url, 15000);
      const patterns = [
        '"playUrl":"([^"]+)"',
        '"url":"([^"]+\\.m3u8[^"]*)"',
        '"url":"([^"]+\\.mp4[^"]*)"',
        '"videoUrl":"([^"]+)"',
        '"src":"([^"]+\\.m3u8[^"]*)"',
        '"src":"([^"]+\\.mp4[^"]*)"',
        'playUrl=([^&\\s]+)',
        'videoUrl=([^&\\s]+)',
      ];
      const result = [];
      for (const p of patterns) {
        const re = new RegExp(p, 'g');
        let m;
        while ((m = re.exec(resp.text)) !== null) {
          let u = m[1];
          try {
            u = decodeURIComponent(u);
          } catch (e) {
            /* 保持原样 */
          }
          if (!result.includes(u)) {
            result.push(u);
          }
        }
      }
      return result;
    } catch (e) {
      return [];
    }
  }

  /** 验证是否为有效视频 URL */
  isValidVideoUrl(url) {
    if (!url || url.length < 10) return false;
    const exts = ['.m3u8', '.mp4', '.flv', '.avi', '.mkv', '.mov', '.wmv'];
    const lower = url.toLowerCase();
    if (exts.some((e) => lower.includes(e))) return true;
    const keywords = ['video', 'player', 'play', 'stream', 'media'];
    return keywords.some((k) => lower.includes(k));
  }

  /** 检测视频类型 */
  detectVideoType(url) {
    const lower = url.toLowerCase();
    if (lower.includes('.m3u8')) return 'm3u8';
    if (lower.includes('.mp4')) return 'mp4';
    if (lower.includes('.flv')) return 'flv';
    return 'm3u8';
  }

  sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }
}

module.exports = VideoParser;