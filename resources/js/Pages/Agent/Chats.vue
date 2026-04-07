<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { ref, onMounted, onBeforeUnmount, watch,computed } from 'vue'
import axios from 'axios'
import { extractErrorMessage } from '../../utils/extractErrorMessage'

// Props from backend
const props = defineProps({
  chats: {
    type: Array,
    default: () => []
  },
  auth_user: {
    type: Array,
    default: () => []
  },
  pollCursor: {
    type: String,
    default: null
  }
})

const chats = ref([])
const selectedChat = ref(null)
const messages = ref([])
const replyMessage = ref('')
const sendError = ref('')
const externalFetching = ref(false)
const externalPdfSending = ref(false)
const markingRead = ref(new Set())
const subscribedChatIds = new Set()
const pollCursor = ref(props.pollCursor)
let onlineFlagsIntervalId = null
let pollIntervalId = null
const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024

// File attachment state
const attachedFiles = ref([])
const fileInputRef = ref(null)
const isDraggingOver = ref(false)

// update online status based on last_activity timestamp (60s threshold)
const updateOnlineFlags = () => {
  const cutoff = Date.now() - 30 * 1000;
  chats.value.forEach(c => {
    if (c.last_activity) {
      c.is_online = new Date(c.last_activity).getTime() > cutoff;
    } else {
      c.is_online = false;
    }
  });
};

onMounted(() => {
  chats.value = props.chats || []
  updateOnlineFlags()
  onlineFlagsIntervalId = setInterval(updateOnlineFlags, 30000)
})

onBeforeUnmount(() => {
  if (onlineFlagsIntervalId) clearInterval(onlineFlagsIntervalId)
  if (pollIntervalId) clearInterval(pollIntervalId)
})

watch(() => props.chats, (newChats) => {
  if (newChats) {
    chats.value = newChats
  }
}, { immediate: true, deep: true })

// const upsertChatFromPoll = (incoming) => {
//   if (!incoming?.id) return

//   const index = chats.value.findIndex(c => c.id === incoming.id)
//   if (index === -1) {
//     incoming.unread_count = incoming.unread_count || 0
//     if (!incoming.messages) incoming.messages = []
//     chats.value.unshift(incoming)
//     updateOnlineFlags()
//     subscribeToChat(incoming.id)
//     return
//   }

//   const existing = chats.value[index]
//   const existingMessages = existing?.messages ? [...existing.messages] : null
//   Object.assign(existing, incoming)
//   if (existingMessages && existingMessages.length) {
//     existing.messages = existingMessages
//   } else if (!existing.messages) {
//     existing.messages = []
//   }

//   // keep list ordering fresh when activity happens
//   moveChatToTop(existing.id)
//   updateOnlineFlags()
// }


// Select a chat
const selectChat = async (chat) => {
  selectedChat.value = chat
  messages.value = []
  chat.unread_count = 0
  markChatRead(chat.id, true)

  try {
    const response = await axios.get(`/agent/chats/${chat.id}/messages`, {
      params: { limit: 10 }
    })
    if (response.data?.chat) Object.assign(chat, response.data.chat)
    messages.value = Array.isArray(response.data?.messages) ? response.data.messages : []
  } catch (e) {
    messages.value = []
  }
}

const mergeChatIntoList = (updated) => {
  if (!updated?.id) return
  const idx = chats.value.findIndex(c => c.id === updated.id)
  if (idx !== -1) Object.assign(chats.value[idx], updated)
  if (selectedChat.value?.id === updated.id) Object.assign(selectedChat.value, updated)
}

const fetchExternalData = async (chat) => {
  if (!chat?.id) return
  externalFetching.value = true
  try {
    const response = await axios.post(`/agent/chats/${chat.id}/external/fetch`)
    if (response.data?.chat) mergeChatIntoList(response.data.chat)
  } catch (e) {
    sendError.value = extractErrorMessage(e, 'Failed to fetch data. Please try again.')
  } finally {
    externalFetching.value = false
  }
}

