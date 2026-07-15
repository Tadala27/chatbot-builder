<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { v4 as uuidv4 } from "uuid";
import {
  PURPOSE_SLOTS,
  SYSTEM_ACTION_OPTIONS,
  type BotDialogRecord,
  type BotDialogFormInput,
  type ReservedPurpose,
  type SystemActionKind,
} from "@/composables/botSettings";

const props = defineProps<{
  purpose: ReservedPurpose;
  existing: BotDialogRecord | null;
  errors?: Record<string, string[]>;
  isSaving?: boolean;
}>();

const emit = defineEmits<{
  (e: "save", input: BotDialogFormInput): void;
  (e: "close"): void;
}>();

const slot = computed(
  () => PURPOSE_SLOTS.find((s) => s.purpose === props.purpose)!,
);

const name = ref("");
const kind = ref<"message" | "buttons" | "list">("message");
const text = ref("");
const buttons = ref<
  Array<{ id: string; label: string; kind: SystemActionKind }>
>([]);

const textMaxLength = computed(() => (kind.value === "message" ? 4096 : 1024));
const textLength = computed(() => text.value.length);
const textNearLimit = computed(
  () => textLength.value >= textMaxLength.value * 0.9,
);
const textAtLimit = computed(() => textLength.value >= textMaxLength.value);

function reset() {
  if (props.existing) {
    name.value = props.existing.name;
    kind.value = props.existing.kind;
    text.value = props.existing.config.text ?? "";
    buttons.value = (props.existing.config.buttons ?? []).map((b) => ({
      ...b,
    }));
  } else {
    name.value = slot.value?.label ?? "";
    kind.value = "message";
    text.value = "";
    buttons.value = [];
  }
}

watch(() => props.purpose, reset, { immediate: true });

watch(kind, () => {
  if (text.value.length > textMaxLength.value) {
    text.value = text.value.slice(0, textMaxLength.value);
  }
});

function addButton() {
  if (buttons.value.length >= 3) return;
  buttons.value.push({ id: uuidv4(), label: "", kind: "go_home" });
}

function removeButton(index: number) {
  buttons.value.splice(index, 1);
}

const canSave = computed(() => {
  if (!name.value.trim() || !text.value.trim()) return false;
  if (text.value.length > textMaxLength.value) return false;
  if (kind.value === "buttons") {
    return (
      buttons.value.length > 0 &&
      buttons.value.every((b) => b.label.trim().length > 0)
    );
  }
  return true;
});

function fieldError(field: string): string | null {
  return props.errors?.[field]?.[0] ?? null;
}

function handleSave() {
  if (!canSave.value) return;
  const input: BotDialogFormInput = {
    purpose: props.purpose,
    name: name.value.trim(),
    kind: kind.value,
    is_active: true,
    config: {
      text: text.value.trim(),
      ...(kind.value === "buttons" ? { buttons: buttons.value } : {}),
    },
  };
  emit("save", input);
}
</script>

