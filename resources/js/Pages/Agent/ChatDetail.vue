<template>
  <div>
    <h2>Chat #{{ chat.id }}</h2>
    <div class="border h-64 overflow-y-auto mb-4 p-2">
      <div v-for="msg in messages" :key="msg.id">
        <strong>{{ msg.sender_type }}:</strong> {{ msg.message }}
      </div>
    </div>

    <form @submit.prevent="sendReply" class="flex">
      <input v-model="replyMessage" type="text" placeholder="Type your reply" class="flex-1 p-2 border rounded-l">
      <button type="submit" class="bg-blue-500 text-white px-4 rounded-r">Send</button>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

const props = usePage().props.value;
const chat = props.chat;
const messages = ref(props.messages || []);
const replyMessage = ref('');

const chatId = chat.id;

// Send reply
const sendReply = () => {
  if (replyMessage.value.trim() === '') return;

  router.post(`/agent/chats/${chatId}/reply`, { message: replyMessage.value });
  replyMessage.value = '';
};

// Listen real-time from visitor
onMounted(() => {
  window.Echo.channel('chat.' + chatId)
    .listen('MessageSent', (e) => {
      messages.value.push(e.message);
    });
});
</script>