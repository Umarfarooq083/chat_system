<script setup>
import { ref, onMounted, watch, nextTick } from 'vue'
import axios from 'axios'
import { extractErrorMessage } from '../utils/extractErrorMessage'


const open = ref(false)
const messages = ref([])
const message = ref('')
const attachedFiles = ref([])
const fileInputRef = ref(null)
const messageContainer = ref(null)
const showPrechatForm = ref(false)
const showUserForm = ref(false)
const sendError = ref('')
const chatClosed = ref(false)
const chatReadState = ref({
  agent_last_read_at: null,
  visitor_last_read_at: null,
})
const userForm = ref({
  phone: '',
  customerName: '',
  registrationNo: '',
  email: ''
})
const prechatForm = ref({
  name: '',
  phone: '',
})
let chatId = null
let visitorId = null
let lastSentUrl = null
let urlTrackingSetup = false
const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024

const resolveAttachmentUrl = (relativeOrAbsoluteUrl) => {
  if (!relativeOrAbsoluteUrl) return null
  if (/^https?:\/\//i.test(relativeOrAbsoluteUrl)) return relativeOrAbsoluteUrl

  const cfg = window.ChatConfig || {}
  const apiBase = (cfg.apiBase || '').toString().trim()

  // For external widgets, `apiBase` is often like `https://your-domain.com/api`.
  // Attachments live on the web host root, so we only use the URL origin, not the `/api` path.
  if (/^https?:\/\//i.test(apiBase)) {
    try {
      const origin = new URL(apiBase).origin
      return origin + relativeOrAbsoluteUrl
    } catch (e) {
      // fall through
    }
  }

  // If apiBase is not absolute, we can't reliably resolve cross-domain attachments.
  return relativeOrAbsoluteUrl
}

const withToken = (url) => {
  if (!url) return null
  const cfg = window.ChatConfig || {}
  if (!cfg.apiToken) return url
  // Don't mutate signed URLs (adding params breaks the signature)
  if (url.includes('signature=')) return url

  try {
    const u = new URL(url, window.location.origin)
    // only add if not present already
    if (!u.searchParams.get('token')) {
      u.searchParams.set('token', cfg.apiToken)
    }
    return u.toString()
  } catch (e) {
    const join = url.includes('?') ? '&' : '?'
    return url + join + 'token=' + encodeURIComponent(cfg.apiToken)
  }
}

const attachmentViewUrl = (msg) => withToken(resolveAttachmentUrl(msg?.attachment_view_url))
const attachmentDownloadUrl = (msg) => (resolveAttachmentUrl(msg?.attachment_download_url || msg?.attachment_view_url))

const formatTime = (timestamp) => {
  const date = new Date(timestamp)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const toMillis = (value) => {
  if (!value) return null
  const ts = new Date(value).getTime()
  return Number.isNaN(ts) ? null : ts
}

const isVisitorMessageReadByAgent = (msg) => {
  if (!msg || msg.sender_type !== 'visitor') return false
  const messageTs = toMillis(msg.created_at)
  const agentReadTs = toMillis(chatReadState.value.agent_last_read_at)
  return messageTs !== null && agentReadTs !== null && messageTs <= agentReadTs
}

const scrollToBottom = async () => {
  await nextTick()
  if (messageContainer.value) {
    messageContainer.value.scrollTop = messageContainer.value.scrollHeight
  }
}

watch(messages, () => {
  scrollToBottom()
}, { deep: true })

onMounted(async () => {
  try {
    // configuration for external use
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    // determine URL for creating/getting chat
    const createUrl = apiBase
      ? apiBase + '/chat'
      : '/visitor-chat/create'

    const response = await axios.post(createUrl, { current_url: window.location.href }, { headers })
    chatId = response.data.chat.id
    visitorId = response.data?.chat?.visitor_id || null
    chatReadState.value.agent_last_read_at = response.data?.chat?.agent_last_read_at || null
    chatReadState.value.visitor_last_read_at = response.data?.chat?.visitor_last_read_at || null
    messages.value = response.data.messages
    
    // initial ping to mark online
    await pingChat(true)
    await markVisitorRead()
    setupUrlTracking()

    // Check if there's a pending user info request (request exists but no response yet)
    const hasPendingRequest = messages.value.some(msg => msg.message_type === 'user_info_request') && 
                             !messages.value.some(msg => msg.message_type === 'user_info_response')
    if (hasPendingRequest) {
      showUserForm.value = true
    }

    const chat = response.data?.chat || {}
    const phone = (chat.phone || '').toString().trim()
    const name = (chat.customer_name || '').toString().trim()
    const hasBasicInfo = !!phone && !!name
    const hasPrechatResponse = messages.value.some(msg => msg.message_type === 'prechat_info_response' && msg.sender_type === 'visitor')
    showPrechatForm.value = !chat.prechat_submitted_at && !chat.user_info_submitted_at && !hasBasicInfo && !hasPrechatResponse
    
    await scrollToBottom()

    if (window.Echo) {
      window.Echo.channel('chat.' + chatId)
        .listen('MessageSent', (e) => {
          messages.value.push(e.message)
          if (e.message.message_type === 'prechat_info_request') {
            showPrechatForm.value = true
          }
          if (e.message.message_type === 'prechat_info_response' && e.message.sender_type === 'visitor') {
            showPrechatForm.value = false
          }
          // Check if this is a user info request
          if (e.message.message_type === 'user_info_request') {
            showUserForm.value = true
          }
          // Hide form if user just submitted response
          if (e.message.message_type === 'user_info_response' && e.message.sender_type === 'visitor') {
            showUserForm.value = false
          }
          if (e.message.sender_type === 'agent') {
            markVisitorRead()
          }
        })
        .listen('ChatReadUpdated', (e) => {
          if (e.agentLastReadAt) {
            chatReadState.value.agent_last_read_at = e.agentLastReadAt
          }
          if (e.visitorLastReadAt) {
            chatReadState.value.visitor_last_read_at = e.visitorLastReadAt
          }
        })
    }

    // periodic ping (also updates current URL)
    setInterval(() => {
      pingChat()
    }, 20000);
  } catch (error) {
    console.error('Failed to initialize chat:', error)
  }
})

const setupUrlTracking = () => {
  if (urlTrackingSetup) return
  urlTrackingSetup = true

  const notify = () => pingChat(true)
  window.addEventListener('popstate', notify)
  window.addEventListener('locationchange', notify)

  const pushState = history.pushState
  if (typeof pushState === 'function') {
    history.pushState = function (...args) {
      pushState.apply(this, args)
      window.dispatchEvent(new Event('locationchange'))
    }
  }

  const replaceState = history.replaceState
  if (typeof replaceState === 'function') {
    history.replaceState = function (...args) {
      replaceState.apply(this, args)
      window.dispatchEvent(new Event('locationchange'))
    }
  }
}

const pingChat = async (force = false) => {
  if (!chatId) return
  try {
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    const pingUrl = apiBase ? apiBase + '/chat/ping' : '/chat/ping'
    const currentUrl = window.location.href
    if (!force && lastSentUrl === currentUrl) {
      await axios.post(pingUrl, { chat_id: chatId }, { headers })
      return
    }

    lastSentUrl = currentUrl
    await axios.post(pingUrl, { chat_id: chatId, current_url: currentUrl }, { headers })
  } catch (err) {
    console.error('Ping failed', err)
  }
}

const markVisitorRead = async () => {
  if (!chatId) return
  try {
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    const readUrl = apiBase ? apiBase + '/chat/read' : '/chat/read'
    const payload = { chat_id: chatId }
    if (visitorId) payload.visitor_id = visitorId
    await axios.post(readUrl, payload, { headers })
    chatReadState.value.visitor_last_read_at = new Date().toISOString()
  } catch (err) {
    // silent: receipts should not block chatting
  }
}

const triggerFileInput = () => {
  fileInputRef.value?.click()
}

const onFileInputChange = (event) => {
  addFiles(Array.from(event.target.files || []))
  event.target.value = ''
}

const addFiles = (newFiles) => {
  const file = newFiles[0]
  if (!file) return
  if (file.size > MAX_ATTACHMENT_BYTES) {
    sendError.value = 'File too large. Maximum size is 20 MB.'
    return
  }
  attachedFiles.value = []
  const isImage = file.type.startsWith('image/')
  const preview = isImage ? URL.createObjectURL(file) : null
  attachedFiles.value.push({ file, preview, isImage })
}

const removeAttachment = (index) => {
  const item = attachedFiles.value[index]
  if (item?.preview) URL.revokeObjectURL(item.preview)
  attachedFiles.value.splice(index, 1)
}

const clearAttachments = () => {
  attachedFiles.value.forEach(item => {
    if (item.preview) URL.revokeObjectURL(item.preview)
  })
  attachedFiles.value = []
}

const sendMessage = async () => {
  if (!chatId) return
  if (showPrechatForm.value) {
    sendError.value = 'Please provide your name and phone number to start chatting.'
    return
  }
  const hasText = message.value.trim() !== ''
  const hasFiles = attachedFiles.value.length > 0
  if (!hasText && !hasFiles) return

  const userMessage = message.value
  const tempFiles = [...attachedFiles.value]
  message.value = ''
  clearAttachments()

  try {
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    const sendUrl = apiBase ? apiBase + '/message' : '/send-message'

    if (hasFiles) {
      const formData = new FormData()
      formData.append('chat_id', chatId)
      formData.append('message', userMessage)
      formData.append('sender_type', 'visitor')
      if (tempFiles[0]?.file) {
        formData.append('attachments', tempFiles[0].file)
      }

      await axios.post(sendUrl, formData, { headers })
    } else {
      await axios.post(sendUrl, {
        chat_id: chatId,
        message: userMessage,
        sender_type: 'visitor'
      }, { headers })
    }
    sendError.value = ''
    await scrollToBottom()
  } catch (error) {
    console.error('Failed to send message:', error)
    sendError.value = extractErrorMessage(error, 'Failed to send. Please try again.')
    // Check if chat was closed
    if (error?.response?.status === 403 && error?.response?.data?.message?.includes('closed')) {
      chatClosed.value = true
    }
    message.value = userMessage
    attachedFiles.value = tempFiles
  }
}

const submitPrechatInfo = async () => {
  if (!chatId) return

  const name = (prechatForm.value.name || '').trim()
  const phone = (prechatForm.value.phone || '').trim()

  if (!name || !phone) {
    sendError.value = 'Please fill in the required fields (Name, Phone No).'
    return
  }

  try {
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    const sendUrl = apiBase ? apiBase + '/message' : '/send-message'

    await axios.post(sendUrl, {
      chat_id: chatId,
      message: JSON.stringify({ type: 'prechat_info_response', name, phone }),
      sender_type: 'visitor',
      message_type: 'prechat_info_response',
      customer_name: name,
      phone,
    }, { headers })

    sendError.value = ''
    showPrechatForm.value = false
    prechatForm.value = { name: '', phone: '' }
    await scrollToBottom()
  } catch (error) {
    console.error('Failed to send pre-chat info:', error)
    sendError.value = extractErrorMessage(error, 'Failed to send. Please try again.')
  }
}

const submitUserInfo = async () => {
  if (!chatId) return

  const phone = (userForm.value.phone || '').trim()
  const customerName = (userForm.value.customerName || '').trim()
  const registrationNo = (userForm.value.registrationNo || '').trim()
  const email = (userForm.value.email || '').trim()

  if (!phone || !customerName || !registrationNo) {
    sendError.value = 'Please fill in the required fields (Phone No, Customer Name, Registration No).'
    return
  }

  const lines = [
    'User Information:',
    'Phone No: ' + phone,
    'Customer Name: ' + customerName,
    'Registration No: ' + registrationNo,
  ]
  if (email) lines.push('Email: ' + email)
  const userInfoMessage = lines.join('\n')

  try {
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    const sendUrl = apiBase ? apiBase + '/message' : '/send-message'

    await axios.post(sendUrl, {
      chat_id: chatId,
      message: userInfoMessage,
      sender_type: 'visitor',
      message_type: 'user_info_response',
      phone,
      customer_name: customerName,
      registration_no: registrationNo,
      email: email || null
    }, { headers })
     
    sendError.value = ''
    showUserForm.value = false
    userForm.value = { phone: '', customerName: '', registrationNo: '', email: '' }
    await scrollToBottom()
  } catch (error) {
    console.error('Failed to send user info:', error)
    sendError.value = extractErrorMessage(error, 'Failed to send. Please try again.')
  }
}

const getUserInfo = (msg) => {
  try {
    return typeof msg.message === 'string'
      ? JSON.parse(msg.message)
      : msg.message
  } catch (e) {
    return {}
  }
}

const cancelUserInfo = () => {
  showUserForm.value = false
  userForm.value = { phone: '', customerName: '', registrationNo: '', email: '' }
}
</script>


<template>
  <div v-show="!open" class="chat_btn fixed bottom-5 right-5 bg-primary d-inline-flex align-items-center justify-content-center text-white rounded-full" @click="open = true">
    <i class="fa fa-commenting fa-3x"></i>
  </div>
  <div v-show="open" class="fixed bottom-5 right-5 z-50 cstm_chat">
    <div class="bg-primary text-white p-2 rounded-t-lg cursor-pointer d-inline-flex justify-content-between align-items-center px-3 w-100" @click="open = false">
      <div>Chat with us</div>
      <i class="fa fa-minus"></i>
    </div>

    <div class="bg-white shadow-lg rounded-b-lg h-96 flex flex-col overflow-hidden border-t position-relative">
      <div ref="messageContainer" class="flex-1 overflow-y-auto p-3 space-y-3">
        <div 
          v-for="(msg, index) in messages" 
          :key="index"
          :class="[
            'flex',
            msg.sender_type === 'visitor' ? 'justify-end' : 'justify-start'
          ]"
          v-show="msg.message_type !== 'user_info_request'"
        >
          <div 
            :class="[
              'max-w-[80%] rounded-lg px-3 py-2 break-words',
              msg.sender_type === 'visitor' 
                ? 'bg-primary text-white rounded-br-none' 
                : 'bg-gray-100 text-gray-800 rounded-bl-none'
            ]"
          >
            <div class="text-xs opacity-75 mb-1">
              {{ msg.sender_type === 'visitor' ? 'You' : 'Agent' }}
            </div>
           
            <div class="text-sm whitespace-pre-line" v-if="msg?.message_type === 'user_info_response'">
              <div class="text-sm whitespace-pre-line">
                <strong class="text-xs font-bold whitespace-pre-line mb-1.5 flex items-center gap-1.5" style="font-size: 15px;">
                  User Information Send:
                </strong>
                      <div><strong>Name:</strong> {{ getUserInfo(msg).name }}</div>
                      <div><strong>Email:</strong> {{ getUserInfo(msg).email }}</div>
                      <div><strong>Phone:</strong> {{ getUserInfo(msg).phone }}</div>
                      <div><strong>Reg No:</strong> {{ getUserInfo(msg).registration_no }}</div>
                    </div>

            </div>

            <div class="text-sm whitespace-pre-line" v-else-if="msg?.message_type === 'prechat_info_response'">
              <div class="text-sm whitespace-pre-line">
                <strong class="text-xs font-bold whitespace-pre-line mb-1.5 flex items-center gap-1.5" style="font-size: 15px;">
                  Visitor Details:
                </strong>
                <div><strong>Name:</strong> {{ getUserInfo(msg).name }}</div>
                <div><strong>Phone:</strong> {{ getUserInfo(msg).phone }}</div>
              </div>
            </div>

            <!-- <div class="text-sm whitespace-pre-line" v-else-if="msg?.message_type === 'external_data_html'">
              <iframe v-if="attachmentViewUrl(msg)"
                :src="attachmentViewUrl(msg)"
                sandbox
                class="w-full rounded border border-gray-200 bg-white"
                style="height: 280px;"
              />
              <iframe v-else
                :srcdoc="msg.message"
                sandbox
                class="w-full rounded border border-gray-200 bg-white"
                style="height: 280px;"
              />
            </div> -->

            <div class="text-sm whitespace-pre-line" v-else>{{ msg.message }}</div>
            <div v-if="attachmentViewUrl(msg)" class="mt-2">
              <img v-if="msg.attachment_is_image" :src="attachmentViewUrl(msg)"
                :alt="msg.attachment_name || 'Attachment'"
                class="max-w-[180px] max-h-40 rounded border border-gray-200 object-cover cursor-pointer"
                @click="window.open(attachmentViewUrl(msg), '_blank')" />
              <div v-if="msg.attachment_is_image" class="mt-1 text-right">
                <a :href="attachmentDownloadUrl(msg)" :download="msg.attachment_name" target="_blank" rel="noopener">
                 <i class="fa fa-download" aria-hidden="true"></i>
                </a>
              </div>
              <a v-else :href="attachmentDownloadUrl(msg)" :download="msg.attachment_name" target="_blank" rel="noopener"
                class="text-xs underline break-all">
                Download {{ msg.attachment_name || 'file' }}
              </a>
            </div>
            <div class="text-xs opacity-50 text-right mt-1">
              {{ formatTime(msg.created_at) }}
              <span v-if="msg.sender_type === 'visitor'" :class="isVisitorMessageReadByAgent(msg) ? 'text-info' : ''">
                {{ isVisitorMessageReadByAgent(msg) ? ' ✓✓' : ' ✓' }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Send error -->
      <div v-if="sendError"
        class="border-t border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 flex items-start justify-between gap-2">
        <span class="whitespace-pre-line">{{ sendError }}</span>
        <button type="button" class="text-red-700 font-bold leading-none" @click="sendError = ''">×</button>
      </div>

      <!-- Pre-chat Form -->
      <div v-if="showPrechatForm" class="border-t p-3 bg-cyan-50 info_form">
        <h4 class="font-semibold text-cyan-800 mb-3">To start chat, please provide:</h4>
        <form @submit.prevent="submitPrechatInfo" class="row gx-1">
          <div class="col-6 mb-1">
            <input
              v-model="prechatForm.name"
              type="text"
              required
              placeholder="Your Name"
              class="form-control form-control-sm"
            />
          </div>
          <div class="col-6 mb-1">
            <input
              v-model="prechatForm.phone"
              type="tel"
              required
              placeholder="Phone No"
              class="form-control form-control-sm"
            />
          </div>
          <div class="col-md-12 mt-2">
            <div class="row gap-2 m-0 justify-content-end">
              <button type="submit" class="btn btn-primary btn-sm w-25">
                Start Chat
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- User Info Form -->
      <div v-if="showUserForm" class="border-t p-3 bg-blue-50 info_form">
        <h4 class="font-semibold text-blue-800 mb-3">Please provide your information:</h4>
        <form @submit.prevent="submitUserInfo" class="row gx-1">
          <div class="col-6 mb-1">
            <input
              v-model="userForm.phone"
              type="tel"
              required
              placeholder="Phone No"
              class="form-control form-control-sm"
            />
          </div>
          <div class="col-6 mb-1">
            <input
              v-model="userForm.customerName"
              type="text"
              required
              placeholder="Customer Name"
              class="form-control form-control-sm"
            />
          </div>
          <div class="col-6 mb-1">
            <input
              v-model="userForm.registrationNo"
              type="text"
              required
              placeholder="Registration No"
              class="form-control form-control-sm"
            />
          </div>
          <div class="col-6 mb-1">
            <input
              v-model="userForm.email"
              type="email"
              placeholder="Email"
              class="form-control form-control-sm"
            />
          </div>
            <div class="col-md-12 mt-2">
            <div class="row gap-2 m-0 justify-content-end">
              <button
                type="submit"
                class="btn btn-primary btn-sm w-25"
              >
                Submit
              </button>
              <button
                type="button"
                @click="cancelUserInfo"
                class="btn btn-secondary btn-sm w-25"
              >
                Cancel
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Input area -->
      <div  class="border-t p-2">
        <!-- Chat Closed Overlay -->
        <div v-if="chatClosed" class="absolute inset-0 bg-white/90 flex flex-col items-center justify-center z-10" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0;">
          <div class="text-center">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mx-auto mb-2 text-slate-400">
              <path d="M18 6 6 18" />
              <path d="M6 6l12 12" />
            </svg>
            <p class="text-sm font-semibold text-slate-600">Chat Closed</p>
            <p class="text-xs text-slate-400 mt-1">No further messages can be sent.</p>
          </div>
        </div>

        <div v-if="attachedFiles.length" class="mb-2 flex flex-wrap gap-2">
          <div v-for="(item, index) in attachedFiles" :key="index"
            class="relative flex items-center gap-2 bg-gray-100 border border-gray-200 rounded px-2 py-1">
            <img v-if="item.isImage" :src="item.preview" :alt="item.file.name" class="w-10 h-10 object-cover rounded" />
            <div v-else class="text-xs max-w-[180px] truncate">{{ item.file.name }}</div>
            <button type="button" @click="removeAttachment(index)"
              class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-gray-700 text-white text-xs leading-none">
              ×
            </button>
          </div>
        </div>

        <form @submit.prevent="sendMessage" class="flex gap-2">
          <input ref="fileInputRef" type="file" class="hidden" @change="onFileInputChange" />

          <button type="button" @click="triggerFileInput" title="Attach file"
            class="w-20 rounded-5 border-1"
            :disabled="showPrechatForm || chatClosed"
            :class="{ 'opacity-50 cursor-not-allowed': showPrechatForm || chatClosed }">
            <i class="fa fa-paperclip"></i>
          </button>

          <input v-model="message" type="text" placeholder="Type a message..."
            class="form-control rounded-5"
            :disabled="showPrechatForm || chatClosed" />

          <button type="submit"
            class="btn btn-primary btn-sm rounded-5 px-3"
            :disabled="showPrechatForm || chatClosed || (!message.trim() && !attachedFiles.length)"
            :class="{ 'opacity-50 cursor-not-allowed': showPrechatForm || chatClosed || (!message.trim() && !attachedFiles.length) }">
            Send
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.chat_btn {
    width: 90px;
    height: 90px;
}
.flex-1 {
  scroll-behavior: smooth;
}

.flex-1::-webkit-scrollbar {
  width: 6px;
}

.flex-1::-webkit-scrollbar-track {
  background: #f1f1f1;
}

.flex-1::-webkit-scrollbar-thumb {
  background: #888;
  border-radius: 3px;
}

.flex-1::-webkit-scrollbar-thumb:hover {
  background: #555;
}
.cstm_chat{
  width: 350px;
}
</style>
