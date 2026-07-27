<!-- RichTextField.vue -->

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
      :class="[
        `field-container--density-${density}`,
        `field-container--variant-${variant}`,
        {
          'is-focused': isFocused,
          'has-error': errorMessages?.length || isOverLimit,
          'is-disabled': disabled,
        },
      ]"
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

      <!-- Pickers row — positioned at bottom-right like original variable picker -->
      <div class="pickers-row" @click.stop>
        <!-- Variable Picker -->
        <div v-if="editor && availableVariables?.length">
          <v-menu v-model="variableMenu" :close-on-content-click="false">
            <template #activator="{ props: menuProps }">
              <v-btn
                v-bind="menuProps"
                icon
                size="x-small"
                variant="text"
                :disabled="disabled || isOverLimit"
                title="Insert Variable"
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
                    class="cursor-pointer"
                    @click="insertVariable(variable)"
                  >
                    <v-list-item-title>
                      <v-chip size="small" color="primary" variant="tonal">
                        ${{ variable }}
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

        <!-- Function Picker -->
        <div v-if="editor && availableFunctions?.length">
          <v-menu v-model="functionMenu" :close-on-content-click="false">
            <template #activator="{ props: menuProps }">
              <v-btn
                v-bind="menuProps"
                icon
                size="x-small"
                variant="text"
                :disabled="disabled || isOverLimit"
                title="Insert Function"
              >
                <v-icon>$function</v-icon>
              </v-btn>
            </template>

            <v-card min-width="250" max-width="300">
              <v-card-text class="pa-2">
                <v-text-field
                  v-model="functionSearch"
                  placeholder="Search functions..."
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
                    v-for="fn in filteredFunctions"
                    :key="fn"
                    class="cursor-pointer"
                    @click="insertFunction(fn)"
                  >
                    <v-list-item-title>
                      <v-chip size="small" color="secondary" variant="tonal">
                        f(x){{ fn }}
                      </v-chip>
                    </v-list-item-title>
                  </v-list-item>

                  <v-list-item v-if="filteredFunctions.length === 0">
                    <v-list-item-title class="text-grey">
                      No functions found
                    </v-list-item-title>
                  </v-list-item>
                </v-list>
              </v-card-text>
            </v-card>
          </v-menu>
        </div>
      </div>
    </div>

    <!-- Character Count and Error Messages -->
    <div
      v-if="!hideDetails"
      class="d-flex justify-space-between align-start mt-1"
    >
      <div>
        <!-- Error Messages -->
        <div v-if="errorMessages?.length" class="text-error text-caption ml-3">
          {{ Array.isArray(errorMessages) ? errorMessages[0] : errorMessages }}
        </div>

        <!-- Hint -->
        <div v-if="hint && !errorMessages?.length" class="text-caption ml-3">
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
        {{ characterCount }} / {{ effectiveMaxLength }} characters
        <v-tooltip v-if="effectiveMaxLength" location="top">
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
          <span>Maximum {{ effectiveMaxLength }} characters</span>
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
    availableFunctions?: string[];
    disabled?: boolean;
    hideDetails?: boolean;
    maxLength?: number;
    /**
     * Mirrors Vuetify's density scale so this field can sit flush next to
     * v-select / v-text-field / v-btn siblings in the same flex row and
     * actually match their height instead of always rendering at 56px.
     */
    density?: "default" | "comfortable" | "compact";
    /**
     * "outlined" (default) keeps the bordered box. "plain" strips the
     * border/background for inline contexts like row & section titles.
     */
    variant?: "outlined" | "plain";
    fieldType?:
      | "header"
      | "body"
      | "footer"
      | "button"
      | "title"
      | "url"
      | "description";
  }>(),
  {
    disabled: false,
    hideDetails: false,
    maxLength: 255,
    density: "default",
    variant: "outlined",
    fieldType: "body",
  },
);

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

// ── State ──────────────────────────────────────────────────────────────────
const fieldContainer = ref<HTMLElement>();
const editor = ref<Editor | null>(null);
const isFocused = ref(false);
const hasContent = ref(false);
const variableMenu = ref(false);
const variableSearch = ref("");
const functionMenu = ref(false);
const functionSearch = ref("");
const characterCount = ref(0);

