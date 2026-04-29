(function () {
  'use strict';

  /* ─────────────────────────────────────────────
     CONFIG  (reads from window.ChatConfig)
  ───────────────────────────────────────────── */
  var cfg         = window.ChatConfig || {};
  var API_BASE    = (cfg.apiBase    || '').replace(/\/$/, '');
  var API_TOKEN   = cfg.apiToken    || '';
  var PUSHER_KEY  = cfg.pusherKey   || '';
  var PUSHER_CLUSTER = cfg.pusherCluster || 'ap2';

  var VISITOR_ID_KEY = (cfg.visitorIdStorageKey || 'chat_visitor_id');
  var visitorId = (cfg.visitorId || '').toString().trim();
  if (!visitorId) {
    try { visitorId = localStorage.getItem(VISITOR_ID_KEY) || ''; } catch (e) { visitorId = ''; }
  }
  if (!visitorId) {
    if (window.crypto && crypto.randomUUID) visitorId = crypto.randomUUID();
    else visitorId = 'v-' + Math.random().toString(16).slice(2) + '-' + Date.now().toString(16);
  }
  try { localStorage.setItem(VISITOR_ID_KEY, visitorId); } catch (e) {}

  var chatId      = null;
  var lastSentUrl = null;
  var urlTrackingSetup = false;
  var MAX_FILE_BYTES   = 20 * 1024 * 1024;

  /* ─────────────────────────────────────────────
     STATE
  ───────────────────────────────────────────── */
  var state = {
    open:          false,
    messages:      [],
    message:       '',
    attachedFile:  null,   // { file, preview, isImage }
    showUserForm:  false,
    chatClosed:    false,
    sendError:     '',
    userForm:      { phone: '', customerName: '', registrationNo: '', email: '' }
  };

  /* ─────────────────────────────────────────────
     INJECT STYLES
  ───────────────────────────────────────────── */
  var style = document.createElement('style');
  style.textContent = [
    '#bgc-widget-root * { box-sizing: border-box; font-family: inherit; }',

    /* Floating button */
    '#bgc-chat-btn {',
    '  position:fixed; bottom:20px; right:20px; width:60px; height:60px;',
    '  border-radius:50%; background:var(--bs-primary,#0d6efd); border:none;',
    '  cursor:pointer; display:flex; align-items:center; justify-content:center;',
    '  z-index:99999; box-shadow:0 4px 14px rgba(0,0,0,.25); transition:transform .2s;',
    '}',
    '#bgc-chat-btn:hover { transform:scale(1.08); }',
    '#bgc-chat-btn i { color:#fff; font-size:26px; }',

    /* Chat box */
    '#bgc-chat-box {',
    '  position:fixed; bottom:90px; right:20px; width:350px;',
    '  border-radius:8px; overflow:hidden; box-shadow:0 8px 28px rgba(0,0,0,.18);',
    '  display:flex; flex-direction:column; z-index:99998;',
    '  transition:opacity .2s,transform .2s; opacity:0; transform:translateY(16px);',
    '  pointer-events:none; height:500px;',
    '}',
    '#bgc-chat-box.bgc-open { opacity:1; transform:translateY(0); pointer-events:all; }',

    /* Header */
    '#bgc-header {',
    '  background:var(--bs-primary,#0d6efd); color:#fff;',
    '  padding:10px 14px; display:flex; align-items:center;',
    '  justify-content:space-between; cursor:pointer; flex-shrink:0;',
    '}',
    '#bgc-header span { font-size:15px; font-weight:600; }',
    '#bgc-close-btn { background:none; border:none; color:#fff; font-size:20px;',
    '  cursor:pointer; line-height:1; padding:0; opacity:.85; }',
    '#bgc-close-btn:hover { opacity:1; }',

    /* Messages area */
    '#bgc-messages {',
    '  flex:1; overflow-y:auto; padding:12px; background:#f8f9fa;',
    '  display:flex; flex-direction:column; gap:10px;',
    '  scroll-behavior:smooth;',
    '}',
    '#bgc-messages::-webkit-scrollbar { width:5px; }',
    '#bgc-messages::-webkit-scrollbar-thumb { background:#bbb; border-radius:3px; }',

    /* Bubbles */
    '.bgc-msg-row { display:flex; }',
    '.bgc-msg-row.visitor { justify-content:flex-end; }',
    '.bgc-msg-row.agent   { justify-content:flex-start; }',
    '.bgc-bubble {',
    '  max-width:80%; padding:8px 12px; border-radius:10px;',
    '  font-size:13px; line-height:1.5; word-break:break-word;',
    '}',
    '.bgc-msg-row.visitor .bgc-bubble {',
    '  background:var(--bs-primary,#0d6efd); color:#fff; border-bottom-right-radius:2px;',
    '}',
    '.bgc-msg-row.agent .bgc-bubble {',
    '  background:#fff; color:#333; border-bottom-left-radius:2px;',
    '  box-shadow:0 1px 3px rgba(0,0,0,.1);',
    '}',
    '.bgc-sender { font-size:11px; opacity:.7; margin-bottom:3px; }',
    '.bgc-time   { font-size:11px; opacity:.5; text-align:right; margin-top:3px; }',
    '.bgc-msg-text { white-space:pre-line; }',

    /* Attachment thumbnail */
    '.bgc-attach-img { max-width:160px; max-height:120px; border-radius:6px;',
    '  border:1px solid rgba(0,0,0,.1); display:block; margin-top:6px; cursor:pointer; }',
    '.bgc-attach-link { font-size:12px; text-decoration:underline; }',

    /* Error bar */
    '#bgc-error-bar {',
    '  border-top:1px solid #f5c2c7; background:#f8d7da; color:#842029;',
    '  padding:6px 12px; font-size:12px; display:none;',
    '  align-items:flex-start; justify-content:space-between; gap:6px; flex-shrink:0;',
    '}',
    '#bgc-error-bar.bgc-show { display:flex; }',
    '#bgc-error-dismiss { background:none; border:none; color:#842029;',
    '  font-weight:700; font-size:16px; cursor:pointer; line-height:1; padding:0; }',

    /* User info form */
    '#bgc-user-form {',
    '  border-top:1px solid #dee2e6; padding:12px; background:#eff6ff;',
    '  display:none; flex-shrink:0;',
    '}',
    '#bgc-user-form.bgc-show { display:block; }',
    '#bgc-user-form h4 { font-size:13px; font-weight:600; color:#1e40af; margin-bottom:8px; }',
    '.bgc-form-row { display:flex; gap:6px; margin-bottom:6px; }',
    '.bgc-form-row input {',
    '  flex:1; padding:5px 8px; font-size:12px; border:1px solid #cbd5e1;',
    '  border-radius:6px; outline:none;',
    '}',
    '.bgc-form-row input:focus { border-color:var(--bs-primary,#0d6efd); }',
    '.bgc-form-btns { display:flex; justify-content:flex-end; gap:6px; margin-top:6px; }',
    '.bgc-btn-submit  { background:var(--bs-primary,#0d6efd); color:#fff;',
    '  border:none; border-radius:6px; padding:5px 14px; font-size:12px; cursor:pointer; }',
    '.bgc-btn-cancel  { background:#6c757d; color:#fff;',
    '  border:none; border-radius:6px; padding:5px 14px; font-size:12px; cursor:pointer; }',

    /* File preview inside input */
    '#bgc-file-preview { padding:6px 10px; display:none; gap:6px;',
    '  align-items:center; background:#f1f5f9; flex-shrink:0; flex-wrap:wrap; }',
    '#bgc-file-preview.bgc-show { display:flex; }',
    '.bgc-fp-item { position:relative; display:flex; align-items:center; gap:6px;',
    '  background:#e2e8f0; border-radius:6px; padding:4px 8px; font-size:12px; }',
    '.bgc-fp-remove { position:absolute; top:-6px; right:-6px; width:16px; height:16px;',
    '  background:#475569; color:#fff; border:none; border-radius:50%;',
    '  font-size:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; }',
    '.bgc-fp-img { width:36px; height:36px; object-fit:cover; border-radius:4px; }',

    /* Input footer */
    '#bgc-footer {',
    '  border-top:1px solid #dee2e6; padding:8px 10px;',
    '  background:#fff; display:flex; gap:6px; align-items:center; flex-shrink:0;',
    '}',
    '#bgc-attach-btn {',
    '  background:none; border:1px solid #dee2e6; border-radius:20px;',
    '  width:34px; height:34px; cursor:pointer; display:flex;',
    '  align-items:center; justify-content:center; flex-shrink:0;',
    '}',
    '#bgc-text-input {',
    '  flex:1; border:1px solid #dee2e6; border-radius:20px;',
    '  padding:7px 14px; font-size:13px; outline:none;',
    '}',
    '#bgc-text-input:focus { border-color:var(--bs-primary,#0d6efd); }',
    '#bgc-send-btn {',
    '  background:var(--bs-primary,#0d6efd); color:#fff; border:none;',
    '  border-radius:20px; padding:7px 14px; font-size:13px; cursor:pointer; flex-shrink:0;',
    '}',
    '#bgc-send-btn:disabled { opacity:.5; cursor:not-allowed; }',
    '#bgc-new-chat-btn {',
    '  background:#16a34a; color:#fff; border:none;',
    '  border-radius:20px; padding:7px 14px; font-size:13px; cursor:pointer; flex-shrink:0;',
    '}',
  ].join('\n');
  document.head.appendChild(style);

  /* ─────────────────────────────────────────────
     BUILD HTML
  ───────────────────────────────────────────── */
  var root = document.createElement('div');
  root.id  = 'bgc-widget-root';
  root.innerHTML = [
    /* Floating button */
    '<button id="bgc-chat-btn" title="Chat with us">',
    '  <i class="fa fa-commenting"></i>',
    '</button>',

    /* Chat box */
    '<div id="bgc-chat-box">',

    '  <div id="bgc-header">',
    '    <span>Chat with us</span>',
    '    <button id="bgc-close-btn">&#8722;</button>',
    '  </div>',

    '  <div id="bgc-messages"></div>',

    '  <div id="bgc-error-bar">',
    '    <span id="bgc-error-text"></span>',
    '    <button id="bgc-error-dismiss">&#215;</button>',
    '  </div>',

    '  <div id="bgc-user-form">',
    '    <h4>Please provide your information:</h4>',
    '    <div class="bgc-form-row">',
    '      <input id="bgc-f-phone"    type="tel"   placeholder="Phone No *" />',
    '      <input id="bgc-f-name"     type="text"  placeholder="Customer Name *" />',
    '    </div>',
    '    <div class="bgc-form-row">',
    '      <input id="bgc-f-regno"    type="text"  placeholder="Registration No *" />',
    '      <input id="bgc-f-email"    type="email" placeholder="Email" />',
    '    </div>',
    '    <div class="bgc-form-btns">',
    '      <button class="bgc-btn-submit" id="bgc-submit-info">Submit</button>',
    '      <button class="bgc-btn-cancel" id="bgc-cancel-info">Cancel</button>',
    '    </div>',
    '  </div>',

    '  <div id="bgc-file-preview"></div>',

    '  <div id="bgc-footer">',
    '    <input type="file" id="bgc-file-input" style="display:none" />',
    '    <button id="bgc-attach-btn" title="Attach file"><i class="fa fa-paperclip"></i></button>',
    '    <input id="bgc-text-input" type="text" placeholder="Type a message..." />',
    '    <button id="bgc-send-btn">Send</button>',
    '    <button id="bgc-new-chat-btn" style="display:none">New Chat</button>',
    '  </div>',

    '</div>',
  ].join('');
  document.body.appendChild(root);

  /* ─────────────────────────────────────────────
     ELEMENT REFS
  ───────────────────────────────────────────── */
  var elBtn         = document.getElementById('bgc-chat-btn');
  var elBox         = document.getElementById('bgc-chat-box');
  var elClose       = document.getElementById('bgc-close-btn');
  var elMsgs        = document.getElementById('bgc-messages');
  var elErrorBar    = document.getElementById('bgc-error-bar');
  var elErrorText   = document.getElementById('bgc-error-text');
  var elErrorDismiss= document.getElementById('bgc-error-dismiss');
  var elUserForm    = document.getElementById('bgc-user-form');
  var elFPhone      = document.getElementById('bgc-f-phone');
  var elFName       = document.getElementById('bgc-f-name');
  var elFRegno      = document.getElementById('bgc-f-regno');
  var elFEmail      = document.getElementById('bgc-f-email');
  var elSubmitInfo  = document.getElementById('bgc-submit-info');
  var elCancelInfo  = document.getElementById('bgc-cancel-info');
  var elFilePreview = document.getElementById('bgc-file-preview');
  var elFileInput   = document.getElementById('bgc-file-input');
  var elAttachBtn   = document.getElementById('bgc-attach-btn');
  var elTextInput   = document.getElementById('bgc-text-input');
  var elSendBtn     = document.getElementById('bgc-send-btn');
  var elNewChatBtn  = document.getElementById('bgc-new-chat-btn');

  /* ─────────────────────────────────────────────
     HELPERS
  ───────────────────────────────────────────── */
  function apiHeaders() {
    var h = { 'Content-Type': 'application/json' };
    if (API_TOKEN) h['X-CHAT-TOKEN'] = API_TOKEN;
    return h;
  }

  function formatTime(ts) {
    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function resolveAttachmentUrl(url) {
    if (!url) return null;
    if (/^https?:\/\//i.test(url)) return url;
    if (API_BASE) {
      try { return new URL(API_BASE).origin + url; } catch (e) {}
    }
    return url;
  }

  function withToken(url) {
    if (!url || !API_TOKEN) return url;
    if (url.includes('signature=')) return url;
    try {
      var u = new URL(url, window.location.origin);
      if (!u.searchParams.get('token')) u.searchParams.set('token', API_TOKEN);
      return u.toString();
    } catch (e) {
      return url + (url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(API_TOKEN);
    }
  }

  function scrollBottom() {
    setTimeout(function () { elMsgs.scrollTop = elMsgs.scrollHeight; }, 50);
  }

  function showError(msg) {
    elErrorText.textContent = msg;
    elErrorBar.classList.add('bgc-show');
  }

  function hideError() {
    elErrorBar.classList.remove('bgc-show');
    elErrorText.textContent = '';
  }

  /* ─────────────────────────────────────────────
     RENDER A SINGLE MESSAGE BUBBLE
  ───────────────────────────────────────────── */
  function renderMessage(msg) {
    if (msg.message_type === 'user_info_request') return; // hidden per original

    var row = document.createElement('div');
    row.className = 'bgc-msg-row ' + (msg.sender_type === 'visitor' ? 'visitor' : 'agent');

    var bubble = document.createElement('div');
    bubble.className = 'bgc-bubble';

    /* sender label */
    var sender = document.createElement('div');
    sender.className = 'bgc-sender';
    sender.textContent = msg.sender_type === 'visitor' ? 'You' : 'Agent';
    bubble.appendChild(sender);

    /* message body */
    var body = document.createElement('div');
    body.className = 'bgc-msg-text';

    if (msg.message_type === 'user_info_response') {
      var info = {};
      try { info = typeof msg.message === 'string' ? JSON.parse(msg.message) : msg.message; } catch (e) {}
      body.innerHTML = [
        '<strong style="font-size:13px">User Information Sent:</strong>',
        msg.message || '',   // already formatted text from submitUserInfo
      ].join('<br>');
    } else {
      body.textContent = msg.message || '';
    }
    bubble.appendChild(body);

    /* attachment */
    var viewUrl     = withToken(resolveAttachmentUrl(msg.attachment_view_url));
    var downloadUrl = resolveAttachmentUrl(msg.attachment_download_url || msg.attachment_view_url);
    if (viewUrl) {
      if (msg.attachment_is_image) {
        var img = document.createElement('img');
        img.src = viewUrl;
        img.alt = msg.attachment_name || 'Attachment';
        img.className = 'bgc-attach-img';
        img.onclick = function () { window.open(viewUrl, '_blank'); };
        bubble.appendChild(img);

        var dlLink = document.createElement('a');
        dlLink.href = downloadUrl;
        dlLink.download = msg.attachment_name || 'file';
        dlLink.target = '_blank';
        dlLink.rel = 'noopener';
        dlLink.innerHTML = '<i class="fa fa-download"></i>';
        dlLink.style.cssText = 'display:block;margin-top:4px;font-size:12px;';
        bubble.appendChild(dlLink);
      } else {
        var fileLink = document.createElement('a');
        fileLink.href = downloadUrl;
        fileLink.download = msg.attachment_name || 'file';
        fileLink.target = '_blank';
        fileLink.rel = 'noopener';
        fileLink.className = 'bgc-attach-link';
        fileLink.textContent = 'Download ' + (msg.attachment_name || 'file');
        bubble.appendChild(fileLink);
      }
    }

    /* timestamp */
    var time = document.createElement('div');
    time.className = 'bgc-time';
    time.textContent = formatTime(msg.created_at);
    bubble.appendChild(time);

    row.appendChild(bubble);
    elMsgs.appendChild(row);
    scrollBottom();
  }

  function renderAllMessages(msgs) {
    elMsgs.innerHTML = '';
    (msgs || []).forEach(renderMessage);
  }

  /* ─────────────────────────────────────────────
     API CALLS
  ───────────────────────────────────────────── */
  function apiFetch(path, options) {
    return fetch(API_BASE + path, options);
  }

  /* Init / create chat */
  function initChat() {
    apiFetch('/chat', {
      method: 'POST',
      headers: apiHeaders(),
      credentials: 'include',
      body: JSON.stringify({ visitor_id: visitorId, current_url: window.location.href })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      chatId = data.chat.id;
      setClosedUI(data.chat && data.chat.status === 'close');
      renderAllMessages(data.messages);

      if (!data.messages || !data.messages.length) {
        /* friendly greeting bubble if no history */
        renderMessage({
          message: 'Hello! 👋 How can we help you today?',
          sender_type: 'agent',
          message_type: 'text',
          created_at: new Date().toISOString()
        });
      }

      /* show user form if pending request */
      var hasPending = (data.messages || []).some(function (m) {
        return m.message_type === 'user_info_request';
      }) && !(data.messages || []).some(function (m) {
        return m.message_type === 'user_info_response';
      });
      if (hasPending) showUserFormEl();

      pingChat(true);
      setupUrlTracking();
      connectPusher();
    })
    .catch(function (err) {
      console.error('[BGC] Init failed', err);
      renderMessage({
        message: 'Could not connect. Please try again later.',
        sender_type: 'agent',
        message_type: 'text',
        created_at: new Date().toISOString()
      });
    });
  }

  /* Ping */
  function pingChat(force) {
    if (!chatId) return;
    var currentUrl = window.location.href;
    var body = { chat_id: chatId, visitor_id: visitorId };
    if (force || lastSentUrl !== currentUrl) {
      body.current_url = currentUrl;
      lastSentUrl = currentUrl;
    }
    apiFetch('/chat/ping', {
      method: 'POST',
      headers: apiHeaders(),
      credentials: 'include',
      body: JSON.stringify(body)
    }).catch(function () {});
  }

  /* Send text message */
  function sendMessage() {
    if (!chatId) return;
    if (state.chatClosed) return;
    var text = elTextInput.value.trim();
    var file = state.attachedFile;
    if (!text && !file) return;

    var savedText = text;
    var savedFile = file;
    elTextInput.value = '';
    clearFilePreview();
    hideError();

    var doSend;

    if (savedFile) {
      var fd = new FormData();
      fd.append('chat_id', chatId);
      fd.append('visitor_id', visitorId);
      fd.append('message', savedText);
      fd.append('sender_type', 'visitor');
      fd.append('attachments', savedFile.file);

      var headers = {};
      if (API_TOKEN) headers['X-CHAT-TOKEN'] = API_TOKEN;

      doSend = fetch(API_BASE + '/message', {
        method: 'POST',
        headers: headers,
        credentials: 'include',
        body: fd
      });
    } else {
      doSend = apiFetch('/message', {
        method: 'POST',
        headers: apiHeaders(),
        credentials: 'include',
        body: JSON.stringify({ chat_id: chatId, visitor_id: visitorId, message: savedText, sender_type: 'visitor' })
      });
    }

    doSend
    .then(function (r) {
      if (!r || r.ok) return null;
      return r.json().catch(function () { return null; }).then(function (payload) {
        var msg = payload && payload.message ? String(payload.message) : ('Request failed (' + r.status + ')');
        if (r.status === 403 && /closed/i.test(msg)) {
          setClosedUI(true);
          showError('This chat has been closed. Click "New Chat" to start again.');
          return null;
        }
        throw new Error(msg);
      });
    })
    .catch(function (err) {
      console.error('[BGC] Send failed', err);
      showError('Failed to send. Please try again.');
      elTextInput.value = savedText;
      if (savedFile) state.attachedFile = savedFile;
    });
  }

  /* Submit user info form */
  function submitUserInfo() {
    var phone  = elFPhone.value.trim();
    var name   = elFName.value.trim();
    var regno  = elFRegno.value.trim();
    var email  = elFEmail.value.trim();

    if (!phone || !name || !regno) {
      showError('Please fill in Phone No, Customer Name, and Registration No.');
      return;
    }

    var lines = [
      'User Information:',
      'Phone No: '        + phone,
      'Customer Name: '   + name,
      'Registration No: ' + regno,
    ];
    if (email) lines.push('Email: ' + email);

    apiFetch('/message', {
      method: 'POST',
      headers: apiHeaders(),
      credentials: 'include',
      body: JSON.stringify({
        chat_id:          chatId,
        visitor_id:       visitorId,
        message:          lines.join('\n'),
        sender_type:      'visitor',
        message_type:     'user_info_response',
        phone:            phone,
        customer_name:    name,
        registration_no:  regno,
        email:            email || null
      })
    })
    .then(function () {
      hideError();
      hideUserFormEl();
      elFPhone.value = elFName.value = elFRegno.value = elFEmail.value = '';
    })
    .catch(function (err) {
      console.error('[BGC] User info failed', err);
      showError('Failed to send. Please try again.');
    });
  }

  /* ─────────────────────────────────────────────
     USER FORM SHOW / HIDE
  ───────────────────────────────────────────── */
  function showUserFormEl()  { elUserForm.classList.add('bgc-show'); }
  function hideUserFormEl()  { elUserForm.classList.remove('bgc-show'); }

  /* ─────────────────────────────────────────────
     FILE ATTACHMENT
  ───────────────────────────────────────────── */
  function clearFilePreview() {
    state.attachedFile = null;
    elFilePreview.innerHTML = '';
    elFilePreview.classList.remove('bgc-show');
  }

  function addFile(file) {
    if (file.size > MAX_FILE_BYTES) {
      showError('File too large. Maximum size is 20 MB.');
      return;
    }
    clearFilePreview();
    var isImage = file.type.startsWith('image/');
    var preview = isImage ? URL.createObjectURL(file) : null;
    state.attachedFile = { file: file, preview: preview, isImage: isImage };

    var item = document.createElement('div');
    item.className = 'bgc-fp-item';

    if (isImage) {
      var img = document.createElement('img');
      img.src = preview;
      img.className = 'bgc-fp-img';
      item.appendChild(img);
    } else {
      var label = document.createElement('span');
      label.textContent = file.name.length > 22 ? file.name.slice(0, 22) + '…' : file.name;
      item.appendChild(label);
    }

    var removeBtn = document.createElement('button');
    removeBtn.className = 'bgc-fp-remove';
    removeBtn.textContent = '×';
    removeBtn.onclick = clearFilePreview;
    item.appendChild(removeBtn);

    elFilePreview.appendChild(item);
    elFilePreview.classList.add('bgc-show');
  }

  /* ─────────────────────────────────────────────
     PUSHER / REAL-TIME
  ───────────────────────────────────────────── */
  function connectPusher() {
    if (!PUSHER_KEY || !chatId) return;

    function doConnect() {
      var pusher = new window.Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER });
      var ch = pusher.subscribe('chat.' + chatId);
      ch.bind('MessageSent', function (e) {
        var msg = e.message;
        renderMessage(msg);
        if (msg.message_type === 'user_info_request') showUserFormEl();
        if (msg.message_type === 'user_info_response' && msg.sender_type === 'visitor') hideUserFormEl();
      });
    }

    if (window.Pusher) {
      doConnect();
    } else {
      var s = document.createElement('script');
      s.src = 'https://js.pusher.com/8.2.0/pusher.min.js';
      s.onload = doConnect;
      document.head.appendChild(s);
    }
  }

  /* ─────────────────────────────────────────────
     URL TRACKING (same as original)
  ───────────────────────────────────────────── */
  function setupUrlTracking() {
    if (urlTrackingSetup) return;
    urlTrackingSetup = true;
    var notify = function () { pingChat(true); };
    window.addEventListener('popstate', notify);
    window.addEventListener('locationchange', notify);

    var ps = history.pushState;
    if (typeof ps === 'function') {
      history.pushState = function () {
        ps.apply(this, arguments);
        window.dispatchEvent(new Event('locationchange'));
      };
    }
    var rs = history.replaceState;
    if (typeof rs === 'function') {
      history.replaceState = function () {
        rs.apply(this, arguments);
        window.dispatchEvent(new Event('locationchange'));
      };
    }
  }

  /* ─────────────────────────────────────────────
     OPEN / CLOSE TOGGLE
  ───────────────────────────────────────────── */
  var initialized = false;

  function openChat() {
    state.open = true;
    elBox.classList.add('bgc-open');
    elBtn.style.display = 'none';
    if (!initialized) {
      initialized = true;
      initChat();
      setInterval(function () { pingChat(); }, 20000);
    }
    setTimeout(function () { elTextInput.focus(); }, 200);
  }

  function closeChat() {
    state.open = false;
    elBox.classList.remove('bgc-open');
    elBtn.style.display = 'flex';
  }

  function generateVisitorId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'v-' + Math.random().toString(16).slice(2) + '-' + Date.now().toString(16);
  }

  function setClosedUI(isClosed) {
    state.chatClosed = !!isClosed;

    elNewChatBtn.style.display = state.chatClosed ? 'inline-flex' : 'none';
    elSendBtn.style.display = state.chatClosed ? 'none' : 'inline-flex';

    elTextInput.disabled = state.chatClosed;
    elAttachBtn.disabled = state.chatClosed;
    elFileInput.disabled = state.chatClosed;

    if (state.chatClosed) {
      clearFilePreview();
      elTextInput.value = '';
    }

    updateSendBtn();
  }

  function startNewChat() {
    var previousVisitorId = visitorId;
    var nextVisitorId = generateVisitorId();

    apiFetch('/chat/new', {
      method: 'POST',
      headers: apiHeaders(),
      credentials: 'include',
      body: JSON.stringify({ visitor_id: nextVisitorId, previous_visitor_id: previousVisitorId, current_url: window.location.href })
    })
    .then(function (r) {
      if (!r.ok) {
        return r.json().catch(function () { return null; }).then(function (p) {
          throw new Error((p && p.message) ? String(p.message) : ('Request failed (' + r.status + ')'));
        });
      }
      return r.json();
    })
    .then(function (data) {
      visitorId = nextVisitorId;
      try { localStorage.setItem(VISITOR_ID_KEY, visitorId); } catch (e) {}

      chatId = data.chat.id;
      renderAllMessages(data.messages);
      hideError();
      hideUserFormEl();
      setClosedUI(false);
      pingChat(true);
    })
    .catch(function (err) {
      console.error('[BGC] New chat failed', err);
      showError('Failed to start a new chat. Please try again.');
    });
  }

  /* ─────────────────────────────────────────────
     EVENT LISTENERS
  ───────────────────────────────────────────── */
  elBtn.addEventListener('click', openChat);
  elClose.addEventListener('click', closeChat);
  document.getElementById('bgc-header').addEventListener('click', function (e) {
    if (e.target !== elClose) closeChat();
  });

  elErrorDismiss.addEventListener('click', hideError);

  elAttachBtn.addEventListener('click', function () { elFileInput.click(); });
  elFileInput.addEventListener('change', function (e) {
    var files = e.target.files;
    if (files && files[0]) addFile(files[0]);
    e.target.value = '';
  });

  elTextInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
  });

  elSendBtn.addEventListener('click', sendMessage);
  elNewChatBtn.addEventListener('click', startNewChat);

  elSubmitInfo.addEventListener('click', submitUserInfo);
  elCancelInfo.addEventListener('click', function () {
    hideUserFormEl();
    elFPhone.value = elFName.value = elFRegno.value = elFEmail.value = '';
    hideError();
  });

  /* keep send button disabled when nothing to send */
  function updateSendBtn() {
    if (state.chatClosed) {
      elSendBtn.disabled = true;
      return;
    }
    var hasText = elTextInput.value.trim() !== '';
    var hasFile = !!state.attachedFile;
    elSendBtn.disabled = !hasText && !hasFile;
  }
  elTextInput.addEventListener('input', updateSendBtn);
  updateSendBtn();

})();
