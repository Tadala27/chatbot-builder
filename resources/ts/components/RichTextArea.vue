<!-- WhatsAppTextarea.vue -->
<template>
    <div class="whatsapp-textarea-wrapper">
        <!-- Textarea with formatting overlay -->
        <div ref="containerRef" class="whatsapp-textarea-container" :class="{
            'is-focused': isFocused,
            'is-disabled': disabled,
            'has-error': errorMessages?.length,
            'with-formatting': showFormatting,
            [`variant-${variant}`]: true,
        }" @click="focusTextarea">
            <!-- Textarea -->
            <textarea ref="textareaRef" v-model="localValue" class="whatsapp-textarea" :placeholder="placeholder"
                :disabled="disabled" :rows="rows" :max-rows="maxRows" @input="onInput" @keydown="onKeyDown"
                @focus="onFocus" @blur="onBlur" @compositionstart="onCompositionStart"
                @compositionend="onCompositionEnd" />


            <!-- Bottom bar with formatting toolbar and append slots -->
            <div class="bottom-bar">
                <div class="left-actions">
                    <!-- Formatting Toolbar (constant) -->
                    <div v-if="showFormatting && !disabled" class="formatting-toolbar">
                        <v-btn icon size="x-small" variant="tonal" :color="activeFormat.bold ? 'error' : 'default'"
                            @click.stop="toggleFormat('bold')" :disabled="disabled"
                            :class="{ 'active-format': activeFormat.bold }" title="Bold - *text* (Ctrl+B)">
                            <v-icon>$formatBold</v-icon>
                        </v-btn>
                        <v-divider vertical class="mx-1" />
                        <v-btn icon size="x-small" variant="tonal" :color="activeFormat.italic ? 'error' : 'default'"
                            @click.stop="toggleFormat('italic')" :disabled="disabled"
                            :class="{ 'active-format': activeFormat.italic }" title="Italic - _text_ (Ctrl+I)">
                            <v-icon>$formatItalic</v-icon>
                        </v-btn>
                        <v-divider vertical class="mx-1" />
                        <v-btn icon size="x-small" variant="tonal" :color="activeFormat.strike ? 'error' : 'default'"
                            @click.stop="toggleFormat('strike')" :disabled="disabled"
                            :class="{ 'active-format': activeFormat.strike }"
                            title="Strikethrough - ~text~ (Ctrl+Shift+X)">
                            <v-icon>$formatStrikethroughVariant</v-icon>
                        </v-btn>
                        <v-divider vertical class="mx-1" />
                        <v-btn icon size="x-small" variant="tonal" :color="activeFormat.code ? 'error' : 'default'"
                            @click.stop="toggleFormat('code')" :disabled="disabled"
                            :class="{ 'active-format': activeFormat.code }" title="Monospace - `text` (Ctrl+E)">
                            <v-icon>$codeTags</v-icon>
                        </v-btn>

                        <v-divider vertical class="mx-1" />

                    </div>
                </div>

                <div v-if="$slots.append" class="append">
                    <slot name="append" />
                </div>
            </div>
        </div>

        <!-- Character Count and Error Messages (like VTextarea) -->
        <div v-if="!hideDetails" class="details-row">
            <div class="error-hint">
                <!-- Error Messages -->
                <div v-if="errorMessages?.length" class="text-error text-caption ml-3">
                    {{ Array.isArray(errorMessages) ? errorMessages[0] : errorMessages }}
                </div>

                <!-- Hint -->
                <div v-else-if="hint" class="text-caption ml-3" :class="{ 'text-grey': true }">
                    {{ hint }}
                </div>
            </div>

            <!-- Character Counter (like VTextarea) -->
            <div v-if="showCharacterCount" class="text-caption mr-3" :class="{
                'text-error': isOverLimit,
                'text-grey': !isOverLimit,
            }">
                {{ characterCount }} / {{ effectiveMaxLength }}
                <v-tooltip v-if="maxLength" location="top">
                    <template #activator="{ props }">
                        <v-icon v-bind="props" size="x-small" class="ml-1" :color="isOverLimit ? 'error' : 'grey'">
                            $information
                        </v-icon>
                    </template>
                    <span>WhatsApp limit: {{ effectiveMaxLength }} characters</span>
                </v-tooltip>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'

