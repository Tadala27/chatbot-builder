<template>
    <VRow class="mt-0">
        <!-- ─── Chat Sidebar ────────────────────────────────────────────── -->
        <VCol v-if="!toggleSide && mdAndUp" class="d-flex align-stretch chatSidebar pe-md-0">
            <VCard variant="outlined" class="bg-surface br-0" rounded="lg">
                <VCardText class="py-5 px-0">
                    <h5 class="text-h5 px-5">
                        Messages
                        <VChip v-if="totalUnread > 0" color="secondary" size="x-small" variant="flat">
                            {{ totalUnread > 99 ? '99+' : totalUnread }}
                        </VChip>
                    </h5>
                    <div class="py-3 px-5 mt-2">
                        <VTextField v-model="searchQuery" variant="outlined" persistent-placeholder
                            placeholder="Search Contact" hide-details>
                            <template #prepend-inner>
                                <SvgSprite name="custom-search" class="text-lightText" style="width:20px;height:20px" />
                            </template>
                        </VTextField>
                    </div>
                    <PerfectScrollbar class="mb-3" style="height:430px">
                        <VProgressLinear v-if="loadingConvos" indeterminate color="primary" />
                        <VList aria-label="chat list" aria-busy="true" border class="px-5">
                            <VListItem v-for="conv in filteredConversations" :key="conv.id" :value="conv.id"
                                color="secondary" class="text-no-wrap chatItem" lines="two" rounded="md"
                                :active="activeConversation?.id === conv.id" @click="openConversation(conv)">
                                <template #prepend>
                                    <VAvatar class="bg-info" size="35">
                                        <span class="text-caption font-weight-bold">{{ getUserInitials(conv) }}</span>
                                    </VAvatar>
                                    <SvgSprite class="badg-dot" :name="statusIcon(conv.status)"
                                        :class="statusClass(conv.status)" style="width:14px;height:14px" />
                                </template>
                                <VListItemTitle class="text-h5 pr-2 mb-1"
                                    :class="{ 'font-weight-bold': conv.unread_count > 0 }">
                                    {{ conv.whatsapp_user_name || conv.whatsapp_user_phone }}
                                </VListItemTitle>
                                <VListItemSubtitle class="text-caption mt-n1 text-lightText" style="opacity:1">
                                    {{ getLastMessagePreview(conv) }}
                                </VListItemSubtitle>
                                <template #append>
                                    <div class="d-flex flex-column text-right">
                                        <small class="text-lightText text-caption mb-1">{{
                                            formatTime(conv.last_message_at) }}</small>
                                        <VBadge v-if="conv.unread_count > 0" dot color="primary"
                                            :content="conv.unread_count" inline />
                                        <VIcon start v-else class="ml-auto text-lightText font-weight-light" size="16">
                                            $checkAll
                                        </VIcon>
                                    </div>
                                </template>
                            </VListItem>
                            <VListItem v-if="!loadingConvos && filteredConversations.length === 0"
                                class="text-center pa-6">
                                <VListItemTitle class="text-caption text-lightText">No conversations found
                                </VListItemTitle>
                            </VListItem>
                        </VList>
                    </PerfectScrollbar>
                </VCardText>
            </VCard>
        </VCol>

        <!-- ─── Chat Detail ──────────────────────────────────────────────── -->
        <VCol class="d-flex align-stretch ps-md-0">
            <VCard variant="outlined" class="bg-surface bl-0" rounded="lg">
                <div v-if="!activeConversation"
                    class="customHeight d-flex flex-column align-center justify-center text-center">
                    <SvgSprite name="custom-message-text-outline" class="text-lightText mb-4"
                        style="width:64px;height:64px;opacity:0.4" />
                    <p class="text-h6 text-lightText">Select a conversation</p>
                    <p class="text-caption text-lightText">Choose from the list on the left</p>
                </div>

                <div v-else class="customHeight">
                    <!-- Chat Detail Header -->
                    <div class="d-sm-flex align-center ga-4 pa-4">
                        <VBtn icon aria-label="menu" variant="text" rounded="md" class="d-none d-md-flex"
                            @click="toggleSide = !toggleSide">
                            <SvgSprite name="custom-menu-outline" class="text-lightText"
                                style="width:20px;height:20px" />
                        </VBtn>
                        <div class="d-flex align-center">
                            <VBtn icon variant="text" class="d-md-none d-sm-flex" @click="sDrawer = !sDrawer">
                                <SvgSprite name="custom-menu-outline" style="width:20px;height:20px" />
                            </VBtn>
                            <div class="d-flex align-center">
                                <VAvatar class="bg-info" size="35">
                                    <span class="text-caption font-weight-bold">{{ getUserInitials(activeConversation)
                                    }}</span>
                                </VAvatar>
                                <div>
                                    <h5 class="text-subtitle-1 mb-0">
                                        {{ activeConversation.whatsapp_user_name ||
                                            activeConversation.whatsapp_user_phone }}
                                    </h5>
                                    <small v-if="remoteTyping" class="text-primary" style="font-style:italic">
                                        {{ remoteTypingName }} is typing…
                                    </small>
                                    <small v-else class="text-lightText">
                                        {{ activeConversation.whatsapp_user_phone }}
                                        ·
                                        <VChip :color="getStatusColor(activeConversation.status)" size="x-small" label>
                                            {{ activeConversation.status }}
                                        </VChip>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="ms-auto ga-2 d-flex">
                            <VBtn icon variant="text" aria-label="refresh" rounded="md" :loading="loadingMessages"
                                @click="loadMessages(1)">
                                <SvgSprite name="custom-reload" class="text-lightText" style="width:20px;height:20px" />
                            </VBtn>
                            <VBtn icon variant="text" aria-label="info" rounded="md"
                                @click.stop="infodrawer = !infodrawer">
                                <SvgSprite name="custom-info-circle-outline" class="text-lightText"
                                    style="width:20px;height:20px" />
                            </VBtn>
                            <VMenu rounded="md">
                                <template #activator="{ props }">
                                    <VBtn icon variant="text" aria-label="menu" rounded="md" v-bind="props">
                                        <VIcon class="text-lightText" size="20">$dotsVertical</VIcon>
                                    </VBtn>
                                </template>
                                <VList rounded="md" elevation="24" aria-label="menu" aria-busy="true" width="110"
                                    density="compact" class="py-0">
                                    <VListItem v-for="(item, index) in menuItems" :key="index" :value="index">
                                        <template #prepend>
                                            <SvgSprite :name="item.icon || ''" class="me-2"
                                                style="width:16px;height:16px" />
                                        </template>
                                        <VListItemTitle class="text-h6">{{ item.title }}</VListItemTitle>
                                    </VListItem>
                                </VList>
                            </VMenu>
                        </div>
                    </div>

                    <VDivider />

                    <!-- Chat History -->
                    <PerfectScrollbar ref="messagesContainer" style="min-height:calc(100vh - 495px);height:430px"
                        :options="{ suppressScrollX: true }">
                        <div class="bg-containerBg pa-5">
                            <div v-if="hasMoreMessages" class="text-center py-2">
                                <VBtn variant="text" size="small" :loading="loadingMessages" prepend-icon="$chevronUp"
                                    @click="loadMoreMessages">
                                    Load earlier messages
                                </VBtn>
                            </div>

                            <template v-for="(msg, idx) in messages" :key="msg.id">
                                <div v-if="idx === 0 || !isSameDay(msg.created_at, messages[idx - 1].created_at)"
                                    class="text-center my-4">
                                    <VChip size="x-small" variant="tonal" label class="text-lightText">
                                        {{ formatDateLabel(msg.created_at) }}
                                    </VChip>
                                </div>

                                <!-- ── Inbound message ── -->
                                <div v-if="msg.direction === 'inbound'" :id="`msg-${msg.id}`"
                                    class="d-flex position-relative message-wrapper"
                                    :class="isGroupedWithPrev(idx) ? 'mb-1' : 'mb-3'">
                                    <div class="message-content-wrapper" style="max-width: 65%;">
                                        <div v-if="msg.content?.context?.message_id" class="quoted-bubble inbound mb-1"
                                            @click="scrollToQuoted(msg.content.context.message_id)">
                                            <p class="quoted-name text-primary mb-0">
                                                {{ activeConversation.whatsapp_user_name ??
                                                    activeConversation.whatsapp_user_phone }}
                                            </p>
                                            <p class="quoted-text text-lightText mb-0 text-truncate">
                                                {{ getQuotedPreview(msg.content.context) }}
                                            </p>
                                        </div>

                                        <VSheet class="bg-surface rounded-lg pa-3 message-bubble inbound-bubble">
                                            <MediaMessage :message="msg" :direction="msg.direction" :show-caption="true"
                                                @open-image="openImagePreview" @open-list="openList(msg)" />
                                            <div v-if="!isMediaMessage(msg.message_type)"
                                                class="d-flex align-center justify-end message-meta">
                                                <small class="text-caption text-lightText">{{
                                                    formatMessageTime(msg.sent_at || msg.created_at) }}</small>
                                            </div>
                                        </VSheet>

                                        <VBtn icon size="x-small" variant="text" rounded="md"
                                            class="reply-btn ms-1 align-self-center" @click="startReply(msg)">
                                            <SvgSprite name="custom-reply-outline" class="text-lightText"
                                                style="width:14px;height:14px" />
                                        </VBtn>
                                    </div>
                                </div>

                                <!-- ── Outbound message ── -->
                                <div v-else :id="`msg-${msg.id}`" class="ml-auto d-flex justify-end message-wrapper"
                                    :class="isGroupedWithPrev(idx) ? 'mb-1' : 'mb-3'">
                                    <div class="message-content-wrapper outbound-wrapper">
                                        <div v-if="!isGroupedWithPrev(idx)" class="sender-label text-end mb-1">
                                            <small class="text-caption text-lightText">
                                                {{ msg.sender_type === 'agent' ? `👤 ${msg.sender_name ?? 'Agent'}` :
                                                    '🤖 Bot' }}
                                            </small>
                                        </div>

                                        <div v-if="msg.content?.context?.message_id" class="quoted-bubble outbound mb-1"
                                            @click="scrollToQuoted(msg.content.context.message_id)">
                                            <p class="quoted-name mb-0" style="color:rgba(255,255,255,0.9)">You</p>
                                            <p class="quoted-text mb-0 text-truncate"
                                                style="color:rgba(255,255,255,0.7)">
                                                {{ getQuotedPreview(msg.content.context) }}
                                            </p>
                                        </div>

                                        <VSheet class="bg-primary rounded-lg pa-3 message-bubble outbound-bubble"
                                            :class="{ 'opacity-60': msg._pending }">
                                            <MediaMessage :message="msg" :direction="msg.direction" :show-caption="true"
                                                :outbound="true" @open-image="openImagePreview"
                                                @open-list="openList(msg)" />
                                            <div v-if="!isMediaMessage(msg.message_type)"
                                                class="d-flex align-center justify-end message-meta">
                                                <small class="text-caption text-white">
                                                    {{ formatMessageTime(msg.sent_at || msg.created_at) }}
                                                </small>
                                                <div class="ms-1 d-flex align-center">
                                                    <SvgSprite v-if="msg._pending" name="custom-clock-outline"
                                                        class="text-white"
                                                        style="width:12px;height:12px;margin-left:2px;" />
                                                    <VIcon v-else-if="msg.status === 'failed'" icon="$alertCircle"
                                                        size="12" class="text-white ml-1" />
                                                    <VIcon v-else-if="msg.status === 'sent'" icon="$check" size="14"
                                                        class="text-white ml-1" />
                                                    <VIcon v-else-if="msg.status === 'delivered'" icon="$checkAll"
                                                        size="14" class="text-white ml-1" style="opacity:0.8" />
                                                    <VIcon v-else-if="msg.status === 'read'" icon="$checkAll" size="14"
                                                        class="ml-1" style="color:#53bdeb" />
                                                    <VIcon v-else-if="msg.status" icon="$checkAll" size="14"
                                                        class="text-white ml-1" style="opacity:0.6" />
                                                </div>
                                            </div>
                                        </VSheet>

                                        <div class="d-flex justify-end mt-1 message-actions">
                                            <VMenu rounded="md">
                                                <template #activator="{ props }">
                                                    <VBtn icon variant="text" aria-label="menu" size="x-small"
                                                        rounded="md" v-bind="props">
                                                        <SvgSprite name="custom-more-outline" class="text-lightText"
                                                            style="width:14px;height:14px" />
                                                    </VBtn>
                                                </template>
                                                <VList elevation="24" width="120" aria-label="menu" aria-busy="true"
                                                    rounded="md" density="compact" class="py-0">
                                                    <VListItem @click="startReply(msg)">
                                                        <template #prepend>
                                                            <SvgSprite name="custom-reply-outline" class="me-2"
                                                                style="width:16px;height:16px" />
                                                        </template>
                                                        <VListItemTitle class="text-h6">Reply</VListItemTitle>
                                                    </VListItem>
                                                    <VListItem @click="copyMessageText(msg)">
                                                        <template #prepend>
                                                            <SvgSprite name="custom-copy" class="me-2"
                                                                style="width:16px;height:16px" />
                                                        </template>
                                                        <VListItemTitle class="text-h6">Copy</VListItemTitle>
                                                    </VListItem>
                                                </VList>
                                            </VMenu>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- Remote typing indicator (contact OR other agent) -->
                            <div v-if="remoteTyping" class="d-flex position-relative mb-3">
                                <VSheet class="bg-surface rounded-md pa-3 mb-1 d-flex align-center ga-1">
                                    <span class="typing-dot" /><span class="typing-dot" /><span class="typing-dot" />
                                </VSheet>
                            </div>
                        </div>
                    </PerfectScrollbar>

                    <VDivider />

                    <!-- ── Reply preview bar ──────────────────────────────── -->
                    <Transition name="slide-up">
                        <div v-if="replyingTo" class="reply-bar pa-3 d-flex align-center ga-2">
                            <div class="reply-bar-inner flex-grow-1 pa-2 rounded">
                                <p class="text-caption font-weight-bold text-primary mb-1">
                                    Replying to
                                    <span class="font-weight-regular text-lightText">
                                        {{ replyingTo.direction === 'inbound'
                                            ? (activeConversation.whatsapp_user_name ??
                                                activeConversation.whatsapp_user_phone)
                                            : 'yourself' }}
                                    </span>
                                </p>
                                <div class="d-flex align-center ga-2">
                                    <img v-if="replyingTo.message_type === 'image' && replyingTo.content.link"
                                        :src="replyingTo.content.link" class="reply-thumb" />
                                    <VIcon v-else-if="replyingTo.message_type === 'document'"
                                        :icon="getDocIcon(replyingTo.content.filename)" class="text-primary"
                                        style="font-size:16px" />
                                    <VIcon v-else-if="replyingTo.message_type === 'audio'" icon="$microphone"
                                        class="text-primary" style="font-size:16px" />
                                    <VIcon v-else-if="replyingTo.message_type === 'video'" icon="$videoVintage"
                                        class="text-primary" style="font-size:16px" />
                                    <VIcon v-else-if="replyingTo.message_type === 'location'"
                                        icon="$mapMarkerRadiusOutline" class="text-primary" style="font-size:16px" />
                                    <p class="text-caption text-truncate text-lightText mb-0 flex-grow-1">
                                        {{ getMessageText(replyingTo) }}
                                    </p>
                                </div>
                            </div>
                            <VBtn icon size="x-small" variant="text" rounded="md" @click="replyingTo = null">
                                <SvgSprite name="custom-close-icon" style="width:14px;height:14px" />
                            </VBtn>
                        </div>
                    </Transition>

                    <!-- ── Attachment preview bar ─────────────────────────── -->
                    <Transition name="slide-up">
                        <div v-if="attachedFile" class="attach-bar pa-3 d-flex align-center ga-3">
                            <template v-if="attachedFile.type.startsWith('image/')">
                                <img :src="attachPreviewUrl" class="attach-thumb" />
                            </template>
                            <template v-else-if="attachedFile.type.startsWith('video/')">
                                <div class="attach-icon-box">
                                    <VIcon icon="$videoVintage"
                                        style="font-size:22px;color:rgb(var(--v-theme-primary))" />
                                </div>
                            </template>
                            <template v-else-if="attachedFile.type.startsWith('audio/')">
                                <div class="attach-icon-box">
                                    <VIcon icon="$microphone"
                                        style="font-size:22px;color:rgb(var(--v-theme-primary))" />
                                </div>
                            </template>
                            <template v-else>
                                <div class="attach-icon-box">
                                    <VIcon :icon="getDocIcon(attachedFile.name)"
                                        style="font-size:22px;color:rgb(var(--v-theme-primary))" />
                                </div>
                            </template>

                            <div class="flex-grow-1 min-width-0">
                                <p class="text-caption font-weight-medium mb-0 text-truncate">{{ attachedFile.name }}
                                </p>
                                <p class="text-caption text-lightText mb-0">
                                    {{ getAttachTypeLabel(attachedFile) }} · {{ formatFileSize(attachedFile.size) }}
                                </p>
                            </div>
                            <VBtn icon size="x-small" variant="text" rounded="md" @click="clearAttachment">
                                <SvgSprite class="text-lightText" name="custom-close"
                                    style="width:24px;height:24px;transform:rotate(45deg)" />
                            </VBtn>
                        </div>
                    </Transition>

                    <!-- ── Compose bar ────────────────────────────────────── -->
                    <input ref="fileInputRef" type="file" style="display:none" :accept="ALLOWED_MIME_TYPES.join(',')"
                        @change="onFileSelected" />

                    <form class="pa-4" @submit.prevent="sendMessage">
                        <WhatsAppTextarea v-if="attachedFile" v-model="attachCaption" placeholder="Add a caption…"
                            variant="underlined" hide-details :rows="1" :max-rows="3" :show-formatting="false"
                            @keydown="handleKeydown">
                            <template #append>
                                <VBtn icon rounded="md" aria-label="attach" variant="text" color="primary"
                                    @click="fileInputRef?.click()">
                                    <SvgSprite name="custom-attach" style="width:24px;height:24px" />
                                </VBtn>
                                <VBtn icon rounded="md" aria-label="send" variant="text" color="primary" type="submit"
                                    :loading="sending" :disabled="sending" @click="sendMessage">
                                    <SvgSprite name="custom-send-outline" style="width:20px;height:20px" />
                                </VBtn>
                            </template>
                        </WhatsAppTextarea>

                        <WhatsAppTextarea v-else v-model="messageText" placeholder="Your message..."
                            variant="underlined" hide-details :rows="2" :max-rows="4" :show-formatting="true"
                            @keydown="handleKeydown" @input="onInputChange">
                            <template #append>
                                <VBtn icon rounded="md" aria-label="attach" variant="text" color="primary"
                                    @click.prevent="fileInputRef?.click()">
                                    <SvgSprite name="custom-attach" style="width:24px;height:24px" />
                                </VBtn>
                                <VBtn icon rounded="md" aria-label="send" variant="text" color="primary" type="submit"
                                    :loading="sending" :disabled="!messageText.trim() || sending" @click="sendMessage">
                                    <SvgSprite name="custom-send-outline" style="width:20px;height:20px" />
                                </VBtn>
                            </template>
                        </WhatsAppTextarea>
                    </form>
                </div>
            </VCard>
        </VCol>
    </VRow>

    <!-- ─── Info Sidebar Drawer ─────────────────────────────────────────── -->
    <VNavigationDrawer v-model="infodrawer" temporary location="end" width="400">
        <div v-if="activeConversation" class="customHeight pa-4">
            <div class="text-end">
                <VBtn color="error" aria-label="close" variant="text" icon rounded="md" size="small"
                    @click="infodrawer = false">
                    <SvgSprite name="custom-close" style="width:20px;height:20px;transform:rotate(45deg)" />
                </VBtn>
            </div>
            <div class="py-4">
                <div class="text-center">
                    <VAvatar size="50" variant="outlined" color="primary">
                        <span class="text-h5 font-weight-bold">{{ getUserInitials(activeConversation) }}</span>
                    </VAvatar>
                    <h4 class="text-h5 mt-3 mb-0">
                        {{ activeConversation.whatsapp_user_name || activeConversation.whatsapp_user_phone }}
                    </h4>
                    <p class="text-caption text-lightText">{{ activeConversation.whatsapp_user_phone }}</p>
                    <div class="d-flex ga-2 align-center justify-center mt-2">
                        <SvgSprite :name="statusIcon(activeConversation.status)"
                            :class="statusClass(activeConversation.status)" style="width:14px;height:14px" />
                        <VChip :color="getStatusColor(activeConversation.status)" size="small">
                            {{ activeConversation.status }}
                        </VChip>
                    </div>
                </div>
                <div class="d-flex ga-4 mt-6">
                    <div class="bg-lightprimary w-100 pa-4 rounded-lg">
                        <h6 class="text-h6 text-primary mb-0">Messages</h6>
                        <div class="d-flex align-center">
                            <SvgSprite name="custom-message-outline" class="text-primary" />
                            <h4 class="text-h4 mb-0 ms-2">{{ messages.length }}</h4>
                        </div>
                    </div>
                    <div class="bg-gray100 w-100 pa-4 rounded-lg">
                        <h6 class="text-h6 mb-0">Unread</h6>
                        <div class="d-flex align-center">
                            <SvgSprite name="custom-notification-outline" />
                            <h4 class="text-h4 mb-0 ms-2">{{ activeConversation.unread_count }}</h4>
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
                                    <p class="mb-0 text-h6 text-lightText ms-auto">{{
                                        activeConversation.whatsapp_user_phone }}</p>
                                </div>
                            </VListItem>
                            <VListItem class="pa-0">
                                <div class="d-flex">
                                    <p class="mb-0 text-h6">Channel</p>
                                    <p class="mb-0 text-h6 text-lightText ms-auto">
                                        {{ activeConversation.whatsapp_account?.verified_name ?? 'WhatsApp' }}
                                    </p>
                                </div>
                            </VListItem>
                            <VListItem class="pa-0">
                                <div class="d-flex">
                                    <p class="mb-0 text-h6">Status</p>
                                    <p class="mb-0 text-h6 text-lightText ms-auto">{{ activeConversation.status }}</p>
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
        </div>
    </VNavigationDrawer>

    <!-- ─── Mobile Sidebar Drawer ───────────────────────────────────────── -->
    <VNavigationDrawer v-if="!mdAndUp" v-model="sDrawer" temporary width="300" top>
        <PerfectScrollbar style="height:calc(100vh - 60px)">
            <VCardText class="pa-5">
                <h5 class="text-h5">
                    Messages
                    <VChip v-if="totalUnread > 0" color="secondary" size="x-small" variant="flat">{{ totalUnread }}
                    </VChip>
                </h5>
                <div class="py-3 px-5 mt-2">
                    <VTextField v-model="searchQuery" variant="outlined" persistent-placeholder
                        placeholder="Search Contact" hide-details>
                        <template #prepend-inner>
                            <SvgSprite name="custom-search" class="text-lightText" style="width:20px;height:20px" />
                        </template>
                    </VTextField>
                </div>
                <VList aria-label="chat list" aria-busy="true" border class="px-5">
                    <VListItem v-for="conv in filteredConversations" :key="conv.id" :value="conv.id" color="secondary"
                        class="text-no-wrap chatItem" lines="two" rounded="md"
                        :active="activeConversation?.id === conv.id" @click="openConversation(conv); sDrawer = false">
                        <template #prepend>
                            <VAvatar>
                                <span class="text-caption font-weight-bold">{{ getUserInitials(conv) }}</span>
                            </VAvatar>
                            <SvgSprite class="badg-dot" :name="statusIcon(conv.status)"
                                :class="statusClass(conv.status)" style="width:14px;height:14px" />
                        </template>
                        <VListItemTitle class="text-h5 pr-2 mb-1">
                            {{ conv.whatsapp_user_name || conv.whatsapp_user_phone }}
                        </VListItemTitle>
                        <VListItemSubtitle class="text-caption mt-n1 text-lightText" style="opacity:1">
                            {{ getLastMessagePreview(conv) }}
                        </VListItemSubtitle>
                        <template #append>
                            <div class="d-flex flex-column text-right">
                                <small class="text-lightText text-caption mb-1">{{ formatTime(conv.last_message_at)
                                }}</small>
                                <VBadge v-if="conv.unread_count > 0" dot color="primary" :content="conv.unread_count"
                                    inline />
                                <SvgSprite v-else name="custom-circle-check-outline" class="ml-auto text-lightText"
                                    style="width:16px;height:16px" />
                            </div>
                        </template>
                    </VListItem>
                </VList>
            </VCardText>
        </PerfectScrollbar>
    </VNavigationDrawer>

    <!-- ─── WhatsApp List Options Dialog ───────────────────────────────── -->
    <VDialog v-model="sheetOpen" max-width="460" rounded="lg" scrollable>
        <VCard rounded="lg">
            <VCardTitle class="d-flex align-center justify-space-between pa-4">
                <span class="text-h6">{{ sheetTitle }}</span>
                <VBtn icon size="small" variant="text" rounded="md" @click="closeSheet">
                    <VIcon size="18">$close</VIcon>
                </VBtn>
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-0">
                <div v-for="(section, si) in sheetSections" :key="si">
                    <p v-if="section.title"
                        class="text-uppercase text-caption font-weight-bold text-primary px-4 pt-4 pb-1"
                        style="letter-spacing:0.08em">
                        {{ section.title }}
                    </p>
                    <VList density="compact" class="pa-0">
                        <VListItem v-for="row in section.rows" :key="row.id" rounded="0" color="secondary"
                            class="px-4 py-3">
                            <VListItemTitle class="text-body-2">{{ row.title }}</VListItemTitle>
                            <VListItemSubtitle v-if="row.description" class="text-caption mt-n1" style="opacity:1">
                                {{ row.description }}
                            </VListItemSubtitle>
                            <template #append>
                                <div class="list-radio-circle" />
                            </template>
                        </VListItem>
                    </VList>
                    <VDivider v-if="si < sheetSections.length - 1" />
                </div>
            </VCardText>
        </VCard>
    </VDialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted, shallowRef, defineAsyncComponent } from 'vue'
