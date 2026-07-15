<script setup lang="ts">
import { computed } from "vue";
import type { DialogOption } from "@/composables/botSettings";

const props = defineProps<{
  modelValue: string | null;
  label: string;
  dialogs: DialogOption[];
  error?: string | null;
  hint?: string;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: string | null): void;
  (e: "create"): void;
  (e: "edit", dialog: DialogOption): void;
}>();

const selected = computed(
  () => props.dialogs.find((d) => d.id === props.modelValue) ?? null,
);
</script>

<template>
  <div class="field">
    <div class="d-flex align-center justify-space-between mb-1">
      <label class="field__label mb-0">{{ label }}</label>
      <button type="button" class="field__new" @click="emit('create')">
        + New dialog
      </button>
    </div>
    <p v-if="hint" class="field__hint">{{ hint }}</p>

    <div class="d-flex gap-2 align-center">
      <select
        :value="modelValue"
        clearable
        class="field__input"
        @change="
          emit(
            'update:modelValue',
            ($event.target as HTMLSelectElement).value || null,
          )
        "
      >
        <option value="">No dialog selected</option>
        <option v-for="d in dialogs" :key="d.id" :value="d.id">
          {{ d.display }}
        </option>
      </select>
      <button
        v-if="selected"
        type="button"
        class="field__edit"
        title="Edit this dialog"
        @click="emit('edit', selected)"
      >
        Edit
      </button>
    </div>
    <span v-if="error" class="field__error">{{ error }}</span>
  </div>
</template>

<style scoped>
.field {
  margin-bottom: 16px;
}
.field__label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #16191c;
}
.field__hint {
  font-size: 12px;
  color: #8d9a93;
  margin: 0 0 8px;
}
.field__input {
  flex: 1;
  border: 1px solid #ececec;
  border-radius: 10px;
  padding: 9px 12px;
  font-size: 13.5px;
  font-family: inherit;
  color: #16191c;
  background: #fff;
}
.field__new {
  border: none;
  background: transparent;
  color: #1fc06b;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}
.field__edit {
  border: 1px solid #ececec;
  background: #fff;
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.field__error {
  display: block;
  font-size: 12px;
  color: #d24b4b;
  margin-top: 4px;
}
</style>
