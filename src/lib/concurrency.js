'use strict';

/**
 * 并发控制（多线程/多线程池）。
 * 在 Node.js 中 I/O 型任务（HTTP 请求）使用异步并发即可达到多线程并行效果，
 * 这里通过信号量 + 固定 worker 数量的方式实现可控的"多线程"并发池，
 * 避免一次性发出过多请求打爆资源站。
 */

/**
 * 将 items 交给 fn 处理，最多同时运行 limit 个任务。
 * @param {Array} items
 * @param {number} limit 并发上限
 * @param {Function} fn (item, index) => Promise
 * @returns {Promise<Array>} 与 items 顺序一致的结果数组
 */
async function mapWithConcurrency(items, limit, fn) {
  if (!Array.isArray(items) || items.length === 0) return [];
  const safeLimit = Math.max(1, Math.min(Number(limit) || 1, items.length));
  const results = new Array(items.length);
  let cursor = 0;

  async function worker() {
    while (cursor < items.length) {
      const idx = cursor++;
      try {
        results[idx] = await fn(items[idx], idx);
      } catch (err) {
        results[idx] = { error: err && err.message ? err.message : String(err) };
      }
    }
  }

  const workers = [];
  for (let i = 0; i < safeLimit; i++) {
    workers.push(worker());
  }
  await Promise.all(workers);
  return results;
}

/**
 * 带超时的 fetch 封装。
 * @param {string} url
 * @param {Object} options
 * @param {number} timeout 毫秒
 * @returns {Promise<Response>}
 */
async function fetchWithTimeout(url, { timeout = 8000, headers = {}, method = 'GET', body } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const resp = await fetch(url, {
      method,
      headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', ...headers },
      body,
      signal: controller.signal,
    });
    return resp;
  } finally {
    clearTimeout(timer);
  }
}

module.exports = { mapWithConcurrency, fetchWithTimeout };
