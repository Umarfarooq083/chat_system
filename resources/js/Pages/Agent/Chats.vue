<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { ref, onMounted, onBeforeUnmount, watch,computed } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import { extractErrorMessage } from '../../utils/extractErrorMessage'
import { beep, setupAudioUnlock } from '../../utils/beep'

// Props from backend
const props = defineProps({
  chats: {
    type: Array,
    default: () => []
  },
  auth_user: {
    type: Object,
    default: () => []
  },
  loginUserCompniesList: {
    type: Object,
    default: () => []
  },
  pollCursor: {
    type: String,
    default: null
  },
})

const chats = ref([])
const selectedChat = ref(null)
const messages = ref([])
const replyMessage = ref('')
const sendError = ref('')
const externalFetching = ref(false)
const externalPdfSending = ref(false)
const externalHtmlSending = ref(false)
const markingRead = ref(new Set())
const subscribedChatIds = new Set()
const pollCursor = ref(props.pollCursor)

const showImageViewer = ref(false)
const currentImageUrl = ref('')
const currentImageName = ref('')

let onlineFlagsIntervalId = null
let pollIntervalId = null
const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024
let slaNowIntervalId = null
const SLA_FIRST_REPLY_SECONDS = 120
const slaNowMs = ref(Date.now())

const feedbacks = ref([])
const inquiries = ref([])
const feedbackLoading = ref(false)
const feedbackSaving = ref(false)
const showFeedbackPanel = ref(false)
const feedbackForm = ref({
  registration_no: '',
  information: [],
  complain: [],
  request_type: [],
})
const feedbackError = ref('')
const selectedInquiries = ref({});
const attachedFiles = ref([])
const fileInputRef = ref(null)
const isDraggingOver = ref(false)
const pasteListenerActive = ref(false)
const dismissedClosedChatId = ref(null)

const isPrechatPending = computed(() => {
  const chat = selectedChat.value
  if (!chat) return false
  if (chat.prechat_submitted_at) return false

  const phone = (chat.phone || '').toString().trim()
  const name = (chat.customer_name || '').toString().trim()
  const hasBasicInfo = !!phone && !!name
  if (chat.user_info_submitted_at || hasBasicInfo) return false

  return true
})

const cnicModalOpen = ref(false)
const cnicInput = ref('')
const cnicSubmitting = ref(false)
const cnicResult = ref(null)
const cnicError = ref('')

const showTransferModal = ref(false)
const transferLoading = ref(false)
const selectedTransferChat = ref(null)
const selectedTransferUser = ref(null)
const transferUsers = ref([])
const transferUsersLoading = ref(false)
const transferUsersError = ref('')

const openTransferModal = (chat) => {
  selectedTransferChat.value = chat
  selectedTransferUser.value = null
  transferUsers.value = []
  transferUsersError.value = ''
  showTransferModal.value = true

  const companyId = chat?.company_id
  if (!companyId) {
    transferUsersError.value = 'Company ID missing for this chat.'
    return
  }

  transferUsersLoading.value = true
  axios
    .get('/agent/transfer-users', { params: { company_id: companyId } })
    .then((res) => {
      transferUsers.value = res?.data?.users || []
    })
    .catch((e) => {
      console.error('Failed to load transfer users:', e)
      transferUsersError.value = extractErrorMessage(e, 'Failed to load users.')
      transferUsers.value = []
    })
    .finally(() => {
      transferUsersLoading.value = false
    })
}

const closeTransferModal = () => {
  showTransferModal.value = false
  selectedTransferChat.value = null
  selectedTransferUser.value = null
  transferLoading.value = false
  transferUsersLoading.value = false
  transferUsersError.value = ''
  transferUsers.value = []
}

const transferChat = async () => {
  if (!selectedTransferChat.value || !selectedTransferUser.value) return
  transferLoading.value = true
  try {
    const response = await axios.post(`/agent/chats/${selectedTransferChat.value.id}/transfer`, {
      agent_id: selectedTransferUser.value.id
    })
    
    if (response.data?.chat) {
      mergeChatIntoList(response.data.chat)
      if (selectedChat.value?.id === selectedTransferChat.value.id) {
        Object.assign(selectedChat.value, response.data.chat)
      }
    }
    
    closeTransferModal()
  } catch (e) {
    console.log(e)
    console.error('Transfer failed:', e)
  } finally {
    transferLoading.value = false
  }
}

const openCnicModal = () => {
  cnicModalOpen.value = true
  cnicInput.value = ''
  cnicSubmitting.value = false
  cnicResult.value = null
  cnicError.value = ''
}

const closeCnicModal = () => {
  cnicModalOpen.value = false
  cnicSubmitting.value = false
  cnicError.value = ''
}

const dismissClosedOverlay = () => {
  if (selectedChat.value) {
    dismissedClosedChatId.value = selectedChat.value.id
  }
}

const openImageViewer = (imageUrl, imageName = 'Image') => {
  currentImageUrl.value = imageUrl
  currentImageName.value = imageName
  showImageViewer.value = true
}

const closeImageViewer = () => {
  showImageViewer.value = false
  currentImageUrl.value = ''
  currentImageName.value = ''
}

const submitCnicLookup = async () => {
  cnicError.value = ''
  cnicResult.value = null

  const digits = (cnicInput.value || '').toString().replace(/\D+/g, '')
  console.log(digits.length)
  if (digits.length !== 13) {
    cnicError.value = 'CNIC must be 13 digits (e.g. 11111-1111111-1).'
    return
  }

  cnicSubmitting.value = true
  try {
    const response = await axios.post('/agent/cnic/lookup', { cnic: cnicInput.value })
    cnicResult.value = response?.data ?? null
    cnicInput.value = null
  } catch (e) {
    cnicError.value = extractErrorMessage(e) || 'Failed to lookup CNIC.'
  } finally {
    cnicSubmitting.value = false
  }
}

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
  slaNowIntervalId = setInterval(() => {
    slaNowMs.value = Date.now()
  }, 1000)
  setupAudioUnlock()
  document.addEventListener('paste', handlePaste)
  pasteListenerActive.value = true
})

