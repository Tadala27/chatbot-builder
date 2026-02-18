<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { EditorContent, useEditor } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import TaskList from "@tiptap/extension-task-list";
import TaskItem from "@tiptap/extension-task-item";
import Highlight from "@tiptap/extension-highlight";


const props = defineProps<{
    modelValue: string;
    placeholder?: string;
    rules?: Array<(v: string) => string | boolean>;
    label?: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'blur'): void;
    (e: 'focus'): void;
}>();

const isFocused = ref(false);
const editorContent = ref(props.modelValue || '');
const validationErrors = ref<string[]>([]);

// Editor setup
const editor = useEditor({
    editable: !props.disabled,
    extensions: [
        StarterKit,
        TaskList,
        TaskItem,
        Highlight,
        Placeholder.configure({
            placeholder: props.placeholder || "Start typing..."
        }),
    ],
    content: props.modelValue || '',
    onUpdate: ({ editor }) => {
        if (props.disabled) return;
        const html = editor.getHTML();
        editorContent.value = html;
        emit('update:modelValue', html);
        validateContent(html);
    },
    editorProps: {
        attributes: {
            class: props.disabled ? "editor-content disabled" : "editor-content"
        }
    }
});


// Function to strip HTML tags and get plain text
const stripHtml = (html: string): string => {
    if (!html) return '';
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent?.trim() || div.innerText?.trim() || '';
};

// Function to validate content against rules
const validateContent = (content: string) => {
    validationErrors.value = [];
    if (!props.rules || props.rules.length === 0) return;

    const plainText = stripHtml(content);
    const htmlLength = content.trim().length;

    for (const rule of props.rules) {
        const result = rule(content);
        if (typeof result === 'string') {
            validationErrors.value.push(result);
            break; // Only show first error
        }
    }
};

// Computed for has content
const hasContent = computed(() => {
    const plainText = stripHtml(props.modelValue || '');
    return plainText.length > 0 || props.modelValue?.trim().length > 0;
});

// Custom required rule for rich text
const richTextRequiredRule = (fieldName: string) => [
    (v: string) => {
        const content = v || '';
        const plainText = stripHtml(content);
        return plainText.length > 0 || `${fieldName} is required`;
    }
];

// Handle focus/blur
const focusEditor = (event: MouseEvent) => {
    if ((event.target as HTMLElement).closest(".editor-toolbar")) return;
    editor.value?.commands.focus();
    emit('focus');
};

const handleBlur = () => {
    isFocused.value = false;
    emit('blur');
    validateContent(props.modelValue || '');
};

const handleFocus = () => {
    isFocused.value = true;
    emit('focus');
};

// Toolbar actions
const toggleBold = () => editor.value?.chain().focus().toggleBold().run();
const toggleItalic = () => editor.value?.chain().focus().toggleItalic().run();
const toggleStrike = () => editor.value?.chain().focus().toggleStrike().run();
const toggleUnderline = () => editor.value?.chain().focus().toggleUnderline().run();
const toggleBulletList = () => editor.value?.chain().focus().toggleBulletList().run();
const toggleOrderedList = () => editor.value?.chain().focus().toggleOrderedList().run();
const toggleLink = () => {
    const url = prompt('Enter URL');
    if (url) {
        editor.value?.chain().focus().toggleLink({ href: url }).run();
    }
};

// Watch for external modelValue changes
watch(() => props.modelValue, (newVal) => {
    if (editor.value && newVal !== editor.value.getHTML()) {
        editor.value.commands.setContent(newVal || '', false);
        validateContent(newVal || '');
    }
}, { immediate: true });