import WhatsAppTextarea from "@/components/RichTextArea.vue";
import axios from 'axios'
import Pusher from 'pusher-js'
import { useDisplay } from 'vuetify'

const MediaMessage = defineAsyncComponent(() => import('@/components/chat/MediaMessage.vue'))

const { mdAndUp } = useDisplay()

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
}
interface SheetRow { id: string; title: string; description?: string }
interface SheetSection { title?: string; rows: SheetRow[] }

// ── State ─────────────────────────────────────────────────────────────────────
const conversations = ref<Conversation[]>([])
const activeConversation = ref<Conversation | null>(null)
const messages = ref<Message[]>([])
const messageText = ref('')
const replyingTo = ref<Message | null>(null)
const searchQuery = ref('')
const statusFilter = ref('all')
const loadingConvos = ref(false)
const loadingMessages = ref(false)
const sending = ref(false)
const hasMoreMessages = ref(false)
const currentPage = ref(1)
const messagesContainer = ref<any>(null)
const remoteTyping = ref(false)
const remoteTypingName = ref('')
const remoteTypingTimeout = ref<ReturnType<typeof setTimeout> | null>(null)
const typingActive = ref(false)
const typingDebounce = ref<ReturnType<typeof setTimeout> | null>(null)
const toggleSide = ref(false)
const sDrawer = ref(false)
const infodrawer = ref(false)
const notification = ref(true)
const panel1 = ref([0])
const sheetOpen = ref(false)
const sheetTitle = ref('')
const sheetSections = ref<SheetSection[]>([])