onBeforeUnmount(() => {
  if (onlineFlagsIntervalId) clearInterval(onlineFlagsIntervalId)
  if (pollIntervalId) clearInterval(pollIntervalId)
  if (slaNowIntervalId) clearInterval(slaNowIntervalId)
  if (pasteListenerActive.value) {
    document.removeEventListener('paste', handlePaste)
  }
})

watch(() => props.chats, (newChats) => {
  if (newChats) {
    chats.value = newChats
  }
}, { immediate: true, deep: true })

watch(selectedChat, (newChat, oldChat) => {
  if (newChat?.id !== dismissedClosedChatId.value) {
    dismissedClosedChatId.value = null
  }
})

const selectChat = async (chat) => {
  selectedChat.value = chat
  messages.value = []
  feedbacks.value = []
  inquiries.value = []
  feedbackError.value = ''
  showFeedbackPanel.value = false
  chat.unread_count = 0
  markChatRead(chat.id, true)
  
  try {
    const response = await axios.get(`/agent/chats/${chat.id}/messages`, {
      params: { limit: 10 }
    })
    if (response.data?.chat) Object.assign(chat, response.data.chat)
    messages.value = Array.isArray(response.data?.messages) ? response.data.messages : []
    await fetchFeedbacks(chat.id)
  } catch (e) {
    messages.value = []
  }
}

const fetchFeedbacks = async (chatId) => {
  if (!chatId) return
  feedbackLoading.value = true
  try {
    const response = await axios.get(`/agent/chats/${chatId}/feedbacks`)
    
    feedbacks.value = Array.isArray(response.data?.feedbacks) ? response.data.feedbacks : []
    inquiries.value = Array.isArray(response.data?.inquiries) ? response.data.inquiries : []

    selectedInquiries.value = {}
    inquiries.value.forEach(item => {
      selectedInquiries.value[item.id] = []
    })

    feedbackError.value = ''
  } catch (e) {
    feedbacks.value = []
    inquiries.value = []
    feedbackError.value = extractErrorMessage(e, 'Failed to load chat feedback.')
  } finally {
    feedbackLoading.value = false
  }
}

const openFeedbackPanel = async (chat, event) => {
  if (event?.stopPropagation) event.stopPropagation()
  if (!chat?.id) return
  if (selectedChat.value?.id !== chat.id) {
    await selectChat(chat)
  } else if (!feedbacks.value.length) {
    await fetchFeedbacks(chat.id)
  }
  showFeedbackPanel.value = true
}

const closeFeedbackPanel = () => {
  showFeedbackPanel.value = false
  feedbackForm.value = { information: [], complain: [], request_type: [], registration_no: ''  }
  feedbackError.value = ''
}

const submitFeedback = async () => {
  const chatId = selectedChat.value?.id
  if (!chatId) return

  feedbackSaving.value = true

  try {
    const payload = {
      registration_no: feedbackForm.value.registration_no,
      inquiries: selectedInquiries.value
    }

    const response = await axios.post(`/agent/chats/${chatId}/feedbacks`, payload)

    if (response.data?.feedback) {
      feedbacks.value = [response.data.feedback, ...feedbacks.value]
    } else {
      await fetchFeedbacks(chatId)
    }

    inquiries.value.forEach(item => {
      selectedInquiries.value[item.id] = []
    })

    feedbackForm.value.registration_no = ''
    feedbackError.value = ''

  } catch (e) {
    feedbackError.value = extractErrorMessage(e, 'Failed to save feedback.')
  } finally {
    feedbackSaving.value = false
  }
}

onMounted(() => {
  inquiries.value.forEach(item => {
    selectedInquiries.value[item.id] = [];
  });
});

const formatFeedbackDate = (ts) => {
  if (!ts) return ''
  try {
    return new Date(ts).toLocaleString()
  } catch (e) {
    return ''
  }
}