<template>
  <VDialog
    model-value
    max-width="560"
    persistent
    @update:model-value="emit('close')"
  >
    <VCard>
      <VCardTitle>
        {{ existing ? "Edit" : "Set up" }} — {{ slot?.label }}
      </VCardTitle>
      <VCardSubtitle class="pb-2">{{ slot?.hint }}</VCardSubtitle>
      <VDivider />

      <VCardText>
        <VTextField
          v-model="name"
          label="Internal name"
          placeholder="e.g. Greeting message"
          variant="outlined"
          density="comfortable"
          hide-details
          class="mb-4"
        />

        <VDivider class="mb-4" />

        <VBtnToggle v-model="kind" mandatory density="comfortable" class="mb-1">
          <VBtn value="message" size="small">Message only</VBtn>
          <VBtn value="buttons" size="small">Message + buttons</VBtn>
        </VBtnToggle>

        <p class="kind-hint mb-3">
          <template v-if="kind === 'message'">
            Plain text message — up to <strong>4,096 characters</strong>.
          </template>
          <template v-else>
            Message shown above the buttons — up to
            <strong>1,024 characters</strong>.
          </template>
        </p>

        <div class="textarea-wrap mb-2">
          <VTextarea
            v-model="text"
            label="Message text"
            placeholder="Hi there! What can I help you with today?"
            variant="outlined"
            density="comfortable"
            rows="4"
            :maxlength="textMaxLength"
            :error-messages="fieldError('config.text') ?? undefined"
            no-resize
          />
          <div
            class="char-counter"
            :class="{
              'char-counter--warn': textNearLimit && !textAtLimit,
              'char-counter--limit': textAtLimit,
            }"
          >
            {{ textLength.toLocaleString() }} /
            {{ textMaxLength.toLocaleString() }}
          </div>
        </div>

        <template v-if="kind === 'buttons'">
          <VDivider class="my-4" />
          <div class="d-flex align-center justify-space-between mb-3">
            <span class="text-subtitle-2 font-weight-bold">
              Buttons ({{ buttons.length }}/3)
            </span>
            <VBtn
              size="x-small"
              variant="outlined"
              prepend-icon="$plus"
              :disabled="buttons.length >= 3"
              @click="addButton"
            >
              Add button
            </VBtn>
          </div>

          <VCard
            v-for="(btn, i) in buttons"
            :key="btn.id"
            variant="outlined"
            class="mb-3 pa-3"
          >
            <div class="d-flex gap-2 align-center mb-2">
              <div class="btn-label-wrap flex-grow-1">
                <VTextField
                  v-model="btn.label"
                  label="Button text"
                  placeholder="Main menu"
                  maxlength="20"
                  variant="outlined"
                  density="compact"
                  hide-details
                />
                <div
                  class="btn-char-counter"
                  :class="{
                    'btn-char-counter--warn':
                      btn.label.length >= 16 && btn.label.length < 20,
                    'btn-char-counter--limit': btn.label.length >= 20,
                  }"
                >
                  {{ btn.label.length }}/20
                </div>
              </div>
              <VBtn
                icon="$trashCan"
                size="x-small"
                variant="text"
                color="error"
                @click="removeButton(i)"
              />
            </div>
            <VSelect
              v-model="btn.kind"
              :items="SYSTEM_ACTION_OPTIONS"
              item-title="title"
              item-value="value"
              label="When tapped"
              variant="outlined"
              density="compact"
              hide-details
            />
            <p class="text-caption text-medium-emphasis mt-1 mb-0">
              {{
                SYSTEM_ACTION_OPTIONS.find((o) => o.value === btn.kind)?.hint
              }}
            </p>
          </VCard>

          <VAlert
            v-if="!buttons.length"
            type="info"
            variant="tonal"
            density="compact"
          >
            Add at least one button. Use <strong>Start flow</strong> to
            transition into the bot builder flow, or
            <strong>Go to main menu</strong> to stay in config mode.
          </VAlert>
        </template>
      </VCardText>

      <VDivider />
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="emit('close')">Cancel</VBtn>
        <VBtn
          color="primary"
          variant="flat"
          :disabled="!canSave"
          :loading="isSaving"
          @click="handleSave"
        >
          {{ existing ? "Save changes" : "Create" }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.kind-hint {
  font-size: 12px;
  color: #8d9a93;
  margin: 0;
  padding-left: 2px;
}

.textarea-wrap {
  position: relative;
}

.char-counter {
  position: absolute;
  bottom: 15px;
  right: 14px;
  font-size: 11.5px;
  font-variant-numeric: tabular-nums;
  color: #b0b8b4;
  pointer-events: none;
  line-height: 1;
  background: #fff;
  padding: 2px 4px;
  border-radius: 4px;
}

.char-counter--warn {
  color: #c28b1a;
}
.char-counter--limit {
  color: #d24b4b;
  font-weight: 600;
}

.btn-label-wrap {
  position: relative;
}

.btn-char-counter {
  position: absolute;
  bottom: 6px;
  right: 10px;
  font-size: 10.5px;
  font-variant-numeric: tabular-nums;
  color: #b0b8b4;
  pointer-events: none;
  line-height: 1;
  background: #fff;
  padding: 1px 3px;
  border-radius: 3px;
}

.btn-char-counter--warn {
  color: #c28b1a;
}
.btn-char-counter--limit {
  color: #d24b4b;
  font-weight: 600;
}
</style>