// ── Attachment state ──────────────────────────────────────────────────────────
const fileInputRef = ref<HTMLInputElement | null>(null)
const attachedFile = ref<File | null>(null)
const attachCaption = ref('')
const attachPreviewUrl = ref('')

const ALLOWED_MIME_TYPES = [
    'image/jpeg', 'image/png', 'image/webp',
    'video/mp4', 'video/3gpp',
    'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/amr',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain',
]

const menuItems = shallowRef([
    { title: 'Archive', icon: 'custom-document-2' },
    { title: 'Muted', icon: 'custom-speaker-outline' },
    { title: 'Delete', icon: 'custom-trash' },
])

function isMediaMessage(type: string): boolean {
    return ['image', 'video', 'audio', 'document', 'location'].includes(type)
}

// ── Pusher ────────────────────────────────────────────────────────────────────
let pusher: Pusher | null = null
let inboxChannel: ReturnType<Pusher['subscribe']> | null = null
let conversationChannel: ReturnType<Pusher['subscribe']> | null = null

function initPusher() {
    if (pusher) return
    pusher = new Pusher(import.meta.env.VITE_PUSHER_APP_KEY as string, {
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER as string ?? 'ap2',
    })
    const tenantId = (window as any).__TENANT_ID__
    if (!tenantId) {
        console.warn('[Pusher] window.__TENANT_ID__ not set — inbox channel not subscribed.')
        return
    }

    // ── Tenant inbox channel: sidebar preview + unread badge updates ──────────
    inboxChannel = pusher.subscribe(`tenant.${tenantId}.inbox`)
    inboxChannel.bind('message.received', (e: any) => {
        updateConversationInList(e.conversation)
        // The conversation.{id} channel handles appending + markAsRead for the
        // open conversation — do NOT duplicate that logic here.
    })
}