// ── Computed ───────────────────────────────────────────────────────────────
const filteredVariables = computed(() => {
  if (!props.availableVariables) return [];
  const query = variableSearch.value.toLowerCase().trim();
  if (!query) return props.availableVariables;
  return props.availableVariables.filter((v) =>
    v.toLowerCase().includes(query),
  );
});

const filteredFunctions = computed(() => {
  if (!props.availableFunctions) return [];
  const query = functionSearch.value.toLowerCase().trim();
  if (!query) return props.availableFunctions;
  return props.availableFunctions.filter((f) =>
    f.toLowerCase().includes(query),
  );
});

const effectiveMaxLength = computed(() => {
  if (props.maxLength) return props.maxLength;
  return FIELD_LIMITS[props.fieldType] || 255;
});

const isOverLimit = computed(
  () => characterCount.value > effectiveMaxLength.value,
);
const showCharacterCount = computed(
  () => !props.hideDetails && effectiveMaxLength.value > 0,
);

// ── hasDocumentContent ─────────────────────────────────────────────────────
// Recognises variable nodes, function nodes, and non-empty text so the
// floating label stays raised whenever there is any real content.
function hasDocumentContent(ed: Editor): boolean {
  const doc = ed.state.doc;

  for (let i = 0; i < doc.childCount; i++) {
    const block = doc.child(i);

    if (block.type.name !== "paragraph") return true;

    for (let j = 0; j < block.content.childCount; j++) {
      const child = block.content.child(j);
      if (child.type.name === "variable") return true;
      if (child.type.name === "function") return true;
      if (child.text && child.text.trim().length > 0) return true;
    }
  }

  return false;
}

// ── TipTap nodes ───────────────────────────────────────────────────────────

const VariableNode = Node.create({
  name: "variable",
  group: "inline",
  inline: true,
  atom: true,

  addAttributes() {
    return { name: { default: "" } };
  },

  parseHTML() {
    return [{ tag: "span[data-variable]" }];
  },

  renderHTML({ node }) {
    return [
      "span",
      {
        "data-variable": node.attrs.name,
        class: "variable-badge",
        contenteditable: "false",
      },
      `$${node.attrs.name}`,
    ];
  },

  addKeyboardShortcuts() {
    return {
      Backspace: () =>
        this.editor.commands.command(({ tr, state }) => {
          const { empty, $from } = state.selection;
          if (!empty) return false;
          const n = $from.nodeBefore;
          if (n?.type.name === "variable") {
            tr.delete($from.pos - n.nodeSize, $from.pos);
            return true;
          }
          return false;
        }),
      Delete: () =>
        this.editor.commands.command(({ tr, state }) => {
          const { empty, $from } = state.selection;
          if (!empty) return false;
          const n = $from.nodeAfter;
          if (n?.type.name === "variable") {
            tr.delete($from.pos, $from.pos + n.nodeSize);
            return true;
          }
          return false;
        }),
    };
  },
});

const FunctionNode = Node.create({
  name: "function",
  group: "inline",
  inline: true,
  atom: true,

  addAttributes() {
    return { name: { default: "" } };
  },

  parseHTML() {
    return [{ tag: "span[data-function]" }];
  },

  renderHTML({ node }) {
    return [
      "span",
      {
        "data-function": node.attrs.name,
        class: "function-badge",
        contenteditable: "false",
      },
      `f(x)${node.attrs.name}`,
    ];
  },

  addKeyboardShortcuts() {
    return {
      Backspace: () =>
        this.editor.commands.command(({ tr, state }) => {
          const { empty, $from } = state.selection;
          if (!empty) return false;
          const n = $from.nodeBefore;
          if (n?.type.name === "function") {
            tr.delete($from.pos - n.nodeSize, $from.pos);
            return true;
          }
          return false;
        }),
      Delete: () =>
        this.editor.commands.command(({ tr, state }) => {
          const { empty, $from } = state.selection;
          if (!empty) return false;
          const n = $from.nodeAfter;
          if (n?.type.name === "function") {
            tr.delete($from.pos, $from.pos + n.nodeSize);
            return true;
          }
          return false;
        }),
    };
  },
});

