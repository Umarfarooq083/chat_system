<template>
  <div>
    <div v-if="chat.status === 'close'" class="mb-4 p-4 bg-slate-100 border border-slate-200 rounded-lg text-center">
      <div class="flex items-center justify-center gap-2 text-slate-600">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 6 6 18" />
          <path d="M6 6l12 12" />
        </svg>
        <span class="font-semibold">This chat has been closed</span>
      </div>
      <p class="text-xs text-slate-500 mt-1">No further messages can be sent.</p>
    </div>

    <h2>Chat #{{ chat.id }}</h2>
    <div class="border h-64 overflow-y-auto mb-4 p-2">
      <div v-for="msg in messages" :key="msg.id">
        <strong>{{ msg.sender_type }}:</strong> {{ msg.message }}
      </div>
    </div>

    <form @submit.prevent="sendReply" class="flex flex-col gap-2" v-if="chat.status !== 'close'">
      <div v-if="sendError"
        class="w-full bg-red-50 border border-red-200 text-red-700 p-2 rounded flex items-start justify-between gap-2">
        <span class="whitespace-pre-line">{{ sendError }}</span>
        <button type="button" class="font-bold leading-none" @click="sendError = ''">×</button>
      </div>
      <div class="flex">
        <input v-model="replyMessage" type="text" placeholder="Type your reply" class="flex-1 p-2 border rounded-l" :disabled="chat.status === 'close'">
        <button type="submit" class="bg-blue-500 text-white px-4 rounded-r" :disabled="chat.status === 'close'">Send</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { extractErrorMessage } from '../../utils/extractErrorMessage'
import { beep, setupAudioUnlock } from '../../utils/beep'

const props = usePage().props.value;
const chat = props.chat;
const messages = ref(props.messages || []);
const replyMessage = ref('');
const sendError = ref('')

const chatId = chat.id;

// Send reply
const sendReply = () => {
  if (replyMessage.value.trim() === '') return;
  const payload = { message: replyMessage.value }

  router.post(`/agent/chats/${chatId}/reply`, payload, {
    onSuccess: () => {
      sendError.value = ''
      replyMessage.value = ''
    },
    onError: (errors) => {
      sendError.value = extractErrorMessage(errors, 'Failed to send. Please try again.')
    },
  });
};

// Listen real-time from visitor
onMounted(() => {
  setupAudioUnlock()
  window.Echo.channel('chat.' + chatId)
    .listen('MessageSent', (e) => {
      messages.value.push(e.message);
      if (e?.message?.sender_type === 'visitor') {
        beep()
      }
    });
});
</script>
