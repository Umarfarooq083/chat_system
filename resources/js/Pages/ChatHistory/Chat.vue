<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref} from 'vue'

const props = defineProps({
    chats: Object,
});


const showImageViewer = ref(false)
const currentImageUrl = ref('')
const currentImageName = ref('')



const getUserInfo = (message) => {
    try {
        return typeof message === 'string'
            ? JSON.parse(message)
            : message;
    } catch (e) {
        return {};
    }
};

const attachmentViewUrl = (msg) => (resolveAttachmentUrl(msg?.attachment_view_url))
const attachmentDownloadUrl = (msg) => (resolveAttachmentUrl(msg?.attachment_download_url || msg?.attachment_view_url))

const resolveAttachmentUrl = (relativeOrAbsoluteUrl) => {
  if (!relativeOrAbsoluteUrl) return null
  if (/^https?:\/\//i.test(relativeOrAbsoluteUrl)) return relativeOrAbsoluteUrl

  const cfg = window.ChatConfig || {}
  const apiBase = (cfg.apiBase || '').toString().trim()

  if (/^https?:\/\//i.test(apiBase)) {
    try {
      const origin = new URL(apiBase).origin
      return origin + relativeOrAbsoluteUrl
    } catch (e) {
      // fall through
    }
  }

  return relativeOrAbsoluteUrl
}

const openImageViewer = (imageUrl, imageName = 'Image') => {
  currentImageUrl.value = imageUrl
  currentImageName.value = imageName
  showImageViewer.value = true
}

</script>

<template>

    <Head title="Chat History" />

    <GuestLayout>

        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">
                        Chat History
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">
                        Review your previous conversations.
                    </p>
                </div>
                <span class="rounded-full bg-indigo-100 px-4 py-2 text-sm font-semibold text-indigo-700">
                    {{ chats.data.length }} Messages
                </span>
            </div>
        </template>

        <div class="space-y-6">
            <div v-for="chat in chats.data" :key="chat.id" class="flex items-end gap-3" :class="chat.sender_type === 'visitor'
                ? 'justify-end'
                : 'justify-start'">

                <div v-if="chat.sender_type === 'agent'"
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-300 font-bold">
                    A
                </div>

                <div class="max-w-[70%] rounded-2xl px-5 py-3 shadow" :class="chat.sender_type === 'visitor'
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white border border-gray-200'" v-if="chat?.message_type == 'user_info_response'">
                    <div class="space-y-2">
                        <div>
                            <span class="font-medium">Name:</span>
                            {{ getUserInfo(chat.message).name }}
                        </div>

                        <div>
                            <span class="font-medium">Email:</span>
                            {{ getUserInfo(chat.message).email }}
                        </div>

                        <div>
                            <span class="font-medium">Phone:</span>
                            {{ getUserInfo(chat.message).phone }}
                        </div>

                        <div>
                            <span class="font-medium">Registration #:</span>
                            {{ getUserInfo(chat.message).registration_no }}
                        </div>
                    </div>
                </div>
                <div class="max-w-[70%] rounded-2xl px-5 py-3 shadow" :class="chat.sender_type === 'visitor'
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white border border-gray-200'" v-else-if="chat?.message_type == 'prechat_info_response'">
                    <div class="space-y-2">
                        <div>
                            <span class="font-medium">Name:</span>
                            {{ getUserInfo(chat.message).name }}
                        </div>
                        <div>
                            <span class="font-medium">Phone:</span>
                            {{ getUserInfo(chat.message).phone }}
                        </div>
                    </div>
                </div>

                <div v-else-if="chat.message_type === 'external_data_html'" class="max-w-xl bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs font-bold text-slate-700 mb-2">PDF Sent</div>
                <div v-if="attachmentViewUrl(chat)" class="mt-2">
                  <img
                    v-if="chat.attachment_is_image"
                    :src="attachmentViewUrl(chat)"
                    :alt="chat.attachment_name || 'Attachment'"
                    class="max-w-[180px] max-h-40 rounded border border-gray-200 object-cover cursor-pointer"
                    @click="openImageViewer(attachmentViewUrl(chat), chat.attachment_name)"
                  />
                  <div v-if="chat.attachment_is_image" class="mt-1 text-right">
                    <a
                      :href="attachmentDownloadUrl(chat)"
                      :download="chat.attachment_name"
                      target="_blank"
                      rel="noopener"
                      class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:underline"
                    >
                      <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                        <path d="M12 3v10" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        <path d="M8 11l4 4 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                      </svg>
                      Download
                    </a>
                  </div>
                  <a
                    v-else
                    :href="attachmentDownloadUrl(chat)"
                    :download="chat.attachment_name"
                    target="_blank"
                    rel="noopener"
                    class="text-xs underline break-all"
                  >
                    Download {{ chat.attachment_name || 'file' }}
                  </a>
                </div>
              </div>



                <div class="max-w-[70%] rounded-2xl px-5 py-3 shadow" :class="chat.sender_type === 'visitor'
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white border border-gray-200'" v-else>
                    {{ chat.message }}
                    {{ chat?.attachments }}
                </div>


                <div v-if="chat.sender_type === 'visitor'"
                    class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-600 font-bold text-white">
                    U
                </div>
            </div>


            <div v-if="!chats.data.length" class="rounded-xl border border-dashed border-gray-300 p-12 text-center">
                <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M8 10h8M8 14h5m-7 7h12a2 2 0 002-2V5a2 2 0 00-2-2H6a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>

                <h3 class="mt-4 text-lg font-semibold text-gray-700">
                    No Chat History
                </h3>

                <p class="mt-2 text-gray-500">
                    Your conversations will appear here.
                </p>
            </div>

        </div>


        <div v-if="chats.last_page > 1" class="mt-8 flex justify-center gap-2">
            <Link v-for="link in chats.links" :key="link.label" :href="link.url || '#'" v-html="link.label"
                class="rounded-lg border px-4 py-2 transition" :class="[
                    link.active
                        ? 'bg-indigo-600 text-white'
                        : 'bg-white text-gray-700 hover:bg-gray-100',
                    !link.url && 'pointer-events-none opacity-50'
                ]" />
        </div>
    </GuestLayout>

</template>