// ── Serialise ──────────────────────────────────────────────────────────────
// variable node → $varName
// function node → f(x) funcName
function serializeEditorState(ed: Editor): string {
  return (ed.getJSON().content ?? [])
    .map((block: any) => {
      if (!block?.content?.length) return "";
      return block.content
        .map((node: any) => {
          if (node.type === "variable") return `$${node.attrs.name}`;
          if (node.type === "function") return `f(x)${node.attrs.name}`;
          return node.text ?? "";
        })
        .join("");
    })
    .join("\n");
}

// ── Parse ──────────────────────────────────────────────────────────────────
// Handles both $varName and f(x) funcName tokens in a single-line value.
function parseValue(value: string | null | undefined): any {
  if (!value) {
    return { type: "doc", content: [{ type: "paragraph", content: [] }] };
  }

  const lines = value.split(/\r?\n/);

  const content = lines.map((line) => {
    if (!line.length) return { type: "paragraph", content: [] };

    // Match $varName OR f(x) funcName
    const tokenRe =
      /\$([a-zA-Z_][a-zA-Z0-9_]*)|f\(x\)\s+([a-zA-Z_][a-zA-Z0-9_]*)/g;
    const matches = Array.from(line.matchAll(tokenRe));

    if (!matches.length) {
      return { type: "paragraph", content: [{ type: "text", text: line }] };
    }

    const nodes: any[] = [];
    let pos = 0;

    matches.forEach((m) => {
      if (m.index! > pos) {
        nodes.push({ type: "text", text: line.slice(pos, m.index) });
      }

      if (m[1] !== undefined) {
        // $varName
        nodes.push({ type: "variable", attrs: { name: m[1] } });
      } else {
        // f(x) funcName
        nodes.push({ type: "function", attrs: { name: m[2] } });
      }

      pos = m.index! + m[0].length;
    });

    if (pos < line.length) {
      nodes.push({ type: "text", text: line.slice(pos) });
    }

    return { type: "paragraph", content: nodes };
  });

  return { type: "doc", content };
}

// ── Editor setup ───────────────────────────────────────────────────────────
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
          HTMLAttributes: { class: "field-paragraph" },
        },
      }),
      VariableNode,
      FunctionNode,
    ],
    onUpdate: ({ editor: ed }) => {
      const newCount = ed.state.doc.textContent.length;

      if (newCount <= effectiveMaxLength.value) {
        emit("update:modelValue", serializeEditorState(ed));
        characterCount.value = newCount;
      } else {
        ed.commands.setContent(parseValue(props.modelValue));
      }

      hasContent.value = hasDocumentContent(ed);
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
      attributes: { class: "prose prose-sm max-w-none focus:outline-none" },
    },
    editable: !props.disabled,
  });

  if (editor.value) {
    hasContent.value = hasDocumentContent(editor.value);
    characterCount.value = editor.value.state.doc.textContent.length;
  }
});

// ── Watchers ───────────────────────────────────────────────────────────────
watch(
  () => props.disabled,
  (d) => editor.value?.setEditable(!d),
);

watch(
  () => props.modelValue,
  (newVal) => {
    if (!editor.value) return;
    const current = serializeEditorState(editor.value);
    if (newVal !== current) {
      editor.value.commands.setContent(parseValue(newVal));
      hasContent.value = hasDocumentContent(editor.value);
      characterCount.value = editor.value.state.doc.textContent.length;
    }
  },
);

onBeforeUnmount(() => editor.value?.destroy());

// ── Methods ────────────────────────────────────────────────────────────────
const focusEditor = () => {
  if (!props.disabled && !isOverLimit.value) editor.value?.commands.focus();
};

const insertVariable = (variable: string) => {
  if (isOverLimit.value) return;
  editor.value
    ?.chain()
    .focus()
    .insertContent({ type: "variable", attrs: { name: variable } })
    .run();
  variableMenu.value = false;
  variableSearch.value = "";
};

