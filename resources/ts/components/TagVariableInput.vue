<script setup lang="ts">
import { ref, computed } from 'vue'

const props = withDefaults(defineProps<{
    modelValue: string[]
    availableVariables?: string[]
    placeholder?: string
    label?: string
}>(), {
    availableVariables: () => [],
    placeholder: 'Type and press Enter…',
    label: '',
})

const emit = defineEmits<{
    (e: 'update:modelValue', val: string[]): void
}>()

// ── Internal state ────────────────────────────────────────────────────────────
const inputText = ref('')
const inputEl = ref<HTMLInputElement>()
const showVarMenu = ref(false)
const varMenuAnchor = ref<HTMLElement>()
const varSearch = ref('')

// ── Variable menu ─────────────────────────────────────────────────────────────
const filteredVars = computed(() => {
    const q = varSearch.value.toLowerCase()
    if (!q) return props.availableVariables
    return props.availableVariables.filter(v => v.toLowerCase().includes(q))
})

function openVarMenu() {
    varSearch.value = ''
    showVarMenu.value = true
}

function pickVariable(name: string) {
    addTag(`{{${name}}}`)
    showVarMenu.value = false
    varSearch.value = ''
    inputEl.value?.focus()
}

// ── Tag management ────────────────────────────────────────────────────────────
function addTag(raw: string) {
    const t = raw.trim()
    if (!t) return
    // avoid duplicates
    if (props.modelValue.includes(t)) return
    emit('update:modelValue', [...props.modelValue, t])
}

function removeTag(i: number) {
    const next = [...props.modelValue]
    next.splice(i, 1)
    emit('update:modelValue', next)
}

function commitInput() {
    if (!inputText.value.trim()) return
    addTag(inputText.value)
    inputText.value = ''
}

function onKeydown(e: KeyboardEvent) {
    // Enter or comma → commit
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault()
        commitInput()
    }
    // Backspace on empty input → remove last tag
    if (e.key === 'Backspace' && inputText.value === '' && props.modelValue.length) {
        removeTag(props.modelValue.length - 1)
    }
}

// ── Chip display helpers ───────────────────────────────────────────────────────
function isVariable(tag: string) { return tag.startsWith('{{') && tag.endsWith('}}') }
function displayTag(tag: string) {
    return isVariable(tag) ? tag.slice(2, -2) : tag
}
</script>

<template>
    <div class="tvi-root">
        <!-- Optional label -->
        <p v-if="label" class="tvi-label">{{ label }}</p>

        <div class="tvi-box" @click="inputEl?.focus()">

            <!-- Chips -->
            <v-chip v-for="(tag, i) in modelValue" :key="tag + i" :color="isVariable(tag) ? 'primary' : undefined"
                :variant="isVariable(tag) ? 'tonal' : 'tonal'" size="small" closable class="tvi-chip"
                @click:close="removeTag(i)">
                <!-- Variable chips get the { } prefix icon -->
                <template v-if="isVariable(tag)">
                    <v-icon start size="12">$codeJson</v-icon>
                    {{ displayTag(tag) }}
                </template>
                <template v-else>
                    {{ displayTag(tag) }}
                </template>
            </v-chip>

            <!-- Invisible native input sits inline with the chips -->
            <input ref="inputEl" v-model="inputText" class="tvi-input"
                :placeholder="modelValue.length === 0 ? placeholder : ''" @keydown="onKeydown" @blur="commitInput" />

            <!-- Variable picker trigger -->
            <v-menu v-model="showVarMenu" :close-on-content-click="false" location="bottom start" offset="4">
                <template #activator="{ props: menuProps }">
                    <v-btn v-bind="menuProps" ref="varMenuAnchor" icon size="x-small" variant="text"
                        :color="availableVariables.length ? 'primary' : 'grey'" :disabled="!availableVariables.length"
                        class="tvi-var-btn" title="Insert variable" @click.stop="openVarMenu">
                        <SvgSprite name="custom-code-json" style="width:14px;height:14px" />
                    </v-btn>
                </template>

                <v-card min-width="200" max-width="280" elevation="4" rounded="lg">
                    <v-text-field v-model="varSearch" placeholder="Search variables…" prepend-inner-icon="$magnify"
                        variant="plain" density="compact" hide-details autofocus class="px-2 pt-2" />
                    <v-divider />
                    <v-list density="compact" class="py-1" max-height="220" style="overflow-y:auto">
                        <v-list-item v-for="v in filteredVars" :key="v" :value="v" rounded="lg"
                            @click="pickVariable(v)">
                            <template #prepend>
                                <v-icon size="14" color="primary" class="mr-1">$codeJson</v-icon>
                            </template>
                            <v-list-item-title class="text-caption font-weight-medium">{{ v }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item v-if="filteredVars.length === 0" disabled>
                            <v-list-item-title class="text-caption text-medium-emphasis">No variables
                                found</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-menu>

        </div>

        <p class="tvi-hint">
            Type and press <kbd>Enter</kbd> or <kbd>,</kbd> to add · Click <v-icon size="12"
                color="primary">$codeJson</v-icon>
            to insert a variable
        </p>
    </div>
</template>

<style scoped lang="scss">
.tvi-root {
    width: 100%;
}

.tvi-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: rgba(var(--v-theme-on-surface), 0.6);
    margin-bottom: 6px;
}

.tvi-box {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    min-height: 46px;
    padding: 6px 8px 6px 10px;
    border: 1px solid rgb(var(--v-theme-inputBorder));
    border-radius: 8px;
    cursor: text;
    transition: border-color 0.15s;
    background: transparent;

    &:focus-within {
        border-color: rgb(var(--v-theme-primary));
    }
}

.tvi-chip {
    // slight bottom margin so wrapping chips don't collide
    margin-bottom: 2px;
}

.tvi-input {
    flex: 1;
    min-width: 100px;
    border: none;
    outline: none;
    background: transparent;
    font-size: 0.82rem;
    color: rgb(var(--v-theme-on-surface));
    padding: 2px 4px;
    line-height: 1.5;

    &::placeholder {
        color: rgba(var(--v-theme-on-surface), 0.38);
    }
}

.tvi-var-btn {
    // keep it flush to the right of the input row
    flex-shrink: 0;
    margin-left: auto;
}

.tvi-hint {
    font-size: 0.7rem;
    color: rgba(var(--v-theme-on-surface), 0.4);
    margin-top: 4px;
    margin-bottom: 0;

    kbd {
        font-size: 0.65rem;
        padding: 1px 4px;
        border: 1px solid rgba(var(--v-theme-on-surface), 0.25);
        border-radius: 4px;
        font-family: inherit;
    }
}
</style>
