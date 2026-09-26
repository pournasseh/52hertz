/** Discover and install the current station as its own app. */
(() => {
  // The same two address forms as app.js: the clean one where the server
  // rewrites addresses, ?station= where it does not. Whichever brought the
  // listener here is the one the rest is built in.
  const pathId = (location.pathname.match(/\/player\/([a-z0-9]+(?:-[a-z0-9]+)*)$/) || [])[1] || '';
  const clean = pathId !== '';
  const stationId = clean ? pathId : new URLSearchParams(location.search).get('station') || '';
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(stationId)) return;

  const playerRoot = new URL('./', location.href);
  const panelRoot = new URL('../', playerRoot);
  const id = encodeURIComponent(stationId);
  const stationUrl = new URL(clean ? id : './?station=' + id, playerRoot);
  const manifestUrl = new URL(clean ? `stations/${id}/manifest.webmanifest` : `index.php?p=station-manifest&id=${id}`, panelRoot);
  const iconUrl = new URL(clean ? `stations/${id}/icon-192.png` : `index.php?p=station-icon&id=${id}&size=192`, panelRoot);

  const canonical = document.createElement('link');
  canonical.rel = 'canonical';
  canonical.href = stationUrl.href;
  document.head.appendChild(canonical);

  const manifest = document.createElement('link');
  manifest.rel = 'manifest';
  manifest.href = manifestUrl.href;
  document.head.appendChild(manifest);

  const favicon = document.querySelector('link[rel~="icon"]') || document.createElement('link');
  favicon.rel = 'icon';
  favicon.type = 'image/png';
  favicon.href = iconUrl.href;
  if (!favicon.parentNode) document.head.appendChild(favicon);

  // iOS prefers apple-touch-icon when both it and manifest icons exist.
  const appleIcon = document.createElement('link');
  appleIcon.rel = 'apple-touch-icon';
  appleIcon.href = iconUrl.href;
  document.head.appendChild(appleIcon);

  if ('serviceWorker' in navigator) {
    addEventListener('load', () => {
      navigator.serviceWorker.register(new URL('sw.js', playerRoot).href, { scope: playerRoot.pathname }).catch(() => {});
    }, { once: true });
  }

  let installRequest = null;
  const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const installButton = () => document.getElementById('install');
  const updateButton = () => {
    const button = installButton();
    if (button) button.hidden = standalone() || installRequest === null;
  };

  addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installRequest = event;
    updateButton();
  });
  addEventListener('appinstalled', () => {
    installRequest = null;
    updateButton();
  });
  addEventListener('DOMContentLoaded', () => {
    updateButton();
    installButton()?.addEventListener('click', async () => {
      if (!installRequest) return;
      const request = installRequest;
      installRequest = null;
      updateButton();
      await request.prompt();
    });
  }, { once: true });
})();
