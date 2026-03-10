<script setup lang="ts">
import { ref, computed, shallowRef, nextTick, onMounted, onUnmounted, watch } from 'vue'
import { useDisplay } from 'vuetify'
import axios from 'axios'

// ── Types ─────────────────────────────────────────────────────────────────────
interface Message {
    id: number
    conversation_id: number
    whatsapp_message_id: string | null
    direction: 'inbound' | 'outbound'
    message_type: string
    content: Record<string, any>
    status: 'sent' | 'delivered' | 'read' | 'failed'
    sent_at: string | null
    delivered_at: string | null
    read_at: string | null
    created_at: string
    sender_type?: 'agent' | 'bot'
    sender_name?: string
    _pending?: boolean
}

interface Conversation {
    id: number
    whatsapp_user_phone: string
    whatsapp_user_name: string | null
    status: string
    last_message_at: string | null
    unread_count: number
    latest_message?: Message
    whatsapp_account?: { id: number; verified_name: string; display_phone_number: string }
    // Shaped for the template (mirrors inspo's chat shape)
    messages: Message[]
}

// ── Display ───────────────────────────────────────────────────────────────────
const { mdAndUp } = useDisplay()

// ── State ─────────────────────────────────────────────────────────────────────
const toggleSide = ref(false)
const sDrawer = ref(false)
const infodrawer = ref(false)
const searchValue = ref('')
const newMessage = ref('')
const notification = ref(true)
const panel1 = ref([0])
const activeChatId = ref<number | null>(null)
const loadingConvos = ref(false)
const loadingMessages = ref(false)
const sending = ref(false)
const hasMoreMessages = ref(false)
const currentPage = ref(1)
const messagesEnd = ref<HTMLElement | null>(null)
const typingDebounce = ref<ReturnType<typeof setTimeout> | null>(null)
const remoteTyping = ref(false)
const remoteTypingName = ref('')
const remoteTypingTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const echo = ref<any>(null)
const replyingTo = ref<Message | null>(null)

// ── Bottom sheet (WhatsApp list messages) ─────────────────────────────────────
interface SheetRow { id: string; title: string; description?: string }
interface SheetSection { title?: string; rows: SheetRow[] }
const sheetOpen = ref(false)
const sheetTitle = ref('')
const sheetSections = ref<SheetSection[]>([])

function openListSheet(msg: Message) {
    sheetSections.value = (msg.content?.action?.sections as SheetSection[]) ?? []
    sheetTitle.value = msg.content?.action?.button ?? 'Options'
    sheetOpen.value = true
}
function closeSheet() { sheetOpen.value = false }

// ── Current user (logged-in agent) ────────────────────────────────────────────
const currentUser = ref({
    name: (window as any).__USER_NAME__ ?? 'Agent',
    avatar: (window as any).__USER_AVATAR__ ?? null,
    status: 'active',
})

// ── Static menu data ──────────────────────────────────────────────────────────
const attach = shallowRef([
    { color: 'success', icon: 'custom-file-outline-2', name: 'Document', size: '123 files, 193MB' },
    { color: 'warning', icon: 'custom-picture-outline', name: 'Photos', size: '53 files, 321MB' },
    { color: 'primary', icon: 'custom-document-outline-1', name: 'Other', size: '49 files, 193MB' },
])
const menuItems = shallowRef([
    { title: 'Archive', icon: 'custom-document-2' },
    { title: 'Muted', icon: 'custom-speaker-outline' },
    { title: 'Delete', icon: 'custom-trash' },
])
const replyItems = shallowRef([
    { title: 'Reply', icon: 'custom-reply-outline' },
    { title: 'Forward', icon: 'custom-play-outline' },
    { title: 'Copy', icon: 'custom-copy' },
    { title: 'Delete', icon: 'custom-trash' },
])
const profileItems = shallowRef([
    { title: 'active', icon: 'custom-check-circle-fill', color: 'success' },
    { title: 'away', icon: 'custom-away-fill', color: 'warning' },
    { title: 'do not disturb', icon: 'custom-disturb-fill', color: 'secondary' },
])

// ── Conversations list (mirrors dummyChats shape) ─────────────────────────────
const conversations = ref<Conversation[]>([])

// ── Computed ──────────────────────────────────────────────────────────────────
const filteredChats = computed(() =>
    conversations.value.filter(c =>
        (c.whatsapp_user_name ?? c.whatsapp_user_phone)
            .toLowerCase()
            .includes(searchValue.value.toLowerCase())
    )
)

const selectedChat = computed<Conversation | undefined>(() =>
    conversations.value.find(c => c.id === activeChatId.value)
)

// ── Helpers ───────────────────────────────────────────────────────────────────
function getUserInitials(conv: Conversation): string {
    const name = conv.whatsapp_user_name
    if (!name) return conv.whatsapp_user_phone.slice(-2)
    return name.split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase()
}

function getMessageText(msg: Message): string {
    const c = msg.content
    if (msg.message_type === 'text') return c.text ?? c.body ?? ''
    if (msg.message_type === 'interactive') return c.body?.text ?? c.response?.title ?? '[Interactive]'
    if (msg.message_type === 'image') return c.caption ?? '📷 Image'
    if (msg.message_type === 'video') return c.caption ?? '🎥 Video'
    if (msg.message_type === 'audio') return '🎵 Audio message'
    if (msg.message_type === 'document') return `📄 ${c.filename ?? 'Document'}`
    if (msg.message_type === 'location') return '📍 Location'
    if (msg.message_type === 'contacts') return '👤 Contact'
    return '[Message]'
}

function getLastMessagePreview(conv: Conversation): string {
    const m = conv.latest_message
    if (!m) return 'No messages yet'
    return (m.direction === 'outbound' ? 'You: ' : '') + getMessageText(m)
}