watch(() => props.disabled, (isDisabled) => {
    if (editor.value) {
        editor.value.setEditable(!isDisabled);
    }
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<template>
    <div class="rich-text-input">
        <!-- Label -->
        <v-label v-if="label" class="mb-2 text-subtitle-1 font-weight-medium">
            {{ label }}
        </v-label>

        <!-- Editor Wrapper -->
        <div class="editor-wrapper" @click="focusEditor">
            <label class="editor-label" :class="{
                'editor-label-floating': isFocused || hasContent || props.modelValue?.trim(),
                'text-error': validationErrors.length > 0
            }">
                {{ props.placeholder }}
            </label>

            <EditorContent :editor="editor" @focus="handleFocus" @blur="handleBlur" />

            <!-- Toolbar -->
            <div class="editor-toolbar cursor-text" :class="{ disabled: props.disabled }">
                <button type="button" @click="toggleBold" :class="{ 'is-active': editor?.isActive('bold') }"
                    title="Bold">
                    <VIcon icon="$formatBold" size="18" />
                </button>
                <button type="button" @click="toggleItalic" :class="{ 'is-active': editor?.isActive('italic') }"
                    title="Italic">
                    <VIcon icon="$formatItalic" size="18" />
                </button>
                <button type="button" @click="toggleStrike" :class="{ 'is-active': editor?.isActive('strike') }"
                    title="Strikethrough">
                    <VIcon icon="$formatStrikethroughVariant" size="18" />
                </button>
                <button type="button" @click="toggleUnderline" :class="{ 'is-active': editor?.isActive('underline') }"
                    title="Underline">
                    <VIcon icon="$formatUnderline" size="18" />
                </button>
                <button type="button" @click="toggleBulletList" :class="{ 'is-active': editor?.isActive('bulletList') }"
                    title="Bullet List">
                    <VIcon icon="$formatListBulleted" size="18" />
                </button>
                <button type="button" @click="toggleOrderedList"
                    :class="{ 'is-active': editor?.isActive('orderedList') }" title="Ordered List">
                    <VIcon icon="$formatListNumbered" size="18" />
                </button>
                <button type="button" @click="toggleLink" :class="{ 'is-active': editor?.isActive('link') }"
                    title="Link">
                    <VIcon icon="$linkVariant" size="18" />
                </button>
            </div>
        </div>

        <!-- Validation Errors -->
        <div v-if="validationErrors.length > 0" class="text-error text-caption mt-1">
            {{ validationErrors[0] }}
        </div>
    </div>
</template>

<style scoped lang="scss">
.rich-text-input {
    .editor-wrapper {
        position: relative;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        padding: 16px 12px 60px;
        min-height: 200px;
        background: #fff;
        transition: border-color 0.2s ease;
        cursor: text;

        &.error {
            border-color: rgba(var(--v-theme-error));
        }
    }

    .editor-label {
        position: absolute;
        top: 16px;
        left: 16px;
        color: #9ca3af;
        font-size: 14px;
        pointer-events: none;
        transition: all 0.2s ease;
        background: #fff;
        padding: 0 4px;
        z-index: 1;
    }

    .editor-toolbar.disabled {
        pointer-events: none;
        opacity: 0.4;
    }

    .editor-label-floating {
        top: -8px;
        font-size: 12px;
    }

    .editor-toolbar {
        position: absolute;
        bottom: 8px;
        left: 12px;
        right: 12px;
        display: flex;
        gap: 4px;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        padding: 8px;

        button {
            border: none;
            background: transparent;
            padding: 6px;
            cursor: pointer;
            border-radius: 4px;
            color: #6b7280;
            transition: all 0.2s ease;

            &:hover {
                background: rgba(var(--v-theme-primary), 0.1);
                color: rgba(var(--v-theme-primary));
            }

            &.is-active {
                background: rgba(var(--v-theme-primary), 0.1);
                color: rgba(var(--v-theme-primary));
            }
        }
    }

    :deep(.editor-content) {
        min-height: 150px;
        outline: none;
        font-family: inherit;
        line-height: 1.6;

        p {
            margin-bottom: 1em;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            margin-top: 2em;
            margin-bottom: 1em;
        }

        ul,
        ol {
            padding-left: 2em;
        }

        code {
            background: #f3f4f6;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
        }

        pre {
            background: #f3f4f6;
            padding: 1em;
            border-radius: 6px;
            overflow-x: auto;
            margin: 1em 0;
        }

        a {
            color: rgba(var(--v-theme-primary));
            text-decoration: underline;
        }
    }


}
</style>