const mergeChatIntoList = (updated) => {
  if (!updated?.id) return
  const idx = chats.value.findIndex(c => c.id === updated.id)
  if (idx !== -1) Object.assign(chats.value[idx], updated)
  if (selectedChat.value?.id === updated.id) Object.assign(selectedChat.value, updated)
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

const sendExternalHtml = async (chat, registrationNo = null) => {
  if (!chat?.id) return
  externalHtmlSending.value = true
  try {
    const payload = {}
    const reg = (registrationNo || '').toString().trim()
    if (reg) payload.registration_no = reg

    const response = await axios.post(`/agent/chats/${chat.id}/external/send-html`, payload)
    if (response.data?.chat) mergeChatIntoList(response.data.chat)
    if (response.data?.message) addMessage(chat.id, response.data.message)
    moveChatToTop(chat.id)
  } catch (e) {
    sendError.value = extractErrorMessage(e, 'Failed to send HTML. Please try again.')
  } finally {
    externalHtmlSending.value = false
  }
}

const registrationNoForUserInfoMessage = (msg) => {
  if (!msg || msg.message_type !== 'user_info_response') return null
  var decoded = JSON.parse(msg.message)
  return decoded.registration_no
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

const sendExternalHtmlForMessage = async (chat, msg) => {
  const registrationNo = registrationNoForUserInfoMessage(msg)
  return sendExternalHtml(chat, registrationNo)
}

const canSendHtmlForMessage = (chat, msg) => {
  const msgReg = (registrationNoForUserInfoMessage(msg) || '').toString().trim()
  const chatReg = (chat?.registration_no || '').toString().trim()
  return !!msgReg && !!chatReg && msgReg === chatReg && chat?.external_api_status === 'success' && !!chat?.external_api_response
}

const triggerFileInput = () => {
  fileInputRef.value?.click()
}

const handlePaste = async (e) => {
  if (!selectedChat.value || selectedChat.value?.status === 'close') return

  const items = e.clipboardData?.items
  if (!items) return

  for (const item of items) {
    if (item.type.startsWith('image/')) {
      e.preventDefault()
      const file = item.getAsFile()
      if (!file) return

      if (file.size > MAX_ATTACHMENT_BYTES) {
        sendError.value = 'File too large. Maximum size is 20 MB.'
        return
      }

      attachedFiles.value = []
      const isImage = file.type.startsWith('image/')
      const preview = isImage ? URL.createObjectURL(file) : null
      attachedFiles.value.push({ file, preview, isImage })
      break
    }
  }
}

const onFileInputChange = (event) => {
  addFiles(Array.from(event.target.files || []))
  event.target.value = ''
}

const addFiles = (newFiles) => {
  const file = newFiles[0]
  if (!file) return
  if (file.size > MAX_ATTACHMENT_BYTES) {
    sendError.value = 'File too large. Maximum size is 20 MB.'
    return
  }
  attachedFiles.value = []
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
    sendError.value = extractErrorMessage(e, 'Failed to send. Please try again.')
    replyMessage.value = tempMessage
    attachedFiles.value = tempFiles
  }
}

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

const showUserInfoForm = async (chat, event) => {
  if (event?.stopPropagation) event.stopPropagation()
  if (selectedChat.value?.id !== chat?.id) {
    await selectChat(chat)
  }
  const formData = {
    chat_id: chat.id,
    message: 'Please provide your information:',
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

const getUserInfo = (msg) => {
  try {
    return typeof msg.message === 'string'
      ? JSON.parse(msg.message)
      : msg.message
  } catch (e) {
    return {}
  }
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
    const nowIso = new Date().toISOString()
    chat.agent_last_read_at = nowIso
    if (selectedChat.value?.id === chatId) {
      selectedChat.value.agent_last_read_at = nowIso
    }
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

  if (message?.message_type === 'user_info_response' && message?.sender_type === 'visitor') {
    resetExternalApiState(chatId)
  }

  if (message.sender_type === 'visitor') chat.is_online = true
  chat.last_message_at = message.created_at
  chat.latest_message = message
  if (message.sender_type === 'visitor' && !chat.first_visitor_message_at) {
    chat.first_visitor_message_at = message.created_at
  }
  if (message.sender_type === 'agent' && chat.first_visitor_message_at && !chat.first_agent_reply_at) {
    chat.first_agent_reply_at = message.created_at
  }
  if (message.sender_type === 'visitor') {
    if (chat?.assigned_agent_id === props.auth_user?.id) {
      beep()
    }
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
      console.log(e,'first chat')
      subscribeToChat(e.chat.id)
    })
    .error((error) => console.error('Error subscribing to newChats channel:', error))
  chats.value.forEach(chat => subscribeToChat(chat.id))
})

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
    .listen('ChatReadUpdated', (e) => {
      const chat = chats.value.find(c => c.id === e.chatId)
      if (!chat) return
      if (e.agentLastReadAt) chat.agent_last_read_at = e.agentLastReadAt
      if (e.visitorLastReadAt) chat.visitor_last_read_at = e.visitorLastReadAt
      if (selectedChat.value?.id === chat.id) {
        if (e.agentLastReadAt) selectedChat.value.agent_last_read_at = e.agentLastReadAt
        if (e.visitorLastReadAt) selectedChat.value.visitor_last_read_at = e.visitorLastReadAt
      }
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

const filteredRegistrationNo = computed(() => {
  return messages.value.filter(msg => msg?.message_type === 'user_info_response');
});

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

const attachmentViewUrl = (msg) => (resolveAttachmentUrl(msg?.attachment_view_url))
const attachmentDownloadUrl = (msg) => (resolveAttachmentUrl(msg?.attachment_download_url || msg?.attachment_view_url))

const formatMessageTime = (timestamp) => {
  if (!timestamp) return ''
  try {
    return new Date(timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } catch (e) {
    return ''
  }
}

const toMillis = (value) => {
  if (!value) return null
  const ts = new Date(value).getTime()
  return Number.isNaN(ts) ? null : ts
}

const slaRemainingSeconds = (chat) => {
  if (!chat) return null
  if (chat.status !== 'open') return null
  if (chat.first_agent_reply_at) return null

  const startedAtMs = toMillis(chat.first_visitor_message_at)
  if (startedAtMs === null) return null

  const elapsedSeconds = Math.floor((slaNowMs.value - startedAtMs) / 1000)
  return SLA_FIRST_REPLY_SECONDS - elapsedSeconds
}

const formatMmSs = (seconds) => {
  const s = Math.max(0, Math.floor(seconds || 0))
  const mm = String(Math.floor(s / 60)).padStart(2, '0')
  const ss = String(s % 60).padStart(2, '0')
  return `${mm}:${ss}`
}

const slaBadgeForChat = (chat) => {
  const remaining = slaRemainingSeconds(chat)
  if (remaining === null) return null
  if (remaining <= 0) return { label: 'SLA Breached', variant: 'breached' }
  return { label: `${formatMmSs(remaining)}`, variant: remaining <= 30 ? 'warning' : 'ok' }
}

const slaBadgeLabel = (chat) => slaBadgeForChat(chat)?.label ?? null

const slaBadgeClass = (chat) => {
  const variant = slaBadgeForChat(chat)?.variant
  if (variant === 'breached') return 'bg-red-100 text-red-800 border-red-200'
  if (variant === 'warning') return 'bg-amber-100 text-amber-800 border-amber-200'
  if (variant === 'ok') return 'bg-emerald-100 text-emerald-800 border-emerald-200'
  return 'bg-slate-100 text-slate-700 border-slate-200'
}

const isMessageReadByRecipient = (msg) => {
  if (!msg || !selectedChat.value) return false
  const messageTs = toMillis(msg.created_at)
  if (messageTs === null) return false

  if (msg.sender_type === 'agent') {
    const visitorReadTs = toMillis(selectedChat.value.visitor_last_read_at)
    return visitorReadTs !== null && messageTs <= visitorReadTs
  }

  const agentReadTs = toMillis(selectedChat.value.agent_last_read_at)
  return agentReadTs !== null && messageTs <= agentReadTs
}

const filteredUnassignChatsByCompany = computed(() => {
  return filteredUnassignChats.value.filter(chat =>
    props.loginUserCompniesList.includes(chat.company_id)
  )
})
</script>

<template>
  <AuthenticatedLayout>
    <template #header>
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-600 text-white">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
          </div>
          <h2 class="text-base font-bold text-gray-900 tracking-tight">Agent Dashboard</h2>
        </div>
        <div class="flex items-center gap-3">
          <button
            type="button"
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-sm"
            @click="openCnicModal"
          >
            CNIC Lookup
          </button>
          <div
            class="flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700 uppercase tracking-widest">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            Live
          </div>
        </div>
      </div>
    </template>

    <!-- CNIC Modal -->
    <Modal :show="cnicModalOpen" @close="closeCnicModal">
      <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900">CNIC Lookup</h2>
        
        <div class="mt-6">
          <InputLabel for="cnic" value="CNIC" />
          <TextInput
            id="cnic"
            v-model="cnicInput"
            type="text"
            class="mt-1 block w-full"
            placeholder="11111-111111-1"
            @keyup.enter="submitCnicLookup"
          />
          <InputError :message="cnicError" class="mt-2" />
        </div>

        <div v-if="cnicResult" class="mt-4">
          <table class="w-full text-left text-sm text-gray-600 mb-4">
            <thead>
              <tr>
                <th class="py-1 px-2 font-medium text-slate-700">CNIC No : {{ cnicResult?.cnic }}</th>
              </tr>
              <tr>
                <th class="py-1 px-2 font-medium text-slate-700">Registration No:</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="py-1 px-2" v-for="value in cnicResult?.data?.data?.files" :key="value">{{ value?.reg_no }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <SecondaryButton type="button" @click="closeCnicModal">Close</SecondaryButton>
          <PrimaryButton
            type="button"
            :class="{ 'opacity-25': cnicSubmitting }"
            :disabled="cnicSubmitting"
            @click="submitCnicLookup"
          >
            {{ cnicSubmitting ? 'Submitting...' : 'Submit' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>

    <!-- Image Viewer Modal -->
    <Modal :show="showImageViewer" @close="closeImageViewer" max-width="max-w-4xl">
      <div class="relative">
        <div class="flex items-center justify-between p-2 border-b border-slate-200">
          <button
            @click="closeImageViewer"
            class="text-gray-400 hover:text-gray-600 transition-colors"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>
        <div class="p-4 flex items-center justify-center bg-gray-900 bg-opacity-95">
          <img
            :src="currentImageUrl"
            :alt="currentImageName"
            class="max-w-full max-h-[80vh] object-contain rounded-lg"
          />
        </div>
        <div class="flex justify-end gap-3 p-2 border-t border-slate-200 bg-white">
          <a
            :href="currentImageUrl"
            :download="currentImageName"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
              <polyline points="7 10 12 15 17 10" />
              <line x1="12" y1="15" x2="12" y2="3" />
            </svg>
            Download
          </a>
          <button
            @click="closeImageViewer"
            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
          >
            Close
          </button>
        </div>
      </div>
    </Modal>

    <div class="flex bg-slate-50 rounded-xl overflow-hidden border border-slate-200 shadow-lg m-4"
      style="height: calc(100vh - 85px);">

      <!-- ═══════════════════ LEFT SIDEBAR ═══════════════════ -->
      <aside class="flex flex-col bg-white border-r border-slate-200 overflow-hidden"
        style="width: 350px; min-width: 350px;">

        <!-- Recent chats header -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Recent chats</div>
          </div>
        </div>

        <!-- Recent (open) chats list -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div
            v-for="chat in filteredOpenChats"
            :key="chat.id"
            @click="selectChat(chat)"
            :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
              selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                ? 'bg-indigo-50 ring-1 ring-indigo-200'
                : chat.unread_count > 0 && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                  : 'hover:bg-slate-50'
            ]"
          >
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div
                :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center text-white text-xs font-bold font-mono',
                  selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                    ? 'text-white'
                    : 'text-slate-500'
                ]"
                :style="{
                  backgroundColor:
                    selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                      ? (chat?.company_rel?.color || '#6366f1')
                      : (chat?.company_rel?.color || '#cbd5e1')
                }"
              >
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span
                v-if="chat.unread_count > 0 && selectedChat?.id !== chat.id && chat?.assigned_agent_id === auth_user.id"
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping"
              ></span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span v-if="chat?.customer_name" :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat: {{ chat?.customer_name }}
                </span>
                <span v-else :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat # {{ chat.id }}
                </span>
                <span
                  v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;"
                >
                  {{ chat.unread_count }}
                </span>
                <span
                  v-if="(chat?.assigned_agent_id === auth_user.id || chat?.assigned_agent_id == null) && slaBadgeLabel(chat)"
                  :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border', slaBadgeClass(chat)]"
                >
                  {{ slaBadgeLabel(chat) }}
                </span>
              </div>

              <p class="text-xs text-slate-500 truncate mb-1" v-if="chat?.latest_message?.message_type == 'user_info_response'">
                {{ getUserInfo(chat?.latest_message?.message) }}
              </p>
              <p v-else-if="chat?.latest_message?.message_type == 'prechat_info_response'" class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.customer_name }}
              </p>
              <p class="text-xs text-slate-500 truncate mb-1" v-else>
                {{ chat?.latest_message?.message || 'No messages' }}
              </p>

              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons (Transfer + Close) -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button
                @click.stop="openTransferModal(chat)"
                title="Transfer Chat"
                :disabled="chat?.assigned_agent_id !== auth_user.id"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-amber-100 text-amber-600 hover:bg-amber-600 hover:text-white transition-colors duration-150 disabled:opacity-30 disabled:cursor-not-allowed"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M5 12h14" />
                  <path d="M12 5l7 7-7 7" />
                </svg>
              </button>
              <button
                @click.stop="closeChat(chat, $event)"
                title="Close Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-slate-100 text-slate-600 hover:bg-slate-600 hover:text-white transition-colors duration-150"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M18 6 6 18" />
                  <path d="M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Previous chats header -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Previous chats</div>
          </div>
        </div>

        <!-- Closed chats list -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div
            v-for="chat in filteredClosedChats"
            :key="chat.id"
            @click="selectChat(chat)"
            :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
              selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                ? 'bg-indigo-50 ring-1 ring-indigo-200'
                : chat.unread_count > 0 && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                  : 'hover:bg-slate-50'
            ]"
          >
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div
                :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center text-white text-xs font-bold font-mono',
                  selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                    ? 'text-white'
                    : 'text-slate-500'
                ]"
                :style="{
                  backgroundColor:
                    selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                      ? (chat?.company_rel?.color || '#6366f1')
                      : (chat?.company_rel?.color || '#cbd5e1')
                }"
              >
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span
                v-if="chat.unread_count > 0 && selectedChat?.id !== chat.id && chat?.assigned_agent_id === auth_user.id"
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping"
              ></span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span v-if="chat?.customer_name" :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat: {{ chat?.customer_name }}
                </span>
                <span v-else :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat # {{ chat.id }}
                </span>
                <span
                  v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;"
                >
                  {{ chat.unread_count }}
                </span>
                <span
                  v-if="(chat?.assigned_agent_id === auth_user.id || chat?.assigned_agent_id == null) && slaBadgeLabel(chat)"
                  :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border', slaBadgeClass(chat)]"
                >
                  {{ slaBadgeLabel(chat) }}
                </span>
              </div>

              <p class="text-xs text-slate-500 truncate mb-1" v-if="chat?.latest_message?.message_type == 'user_info_response'">
                {{ getUserInfo(chat?.latest_message?.message) }}
              </p>
              <p v-else-if="chat?.latest_message?.message_type == 'prechat_info_response'" class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.customer_name }}
              </p>
              <p class="text-xs text-slate-500 truncate mb-1" v-else>
                {{ chat?.latest_message?.message || 'No messages' }}
              </p>

              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons (Delete only for closed) -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button
                @click.stop="deleteChat(chat, $event)"
                title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
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
              style="background: linear-gradient(135deg, #4f46e5, #818cf8);"
            >
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
                <span v-if="selectedChat.ip || selectedChat.ip_address" class="text-xs text-slate-400 font-mono">
                  {{ selectedChat.ip || selectedChat.ip_address }}
                </span>
              </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
              <button
                @click="openFeedbackPanel(selectedChat)"
                title="Feedback"
                class="h-8 px-3 rounded-lg flex items-center justify-center bg-amber-100 text-amber-700 hover:bg-amber-600 hover:text-white transition-colors duration-150"
              >
                <span class="text-xs font-semibold">Feedback</span>
                <span
                  v-if="feedbacks.length"
                  class="ml-2 inline-flex items-center justify-center bg-amber-700 text-white text-[10px] font-bold rounded-full px-1.5 leading-none"
                  style="height: 16px; min-width: 16px;"
                >{{ feedbacks.length }}</span>
              </button>

              <button
                @click="showUserInfoForm(selectedChat)"
                title="Send Info Form"
                class="w-8 h-8 rounded-lg flex items-center justify-center bg-indigo-100 text-indigo-600 hover:bg-indigo-600 hover:text-white transition-colors duration-150"
              >
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M6 12 3 3l18 9-18 9 3-9Z" />
                </svg>
              </button>

              <div
                v-if="(selectedChat?.assigned_agent_id === auth_user.id || selectedChat?.assigned_agent_id == null) && slaBadgeLabel(selectedChat)"
                :class="[
                  'flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-widest border',
                  slaBadgeClass(selectedChat)
                ]"
              >
                {{ slaBadgeLabel(selectedChat) }}
              </div>

              <div :class="[
                'flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold uppercase tracking-widest border',
                selectedChat.is_online
                  ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                  : 'bg-slate-100 text-slate-400 border-slate-200'
              ]">
                <span :class="['w-1.5 h-1.5 rounded-full', selectedChat.is_online ? 'bg-emerald-500' : 'bg-slate-400']"></span>
                {{ selectedChat.is_online ? 'Online' : 'Offline' }}
              </div>
            </div>
          </div>

          <!-- Chat Feedback Panel -->
          <div v-if="showFeedbackPanel" class="bg-white border-b border-slate-200">
            <div class="px-5 py-2 flex items-center justify-between"></div>

            <div class="px-5 pb-4 space-y-3">
              <div v-if="feedbackError" class="border border-red-200 bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2">
                {{ feedbackError }}
              </div>

              <form @submit.prevent="submitFeedback" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                <div class="md:col-span-4" v-for="inquiryList in inquiries" :key="inquiryList.id">
                  <select
                    class="w-full min-h-28 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                    multiple
                    v-model="selectedInquiries[inquiryList.id]"
                  >
                    <option disabled value="">{{ inquiryList.name }}</option>
                    <option
                      v-for="inqiry in inquiryList.clientTemperature"
                      :key="inqiry.id"
                      :value="{ id: inqiry.id, name: inqiry.name }"
                    >
                      {{ inqiry.name }}
                    </option>
                  </select>
                </div>

                <div class="md:col-span-4">
                  <select v-model="feedbackForm.registration_no" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30">
                    <option disabled value="">Registration No</option>
                    <option value="">Unknown</option>
                    <option v-for="message in filteredRegistrationNo" :value="getUserInfo(message).registration_no">
                      {{ getUserInfo(message).registration_no }}
                    </option>
                  </select>
                </div>

                <div class="md:col-span-4 flex items-center justify-end gap-2">
                  <button
                    type="submit"
                    :disabled="feedbackSaving"
                    class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:ring-offset-2"
                  >
                    {{ feedbackSaving ? 'Saving...' : 'Save' }}
                  </button>
                  <button
                    type="button"
                    @click="closeFeedbackPanel"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 focus:ring-offset-2"
                  >
                    Cancel
                  </button>
                </div>
              </form>

              <div class="border-t border-slate-100 pt-3 max-h-60 overflow-y-auto">
                <div class="flex items-center justify-between mb-2">
                  <div class="text-xs font-semibold text-slate-600 uppercase tracking-wider">History</div>
                  <div v-if="feedbackLoading" class="text-xs text-slate-400">Loading...</div>
                </div>

                <div v-if="!feedbackLoading && !feedbacks.length" class="text-sm text-slate-400">
                  No feedback yet.
                </div>

                <div v-for="fb in feedbacks" :key="fb.id" class="bg-slate-50 border border-slate-200 rounded-lg p-3 mb-2">
                  <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-slate-400 flex-shrink-0">
                      {{ formatFeedbackDate(fb.created_at) }}
                    </div>
                  </div>
                  <div class="text-sm text-slate-700 whitespace-pre-line mt-1">
                    {{ fb.inquiry_name }} | <strong>Reg: {{ fb.registration ?? 'N/A' }}</strong>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Messages scroll area -->
          <div class="flex-1 overflow-y-auto px-5 py-5 flex flex-col gap-3">
            <div v-for="msg in messages" :key="msg.id" :class="['flex', msg.sender_type === 'agent' ? 'justify-end' : 'justify-start']">

              <!-- Prechat info request -->
              <div v-if="msg.message_type === 'prechat_info_request'" class="max-w-sm bg-cyan-50 border border-cyan-200 rounded-xl p-3">
                <div class="text-xs font-bold text-cyan-800 mb-1.5 flex items-center gap-1.5">
                  Visitor Details Form Requested
                </div>
              </div>

              <!-- Prechat info response -->
              <div v-else-if="msg.message_type === 'prechat_info_response'" class="max-w-sm bg-cyan-50 border border-cyan-200 rounded-xl p-3">
                <div class="text-xs font-bold text-cyan-800 mb-1.5 flex items-center gap-1.5">
                  Visitor Details Received:
                </div>
                <div class="text-xs text-cyan-800 space-y-1">
                  <div><strong>Name:</strong> {{ getUserInfo(msg).name }}</div>
                  <div><strong>Phone:</strong> {{ getUserInfo(msg).phone }}</div>
                </div>
              </div>

              <!-- User info request -->
              <div v-else-if="msg.message_type === 'user_info_request'" class="max-w-sm bg-blue-50 border border-blue-200 rounded-xl p-3">
                <div class="text-xs font-bold text-blue-700 mb-1.5 flex items-center gap-1.5">
                  <span>📋</span> User Information Form Request Sent
                </div>
              </div>

              <!-- User info response -->
              <div v-else-if="msg.message_type === 'user_info_response'" class="max-w-sm bg-emerald-50 border border-emerald-200 rounded-xl p-3">
                <div class="text-xs font-bold text-emerald-700 mb-1.5 flex items-center gap-1.5">
                  User Information Received:
                </div>
                <div class="text-xs text-emerald-700 space-y-1">
                  <div><strong>Name:</strong> {{ getUserInfo(msg).name }}</div>
                  <div><strong>Email:</strong> {{ getUserInfo(msg).email }}</div>
                  <div><strong>Phone:</strong> {{ getUserInfo(msg).phone }}</div>
                  <div><strong>Reg No:</strong> {{ getUserInfo(msg).registration_no }}</div>
                </div>

                <div class="flex flex-wrap items-center gap-2 mt-3">
                  <button
                    type="button"
                    @click="fetchExternalDataForMessage(selectedChat, msg)"
                    class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm transition-all duration-200 hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed"
                    :disabled="externalFetching"
                  >
                    {{ externalFetching ? 'Fetching...' : 'Fetch Data' }}
                  </button>

                  <button
                    v-if="canSendHtmlForMessage(selectedChat, msg)"
                    type="button"
                    @click="sendExternalHtmlForMessage(selectedChat, msg)"
                    class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-slate-700 hover:bg-slate-800 rounded-lg shadow-sm transition-all duration-200 hover:shadow-md disabled:opacity-60 disabled:cursor-not-allowed"
                    :disabled="externalHtmlSending"
                  >
                    {{ externalHtmlSending ? 'Sending...' : 'Send PDF' }}
                  </button>
                </div>

                <div
                  v-if="selectedChat?.external_api_status === 'error' && selectedChat?.external_api_error"
                  class="mt-2 text-xs text-red-700 whitespace-pre-line"
                >
                  {{ selectedChat.external_api_error }}
                </div>
              </div>

              <!-- External HTML / PDF sent message -->
              <div v-else-if="msg.message_type === 'external_data_html'" class="max-w-xl bg-white border border-slate-200 rounded-xl p-3 shadow-sm">
                <div class="text-xs font-bold text-slate-700 mb-2">PDF Sent</div>
                <div v-if="attachmentViewUrl(msg)" class="mt-2">
                  <img
                    v-if="msg.attachment_is_image"
                    :src="attachmentViewUrl(msg)"
                    :alt="msg.attachment_name || 'Attachment'"
                    class="max-w-[180px] max-h-40 rounded border border-gray-200 object-cover cursor-pointer"
                    @click="openImageViewer(attachmentViewUrl(msg), msg.attachment_name)"
                  />
                  <div v-if="msg.attachment_is_image" class="mt-1 text-right">
                    <a
                      :href="attachmentDownloadUrl(msg)"
                      :download="msg.attachment_name"
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
                    :href="attachmentDownloadUrl(msg)"
                    :download="msg.attachment_name"
                    target="_blank"
                    rel="noopener"
                    class="text-xs underline break-all"
                  >
                    Download {{ msg.attachment_name || 'file' }}
                  </a>
                </div>
              </div>

              <!-- Regular message with optional attachment -->
              <div v-else class="flex flex-col gap-1.5" :class="msg.sender_type === 'agent' ? 'items-end' : 'items-start'">
                <template v-if="msg.attachment_view_url">
                  <img
                    v-if="msg.attachment_is_image"
                    :src="msg.attachment_view_url"
                    :alt="msg.attachment_name || 'Attachment'"
                    class="max-w-xs max-h-48 rounded-xl border border-slate-200 object-cover shadow-sm cursor-pointer hover:opacity-90 transition-opacity"
                    @click="openImageViewer(msg.attachment_view_url, msg.attachment_name)"
                  />
                  <a
                    v-else
                    :href="msg.attachment_download_url || msg.attachment_view_url"
                    :download="msg.attachment_name"
                    :class="[
                      'flex items-center gap-2 px-3 py-2 rounded-xl border text-xs font-medium transition-colors',
                      msg.sender_type === 'agent'
                        ? 'bg-indigo-500 border-indigo-400 text-white hover:bg-indigo-400'
                        : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                    ]"
                  >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                    </svg>
                    <span class="truncate max-w-[160px]">{{ msg.attachment_name || 'Download file' }}</span>
                  </a>
                </template>

                <div
                  v-if="msg.message"
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

              <!-- Timestamp + read receipt -->
              <div
                class="mt-1 text-[11px] text-slate-400 flex items-center gap-1"
                :class="msg.sender_type === 'agent' ? 'justify-end pr-1' : 'justify-start pl-1'"
              >
                <span>{{ formatMessageTime(msg.created_at) }}</span>
                <span v-if="msg.sender_type === 'agent'" :class="isMessageReadByRecipient(msg) ? 'text-sky-500' : 'text-slate-400'">
                  {{ isMessageReadByRecipient(msg) ? '✓✓' : '✓' }}
                </span>
              </div>
            </div>
          </div>

          <!-- ── Reply bar ── -->
          <div
            class="bg-white border-t border-slate-200 transition-all duration-200"
            :class="isDraggingOver ? 'ring-2 ring-inset ring-indigo-400 bg-indigo-50' : ''"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
          >
            <!-- Attachment previews -->
            <div v-if="attachedFiles.length" class="flex flex-wrap gap-2 px-4 pt-3 pb-1">
              <div
                v-for="(item, index) in attachedFiles"
                :key="index"
                class="relative group flex items-center gap-2 bg-slate-100 border border-slate-200 rounded-lg overflow-hidden"
              >
                <template v-if="item.isImage">
                  <img :src="item.preview" :alt="item.file.name" class="w-14 h-14 object-cover" />
                </template>
                <template v-else>
                  <div class="flex items-center gap-2 px-3 py-2">
                    <span class="text-base leading-none">{{ getFileIcon(item.file) }}</span>
                    <div class="min-w-0">
                      <p class="text-xs font-semibold text-slate-700 truncate max-w-[120px]">{{ item.file.name }}</p>
                    </div>
                  </div>
                </template>
                <button
                  @click="removeAttachment(index)"
                  class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-slate-700 bg-opacity-80 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-500"
                  title="Remove"
                >
                  <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Drag-over hint -->
            <div v-if="isDraggingOver" class="flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-indigo-600">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
              </svg>
              Drop files to attach
            </div>

            <!-- Error message -->
            <div v-if="sendError" class="px-4 pb-2">
              <div class="border border-red-200 bg-red-50 text-red-700 text-sm rounded-lg px-3 py-2 flex items-start justify-between gap-2">
                <span class="whitespace-pre-line">{{ sendError }}</span>
                <button type="button" class="font-bold leading-none" @click="sendError = ''">×</button>
              </div>
            </div>

            <!-- Input row -->
            <form @submit.prevent="sendReply" v-if="selectedChat?.assigned_agent_id === auth_user.id" class="relative flex items-center gap-0 px-4 py-3">
              <!-- Hidden file input -->
              <input ref="fileInputRef" type="file" class="hidden" @change="onFileInputChange" />

              <!-- Chat Closed Overlay -->
              <div
                v-if="selectedChat?.status === 'close' && dismissedClosedChatId !== selectedChat.id"
                class="absolute inset-0 bg-white/90 flex flex-col items-center justify-center z-10"
              >
                <div class="text-center">
                  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mx-auto mb-3 text-slate-400">
                    <path d="M18 6 6 18" />
                    <path d="M6 6l12 12" />
                  </svg>
                  <p class="text-sm font-semibold text-slate-600">Chat Closed</p>
                  <p class="text-xs text-slate-400 mt-1 mb-4">No further messages can be sent.</p>
                  <button
                    @click="dismissClosedOverlay"
                    class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-medium rounded-lg transition-colors"
                  >
                    Dismiss
                  </button>
                </div>
              </div>

              <!-- Attach button -->
              <button
                type="button"
                @click="triggerFileInput"
                title="Attach files or Drag and drop here"
                class="flex-shrink-0 flex items-center justify-center w-9 h-9 mr-2 rounded-xl bg-slate-100 text-slate-500 hover:bg-indigo-100 hover:text-indigo-600 transition-colors border border-slate-200 hover:border-indigo-200"
                :disabled="selectedChat?.status === 'close'"
                :class="[
                  attachedFiles.length ? 'bg-indigo-100 text-indigo-600 border-indigo-200' : '',
                  selectedChat?.status === 'close' ? 'opacity-50 cursor-not-allowed' : ''
                ]"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
                </svg>
                <span
                  v-if="attachedFiles.length"
                  class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center leading-none"
                  style="font-size: 9px;"
                >{{ attachedFiles.length }}</span>
              </button>

              <!-- Textarea -->
              <textarea
                v-model="replyMessage"
                rows="1"
                placeholder="Type your reply…"
                :disabled="selectedChat?.status === 'close'"
                :class="[
                  'flex-1 bg-slate-50 border border-slate-200 border-r-0 rounded-l-xl px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-400 focus:bg-white transition-colors placeholder-slate-400 resize-none overflow-hidden',
                  selectedChat?.status === 'close' ? 'opacity-50 cursor-not-allowed' : ''
                ]"
              ></textarea>

              <!-- Send button -->
              <button
                type="submit"
                :disabled="selectedChat?.status === 'close'"
                :class="[
                  'flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white border-none rounded-r-xl px-5 py-2.5 text-sm font-semibold transition-colors cursor-pointer',
                  selectedChat?.status === 'close' ? 'opacity-60 cursor-not-allowed' : ''
                ]"
              >
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

      <!-- ═══════════════════ RIGHT SIDEBAR ═══════════════════ -->
      <aside class="flex flex-col bg-white border-l border-slate-200 overflow-hidden"
        style="width: 350px; min-width: 350px;">

        <!-- Unassign chats header -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Unassign chats</div>
            <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-xs font-semibold text-emerald-700">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              {{ chats.filter(c => c.is_online).length }} online
            </div>
          </div>
        </div>

        <!-- Unassign chats list -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div
            v-for="chat in filteredUnassignChatsByCompany"
            :key="chat.id"
            @click="selectChat(chat)"
            :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
              selectedChat?.id === chat.id
                ? 'bg-indigo-50 ring-1 ring-indigo-200'
                : chat.unread_count > 0
                  ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                  : 'hover:bg-slate-50'
            ]"
          >
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div
                class="w-10 h-10 rounded-xl flex items-center justify-center text-slate-500 text-xs font-bold font-mono"
                :style="{ backgroundColor: chat?.company_rel?.color || '#cbd5e1' }"
              >
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span v-if="chat.unread_count > 0" class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping"></span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat #{{ chat.id }}
                </span>
                <span
                  v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;"
                >
                  {{ chat.unread_count }}
                </span>
                <span
                  v-if="(chat?.assigned_agent_id === auth_user.id || chat?.assigned_agent_id == null) && slaBadgeLabel(chat)"
                  :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border', slaBadgeClass(chat)]"
                >
                  {{ slaBadgeLabel(chat) }}
                </span>
              </div>

              <p class="text-xs text-slate-500 truncate mb-1" v-if="chat?.latest_message?.message_type == 'user_info_response'">
                {{ getUserInfo(chat?.latest_message?.message) }}
              </p>
              <p v-else-if="chat?.latest_message?.message_type == 'prechat_info_response'" class="text-xs text-slate-500 truncate mb-1">
                {{ chat?.customer_name }}
              </p>
              <p class="text-xs text-slate-500 truncate mb-1" v-else>
                {{ chat?.latest_message?.message || 'No messages' }}
              </p>

              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button
                @click.stop="deleteChat(chat, $event)"
                title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <polyline points="3 6 5 6 21 6" />
                  <path d="M19 6l-1 14H6L5 6" />
                  <path d="M10 11v6M14 11v6" />
                  <path d="M9 6V4h6v2" />
                </svg>
              </button>
            </div>
          </div>
        </div>

        <!-- Other chats header -->
        <div class="px-4 py-4 border-b border-slate-100">
          <div class="flex items-end justify-between">
            <div>Other chats</div>
          </div>
        </div>

        <!-- Other chats list -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div
            v-for="chat in filteredGlobalChats"
            :key="chat.id"
            @click="selectChat(chat)"
            :class="[
              'relative flex items-start gap-2.5 p-2.5 rounded-xl cursor-pointer transition-all duration-150 group',
              selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                ? 'bg-indigo-50 ring-1 ring-indigo-200'
                : chat.unread_count > 0 && chat?.assigned_agent_id === auth_user.id
                  ? 'bg-red-50 ring-1 ring-red-300 animate-pulse hover:bg-red-50'
                  : 'hover:bg-slate-50'
            ]"
          >
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
              <div
                :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center text-white text-xs font-bold font-mono',
                  selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id ? 'text-white' : 'text-slate-500'
                ]"
                :style="{
                  backgroundColor:
                    selectedChat?.id === chat.id && chat?.assigned_agent_id === auth_user.id
                      ? (chat?.company_rel?.color || '#6366f1')
                      : (chat?.company_rel?.color || '#cbd5e1')
                }"
              >
                #{{ chat.id }}
              </div>
              <span :class="[
                'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-white',
                chat.is_online ? 'bg-emerald-500' : 'bg-slate-300'
              ]"></span>
              <span
                v-if="chat.unread_count > 0 && selectedChat?.id !== chat.id && chat?.assigned_agent_id === auth_user.id"
                class="absolute -top-1 -left-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white animate-ping"
              ></span>
            </div>

            <!-- Text Info -->
            <div class="flex-1 min-w-0 pr-12">
              <div class="flex items-center gap-2 mb-0.5">
                <span v-if="chat?.customer_name" :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat: {{ chat?.customer_name }}
                </span>
                <span v-else :class="['text-sm text-gray-800', chat.unread_count > 0 ? 'font-bold' : 'font-semibold']">
                  Chat # {{ chat.id }}
                </span>
                <span
                  v-if="chat.unread_count > 0"
                  class="inline-flex items-center justify-center bg-red-500 text-white text-xs font-bold rounded-full px-1.5 leading-none"
                  style="min-width: 20px; height: 18px;"
                >
                  {{ chat.unread_count }}
                </span>
                <span
                  v-if="(chat?.assigned_agent_id === auth_user.id || chat?.assigned_agent_id == null) && slaBadgeLabel(chat)"
                  :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border', slaBadgeClass(chat)]"
                >
                  {{ slaBadgeLabel(chat) }}
                </span>
              </div>

              <p class="text-xs text-slate-500 truncate mb-1" v-if="chat?.latest_message?.message_type == 'user_info_response'">
                {{ getUserInfo(chat?.latest_message?.message) }}
              </p>
              <p class="text-xs text-slate-500 truncate mb-1" v-else>
                {{ chat?.latest_message?.message || 'No messages' }}
              </p>

              <p v-if="chat.current_url" class="text-xs text-slate-400 truncate flex items-center gap-1">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
                {{ chat.current_url }}
              </p>
            </div>

            <!-- Action buttons -->
            <div class="absolute top-2 right-2 flex flex-col gap-1">
              <button
                @click.stop="deleteChat(chat, $event)"
                title="Delete Chat"
                class="w-6 h-6 rounded-md flex items-center justify-center bg-red-100 text-red-500 hover:bg-red-500 hover:text-white transition-colors duration-150"
              >
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
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
    </div>

    <!-- Transfer Chat Modal -->
    <Modal :show="showTransferModal" @close="closeTransferModal" max-width="max-w-md">
      <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900 mb-4">Transfer Chat</h2>

        <div class="mb-4">
          <p class="text-sm text-slate-600 mb-2">Select user to transfer chat to:</p>
          <select
            v-model="selectedTransferUser"
            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
            :disabled="transferUsersLoading || transferLoading"
          >
            <option :value="null" disabled>
              {{ transferUsersLoading ? 'Loading users...' : 'Select a user...' }}
            </option>
            <option v-for="user in transferUsers" :key="user.id" :value="user">
              {{ user.name }} ({{ user.email }})
            </option>
          </select>
          <p v-if="transferUsersError" class="mt-2 text-sm text-red-600">{{ transferUsersError }}</p>
        </div>

        <div class="flex justify-end gap-2">
          <SecondaryButton type="button" @click="closeTransferModal">Cancel</SecondaryButton>
          <PrimaryButton
            type="button"
            @click="transferChat"
            :class="{ 'opacity-50 cursor-not-allowed': !selectedTransferUser || transferLoading }"
            :disabled="!selectedTransferUser || transferLoading"
          >
            {{ transferLoading ? 'Transferring...' : 'Transfer' }}
          </PrimaryButton>
        </div>
      </div>
    </Modal>
  </AuthenticatedLayout>
</template>
