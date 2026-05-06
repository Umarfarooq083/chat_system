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
  status: props.filters.status ?? '',
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
  form.status = ''
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
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between gap-4 mb-4">
        <h1 class="text-xl font-semibold text-slate-800">Chat History</h1>
        <button type="button" class="text-sm text-slate-600 hover:underline" @click="resetFilters">Reset</button>
      </div>

      <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
          <div class="md:col-span-2">
            <label class="block text-xs font-medium text-slate-600 mb-1">Search</label>
            <input
              v-model="form.search"
              type="text"
              class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
              placeholder="Phone, name, reg, email, website"
              @keyup.enter="applyFilters"
            />
          </div>

          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
            <select
              v-model="form.status"
              class="w-full rounded border-slate-300 focus:border-indigo-500 focus:ring-indigo-500"
              @change="applyFilters"
            >
              <option value="">Any</option>
              <option value="open">Open</option>
              <option value="close">Closed</option>
            </select>
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

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
        <div class="lg:col-span-4 bg-white border border-slate-200 rounded-lg overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 text-sm font-semibold text-slate-700">
            Chats
          </div>

          <div class="divide-y divide-slate-100 max-h-[70vh] overflow-y-auto">
            <button
              v-for="chat in chats.data"
              :key="chat.id"
              type="button"
              class="w-full text-left px-4 py-3 hover:bg-slate-50"
              :class="selectedChat?.id === chat.id ? 'bg-indigo-50' : ''"
              @click="selectChat(chat)"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <div class="font-semibold text-slate-800">#{{ chat.id }}</div>
                    <span
                      class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                      :class="chat.status === 'close' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-800'"
                    >
                      {{ chat.status || '—' }}
                    </span>
                  </div>
                  <div class="text-sm text-slate-800 truncate">
                    {{ chat.customer_name || chat.phone || '—' }}
                  </div>
                  <div class="text-xs text-slate-500 truncate">
                    {{ latestSnippet(chat) }}
                  </div>
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
        </div>

        <div class="lg:col-span-8 bg-white border border-slate-200 rounded-lg overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 bg-slate-50">
            <div class="flex items-center justify-between gap-4">
              <div>
                <div class="text-sm font-semibold text-slate-800">
                  <span v-if="selectedChat">Chat #{{ selectedChat.id }}</span>
                  <span v-else>Select a chat</span>
                </div>
                <div v-if="selectedChat" class="text-xs text-slate-500 mt-0.5">
                  Visitor: {{ selectedChat.customer_name || '—' }} ({{ selectedChat.phone || '—' }})
                  <span class="mx-1">•</span>
                  Agent: {{ selectedChat.agent?.name || '—' }}
                </div>
              </div>
              <div v-if="selectedChat" class="text-xs text-slate-500">
                Last: {{ formatDateTime(selectedChat.last_message_at) }}
              </div>
            </div>
          </div>

          <div v-if="chatError" class="px-4 py-3 text-sm text-red-700 bg-red-50 border-b border-red-200">
            {{ chatError }}
          </div>

          <div class="h-[70vh] overflow-y-auto p-4 bg-slate-50">
            <div v-if="loadingChat" class="text-center text-slate-500 text-sm py-10">Loading…</div>

            <div v-else-if="!selectedChat" class="text-center text-slate-500 text-sm py-10">
              Select a chat from the left to view details.
            </div>

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
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

