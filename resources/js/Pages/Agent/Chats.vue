<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'

// Props from backend
const props = defineProps({
    chats: {
        type: Array,
        default: () => []
    }
})

const chats = ref([])  
const selectedChat = ref(null)            
const messages = ref([])                  
const replyMessage = ref('')              
const markingRead = ref(new Set())
const subscribedChatIds = new Set()

// update online status based on last_activity timestamp (2 minute threshold)
const updateOnlineFlags = () => {
  const cutoff = Date.now() - 2 * 60 * 1000;
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
  setInterval(updateOnlineFlags, 9000)
})

watch(() => props.chats, (newChats) => {
  if (newChats) {
    chats.value = newChats
  }
}, { immediate: true, deep: true })

// Select a chat
const selectChat = (chat) => {
  selectedChat.value = chat
  messages.value = chat.messages ? [...chat.messages] : []
  chat.unread_count = 0
  markChatRead(chat.id, true)
}

// Send reply
const sendReply = async () => {
  if (!selectedChat.value || replyMessage.value.trim() === '') return
  const payload = {
    chat_id: selectedChat.value.id,
    message: replyMessage.value,
    sender_type: 'agent'
  }
  const temp = replyMessage.value
  replyMessage.value = ''
  try {
    await axios.post('/send-message', payload)
  } catch (e) {
    replyMessage.value = temp
  }
}

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
    } catch (e) {}
  }
}

// Show user info form
const showUserInfoForm = async (chat, event) => {
  event.stopPropagation()
  selectChat(chat)
  const formData = {
    chat_id: chat.id,
    message: 'Please provide your contact information by filling out the form below.',
    sender_type: 'agent',
    message_type: 'user_info_request'
  }
  try {
    await axios.post('/send-message', formData)
  } catch (e) {}
}

