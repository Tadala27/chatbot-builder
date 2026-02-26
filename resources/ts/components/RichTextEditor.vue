<template>
  <div class="whatsapp-editor-wrapper">
    <!-- Label -->
    <v-label v-if="label" class="mb-2 text-subtitle-1 font-weight-medium">
      {{ label }}
    </v-label>

    <!-- Editor Container -->
    <div
      ref="editorContainer"
      class="editor-container"
      :class="{
        'is-focused': isFocused,
        'has-error': errorMessages?.length || isOverLimit,
        'is-disabled': disabled,
      }"
      @click="focusEditor"
    >
      <!-- Floating Label -->
      <label
        class="editor-label"
        :class="{
          'editor-label-floating': isFocused || hasContent,
          'text-error': errorMessages?.length || isOverLimit,
        }"
      >
        {{ placeholder }}
      </label>

      <!-- Editor Content -->
      <editor-content :editor="editor" class="editor-content" />

      <!-- Formatting Toolbar -->
      <div
        v-if="editor && showFormatting"
        class="formatting-toolbar d-flex gap-6"
        @click.stop
      >
        <v-btn
          icon
          size="x-small"
          variant="tonal"
          :color="editor.isActive('whatsappBold') ? 'error' : 'default'"
          @click="toggleBold"
          :disabled="disabled || isOverLimit"
          :class="{ 'active-format': editor.isActive('whatsappBold') }"
          title="Bold - *text* (Ctrl+B)"
        >
          <v-icon>$formatBold</v-icon>
        </v-btn>

        <v-btn
          icon
          size="x-small"
          variant="tonal"
          :color="editor.isActive('whatsappItalic') ? 'error' : 'default'"
          @click="toggleItalic"
          :disabled="disabled || isOverLimit"
          :class="{ 'active-format': editor.isActive('whatsappItalic') }"
          title="Italic - _text_ (Ctrl+I)"
        >
          <v-icon>$formatItalic</v-icon>
        </v-btn>

        <v-btn
          icon
          size="x-small"
          variant="tonal"
          :color="editor.isActive('whatsappStrike') ? 'error' : 'default'"
          @click="toggleStrike"
          :disabled="disabled || isOverLimit"
          :class="{ 'active-format': editor.isActive('whatsappStrike') }"
          title="Strikethrough - ~text~ (Ctrl+Shift+X)"
        >
          <v-icon>$formatStrikethroughVariant</v-icon>
        </v-btn>

        <v-btn
          icon
          size="x-small"
          variant="tonal"
          :color="editor.isActive('whatsappCode') ? 'error' : 'default'"
          @click="toggleCode"
          :disabled="disabled || isOverLimit"
          :class="{ 'active-format': editor.isActive('whatsappCode') }"
          title="Monospace - `text` (Ctrl+E)"
        >
          <v-icon>$codeTags</v-icon>
        </v-btn>
      </div>

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
              variant="tonal"
              color="default"
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
          <span
            >WhatsApp {{ getWhatsAppLimitName() }} limit:
            {{ maxLength }} characters</span
          >
        </v-tooltip>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import { Editor, EditorContent } from "@tiptap/vue-3";
import { Node, Mark } from "@tiptap/core";

const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined;  // Allow null/undefined
    label?: string;
    placeholder?: string;
    hint?: string;
    errorMessages?: string | string[];
    availableVariables?: string[];
    showFormatting?: boolean;
    disabled?: boolean;
    hideDetails?: boolean;
    rules?: Array<(v: string) => string | boolean>;
    maxLength?: number; // Custom max length
    characterCountType?: "message" | "header" | "body" | "footer" | "button"; // WhatsApp context
  }>(),
  {
    placeholder: "Type here...",
    showFormatting: true,
    disabled: false,
    hideDetails: false,
    maxLength: 1024, // Default WhatsApp message limit
    characterCountType: "message",
  },
);

// Also update the emit to handle null
const emit = defineEmits<{
  (e: "update:modelValue", value: string): void;
  (e: "focus"): void;
  (e: "blur"): void;
}>();


// WhatsApp character limits
const WHATSAPP_LIMITS = {
  message: 1024, // Text message
  header: 60, // Header text
  body: 1024, // Body text
  footer: 60, // Footer text
  button: 20, // Button text
  listTitle: 24, // List item title
  listDescription: 72, // List item description
  sectionTitle: 24, // Section title
} as const;