function subscribeToConversation(id: number) {
    if (!pusher) return
    if (conversationChannel) {
        pusher.unsubscribe(conversationChannel.name)
        conversationChannel = null
    }

    conversationChannel = pusher.subscribe(`conversation.${id}`)

    // ── All messages (inbound user + outbound bot/agent) ──────────────────────
    conversationChannel.bind('message.received', (e: any) => {
        if (activeConversation.value?.id !== id) return

        appendMessageIfNotExists(e.message)
        nextTick(scrollToBottom)

        // Mark as read after the bubble renders.
        // NEVER pass with_typing here — that would fire AgentTyping for every
        // inbound message, including ones the bot handles, causing a spurious
        // "Agent is typing…" indicator in the header.
        if (e.message.direction === 'inbound') {
            setTimeout(() => markConversationRead(), 300)
        }
    })

    // ── Delivery / read status tick updates ──────────────────────────────────
    conversationChannel.bind('message.status', (e: any) => {
        const msg = messages.value.find(
            m => m.whatsapp_message_id === e.whatsapp_message_id || m.id === e.message_id
        )
        if (msg) {
            msg.status = e.status
            msg.delivered_at = e.delivered_at ?? msg.delivered_at
            msg.read_at = e.read_at ?? msg.read_at
        }
    })

    // ── Agent typing indicator (another agent → shown to THIS agent) ──────────
    // Only fires from POST /typing — never from markAsRead.
    conversationChannel.bind('agent.typing', (e: any) => {
        const myId = (window as any).__USER_ID__
        if (myId && e.agent_id === myId) return  // don't show your own typing to yourself
        remoteTyping.value = e.is_typing
        remoteTypingName.value = e.agent_name ?? 'Agent'
        if (remoteTypingTimeout.value) clearTimeout(remoteTypingTimeout.value)
        if (e.is_typing)
            remoteTypingTimeout.value = setTimeout(() => { remoteTyping.value = false }, 6000)
    })

    // ── Contact typing indicator (WhatsApp user → agent) ─────────────────────
    // WhatsApp Cloud API sends a status="typing" webhook event when the contact
    // is composing a reply. There is no "stopped typing" event — auto-clear after 6s.
    conversationChannel.bind('contact.typing', (e: any) => {
        if (activeConversation.value?.id !== id) return
        remoteTyping.value = e.is_typing
        remoteTypingName.value = activeConversation.value?.whatsapp_user_name
            ?? activeConversation.value?.whatsapp_user_phone
            ?? 'Contact'
        if (remoteTypingTimeout.value) clearTimeout(remoteTypingTimeout.value)
        // Auto-clear since WA never sends a "stopped" event
        remoteTypingTimeout.value = setTimeout(() => { remoteTyping.value = false }, 6000)
    })
}