const moveChatToTop = (chatId) => {
  const index = chats.value.findIndex(c => c.id === chatId)
  if (index <= 0) return
  const [chat] = chats.value.splice(index, 1)
  chats.value.unshift(chat)
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

const addMessage = (chatId, message) => {
  const chat = chats.value.find(c => c.id === chatId)
  if (!chat) return
  if (!chat.messages) chat.messages = []
  if (chat.messages.some(m => m.id === message.id)) return
  if (message.sender_type === 'visitor') chat.is_online = true
  chat.last_message_at = message.created_at
  if (message.sender_type === 'visitor') {
    if (selectedChat.value && selectedChat.value.id === chatId) {
      chat.unread_count = 0
      markChatRead(chatId, true)
    } else {
      chat.unread_count = (chat.unread_count || 0) + 1
    }
  }
  moveChatToTop(chatId)
  chat.messages.push(message)
  if (selectedChat.value && selectedChat.value.id === chatId) {
    if (!messages.value.some(m => m.id === message.id)) {
      messages.value.push(message)
    }
  }
}

onMounted(() => {
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
  chats.value.forEach(chat => subscribeToChat(chat.id))
})

const subscribeToChat = (chatId) => {
  if (subscribedChatIds.has(chatId)) return
  subscribedChatIds.add(chatId)
  window.Echo.channel(`chat.${chatId}`)
    .listen('MessageSent', (e) => {
      addMessage(e.message.chat_id, e.message)
    })
    .listen('ChatPing', (e) => {
      const chat = chats.value.find(c => c.id === e.chatId)
      if (chat) {
        chat.is_online = true
        if (e.currentUrl) chat.current_url = e.currentUrl
        if (e.ip) chat.ip = e.ip
      }
    })
    .error((error) => console.error('Error subscribing to chat channel:', error))
}
</script>

<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-600 text-white">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
          </div>
          <h2 class="text-base font-bold text-gray-900 tracking-tight">Agent Dashboard</h2>
        </div>
        <div class="flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700 uppercase tracking-widest">
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
          Live
        </div>
      </div>
    </template>

    <!-- WORKSPACE -->
    <div class="flex bg-slate-50 rounded-xl overflow-hidden border border-slate-200 shadow-lg m-4" style="height: calc(100vh - 130px);">

      <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
      <aside class="flex flex-col bg-white border-r border-slate-200 overflow-hidden" style="width: 288px; min-width: 288px;">

        <!-- Sidebar top stats -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>
              <!-- <div class="text-3xl font-extrabold text-gray-900 leading-none tracking-tight">{{ chats.length }}</div> -->
              <!-- <div class="text-xs font-semibold text-slate-400 uppercase tracking-widest mt-1">Total Chats</div> -->
            </div>
            <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              {{ chats.filter(c => c.is_online).length }} online
            </div>
          </div>
        </div>

        <!-- Chat items list -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div
            v-for="chat in chats"
            :key="chat.id"
            @click="selectChat(chat)"
            :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
              selectedChat?.id === chat.id
                ? 'bg-indigo-50 ring-1 ring-indigo-200'
                : 'hover:bg-slate-50'
            ]"
          >
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div
                :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center text-xs font-bold font-mono',
                  selectedChat?.id === chat.id
                    ? 'bg-indigo-600 text-white'
                    : 'bg-slate-100 text-slate-500'
                ]"
              >
                #{{ chat.id }}
              </div>
              <!-- Online dot -->
              <span
                :class="[
                  'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                  chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
                ]"
              ></span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat #{{ chat.id }}
                </span>
                <!-- UNREAD BADGE — always rendered when count > 0 -->
                <span
                  v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;"
                >
                  {{ chat.unread_count }}
                </span>
              </div>
              <p class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.messages?.[chat.messages.length - 1]?.message || 'No messages yet' }}
              </p>
              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                {{ chat.current_url }}
              </p>
              <p v-if="chat.ip || chat.ip_address" class="text-xs text-slate-400 font-mono">
                {{ chat.ip || chat.ip_address }}
              </p>
            </div>

            <!-- Action buttons — always visible -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button
                @click="showUserInfoForm(chat, $event)"
                title="Send Info Form"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-indigo-100 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-colors duration-150"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M6 12 3 3l18 9-18 9 3-9Z"/>
                </svg>
              </button>
              <button
                @click="deleteChat(chat, $event)"
                title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6l-1 14H6L5 6"/>
                  <path d="M10 11v6M14 11v6"/>
                  <path d="M9 6V4h6v2"/>
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
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-xs font-bold font-mono flex-shrink-0"
                 style="background: linear-gradient(135deg, #4f46e5, #818cf8);">
              #{{ selectedChat.id }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-bold text-gray-900 text-sm tracking-tight">Chat #{{ selectedChat.id }}</div>
              <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                <a
                  v-if="selectedChat.current_url"
                  :href="selectedChat.current_url"
                  target="_blank"
                  class="text-xs text-indigo-500 hover:underline truncate max-w-xs"
                >{{ selectedChat.current_url }}</a>
                <span v-if="selectedChat.ip || selectedChat.ip_address"
                      class="text-xs text-slate-400 font-mono">
                  {{ selectedChat.ip || selectedChat.ip_address }}
                </span>
              </div>
            </div>
            <div
              :class="[
                'flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-widest border',
                selectedChat.is_online
                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                  : 'bg-slate-100 text-slate-400 border-slate-200'
              ]"
            >
              <span
                :class="['w-1.5 h-1.5 rounded-full', selectedChat.is_online ? 'bg-emerald-500' : 'bg-slate-400']"
              ></span>
              {{ selectedChat.is_online ? 'Online' : 'Offline' }}
            </div>
          </div>

          <!-- Messages scroll area -->
          <div class="flex-1 overflow-y-auto px-5 py-5 flex flex-col gap-3">
            <div
              v-for="msg in messages"
              :key="msg.id"
              :class="['flex', msg.sender_type === 'agent' ? 'justify-end' : 'justify-start']"
            >
              <!-- User Info Request -->
              <div
                v-if="msg.message_type === 'user_info_request'"
                class="max-w-sm bg-blue-50 border border-blue-200 rounded-xl p-3"
              >
                <div class="text-xs font-bold text-blue-700 mb-1.5 flex items-center gap-1.5">
                  <span>📋</span> User Information Request Sent
                </div>
                <div class="text-xs text-blue-600 leading-relaxed">{{ msg.message }}</div>
              </div>

              <!-- User Info Response -->
              <div
                v-else-if="msg.message_type === 'user_info_response'"
                class="max-w-sm bg-emerald-50 border border-emerald-200 rounded-xl p-3"
              >
                <div class="text-xs font-bold text-emerald-700 mb-1.5 flex items-center gap-1.5">
                 User Information Received:
                </div>
                <div class="text-xs text-emerald-600 leading-relaxed whitespace-pre-line">{{ msg.message }}</div>
              </div>

              <!-- Regular bubble -->
              <div
                v-else
                :class="[
                  'max-w-xs lg:max-w-md px-4 py-2.5 text-sm leading-relaxed break-words',
                  msg.sender_type === 'agent'
                    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm shadow-indigo-200 shadow-md'
                    : 'bg-white text-gray-800 rounded-2xl rounded-bl-sm border border-slate-200 shadow-sm'
                ]"
              >
                {{ msg.message }}
              </div>
            </div>
          </div>

          <!-- Reply bar -->
          <form @submit.prevent="sendReply" class="flex items-center gap-0 px-4 py-3 bg-white border-t border-slate-200">
            <input
              v-model="replyMessage"
              type="text"
              placeholder="Type your reply…"
              class="flex-1 bg-slate-50 border border-slate-200 border-r-0 rounded-l-xl px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-400 focus:bg-white transition-colors placeholder-slate-400"
            />
            <button
              type="submit"
              class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white border-none rounded-r-xl px-5 py-2.5 text-sm font-semibold transition-colors cursor-pointer"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                <path d="M6 12 3 3l18 9-18 9 3-9Z"/>
              </svg>
              Send
            </button>
          </form>

        </template>

        <!-- Empty state -->
        <div v-else class="flex-1 flex flex-col items-center justify-center gap-4 text-slate-400 px-12">
          <div class="w-20 h-20 rounded-2xl bg-white border border-slate-200 shadow-sm flex items-center justify-center">
            <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
          </div>
          <div class="text-center">
            <p class="font-bold text-gray-700 text-base mb-1">No conversation selected</p>
            <p class="text-sm text-slate-400">Choose a chat from the sidebar to get started</p>
          </div>
        </div>

      </main>
    </div>

  </AuthenticatedLayout>
</template>