function formatTime(iso: string | null): string {
    if (!iso) return ''
    const d = new Date(iso), now = new Date()
    if (d.toDateString() === now.toDateString())
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    const y = new Date(now); y.setDate(now.getDate() - 1)
    if (d.toDateString() === y.toDateString()) return 'Yesterday'
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function formatMessageTime(iso: string | null): string {
    if (!iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function isSameDay(a: string, b: string): boolean {
    return new Date(a).toDateString() === new Date(b).toDateString()
}

function formatDateLabel(iso: string): string {
    const d = new Date(iso), now = new Date()
    if (d.toDateString() === now.toDateString()) return 'Today'
    const y = new Date(now); y.setDate(now.getDate() - 1)
    if (d.toDateString() === y.toDateString()) return 'Yesterday'
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })
}

function getStatusColor(status: string): string {
    return { active: 'success', completed: 'default', abandoned: 'warning' }[status] ?? 'default'
}

function isGroupedWithPrev(msgs: Message[], idx: number): boolean {
    if (idx === 0) return false
    const curr = msgs[idx], prev = msgs[idx - 1]
    return curr.direction === prev.direction &&
        new Date(curr.created_at).getTime() - new Date(prev.created_at).getTime() < 60000
}

function scrollToBottom() {
    nextTick(() => messagesEnd.value?.scrollIntoView({ behavior: 'instant' }))
}

function appendMessageIfNotExists(conv: Conversation, msg: Message) {
    const exists = conv.messages.some(m =>
        m.id === msg.id || (m.whatsapp_message_id && m.whatsapp_message_id === msg.whatsapp_message_id)
    )
    if (!exists) conv.messages.push(msg)
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => {
    await loadConversations()
    setupEcho()
})
onUnmounted(() => teardownEcho())

// ── Echo / Real-time ──────────────────────────────────────────────────────────
function setupEcho() {
    if (!(window as any).Echo) return
    echo.value = (window as any).Echo
    echo.value
        .private(`tenant.${(window as any).__TENANT_ID__}.inbox`)
        .listen('.message.received', (e: any) => {
            const conv = conversations.value.find(c => c.id === e.conversation.id)
            if (conv) {
                // Update sidebar preview
                conv.latest_message = e.message
                conv.last_message_at = e.message.created_at
                if (activeChatId.value !== conv.id) conv.unread_count++
                // Move to top
                const idx = conversations.value.indexOf(conv)
                if (idx > 0) { conversations.value.splice(idx, 1); conversations.value.unshift(conv) }
                // Append to open chat
                if (activeChatId.value === conv.id) {
                    appendMessageIfNotExists(conv, e.message)
                    markConversationRead()
                    scrollToBottom()
                }
            } else {
                // New conversation — prepend a stub
                conversations.value.unshift({ ...e.conversation, messages: [e.message] })
            }
        })
}

function subscribeToConversation(conversationId: number) {
    if (!echo.value) return
    echo.value
        .private(`conversation.${conversationId}`)
        .listen('.message.received', (e: any) => {
            const conv = conversations.value.find(c => c.id === conversationId)
            if (conv && activeChatId.value === conversationId) {
                appendMessageIfNotExists(conv, e.message)
                scrollToBottom()
            }
        })
        .listen('.message.status', (e: any) => {
            const conv = conversations.value.find(c => c.id === conversationId)
            const msg = conv?.messages.find(m =>
                m.whatsapp_message_id === e.whatsapp_message_id || m.id === e.message_id
            )
            if (msg) {
                msg.status = e.status
                msg.delivered_at = e.delivered_at ?? msg.delivered_at
                msg.read_at = e.read_at ?? msg.read_at
            }
        })
        .listen('.agent.typing', (e: any) => {
            if (e.agent_id === (window as any).__USER_ID__) return
            remoteTyping.value = e.is_typing
            remoteTypingName.value = e.agent_name
            if (remoteTypingTimer.value) clearTimeout(remoteTypingTimer.value)
            if (e.is_typing)
                remoteTypingTimer.value = setTimeout(() => { remoteTyping.value = false }, 6000)
        })
}

function unsubscribeFromConversation(id: number) { echo.value?.leave(`conversation.${id}`) }

function teardownEcho() {
    if (activeChatId.value) unsubscribeFromConversation(activeChatId.value)
    echo.value?.leave(`tenant.${(window as any).__TENANT_ID__}.inbox`)
}

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadConversations() {
    loadingConvos.value = true
    try {
        const { data } = await axios.get('/api/inbox/conversations')
        conversations.value = data.data.map((c: any) => ({ ...c, messages: [] }))
    } finally {
        loadingConvos.value = false
    }
}

async function selectChat(id: number) {
    if (activeChatId.value === id) return
    if (activeChatId.value) unsubscribeFromConversation(activeChatId.value)

    activeChatId.value = id
    replyingTo.value = null
    remoteTyping.value = false

    // Clear messages for fresh load
    const conv = conversations.value.find(c => c.id === id)
    if (conv) conv.messages = []

    subscribeToConversation(id)
    await loadMessages(id, 1)
    await markConversationRead()

    // Mark read locally
    if (conv) conv.unread_count = 0
    scrollToBottom()
}

async function loadMessages(convId: number, page = 1) {
    loadingMessages.value = true
    try {
        const { data } = await axios.get(`/api/inbox/conversations/${convId}`, { params: { page } })
        const conv = conversations.value.find(c => c.id === convId)
        if (!conv) return
        conv.messages = page === 1 ? data.messages.data : [...data.messages.data, ...conv.messages]
        hasMoreMessages.value = !!data.messages.next_page_url
        currentPage.value = page
    } finally {
        loadingMessages.value = false
    }
}

async function loadMoreMessages() {
    if (!hasMoreMessages.value || loadingMessages.value || !activeChatId.value) return
    await loadMessages(activeChatId.value, currentPage.value + 1)
}

// ── Sending ───────────────────────────────────────────────────────────────────
async function sendMessage() {
    if (!newMessage.value.trim() || !selectedChat.value || sending.value) return

    const conv = selectedChat.value
    const text = newMessage.value.trim()
    const replyWamid = replyingTo.value?.whatsapp_message_id ?? null

    const optimistic: Message = {
        id: Date.now(), conversation_id: conv.id,
        whatsapp_message_id: null, direction: 'outbound', message_type: 'text',
        content: { text }, status: 'sent',
        sent_at: new Date().toISOString(), delivered_at: null, read_at: null,
        created_at: new Date().toISOString(), sender_type: 'agent',
        sender_name: currentUser.value.name, _pending: true,
    }
    conv.messages.push(optimistic)
    newMessage.value = ''
    replyingTo.value = null
    sending.value = true
    scrollToBottom()

    try {
        const { data } = await axios.post(
            `/api/inbox/conversations/${conv.id}/messages`,
            { text, reply_to_wamid: replyWamid }
        )
        const idx = conv.messages.findIndex(m => m.id === optimistic.id)
        if (idx !== -1) conv.messages.splice(idx, 1, { ...data.message, _pending: false })
    } catch {
        const idx = conv.messages.findIndex(m => m.id === optimistic.id)
        if (idx !== -1) conv.messages[idx].status = 'failed'
    } finally {
        sending.value = false
        stopTypingDebounce()
    }
}

// ── Typing ────────────────────────────────────────────────────────────────────
function onInput() {
    if (!activeChatId.value) return
    if (typingDebounce.value) clearTimeout(typingDebounce.value)
    sendTyping(true)
    typingDebounce.value = setTimeout(() => sendTyping(false), 3000)
}
async function sendTyping(isTyping: boolean) {
    if (!activeChatId.value) return
    try { await axios.post(`/api/inbox/conversations/${activeChatId.value}/typing`, { typing: isTyping }) }
    catch { /* non-fatal */ }
}
function stopTypingDebounce() {
    if (typingDebounce.value) { clearTimeout(typingDebounce.value); sendTyping(false) }
}

// ── Mark read ─────────────────────────────────────────────────────────────────
async function markConversationRead() {
    if (!activeChatId.value) return
    try { await axios.post(`/api/inbox/conversations/${activeChatId.value}/read`) }
    catch { /* non-fatal */ }
}

watch(() => searchValue.value, () => {
    clearTimeout((watch as any)._d)
        ; (watch as any)._d = setTimeout(loadConversations, 300)
})
</script>

<template>
    <VRow class="mt-0">

        <!-- ══════════════════════════════════════════════════
         CHAT SIDEBAR
    ══════════════════════════════════════════════════ -->
        <VCol v-if="!toggleSide && mdAndUp" class="d-flex align-stretch chatSidebar pe-md-0">
            <VCard variant="outlined" class="bg-surface br-0" rounded="lg">
                <VCardText class="py-5 px-0">

                    <h5 class="text-h5 px-5">
                        Messages
                        <VChip color="secondary" size="x-small" variant="flat">
                            {{conversations.filter(c => c.unread_count > 0).length}}
                        </VChip>
                    </h5>

                    <!-- Chat Listing -->
                    <div>
                        <div class="py-3 px-5 mt-2">
                            <VTextField v-model="searchValue" variant="outlined" persistent-placeholder
                                placeholder="Search Contact" hide-details>
                                <template #prepend-inner>
                                    <SvgSprite name="custom-search" class="text-lightText"
                                        style="width: 20px; height: 20px" />
                                </template>
                            </VTextField>
                        </div>

                        <VProgressLinear v-if="loadingConvos" indeterminate color="primary" class="mx-5" />

                        <PerfectScrollbar class="mb-3" style="height: 430px">
                            <VList aria-label="chat list" aria-busy="true" border class="px-5">
                                <VListItem v-for="chat in filteredChats" :key="chat.id" :value="chat.id"
                                    color="secondary" class="text-no-wrap chatItem" lines="two" rounded="md"
                                    :active="activeChatId === chat.id" @click="selectChat(chat.id)">
                                    <template #prepend>
                                        <VAvatar color="primary" variant="tonal" size="40">
                                            <span class="text-caption font-weight-bold">{{ getUserInitials(chat)
                                                }}</span>
                                        </VAvatar>
                                        <SvgSprite class="badg-dot"
                                            :name="chat.status === 'active' ? 'custom-check-circle-fill' : 'containerBg'"
                                            :class="chat.status === 'active' ? 'text-success' : 'text-containerBg'"
                                            style="width: 14px; height: 14px" />
                                    </template>

                                    <VListItemTitle class="text-h5 pr-2 mb-1">
                                        {{ chat.whatsapp_user_name || chat.whatsapp_user_phone }}
                                    </VListItemTitle>
                                    <VListItemSubtitle class="text-caption mt-n1 text-lightText" style="opacity: 1">
                                        {{ getLastMessagePreview(chat) }}
                                    </VListItemSubtitle>

                                    <template #append>
                                        <div class="d-flex flex-column text-right">
                                            <small class="text-lightText text-caption mb-1">{{
                                                formatTime(chat.last_message_at) }}</small>
                                            <VBadge v-if="chat.unread_count > 0" color="primary"
                                                :content="chat.unread_count" inline />
                                            <SvgSprite v-else name="custom-circle-check-outline"
                                                class="ml-auto text-lightText" style="width: 16px; height: 16px" />
                                        </div>
                                    </template>
                                </VListItem>
                            </VList>
                        </PerfectScrollbar>
                    </div>

                    <!-- User Profile -->
                    <div>
                        <VList rounded="md" density="comfortable" color="secondary" aria-label="profile list"
                            aria-busy="true" elevation="0" class="py-0 px-5">
                            <VListItem color="secondary" value="logout" rounded="md">
                                <template #prepend>
                                    <SvgSprite name="custom-logout-1" class="me-2 text-lightText"
                                        style="width: 24px; height: 24px" />
                                </template>
                                <VListItemTitle class="text-h6 text-lightText">LogOut</VListItemTitle>
                            </VListItem>
                            <VListItem color="secondary" value="setting" rounded="md">
                                <template #prepend>
                                    <SvgSprite name="custom-setup" class="me-2 text-lightText"
                                        style="width: 24px; height: 24px" />
                                </template>
                                <VListItemTitle class="text-h6 text-lightText">Settings</VListItemTitle>
                            </VListItem>
                        </VList>
                        <div class="d-flex align-center pa-5 px-10 pb-0">
                            <VAvatar class="me-2" color="primary" variant="tonal" size="40">
                                <img v-if="currentUser.avatar" :src="currentUser.avatar" alt="user" width="40">
                                <span v-else class="text-caption font-weight-bold">
                                    {{ currentUser.name.slice(0, 2).toUpperCase() }}
                                </span>
                            </VAvatar>
                            <SvgSprite class="badg-dotDetail"
                                :name="currentUser.status === 'active' ? 'custom-check-circle-fill' : 'custom-away-fill'"
                                :class="currentUser.status === 'active' ? 'text-success' : 'text-warning'"
                                style="width: 14px; height: 14px" />
                            <div>
                                <h5 class="text-h5 mb-0">{{ currentUser.name }}</h5>
                            </div>
                            <div class="ms-auto">
                                <VMenu location="top" rounded="md">
                                    <template #activator="{ props }">
                                        <VBtn icon v-bind="props" aria-label="dropdown" variant="text" size="x-small"
                                            rounded="md">
                                            <SvgSprite name="custom-chevron-outline" class="text-lightText"
                                                style="width: 16px; height: 16px" />
                                        </VBtn>
                                    </template>
                                    <VList width="150" rounded="md" density="compact" elevation="24" class="py-0">
                                        <VListItem v-for="(item, index) in profileItems" :key="index" :value="index">
                                            <template #prepend>
                                                <SvgSprite :name="item.icon || ''" :class="`me-1 text-${item.color}`"
                                                    style="width: 16px; height: 16px" />
                                            </template>
                                            <VListItemTitle class="text-h6" @click="currentUser.status = item.title">
                                                {{ item.title }}
                                            </VListItemTitle>
                                        </VListItem>
                                    </VList>
                                </VMenu>
                            </div>
                        </div>
                    </div>

                </VCardText>
            </VCard>
        </VCol>

        <!-- ══════════════════════════════════════════════════
         CHAT DETAIL
    ══════════════════════════════════════════════════ -->
        <VCol class="d-flex align-stretch ps-md-0">
            <VCard variant="outlined" class="bg-surface bl-0" rounded="lg">

                <div v-if="selectedChat" class="customHeight">

                    <!-- Chat Detail Header -->
                    <div class="d-sm-flex align-center ga-4 pa-4">
                        <VBtn icon aria-label="menu" variant="text" rounded="md" class="d-none d-md-flex"
                            @click="toggleSide = !toggleSide">
                            <SvgSprite name="custom-menu-outline" class="text-lightText"
                                style="width: 20px; height: 20px" />
                        </VBtn>

                        <div class="d-flex align-center">
                            <VBtn icon variant="text" class="d-md-none d-sm-flex" @click="sDrawer = !sDrawer">
                                <SvgSprite name="custom-menu-outline" class="text-lightText"
                                    style="width: 20px; height: 20px" />
                            </VBtn>

                            <div class="d-flex align-center">
                                <VAvatar color="primary" variant="tonal" size="40">
                                    <span class="text-caption font-weight-bold">{{ getUserInitials(selectedChat)
                                        }}</span>
                                </VAvatar>
                                <SvgSprite class="badg-Detail"
                                    :name="selectedChat.status === 'active' ? 'custom-check-circle-fill' : 'containerBg'"
                                    :class="selectedChat.status === 'active' ? 'text-success' : 'text-containerBg'"
                                    style="width: 14px; height: 14px" />
                                <div>
                                    <h5 class="text-subtitle-1 mb-0">
                                        {{ selectedChat.whatsapp_user_name || selectedChat.whatsapp_user_phone }}
                                    </h5>
                                    <small v-if="remoteTyping" class="text-primary" style="font-style: italic">
                                        {{ remoteTypingName }} is typing…
                                    </small>
                                    <small v-else class="text-lightText">
                                        {{ selectedChat.whatsapp_user_phone }}
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="ms-auto ga-2 d-flex">
                            <VBtn icon variant="text" aria-label="phone" rounded="md">
                                <SvgSprite name="custom-phone-outline" class="text-lightText"
                                    style="width: 20px; height: 20px" />
                            </VBtn>
                            <VBtn icon variant="text" aria-label="camera" rounded="md">
                                <SvgSprite name="custom-camera-outline" class="text-lightText"
                                    style="width: 20px; height: 20px" />
                            </VBtn>
                            <VBtn icon variant="text" aria-label="info" rounded="md"
                                @click.stop="infodrawer = !infodrawer">
                                <SvgSprite name="custom-info-circle-outline" class="text-lightText"
                                    style="width: 20px; height: 20px" />
                            </VBtn>
                            <VMenu rounded="md">
                                <template #activator="{ props }">
                                    <VBtn icon variant="text" aria-label="menu" rounded="md" v-bind="props">
                                        <SvgSprite name="custom-more-outline" class="text-lightText"
                                            style="width: 20px; height: 20px" />
                                    </VBtn>
                                </template>
                                <VList rounded="md" elevation="24" aria-label="menu" aria-busy="true" width="110"
                                    density="compact" class="py-0">
                                    <VListItem v-for="(item, index) in menuItems" :key="index" :value="index">
                                        <template #prepend>
                                            <SvgSprite :name="item.icon || ''" class="me-2"
                                                style="width: 16px; height: 16px" />
                                        </template>
                                        <VListItemTitle class="text-h6">{{ item.title }}</VListItemTitle>
                                    </VListItem>
                                </VList>
                            </VMenu>
                        </div>
                    </div>

                    <VDivider />

                    <!-- Chat History -->
                    <PerfectScrollbar style="min-height: calc(100vh - 495px); height: 430px"
                        :options="{ suppressScrollX: true }">
                        <!-- Load more -->
                        <div v-if="hasMoreMessages" class="text-center pa-2">
                            <VBtn variant="text" size="small" :loading="loadingMessages" @click="loadMoreMessages">
                                Load earlier messages
                            </VBtn>
                        </div>

                        <template v-for="(message, index) in selectedChat.messages" :key="message.id">

                            <!-- Date separator -->
                            <div v-if="index === 0 || !isSameDay(message.created_at, selectedChat.messages[index - 1].created_at)"
                                class="text-center pa-3">
                                <VChip size="x-small" variant="tonal" class="text-caption text-lightText">
                                    {{ formatDateLabel(message.created_at) }}
                                </VChip>
                            </div>

                            <!-- INBOUND message -->
                            <div v-if="message.direction === 'inbound'" class="pa-5 bg-containerBg">
                                <div class="d-flex position-relative mb-4">
                                    <VAvatar size="40" variant="flat" class="me-2">
                                        <span class="text-caption font-weight-bold"
                                            style="background: rgb(var(--v-theme-primary)); color: white; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: 50%">{{
                                            getUserInitials(selectedChat) }}</span>
                                    </VAvatar>
                                    <SvgSprite class="detail-badg-dot"
                                        :name="selectedChat.status === 'active' ? 'custom-check-circle-fill' : 'containerBg'"
                                        :class="selectedChat.status === 'active' ? 'text-success' : 'text-containerBg'"
                                        style="width: 14px; height: 14px" />
                                    <div class="mb-3" style="max-width: 60%">
                                        <!-- Interactive inbound (user tapped option) -->
                                        <VSheet v-if="message.message_type === 'interactive'"
                                            class="bg-surface rounded-md pa-3 mb-1">
                                            <div class="d-flex align-center ga-1">
                                                <SvgSprite name="custom-check-circle-fill"
                                                    class="text-success flex-shrink-0"
                                                    style="width: 14px; height: 14px" />
                                                <p class="text-body-1 mb-0">
                                                    {{ message.content.response?.title ?? message.content.response?.id
                                                    ?? '[Selected option]' }}
                                                </p>
                                            </div>
                                        </VSheet>

                                        <!-- Image -->
                                        <VSheet v-else-if="message.message_type === 'image'"
                                            class="bg-surface rounded-md pa-2 mb-1">
                                            <img v-if="message.content.link" :src="message.content.link"
                                                class="media-image rounded" />
                                            <p v-if="message.content.caption"
                                                class="text-caption text-lightText mt-1 mb-0">{{ message.content.caption
                                                }}</p>
                                        </VSheet>

                                        <!-- Document -->
                                        <VSheet v-else-if="message.message_type === 'document'"
                                            class="bg-surface rounded-md pa-3 mb-1">
                                            <div class="d-flex align-center ga-2">
                                                <VAvatar color="primary" variant="tonal" rounded="md" size="36">
                                                    <SvgSprite name="custom-file-outline-2" class="text-primary"
                                                        style="width: 18px; height: 18px" />
                                                </VAvatar>
                                                <div>
                                                    <p class="text-body-2 font-weight-medium mb-0">{{
                                                        message.content.filename ?? 'Document' }}</p>
                                                    <a v-if="message.content.link" :href="message.content.link"
                                                        target="_blank" class="text-caption text-primary">Download</a>
                                                </div>
                                            </div>
                                        </VSheet>

                                        <!-- Text / fallback -->
                                        <VSheet v-else class="bg-surface rounded-md pa-3 mb-1">
                                            <p class="text-body-1 mb-0">{{ getMessageText(message) }}</p>
                                        </VSheet>

                                        <small class="text-subtitle-2 text-lightText">{{
                                            formatMessageTime(message.sent_at || message.created_at) }}</small>
                                    </div>
                                </div>
                            </div>

                            <!-- OUTBOUND message -->
                            <div v-else class="pa-5 bg-containerBg ml-auto text-end">
                                <div class="d-flex flex-end userReply position-relative mb-4">
                                    <VAvatar size="40" variant="flat" class="ms-2">
                                        <span class="text-caption font-weight-bold"
                                            style="background: rgb(var(--v-theme-secondary)); color: white; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: 50%">{{
                                                message.sender_type === 'agent' ? 'AG' : 'BOT' }}</span>
                                    </VAvatar>
                                    <SvgSprite name="custom-check-circle-fill" class="detail-badg-dot"
                                        :class="message.status === 'read' ? 'text-primary' : 'text-success'"
                                        style="width: 14px; height: 14px" />
                                    <div class="mb-3" style="max-width: 60%">

                                        <!-- Sender label -->
                                        <p v-if="!isGroupedWithPrev(selectedChat.messages, index)"
                                            class="text-caption text-lightText mb-1"
                                            style="font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.4px">
                                            {{ message.sender_type === 'agent' ? `👤 ${message.sender_name ?? 'Agent'}`
                                            : '🤖 Bot' }}
                                        </p>

                                        <!-- ── Interactive outbound (bot buttons / list) ── -->
                                        <template v-if="message.message_type === 'interactive'">
                                            <VSheet class="bg-primary rounded-md mb-1 d-inline-block text-left"
                                                :class="{ 'msg-pending': message._pending }"
                                                style="overflow: hidden; min-width: 200px">
                                                <div class="pa-3">
                                                    <p v-if="message.content.header?.text"
                                                        class="text-body-2 font-weight-semibold mb-1">
                                                        {{ message.content.header.text }}
                                                    </p>
                                                    <p class="text-body-1 mb-0">
                                                        {{ message.content.body?.text ?? message.content.response?.title
                                                        ?? '' }}
                                                    </p>
                                                    <p v-if="message.content.footer?.text"
                                                        class="text-caption mt-1 mb-0" style="opacity: 0.65">
                                                        {{ message.content.footer.text }}
                                                    </p>
                                                    <!-- Time + ticks inside bubble -->
                                                    <div class="d-flex align-center justify-end ga-1 mt-1">
                                                        <small style="font-size: 0.62rem; opacity: 0.7">
                                                            {{ formatMessageTime(message.sent_at || message.created_at)
                                                            }}
                                                        </small>
                                                        <SvgSprite v-if="message._pending" name="custom-clock-outline"
                                                            class="text-lightText" style="width: 11px; height: 11px" />
                                                        <SvgSprite v-else-if="message.status === 'sent'"
                                                            name="custom-check-outline" class="tick-grey"
                                                            style="width: 12px; height: 12px" />
                                                        <SvgSprite v-else-if="message.status === 'delivered'"
                                                            name="custom-checks-outline" class="tick-grey"
                                                            style="width: 12px; height: 12px" />
                                                        <SvgSprite v-else-if="message.status === 'read'"
                                                            name="custom-checks-outline" class="tick-blue"
                                                            style="width: 12px; height: 12px" />
                                                        <SvgSprite v-else-if="message.status === 'failed'"
                                                            name="custom-alert-circle-outline" class="text-error"
                                                            style="width: 12px; height: 12px" />
                                                    </div>
                                                </div>
                                                <VDivider />
                                                <!-- Quick-reply buttons -->
                                                <template
                                                    v-if="message.content.type === 'button' && message.content.action?.buttons">
                                                    <button v-for="btn in message.content.action.buttons"
                                                        :key="btn.reply?.id" class="wa-btn-preview">{{ btn.reply?.title
                                                        }}</button>
                                                </template>
                                                <!-- List trigger -->
                                                <template v-else-if="message.content.type === 'list'">
                                                    <button class="wa-list-trigger"
                                                        @click.stop="openListSheet(message)">
                                                        <SvgSprite name="custom-menu-outline"
                                                            style="width: 14px; height: 14px" />
                                                        {{ message.content.action?.button ?? 'View Options' }}
                                                    </button>
                                                </template>
                                            </VSheet>
                                        </template>

                                        <!-- Regular outbound -->
                                        <template v-else>
                                            <VSheet class="bg-primary rounded-md pa-3 mb-1 d-inline-block"
                                                :class="{ 'msg-pending': message._pending }">
                                                <!-- Image -->
                                                <template v-if="message.message_type === 'image'">
                                                    <img v-if="message.content.link" :src="message.content.link"
                                                        class="media-image rounded" />
                                                    <p v-if="message.content.caption" class="text-caption mt-1 mb-0"
                                                        style="opacity: 0.8">{{ message.content.caption }}</p>
                                                </template>
                                                <!-- Document -->
                                                <template v-else-if="message.message_type === 'document'">
                                                    <div class="d-flex align-center ga-2">
                                                        <VAvatar color="white" variant="tonal" rounded="md" size="36">
                                                            <SvgSprite name="custom-file-outline-2"
                                                                style="width: 18px; height: 18px" />
                                                        </VAvatar>
                                                        <div>
                                                            <p class="text-body-2 font-weight-medium mb-0">{{
                                                                message.content.filename ?? 'Document' }}</p>
                                                            <a v-if="message.content.link" :href="message.content.link"
                                                                target="_blank" class="text-caption"
                                                                style="opacity: 0.8">Download</a>
                                                        </div>
                                                    </div>
                                                </template>
                                                <!-- Text / fallback -->
                                                <template v-else>
                                                    <p class="text-body-1 mb-0">{{ getMessageText(message) }}</p>
                                                </template>
                                            </VSheet>
                                        </template>

                                        <!-- Time + ticks (below bubble, for non-interactive) -->
                                        <div v-if="message.message_type !== 'interactive'"
                                            class="d-flex align-center justify-end ga-1">
                                            <small class="text-subtitle-2 text-lightText d-block">
                                                {{ formatMessageTime(message.sent_at || message.created_at) }}
                                            </small>
                                            <SvgSprite v-if="message._pending" name="custom-clock-outline"
                                                class="text-lightText" style="width: 12px; height: 12px" />
                                            <SvgSprite v-else-if="message.status === 'sent'" name="custom-check-outline"
                                                class="tick-grey" style="width: 14px; height: 14px" />
                                            <SvgSprite v-else-if="message.status === 'delivered'"
                                                name="custom-checks-outline" class="tick-grey"
                                                style="width: 14px; height: 14px" />
                                            <SvgSprite v-else-if="message.status === 'read'"
                                                name="custom-checks-outline" class="tick-blue"
                                                style="width: 14px; height: 14px" />
                                            <SvgSprite v-else-if="message.status === 'failed'"
                                                name="custom-alert-circle-outline" class="text-error"
                                                style="width: 14px; height: 14px" />
                                        </div>
                                    </div>

                                    <!-- Context menu (matches inspo exactly) -->
                                    <div style="min-width: 80px">
                                        <VMenu rounded="md">
                                            <template #activator="{ props }">
                                                <VBtn icon variant="text" aria-label="menu" size="small" rounded="md"
                                                    v-bind="props">
                                                    <SvgSprite name="custom-more-outline" class="text-lightText"
                                                        style="width: 16px; height: 16px" />
                                                </VBtn>
                                            </template>
                                            <VList elevation="24" width="120" aria-label="menu" aria-busy="true"
                                                rounded="md" density="compact" class="py-0">
                                                <VListItem v-for="(item, index3) in replyItems" :key="index3"
                                                    :value="index3">
                                                    <template #prepend>
                                                        <SvgSprite :name="item.icon || ''" class="me-2"
                                                            style="width: 16px; height: 16px" />
                                                    </template>
                                                    <VListItemTitle class="text-h6">{{ item.title }}</VListItemTitle>
                                                </VListItem>
                                            </VList>
                                        </VMenu>
                                        <VBtn size="small" variant="text" aria-label="edit" class="me-2" rounded="md"
                                            icon>
                                            <SvgSprite name="custom-edit-outline" class="text-lightText"
                                                style="width: 16px; height: 16px" />
                                        </VBtn>
                                    </div>
                                </div>
                            </div>

                        </template>

                        <!-- Typing dots -->
                        <div v-if="remoteTyping" class="pa-5 bg-containerBg">
                            <div class="d-flex position-relative mb-4">
                                <VAvatar size="40" variant="flat" class="me-2">
                                    <span
                                        style="background: rgb(var(--v-theme-primary)); color: white; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 0.75rem; font-weight: 700">
                                        {{ getUserInitials(selectedChat) }}
                                    </span>
                                </VAvatar>
                                <VSheet class="bg-surface rounded-md pa-3 mb-1 d-flex align-center ga-1">
                                    <span class="typing-dot" /><span class="typing-dot" /><span class="typing-dot" />
                                </VSheet>
                            </div>
                        </div>

                        <!-- Scroll anchor -->
                        <div ref="messagesEnd" />
                    </PerfectScrollbar>

                    <!-- Reply bar -->
                    <Transition name="slide-up">
                        <div v-if="replyingTo" class="d-flex align-center ga-2 px-4 py-2 reply-bar">
                            <div class="reply-bar-inner flex-1 d-flex align-center ga-2 pa-2 rounded">
                                <SvgSprite name="custom-reply-outline" class="text-primary flex-shrink-0"
                                    style="width: 16px; height: 16px" />
                                <div class="flex-1 text-truncate">
                                    <p class="text-caption font-weight-medium text-primary mb-0">Replying to</p>
                                    <p class="text-caption text-lightText text-truncate mb-0">{{
                                        getMessageText(replyingTo) }}</p>
                                </div>
                            </div>
                            <VBtn icon size="x-small" variant="text" rounded="md" @click="replyingTo = null">
                                <SvgSprite name="custom-close"
                                    style="width: 14px; height: 14px; transform: rotate(45deg)" />
                            </VBtn>
                        </div>
                    </Transition>

                    <!-- Chat Send — exact inspo structure -->
                    <VDivider />
                    <form class="pa-4" @submit.prevent="sendMessage">
                        <VTextarea v-model="newMessage" placeholder="Your message..." variant="underlined" rows="1"
                            auto-grow max-rows="4" hide-details @input="onInput"
                            @keydown.enter.exact.prevent="sendMessage" />
                        <div class="d-flex align-center">
                            <VBtn icon rounded="md" aria-label="smile" variant="text" size="small">
                                <SvgSprite name="custom-smile-outline" class="text-secondary"
                                    style="width: 20px; height: 20px; opacity: 0.5" />
                            </VBtn>
                            <VBtn icon rounded="md" aria-label="clip" variant="text" size="small">
                                <SvgSprite name="custom-paper-clip-outline" class="text-secondary"
                                    style="width: 20px; height: 20px; opacity: 0.5" />
                            </VBtn>
                            <VBtn icon rounded="md" aria-label="picture" variant="text" size="small">
                                <SvgSprite name="custom-picture-outline" class="text-secondary"
                                    style="width: 20px; height: 20px; opacity: 0.5" />
                            </VBtn>
                            <VBtn icon rounded="md" aria-label="sound" variant="text" size="small">
                                <SvgSprite name="custom-sound-outline" class="text-secondary"
                                    style="width: 20px; height: 20px; opacity: 0.5" />
                            </VBtn>
                            <VBtn icon rounded="md" aria-label="send" variant="text" class="ms-auto" color="primary"
                                type="submit" :loading="sending">
                                <SvgSprite name="custom-send-outline" style="width: 20px; height: 20px" />
                            </VBtn>
                        </div>
                    </form>

                </div>
            </VCard>
        </VCol>
    </VRow>

    <!-- ══════════════════════════════════════════════════
       Info Sidebar Drawer — exact inspo structure
  ══════════════════════════════════════════════════ -->
    <VNavigationDrawar v-if="selectedChat" v-model="infodrawer" temporary location="end" width="300">
        <div class="customHeight pa-4">
            <div class="text-end">
                <VBtn color="error" aria-label="close" variant="text" icon rounded="md" size="small"
                    @click="infodrawer = false">
                    <SvgSprite name="custom-close" style="width: 16px; height: 16px; transform: rotate(45deg)" />
                </VBtn>
            </div>
            <div class="py-4">
                <div class="text-center">
                    <VAvatar size="88" variant="outlined" color="primary">
                        <span class="text-h5 font-weight-bold">{{ getUserInitials(selectedChat) }}</span>
                    </VAvatar>
                    <h4 class="text-h5 mt-3 mb-0">
                        {{ selectedChat.whatsapp_user_name || selectedChat.whatsapp_user_phone }}
                    </h4>
                    <p class="text-caption text-lightText">{{ selectedChat.whatsapp_user_phone }}</p>
                    <div class="d-flex ga-2 align-center justify-center mt-2">
                        <SvgSprite :name="selectedChat.status === 'active' ? 'custom-check-circle-fill' : 'containerBg'"
                            :class="selectedChat.status === 'active' ? 'text-success' : 'text-containerBg'"
                            style="width: 14px; height: 14px" />
                        <VChip :color="getStatusColor(selectedChat.status)" size="small">
                            {{ selectedChat.status }}
                        </VChip>
                    </div>
                </div>
                <div class="d-flex align-center justify-center ga-4 mt-6">
                    <VBtn elevation="24" aria-label="mobile" icon rounded="md" size="small">
                        <SvgSprite name="custom-mobile-outline-2" class="text-lightText ml-1"
                            style="width: 20px; height: 20px" />
                    </VBtn>
                    <VBtn elevation="24" aria-label="mail" icon rounded="md" size="small">
                        <SvgSprite name="custom-mail-outline" class="text-lightText"
                            style="width: 20px; height: 20px" />
                    </VBtn>
                    <VBtn elevation="24" aria-label="camera" icon rounded="md" size="small">
                        <SvgSprite name="custom-camera-outline" class="text-lightText"
                            style="width: 20px; height: 20px" />
                    </VBtn>
                </div>
                <div class="d-flex ga-4 mt-6">
                    <div class="bg-lightprimary w-100 pa-4 rounded-lg">
                        <h6 class="text-h6 text-primary mb-0">All File</h6>
                        <div class="d-flex align-center">
                            <SvgSprite name="custom-folder-open-outline" class="text-primary" />
                            <h4 class="text-h4 mb-0 ms-2">231</h4>
                        </div>
                    </div>
                    <div class="bg-gray100 w-100 pa-4 rounded-lg">
                        <h6 class="text-h6 mb-0">All Link</h6>
                        <div class="d-flex align-center">
                            <SvgSprite name="custom-link3" />
                            <h4 class="text-h4 mb-0 ms-2">231</h4>
                        </div>
                    </div>
                </div>
            </div>

            <VExpansionPanels v-model="panel1" class="accordionWithoutBorder mt-2">
                <VExpansionPanel elevation="0">
                    <VExpansionPanelTitle class="text-h5 pa-0 pb-3" color="surface">Information</VExpansionPanelTitle>
                    <VExpansionPanelText>
                        <VList density="compact" class="pa-0" aria-label="information list" aria-busy="true" nav>
                            <VListItem class="pa-0">
                                <div class="d-flex">
                                    <p class="mb-0 text-h6">Phone</p>
                                    <p class="mb-0 text-h6 text-lightText ms-auto">{{ selectedChat.whatsapp_user_phone
                                        }}
                                    </p>
                                </div>
                            </VListItem>
                            <VListItem class="pa-0">
                                <div class="d-flex">
                                    <p class="mb-0 text-h6">Account</p>
                                    <p class="mb-0 text-h6 text-lightText ms-auto">
                                        {{ selectedChat.whatsapp_account?.verified_name ?? '—' }}
                                    </p>
                                </div>
                            </VListItem>
                            <VListItem class="pa-0">
                                <div class="d-flex">
                                    <p class="mb-0 text-h6">Last message</p>
                                    <p class="mb-0 text-h6 text-lightText ms-auto">{{
                                        formatTime(selectedChat.last_message_at) }}</p>
                                </div>
                            </VListItem>
                        </VList>
                    </VExpansionPanelText>
                </VExpansionPanel>
            </VExpansionPanels>

            <div class="d-flex justify-space-between align-center mt-4 mb-1">
                <h5 class="text-h5 mb-0">Notification</h5>
                <VSwitch v-model="notification" color="primary" aria-label="switch" class="switchRight" hide-details />
            </div>
            <VDivider />
            <div class="d-flex justify-space-between align-center py-2">
                <h5 class="text-h5 mb-0">File type</h5>
                <VBtn icon rounded="md" aria-label="menu" variant="text" size="small">
                    <SvgSprite name="custom-more-outline" class="text-lightText" style="width: 20px; height: 20px" />
                </VBtn>
            </div>
            <VDivider />

            <VList density="compact" lines="two" aria-label="files list" aria-busy="true">
                <VListItem v-for="(file, i) in attach" :key="i" rounded="sm" color="secondary" class="pa-0">
                    <template #prepend>
                        <div class="me-3">
                            <VAvatar size="40" :color="file.color" variant="tonal" rounded="md">
                                <SvgSprite :name="file.icon || ''" :class="`text-${file.color}`"
                                    style="width: 20px; height: 20px" />
                            </VAvatar>
                        </div>
                    </template>
                    <template #append>
                        <VBtn icon size="x-small" aria-label="arrow" variant="text" rounded="md">
                            <SvgSprite name="custom-chevron-outline" class="text-lightText"
                                style="width: 16px; height: 16px" />
                        </VBtn>
                    </template>
                    <div class="w-100">
                        <h6 class="text-h6 mb-0">{{ file.name }}</h6>
                        <span class="text-h6 text-lightText">{{ file.size }}</span>
                    </div>
                </VListItem>
            </VList>
        </div>
    </VNavigationDrawar>

    <!-- ══════════════════════════════════════════════════
       Mobile Sidebar Drawer
  ══════════════════════════════════════════════════ -->
    <VNavigationDrawer v-if="!mdAndUp" v-model="sDrawer" temporary width="300" top>
        <PerfectScrollbar style="height: calc(100vh - 60px)">
            <VCardText class="pa-5">
                <h5 class="text-h5">
                    Messages
                    <VChip color="secondary" size="x-small" variant="flat">
                        {{conversations.filter(c => c.unread_count > 0).length}}
                    </VChip>
                </h5>
                <div>
                    <div class="py-3 px-5 mt-2">
                        <VTextField v-model="searchValue" variant="outlined" persistent-placeholder
                            placeholder="Search Contact" hide-details>
                            <template #prepend-inner>
                                <SvgSprite name="custom-search" class="text-lightText"
                                    style="width: 20px; height: 20px" />
                            </template>
                        </VTextField>
                    </div>
                    <VList aria-label="chat list" aria-busy="true" border class="px-5">
                        <VListItem v-for="chat in filteredChats" :key="chat.id" :value="chat.id" color="secondary"
                            class="text-no-wrap chatItem" lines="two" rounded="md" :active="activeChatId === chat.id"
                            @click="selectChat(chat.id); sDrawer = false">
                            <template #prepend>
                                <VAvatar color="primary" variant="tonal" size="40">
                                    <span class="text-caption font-weight-bold">{{ getUserInitials(chat) }}</span>
                                </VAvatar>
                                <SvgSprite class="badg-dot"
                                    :name="chat.status === 'active' ? 'custom-check-circle-fill' : 'containerBg'"
                                    :class="chat.status === 'active' ? 'text-success' : 'text-containerBg'"
                                    style="width: 14px; height: 14px" />
                            </template>
                            <VListItemTitle class="text-h5 pr-2 mb-1">
                                {{ chat.whatsapp_user_name || chat.whatsapp_user_phone }}
                            </VListItemTitle>
                            <VListItemSubtitle class="text-caption mt-n1 text-lightText" style="opacity: 1">
                                {{ getLastMessagePreview(chat) }}
                            </VListItemSubtitle>
                            <template #append>
                                <div class="d-flex flex-column text-right">
                                    <small class="text-lightText text-caption mb-1">{{ formatTime(chat.last_message_at)
                                        }}</small>
                                    <VBadge v-if="chat.unread_count > 0" color="primary" :content="chat.unread_count"
                                        inline />
                                    <SvgSprite v-else name="custom-circle-check-outline" class="ml-auto text-lightText"
                                        style="width: 16px; height: 16px" />
                                </div>
                            </template>
                        </VListItem>
                    </VList>
                </div>
                <div>
                    <VList rounded="md" density="comfortable" color="secondary" aria-label="profile list"
                        aria-busy="true" elevation="0" class="py-0 px-5">
                        <VListItem color="secondary" value="logout" rounded="md">
                            <template #prepend>
                                <SvgSprite name="custom-logout-1" class="me-2 text-lightText"
                                    style="width: 24px; height: 24px" />
                            </template>
                            <VListItemTitle class="text-h6 text-lightText">LogOut</VListItemTitle>
                        </VListItem>
                        <VListItem color="secondary" value="setting" rounded="md">
                            <template #prepend>
                                <SvgSprite name="custom-setup" class="me-2 text-lightText"
                                    style="width: 24px; height: 24px" />
                            </template>
                            <VListItemTitle class="text-h6 text-lightText">Settings</VListItemTitle>
                        </VListItem>
                    </VList>
                    <div class="d-flex align-center pa-5 px-10 pb-0">
                        <VAvatar class="me-2" color="primary" variant="tonal" size="40">
                            <img v-if="currentUser.avatar" :src="currentUser.avatar" alt="user" width="40">
                            <span v-else class="text-caption font-weight-bold">{{
                                currentUser.name.slice(0,2).toUpperCase()
                                }}</span>
                        </VAvatar>
                        <SvgSprite class="badg-dotDetail"
                            :name="currentUser.status === 'active' ? 'custom-check-circle-fill' : 'custom-away-fill'"
                            :class="currentUser.status === 'active' ? 'text-success' : 'text-warning'"
                            style="width: 14px; height: 14px" />
                        <div>
                            <h5 class="text-h5 mb-0">{{ currentUser.name }}</h5>
                        </div>
                        <div class="ms-auto">
                            <VMenu location="top" rounded="md">
                                <template #activator="{ props }">
                                    <VBtn icon v-bind="props" aria-label="dropdown" variant="text" size="x-small"
                                        rounded="md">
                                        <SvgSprite name="custom-chevron-outline" class="text-lightText"
                                            style="width: 16px; height: 16px" />
                                    </VBtn>
                                </template>
                                <VList width="150" rounded="md" density="compact" elevation="24" class="py-0">
                                    <VListItem v-for="(item, index) in profileItems" :key="index" :value="index">
                                        <template #prepend>
                                            <SvgSprite :name="item.icon || ''" :class="`me-1 text-${item.color}`"
                                                style="width: 16px; height: 16px" />
                                        </template>
                                        <VListItemTitle class="text-h6" @click="currentUser.status = item.title">
                                            {{ item.title }}
                                        </VListItemTitle>
                                    </VListItem>
                                </VList>
                            </VMenu>
                        </div>
                    </div>
                </div>
            </VCardText>
        </PerfectScrollbar>
    </VNavigationDrawer>

    <!-- ══════════════════════════════════════════════════
       WhatsApp bottom sheet (list messages)
  ══════════════════════════════════════════════════ -->
    <Teleport to="body">
        <Transition name="sheet">
            <div v-if="sheetOpen" class="wa-sheet-overlay" @click.self="closeSheet">
                <div class="wa-sheet">
                    <div class="wa-sheet-handle mx-auto mt-2 mb-1" />
                    <div class="d-flex align-center ga-3 px-4 py-3">
                        <VBtn icon size="small" variant="text" rounded="md" @click="closeSheet">
                            <SvgSprite name="custom-close"
                                style="width: 18px; height: 18px; transform: rotate(45deg)" />
                        </VBtn>
                        <span class="text-subtitle-1 font-weight-semibold flex-1 text-center"
                            style="padding-right: 44px">
                            {{ sheetTitle }}
                        </span>
                    </div>
                    <VDivider />
                    <div class="wa-sheet-body">
                        <div v-for="(section, si) in sheetSections" :key="si">
                            <p v-if="section.title"
                                class="text-caption font-weight-bold text-primary text-uppercase px-4 pt-3 pb-1 mb-0">
                                {{ section.title }}
                            </p>
                            <VList class="py-0">
                                <VListItem v-for="row in section.rows" :key="row.id" :title="row.title"
                                    :subtitle="row.description" lines="two" rounded="0" class="px-4">
                                    <template #append>
                                        <div class="wa-radio-circle" />
                                    </template>
                                </VListItem>
                            </VList>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style lang="scss">
/* ── Exact copy of inspo CSS ───────────────────────────────────────────────── */
.br-0 {
    @media (min-width: 960px) {
        border-right: none;
        border-top-right-radius: 0 !important;
        border-bottom-right-radius: 0 !important;

        [dir="rtl"] & {
            border-left: none;
            border-right: 1px solid rgb(var(--v-theme-borderLight));
            border-top-right-radius: 8px !important;
            border-bottom-right-radius: 8px !important;
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
        }
    }
}

.bl-0 {
    @media (min-width: 960px) {
        border-top-left-radius: 0 !important;
        border-bottom-left-radius: 0 !important;

        [dir="rtl"] & {
            border-top-left-radius: 8px !important;
            border-bottom-left-radius: 8px !important;
            border-top-right-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
        }
    }
}

.custom-main {
    margin: 0;
}

.chatSidebar {
    max-width: 319px;

    .v-list-item__prepend>.v-avatar~.v-list-item__spacer {
        width: 0;
    }
}

.customHeight {
    min-height: calc(100vh - 300px);
}

.badg-dot {
    position: relative;
    top: -14px;
    left: -11px;
    background-color: rgb(var(--v-theme-surface));
    border-radius: 100%;

    [dir="rtl"] & {
        left: unset;
        right: -11px;
    }
}

.badg-dotDetail {
    left: -16px;
    position: relative;
    top: -16px;

    [dir="rtl"] & {
        left: unset;
        right: -16px;
    }
}

.detail-badg-dot {
    position: absolute;
    top: 0;
    left: 28px;
    background-color: rgb(var(--v-theme-surface));
    border-radius: 100%;

    [dir="rtl"] & {
        left: unset;
        right: 28px;
    }
}

.userReply {
    flex-flow: row-reverse;

    .detail-badg-dot {
        right: 0;
        left: unset;

        [dir="rtl"] & {
            left: 0;
            right: unset;
        }
    }
}

.badg-Detail {
    left: -12px;
    position: relative;
    top: -15px;
    background-color: rgb(var(--v-theme-surface));
    border-radius: 100%;

    [dir="rtl"] & {
        left: unset;
        right: -12px;
    }
}

.chatItem {
    padding: 16px !important;
}

.accordionWithoutBorder {
    .v-expansion-panel {
        border: none;

        .v-expansion-panel-title {
            border-bottom: 1px solid rgb(var(--v-theme-borderLight));
            min-height: unset;

            &:hover>.v-expansion-panel-title__overlay {
                opacity: 0;
            }
        }

        &.v-expansion-panel--active .v-expansion-panel-title--active .v-expansion-panel-title__overlay {
            background: transparent;
        }

        .v-expansion-panel-text__wrapper {
            border-top: none;
            padding: 0;
            padding-top: 15px;
        }
    }
}

/* ── WhatsApp-specific additions (minimal) ────────────────────────────────── */
.msg-pending {
    opacity: 0.6 !important;
}

.tick-grey {
    color: rgba(var(--v-theme-on-surface), 0.38);
}

.tick-blue {
    color: #4fc3f7 !important;
}

.media-image {
    max-width: 100%;
    max-height: 200px;
    display: block;
}

/* Typing dots */
.typing-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: rgba(var(--v-theme-on-surface), 0.3);
    animation: wa-bounce 1.2s infinite;

    &:nth-child(2) {
        animation-delay: 0.2s;
    }

    &:nth-child(3) {
        animation-delay: 0.4s;
    }
}

