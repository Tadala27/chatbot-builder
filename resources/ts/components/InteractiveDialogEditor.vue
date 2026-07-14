<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { v4 as uuidv4 } from "uuid";
import {
  SYSTEM_ACTION_OPTIONS,
  type DialogButton,
  type DialogFormInput,
  type DialogOption,
  type SystemActionKind,
} from "@/composables/botSettings";

const props = defineProps<{
  modelValue: boolean;
  /** Pass an existing dialog to edit it; omit to create a new one. */
  dialog?: DialogOption | null;
  errors?: Record<string, string[]>;
  isSaving?: boolean;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "save", input: DialogFormInput): void;
}>();

const isEditing = computed(() => !!props.dialog);

const purpose = ref("");
const name = ref("");
const description = ref("");
const kind = ref<"message" | "buttons">("message");
const text = ref("");
const buttons = ref<DialogButton[]>([]);

function resetForm() {
  if (props.dialog) {
    purpose.value = props.dialog.purpose ?? "";
    name.value = props.dialog.label;
    kind.value = props.dialog.kind === "list" ? "buttons" : props.dialog.kind;
    text.value = props.dialog.config?.text ?? "";
    buttons.value = (props.dialog.config?.buttons ?? []).map((b) => ({ ...b }));
  } else {
    purpose.value = "";
    name.value = "";
    description.value = "";
    kind.value = "message";
    text.value = "";
    buttons.value = [];
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) resetForm();
  },
);

// Auto-slug the purpose from the name while creating, unless the user has
// already started typing their own purpose.
const purposeTouched = ref(false);
watch(name, (val) => {
  if (isEditing.value || purposeTouched.value) return;
  purpose.value = val
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
});

function addButton() {
  if (buttons.value.length >= 3) return;
  buttons.value.push({ id: uuidv4(), label: "", kind: "go_home" });
}
function removeButton(index: number) {
  buttons.value.splice(index, 1);
}

const canSave = computed(() => {
  if (!purpose.value.trim() || !name.value.trim() || !text.value.trim())
    return false;
  if (kind.value === "buttons") {
    return (
      buttons.value.length > 0 &&
      buttons.value.every((b) => b.label.trim().length > 0)
    );
  }
  return true;
});

function handleSave() {
  if (!canSave.value) return;

  const input: DialogFormInput = {
    purpose: purpose.value.trim(),
    name: name.value.trim(),
    description: description.value.trim() || null,
    kind: kind.value,
    is_active: true,
    config: {
      text: text.value.trim(),
      ...(kind.value === "buttons" ? { buttons: buttons.value } : {}),
    },
  };

  emit("save", input);
}

function close() {
  emit("update:modelValue", false);
}

function fieldError(field: string): string | null {
  return props.errors?.[field]?.[0] ?? null;
}

const actionLabel = (k: SystemActionKind) =>
  SYSTEM_ACTION_OPTIONS.find((o) => o.value === k)?.title ?? k;
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="560"
    persistent
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <VCard>
      <VCardTitle>{{ isEditing ? "Edit dialog" : "New dialog" }}</VCardTitle>
      <VDivider />
      <VCardText>
        <div class="field-row mb-3">
          <VTextField
            v-model="name"
            label="Name"
            placeholder="e.g. Invalid input — retry"
            variant="outlined"
            density="comfortable"
            hide-details
          />
        </div>

        <div class="field-row mb-3">
          <VTextField
            v-model="purpose"
            label="Purpose (unique identifier)"
            placeholder="invalid_input_retry"
            variant="outlined"
            density="comfortable"
            :error-messages="fieldError('purpose') ?? undefined"
            hint="Used internally to reference this dialog — lowercase, no spaces"
            persistent-hint
            @update:model-value="purposeTouched = true"
          />
        </div>

        <div class="field-row mb-3">
          <VTextarea
            v-model="description"
            label="Description (optional)"
            variant="outlined"
            density="comfortable"
            rows="2"
            hide-details
          />
        </div>

        <VDivider class="my-4" />

        <VBtnToggle v-model="kind" mandatory density="comfortable" class="mb-4">
          <VBtn value="message" size="small">Message only</VBtn>
          <VBtn value="buttons" size="small">Message + buttons</VBtn>
        </VBtnToggle>

        <VTextarea
          v-model="text"
          label="Message text"
          placeholder="Sorry, I didn't understand that."
          variant="outlined"
          density="comfortable"
          rows="3"
          :error-messages="fieldError('config.text') ?? undefined"
          class="mb-2"
        />

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
              <VTextField
                v-model="btn.label"
                label="Button text"
                placeholder="Home"
                maxlength="20"
                variant="outlined"
                density="compact"
                hide-details
                class="flex-grow-1"
              />
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
            Add up to 3 buttons — each runs a navigation action like
            {{ actionLabel("go_home") }} or {{ actionLabel("go_back") }}.
          </VAlert>
        </template>
      </VCardText>
      <VDivider />
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="close">Cancel</VBtn>
        <VBtn
          color="primary"
          variant="flat"
          :disabled="!canSave"
          :loading="isSaving"
          @click="handleSave"
        >
          {{ isEditing ? "Save changes" : "Create dialog" }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.field-row {
  display: flex;
  gap: 12px;
}
</style>
