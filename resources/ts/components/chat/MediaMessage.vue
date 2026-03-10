<template>
    <!-- ── Text ─────────────────────────────────────────────────────────────── -->
    <div v-if="message.message_type === 'text'">
        <p class="text-body-1 mb-0 message-text" style="white-space:pre-wrap"
            v-html="linkify(message.content.text ?? message.content.body ?? '')" />
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Interactive inbound (selected button/list option) ─────────────────── -->
    <div v-else-if="message.message_type === 'interactive' && message.direction === 'inbound'"
        class="d-flex align-center ga-2">
        <VIcon :color="outbound ? 'white' : 'primary'" size="16">$checkCircleOutline</VIcon>
        <p class="text-body-1 mb-0">
            {{ message.content.response?.title ?? message.content.response?.id ?? '[Selected option]' }}
        </p>
        <MessageMeta :message="message" :outbound="outbound" inline />
    </div>

    <!-- ── Interactive outbound (buttons message) ─────────────────────────────── -->
    <div v-else-if="message.message_type === 'interactive' && message.direction === 'outbound'">
        <p v-if="bodyText" class="text-body-1 mb-2 message-text" style="white-space:pre-wrap">{{ bodyText }}</p>
        <template v-if="isListType">
            <button class="wa-action-btn w-100 py-2 d-flex align-center justify-center ga-1"
                @click="$emit('openList', message)">
                <VIcon size="16" color="'#53bdeb'">$formatListBulleted</VIcon>
                <span>{{ message.content.action?.button ?? 'View options' }}</span>
            </button>
        </template>
        <template v-else>
            <div v-for="btn in (message.content.action?.buttons ?? [])" :key="btn.reply?.id ?? btn.id"
                class="wa-action-btn w-100 py-2 d-flex align-center justify-center ga-1">
                <VIcon size="14" :color="outbound ? 'white' : '#53bdeb'">$replyOutline</VIcon>
                <span>{{ btn.reply?.title ?? btn.title }}</span>
            </div>
        </template>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Image ──────────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'image'">
        <div class="wa-media-image-wrap" @click="message.content.link && $emit('openImage', message.content.link)">
            <img v-if="message.content.link" :src="message.content.link" class="wa-media-image"
                :alt="message.content.caption ?? 'Image'" loading="lazy" />
            <div v-else class="wa-media-placeholder d-flex align-center justify-center">
                <VIcon size="36" :color="outbound ? 'rgba(255,255,255,0.5)' : 'rgba(0,0,0,0.2)'">$imageOutline</VIcon>
            </div>
        </div>
        <p v-if="showCaption && message.content.caption" class="text-caption mt-2 mb-0"
            :class="outbound ? 'text-white' : 'text-lightText'">
            {{ message.content.caption }}
        </p>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Video ──────────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'video'">
        <div class="wa-video-wrap">
            <!-- If a blob/local URL is available, show native player -->
            <video v-if="message.content.link" :src="message.content.link" class="wa-video-player" controls
                preload="metadata" />
            <!-- Otherwise show a placeholder with play icon -->
            <div v-else class="wa-video-placeholder d-flex align-center justify-center flex-column">
                <VIcon size="40" :color="outbound ? 'rgba(255,255,255,0.7)' : 'rgba(0,0,0,0.35)'">
                    $playCircleOutline
                </VIcon>
                <span class="text-caption mt-1"
                    :style="outbound ? 'color:rgba(255,255,255,0.6)' : 'color:rgba(0,0,0,0.45)'">
                    Video
                </span>
            </div>
        </div>
        <p v-if="showCaption && message.content.caption" class="text-caption mt-2 mb-0"
            :class="outbound ? 'text-white' : 'text-lightText'">
            {{ message.content.caption }}
        </p>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Audio ──────────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'audio'">
        <div class="wa-audio-wrap d-flex align-center ga-2">
            <VIcon :color="outbound ? 'white' : 'primary'" size="22">$microphone</VIcon>
            <audio v-if="message.content.link" :src="message.content.link" controls
                class="wa-audio-player flex-grow-1" />
            <div v-else class="wa-audio-waveform flex-grow-1 d-flex align-center ga-1">
                <span v-for="h in waveformHeights" :key="h.key" class="wa-wave-bar"
                    :style="{ height: h.height + 'px', opacity: outbound ? 0.8 : 0.6 }" />
            </div>
        </div>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Document ───────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'document'">
        <div class="wa-doc-wrap d-flex align-center ga-2 pa-1">
            <div class="wa-doc-icon-box" :class="outbound ? 'outbound' : 'inbound'">
                <VIcon :color="outbound ? 'white' : 'primary'" size="22">{{ docIcon }}</VIcon>
            </div>
            <div class="flex-grow-1 min-width-0">
                <p class="text-body-2 font-weight-medium mb-0 text-truncate" :class="outbound ? 'text-white' : ''">
                    {{ message.content.filename ?? 'Document' }}
                </p>
                <p class="text-caption mb-0" :class="outbound ? 'text-white opacity-70' : 'text-lightText'">
                    {{ docExtLabel }}
                </p>
            </div>
            <VBtn v-if="message.content.link" icon size="x-small" variant="text" :color="outbound ? 'white' : 'primary'"
                :href="message.content.link" target="_blank" download>
                <VIcon size="18">$trayArrowDown</VIcon>
            </VBtn>
        </div>
        <p v-if="showCaption && message.content.caption" class="text-caption mt-2 mb-0 px-1"
            :class="outbound ? 'text-white' : 'text-lightText'">
            {{ message.content.caption }}
        </p>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Location ───────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'location'">
        <a :href="googleMapsUrl" target="_blank" rel="noopener noreferrer"
            class="wa-location-wrap d-block text-decoration-none">
            <div class="wa-location-map d-flex align-center justify-center flex-column"
                :class="outbound ? 'outbound' : 'inbound'">
                <VIcon size="30" :color="outbound ? 'white' : 'success'">$mapMarker</VIcon>
                <span class="text-caption mt-1"
                    :style="outbound ? 'color:rgba(255,255,255,0.8)' : 'color:rgba(0,0,0,0.5)'">
                    {{ message.content.latitude?.toFixed(5) }}, {{ message.content.longitude?.toFixed(5) }}
                </span>
            </div>
            <div class="pa-2">
                <p v-if="message.content.name" class="text-body-2 font-weight-medium mb-0"
                    :class="outbound ? 'text-white' : ''">
                    {{ message.content.name }}
                </p>
                <p v-if="message.content.address" class="text-caption mb-0"
                    :class="outbound ? 'text-white opacity-70' : 'text-lightText'">
                    {{ message.content.address }}
                </p>
            </div>
        </a>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Contacts ───────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'contacts'">
        <div v-for="(contact, i) in (message.content.contacts ?? [message.content])" :key="i"
            class="wa-contact-row d-flex align-center ga-2 pa-1" :class="{ 'mt-1': i > 0 }">
            <VAvatar size="36" :color="outbound ? 'rgba(255,255,255,0.2)' : 'primary'" variant="tonal">
                <VIcon :color="outbound ? 'white' : 'primary'" size="20">$accountOutline</VIcon>
            </VAvatar>
            <div class="flex-grow-1 min-width-0">
                <p class="text-body-2 font-weight-medium mb-0 text-truncate" :class="outbound ? 'text-white' : ''">
                    {{ contactName(contact) }}
                </p>
                <p v-if="contact.phones?.[0]?.phone" class="text-caption mb-0"
                    :class="outbound ? 'text-white opacity-70' : 'text-lightText'">
                    {{ contact.phones[0].phone }}
                </p>
            </div>
        </div>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Sticker ────────────────────────────────────────────────────────────── -->
    <div v-else-if="message.message_type === 'sticker'">
        <img v-if="message.content.link" :src="message.content.link" class="wa-sticker" alt="Sticker" loading="lazy" />
        <div v-else class="wa-sticker d-flex align-center justify-center">
            <VIcon size="48" color="primary">$emoticon</VIcon>
        </div>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>

    <!-- ── Fallback ───────────────────────────────────────────────────────────── -->
    <div v-else>
        <p class="text-caption mb-0" :class="outbound ? 'text-white opacity-70' : 'text-lightText'">
            [{{ message.message_type }}]
        </p>
        <MessageMeta :message="message" :outbound="outbound" />
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

