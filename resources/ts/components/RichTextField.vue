<template>
  <div class="variable-text-field">
    <!-- Label -->
    <v-label v-if="label" class="mb-2 text-subtitle-1 font-weight-medium">
      {{ label }}
    </v-label>

    <!-- Field Container -->
    <div
      ref="fieldContainer"
      class="field-container"
      :class="{
        'is-focused': isFocused,
        'has-error': errorMessages?.length || isOverLimit,
        'is-disabled': disabled,
      }"
      @click="focusEditor"
    >
      <!-- Floating Label -->
      <label
        class="field-label"
        :class="{
          'field-label-floating': isFocused || hasContent,
          'text-error': errorMessages?.length || isOverLimit,
        }"
      >
        {{ placeholder }}
      </label>

      <!-- Field Content -->
      <editor-content :editor="editor" class="field-content" />

      <!-- Variable Picker -->
      <div
        v-if="editor && availableVariables?.length"
        class="variable-picker"
        @click.stop
      >
        <v-menu v-model="variableMenu" :close-on-content-click="false">
          <template #activator="{ props }">
            <v-btn
              v-bind="props"
              icon
              size="x-small"
              variant="text"
              :disabled="disabled || isOverLimit"
              title="Insert Variable {{var}}"
            >
              <v-icon>$xml</v-icon>
            </v-btn>
          </template>

          <v-card min-width="250" max-width="300">
            <v-card-text class="pa-2">
              <v-text-field
                v-model="variableSearch"
                placeholder="Search variables..."
                density="compact"
                variant="outlined"
                hide-details
                prepend-inner-icon="$magnify"
                class="mb-2"
              />

              <v-list
                density="compact"
                style="max-height: 250px; overflow-y: auto"
              >
                <v-list-item
                  v-for="variable in filteredVariables"
                  :key="variable"
                  @click="insertVariable(variable)"
                  class="cursor-pointer"
                >
                  <v-list-item-title>
                    <v-chip size="small" color="primary" variant="tonal">
                      {{ formatVariable(variable) }}
                    </v-chip>
                  </v-list-item-title>
                </v-list-item>

                <v-list-item v-if="filteredVariables.length === 0">
                  <v-list-item-title class="text-grey">
                    No variables found
                  </v-list-item-title>
                </v-list-item>
              </v-list>
            </v-card-text>
          </v-card>
        </v-menu>
      </div>
    </div>

    <!-- Character Count and Error Messages -->
    <div class="d-flex justify-space-between align-start mt-1">
      <div>
        <!-- Error Messages -->
        <div
          v-if="errorMessages?.length && !hideDetails"
          class="text-error text-caption ml-3"
        >
          {{ Array.isArray(errorMessages) ? errorMessages[0] : errorMessages }}
        </div>

        <!-- Hint -->
        <div
          v-if="hint && !errorMessages?.length && !hideDetails"
          class="text-caption ml-3"
        >
          {{ hint }}
        </div>
      </div>

      <!-- Character Counter -->
      <div
        v-if="showCharacterCount"
        class="text-caption mr-3"
        :class="{
          'text-error': isOverLimit,
          'text-disabled': !isOverLimit && characterCount > 0,
          'text-grey': characterCount === 0,
        }"
      >
        {{ characterCount }} / {{ maxLength }} characters
        <v-tooltip v-if="maxLength" location="top">
          <template #activator="{ props }">
            <v-icon
              v-bind="props"
              size="x-small"
              class="ml-1"
              :color="isOverLimit ? 'error' : 'grey'"
            >
              $information
            </v-icon>
          </template>
          <span>Maximum {{ maxLength }} characters</span>
        </v-tooltip>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { Editor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import { Node } from "@tiptap/core";

const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined;
    label?: string;
    placeholder?: string;
    hint?: string;
    errorMessages?: string | string[];
    availableVariables?: string[];
    disabled?: boolean;
    hideDetails?: boolean;
    maxLength?: number;
    fieldType?:
      | "header"
      | "body"
      | "footer"
      | "button"
      | "title"
      | "description";
  }>(),
  {
    disabled: false,
    hideDetails: false,
    maxLength: 255, // Default max length
    fieldType: "body",
  },
);

// Also update the emit to handle null
const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
  (e: "focus"): void;
  (e: "blur"): void;
}>();

// Field-specific max lengths
const FIELD_LIMITS = {
  header: 60,
  body: 1024,
  footer: 60,
  button: 20,
  title: 24,
  description: 72,
} as const;