function teardownPusher() {
    if (conversationChannel) { pusher?.unsubscribe(conversationChannel.name); conversationChannel = null }
    if (inboxChannel) { pusher?.unsubscribe(inboxChannel.name); inboxChannel = null }
    pusher?.disconnect()
    pusher = null
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(async () => { await loadConversations(); initPusher() })
onUnmounted(teardownPusher)

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadConversations() {
    loadingConvos.value = true
    try {
        const { data } = await axios.get('/api/inbox/conversations', {
            params: {
                status: statusFilter.value === 'all' ? undefined : statusFilter.value,
                search: searchQuery.value || undefined,
            },
        })
        conversations.value = data.data
    } finally {
        loadingConvos.value = false
    }
}

async function openConversation(conv: Conversation) {
    if (activeConversation.value?.id === conv.id) return
    activeConversation.value = conv
    messages.value = []
    currentPage.value = 1
    replyingTo.value = null
    remoteTyping.value = false
    clearAttachment()
    subscribeToConversation(conv.id)
    await loadMessages(1)
    // Mark read on open (no typing indicator — agent hasn't started typing yet)
    setTimeout(() => markConversationRead(), 300)
    const idx = conversations.value.findIndex(c => c.id === conv.id)
    if (idx !== -1) conversations.value[idx].unread_count = 0
    await nextTick()
    scrollToBottom()
}

async function loadMessages(page = 1) {
    if (!activeConversation.value) return
    loadingMessages.value = true
    try {
        const { data } = await axios.get(
            `/api/inbox/conversations/${activeConversation.value.id}`,
            { params: { page } }
        )
        if (page === 1) messages.value = data.messages.data
        else messages.value = [...data.messages.data, ...messages.value]
        hasMoreMessages.value = !!data.messages.next_page_url
        currentPage.value = page
    } finally {
        loadingMessages.value = false
    }
}

async function loadMoreMessages() {
    if (!hasMoreMessages.value || loadingMessages.value) return
    await loadMessages(currentPage.value + 1)
}

// ── Sending ───────────────────────────────────────────────────────────────────
async function sendMessage() {
    if (!activeConversation.value || sending.value) return
    if (!attachedFile.value && !messageText.value.trim()) return

    stopTyping()
    sending.value = true

    const replyWamid = replyingTo.value?.whatsapp_message_id ?? null
    const convId = activeConversation.value.id

    // ── File attachment path ──────────────────────────────────────────────────
    if (attachedFile.value) {
        const file = attachedFile.value
        const caption = attachCaption.value.trim()
        const msgType = file.type.startsWith('image/') ? 'image'
            : file.type.startsWith('video/') ? 'video'
                : file.type.startsWith('audio/') ? 'audio'
                    : 'document'

        const optimistic: Message = {
            id: Date.now(),
            conversation_id: convId,
            whatsapp_message_id: null,
            direction: 'outbound',
            message_type: msgType,
            content: {
                link: attachPreviewUrl.value || null,
                filename: file.name,
                caption: caption || undefined,
                context: replyWamid ? { message_id: replyWamid } : undefined,
            },
            status: 'sent',
            sent_at: new Date().toISOString(),
            created_at: new Date().toISOString(),
            sender_type: 'agent',
            _pending: true,
        }

        messages.value.push(optimistic)
        replyingTo.value = null
        clearAttachment()
        await nextTick(); scrollToBottom()

        try {
            const form = new FormData()
            form.append('file', file)
            form.append('conversation_id', String(convId))
            if (caption) form.append('caption', caption)
            if (replyWamid) form.append('reply_to_wamid', replyWamid)

            const { data } = await axios.post(
                `/api/inbox/conversations/${convId}/media`,
                form,
                { headers: { 'Content-Type': 'multipart/form-data' } }
            )
            const idx = messages.value.findIndex(m => m.id === optimistic.id)
            if (idx !== -1) messages.value.splice(idx, 1, { ...data.message, _pending: false })
        } catch {
            const idx = messages.value.findIndex(m => m.id === optimistic.id)
            if (idx !== -1) messages.value[idx].status = 'failed'
        } finally {
            sending.value = false
        }
        return
    }

    // ── Text path ─────────────────────────────────────────────────────────────
    const text = messageText.value.trim()

    const optimistic: Message = {
        id: Date.now(),
        conversation_id: convId,
        whatsapp_message_id: null,
        direction: 'outbound',
        message_type: 'text',
        content: {
            text,
            context: replyWamid ? { message_id: replyWamid } : undefined,
        },
        status: 'sent',
        sent_at: new Date().toISOString(),
        created_at: new Date().toISOString(),
        sender_type: 'agent',
        _pending: true,
    }

    messages.value.push(optimistic)
    messageText.value = ''
    replyingTo.value = null
    await nextTick(); scrollToBottom()

    try {
        const { data } = await axios.post(
            `/api/inbox/conversations/${convId}/messages`,
            { text, reply_to_wamid: replyWamid }
        )
        const idx = messages.value.findIndex(m => m.id === optimistic.id)
        if (idx !== -1) messages.value.splice(idx, 1, { ...data.message, _pending: false })
    } catch {
        const idx = messages.value.findIndex(m => m.id === optimistic.id)
        if (idx !== -1) messages.value[idx].status = 'failed'
    } finally {
        sending.value = false
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage() }
}

function onInputChange() {
    if (!activeConversation.value) return
    if (!typingActive.value) { typingActive.value = true; sendTypingEvent(true) }
    if (typingDebounce.value) clearTimeout(typingDebounce.value)
    typingDebounce.value = setTimeout(() => stopTyping(), 3000)
}

function stopTyping() {
    if (typingDebounce.value) { clearTimeout(typingDebounce.value); typingDebounce.value = null }
    if (typingActive.value) { typingActive.value = false; sendTypingEvent(false) }
}

async function sendTypingEvent(isTyping: boolean) {
    if (!activeConversation.value) return
    try {
        await axios.post(
            `/api/inbox/conversations/${activeConversation.value.id}/typing`,
            { typing: isTyping }
        )
    } catch { /* non-fatal */ }
}

/**
 * Tell the server to mark all inbound messages as read.
 * NEVER fires a typing indicator — that is done exclusively via sendTypingEvent()
 * when the agent is actually typing. Combining the two caused a spurious
 * "Agent is typing…" bubble on every inbound message, including bot replies.
 */
async function markConversationRead() {
    if (!activeConversation.value) return
    try {
        await axios.post(`/api/inbox/conversations/${activeConversation.value.id}/read`)
    } catch { /* non-fatal */ }
}

// ── Attachment helpers ────────────────────────────────────────────────────────
function onFileSelected(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    if (!ALLOWED_MIME_TYPES.includes(file.type)) {
        alert(`File type not supported: ${file.type}`)
        return
    }
    attachedFile.value = file
    attachCaption.value = ''
    attachPreviewUrl.value = file.type.startsWith('image/') ? URL.createObjectURL(file) : ''
    if (fileInputRef.value) fileInputRef.value.value = ''
}

function clearAttachment() {
    if (attachPreviewUrl.value) URL.revokeObjectURL(attachPreviewUrl.value)
    attachedFile.value = null
    attachCaption.value = ''
    attachPreviewUrl.value = ''
}

function getAttachTypeLabel(file: File): string {
    if (file.type.startsWith('image/')) return 'Image'
    if (file.type.startsWith('video/')) return 'Video'
    if (file.type.startsWith('audio/')) return 'Audio'
    return getDocLabel(file.name)
}

function formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 B'
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i]
}