const props = withDefaults(
    defineProps<{
        modelValue: string
        placeholder?: string
        hint?: string
        errorMessages?: string | string[]
        disabled?: boolean
        hideDetails?: boolean
        rows?: number | string
        maxRows?: number | string
        autoGrow?: boolean
        maxLength?: number
        showFormatting?: boolean
        variant?: 'underlined' | 'outlined' | 'filled' | 'plain'
    }>(),
    {
        placeholder: '',
        disabled: false,
        hideDetails: false,
        rows: 1,
        maxRows: 4,
        autoGrow: true,
        maxLength: 1024,
        showFormatting: true,
        variant: 'underlined',
    },
)

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
    (e: 'focus'): void
    (e: 'blur'): void
    (e: 'keydown', event: KeyboardEvent): void
}>()

// WhatsApp character limits
const WHATSAPP_LIMIT = 1024

// Refs
const textareaRef = ref<HTMLTextAreaElement>()
const containerRef = ref<HTMLElement>()
const localValue = ref(props.modelValue)
const isFocused = ref(false)
const isComposing = ref(false)
const selectionStart = ref(0)
const selectionEnd = ref(0)
const typingPosition = ref<number | null>(null)

// Format state
const activeFormat = ref({
    bold: false,
    italic: false,
    strike: false,
    code: false,
})

// Computed
const effectiveMaxLength = computed(() => props.maxLength || WHATSAPP_LIMIT)

const characterCount = computed(() => localValue.value.length)

const isOverLimit = computed(() => characterCount.value > effectiveMaxLength.value)

const showCharacterCount = computed(() => !props.hideDetails && effectiveMaxLength.value > 0)

const hasSelection = computed(() => selectionEnd.value > selectionStart.value)

const selectionLength = computed(() => selectionEnd.value - selectionStart.value)

const hasAnyFormatting = computed(() =>
    activeFormat.value.bold || activeFormat.value.italic ||
    activeFormat.value.strike || activeFormat.value.code
)

// Update local value when modelValue changes externally
watch(() => props.modelValue, (newVal) => {
    localValue.value = newVal
})

// Auto-grow functionality
const adjustHeight = () => {
    if (!props.autoGrow || !textareaRef.value) return

    const textarea = textareaRef.value
    textarea.style.height = 'auto'

    const maxHeight = typeof props.maxRows === 'number'
        ? props.maxRows * 24 // Approximate row height
        : parseInt(String(props.maxRows)) * 24

    const newHeight = Math.min(textarea.scrollHeight, maxHeight)
    textarea.style.height = `${newHeight}px`
}

// Input handling
const onInput = (event: Event) => {
    const target = event.target as HTMLTextAreaElement
    let newValue = target.value

    // Enforce max length
    if (newValue.length > effectiveMaxLength.value) {
        newValue = newValue.slice(0, effectiveMaxLength.value)
    }

    localValue.value = newValue
    emit('update:modelValue', newValue)

    if (props.autoGrow) {
        adjustHeight()
    }

    // Update cursor position
    typingPosition.value = target.selectionStart

    // Check for formatting at cursor
    detectFormattingAtCursor()
}

const onKeyDown = (event: KeyboardEvent) => {
    // Handle keyboard shortcuts
    if (!props.disabled && !isComposing.value) {
        if (event.ctrlKey || event.metaKey) {
            switch (event.key.toLowerCase()) {
                case 'b':
                    event.preventDefault()
                    toggleFormat('bold')
                    break
                case 'i':
                    event.preventDefault()
                    toggleFormat('italic')
                    break
                case 'e':
                    event.preventDefault()
                    toggleFormat('code')
                    break
                case 'x':
                    if (event.shiftKey) {
                        event.preventDefault()
                        toggleFormat('strike')
                    }
                    break
            }
        }
    }

    emit('keydown', event)
}

const onFocus = () => {
    isFocused.value = true
    emit('focus')
}

const onBlur = () => {
    isFocused.value = false
    emit('blur')
}

const onCompositionStart = () => {
    isComposing.value = true
}

const onCompositionEnd = () => {
    isComposing.value = false
}

