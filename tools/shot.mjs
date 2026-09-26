/**
 * A very small browser driver, for looking at the thing before claiming it works.
 *
 * Drives headless Chrome over the DevTools Protocol using Node's own
 * WebSocket — no packages, nothing to install. It can carry a session cookie
 * (so the private panel can be photographed) and run a snippet of script
 * before the shot (so an interactive state can be photographed).
 *
 *   node tools/shot.mjs --url http://127.0.0.1:8381/ --out shots/player.png
 *   node tools/shot.mjs --url ... --cookie radiopanel=abc --eval "..." --wait 3000
 *
 * Not part of the product. A development tool, kept because "I looked at it"
 * should mean something.
 */

import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync, existsSync } from 'node:fs';
import { dirname, resolve as resolvePath } from 'node:path';
import { tmpdir } from 'node:os';

const args = new Map();
for (let i = 2; i < process.argv.length; i += 2) {
  args.set(process.argv[i].replace(/^--/, ''), process.argv[i + 1]);
}

const url = args.get('url');
const out = resolvePath(args.get('out') || 'shot.png');
const size = (args.get('size') || '760,1100').split(/[x,]/).map(Number);
const waitMs = Number(args.get('wait') || 2500);
const script = args.get('eval');
const cookie = args.get('cookie');
const fullPage = args.has('full');

const CHROME = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
].find((p) => existsSync(p));

if (!CHROME) { console.error('No Chrome or Edge found.'); process.exit(2); }
if (!url) { console.error('Usage: node tools/shot.mjs --url <url> --out <file.png>'); process.exit(2); }

const port = 9222 + Math.floor(Math.random() * 300);
const profile = `${tmpdir()}/radio-shot-${port}`;

const chrome = spawn(CHROME, [
  '--headless=new',
  '--disable-gpu',
  '--hide-scrollbars',
  '--no-first-run',
  '--autoplay-policy=no-user-gesture-required',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`,
  `--window-size=${size[0]},${size[1]}`,
  'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function endpoint() {
  for (let i = 0; i < 60; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${port}/json/version`);
      return (await res.json()).webSocketDebuggerUrl;
    } catch { await sleep(150); }
  }
  throw new Error('the browser never opened a debugging port');
}

function connect(wsUrl) {
  const socket = new WebSocket(wsUrl);
  const pending = new Map();
  const listeners = [];
  let nextId = 1;

  const ready = new Promise((res, rej) => {
    socket.addEventListener('open', res, { once: true });
    socket.addEventListener('error', rej, { once: true });
  });

  socket.addEventListener('message', (event) => {
    const msg = JSON.parse(event.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
    } else if (msg.method) {
      for (const fn of listeners) fn(msg);
    }
  });

  return {
    ready,
    send(method, params = {}, sessionId) {
      const id = nextId++;
      socket.send(JSON.stringify({ id, method, params, sessionId }));
      return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
    },
    on(fn) { listeners.push(fn); },
    close() { socket.close(); },
  };
}

try {
  const cdp = connect(await endpoint());
  await cdp.ready;

  const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  const call = (method, params) => cdp.send(method, params, sessionId);

  await call('Page.enable');
  await call('Network.enable');
  await call('Runtime.enable');

  if (cookie) {
    const [name, value] = cookie.split('=');
    const { hostname, pathname } = new URL(url);
    await call('Network.setCookie', { name, value, domain: hostname, path: '/' });
  }

  const loaded = new Promise((res) => {
    cdp.on((msg) => { if (msg.method === 'Page.loadEventFired') res(); });
  });
  await call('Page.navigate', { url });
  await Promise.race([loaded, sleep(15000)]);
  await sleep(waitMs);

  if (script) {
    const result = await call('Runtime.evaluate', { expression: script, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) console.error('eval threw:', result.exceptionDetails.text);
    else if (result.result && result.result.value !== undefined) console.log(JSON.stringify(result.result.value, null, 2));
    await sleep(Number(args.get('after') || 1500));
  }

  const shot = await call('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: fullPage,
  });
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, Buffer.from(shot.data, 'base64'));
  console.log('wrote ' + out);

  cdp.close();
} finally {
  chrome.kill();
  await sleep(300);
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* windows holds it briefly */ }
}
