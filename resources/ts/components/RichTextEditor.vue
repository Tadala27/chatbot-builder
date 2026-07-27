<!-- RichTextArea.vue -->

<template>
  <div class="whatsapp-editor-wrapper">
    <v-label v-if="label" class="mb-2 text-subtitle-1 font-weight-medium">
      {{ label }}
    </v-label>

    <div
      ref="editorContainer"
      class="editor-container"
      :class="[
        `editor-container--density-${density}`,
        `editor-container--variant-${variant}`,
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
        class="editor-label"
        :class="{
          'editor-label-floating': isFocused || hasContent,
          'text-error': errorMessages?.length || isOverLimit,
        }"
      >
        {{ placeholder }}
      </label>

      <div ref="scrollContainer" class="editor-scroll-area">
        <div ref="contentMeasure">
          <editor-content :editor="editor" class="editor-content" />
        </div>
      </div>

      <!-- Bottom Toolbar -->
      <div class="editor-bottom-toolbar">
        <div
          v-if="editor && showFormatting"
          class="formatting-toolbar"
          @click.stop
        >
          <v-btn
            icon
            size="x-small"
            variant="tonal"
            :color="editor.isActive('whatsappBold') ? 'primary' : 'default'"
            :class="{ 'active-format': editor.isActive('whatsappBold') }"
            :disabled="disabled || isOverLimit"
            title="Bold (Ctrl+B)"
            @click="toggleBold"
            ><v-icon>$formatBold</v-icon></v-btn
          >

          <v-btn
            icon
            size="x-small"
            variant="tonal"
            :color="editor.isActive('whatsappItalic') ? 'primary' : 'default'"
            :class="{ 'active-format': editor.isActive('whatsappItalic') }"
            :disabled="disabled || isOverLimit"
            title="Italic (Ctrl+I)"
            @click="toggleItalic"
            ><v-icon>$formatItalic</v-icon></v-btn
          >

          <v-btn
            icon
            size="x-small"
            variant="tonal"
            :color="editor.isActive('whatsappStrike') ? 'primary' : 'default'"
            :class="{ 'active-format': editor.isActive('whatsappStrike') }"
            :disabled="disabled || isOverLimit"
            title="Strikethrough (Ctrl+Shift+X)"
            @click="toggleStrike"
            ><v-icon>$formatStrikethroughVariant</v-icon></v-btn
          >

          <v-btn
            icon
            size="x-small"
            variant="tonal"
            :color="editor.isActive('whatsappCode') ? 'primary' : 'default'"
            :class="{ 'active-format': editor.isActive('whatsappCode') }"
            :disabled="disabled || isOverLimit"
            title="Monospace (Ctrl+E)"
            @click="toggleCode"
            ><v-icon>$codeTags</v-icon></v-btn
          >
        </div>

        <div class="d-flex align-center offset-md-3">
          <!-- Variable Picker -->
          <div v-if="editor && availableVariables?.length" @click.stop>
            <v-menu
              v-model="variableMenu"
              :close-on-content-click="false"
              :close-on-back="false"
            >
              <template #activator="{ props: menuProps }">
                <v-btn
                  v-bind="menuProps"
                  icon
                  size="x-small"
                  variant="text"
                  color="default"
                  :disabled="disabled || isOverLimit"
                  title="Insert variable"
                  ><v-icon>$xml</v-icon></v-btn
                >
              </template>

              <v-card min-width="280" max-width="320" @click.stop>
                <v-card-text class="pa-3">
                  <div class="d-flex align-center mb-2" style="gap: 8px">
                    <v-text-field
                      v-model="variableSearch"
                      placeholder="Search variables..."
                      density="compact"
                      variant="outlined"
                      hide-details
                      prepend-inner-icon="$magnify"
                      class="flex-grow-1"
                      autofocus
                      @keyup.esc="closeVariableMenu"
                    />
                    <v-btn
                      icon
                      size="small"
                      variant="text"
                      title="Close"
                      @click="closeVariableMenu"
                    >
                      <v-icon>$close</v-icon>
                    </v-btn>
                  </div>
                  <div class="var-list-outer">
                    <PerfectScrollbar class="var-list-ps">
                      <v-list density="compact" class="pa-0">
                        <v-list-item
                          v-for="variable in filteredVariables"
                          :key="variable"
                          class="cursor-pointer"
                          @click="insertVariable(variable)"
                        >
                          {{ variable }}
                        </v-list-item>
                        <v-list-item v-if="filteredVariables.length === 0">
                          <span class="text-caption text-medium-emphasis"
                            >No variables found</span
                          >
                        </v-list-item>
                      </v-list>
                    </PerfectScrollbar>
                  </div>
                  <p
                    class="text-caption text-medium-emphasis text-center mt-2 mb-0"
                  >
                    Esc or × to close
                  </p>
                </v-card-text>
              </v-card>
            </v-menu>
          </div>

          <!-- Function Picker -->
          <div v-if="editor && availableFunctions?.length" @click.stop>
            <v-menu
              v-model="functionMenu"
              :close-on-content-click="false"
              :close-on-back="false"
            >
              <template #activator="{ props: menuProps }">
                <v-btn
                  v-bind="menuProps"
                  icon
                  size="x-small"
                  variant="text"
                  color="default"
                  :disabled="disabled || isOverLimit"
                  title="Insert function"
                >
                  <v-icon>$function</v-icon>
                </v-btn>
              </template>

              <v-card min-width="280" max-width="320" @click.stop>
                <v-card-text class="pa-3">
                  <div class="d-flex align-center mb-2" style="gap: 8px">
                    <v-text-field
                      v-model="functionSearch"
                      placeholder="Search functions..."
                      density="compact"
                      variant="outlined"
                      hide-details
                      prepend-inner-icon="$magnify"
                      class="flex-grow-1"
                      autofocus
                      @keyup.esc="closeFunctionMenu"
                    />
                    <v-btn
                      icon
                      size="small"
                      variant="text"
                      title="Close"
                      @click="closeFunctionMenu"
                    >
                      <v-icon>$close</v-icon>
                    </v-btn>
                  </div>
                  <div class="var-list-outer">
                    <PerfectScrollbar class="var-list-ps">
                      <v-list density="compact" class="pa-0">
                        <v-list-item
                          v-for="fn in filteredFunctions"
                          :key="fn"
                          class="cursor-pointer"
                          @click="insertFunction(fn)"
                        >
                          {{ fn }}
                        </v-list-item>
                        <v-list-item v-if="filteredFunctions.length === 0">
                          <span class="text-caption text-medium-emphasis"
                            >No functions found</span
                          >
                        </v-list-item>
                      </v-list>
                    </PerfectScrollbar>
                  </div>
                  <p
                    class="text-caption text-medium-emphasis text-center mt-2 mb-0"
                  >
                    Esc or × to close
                  </p>
                </v-card-text>
              </v-card>
            </v-menu>
          </div>
        </div>
      </div>
    </div>

    <!-- Character Count and Error Messages -->
    <div class="d-flex justify-space-between align-start mt-1">
      <div v-if="!hideDetails" class="mt-1 ml-3">
        <span v-if="errorMessages?.length" class="text-error text-caption">
          {{ Array.isArray(errorMessages) ? errorMessages[0] : errorMessages }}
        </span>
        <span v-else-if="hint" class="text-caption text-medium-emphasis">{{
          hint
        }}</span>
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
import {
  ref,
  computed,
  watch,
  onMounted,
  onBeforeUnmount,
  nextTick,
} from "vue";
import { Editor, EditorContent } from "@tiptap/vue-3";
import { Node, Mark } from "@tiptap/core";
import { PerfectScrollbar } from "vue3-perfect-scrollbar";

