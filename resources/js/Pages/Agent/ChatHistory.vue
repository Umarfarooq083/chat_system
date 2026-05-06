<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { computed, onMounted, reactive, ref } from 'vue'

const props = defineProps({
  chats: { type: Object, required: true },
  filters: { type: Object, required: true },
  companies: { type: Array, default: () => [] },
})

const form = reactive({
  search: props.filters.search ?? '',
  company_id: props.filters.company_id ?? '',
  assigned: props.filters.assigned ?? 'any',
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
  per_page: props.filters.per_page ?? 25,
})

const applyFilters = () => {
  router.get(route('agent.chats.history'), { ...form }, { preserveState: true, preserveScroll: true })
}

const resetFilters = () => {
  form.search = ''
  form.company_id = ''
  form.assigned = 'any'
  form.from = ''
  form.to = ''
  form.per_page = 25
  applyFilters()
}

const selectedChat = ref(null)
const messages = ref([])
const loadingChat = ref(false)
const chatError = ref('')

const formatDateTime = (value) => {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return value
  }
}

const latestSnippet = (chat) => {
  const msg = chat?.latest_message?.message ?? chat?.latestMessage?.message
  if (!msg) return '—'
  if (typeof msg === 'string') return msg.length > 80 ? msg.slice(0, 80) + '…' : msg
  try {
    const text = JSON.stringify(msg)
    return text.length > 80 ? text.slice(0, 80) + '…' : text
  } catch {
    return '—'
  }
}