// ── Reply helpers ─────────────────────────────────────────────────────────────
function startReply(msg: Message) {
    replyingTo.value = msg
    nextTick(() => {
        document.querySelector<HTMLTextAreaElement>('.whatsapp-textarea')?.focus()
    })
}

function getQuotedPreview(context: any): string {
    if (!context) return ''
    if (context.body) return context.body
    const original = messages.value.find(m => m.whatsapp_message_id === context.message_id)
    if (original) return getMessageText(original)
    return '↩ Original message'
}

function scrollToQuoted(wamid: string) {
    const msg = messages.value.find(m => m.whatsapp_message_id === wamid)
    if (!msg) return
    const el = document.getElementById(`msg-${msg.id}`)
    if (!el) return
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    el.classList.add('msg-highlight')
    setTimeout(() => el.classList.remove('msg-highlight'), 1500)
}

function copyMessageText(msg: Message) {
    navigator.clipboard?.writeText(getMessageText(msg)).catch(() => { })
}

// ── Status helpers ────────────────────────────────────────────────────────────
function statusIcon(s: string) {
    if (s === 'away') return 'custom-away-fill'
    if (s === 'do not disturb') return 'custom-disturb-fill'
    if (s === 'active') return 'custom-check-circle-fill'
    return 'containerBg'
}
function statusClass(s: string) {
    if (s === 'away') return 'text-warning'
    if (s === 'do not disturb') return 'text-secondary'
    if (s === 'active') return 'text-success'
    return 'text-containerBg'
}
function getStatusColor(s: string) {
    return ({ active: 'success', completed: 'default', abandoned: 'warning' } as Record<string, string>)[s] ?? 'default'
}