// Get appropriate limit based on type
const getEffectiveMaxLength = computed(() => {
  if (props.maxLength) return props.maxLength;
  return WHATSAPP_LIMITS[props.characterCountType] || WHATSAPP_LIMITS.message;
});

// State
const editorContainer = ref<HTMLElement>();
const editor = ref<Editor | null>(null);
const isFocused = ref(false);
const hasContent = ref(false);
const variableMenu = ref(false);
const variableSearch = ref("");
const formatVariable = (variable: string) => `{{${variable}}}`;
const characterCount = ref(0);

// Computed
const filteredVariables = computed(() => {
  if (!props.availableVariables) return [];
  const query = variableSearch.value.toLowerCase().trim();
  if (!query) return props.availableVariables;
  return props.availableVariables.filter((v) =>
    v.toLowerCase().includes(query),
  );
});

const isOverLimit = computed(() => {
  return characterCount.value > getEffectiveMaxLength.value;
});

const showCharacterCount = computed(() => {
  return !props.hideDetails && getEffectiveMaxLength.value > 0;
});

// Helper to get limit name for tooltip
const getWhatsAppLimitName = () => {
  switch (props.characterCountType) {
    case "message":
      return "message";
    case "header":
      return "header";
    case "body":
      return "body";
    case "footer":
      return "footer";
    case "button":
      return "button";
    default:
      return "text";
  }
};

// WhatsApp Bold Mark (*text*)
const WhatsAppBold = Mark.create({
  name: "whatsappBold",
  priority: 1000,
  keepOnSplit: false,

  parseHTML() {
    return [
      {
        tag: "strong",
      },
      {
        style: "font-weight",
        getAttrs: (value) => value === "bold" && null,
      },
    ];
  },

  renderHTML() {
    return ["strong", 0];
  },

  addCommands() {
    return {
      toggleWhatsAppBold:
        () =>
        ({ commands }) => {
          return commands.toggleMark(this.name);
        },
    };
  },

  addKeyboardShortcuts() {
    return {
      "Mod-b": () => this.editor.commands.toggleMark(this.name),
    };
  },
});

// WhatsApp Italic Mark (_text_)
const WhatsAppItalic = Mark.create({
  name: "whatsappItalic",
  priority: 1000,
  keepOnSplit: false,

  parseHTML() {
    return [
      {
        tag: "em",
      },
      {
        style: "font-style",
        getAttrs: (value) => value === "italic" && null,
      },
    ];
  },

  renderHTML() {
    return ["em", 0];
  },

  addCommands() {
    return {
      toggleWhatsAppItalic:
        () =>
        ({ commands }) => {
          return commands.toggleMark(this.name);
        },
    };
  },

  addKeyboardShortcuts() {
    return {
      "Mod-i": () => this.editor.commands.toggleMark(this.name),
    };
  },
});

// WhatsApp Strike Mark (~text~)
const WhatsAppStrike = Mark.create({
  name: "whatsappStrike",
  priority: 1000,
  keepOnSplit: false,

  parseHTML() {
    return [
      {
        tag: "s",
      },
      {
        tag: "del",
      },
      {
        tag: "strike",
      },
      {
        style: "text-decoration",
        getAttrs: (value) => value === "line-through" && null,
      },
    ];
  },

  renderHTML() {
    return ["s", 0];
  },

  addCommands() {
    return {
      toggleWhatsAppStrike:
        () =>
        ({ commands }) => {
          return commands.toggleMark(this.name);
        },
    };
  },

  addKeyboardShortcuts() {
    return {
      "Mod-Shift-x": () => this.editor.commands.toggleMark(this.name),
    };
  },
});

