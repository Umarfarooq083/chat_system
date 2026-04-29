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
        .msg { max-width: 85%; padding: 10px 12px; border-radius: 12px; margin: 6px 0; font-size: 13px; line-height: 1.35; word-break: break-word; }
        .msg.visitor { margin-left: auto; background:  rgb(17 101 226); color: #fff; border-bottom-right-radius: 4px; }
        .msg.agent { margin-right: auto; background: #fff; color: #111827; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }
        .meta { font-size: 11px; opacity: .75; margin-top: 4px; }
        .composer { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #e5e7eb; background: #fff; }
        .input { flex: 1; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; font-size: 13px; outline: none; }
        .send { border: 0; border-radius: 10px; padding: 10px 14px; background: var(--brand); color: #fff; cursor: pointer; font-weight: 600; }
        .send:disabled { opacity: .6; cursor: not-allowed; }
        .new-chat { background: #16a34a; }
        .attach-btn { border: 1px solid #d1d5db; border-radius: 20px; padding: 7px 13px 7px 14px; background: #fff; cursor: pointer; font-size: 14px; }
        .hint { padding: 10px 12px; color: #6b7280; font-size: 12px; }
        .prechat-form { display: none; border-top: 1px solid #e5e7eb; padding: 12px; background: #ecfeff; }
        .prechat-form.show { display: block; }
        .info-form { display: none; border-top: 1px solid #e5e7eb; padding: 12px; background: #eff6ff; }
        .info-form.show { display: block; }
        .form-row { display: grid; gap: 8px; margin-bottom: 8px; }
        .form-input { flex: 1; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 10px; font-size: 13px; outline: none; }
        .form-input:focus { border-color: var(--brand); }
        .form-btn { border: 0; border-radius: 6px; padding: 8px 12px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .form-btn.primary { background: var(--brand); color: #fff; }
        .form-btn.secondary { background: #6b7280; color: #fff; }
        .attachment { display: flex; align-items: center; gap: 8px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 8px; margin: 8px; margin-bottom: 0; }
        .attachment img { width: 32px; height: 32px; object-fit: cover; border-radius: 4px; }
        .attachment .remove { cursor: pointer; color: #ef4444; font-weight: bold; }
    </style>

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">


</head>
<body>
<div class="wrap">
    <div class="header">
        <div class="title">{{ $title }}</div>
        <button class="btn" id="closeBtn" type="button">-</button>
    </div>
    <div class="body" id="messages"></div>
    <div class="prechat-form" id="prechatForm">
        <div class="hint" style="color: #0e7490; font-weight: 600;">To start chat, please provide:</div>
        <div class="form-row">
            <input class="form-input" id="prechatName" type="text" placeholder="Your Name" required>
            <input class="form-input" id="prechatPhone" type="tel" placeholder="Phone No" required>
        </div>
        <div class="form-row" style="justify-content: flex-end; gap: 8px;">
            <button class="form-btn primary" id="prechatSubmit" type="button">Start Chat</button>
        </div>
    </div>
    <div class="info-form" id="infoForm">
        <div class="hint" style="color: #1e40af; font-weight: 600;">Please provide your information:</div>
        <div class="form-row">
            <input class="form-input" id="phone" type="tel" placeholder="Phone No" required>
            <input class="form-input" id="customerName" type="text" placeholder="Customer Name" required>
        </div>
        <div class="form-row">
            <div id="registrationNoList" style="display: grid; gap: 8px;">
                <div class="reg-row" style="display: flex; gap: 8px; align-items: center;">
                    <input class="form-input reg-input" type="text" placeholder="Registration No" required>
                    <button class="form-btn secondary" id="addRegistrationNo" type="button" style="white-space: nowrap;">+ Add</button>
                </div>
            </div>
            <input class="form-input" id="email" type="email" placeholder="Email">
        </div>
        <div class="form-row" style="justify-content: flex-end; gap: 8px;">
            <button class="form-btn secondary" id="cancelInfo" type="button">Cancel</button>
            <button class="form-btn primary" id="submitInfo" type="button">Submit</button>
        </div>
    </div>
    <div class="composer">
        <button class="attach-btn" id="attachBtn" type="button" title="Attach file"><i data-v-bff3308f="" class="fa fa-paperclip"></i></button>
        <input class="input" id="text" placeholder="Type a message…" autocomplete="off">
        <input type="file" id="fileInput" style="display: none;" accept="image/*,.pdf,.doc,.docx,.txt">
        <button class="send" id="sendBtn" type="button" disabled>Send</button>
        <button class="send new-chat" id="newChatBtn" type="button" style="display: none;">New Chat</button>
    </div>
</div>

<script src="https://js.pusher.com/8.4/pusher.min.js"></script>

<script>
(() => {
    const title = @json($title);
    const initialVisitorId = @json($visitorId);
    const companyId = @json($companyId);
    const apiBase = '/api/widget';
    const messagesEl = document.getElementById('messages');
    const textEl = document.getElementById('text');
    const sendBtn = document.getElementById('sendBtn');
    const attachBtn = document.getElementById('attachBtn');
    const fileInput = document.getElementById('fileInput');
    const newChatBtn = document.getElementById('newChatBtn');
    const prechatFormEl = document.getElementById('prechatForm');
    const prechatNameEl = document.getElementById('prechatName');
    const prechatPhoneEl = document.getElementById('prechatPhone');
    const prechatSubmitBtn = document.getElementById('prechatSubmit');
    const infoFormEl = document.getElementById('infoForm');
    const phoneEl = document.getElementById('phone');
    const customerNameEl = document.getElementById('customerName');
    const registrationNoListEl = document.getElementById('registrationNoList');
    const addRegistrationNoBtn = document.getElementById('addRegistrationNo');
    const emailEl = document.getElementById('email');
    const submitInfoBtn = document.getElementById('submitInfo');
    const cancelInfoBtn = document.getElementById('cancelInfo');

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
    let showPrechatForm = false;
    let showUserForm = false;
    let attachedFile = null;
    let lastSentUrl = null;
    let urlTrackingSetup = false;
    let agentLastReadAt = null;
    let visitorLastReadAt = null;
    let chatClosed = false;

    function getRegistrationNumbers() {
        if (!registrationNoListEl) return [];
        const inputs = registrationNoListEl.querySelectorAll('input.reg-input');
        return Array.from(inputs)
            .map((i) => (i.value || '').toString().trim())
            .filter((v) => v !== '');
    }

    function addRegistrationInput() {
        if (!registrationNoListEl) return;
        const row = document.createElement('div');
        row.className = 'reg-row';
        row.style.display = 'flex';
        row.style.gap = '8px';
        row.style.alignItems = 'center';

        const input = document.createElement('input');
        input.className = 'form-input reg-input';
        input.type = 'text';
        input.placeholder = 'Registration No';
        input.required = true;

        const removeBtn = document.createElement('button');
        removeBtn.className = 'form-btn secondary';
        removeBtn.type = 'button';
        removeBtn.textContent = 'Remove';
        removeBtn.style.whiteSpace = 'nowrap';
        removeBtn.addEventListener('click', () => row.remove());

        row.appendChild(input);
        row.appendChild(removeBtn);
        registrationNoListEl.appendChild(row);
        input.focus();
    }

    function resetRegistrationInputs() {
        if (!registrationNoListEl) return;
        registrationNoListEl.innerHTML = '';

        const row = document.createElement('div');
        row.className = 'reg-row';
        row.style.display = 'flex';
        row.style.gap = '8px';
        row.style.alignItems = 'center';

        const input = document.createElement('input');
        input.className = 'form-input reg-input';
        input.type = 'text';
        input.placeholder = 'Registration No';
        input.required = true;

        const addBtn = document.createElement('button');
        addBtn.className = 'form-btn secondary';
        addBtn.id = 'addRegistrationNo';
        addBtn.type = 'button';
        addBtn.textContent = '+ Add';
        addBtn.style.whiteSpace = 'nowrap';
        addBtn.addEventListener('click', addRegistrationInput);

        row.appendChild(input);
        row.appendChild(addBtn);
        registrationNoListEl.appendChild(row);
    }

    function formatMessageBody(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return value;
        try { return JSON.stringify(value, null, 2); } catch { return String(value); }
    }

    function toMillis(value) {
        if (!value) return null;
        const ts = new Date(value).getTime();
        return Number.isNaN(ts) ? null : ts;
    }

    function isVisitorMessageReadByAgent(message) {
        if (!message || message.sender_type !== 'visitor') return false;
        const messageTs = toMillis(message.created_at);
        const agentReadTs = toMillis(agentLastReadAt);
        return messageTs !== null && agentReadTs !== null && messageTs <= agentReadTs;
    }

    function renderMessage(latestMessage) {
        // Prevent duplicate messages
        if (latestMessage.id && renderedMessageIds.has(latestMessage.id)) {
            return;
        }
        
        const div = document.createElement('div');
        div.className = 'msg ' + (latestMessage.sender_type === 'visitor' ? 'visitor' : 'agent');
        div.dataset.senderType = latestMessage.sender_type || '';
        div.dataset.createdAt = latestMessage.created_at || '';
        const body = document.createElement('div');
        
        if (latestMessage.message_type === 'user_info_response') {
            try {
                const info = typeof latestMessage.message === 'string' ? JSON.parse(latestMessage.message) : latestMessage.message;
                body.innerHTML = `
                    <div style="font-weight: 600; margin-bottom: 4px;">User Information Sent:</div>
                    <div>Name: ${info.name || ''}</div>
                    <div>Email: ${info.email || ''}</div>
                    <div>Phone: ${info.phone || ''}</div>
                    <div>Reg No: ${info.registration_no || ''}</div>
                `;
            } catch (e) {
                body.textContent = formatMessageBody(latestMessage.message);
            }
        } else if (latestMessage.message_type === 'prechat_info_response') {
            try {
                const info = typeof latestMessage.message === 'string' ? JSON.parse(latestMessage.message) : latestMessage.message;
                body.innerHTML = `
                    <div style="font-weight: 600; margin-bottom: 4px;">Visitor Details:</div>
                    <div>Name: ${info.name || ''}</div>
                    <div>Phone: ${info.phone || ''}</div>
                `;
            } catch (e) {
                body.textContent = formatMessageBody(latestMessage.message);
            }
        } else {
            body.textContent = formatMessageBody(latestMessage.message);
        }
        
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
            const formatted = new Date(latestMessage.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const tick = latestMessage.sender_type === 'visitor'
                ? (isVisitorMessageReadByAgent(latestMessage) ? ' ✓✓' : ' ✓')
                : '';
            meta.textContent = formatted + tick;
            div.appendChild(meta);
        }
        messagesEl.appendChild(div);
        
        // Track rendered message ID
        if (latestMessage.id) {
            renderedMessageIds.add(latestMessage.id);
        }
    }

    function refreshVisitorTicks() {
        const rows = messagesEl.querySelectorAll('.msg.visitor');
        rows.forEach((row) => {
            const createdAt = row.dataset.createdAt;
            const meta = row.querySelector('.meta');
            if (!createdAt || !meta) return;
            const formatted = new Date(createdAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const read = isVisitorMessageReadByAgent({ sender_type: 'visitor', created_at: createdAt });
            meta.textContent = formatted + (read ? ' ✓✓' : ' ✓');
        });
    }

    function updateFormVisibility() {
        if (chatClosed) {
            showPrechatForm = false;
            showUserForm = false;
        }
        if (showPrechatForm) {
            prechatFormEl.classList.add('show');
        } else {
            prechatFormEl.classList.remove('show');
        }
        if (showUserForm) {
            infoFormEl.classList.add('show');
        } else {
            infoFormEl.classList.remove('show');
        }

        const blockComposer = showPrechatForm === true || chatClosed === true;
        textEl.disabled = blockComposer;
        attachBtn.disabled = blockComposer;
        fileInput.disabled = blockComposer;
        attachBtn.style.opacity = blockComposer ? '0.55' : '1';
        attachBtn.style.cursor = blockComposer ? 'not-allowed' : 'pointer';
        if (blockComposer) {
            removeAttachment();
        }
        updateSendButton();
    }

    function setChatClosed(isClosed) {
        chatClosed = isClosed === true;

        newChatBtn.style.display = chatClosed ? 'inline-flex' : 'none';
        sendBtn.style.display = chatClosed ? 'none' : 'inline-flex';
        textEl.style.display = chatClosed ? 'none' : 'block';
        attachBtn.style.display = chatClosed ? 'none' : 'inline-flex';

        if (chatClosed) {
            removeAttachment();
        }

        updateFormVisibility();
    }

    function applyChatPayload(data) {
        chatId = data.chat.id;
        agentLastReadAt = data.chat?.agent_last_read_at || null;
        visitorLastReadAt = data.chat?.visitor_last_read_at || null;

        messagesEl.innerHTML = '';
        renderedMessageIds.clear();
        lastId = 0;

        showPrechatForm = !!data.chat?.prechat_required;
        showUserForm = false;

        (data.messages || []).forEach(m => {
            renderMessage(m);
            lastId = Math.max(lastId, Number(m.id || 0));
            if (m.message_type === 'user_info_request') {
                showUserForm = true;
            }
            if (m.message_type === 'user_info_response' && m.sender_type === 'visitor') {
                showUserForm = false;
            }
            if (m.message_type === 'prechat_info_request') {
                showPrechatForm = true;
            }
            if (m.message_type === 'prechat_info_response' && m.sender_type === 'visitor') {
                showPrechatForm = false;
            }
        });

        setChatClosed((data.chat?.status || '') === 'close');
        scrollToBottom();

        initPusher();
        subscribeToChatChannel(chatId);
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
        if (res.status === 204) return null;
        const text = await res.text().catch(() => '');
        if (!text) return null;
        try {
            return JSON.parse(text);
        } catch (e) {
            return null;
        }
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
                if (message.message_type === 'user_info_request') {
                    showUserForm = true;
                }
                if (message.message_type === 'user_info_response' && message.sender_type === 'visitor') {
                    showUserForm = false;
                }
                if (message.message_type === 'prechat_info_request') {
                    showPrechatForm = true;
                }
                if (message.message_type === 'prechat_info_response' && message.sender_type === 'visitor') {
                    showPrechatForm = false;
                }
                if (message.sender_type === 'agent') {
                    markVisitorRead();
                }
                updateFormVisibility();
                scrollToBottom();
            }
        });
        channel.bind('App\\Events\\ChatReadUpdated', function(data) {
            if (data.agentLastReadAt) agentLastReadAt = data.agentLastReadAt;
            if (data.visitorLastReadAt) visitorLastReadAt = data.visitorLastReadAt;
            if (data.readerType === 'agent') refreshVisitorTicks();
        });
    }

    async function init() {
        messagesEl.innerHTML = '<div class="hint">Connecting…</div>';
        try {
            const response = await fetch(`${apiBase}/chat`, {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify({
                    visitor_id: visitorId,
                    company_id: companyId,
                    current_url: document.referrer || null,
                    referrer_url: document.referrer || null,
                }),
            });
            if (!response.ok) {
                throw new Error(`Request failed (${response.status}): ${response.statusText}`);
            }
            const data = await response.json();

            applyChatPayload(data);
            
            // initial ping to mark online
            await pingChat(true);
            await markVisitorRead();
            setupUrlTracking();
        } catch (error) {
            messagesEl.innerHTML = `<div class="hint">Failed to connect. Please refresh.<br>${String(error.message || error)}</div>`;
        }
    }

    async function startNewChat() {
        newChatBtn.disabled = true;
        try {
            const previousVisitorId = visitorId;
            const nextVisitorId = uuid();

            const response = await fetch(`${apiBase}/chat/new`, {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify({
                    visitor_id: nextVisitorId,
                    previous_visitor_id: previousVisitorId,
                    company_id: companyId,
                    current_url: document.referrer || null,
                    referrer_url: document.referrer || null,
                }),
            });
            if (!response.ok) {
                const text = await response.text().catch(() => '');
                throw new Error(text || `Request failed (${response.status})`);
            }
            const data = await response.json();
            visitorId = nextVisitorId;
            localStorage.setItem(storageKey, visitorId);
            applyChatPayload(data);
            await pingChat(true);
            await markVisitorRead();
        } catch (e) {
            alert('Failed to start a new chat. Please try again.');
        } finally {
            newChatBtn.disabled = false;
        }
    }

    async function send() {
        if (showPrechatForm) {
            alert('Please provide your name and phone number to start chatting.');
            return;
        }
        const msg = textEl.value.trim();
        const hasText = msg !== '';
        
        const hasFile = attachedFile !== null;
        if (!hasText && !hasFile) return;
        sendBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('visitor_id', visitorId);
            formData.append('chat_id', chatId);
            formData.append('company_id', companyId);
            if (hasText) formData.append('message', msg);
            if (hasFile) formData.append('attachments', attachedFile);
            formData.append('current_url', document.referrer || null);
            formData.append('referrer_url', document.referrer || null);

            const response = await fetch(`${apiBase}/message`, {
                method: 'POST',
                body: formData,
            });
            if (!response.ok) {
                let serverMessage = '';
                try {
                    const contentType = (response.headers.get('content-type') || '').toLowerCase();
                    if (contentType.includes('application/json')) {
                        const json = await response.json();
                        serverMessage = (json && json.message) ? String(json.message) : '';
                    } else {
                        serverMessage = String(await response.text());
                    }
                } catch (e) {
                    serverMessage = '';
                }

                if (response.status === 403 && /closed/i.test(serverMessage || '')) {
                    setChatClosed(true);
                    alert('This chat has been closed. Click “New Chat” to start again.');
                    return;
                }

                throw new Error(serverMessage || `Request failed (${response.status}): ${response.statusText}`);
            }
            const data = await response.json();

            textEl.value = '';
            removeAttachment();
            // Optimistic render: API returns the created message
            if (data && data.message) {
                renderMessage(data.message);
                lastId = Math.max(lastId, Number(data.message.id || 0));
                scrollToBottom();
            }
        } catch (e) {
            alert(String(e.message || 'Failed to send. Please try again.'));
        } finally {
            sendBtn.disabled = false;
            textEl.focus();
        }
    }

    async function submitPrechat() {
        const name = prechatNameEl.value.trim();
        const phone = prechatPhoneEl.value.trim();

        if (!name || !phone) {
            alert('Please fill in the required fields (Name, Phone No).');
            return;
        }

        prechatSubmitBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('visitor_id', visitorId);
            formData.append('chat_id', chatId);
            formData.append('company_id', companyId);
            formData.append('message_type', 'prechat_info_response');
            formData.append('customer_name', name);
            formData.append('phone', phone);
            formData.append('message', JSON.stringify({ type: 'prechat_info_response', name, phone }));
            formData.append('current_url', document.referrer || null);
            formData.append('referrer_url', document.referrer || null);

            const response = await fetch(`${apiBase}/message`, {
                method: 'POST',
                body: formData,
            });
            if (!response.ok) {
                const text = await response.text().catch(() => '');
                throw new Error(text || `Request failed (${response.status})`);
            }
            const data = await response.json();

            showPrechatForm = false;
            updateFormVisibility();
            prechatNameEl.value = '';
            prechatPhoneEl.value = '';

            if (data && data.message) {
                renderMessage(data.message);
                lastId = Math.max(lastId, Number(data.message.id || 0));
                scrollToBottom();
            }
        } catch (error) {
            alert('Failed to submit info. Please try again.');
        } finally {
            prechatSubmitBtn.disabled = false;
        }
    }

    async function submitInfo() {
        const phone = phoneEl.value.trim();
        const customerName = customerNameEl.value.trim();
        const registrationNumbers = getRegistrationNumbers();
        const email = emailEl.value.trim();

        if (!phone || !customerName || registrationNumbers.length === 0) {
            alert('Please fill in the required fields (Phone No, Customer Name, Registration No).');
            return;
        }

        submitInfoBtn.disabled = true;
        try {
            const formData = new FormData();
            formData.append('visitor_id', visitorId);
            formData.append('chat_id', chatId);
            formData.append('company_id', companyId);
            formData.append('message', `Phone No: ${phone}\nCustomer Name: ${customerName}\nRegistration No: ${registrationNumbers.join(', ')}\nEmail: ${email}`);
            formData.append('message_type', 'user_info_response');
            formData.append('phone', phone);
            formData.append('customer_name', customerName);
            registrationNumbers.forEach((reg) => formData.append('registration_no[]', reg));
            if (email) formData.append('email', email);
            formData.append('current_url', document.referrer || null);
            formData.append('referrer_url', document.referrer || null);

            const response = await fetch(`${apiBase}/message`, {
                method: 'POST',
                body: formData,
            });
            if (!response.ok) {
                throw new Error(`Request failed (${response.status}): ${response.statusText}`);
            }
            const data = await response.json();

            showUserForm = false;
            updateFormVisibility();
            // Clear form
            phoneEl.value = '';
            customerNameEl.value = '';
            resetRegistrationInputs();
            emailEl.value = '';
        } catch (error) {
            alert('Failed to send info. Please try again.');
        } finally {
            submitInfoBtn.disabled = false;
        }
    }

    async function markVisitorRead() {
        if (!chatId) return;
        try {
            await postJson(`${apiBase}/chat/read`, { visitor_id: visitorId, chat_id: chatId });
            visitorLastReadAt = new Date().toISOString();
        } catch (e) {
            // no-op: read receipts are best effort
        }
    }

    function cancelInfo() {
        showUserForm = false;
        updateFormVisibility();
        phoneEl.value = '';
        customerNameEl.value = '';
        resetRegistrationInputs();
        emailEl.value = '';
    }

    function setupUrlTracking() {
        if (urlTrackingSetup) return;
        urlTrackingSetup = true;

        const notify = () => pingChat(true);
        window.addEventListener('popstate', notify);
        window.addEventListener('locationchange', notify);

        const pushState = history.pushState;
        if (typeof pushState === 'function') {
            history.pushState = function (...args) {
                pushState.apply(this, args);
                window.dispatchEvent(new Event('locationchange'));
            };
        }

        const replaceState = history.replaceState;
        if (typeof replaceState === 'function') {
            history.replaceState = function (...args) {
                replaceState.apply(this, args);
                window.dispatchEvent(new Event('locationchange'));
            };
        }
    }

    async function pingChat(force = false) {
        if (!chatId) return;
        try {
            const currentUrl = window.location.href;
            if (!force && lastSentUrl === currentUrl) {
                await fetch(`${apiBase}/chat/ping`, {
                    method: 'POST',
                    headers: { 'content-type': 'application/json' },
                    body: JSON.stringify({ visitor_id: visitorId, chat_id: chatId }),
                });
                return;
            }

            lastSentUrl = currentUrl;
            await fetch(`${apiBase}/chat/ping`, {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify({ visitor_id: visitorId, chat_id: chatId, current_url: currentUrl }),
            });
        } catch (err) {
            console.error('Ping failed', err);
        }
    }

    function updateSendButton() {
        if (showPrechatForm || chatClosed) {
            sendBtn.disabled = true;
            return;
        }
        const hasText = textEl.value.trim() !== '';
        const hasFile = attachedFile !== null;
        sendBtn.disabled = !hasText && !hasFile;
    }

    document.getElementById('closeBtn').addEventListener('click', () => {
        window.parent?.postMessage({ type: 'CHAT_WIDGET_CLOSE' }, '*');
    });

    function removeAttachment() {
        if (attachedFile && attachedFile.type.startsWith('image/')) {
            URL.revokeObjectURL(attachedFile.previewUrl);
        }
        attachedFile = null;
        updateAttachmentDisplay();
    }
 function updateAttachmentDisplay() {
        const existing = document.querySelector('.attachment');
        if (existing) existing.remove();
        
        if (!attachedFile) {
            updateSendButton();
            return;
        }
        
        const div = document.createElement('div');
        div.className = 'attachment';
        
        if (attachedFile.type.startsWith('image/')) {
            const img = document.createElement('img');
            attachedFile.previewUrl = URL.createObjectURL(attachedFile);
            img.src = attachedFile.previewUrl;
            img.alt = attachedFile.name;
            div.appendChild(img);
        }
        
        const span = document.createElement('span');
        span.textContent = attachedFile.name;
        div.appendChild(span);
        
        const removeBtn = document.createElement('span');
        removeBtn.className = 'remove';
        removeBtn.textContent = '×';
        removeBtn.onclick = removeAttachment;
        div.appendChild(removeBtn);
        
        // Insert before the composer
        const composer = document.querySelector('.composer');
        composer.parentNode.insertBefore(div, composer);
        updateSendButton();
    }

    attachBtn.addEventListener('click', () => {
        if (showPrechatForm) return;
        fileInput.click();
    });

    fileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) {
            if (file.size > 20 * 1024 * 1024) {
                alert('File too large. Maximum size is 20 MB.');
                return;
            }
            attachedFile = file;
            updateAttachmentDisplay();
        }
        e.target.value = '';
    });

    sendBtn.addEventListener('click', send);
    newChatBtn.addEventListener('click', startNewChat);
    prechatSubmitBtn.addEventListener('click', submitPrechat);
    submitInfoBtn.addEventListener('click', submitInfo);
    cancelInfoBtn.addEventListener('click', cancelInfo);
    if (addRegistrationNoBtn) addRegistrationNoBtn.addEventListener('click', addRegistrationInput);

    textEl.addEventListener('input', updateSendButton);

    // Initialize send button state
    updateSendButton();

    // periodic ping (also updates current URL)
    setInterval(() => {
        pingChat();
    }, 20000);

    init().catch((e) => {
        messagesEl.innerHTML = `<div class="hint">Failed to connect. Please refresh.<br>${String(e.message || e)}</div>`;
    });
})();
</script>
</body>
</html>