// ── Subcomponent: time + tick marks ─────────────────────────────────────────

interface MessageMetaProps {
    message: any
    outbound: boolean
    inline?: boolean
}

// MessageMeta is defined inline via a micro-component trick — avoids a
// separate file for such a tiny piece.
import { defineComponent, h } from 'vue'

const MessageMeta = defineComponent({
    props: {
        message: { type: Object, required: true },
        outbound: { type: Boolean, default: false },
        inline: { type: Boolean, default: false },
    },
    setup(props) {

        // Status tick icon (single ✓ / double ✓✓ / blue ✓✓ / clock / ✗)
        function statusIcon(status: string) {
            if (status === 'read') return { icon: '$checkAll', class: 'text-blue' }
            if (status === 'delivered') return { icon: '$checkAll', class: props.outbound ? 'text-white-dim' : '' }
            if (status === 'sent') return { icon: '$check', class: props.outbound ? 'text-white-dim' : '' }
            if (status === 'failed') return { icon: '$alertCircle', class: 'text-error' }
            return null
        }

        return () => {
            const m = props.message
            const icon = m.direction === 'outbound' ? statusIcon(m.status) : null

            return h('div', {
                class: `message-meta ${props.inline ? 'inline' : ''} ${props.outbound ? 'outbound' : 'inbound'}`
            }, [
                m._pending
                    ? h('span', { class: 'pending-indicator' }, [
                        h('i', { class: 'v-icon notranslate $clockOutline', style: 'font-size:13px;width:13px;height:13px' })
                    ])
                    : null,
                icon
                    ? h('span', { class: `status-icon ${icon.class || ''}` }, [
                        h('i', { class: `v-icon notranslate ${icon.icon}`, style: 'font-size:13px;width:13px;height:13px' })
                    ])
                    : null,
            ])
        }
    }
})
// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    message: Record<string, any>
    direction?: 'inbound' | 'outbound'
    outbound?: boolean
    showCaption?: boolean
}>()