// ── Props ──────────────────────────────────────────────────────────────────
const props = withDefaults(
  defineProps<{
    modelValue: string | null | undefined;
    label?: string;
    placeholder?: string;
    hint?: string;
    errorMessages?: string | string[];
    availableVariables?: string[];
    availableFunctions?: string[];
    showFormatting?: boolean;
    disabled?: boolean;
    hideDetails?: boolean;
    rules?: Array<(v: string) => string | boolean>;
    maxLength?: number;
    minRows?: number;
    maxRows?: number;
    /** Same density scale as RichTextField — default / comfortable / compact. */
    density?: "default" | "comfortable" | "compact";
    /** "outlined" (default) or "plain" (frameless, inline contexts). */
    variant?: "outlined" | "plain";
    fieldType?:
      | "header"
      | "body"
      | "footer"
      | "button"
      | "title"
      | "description";
  }>(),
  {
    placeholder: "Type here...",
    showFormatting: true,
    disabled: false,
    hideDetails: false,
    maxLength: 255,
    minRows: 3,
    maxRows: 10,
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

// ── Constants ──────────────────────────────────────────────────────────────
const FIELD_LIMITS = {
  header: 60,
  body: 4096,
  footer: 60,
  button: 20,
  title: 24,
  description: 72,
} as const;

const LINE_HEIGHT = 28;

// ── Refs ───────────────────────────────────────────────────────────────────
const editorContainer = ref<HTMLElement | null>(null);
const scrollContainer = ref<HTMLElement | null>(null);
const contentMeasure = ref<HTMLElement | null>(null);
const editor = ref<Editor | null>(null);
const isFocused = ref(false);
const hasContent = ref(false);
const variableMenu = ref(false);
const variableSearch = ref("");
const functionMenu = ref(false);
const functionSearch = ref("");
const characterCount = ref(0);

const effectiveMaxLength = computed(() => {
  if (props.maxLength) return props.maxLength;
  return FIELD_LIMITS[props.fieldType] || 255;
});

const isOverLimit = computed(
  () => characterCount.value > effectiveMaxLength.value,
);
const showCharacterCount = computed(() => !props.hideDetails);
const minHeightPx = computed(() => props.minRows * LINE_HEIGHT);
const maxHeightPx = computed(() => props.maxRows * LINE_HEIGHT);

const filteredVariables = computed(() => {
  const vars = props.availableVariables ?? [];
  const q = variableSearch.value.toLowerCase().trim();
  return q ? vars.filter((v) => v.toLowerCase().includes(q)) : vars;
});

const filteredFunctions = computed(() => {
  const fns = props.availableFunctions ?? [];
  const q = functionSearch.value.toLowerCase().trim();
  return q ? fns.filter((f) => f.toLowerCase().includes(q)) : fns;
});

// ── hasDocumentContent ─────────────────────────────────────────────────────
// Checks for ANY real content: plain text, variable nodes, or function nodes.
// ProseMirror's doc.textContent only returns text from Text nodes — atom nodes
// (variable, function) have no textContent — so we must walk the tree manually.
// This is what keeps the floating label raised when only badges are present.
function hasDocumentContent(ed: Editor): boolean {
  const doc = ed.state.doc;

  for (let i = 0; i < doc.childCount; i++) {
    const block = doc.child(i);

    // Non-paragraph block always counts as content
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

// ── Height management ──────────────────────────────────────────────────────
function updateEditorHeight() {
  const wrapper = scrollContainer.value;
  const inner = contentMeasure.value;
  if (!wrapper || !inner) return;

  const naturalHeight = inner.scrollHeight;
  const clamped = Math.min(
    Math.max(naturalHeight, minHeightPx.value),
    maxHeightPx.value,
  );

  wrapper.style.height = `${clamped}px`;
  wrapper.style.overflowY =
    naturalHeight > maxHeightPx.value ? "auto" : "hidden";
}

// ── Menu helpers ───────────────────────────────────────────────────────────
const closeVariableMenu = () => {
  variableMenu.value = false;
  variableSearch.value = "";
};
const closeFunctionMenu = () => {
  functionMenu.value = false;
  functionSearch.value = "";
};

const insertVariable = (variable: string) => {
  if (isOverLimit.value) return;
  editor.value
    ?.chain()
    .focus()
    .insertContent({ type: "variable", attrs: { name: variable } })
    .run();
  // keep menu open so user can insert multiple
};

const insertFunction = (fn: string) => {
  if (isOverLimit.value) return;
  editor.value
    ?.chain()
    .focus()
    .insertContent({ type: "function", attrs: { name: fn } })
    .run();
  // keep menu open so user can insert multiple
};

// ── Editor actions ─────────────────────────────────────────────────────────
const focusEditor = () => {
  if (!props.disabled) editor.value?.commands.focus();
};
const toggleBold = () => {
  if (!isOverLimit.value)
    editor.value?.chain().focus().toggleMark("whatsappBold").run();
};
const toggleItalic = () => {
  if (!isOverLimit.value)
    editor.value?.chain().focus().toggleMark("whatsappItalic").run();
};
const toggleStrike = () => {
  if (!isOverLimit.value)
    editor.value?.chain().focus().toggleMark("whatsappStrike").run();
};
const toggleCode = () => {
  if (!isOverLimit.value)
    editor.value?.chain().focus().toggleMark("whatsappCode").run();
};

// ── TipTap marks ───────────────────────────────────────────────────────────
const WhatsAppBold = Mark.create({
  name: "whatsappBold",
  priority: 1000,
  keepOnSplit: false,
  parseHTML() {
    return [
      { tag: "strong" },
      { style: "font-weight", getAttrs: (v: any) => v === "bold" && null },
    ];
  },
  renderHTML() {
    return ["strong", 0];
  },
  addCommands() {
    return {
      toggleWhatsAppBold:
        () =>
        ({ commands }: any) =>
          commands.toggleMark(this.name),
    };
  },
  addKeyboardShortcuts() {
    return { "Mod-b": () => this.editor.commands.toggleMark(this.name) };
  },
});

const WhatsAppItalic = Mark.create({
  name: "whatsappItalic",
  priority: 1000,
  keepOnSplit: false,
  parseHTML() {
    return [
      { tag: "em" },
      { style: "font-style", getAttrs: (v: any) => v === "italic" && null },
    ];
  },
  renderHTML() {
    return ["em", 0];
  },
  addCommands() {
    return {
      toggleWhatsAppItalic:
        () =>
        ({ commands }: any) =>
          commands.toggleMark(this.name),
    };
  },
  addKeyboardShortcuts() {
    return { "Mod-i": () => this.editor.commands.toggleMark(this.name) };
  },
});

const WhatsAppStrike = Mark.create({
  name: "whatsappStrike",
  priority: 1000,
  keepOnSplit: false,
  parseHTML() {
    return [
      { tag: "s" },
      { tag: "del" },
      { tag: "strike" },
      {
        style: "text-decoration",
        getAttrs: (v: any) => v === "line-through" && null,
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
        ({ commands }: any) =>
          commands.toggleMark(this.name),
    };
  },
  addKeyboardShortcuts() {
    return { "Mod-Shift-x": () => this.editor.commands.toggleMark(this.name) };
  },
});

const WhatsAppCode = Mark.create({
  name: "whatsappCode",
  priority: 1000,
  keepOnSplit: false,
  inclusive: false,
  parseHTML() {
    return [{ tag: "code" }];
  },
  renderHTML() {
    return ["code", 0];
  },
  addCommands() {
    return {
      toggleWhatsAppCode:
        () =>
        ({ commands }: any) =>
          commands.toggleMark(this.name),
    };
  },
  addKeyboardShortcuts() {
    return { "Mod-e": () => this.editor.commands.toggleMark(this.name) };
  },
});

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
      `f(x) ${node.attrs.name}`,
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

const DocumentNode = Node.create({
  name: "doc",
  topNode: true,
  content: "block+",
});
const ParagraphNode = Node.create({
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
const TextNode = Node.create({ name: "text", group: "inline" });

// ── Serialise ──────────────────────────────────────────────────────────────
function serializeEditorState(ed: Editor): string {
  return (ed.getJSON().content ?? [])
    .map((block: any) => {
      if (!block?.content) return "";
      return block.content
        .map((node: any) => {
          if (node.type === "variable") return `$${node.attrs.name}`;
          if (node.type === "function") return `f(x) ${node.attrs.name}`;
          let t = node.text ?? "";
          if (node.marks) {
            const order: Record<string, number> = {
              whatsappBold: 1,
              whatsappItalic: 2,
              whatsappStrike: 3,
              whatsappCode: 4,
            };
            [...node.marks]
              .sort((a, b) => (order[a.type] ?? 99) - (order[b.type] ?? 99))
              .forEach((mark: any) => {
                switch (mark.type) {
                  case "whatsappBold":
                    t = `*${t}*`;
                    break;
                  case "whatsappItalic":
                    t = `_${t}_`;
                    break;
                  case "whatsappStrike":
                    t = `~${t}~`;
                    break;
                  case "whatsappCode":
                    t = `\`${t}\``;
                    break;
                }
              });
          }
          return t;
        })
        .join("");
    })
    .join("\n");
}

function parseFormattedText(text: string): any[] {
  if (!text) return [];
  type M = { index: number; type: string; content: string; fullMatch: string };
  const patterns = [
    { type: "whatsappBold", regex: /\*(.*?)\*/g },
    { type: "whatsappItalic", regex: /_(.*?)_/g },
    { type: "whatsappStrike", regex: /~(.*?)~/g },
    { type: "whatsappCode", regex: /`(.*?)`/g },
  ];
  const matches: M[] = [];
  patterns.forEach(({ type, regex }) => {
    let m;
    while ((m = regex.exec(text)) !== null)
      matches.push({ index: m.index, type, content: m[1], fullMatch: m[0] });
  });
  matches.sort((a, b) => a.index - b.index);
  if (!matches.length) return [{ type: "text", text }];

  const nodes: any[] = [];
  let last = 0;
  matches.forEach((match) => {
    if (match.index > last)
      nodes.push({ type: "text", text: text.slice(last, match.index) });
    parseFormattedText(match.content).forEach((n) => {
      nodes.push(
        n.type === "text"
          ? { ...n, marks: [...(n.marks ?? []), { type: match.type }] }
          : n,
      );
    });
    last = match.index + match.fullMatch.length;
  });
  if (last < text.length) nodes.push({ type: "text", text: text.slice(last) });
  return nodes;
}

function parseValue(value: string | null | undefined): any {
  if (!value) return { type: "doc", content: [{ type: "paragraph" }] };
  return {
    type: "doc",
    content: value.split(/\r?\n/).map((line) => {
      // Match $varName OR f(x) funcName
      const tokenRe =
        /\$([a-zA-Z_][a-zA-Z0-9_]*)|f\(x\)\s+([a-zA-Z_][a-zA-Z0-9_]*)/g;
      const tokenMatches = Array.from(line.matchAll(tokenRe));
      if (!tokenMatches.length)
        return { type: "paragraph", content: parseFormattedText(line) };
      const nodes: any[] = [];
      let pos = 0;
      tokenMatches.forEach((m) => {
        if (m.index! > pos)
          nodes.push(...parseFormattedText(line.slice(pos, m.index)));
        if (m[1] !== undefined) {
          nodes.push({ type: "variable", attrs: { name: m[1] } });
        } else {
          nodes.push({ type: "function", attrs: { name: m[2] } });
        }
        pos = m.index! + m[0].length;
      });
      if (pos < line.length) nodes.push(...parseFormattedText(line.slice(pos)));
      return { type: "paragraph", content: nodes.length ? nodes : undefined };
    }),
  };
}

// ── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(() => {
  editor.value = new Editor({
    content: parseValue(props.modelValue),
    extensions: [
      DocumentNode,
      ParagraphNode,
      TextNode,
      WhatsAppBold,
      WhatsAppItalic,
      WhatsAppStrike,
      WhatsAppCode,
      VariableNode,
      FunctionNode,
    ],
    onUpdate: ({ editor: ed }) => {
      const content = serializeEditorState(ed);
      const newCount = ed.state.doc.textContent.length;
      if (newCount <= effectiveMaxLength.value) {
        emit("update:modelValue", content);
        characterCount.value = newCount;
      } else {
        ed.commands.setContent(parseValue(props.modelValue));
      }
      // Walk the doc tree — textContent misses atom nodes (variable / function)
      hasContent.value = hasDocumentContent(ed);
      nextTick(updateEditorHeight);
    },
    onFocus: () => {
      isFocused.value = true;
      emit("focus");
    },
    onBlur: () => {
      isFocused.value = false;
      emit("blur");
    },
    onTransaction: ({ transaction }) => {
      if (transaction.docChanged) nextTick(updateEditorHeight);
    },
    editorProps: { attributes: { class: "tiptap-content" } },
    editable: !props.disabled,
  });

  // Walk the doc tree for initial state too
  hasContent.value = hasDocumentContent(editor.value);
  characterCount.value = editor.value.state.doc.textContent.length;

  nextTick(() => {
    updateEditorHeight();
    setTimeout(updateEditorHeight, 50);
    setTimeout(updateEditorHeight, 150);
  });
});

watch(
  () => props.modelValue,
  (newVal) => {
    if (!editor.value) return;
    if (newVal !== serializeEditorState(editor.value)) {
      editor.value.commands.setContent(parseValue(newVal));
      // Walk the tree — don't rely on textContent for atom nodes
      hasContent.value = hasDocumentContent(editor.value);
      characterCount.value = editor.value.state.doc.textContent.length;
      nextTick(() => {
        updateEditorHeight();
        setTimeout(updateEditorHeight, 10);
      });
    }
  },
  { deep: true },
);

watch([() => props.minRows, () => props.maxRows], () =>
  nextTick(updateEditorHeight),
);
watch(
  () => props.disabled,
  (d) => editor.value?.setEditable(!d),
);

onBeforeUnmount(() => editor.value?.destroy());
</script>

<style scoped>
.whatsapp-editor-wrapper {
  width: 100%;
}

/* ── Container ────────────────────────────────────────────────────────── */
.editor-container {
  position: relative;
  border: 1px solid rgb(var(--v-theme-inputBorder));
  border-radius: 9px;
  padding-left: 12px;
  padding-right: 12px;
  min-height: max(
    var(--v-input-control-height, 56px),
    1.5rem + var(--v-field-input-padding-top) +
      var(--v-field-input-padding-bottom)
  );
  display: flex;
  flex-direction: column;
  transition: border-color 0.2s ease;
  cursor: text;
}

.editor-container.is-focused {
  border-color: rgb(var(--v-theme-primary));
}

.editor-container.has-error {
  border-color: rgb(var(--v-theme-error));
}

.editor-container.is-disabled {
  opacity: 0.6;
  cursor: not-allowed;
  pointer-events: none;
}

/* ── Density variants — same scale as RichTextField ─────────────────────── */
.editor-container--density-comfortable {
  min-height: 48px;
}

.editor-container--density-compact {
  min-height: 40px;
}

.editor-container--density-compact .editor-bottom-toolbar {
  padding-top: 2px;
  padding-bottom: 2px;
  margin-top: 2px;
}

.editor-container--density-compact .editor-label {
  font-size: 13px;
}

/* ── Variant: plain ───────────────────────────────────────────────────────
   Frameless look for inline contexts, matching RichTextField's plain variant. */
.editor-container--variant-plain {
  border-color: transparent;
  background: transparent;
}

.editor-container--variant-plain.is-focused {
  border-color: rgb(var(--v-theme-primary));
  background: rgb(var(--v-theme-surface));
}

.editor-container--variant-plain.has-error {
  border-color: rgb(var(--v-theme-error));
}

.editor-label {
  position: absolute;
  top: 14px;
  left: 13px;
  color: rgba(var(--v-theme-on-surface), 0.45);
  font-size: 14px;
  pointer-events: none;
  transition: all 0.15s ease;
  padding: 0 4px;
  z-index: 2;
}

.editor-label-floating {
  top: -9px;
  font-size: 12px;
  background: rgb(var(--v-theme-surface));
  color: rgba(var(--v-theme-on-surface), 0.65);
}

.editor-label-floating.text-error {
  color: rgb(var(--v-theme-error));
}

/* ── Scroll area ──────────────────────────────────────────────────────── */
.editor-scroll-area {
  flex: 1;
}

/* ── Toolbar ──────────────────────────────────────────────────────────── */
.editor-bottom-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-top: 6px;
  padding-bottom: 6px;
  margin-top: 6px;
  gap: 8px;
}

.formatting-toolbar {
  display: flex;
  gap: 6px;
  align-items: center;
}

.formatting-toolbar .v-btn.active-format {
  background: rgba(var(--v-theme-primary), 0.12);
}

.var-list-outer {
  height: 200px;
  overflow: hidden;
  position: relative;
}

.var-list-ps {
  height: 100%;
}

/* ── Character count ──────────────────────────────────────────────────── */
.char-count {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.4);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

/* ── ProseMirror ──────────────────────────────────────────────────────── */
:deep(.tiptap-content) {
  outline: none;
  line-height: 15px;
  min-height: v-bind('minHeightPx + "px"');
}

:deep(.tiptap-content p) {
  margin: 0;
  padding: 0;
  min-height: 15px;
}

:deep(.tiptap-content strong) {
  font-weight: 700;
}

:deep(.tiptap-content em) {
  font-style: italic;
}

:deep(.tiptap-content s) {
  text-decoration: line-through;
}

:deep(.tiptap-content code) {
  background: rgba(var(--v-theme-on-surface), 0.07);
  padding: 1px 5px;
  border-radius: 3px;
  font-family: "Courier New", monospace;
  font-size: 0.88em;
  color: rgb(var(--v-theme-primary));
}

/* ── Variable badge ───────────────────────────────────────────────────── */
:deep(.variable-badge) {
  background: rgba(var(--v-theme-primary), 0.1);
  border: 1px solid rgba(var(--v-theme-primary), 0.35);
  border-radius: 4px;
  padding: 1px 4px;
  margin: 0 1px;
  display: inline-block;
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
  font-size: 0.88em;
  cursor: default;
  user-select: none;
  vertical-align: baseline;
}

/* ── Function badge ───────────────────────────────────────────────────── */
:deep(.function-badge) {
  background: rgba(var(--v-theme-success), 0.1);
  border: 1px solid rgba(var(--v-theme-success), 0.4);
  border-radius: 4px;
  padding: 1px 6px;
  margin: 0 1px;
  display: inline-block;
  color: rgb(var(--v-theme-success));
  font-weight: 600;
  font-size: 0.88em;
  cursor: default;
  user-select: none;
  vertical-align: baseline;
}
</style>