const focusTextarea = () => {
    if (!props.disabled) {
        textareaRef.value?.focus()
    }
}

// Format detection
const detectFormattingAtCursor = () => {
    if (!textareaRef.value) return

    const text = localValue.value
    const cursorPos = textareaRef.value.selectionStart

    // Look backwards for formatting markers
    const beforeCursor = text.slice(0, cursorPos)

    // Check for bold (*)
    const boldMatches = beforeCursor.match(/\*[^*]*$/g)
    activeFormat.value.bold = boldMatches ? boldMatches[0].length > 1 : false

    // Check for italic (_)
    const italicMatches = beforeCursor.match(/_[^_]*$/g)
    activeFormat.value.italic = italicMatches ? italicMatches[0].length > 1 : false

    // Check for strike (~)
    const strikeMatches = beforeCursor.match(/~[^~]*$/g)
    activeFormat.value.strike = strikeMatches ? strikeMatches[0].length > 1 : false

    // Check for code (`)
    const codeMatches = beforeCursor.match(/`[^`]*$/g)
    activeFormat.value.code = codeMatches ? codeMatches[0].length > 1 : false
}

// Format toggle functions
const toggleFormat = (format: 'bold' | 'italic' | 'strike' | 'code') => {
    if (!textareaRef.value || props.disabled) return

    const textarea = textareaRef.value
    const start = textarea.selectionStart
    const end = textarea.selectionEnd
    const text = localValue.value

    let marker: string

    switch (format) {
        case 'bold':
            marker = '*'
            break
        case 'italic':
            marker = '_'
            break
        case 'strike':
            marker = '~'
            break
        case 'code':
            marker = '`'
            break
    }

    if (start === end) {
        // No selection - insert markers at cursor
        const newText = text.slice(0, start) + marker + marker + text.slice(start)
        localValue.value = newText
        emit('update:modelValue', newText)

        // Position cursor between markers
        nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.selectionStart = start + 1
                textareaRef.value.selectionEnd = start + 1
            }
        })
    } else {
        // Has selection - wrap selection with markers
        const selectedText = text.slice(start, end)

        // Check if already formatted
        const isFormatted =
            text.slice(start - 1, start) === marker &&
            text.slice(end, end + 1) === marker

        let newText: string

        if (isFormatted) {
            // Remove formatting
            newText =
                text.slice(0, start - 1) +
                selectedText +
                text.slice(end + 1)

            nextTick(() => {
                if (textareaRef.value) {
                    textareaRef.value.selectionStart = start - 1
                    textareaRef.value.selectionEnd = end - 1
                }
            })
        } else {
            // Apply formatting
            newText =
                text.slice(0, start) +
                marker + selectedText + marker +
                text.slice(end)

            nextTick(() => {
                if (textareaRef.value) {
                    textareaRef.value.selectionStart = start
                    textareaRef.value.selectionEnd = end + 2
                }
            })
        }

        localValue.value = newText
        emit('update:modelValue', newText)

        // Toggle active state
        activeFormat.value[format] = !isFormatted
    }

    if (props.autoGrow) {
        adjustHeight()
    }
}

