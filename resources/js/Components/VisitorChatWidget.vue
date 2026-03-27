<script setup>
import { ref, onMounted, watch, nextTick } from 'vue'
import axios from 'axios'

const open = ref(true)
const messages = ref([])
const message = ref('')
const messageContainer = ref(null)
const showUserForm = ref(false)
const userForm = ref({
  name: '',
  email: '',
  details: ''
})
let chatId = null
let lastSentUrl = null
let urlTrackingSetup = false

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

const sendMessage = async () => {
  if (!message.value.trim() || !chatId) return

  const userMessage = message.value
  message.value = '' 

  try {
    const cfg = window.ChatConfig || {}
    const apiBase = cfg.apiBase || ''
    const headers = {}
    if (cfg.apiToken) headers['X-CHAT-TOKEN'] = cfg.apiToken

    const sendUrl = apiBase ? apiBase + '/message' : '/send-message'

    await axios.post(sendUrl, {
      chat_id: chatId,
      message: userMessage,
      sender_type: 'visitor'
    }, { headers })
    await scrollToBottom()
  } catch (error) {
    console.error('Failed to send message:', error)
    message.value = userMessage
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
    
    showUserForm.value = false
    userForm.value = { name: '', email: '', details: '' }
    await scrollToBottom()
  } catch (error) {
    console.error('Failed to send user info:', error)
  }
}

const cancelUserInfo = () => {
  showUserForm.value = false
  userForm.value = { name: '', email: '', details: '' }
}
</script>


<template>
  <div class="fixed bottom-5 right-5 w-80 z-50">
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
            <div class="text-sm">{{ msg.message }}</div>
            <div class="text-xs opacity-50 text-right mt-1">
              {{ formatTime(msg.created_at) }}
            </div>
          </div>
        </div>
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

      <!-- Input form -->
      <form v-else @submit.prevent="sendMessage" class="flex border-t p-2">
        <input 
          v-model="message" 
          type="text" 
          placeholder="Type a message..." 
          class="border rounded-l-full px-4 py-2 flex-1 focus:outline-none focus:ring-2 focus:ring-blue-300"
        />
        <button 
          type="submit" 
          class="bg-blue-500 text-white px-4 py-2 rounded-r-full hover:bg-blue-600 transition-colors"
          :disabled="!message.trim()"
          :class="{ 'opacity-50 cursor-not-allowed': !message.trim() }"
        >
          Send
        </button>
      </form>
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
