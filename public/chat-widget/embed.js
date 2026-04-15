(() => {
  if (window.__CHAT_WIDGET_LOADED__) return;
  window.__CHAT_WIDGET_LOADED__ = true;

  const scriptEl = document.currentScript;
  const baseUrl =
    (scriptEl && scriptEl.dataset && scriptEl.dataset.chatUrl) ||
    new URL(scriptEl ? scriptEl.src : '/', window.location.href).origin;

  const position = (scriptEl && scriptEl.dataset && scriptEl.dataset.position) || 'right';
  const title = (scriptEl && scriptEl.dataset && scriptEl.dataset.title) || 'Chat';
  const brandColor = (scriptEl && scriptEl.dataset && scriptEl.dataset.color) || '#111827';

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'v-' + Math.random().toString(16).slice(2) + '-' + Date.now().toString(16);
  }

  const storageKey = 'chat_widget_vid';
  let visitorId = '';
  try {
    visitorId = localStorage.getItem(storageKey) || '';
    if (!visitorId) {
      visitorId = uuid();
      localStorage.setItem(storageKey, visitorId);
    }
  } catch {
    visitorId = uuid();
  }

  const side = position === 'left' ? 'left' : 'right';
  const widgetUrl = new URL(baseUrl.replace(/\/+$/, '') + '/chat-widget');
  widgetUrl.searchParams.set('vid', visitorId);
  widgetUrl.searchParams.set('title', title);
  widgetUrl.searchParams.set('color', brandColor);

  const root = document.createElement('div');
  root.id = 'chat-widget-root';
  root.style.position = 'fixed';
  root.style.bottom = '18px';
  root.style[side] = '18px';
  root.style.zIndex = '2147483647';
  root.style.fontFamily =
    'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple Color Emoji", "Segoe UI Emoji"';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Open chat');
  btn.innerHTML = '<i class="fa fa-commenting fa-3x"></i>';
  btn.style.width = '75px';
  btn.style.height = '75px';
  btn.style.borderRadius = '999px';
  btn.style.border = '0';
  btn.style.cursor = 'pointer';
  btn.style.background = 'rgb(17 101 226)';
  btn.style.color = '#fff';
  btn.style.fontWeight = '700';
  btn.style.boxShadow = '0 10px 25px rgba(0,0,0,.18)';

  const panel = document.createElement('div');
  panel.style.position = 'absolute';
  panel.style.bottom = '70px';
  panel.style[side] = '0';
  panel.style.width = '360px';
  panel.style.height = '520px';
  panel.style.maxHeight = '70vh';
  panel.style.borderRadius = '14px';
  panel.style.overflow = 'hidden';
  panel.style.boxShadow = '0 12px 30px rgba(0,0,0,.22)';
  panel.style.background = '#fff';
  panel.style.display = 'none';

  const iframe = document.createElement('iframe');
  iframe.title = 'Chat widget';
  iframe.src = widgetUrl.toString();
  iframe.style.width = '100%';
  iframe.style.height = '100%';
  iframe.style.border = '0';

  panel.appendChild(iframe);
  root.appendChild(panel);
  root.appendChild(btn);
  document.body.appendChild(root);

  function open() {
    panel.style.display = 'block';
    btn.setAttribute('aria-label', 'Close chat');
  }
  function close() {
    panel.style.display = 'none';
    btn.setAttribute('aria-label', 'Open chat');
  }
  function toggle() {
    panel.style.display === 'none' ? open() : close();
  }

  btn.addEventListener('click', toggle);

  window.addEventListener('message', (event) => {
    if (!event || !event.data) return;
    if (event.data.type === 'CHAT_WIDGET_CLOSE') close();
  });
})();

