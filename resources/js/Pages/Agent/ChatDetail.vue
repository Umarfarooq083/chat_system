<template>
  <div>
    <h2>Chat #{{ chat.id }}</h2>
    <div class="border h-64 overflow-y-auto mb-4 p-2">
      <div v-for="msg in messages" :key="msg.id">
        <strong>{{ msg.sender_type }}:</strong> {{ msg.message }}
      </div>
    </div>

    <form @submit.prevent="sendReply" class="flex flex-col gap-2">
      <div v-if="sendError"
        class="w-full bg-red-50 border border-red-200 text-red-700 p-2 rounded flex items-start justify-between gap-2">
        <span class="whitespace-pre-line">{{ sendError }}</span>
        <button type="button" class="font-bold leading-none" @click="sendError = ''">×</button>
      </div>
      <div class="flex">
        <input v-model="replyMessage" type="text" placeholder="Type your reply" class="flex-1 p-2 border rounded-l">
        <button type="submit" class="bg-blue-500 text-white px-4 rounded-r">Send</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { extractErrorMessage } from '../../utils/extractErrorMessage'

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
  window.Echo.channel('chat.' + chatId)
    .listen('MessageSent', (e) => {
      messages.value.push(e.message);
    });
});
</script>
