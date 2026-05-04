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
  const companyId = (scriptEl && scriptEl.dataset && scriptEl.dataset.companyId) || null;

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
  widgetUrl.searchParams.set('companyId', companyId);

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
  btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" fill="#ffffff" height="50px"><path d="M320 544C461.4 544 576 436.5 576 304C576 171.5 461.4 64 320 64C178.6 64 64 171.5 64 304C64 358.3 83.2 408.3 115.6 448.5L66.8 540.8C62 549.8 63.5 560.8 70.4 568.3C77.3 575.8 88.2 578.1 97.5 574.1L215.9 523.4C247.7 536.6 282.9 544 320 544zM192 272C209.7 272 224 286.3 224 304C224 321.7 209.7 336 192 336C174.3 336 160 321.7 160 304C160 286.3 174.3 272 192 272zM320 272C337.7 272 352 286.3 352 304C352 321.7 337.7 336 320 336C302.3 336 288 321.7 288 304C288 286.3 302.3 272 320 272zM416 304C416 286.3 430.3 272 448 272C465.7 272 480 286.3 480 304C480 321.7 465.7 336 448 336C430.3 336 416 321.7 416 304z"/></svg>';
  btn.style.width = '75px';
  btn.style.height = '75px';
  btn.style.borderRadius = '999px';
  btn.style.border = '0';
  btn.style.cursor = 'pointer';
  btn.style.background = 'rgb(17 101 226)';
  btn.style.color = '#fff';
  btn.style.fontWeight = '700';
  btn.style.display = 'inline-flex';
  btn.style.justifyContent = 'center';
  btn.style.alignItems = 'center';
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