// State
const fieldContainer = ref<HTMLElement>();
const editor = ref<Editor | null>(null);
const isFocused = ref(false);
const hasContent = ref(false);
const variableMenu = ref(false);
const variableSearch = ref("");
const characterCount = ref(0);

// Computed
const formatVariable = (variable: string) => `{{${variable}}}`;

const filteredVariables = computed(() => {
  if (!props.availableVariables) return [];
  const query = variableSearch.value.toLowerCase().trim();
  if (!query) return props.availableVariables;
  return props.availableVariables.filter((v) =>
    v.toLowerCase().includes(query),
  );
});

const effectiveMaxLength = computed(() => {
  if (props.maxLength) return props.maxLength;
  return FIELD_LIMITS[props.fieldType] || 255;
});

const isOverLimit = computed(() => {
  return characterCount.value > effectiveMaxLength.value;
});

const showCharacterCount = computed(() => {
  return !props.hideDetails && effectiveMaxLength.value > 0;
});

// Custom Variable Node Extension
const VariableNode = Node.create({
  name: "variable",
  group: "inline",
  inline: true,
  atom: true,

  addAttributes() {
    return {
      name: {
        default: "",
      },
    };
  },

  parseHTML() {
    return [
      {
        tag: "span[data-variable]",
      },
    ];
  },

  renderHTML({ node }) {
    return [
      "span",
      {
        "data-variable": node.attrs.name,
        class: "variable-badge",
        contenteditable: "false",
      },
      `{{${node.attrs.name}}}`,
    ];
  },

  addKeyboardShortcuts() {
    return {
      Backspace: () =>
        this.editor.commands.command(({ tr, state }) => {
          const { selection } = state;
          const { empty, $from } = selection;

          if (!empty) return false;

          const node = $from.nodeBefore;
          if (node && node.type.name === "variable") {
            tr.delete($from.pos - node.nodeSize, $from.pos);
            return true;
          }
          return false;
        }),
      Delete: () =>
        this.editor.commands.command(({ tr, state }) => {
          const { selection } = state;
          const { empty, $from } = selection;

          if (!empty) return false;

          const node = $from.nodeAfter;
          if (node && node.type.name === "variable") {
            tr.delete($from.pos, $from.pos + node.nodeSize);
            return true;
          }
          return false;
        }),
    };
  },
});

// Editor setup
onMounted(() => {
  editor.value = new Editor({
    content: parseValue(props.modelValue),
    extensions: [
      StarterKit.configure({
        bold: true,
        italic: true,
        strike: true,
        code: true,
        hardBreak: false,
        paragraph: {
          HTMLAttributes: {
            class: "field-paragraph",
          },
        },
      }),
      VariableNode,
    ],
    onUpdate: ({ editor }) => {
      const plainText = editor.state.doc.textContent;
      const newCharCount = plainText.length;

      // Check if we're over the limit
      if (newCharCount <= effectiveMaxLength.value) {
        const content = serializeEditorState(editor);
        emit("update:modelValue", content);
        characterCount.value = newCharCount;
      } else {
        // If over limit, revert to previous state
        editor.commands.setContent(parseValue(props.modelValue));
      }

      hasContent.value = editor.state.doc.textContent.length > 0;
    },
    onFocus: () => {
      isFocused.value = true;
      emit("focus");
    },
    onBlur: () => {
      isFocused.value = false;
      emit("blur");
    },
    editorProps: {
      attributes: {
        class: "prose prose-sm max-w-none focus:outline-none",
      },
    },
    editable: !props.disabled,
  });

  // Set initial hasContent and character count
  hasContent.value = editor.value.state.doc.textContent.length > 0;
  characterCount.value = editor.value.state.doc.textContent.length;
});

// Watch disabled prop
watch(
  () => props.disabled,
  (disabled) => {
    editor.value?.setEditable(!disabled);
  },
);

// Watch for external changes
watch(
  () => props.modelValue,
  (newValue) => {
    if (editor.value && newValue !== serializeEditorState(editor.value)) {
      editor.value.commands.setContent(parseValue(newValue));
      hasContent.value = editor.value.state.doc.textContent.length > 0;
      characterCount.value = editor.value.state.doc.textContent.length;
    }
  },
);

// Methods
const focusEditor = () => {
  if (!props.disabled && !isOverLimit.value) {
    editor.value?.commands.focus();
  }
};