const sendExternalPdf = async (chat, registrationNo = null) => {
  if (!chat?.id) return
  externalPdfSending.value = true
  try {
    const payload = {}
    const reg = (registrationNo || '').toString().trim()
    if (reg) payload.registration_no = reg

    const response = await axios.post(`/agent/chats/${chat.id}/external/send-pdf`, payload)
    if (response.data?.chat) mergeChatIntoList(response.data.chat)
    if (response.data?.message) addMessage(chat.id, response.data.message)
    moveChatToTop(chat.id)
  } catch (e) {
    sendError.value = extractErrorMessage(e, 'Failed to generate/send PDF. Please try again.')
  } finally {
    externalPdfSending.value = false
  }
}

const registrationNoForUserInfoMessage = (msg) => {
  if (!msg || msg.message_type !== 'user_info_response') return null
  const text = (msg.message || '').toString()
  if (!text.trim()) return null

  // Be tolerant: older/edited messages may not match the exact "Registration No:" format.
  const match =
    text.match(/(?:^|\r?\n)\s*registration\s*(?:no|number)\s*[:\-]?\s*([^\r\n]+)\s*(?:\r?\n|$)/i) ||
    text.match(/(?:^|\r?\n)\s*reg(?:istration)?\s*(?:no|number)?\s*[:\-]?\s*([^\r\n]+)\s*(?:\r?\n|$)/i)

  const v = (match?.[1] || '').toString().trim()
  return v ? v : null
}

const fetchExternalDataForMessage = async (chat, msg) => {
  if (!chat?.id) return
  const registrationNo = registrationNoForUserInfoMessage(msg)
  if (!registrationNo) {
    sendError.value = 'Registration No is missing in the user info message.'
    return
  }

  externalFetching.value = true
  try {
    const response = await axios.post(`/agent/chats/${chat.id}/external/fetch`, { registration_no: registrationNo })
    if (response.data?.chat) mergeChatIntoList(response.data.chat)
  } catch (e) {
    sendError.value = extractErrorMessage(e, 'Failed to fetch data. Please try again.')
  } finally {
    externalFetching.value = false
  }
}

const sendExternalPdfForMessage = async (chat, msg) => {
  const registrationNo = registrationNoForUserInfoMessage(msg)
  return sendExternalPdf(chat, registrationNo)
}

const canSendPdfForMessage = (chat, msg) => {
  const msgReg = (registrationNoForUserInfoMessage(msg) || '').toString().trim()
  const chatReg = (chat?.registration_no || '').toString().trim()
  return !!msgReg && !!chatReg && msgReg === chatReg && chat?.external_api_status === 'success' && !!chat?.external_api_response
}

const triggerFileInput = () => {
  fileInputRef.value?.click()
}

const onFileInputChange = (event) => {
  addFiles(Array.from(event.target.files || []))
  // reset so the same file can be re-selected
  event.target.value = ''
}

