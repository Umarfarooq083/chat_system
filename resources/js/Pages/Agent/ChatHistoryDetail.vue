<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  chat: { type: Object, required: true },
  messages: { type: Object, required: true },
})

const formatDateTime = (value) => {
  if (!value) return '—'
  try {
    return new Date(value).toLocaleString()
  } catch {
    return value
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
</script>

<template>
  <AuthenticatedLayout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between gap-4 mb-4">
        <div>
          <div class="text-sm text-slate-500">
            <Link :href="route('agent.chats.history')" class="text-indigo-600 hover:underline">Chat History</Link>
            <span class="mx-1">/</span>
            <span>Chat #{{ chat.id }}</span>
          </div>
          <h1 class="text-xl font-semibold text-slate-800 mt-1">Chat Details</h1>
        </div>
        <span
          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
          :class="chat.status === 'close' ? 'bg-slate-100 text-slate-700' : 'bg-emerald-100 text-emerald-800'"
        >
          {{ chat.status || '—' }}
        </span>
      </div>

      <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
          <div>
            <div class="text-xs text-slate-500">Visitor</div>
            <div class="font-semibold text-slate-800">{{ chat.customer_name || '—' }}</div>
            <div class="text-slate-600">{{ chat.phone || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Registration / Email</div>
            <div class="text-slate-700">{{ chat.registration_no || '—' }}</div>
            <div class="text-slate-700">{{ chat.email || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Company</div>
            <div class="text-slate-700">{{ chat.company_rel?.name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Assigned Agent</div>
            <div class="text-slate-700">{{ chat.agent?.name || '—' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Last Message At</div>
            <div class="text-slate-700">{{ formatDateTime(chat.last_message_at) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Created At</div>
            <div class="text-slate-700">{{ formatDateTime(chat.created_at) }}</div>
          </div>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 text-sm font-semibold text-slate-700">
          Messages
        </div>

        <div class="divide-y divide-slate-100">
          <div v-for="msg in messages.data" :key="msg.id" class="px-4 py-3">
            <div class="flex items-center justify-between gap-3">
              <div class="text-sm font-semibold text-slate-800">
                {{ msg.sender_type || '—' }}
                <span v-if="msg.message_type" class="text-xs font-normal text-slate-500">• {{ msg.message_type }}</span>
              </div>
              <div class="text-xs text-slate-500">{{ formatDateTime(msg.created_at) }}</div>
            </div>

            <div v-if="messageText(msg)" class="mt-2 text-sm text-slate-700 whitespace-pre-wrap break-words">
              {{ messageText(msg) }}
            </div>

            <div v-if="msg.attachment_view_url" class="mt-2 text-sm">
              <a
                class="text-indigo-600 hover:underline break-all"
                :href="msg.attachment_download_url || msg.attachment_view_url"
                target="_blank"
                rel="noopener"
                :download="msg.attachment_name"
              >
                Download {{ msg.attachment_name || 'attachment' }}
              </a>
            </div>
          </div>

          <div v-if="!messages.data?.length" class="px-4 py-10 text-center text-slate-500">
            No messages found.
          </div>
        </div>

        <div class="px-4 py-3">
          <Pagination :links="messages.links" />
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