// WhatsApp Code Mark (`text`)
const WhatsAppCode = Mark.create({
  name: "whatsappCode",
  priority: 1000,
  keepOnSplit: false,
  inclusive: false,

  parseHTML() {
    return [
      {
        tag: "code",
      },
    ];
  },

  renderHTML() {
    return ["code", 0];
  },

  addCommands() {
    return {
      toggleWhatsAppCode:
        () =>
        ({ commands }) => {
          return commands.toggleMark(this.name);
        },
    };
  },

  addKeyboardShortcuts() {
    return {
      "Mod-e": () => this.editor.commands.toggleMark(this.name),
    };
  },
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

// Base extension for paragraph and document
const Document = Node.create({
  name: "doc",
  topNode: true,
  content: "block+",
});

const Paragraph = Node.create({
  name: "paragraph",
  group: "block",
  content: "inline*",
  parseHTML() {
    return [{ tag: "p" }];
  },
  renderHTML() {
    return ["p", 0];
  },
});

const Text = Node.create({
  name: "text",
  group: "inline",
});

// Editor setup
onMounted(() => {
  editor.value = new Editor({
    content: parseValue(props.modelValue),
    extensions: [
      Document,
      Paragraph,
      Text,
      WhatsAppBold,
      WhatsAppItalic,
      WhatsAppStrike,
      WhatsAppCode,
      VariableNode,
    ],
    onUpdate: ({ editor }) => {
      const content = serializeEditorState(editor);
      const plainText = editor.state.doc.textContent;
      const newCharCount = plainText.length;

      // Check if we're over the limit
      if (newCharCount <= getEffectiveMaxLength.value) {
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

const toggleBold = () => {
  if (!isOverLimit.value) {
    editor.value?.chain().focus().toggleMark("whatsappBold").run();
  }
};

const toggleItalic = () => {
  if (!isOverLimit.value) {
    editor.value?.chain().focus().toggleMark("whatsappItalic").run();
  }
};

const toggleStrike = () => {
  if (!isOverLimit.value) {
    editor.value?.chain().focus().toggleMark("whatsappStrike").run();
  }
};

const toggleCode = () => {
  if (!isOverLimit.value) {
    editor.value?.chain().focus().toggleMark("whatsappCode").run();
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

// Serialize editor content to WhatsApp markdown format
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

          let text = node.text || "";

          // Apply WhatsApp formatting marks in correct order
          if (node.marks) {
            // Sort marks to ensure proper nesting
            const sortedMarks = [...node.marks].sort((a, b) => {
              const order = {
                whatsappBold: 1,
                whatsappItalic: 2,
                whatsappStrike: 3,
                whatsappCode: 4,
              };
              return (order[a.type] || 99) - (order[b.type] || 99);
            });

            sortedMarks.forEach((mark: any) => {
              switch (mark.type) {
                case "whatsappBold":
                  text = `*${text}*`;
                  break;
                case "whatsappItalic":
                  text = `_${text}_`;
                  break;
                case "whatsappStrike":
                  text = `~${text}~`;
                  break;
                case "whatsappCode":
                  text = `\`${text}\``;
                  break;
              }
            });
          }

          return text;
        })
        .join("");
    })
    .join("\n");
}

// Update the parseValue function to handle null/undefined
function parseValue(value: string | null | undefined): any {
  if (!value) {  // This will handle null, undefined, and empty string
    return {
      type: "doc",
      content: [{ type: "paragraph" }],
    };
  }


  const lines = value.split(/\r?\n/);

  const content = lines.map((line) => {
    const nodes: any[] = [];
    let currentPos = 0;

    // First extract variables: {{variable_name}}
    const variableRegex = /\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/g;
    const variableMatches = Array.from(line.matchAll(variableRegex));

    if (variableMatches.length === 0) {
      // No variables, just parse the formatted text
      nodes.push(...parseFormattedText(line));
    } else {
      // Has variables, need to split around them
      variableMatches.forEach((match) => {
        const [fullMatch, varName] = match;
        const matchPos = match.index!;

        // Add formatted text before variable
        if (matchPos > currentPos) {
          const textBefore = line.slice(currentPos, matchPos);
          nodes.push(...parseFormattedText(textBefore));
        }

        // Add variable node
        nodes.push({
          type: "variable",
          attrs: { name: varName },
        });

        currentPos = matchPos + fullMatch.length;
      });

      // Add remaining formatted text
      if (currentPos < line.length) {
        const textAfter = line.slice(currentPos);
        nodes.push(...parseFormattedText(textAfter));
      }
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

// Parse WhatsApp formatted text
function parseFormattedText(text: string): any[] {
  if (!text) return [];

  const nodes: any[] = [];
  let remaining = text;

  // Regex patterns for WhatsApp formatting
  const patterns = [
    { type: "whatsappBold", regex: /\*(.*?)\*/g }, // *bold*
    { type: "whatsappItalic", regex: /_(.*?)_/g }, // _italic_
    { type: "whatsappStrike", regex: /~(.*?)~/g }, // ~strike~
    { type: "whatsappCode", regex: /`(.*?)`/g }, // `code`
  ];

  let lastIndex = 0;
  let matches: {
    index: number;
    type: string;
    content: string;
    fullMatch: string;
  }[] = [];

  // Find all formatting matches
  patterns.forEach(({ type, regex }) => {
    let match;
    while ((match = regex.exec(text)) !== null) {
      matches.push({
        index: match.index,
        type,
        content: match[1],
        fullMatch: match[0],
      });
    }
  });

  // Sort matches by index
  matches.sort((a, b) => a.index - b.index);

  // Build nodes with proper nesting
  if (matches.length === 0) {
    // No formatting, just plain text
    return [{ type: "text", text }];
  }

  matches.forEach((match) => {
    // Add text before match
    if (match.index > lastIndex) {
      const textBefore = text.slice(lastIndex, match.index);
      if (textBefore) {
        nodes.push({ type: "text", text: textBefore });
      }
    }

    // Parse the inner content recursively for nested formatting
    const innerNodes = parseFormattedText(match.content);

    // Apply the mark to all inner nodes
    innerNodes.forEach((node) => {
      if (node.type === "text") {
        nodes.push({
          type: "text",
          text: node.text,
          marks: [...(node.marks || []), { type: match.type }],
        });
      } else {
        nodes.push(node);
      }
    });

    lastIndex = match.index + match.fullMatch.length;
  });

  // Add remaining text
  if (lastIndex < text.length) {
    const textAfter = text.slice(lastIndex);
    if (textAfter) {
      nodes.push({ type: "text", text: textAfter });
    }
  }

  return nodes;
}

// Cleanup
onBeforeUnmount(() => {
  editor.value?.destroy();
});
</script>

<style scoped>
.whatsapp-editor-wrapper {
  width: 100%;
}

.editor-container {
  position: relative;
  border: 1px solid #d1d5db;
  border-radius: 9px;
  padding: 16px 12px 36px;
  min-height: 140px;
  transition: border-color 0.2s ease;
  cursor: text;
}

.editor-container.is-focused {
  border-color: rgb(var(--v-theme-inputBorder));
}

.editor-container.has-error {
  border-color: rgb(var(--v-theme-error));
}

.editor-container.is-disabled {
  opacity: 0.6;
  cursor: not-allowed;
  pointer-events: none;
}

.gap-6 {
  gap: 1.5rem;
}

.editor-label {
  position: absolute;
  top: 16px;
  left: 25px;
  color: #9ca3af;
  font-size: 14px;
  pointer-events: none;
  transition: all 0.2s ease;
  padding: 0 4px;
  z-index: 1;
}

.editor-label-floating {
  top: -8px;
  font-size: 12px;
  background: rgb(var(--v-theme-containerBg));
}

.editor-content {
  width: 100%;
  min-height: 24px;
}

.formatting-toolbar {
  position: absolute;
  left: 4px;
  bottom: 4px;
  z-index: 5;
  display: flex;
  border-radius: 4px;
  padding: 4px;
}

.formatting-toolbar .v-btn {
  transition: all 0.2s ease;
}

.formatting-toolbar .v-btn.active-format {
  background-color: rgb(var(--v-theme-error), 0.12);
}

.formatting-toolbar .v-btn:hover {
  transform: translateY(-1px);
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
}

:deep(.ProseMirror strong) {
  font-weight: 700;
}

:deep(.ProseMirror em) {
  font-style: italic;
}

:deep(.ProseMirror s) {
  text-decoration: line-through;
}

:deep(.ProseMirror code) {
  background-color: #f5f5f5;
  padding: 2px 4px;
  border-radius: 3px;
  font-family: "Courier New", Courier, monospace;
  font-size: 0.9em;
  color: #d32f2f;
}

/* Dark mode support */
:deep(.dark) .editor-container {
  background: #1e1e1e;
  border-color: #404040;
}

:deep(.dark) .editor-label {
  background: #1e1e1e;
  color: #9ca3af;
}

:deep(.dark) .editor-label-floating {
  background: #1e1e1e;
}

:deep(.dark) .formatting-toolbar {
  background: rgba(30, 30, 30, 0.95);
}
</style>