// ── Sheet ─────────────────────────────────────────────────────────────────────
function openList(msg: Message) {
    sheetSections.value = (msg.content?.action?.sections as SheetSection[]) ?? []
    sheetTitle.value = msg.content?.action?.button ?? 'Options'
    sheetOpen.value = true
}
function closeSheet() { sheetOpen.value = false }

// ── Computed ──────────────────────────────────────────────────────────────────
const filteredConversations = computed(() => {
    let list = conversations.value
    if (statusFilter.value !== 'all') list = list.filter(c => c.status === statusFilter.value)
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase()
        list = list.filter(c =>
            (c.whatsapp_user_name ?? '').toLowerCase().includes(q) ||
            c.whatsapp_user_phone.includes(q)
        )
    }
    return list
})

const totalUnread = computed(() =>
    conversations.value.reduce((sum, c) => sum + (c.unread_count ?? 0), 0)
)

// ── Helpers ───────────────────────────────────────────────────────────────────
function appendMessageIfNotExists(msg: Message) {
    const exists = messages.value.some(
        m => m.id === msg.id ||
            (m.whatsapp_message_id && m.whatsapp_message_id === msg.whatsapp_message_id)
    )
    if (!exists) messages.value.push(msg)
}

function updateConversationInList(update: Partial<Conversation> & { id: number }) {
    const idx = conversations.value.findIndex(c => c.id === update.id)
    if (idx !== -1) {
        const [conv] = conversations.value.splice(idx, 1)
        conversations.value.unshift({ ...conv, ...update })
    } else {
        conversations.value.unshift(update as Conversation)
    }
}

