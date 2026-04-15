<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { --brand: {{ $brandColor }}; }
        html, body { height: 100%; margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Apple Color Emoji", "Segoe UI Emoji"; }
        .wrap { height: 100%; display: flex; flex-direction: column; background: #fff; }
        .header { display: flex; align-items: center; justify-content: space-between; padding: 12px 12px; background: rgb(17 101 226); color: #fff; }
        .title { font-size: 14px; font-weight: 600; }
        .btn { border: 0; background: rgba(255,255,255,.15); color: #fff; padding: 6px 10px; border-radius: 8px; cursor: pointer; }
        .body { flex: 1; overflow: auto; padding: 12px; background: #f9fafb; }
        .msg { max-width: 85%; padding: 10px 12px; border-radius: 12px; margin: 6px 0; font-size: 13px; line-height: 1.35; white-space: pre-wrap; word-break: break-word; }
        .msg.visitor { margin-left: auto; background:  rgb(17 101 226); color: #fff; border-bottom-right-radius: 4px; }
        .msg.agent { margin-right: auto; background: #fff; color: #111827; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }
        .meta { font-size: 11px; opacity: .75; margin-top: 4px; }
        .composer { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #e5e7eb; background: #fff; }
        .input { flex: 1; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; font-size: 13px; outline: none; }
        .send { border: 0; border-radius: 10px; padding: 10px 14px; background: var(--brand); color: #fff; cursor: pointer; font-weight: 600; }
        .send:disabled { opacity: .6; cursor: not-allowed; }
        .hint { padding: 10px 12px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="title">{{ $title }}</div>
        <button class="btn" id="closeBtn" type="button">-</button>
    </div>
    <div class="body" id="messages"></div>
    <div class="composer">
        <input class="input" id="text" placeholder="Type a message…" autocomplete="off">
        <button class="send" id="sendBtn" type="button">Send</button>
    </div>
</div>

<script src="https://js.pusher.com/8.4/pusher.min.js"></script>

<script>
(() => {
    const title = @json($title);
    const initialVisitorId = @json($visitorId);
    const apiBase = '/api/widget';
    const messagesEl = document.getElementById('messages');
    const textEl = document.getElementById('text');
    const sendBtn = document.getElementById('sendBtn');

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'v-' + Math.random().toString(16).slice(2) + '-' + Date.now().toString(16);
    }

    const storageKey = 'chat_widget_vid';
    let visitorId = (typeof initialVisitorId === 'string' && initialVisitorId.trim() !== '') ? initialVisitorId.trim() : (localStorage.getItem(storageKey) || '');
    if (!visitorId) {
        visitorId = uuid();
        localStorage.setItem(storageKey, visitorId);
    } else {
        localStorage.setItem(storageKey, visitorId);
    }

    let chatId = null;
    let lastId = 0;
    let pusher = null;
    let channelSubscription = null;
    let renderedMessageIds = new Set();

    function formatMessageBody(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return value;
        try { return JSON.stringify(value, null, 2); } catch { return String(value); }
    }

    function renderMessage(latestMessage) {
        // Prevent duplicate messages
        if (latestMessage.id && renderedMessageIds.has(latestMessage.id)) {
            return;
        }
        
        const div = document.createElement('div');
        div.className = 'msg ' + (latestMessage.sender_type === 'visitor' ? 'visitor' : 'agent');
        const body = document.createElement('div');
        body.textContent = formatMessageBody(latestMessage.message);
        div.appendChild(body);  
        if (latestMessage.attachment_download_url) {
            const a = document.createElement('a');
            a.href = latestMessage.attachment_download_url;
            a.textContent = latestMessage.attachment_name ? ('Download: ' + latestMessage.attachment_name) : 'Download attachment';
            a.target = '_blank';
            a.rel = 'noreferrer';
            a.style.display = 'block';
            a.style.marginTop = '6px';
            a.style.color = (latestMessage.sender_type === 'visitor') ? '#fff' : '#2563eb';
            div.appendChild(a);
        }
        if (latestMessage.created_at) {
            const meta = document.createElement('div');
            meta.className = 'meta';
            meta.textContent = new Date(latestMessage.created_at).toLocaleString();
            div.appendChild(meta);
        }
        messagesEl.appendChild(div);
        
        // Track rendered message ID
        if (latestMessage.id) {
            renderedMessageIds.add(latestMessage.id);
        }
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    async function postJson(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (!res.ok) {
            const text = await res.text().catch(() => '');
            throw new Error(`Request failed (${res.status}): ${text || res.statusText}`);
        }
        return res.json();
    }

    function initPusher() {
        if (pusher) return;
        
        Pusher.logToConsole = false;
        
        pusher = new Pusher(@json(env('PUSHER_APP_KEY')), {
            cluster: @json(env('PUSHER_APP_CLUSTER')),
            encrypted: true,
            forceTLS: true
        });
    }

    function subscribeToChatChannel(cId) {
        if (!pusher || !cId) return;
        
        // Unsubscribe from previous channel if exists
        if (channelSubscription) {
            pusher.unsubscribe('chat.' + channelSubscription);
        }
        
        channelSubscription = cId;
        const channel = pusher.subscribe('chat.' + cId);
        
        channel.bind('App\\Events\\MessageSent', function(data) {
            const message = data.message;
            if (message && Number(message.id || 0) > lastId) {
                renderMessage(message);
                lastId = Math.max(lastId, Number(message.id || 0));
                scrollToBottom();
            }
        });
    }

    async function init() {
        messagesEl.innerHTML = '<div class="hint">Connecting…</div>';
        try {
            const data = await postJson(`${apiBase}/chat`, {
                visitor_id: visitorId,
                current_url: document.referrer || null,
                referrer_url: document.referrer || null,
            });
            chatId = data.chat.id;
            messagesEl.innerHTML = '';
            renderedMessageIds.clear(); // Clear previous message IDs
            (data.messages || []).forEach(m => {
                renderMessage(m);
                lastId = Math.max(lastId, Number(m.id || 0));
            });
            scrollToBottom();
            
            // Initialize Pusher and subscribe to chat channel
            initPusher();
            subscribeToChatChannel(chatId);
        } catch (error) {
            messagesEl.innerHTML = `<div class="hint">Failed to connect. Please refresh.<br>${String(error.message || error)}</div>`;
        }
    }

    async function send() {
        const msg = textEl.value.trim();
        if (!msg || !chatId) return;
        sendBtn.disabled = true;
        try {
            const data = await postJson(`${apiBase}/message`, {
                visitor_id: visitorId,
                chat_id: chatId,
                message: msg,
                current_url: document.referrer || null,
                referrer_url: document.referrer || null,
            });
            textEl.value = '';
            // Optimistic render: API returns the created message
            if (data && data.message) {
                renderMessage(data.message);
                lastId = Math.max(lastId, Number(data.message.id || 0));
                scrollToBottom();
            }
        } finally {
            sendBtn.disabled = false;
            textEl.focus();
        }
    }

    document.getElementById('closeBtn').addEventListener('click', () => {
        window.parent?.postMessage({ type: 'CHAT_WIDGET_CLOSE' }, '*');
    });

    sendBtn.addEventListener('click', send);
    textEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });

    init().catch((e) => {
        messagesEl.innerHTML = `<div class="hint">Failed to connect. Please refresh.<br>${String(e.message || e)}</div>`;
    });
})();
</script>
</body>
</html>