defineEmits<{
    (e: 'openImage', url: string): void
    (e: 'openList', msg: any): void
}>()

// ── Derived ───────────────────────────────────────────────────────────────────

const bodyText = computed(() => {
    const c = props.message.content
    return c.body?.text ?? c.body ?? ''
})

const isListType = computed(() =>
    props.message.content?.type === 'list' ||
    !!props.message.content?.action?.sections
)

const googleMapsUrl = computed(() => {
    const lat = props.message.content?.latitude
    const lng = props.message.content?.longitude
    return lat && lng ? `https://maps.google.com/?q=${lat},${lng}` : '#'
})

const docIcon = computed(() => {
    const name = props.message.content?.filename ?? ''
    const ext = name.split('.').pop()?.toLowerCase() ?? ''
    if (['doc', 'docx'].includes(ext)) return '$fileWordOutline'
    if (ext === 'pdf') return '$filePdfBox'
    if (['xls', 'xlsx'].includes(ext)) return '$fileExcelOutline'
    if (['ppt', 'pptx'].includes(ext)) return '$filePowerpointOutline'
    if (ext === 'txt') return '$fileDocumentOutline'
    return '$fileOutline'
})

const docExtLabel = computed(() => {
    const name = props.message.content?.filename ?? ''
    if (!name) return 'Document'
    return name.split('.').pop()?.toUpperCase() ?? 'FILE'
})

// Fixed 16-bar waveform heights derived from a sine curve (deterministic)
const waveformHeights = computed(() =>
    Array.from({ length: 16 }, (_, i) => ({
        key: i,
        height: Math.round(4 + Math.abs(Math.sin((i / 16) * Math.PI * 3)) * 12),
    }))
)