function scrollToBottom() {
    nextTick(() => {
        const el = messagesContainer.value?.$el ?? messagesContainer.value
        if (el) el.scrollTop = el.scrollHeight
    })
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
    const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1)
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function formatMessageTime(iso: string | null): string {
    if (!iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function getUserInitials(conv: Conversation): string {
    const name = conv.whatsapp_user_name
    if (!name) return conv.whatsapp_user_phone.slice(-2)
    return name.split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase()
}

function isGroupedWithPrev(idx: number): boolean {
    if (idx === 0) return false
    const curr = messages.value[idx], prev = messages.value[idx - 1]
    return curr.direction === prev.direction &&
        new Date(curr.created_at).getTime() - new Date(prev.created_at).getTime() < 60_000
}

function isSameDay(a: string, b: string): boolean {
    return new Date(a).toDateString() === new Date(b).toDateString()
}

function formatDateLabel(iso: string): string {
    const d = new Date(iso), now = new Date()
    if (d.toDateString() === now.toDateString()) return 'Today'
    const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1)
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday'
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })
}

function openImagePreview(url: string) { window.open(url, '_blank') }

function getDocIcon(filename?: string): string {
    if (!filename) return '$fileDocumentOutline'
    const ext = filename.split('.').pop()?.toLowerCase() ?? ''
    if (['doc', 'docx'].includes(ext)) return '$fileWordOutline'
    if (ext === 'pdf') return '$filePdfBox'
    if (['xls', 'xlsx'].includes(ext)) return '$fileExcelOutline'
    if (['ppt', 'pptx'].includes(ext)) return '$filePowerpointOutline'
    if (ext === 'txt') return '$fileDocumentOutline'
    return '$fileOutline'
}

function getDocLabel(filename?: string): string {
    if (!filename) return 'Document'
    return filename.split('.').pop()?.toUpperCase() ?? 'FILE'
}

// ── Watchers ──────────────────────────────────────────────────────────────────
watch(statusFilter, loadConversations)
let searchDebounce: ReturnType<typeof setTimeout> | null = null
watch(searchQuery, () => {
    if (searchDebounce) clearTimeout(searchDebounce)
    searchDebounce = setTimeout(loadConversations, 300)
})
</script>

<style lang="scss">
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

.chatSidebar {
    max-width: 319px;

    .v-list-item__prepend>.v-avatar~.v-list-item__spacer {
        width: 0;
    }
}

.customHeight {
    min-height: calc(75vh - 300px) !important;
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

            &.v-expansion-panel-title--active .v-expansion-panel-title__overlay {
                background: transparent;
            }
        }

        .v-expansion-panel-text__wrapper {
            border-top: none;
            padding: 0;
            padding-top: 15px;
        }
    }
}

.sender-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: rgba(var(--v-theme-primary), 0.8);
}

.reply-btn {
    opacity: 0;
    transition: opacity 0.15s;
}

.d-flex:hover>.reply-btn,
.message-content-wrapper:hover .reply-btn {
    opacity: 1;
}

.reply-bar {
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.reply-bar-inner {
    background: rgba(var(--v-theme-primary), 0.06);
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

.typing-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: rgba(var(--v-theme-on-surface), 0.35);
    animation: bounce 1.2s infinite;
}

.typing-dot:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes bounce {

    0%,
    60%,
    100% {
        transform: translateY(0);
    }

    30% {
        transform: translateY(-6px);
    }
}

.wa-action-btn {
    border-top: 1px solid rgba(var(--v-border-color), 0.12);
    color: #53bdeb;
    font-size: 0.875rem;
    transition: background 0.15s;

    &:hover {
        background: rgba(83, 189, 235, 0.08);
    }
}

.list-radio-circle {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid rgba(var(--v-theme-on-surface), 0.25);
    flex-shrink: 0;
}

.message-wrapper {
    width: 100%;
}

.message-content-wrapper {
    max-width: 50%;
}

.message-bubble {
    display: inline-block;
    word-wrap: break-word;
    max-width: 100%;
}

.message-text {
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: pre-wrap;
    word-break: break-word;
}

.message-meta {
    gap: 4px;
    opacity: 0.8;
    font-size: 0.7rem;
    line-height: 1;
}

.inbound-bubble {
    background-color: rgb(var(--v-theme-surface));
    border: 1px solid rgb(var(--v-theme-borderLight));
}

.outbound-bubble {
    background-color: rgb(var(--v-theme-primary));
}

.message-actions {
    opacity: 0;
    transition: opacity 0.2s;
}

.message-content-wrapper:hover .message-actions {
    opacity: 1;
}

[dir="rtl"] .outbound-wrapper {
    margin-left: 0;
    margin-right: auto;
}

.quoted-bubble {
    border-radius: 6px;
    padding: 6px 10px;
    cursor: pointer;
    overflow: hidden;
    max-width: 100%;
    transition: opacity 0.15s;

    &:hover {
        opacity: 0.8;
    }

    &.inbound {
        background: rgba(var(--v-theme-primary), 0.08);
        border-left: 3px solid rgb(var(--v-theme-primary));
    }

    &.outbound {
        background: rgba(255, 255, 255, 0.15);
        border-left: 3px solid rgba(255, 255, 255, 0.55);
    }
}

.quoted-name {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.quoted-text {
    font-size: 0.8rem;
    max-width: 260px;
}

.reply-thumb {
    width: 36px;
    height: 36px;
    border-radius: 4px;
    object-fit: cover;
    flex-shrink: 0;
}

.attach-bar {
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: rgb(var(--v-theme-surface));
}

.attach-thumb {
    width: 48px;
    height: 48px;
    border-radius: 6px;
    object-fit: cover;
    flex-shrink: 0;
}

.attach-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: rgba(var(--v-theme-primary), 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

@keyframes highlight-flash {
    0% {
        background: rgba(var(--v-theme-warning), 0.25);
    }

    100% {
        background: transparent;
    }
}

.msg-highlight .message-bubble {
    animation: highlight-flash 1.5s ease-out;
}
</style>