const messageText = (msg) => {
  const value = msg?.message
  if (value == null) return ''
  if (typeof value === 'string') return value
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

const isMine = (msg) => msg?.sender_type === 'agent'
const bubbleClass = (msg) =>
  isMine(msg)
    ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm shadow-indigo-200 shadow-md'
    : 'bg-white text-gray-800 rounded-2xl rounded-bl-sm border border-slate-200 shadow-sm'

const selectChat = async (chat) => {
  if (!chat?.id) return
  if (selectedChat.value?.id === chat.id) return

  loadingChat.value = true
  chatError.value = ''
  selectedChat.value = chat
  messages.value = []

  try {
    const res = await axios.get(route('agent.chats.history.messages', chat.id), { params: { limit: 200 } })
    selectedChat.value = res?.data?.chat ?? chat
    messages.value = res?.data?.messages ?? []
  } catch (e) {
    console.error(e)
    chatError.value = 'Failed to load chat messages.'
    messages.value = []
  } finally {
    loadingChat.value = false
  }
}

const chatRows = computed(() => props.chats?.data || [])

onMounted(() => {
  if (chatRows.value.length) {
    selectChat(chatRows.value[0])
  }
})
</script>

<template>
  <AuthenticatedLayout>
    <div class="mx-auto max-w-8xl px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between gap-4 mb-4">
        <h1 class="text-xl font-semibold text-slate-800">Chat History</h1>
        <button type="button" class="text-sm text-slate-800 btn btn-primary hover:underline" style="color:#fff" @click="resetFilters">Reset</button>
      </div>

      <!-- Filters -->
      <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
      <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
        <div class="md:col-span-2">
          <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
          <input
            v-model="form.search"
            type="text"
            class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="Phone, name, email, website"
            @keyup.enter="applyFilters"
          />
        </div>


        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Assigned</label>
          <select
            v-model="form.assigned"
            class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
            @change="applyFilters"
          >
            <option value="any">Any</option>
            <option value="me">Me</option>
            <option value="assigned">Assigned</option>
            <option value="unassigned">Unassigned</option>
          </select>
        </div>

        <div class="md:col-span-2">
          <label class="block text-xs font-medium text-slate-600 mb-1">Company</label>
          <select
            v-model="form.company_id"
            class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
            @change="applyFilters"
          >
            <option value="">All</option>
            <option v-for="c in companies" :key="c.uuid" :value="c.uuid">{{ c.name }}</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">From</label>
          <input
            v-model="form.from"
            type="date"
            class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
            @change="applyFilters"
          />
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">To</label>
          <input
            v-model="form.to"
            type="date"
            class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
            @change="applyFilters"
          />
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Per page</label>
          <select
            v-model.number="form.per_page"
            class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
            @change="applyFilters"
          >
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
            <option :value="200">200</option>
          </select>
        </div>

        <div class="flex items-end">
          <button
            type="button"
            class="w-full md:w-auto px-4 py-2 rounded bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700"
            @click="applyFilters"
          >
            Apply
          </button>
        </div>
      </div>
    </div>

      <div class="flex bg-slate-50 rounded-xl overflow-hidden border border-slate-200 shadow-lg" style="height: calc(100vh - 85px);">

        <!-- ═══════════════════ LEFT SIDEBAR ═══════════════════ -->
        <aside class="flex flex-col bg-white border-r border-slate-200 overflow-hidden" style="width: 350px; min-width: 350px;">

          <!-- Recent chats header -->
          <div class="px-4 py-4 border-b border-slate-100">
            <div class="flex items-end justify-between">
              <div>Chat History</div>
            </div>
          </div>

          <!-- Chat list -->
          <div class="flex-1 overflow-y-auto p-2 space-y-1">
            <button
              v-for="chat in chats.data"
              :key="chat.id"
              type="button"
              :class="[
                'w-full text-left relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
                selectedChat?.id === chat.id
                  ? 'bg-indigo-50 ring-1 ring-indigo-200'
                  : 'hover:bg-slate-50'
              ]"
              @click="selectChat(chat)"
            >
              <!-- Avatar -->
              <div class="relative flex-shrink-0">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-slate-500 text-xs font-bold font-mono" :style="{ backgroundColor: '#cbd5e1' }">
                  #{{ chat.id }}
                </div>
              </div>

              <!-- Text Info -->
              <div class="flex-1 min-w-0 pr-12">
                <div class="flex items-center gap-2 mb-0.5">
                  <span class="text-sm text-gray-800 font-semibold">
                    Agent: {{ chat?.agent?.name }}
                  </span>
                </div>

                <div class="text-xs text-slate-500 truncate mb-1">
                  {{ latestSnippet(chat) }}
                </div>

                <div class="text-[11px] text-slate-500 whitespace-nowrap">
                  {{ formatDateTime(chat.last_message_at) }}
                </div>
              </div>
            </button>

            <div v-if="!chats.data?.length" class="px-4 py-10 text-center text-slate-500">
              No chats found.
            </div>
          </div>

          <div class="px-4 py-3 border-t border-slate-200">
            <Pagination :links="chats.links" />
          </div>
        </aside>

        <!-- ═══════════════════ MAIN CONTENT ═══════════════════ -->
        <main class="flex-1 flex flex-col bg-slate-50 overflow-hidden">
          <template v-if="selectedChat">

            <div class="flex items-center gap-3 px-5 py-3.5 bg-white border-b border-slate-200 shadow-sm">
              <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-xs font-bold font-mono flex-shrink-0" style="background: linear-gradient(135deg, #4f46e5, #818cf8);">
                #{{ selectedChat.id }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="font-bold text-gray-900 text-sm tracking-tight">Chat #{{ selectedChat.id }}</div>
                <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                  <span class="text-xs text-slate-400">
                    Visitor: {{ selectedChat.customer_name || '—' }}
                  </span>
                </div>
              </div>

              <div class="flex items-center gap-2 flex-shrink-0">
                <div class="text-xs text-slate-500">
                  Last: {{ formatDateTime(selectedChat.last_message_at) }}
                </div>
              </div>
            </div>

            <div v-if="chatError" class="px-4 py-3 text-sm text-red-700 bg-red-50 border-b border-red-200">
              {{ chatError }}
            </div>

            <div class="flex-1 overflow-y-auto px-5 py-5 flex flex-col gap-3">
              <div v-if="loadingChat" class="text-center text-slate-500 text-sm py-10">Loading…</div>

              <div v-else-if="!messages.length" class="text-center text-slate-500 text-sm py-10">
                No messages found.
              </div>

              <div v-else class="space-y-3">
                <div
                  v-for="msg in messages"
                  :key="msg.id"
                  class="flex flex-col"
                  :class="isMine(msg) ? 'items-end' : 'items-start'"
                >
                  <div class="max-w-[80%] px-4 py-2.5 text-sm leading-relaxed break-words" :class="bubbleClass(msg)">
                    {{ messageText(msg) }}
                  </div>
                  <div class="mt-1 text-[11px] text-slate-400" :class="isMine(msg) ? 'pr-1' : 'pl-1'">
                    {{ formatDateTime(msg.created_at) }}
                  </div>
                </div>
              </div>
            </div>
          </template>

          <div v-else class="flex-1 flex flex-col items-center justify-center gap-4 text-slate-400 px-12">
            <div class="w-20 h-20 rounded-2xl bg-white border border-slate-200 shadow-sm flex items-center justify-center">
              <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
              </svg>
            </div>
            <div class="text-center">
              <p class="font-bold text-gray-700 text-base mb-1">No conversation selected</p>
              <p class="text-sm text-slate-400">Choose a chat from the sidebar to get started</p>
            </div>
          </div>
        </main>

       
      </div>
    </div>
  </AuthenticatedLayout>
</template>