function contactName(contact: any): string {
    if (!contact) return 'Contact'
    if (typeof contact.name === 'string') return contact.name
    if (contact.name?.formatted_name) return contact.name.formatted_name
    const fn = contact.name?.first_name ?? ''
    const ln = contact.name?.last_name ?? ''
    return [fn, ln].filter(Boolean).join(' ') || 'Contact'
}

function linkify(text: string): string {
    return text.replace(
        /(https?:\/\/[^\s<>"']+)/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-primary">$1</a>'
    )
}
</script>

<style scoped lang="scss">
/* ── Image ────────────────────────────────────────────────────────────────── */
.wa-media-image-wrap {
    cursor: pointer;
    border-radius: 8px;
    overflow: hidden;
    max-width: 260px;
}

.wa-media-image {
    display: block;
    width: 100%;
    max-width: 260px;
    max-height: 200px;
    object-fit: cover;
    border-radius: 8px;
    transition: opacity 0.15s;

    &:hover {
        opacity: 0.9;
    }
}

.wa-media-placeholder {
    width: 260px;
    height: 160px;
    background: rgba(var(--v-theme-on-surface), 0.06);
    border-radius: 8px;
}

/* ── Video ────────────────────────────────────────────────────────────────── */
.wa-video-wrap {
    border-radius: 8px;
    overflow: hidden;
    max-width: 280px;
}

.wa-video-player {
    display: block;
    width: 100%;
    max-width: 280px;
    max-height: 200px;
    background: #000;
    border-radius: 8px;
}

.wa-video-placeholder {
    width: 280px;
    height: 160px;
    background: rgba(var(--v-theme-on-surface), 0.08);
    border-radius: 8px;
}

/* ── Audio ────────────────────────────────────────────────────────────────── */
.wa-audio-wrap {
    min-width: 200px;
    max-width: 280px;
}

.wa-audio-player {
    height: 32px;
    min-width: 0;

    &::-webkit-media-controls-panel {
        background: rgba(var(--v-theme-primary), 0.10) !important;
    }
}

.wa-audio-waveform {
    height: 32px;
}

.wa-wave-bar {
    display: inline-block;
    width: 3px;
    border-radius: 2px;
    background: currentColor;
    flex-shrink: 0;
}

/* ── Document ─────────────────────────────────────────────────────────────── */
.wa-doc-wrap {
    min-width: 180px;
    max-width: 260px;
}

.wa-doc-icon-box {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;

    &.inbound {
        background: rgba(var(--v-theme-primary), 0.10);
    }

    &.outbound {
        background: rgba(255, 255, 255, 0.18);
    }
}

/* ── Location ─────────────────────────────────────────────────────────────── */
.wa-location-wrap {
    border-radius: 8px;
    overflow: hidden;
    max-width: 260px;

    &:hover {
        opacity: 0.9;
    }
}

.wa-location-map {
    height: 110px;

    &.inbound {
        background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
    }

    &.outbound {
        background: rgba(255, 255, 255, 0.15);
    }
}

/* ── Sticker ──────────────────────────────────────────────────────────────── */
.wa-sticker {
    width: 100px;
    height: 100px;
    object-fit: contain;
    border-radius: 4px;
    display: block;
}

/* ── Text ─────────────────────────────────────────────────────────────────── */
.message-text {
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
}

/* ── Action buttons (inside list/buttons messages) ───────────────────────── */
.wa-action-btn {
    border-top: 1px solid rgba(var(--v-border-color), 0.12);
    color: #53bdeb;
    font-size: 0.875rem;
    cursor: pointer;
    background: transparent;
    border-left: none;
    border-right: none;
    border-bottom: none;
    transition: background 0.15s;

    &:hover {
        background: rgba(83, 189, 235, 0.08);
    }
}

.opacity-70 {
    opacity: 0.7;
}
</style>