const insertFunction = (fn: string) => {
  if (isOverLimit.value) return;
  editor.value
    ?.chain()
    .focus()
    .insertContent({ type: "function", attrs: { name: fn } })
    .run();
  functionMenu.value = false;
  functionSearch.value = "";
};
</script>

<style scoped>
.variable-text-field {
  width: 100%;
}

.field-container {
  position: relative;
  border: 1px solid rgb(var(--v-theme-inputBorder));
  border-radius: 9px;
  /* right padding reserves space for both picker buttons */
  padding-left: 12px;
  padding-right: 72px;
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

/* ── Density variants ──────────────────────────────────────────────────────
   Matches Vuetify's own density scale (default 56 / comfortable 48 /
   compact 40) so a RichTextField sitting next to a v-select or v-btn with
   the same density prop lines up without manual margin offsets. */
.field-container--density-comfortable {
  min-height: 48px;
}

.field-container--density-comfortable .field-content {
  margin-top: 4px;
  margin-bottom: 4px;
}

.field-container--density-compact {
  min-height: 40px;
}

.field-container--density-compact .field-content {
  margin-top: 2px;
  margin-bottom: 2px;
}

.field-container--density-compact .field-label {
  font-size: 13px;
}

.field-container--density-compact .field-label-floating {
  font-size: 10px;
}

.field-container--density-compact .pickers-row {
  right: 4px;
  bottom: 2px;
}

.field-container--density-compact :deep(.variable-badge),
.field-container--density-compact :deep(.function-badge) {
  padding: 1px 6px;
  font-size: 0.85em;
}

/* ── Variant: plain ───────────────────────────────────────────────────────
   Frameless look for inline contexts (row / section title fields) that
   shouldn't look like a full form control. */
.field-container--variant-plain {
  border-color: transparent;
  background: transparent;
  padding-right: 12px;
}

.field-container--variant-plain.is-focused {
  border-color: rgb(var(--v-theme-primary));
  background: rgb(var(--v-theme-surface));
}

.field-container--variant-plain.has-error {
  border-color: rgb(var(--v-theme-error));
}

/* Floating label — identical to original */
.field-label {
  position: absolute;
  top: 50%;
  left: 12px;
  transform: translateY(-50%);
  color: rgb(var(--v-theme-lightText));
  font-size: 14px;
  pointer-events: none;
  transition: all 0.2s ease;
  padding: 0 4px;
  z-index: 1;
}

.field-label-floating {
  top: -8px;
  left: 25px;
  transform: translateY(0);
  font-size: 11px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-lightText));
}

.field-label-floating.text-error {
  color: rgb(var(--v-theme-error));
}

/* Editor area — same as original */
.field-content {
  width: 100%;
  min-height: 24px;
  margin-top: 6px;
  margin-bottom: 6px;
}

/* Both pickers sit together at bottom-right */
.pickers-row {
  position: absolute;
  right: 6px;
  bottom: 6px;
  display: flex;
  align-items: center;
  gap: 2px;
  z-index: 5;
}

.cursor-pointer {
  cursor: pointer;
}

/* ── Badges ─────────────────────────────────────────────────────────────── */
:deep(.variable-badge) {
  background-color: rgba(var(--v-theme-primary), 0.1);
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

:deep(.function-badge) {
  background-color: rgba(var(--v-theme-secondary), 0.1);
  border: 1px solid rgb(var(--v-theme-secondary));
  border-radius: 4px;
  padding: 2px 8px;
  margin: 0 2px;
  display: inline-block;
  color: rgb(var(--v-theme-secondary));
  font-weight: 600;
  font-size: 0.9em;
  cursor: default;
  user-select: none;
}

/* ── ProseMirror ─────────────────────────────────────────────────────────── */
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

:deep(.ProseMirror p:first-child) {
  margin-top: 0;
}

:deep(.ProseMirror p:last-child) {
  margin-bottom: 0;
}

/* Dark mode support — unchanged from original */
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