const clearFormatting = () => {
    if (!textareaRef.value || props.disabled) return

    const start = textareaRef.value.selectionStart
    const end = textareaRef.value.selectionEnd
    const text = localValue.value

    if (start === end) {
        // Just remove markers around cursor
        const before = text.slice(0, start)
        const after = text.slice(start)

        // Remove single markers
        const cleaned = before.replace(/[*_~`]$/, '') + after.replace(/^[*_~`]/, '')

        localValue.value = cleaned
        emit('update:modelValue', cleaned)

        nextTick(() => {
            if (textareaRef.value) {
                textareaRef.value.selectionStart = start - 1
                textareaRef.value.selectionEnd = start - 1
            }
        })
    } else {
        // Remove all formatting from selection
        const selectedText = text.slice(start, end)
        const cleaned = selectedText.replace(/[*_~`]/g, '')

        const newText = text.slice(0, start) + cleaned + text.slice(end)
        localValue.value = newText
        emit('update:modelValue', newText)
    }

    // Reset active formats
    activeFormat.value = {
        bold: false,
        italic: false,
        strike: false,
        code: false,
    }

    if (props.autoGrow) {
        adjustHeight()
    }
}

// Track selection changes
const trackSelection = () => {
    if (textareaRef.value && isFocused.value) {
        selectionStart.value = textareaRef.value.selectionStart
        selectionEnd.value = textareaRef.value.selectionEnd
        typingPosition.value = textareaRef.value.selectionStart
        detectFormattingAtCursor()
    }
}

// Lifecycle
onMounted(() => {
    if (props.autoGrow) {
        adjustHeight()
    }

    document.addEventListener('selectionchange', trackSelection)
})

onBeforeUnmount(() => {
    document.removeEventListener('selectionchange', trackSelection)
})

// Expose methods if needed
defineExpose({
    focus: () => textareaRef.value?.focus(),
    blur: () => textareaRef.value?.blur(),
    select: () => textareaRef.value?.select(),
})
</script>

<style scoped>
.whatsapp-textarea-wrapper {
    position: relative;
    width: 100%;
}

.whatsapp-textarea-container {
    position: relative;
    border-radius: 8px;
    transition: all 0.2s ease;
    min-height: 42px;
    padding-bottom: 40px;
    /* Make space for bottom bar */
}

/* Variant styles */
.variant-underlined {
    border-bottom: 1px solid rgb(var(--v-theme-inputBorder));
    border-radius: 4px 4px 0 0;
}

.variant-underlined.is-focused {
    border-bottom-color: rgb(var(--v-theme-inputBorder));
}

.variant-underlined.has-error {
    border-bottom-color: rgb(var(--v-theme-error));
}

.variant-outlined {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}

.variant-outlined.is-focused {
    border-color: rgb(var(--v-theme-primary));
}

.variant-outlined.has-error {
    border-color: rgb(var(--v-theme-error));
}

.variant-filled {
    background: #f5f5f5;
    border-radius: 8px 8px 0 0;
}

.variant-filled.is-focused {
    background: #eeeeee;
}

.variant-plain {
    border: none;
    background: transparent;
}

.whatsapp-textarea {
    width: 100%;
    padding: 12px 16px;
    font-family: inherit;
    font-size: 14px;
    line-height: 1.5;
    border: none;
    background: transparent;
    resize: none;
    outline: none;
    min-height: 42px;
}

.whatsapp-textarea:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Bottom bar - constant at the bottom */
.bottom-bar {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 4px 8px;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background: inherit;
    z-index: 15;
    min-height: 36px;
}

.left-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.append {
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Formatting Indicator */
.formatting-indicator {
    position: absolute;
    top: -20px;
    right: 8px;
    background: rgba(0, 0, 0, 0.6);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    pointer-events: none;
    transition: opacity 0.2s;
    opacity: 0;
    z-index: 20;
}

.formatting-indicator.has-selection,
.whatsapp-textarea-container.is-focused .formatting-indicator {
    opacity: 1;
}

.selection-info {
    color: #fff;
}

.cursor-position {
    display: flex;
    gap: 4px;
}

.format-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 0 4px;
    border-radius: 4px;
    font-weight: 600;
}

/* Formatting Toolbar - now always visible in bottom bar */
.formatting-toolbar {
    display: flex;
    gap: 2px;
    padding: 2px;
}

.formatting-toolbar .v-btn {
    min-width: 28px;
    height: 28px;
    transition: all 0.2s ease;
}

.formatting-toolbar .v-btn.active-format {
    background-color: rgba(var(--v-theme-error), 0.12);
}

.formatting-toolbar .v-btn:hover {
    transform: translateY(-1px);
}

/* Details row (like VTextarea) */
.details-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-top: 4px;
    padding: 0 12px;
}

.error-hint {
    flex: 1;
}

/* Dark mode */
:deep(.dark) .formatting-toolbar {
    background: transparent;
}

:deep(.dark) .variant-filled {
    background: #2c2c2c;
}

:deep(.dark) .variant-underlined {
    border-bottom-color: #404040;
}

:deep(.dark) .formatting-indicator {
    background: rgba(255, 255, 255, 0.2);
    color: #000;
}

:deep(.dark) .selection-info {
    color: #000;
}

:deep(.dark) .bottom-bar {
    border-top-color: #404040;
}
</style>