const insertVariable = (variable: string) => {
  if (!isOverLimit.value) {
    editor.value
      ?.chain()
      .focus()
      .insertContent({
        type: "variable",
        attrs: { name: variable },
      })
      .run();

    variableMenu.value = false;
    variableSearch.value = "";
  }
};

// Serialize editor content
function serializeEditorState(editor: Editor): string {
  const json = editor.getJSON();

  return (json.content || [])
    .map((block: any) => {
      if (!block || !block.content) return "";

      return block.content
        .map((node: any) => {
          if (node.type === "variable") {
            return `{{${node.attrs.name}}}`;
          }

          return node.text || "";
        })
        .join("");
    })
    .join("\n");
}

// Parse value to editor content
function parseValue(value: string | null | undefined): any {
  if (!value) {
    return {
      type: "doc",
      content: [{ type: "paragraph" }],
    };
  }

  const lines = value.split(/\r?\n/);

  const content = lines.map((line) => {
    const nodes: any[] = [];
    let currentPos = 0;

    // Match variables: {{variable_name}}
    const variableRegex = /\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/g;
    const matches = Array.from(line.matchAll(variableRegex));

    if (matches.length === 0 && line.length === 0) {
      return { type: "paragraph" };
    }

    matches.forEach((match) => {
      const [fullMatch, varName] = match;
      const matchPos = match.index!;

      // Add text before variable
      if (matchPos > currentPos) {
        const textBefore = line.slice(currentPos, matchPos);
        nodes.push({ type: "text", text: textBefore });
      }

      // Add variable node
      nodes.push({
        type: "variable",
        attrs: { name: varName },
      });

      currentPos = matchPos + fullMatch.length;
    });

    // Add remaining text
    if (currentPos < line.length) {
      const textAfter = line.slice(currentPos);
      nodes.push({ type: "text", text: textAfter });
    }

    return {
      type: "paragraph",
      content: nodes.length > 0 ? nodes : undefined,
    };
  });

  return {
    type: "doc",
    content,
  };
}

// Cleanup
onBeforeUnmount(() => {
  editor.value?.destroy();
});
</script>

<style scoped>
.variable-text-field {
  width: 100%;
}

.field-container {
  position: relative;
  border: 1px solid #d1d5db;
  border-radius: 9px;
  padding-left: 12px;
  min-height: max(
    var(--v-input-control-height, 56px),
    1.5rem + var(--v-field-input-padding-top) +
      var(--v-field-input-padding-bottom)
  );
  transition: border-color 0.2s ease;
  cursor: text;
}

.field-container.is-focused {
  border-color: rgb(var(--v-theme-inputBorder));
}

.field-container.has-error {
  border-color: rgb(var(--v-theme-error));
}

.field-container.is-disabled {
  opacity: 0.6;
  cursor: not-allowed;
  pointer-events: none;
}

.field-label {
  position: absolute;
  top: 10px;
  left: 25px;
  color: #9ca3af;
  font-size: 14px;
  pointer-events: none;
  transition: all 0.2s ease;
  padding: 0 4px;
  z-index: 1;
}

.field-label-floating {
  top: -8px;
  font-size: 12px;
  background: rgb(var(--v-theme-containerBg));
}

.field-content {
  width: 100%;
  min-height: 24px;
  margin-top: 6px;
  margin-bottom: 6px;
}

.variable-picker {
  position: absolute;
  right: 4px;
  bottom: 4px;
  z-index: 5;
}

.cursor-pointer {
  cursor: pointer;
}

:deep(.variable-badge) {
  background-color: #e3f2fd;
  border: 1px solid rgb(var(--v-theme-primary));
  border-radius: 4px;
  padding: 2px 8px;
  margin: 0 2px;
  display: inline-block;
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
  font-size: 0.9em;
  cursor: default;
  user-select: none;
}

:deep(.ProseMirror) {
  min-height: 24px;
  outline: none;
  padding: 4px 0;
}

:deep(.ProseMirror p) {
  margin: 0;
  padding: 2px 0;
  min-height: 1.5em;
  padding-inline: var(--v-field-padding-start) var(--v-field-padding-end);
  padding-top: var(--v-field-input-padding-top);
  padding-bottom: var(--v-field-input-padding-bottom);
}

/* Dark mode support */
:deep(.dark) .field-container {
  background: #1e1e1e;
  border-color: #404040;
}

:deep(.dark) .field-label {
  background: #1e1e1e;
  color: #9ca3af;
}

:deep(.dark) .field-label-floating {
  background: #1e1e1e;
}
</style>
