'use strict';

const COLORS = { red: 31, green: 32, yellow: 33, blue: 34, magenta: 35, cyan: 36, gray: 90 };

function paint(color, text) {
  const code = COLORS[color];
  if (code === undefined || !process.stdout.isTTY) return text;
  return `\u001b[${code}m${text}\u001b[0m`;
}

function ts() {
  return new Date().toISOString();
}

function info(...args) {
  console.log(paint('green', `[${ts()}] [INFO]`), ...args);
}

function warn(...args) {
  console.log(paint('yellow', `[${ts()}] [WARN]`), ...args);
}

function error(...args) {
  console.log(paint('red', `[${ts()}] [ERROR]`), ...args);
}

function debug(...args) {
  if (process.env.MXGJ_DEBUG === '1') {
    console.log(paint('gray', `[${ts()}] [DEBUG]`), ...args);
  }
}

module.exports = { info, warn, error, debug };