@keyframes wa-bounce {

    0%,
    60%,
    100% {
        transform: translateY(0);
    }

    30% {
        transform: translateY(-5px);
    }
}

/* Reply bar */
.reply-bar {
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: rgb(var(--v-theme-surface));
}

.reply-bar-inner {
    background: rgba(var(--v-theme-primary), 0.05);
    border-left: 3px solid rgb(var(--v-theme-primary));
}

.slide-up-enter-active,
.slide-up-leave-active {
    transition: all 0.15s ease;
}

.slide-up-enter-from,
.slide-up-leave-to {
    transform: translateY(100%);
    opacity: 0;
}

/* Interactive outbound buttons */
.wa-btn-preview {
    display: block;
    width: 100%;
    padding: 10px 12px;
    background: transparent;
    border: none;
    border-top: 1px solid rgba(255, 255, 255, 0.15);
    font-size: 0.875rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
    text-align: center;
    cursor: default;

    &:last-child {
        border-radius: 0 0 8px 8px;
    }
}

.wa-list-trigger {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    padding: 10px 12px;
    background: transparent;
    border: none;
    border-top: 1px solid rgba(255, 255, 255, 0.15);
    font-size: 0.875rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
    cursor: pointer;
    border-radius: 0 0 8px 8px;
    transition: background 0.15s;

    &:hover {
        background: rgba(255, 255, 255, 0.08);
    }
}

/* Bottom sheet */
.wa-sheet-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9999;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.wa-sheet {
    width: 100%;
    max-width: 480px;
    background: rgb(var(--v-theme-surface));
    border-radius: 16px 16px 0 0;
    max-height: 72vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.wa-sheet-handle {
    width: 36px;
    height: 4px;
    background: rgba(var(--v-theme-on-surface), 0.15);
    border-radius: 2px;
}

.wa-sheet-body {
    overflow-y: auto;
    flex: 1;
    padding-bottom: 20px;
}

.wa-radio-circle {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid rgba(var(--v-theme-on-surface), 0.25);
    flex-shrink: 0;
}

.sheet-enter-active,
.sheet-leave-active {
    transition: opacity 0.22s ease;
}

.sheet-enter-active .wa-sheet,
.sheet-leave-active .wa-sheet {
    transition: transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.sheet-enter-from,
.sheet-leave-to {
    opacity: 0;
}

.sheet-enter-from .wa-sheet,
.sheet-leave-to .wa-sheet {
    transform: translateY(100%);
}
</style>