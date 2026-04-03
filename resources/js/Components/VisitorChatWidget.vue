<script setup>
import { ref, onMounted, watch, nextTick } from 'vue'
import axios from 'axios'
import { extractErrorMessage } from '../utils/extractErrorMessage'

const open = ref(true)
const messages = ref([])
const message = ref('')
const attachedFiles = ref([])
const fileInputRef = ref(null)
const messageContainer = ref(null)
const showUserForm = ref(false)
const sendError = ref('')
const userForm = ref({
  name: '',
  email: '',
  details: ''
})
let chatId = null
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
const attachmentDownloadUrl = (msg) => withToken(resolveAttachmentUrl(msg?.attachment_download_url || msg?.attachment_view_url))

const formatTime = (timestamp) => {
  const date = new Date(timestamp)
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
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
    messages.value = response.data.messages
    
    // initial ping to mark online
    await pingChat(true)
    setupUrlTracking()

    // Check if there's a pending user info request (request exists but no response yet)
    const hasPendingRequest = messages.value.some(msg => msg.message_type === 'user_info_request') && 
                             !messages.value.some(msg => msg.message_type === 'user_info_response')
    if (hasPendingRequest) {
      showUserForm.value = true
    }
    
    await scrollToBottom()

    if (window.Echo) {
      window.Echo.channel('chat.' + chatId)
        .listen('MessageSent', (e) => {
          messages.value.push(e.message)
          // Check if this is a user info request
          if (e.message.message_type === 'user_info_request') {
            showUserForm.value = true
          }
          // Hide form if user just submitted response
          if (e.message.message_type === 'user_info_response' && e.message.sender_type === 'visitor') {
            showUserForm.value = false
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
    message.value = userMessage
    attachedFiles.value = tempFiles
  }
}

const submitUserInfo = async () => {
  if (!chatId) return

  const userInfoMessage = "User Information:\n" +
      "Name: " + userForm.value.name + "\n" +
      "Email: " + userForm.value.email + "\n" +
      "Details: " + userForm.value.details

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
      message_type: 'user_info_response'
    }, { headers })
    
    sendError.value = ''
    showUserForm.value = false
    userForm.value = { name: '', email: '', details: '' }
    await scrollToBottom()
  } catch (error) {
    console.error('Failed to send user info:', error)
    sendError.value = extractErrorMessage(error, 'Failed to send. Please try again.')
  }
}

const cancelUserInfo = () => {
  showUserForm.value = false
  userForm.value = { name: '', email: '', details: '' }
}
</script>


<template>
  <div class="fixed bottom-5 right-5 w-100 z-50">
    <div class="bg-blue-500 text-white p-2 rounded-t-lg cursor-pointer" @click="open = !open">
      Chat with us
    </div>

    <div v-show="open" class="bg-white shadow-lg rounded-b-lg h-96 flex flex-col overflow-hidden border-t">
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
                ? 'bg-blue-500 text-white rounded-br-none' 
                : 'bg-gray-100 text-gray-800 rounded-bl-none'
            ]"
          >
            <div class="text-xs opacity-75 mb-1">
              {{ msg.sender_type === 'visitor' ? 'You' : 'Agent' }}
            </div>
            <div class="text-sm whitespace-pre-line">{{ msg.message }}</div>
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

      <!-- User Info Form -->
      <div v-if="showUserForm" class="border-t p-3 bg-blue-50">
        <h4 class="font-semibold text-blue-800 mb-3">Please provide your information:</h4>
        <form @submit.prevent="submitUserInfo" class="space-y-3">
          <div>
            <input
              v-model="userForm.name"
              type="text"
              required
              placeholder="Your Name"
              class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
            />
          </div>
          <div>
            <input
              v-model="userForm.email"
              type="email"
              required
              placeholder="Your Email"
              class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
            />
          </div>
          <div>
            <textarea
              v-model="userForm.details"
              required
              rows="3"
              placeholder="Additional Details"
              class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
            ></textarea>
          </div>
          <div class="flex gap-2">
            <button
              type="submit"
              class="bg-blue-500 text-white px-4 py-2 rounded text-sm hover:bg-blue-600 transition-colors"
            >
              Submit
            </button>
            <button
              type="button"
              @click="cancelUserInfo"
              class="bg-gray-500 text-white px-4 py-2 rounded text-sm hover:bg-gray-600 transition-colors"
            >
              Cancel
            </button>
          </div>
        </form>
      </div>

      <!-- Input area -->
      <div v-else class="border-t p-2">
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
            class="w-10 h-10 flex items-center justify-center rounded-full border border-gray-300 text-gray-600 hover:bg-gray-100">
            <svg width="35" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
              stroke-linecap="round" stroke-linejoin="round">
              <path
                d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
            </svg>
          </button>

          <input v-model="message" type="text" placeholder="Type a message..."
            class="border rounded-full px-4 py-2 flex-1 focus:outline-none focus:ring-2 focus:ring-blue-300" />

          <button type="submit"
            class="bg-blue-500 text-white px-4 py-2 rounded-full hover:bg-blue-600 transition-colors"
            :disabled="!message.trim() && !attachedFiles.length"
            :class="{ 'opacity-50 cursor-not-allowed': !message.trim() && !attachedFiles.length }">
            Send
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
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
</style>