const addFiles = (newFiles) => {
  // only take the first file — one at a time
  const file = newFiles[0]
  if (!file) return
  if (file.size > MAX_ATTACHMENT_BYTES) {
    sendError.value = 'File too large. Maximum size is 20 MB.'
    return
  }
  attachedFiles.value = [] // replace any existing
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

// Drag-and-drop on the reply area
const onDragOver = (e) => {
  e.preventDefault()
  isDraggingOver.value = true
}
const onDragLeave = () => {
  isDraggingOver.value = false
}
const onDrop = (e) => {
  e.preventDefault()
  isDraggingOver.value = false
  addFiles(Array.from(e.dataTransfer.files || []))
}


const getFileIcon = (file) => {
  const ext = file.name.split('.').pop().toLowerCase()
  if (['pdf'].includes(ext)) return '📄'
  return '📄'
}

const sendReply = async () => {
  if (!selectedChat.value) return
  const hasText = replyMessage.value.trim() !== ''
  const hasFiles = attachedFiles.value.length > 0
  if (!hasText && !hasFiles) return

  const tempMessage = replyMessage.value
  const tempFiles = [...attachedFiles.value]

  replyMessage.value = ''
  clearAttachments()

  try {
    if (hasFiles) {
      const formData = new FormData()
      formData.append('chat_id', selectedChat.value.id)
      formData.append('message', tempMessage)
      formData.append('sender_type', 'agent')
      if (tempFiles[0]?.file) {
        formData.append('attachments', tempFiles[0].file)
      }
      await axios.post('/send-message', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
    } else {
      await axios.post('/send-message', {
        chat_id: selectedChat.value.id,
        message: tempMessage,
        sender_type: 'agent'
      })
    }
    sendError.value = ''
  } catch (e) {
    // restore on error
    sendError.value = extractErrorMessage(e, 'Failed to send. Please try again.')
    replyMessage.value = tempMessage
    attachedFiles.value = tempFiles
  }
}

// ── Existing methods ─────────────────────────────────────────────────────────

// Delete chat
const deleteChat = async (chat, event) => {
  event.stopPropagation()
  if (confirm('Are you sure you want to delete this chat?')) {
    try {
      await axios.delete(`/agent/chats/${chat.id}`)
      chats.value = chats.value.filter(c => c.id !== chat.id)
      if (selectedChat.value && selectedChat.value.id === chat.id) {
        selectedChat.value = null
        messages.value = []
      }
    } catch (e) { }
  }
}

// Show user info form
const showUserInfoForm = async (chat, event) => {
  if (event?.stopPropagation) event.stopPropagation()
  if (selectedChat.value?.id !== chat?.id) {
    await selectChat(chat)
  }
  const formData = {
    chat_id: chat.id,
    message: 'Please provide your information: Phone No (required), Customer Name (required), Registration No (required). Email is optional.',
    sender_type: 'agent',
    message_type: 'user_info_request'
  }
  try {
    await axios.post('/send-message', formData)
  } catch (e) { }
}

const moveChatToTop = (chatId) => {
  const index = chats.value.findIndex(c => c.id === chatId)
  if (index <= 0) return
  const [chat] = chats.value.splice(index, 1)
  chats.value.unshift(chat)
}

const closeChat = async (chat, event) => {
  event.stopPropagation()
  if (!confirm('Are you sure you want to close this chat?')) return
  try {
    await axios.post(`/agent/chats/${chat.id}/close`)
    const existing = chats.value.find(c => c.id === chat.id)
    if (existing) existing.status = 'close'
    if (selectedChat.value && selectedChat.value.id === chat.id) {
      selectedChat.value.status = 'close'
    }
  } catch (e) { }
}

const markChatRead = async (chatId, force = false) => {
  const chat = chats.value.find(c => c.id === chatId)
  if (!chat) return
  if (!force && (!chat.unread_count || chat.unread_count <= 0)) return
  if (markingRead.value.has(chatId)) return
  markingRead.value.add(chatId)
  try {
    await axios.post(`/agent/chats/${chatId}/read`)
    chat.unread_count = 0
  } catch (e) {
  } finally {
    markingRead.value.delete(chatId)
  }
}

const resetExternalApiState = (chatId) => {
  const chat = chats.value.find(c => c.id === chatId)
  if (!chat) return

  chat.external_api_status = null
  chat.external_api_error = null
  chat.external_api_response = null
  chat.external_api_fetched_at = null
  chat.external_api_pdf_sent_at = null

  if (selectedChat.value?.id === chatId) {
    selectedChat.value.external_api_status = null
    selectedChat.value.external_api_error = null
    selectedChat.value.external_api_response = null
    selectedChat.value.external_api_fetched_at = null
    selectedChat.value.external_api_pdf_sent_at = null
  }
}

const addMessage = (chatId, message) => {
  const chat = chats.value.find(c => c.id === chatId)
  if (!chat) return
  if (chat.latest_message?.id === message.id) return

  // If visitor submits the info form again (possibly for a different registration),
  // treat any previously fetched external data as stale until re-fetched.
  if (message?.message_type === 'user_info_response' && message?.sender_type === 'visitor') {
    resetExternalApiState(chatId)
  }

  if (message.sender_type === 'visitor') chat.is_online = true
  chat.last_message_at = message.created_at
  chat.latest_message = message
  if (message.sender_type === 'visitor') {
    if (selectedChat.value && selectedChat.value.id === chatId) {
      chat.unread_count = 0
      markChatRead(chatId, true)
    } else {
      chat.unread_count = (chat.unread_count || 0) + 1
    }
  }
  moveChatToTop(chatId)
  if (selectedChat.value && selectedChat.value.id === chatId) {
    if (!messages.value.some(m => m.id === message.id)) {
      messages.value.push(message)
      // keep only the latest 10 in the opened chat
      if (messages.value.length > 10) {
        messages.value.splice(0, messages.value.length - 10)
      }
    }
  }
}

onMounted(() => {
  if (!window.Echo) return
  window.Echo.channel('newChats')
    .listen('NewChat', (e) => {
      const chatExists = chats.value.some(c => c.id === e.chat.id)
      if (!chatExists) {
        e.chat.unread_count = e.chat.unread_count || 0
        chats.value.unshift(e.chat)
        updateOnlineFlags()
      }
      subscribeToChat(e.chat.id)
    })
    .error((error) => console.error('Error subscribing to newChats channel:', error))
  chats.value.forEach(chat => subscribeToChat(chat.id))
})

// uncomment this code if you want to move clicked chat on top and commented by umar farooq
// const pollForChats = async () => {
//   try {
//     const response = await axios.get('/agent/chats/poll', {
//       params: { cursor: pollCursor.value }
//     })
//     pollCursor.value = response.data?.cursor || pollCursor.value
//     const incomingChats = response.data?.chats || []
//     if (Array.isArray(incomingChats) && incomingChats.length) {
//       incomingChats.forEach(upsertChatFromPoll)
//     }
//   } catch (e) {
//     // ignore transient errors; polling is only a safety net when realtime drops
//   }
// }

onMounted(() => {
  // pollForChats()
  // pollIntervalId = setInterval(pollForChats, 5000)
})

const filteredOpenChats = computed(() => {
  return chats.value.filter(chat => chat?.assigned_agent_id === props.auth_user?.id && chat?.status === 'open');
});

const filteredClosedChats = computed(() => {
  return chats.value.filter(chat => chat?.assigned_agent_id === props.auth_user?.id && chat?.status === 'close');
});

const filteredUnassignChats = computed(() => {
  return chats.value.filter(chat => chat?.assigned_agent_id == null);
});

const filteredGlobalChats = computed(() => {
  return chats.value.filter(chat => chat?.assigned_agent_id != props.auth_user?.id && chat?.assigned_agent_id != null);
});

const subscribeToChat = (chatId) => {
  if (subscribedChatIds.has(chatId)) return
  if (!window.Echo) return
  subscribedChatIds.add(chatId)
  window.Echo.channel(`chat.${chatId}`)
    .listen('MessageSent', (e) => {
      addMessage(e.message.chat_id, e.message)
    })
    .listen('ChatPing', (e) => {
      const chat = chats.value.find(c => c.id === e.chatId)
      if (chat) {
        chat.is_online = true
        if (e.lastActivity) chat.last_activity = e.lastActivity
        if (e.currentUrl) chat.current_url = e.currentUrl
        if (e.ip) chat.ip = e.ip
      }
    })
    .error((error) => console.error('Error subscribing to chat channel:', error))
}
</script>

<template>
  <AuthenticatedLayout>
    <!-- <template #header>
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-600 text-white">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
          </div>
          <h2 class="text-base font-bold text-gray-900 tracking-tight">Agent Dashboard</h2>
        </div>
        <div
          class="flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700 uppercase tracking-widest">
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
          Live
        </div>
      </div>
    </template> -->

    <!-- WORKSPACE -->
    <div class="flex bg-slate-50 rounded-xl overflow-hidden border border-slate-200 shadow-lg m-4"
      style="height: calc(100vh - 85px);">
      <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
      <aside class="flex flex-col bg-white border-r border-slate-200 overflow-hidden"
        style="width: 350px; min-width: 350px;">
        <!-- Sidebar top stats -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Recent chats</div>
            <!-- <div
              class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              {{chats.filter(c => c.is_online).length}} online
            </div> -->
          </div>
        </div>
        
        <!-- Chat items list recent chats -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div v-for="chat in filteredOpenChats" :key="chat.id" @click="selectChat(chat)" :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
                selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-indigo-50 ring-1 ring-indigo-200'
                  : chat.unread_count > 0 && chat?.assigned_agent_id === auth_user.id
                    ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                    : 'hover:bg-slate-50'
              ]"> 
            <!-- Avatar -->
            <div class="relative flex-shrink-0" >
              <div :class="[
                'w-10 h-10 rounded-xl flex items-center justify-center text-xs font-bold font-mono',
                selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-100 text-slate-500'
              ]">
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span v-if="chat.unread_count > 0 && selectedChat?.id !== chat.id && chat?.assigned_agent_id === auth_user.id "
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping">
              </span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12" >
              <div class="flex items-center gap-2 mb-0.5">
                <span :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat #{{ chat.id }} 
                </span>
                <span v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;">
                  {{ chat.unread_count }}
                </span>
              </div>
              <p class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.latest_message?.message || 'No messages yet' }}
              </p>
              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons -->
            <div class="absolute top-2 right-2 flex flex-col gap-1" >
              <button @click="closeChat(chat, $event)" title="Close Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-slate-100 text-slate-600 hover:bg-slate-600 hover:text-white transition-colors duration-150">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round">
                  <path d="M18 6 6 18" />
                  <path d="M6 6l12 12" />
                </svg>
              </button>
              <!-- <button @click="deleteChat(chat, $event)" title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6" />
                  <path d="M19 6l-1 14H6L5 6" />
                  <path d="M10 11v6M14 11v6" />
                  <path d="M9 6V4h6v2" />
                </svg>
              </button> -->
            </div>
          </div>
        </div>

        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Previous chats</div>
          </div>
        </div>
        
        <!-- Chat items list previous chats -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div v-for="chat in filteredClosedChats" :key="chat.id" @click="selectChat(chat)" :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
                selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-indigo-50 ring-1 ring-indigo-200'
                  : chat.unread_count > 0 && chat?.assigned_agent_id === auth_user.id
                    ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                    : 'hover:bg-slate-50'
              ]"> 
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div :class="[
                'w-10 h-10 rounded-xl flex items-center justify-center text-xs font-bold font-mono',
                selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-100 text-slate-500'
              ]">
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span v-if="chat.unread_count > 0 && selectedChat?.id !== chat.id && chat?.assigned_agent_id === auth_user.id "
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping">
              </span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12" >
              <div class="flex items-center gap-2 mb-0.5">
                <span :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat #{{ chat.id }}
                </span>
                <span v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;">
                  {{ chat.unread_count }}
                </span>
              </div>
              <p class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.latest_message?.message || 'No messages yet' }}
              </p>
              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button @click="deleteChat(chat, $event)" title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6" />
                  <path d="M19 6l-1 14H6L5 6" />
                  <path d="M10 11v6M14 11v6" />
                  <path d="M9 6V4h6v2" />
                </svg>
              </button>
            </div>
          </div>
        </div>


      </aside>

      <!-- ═══════════════════ MAIN PANEL ═══════════════════ -->
      <main class="flex-1 flex flex-col bg-slate-50 overflow-hidden">

        <template v-if="selectedChat">

          <!-- Chat Header -->
          <div class="flex items-center gap-3 px-5 py-3.5 bg-white border-b border-slate-200 shadow-sm">
            <div
              class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-xs font-bold font-mono flex-shrink-0"
              style="background: linear-gradient(135deg, #4f46e5, #818cf8);">
              #{{ selectedChat.id }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-bold text-gray-900 text-sm tracking-tight">Chat #{{ selectedChat.id }}</div>
              <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                <a v-if="selectedChat.current_url" :href="selectedChat.current_url" target="_blank"
                  class="text-xs text-indigo-500 hover:underline truncate max-w-xs">{{ selectedChat.current_url }}</a>
                <span v-if="selectedChat.ip || selectedChat.ip_address" class="text-xs text-slate-400 font-mono">
                  {{ selectedChat.ip || selectedChat.ip_address }}
                </span>
              </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
              <button @click="showUserInfoForm(selectedChat)" title="Send Info Form"
                class="w-8 h-8 rounded-lg flex items-center justify-center bg-indigo-100 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-colors duration-150">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M6 12 3 3l18 9-18 9 3-9Z" />
                </svg>
              </button>

              <div :class="[
                'flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-widest border',
                selectedChat.is_online
                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                  : 'bg-slate-100 text-slate-400 border-slate-200'
              ]">
                <span
                  :class="['w-1.5 h-1.5 rounded-full', selectedChat.is_online ? 'bg-emerald-500' : 'bg-slate-400']"></span>
                {{ selectedChat.is_online ? 'Online' : 'Offline' }}
              </div>
            </div>
          </div>

          <!-- Messages scroll area -->
          <div class="flex-1 overflow-y-auto px-5 py-5 flex flex-col gap-3">
            <div v-for="msg in messages" :key="msg.id"
              :class="['flex', msg.sender_type === 'agent' ? 'justify-end' : 'justify-start']">
              <!-- User Info Request -->

              <div v-if="msg.message_type === 'user_info_request'"
                class="max-w-sm bg-blue-50 border border-blue-200 rounded-xl p-3">
                <div class="text-xs font-bold text-blue-700 mb-1.5 flex items-center gap-1.5">
                  <span>📋</span> User Information Form Request Sent
                </div>
                 
              </div>
             
              <!-- User Info Response -->
              <div v-else-if="msg.message_type === 'user_info_response'"
                class="max-w-sm bg-emerald-50 border border-emerald-200 rounded-xl p-3">
                <div class="text-xs font-bold text-emerald-700 mb-1.5 flex items-center gap-1.5">
                  User Information Received:
                </div>
                <div class="text-xs text-emerald-600 leading-relaxed whitespace-pre-line">{{ msg.message }}</div>
                
                <div class="flex flex-wrap items-center gap-2 mt-3">
                  <button type="button" @click="fetchExternalDataForMessage(selectedChat, msg)"
                    class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm transition-all duration-200 hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed"
                    :disabled="externalFetching">
                    {{ externalFetching ? 'Fetching...' : 'Fetch Data' }}
                  </button>
                 
                  <button v-if="canSendPdfForMessage(selectedChat, msg)"
                    type="button" @click="sendExternalPdfForMessage(selectedChat, msg)"
                    class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shadow-sm transition-all duration-200 hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed"
                    :disabled="externalPdfSending">
                    {{ externalPdfSending ? 'Sending...' : 'Send PDF' }}
                  </button>
                </div>

                <div v-if="selectedChat?.external_api_status === 'error' && selectedChat?.external_api_error"
                  class="mt-2 text-xs text-red-700 whitespace-pre-line">
                  {{ selectedChat.external_api_error }}
                </div>
              </div>

              <!-- Message with attachments -->
              <div v-else class="flex flex-col gap-1.5"
                :class="msg.sender_type === 'agent' ? 'items-end' : 'items-start'">

                <template v-if="msg.attachment_view_url">
                  <img v-if="msg.attachment_is_image" :src="msg.attachment_view_url"
                    :alt="msg.attachment_name || 'Attachment'"
                    class="max-w-xs max-h-48 rounded-xl border border-slate-200 object-cover shadow-sm cursor-pointer hover:opacity-90 transition-opacity"
                    @click="window.open(msg.attachment_view_url, '_blank')" />

                  <a v-else :href="msg.attachment_download_url || msg.attachment_view_url"
                    :download="msg.attachment_name" :class="[
                      'flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-medium transition-colors',
                      msg.sender_type === 'agent'
                        ? 'bg-indigo-500 border-indigo-400 text-white hover:bg-indigo-400'
                        : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                    ]">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                      stroke-linecap="round" stroke-linejoin="round">
                      <path
                        d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                    </svg>
                    <span class="truncate max-w-[160px]">{{ msg.attachment_name || 'Download file' }}</span>
                  </a>
                </template>

                <!-- Regular text bubble -->
                <div v-if="msg.message" :class="[
                  'max-w-xs lg:max-w-md px-4 py-2.5 text-sm leading-relaxed break-words',
                  msg.sender_type === 'agent'
                    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm shadow-indigo-200 shadow-md'
                    : 'bg-white text-gray-800 rounded-2xl rounded-bl-sm border border-slate-200 shadow-sm'
                ]">
                  {{ msg.message }}
                </div>
              </div>
            </div>
          </div>

          <!-- ── Reply bar ── -->
          <div class="bg-white border-t border-slate-200 transition-all duration-200"
            :class="isDraggingOver ? 'ring-2 ring-inset ring-indigo-400 bg-indigo-50' : ''" @dragover="onDragOver"
            @dragleave="onDragLeave" @drop="onDrop">
            <!-- Attachment previews -->
            <div v-if="attachedFiles.length" class="flex flex-wrap gap-2 px-4 pt-3 pb-1">
              <div v-for="(item, index) in attachedFiles" :key="index"
                class="relative group flex items-center gap-2 bg-slate-100 border border-slate-200 rounded-lg overflow-hidden">
                <!-- Image thumbnail -->
                <template v-if="item.isImage">
                  <img :src="item.preview" :alt="item.file.name" class="w-14 h-14 object-cover" />
                </template>
                <!-- File chip -->
                <template v-else>
                  <div class="flex items-center gap-2 px-3 py-2">
                    <span class="text-base leading-none">{{ getFileIcon(item.file) }}</span>
                    <div class="min-w-0">
                      <p class="text-xs font-semibold text-slate-700 truncate max-w-[120px]">{{ item.file.name }}</p>
                 
                    </div>
                  </div>
                </template>

                <!-- Remove button -->
                <button @click="removeAttachment(index)"
                  class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-slate-700 bg-opacity-80 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-500"
                  title="Remove">
                  <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                    stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                  </svg>
                </button>
              </div>

            </div>

            <!-- Drag-over hint -->
            <div v-if="isDraggingOver"
              class="flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-indigo-600">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                stroke-linecap="round">
                <path
                  d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
              </svg>
              Drop files to attach
            </div>

            <div v-if="sendError" class="px-4 pb-2">
              <div
                class="border border-red-200 bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2 flex items-start justify-between gap-2">
                <span class="whitespace-pre-line">{{ sendError }}</span>
                <button type="button" class="font-bold leading-none" @click="sendError = ''">×</button>
              </div>
            </div>

            <!-- Input row -->
            <form @submit.prevent="sendReply" v-if="selectedChat?.assigned_agent_id === auth_user.id" class="flex items-center gap-0 px-4 py-3">

              <!-- Hidden file input -->
              <input ref="fileInputRef" type="file" class="hidden" @change="onFileInputChange" />

              <!-- Attach button -->
              <button type="button" @click="triggerFileInput" title="Attach files"
                class="flex-shrink-0 flex items-center justify-center w-9 h-9 mr-2 rounded-xl bg-slate-100 text-slate-500 hover:bg-indigo-100 hover:text-indigo-600 transition-colors border border-slate-200 hover:border-indigo-200"
                :class="attachedFiles.length ? 'bg-indigo-100 text-indigo-600 border-indigo-200' : ''">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round">
                  <path
                    d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                </svg>
                <!-- Badge count -->
                <span v-if="attachedFiles.length"
                  class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center leading-none"
                  style="font-size: 9px;">{{ attachedFiles.length }}</span>
              </button>

              <!-- Text input -->
              <input v-model="replyMessage" type="text" placeholder="Type your reply…"
                class="flex-1 bg-slate-50 border border-slate-200 border-r-0 rounded-l-xl px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-400 focus:bg-white transition-colors placeholder-slate-400" />

              <!-- Send button -->
              <button type="submit"
                class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white border-none rounded-r-xl px-5 py-2.5 text-sm font-semibold transition-colors cursor-pointer">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M6 12 3 3l18 9-18 9 3-9Z" />
                </svg>
                Send
              </button>
            </form>
          </div>

        </template>

        <!-- Empty state -->
        <div v-else class="flex-1 flex flex-col items-center justify-center gap-4 text-slate-400 px-12">
          <div
            class="w-20 h-20 rounded-2xl bg-white border border-slate-200 shadow-sm flex items-center justify-center">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
          </div>
          <div class="text-center">
            <p class="font-bold text-gray-700 text-base mb-1">No conversation selected</p>
            <p class="text-sm text-slate-400">Choose a chat from the sidebar to get started</p>
          </div>
        </div>

      </main>

      <!-- RIGHT SIDEBAR -->
      <aside class="flex flex-col bg-white border-l border-slate-200 overflow-hidden"
        style="width: 350px; min-width: 350px;">
        <!-- Sidebar top stats -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Unassign chats</div>
            <div
              class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              {{chats.filter(c => c.is_online).length}} online
            </div>
          </div>
        </div>

        <!-- Chat items list Unassign chats -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div v-for="chat in filteredUnassignChats" :key="chat.id" @click="selectChat(chat)" :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
                selectedChat?.id === chat.id 
                  ? 'bg-indigo-50 ring-1 ring-indigo-200'
                  : chat.unread_count > 0
                    ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                    : 'hover:bg-slate-50'
              ]">
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div :class="[
                'w-10 h-10 rounded-xl flex items-center justify-center text-xs font-bold font-mono',
                selectedChat?.id === chat.id 
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-100 text-slate-500'
              ]">
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span v-if="chat.unread_count > 0"
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping">
              </span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat #{{ chat.id }} 
                </span>
                <span v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;">
                  {{ chat.unread_count }}
                </span>
              </div>
              <p class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.latest_message?.message || 'No messages yet' }}
              </p>
              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button @click="deleteChat(chat, $event)" title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6" />
                  <path d="M19 6l-1 14H6L5 6" />
                  <path d="M10 11v6M14 11v6" />
                  <path d="M9 6V4h6v2" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Other chats</div>
          </div>
        </div> 

        <!-- Chat items list Other chats -->
         <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div v-for="chat in filteredGlobalChats" :key="chat.id" @click="selectChat(chat)" :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
                selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-indigo-50 ring-1 ring-indigo-200'
                  : chat.unread_count > 0 && chat?.assigned_agent_id === auth_user.id
                    ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                    : 'hover:bg-slate-50'
              ]">
           
            <div class="relative flex-shrink-0">
              <div :class="[
                'w-10 h-10 rounded-xl flex items-center justify-center text-xs font-bold font-mono',
                selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-100 text-slate-500'
              ]">
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span v-if="chat.unread_count > 0 && selectedChat?.id !== chat.id && chat?.assigned_agent_id === auth_user.id "
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping">
              </span>
            </div>

            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat #{{ chat.id }}
                </span>
                <span v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;">
                  {{ chat.unread_count }}
                </span>
              </div>
              <p class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.latest_message?.message || 'No messages yet' }}
              </p>
              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button @click="deleteChat(chat, $event)" title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6" />
                  <path d="M19 6l-1 14H6L5 6" />
                  <path d="M10 11v6M14 11v6" />
                  <path d="M9 6V4h6v2" />
                </svg>
              </button>
            </div>
          </div>
        </div> -
      </aside>
    </div>

  </AuthenticatedLayout>
</template>
