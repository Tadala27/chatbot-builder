<script setup lang="ts">
/**
 * components/ListDialog.vue
 *
 * Shows the rows/buttons of an outbound interactive (list/button) message
 * so an agent can see what the bot offered the contact.
 *
 * Deliberately READ-ONLY: selecting a row only highlights it for
 * reference. It never sends anything to WhatsApp or to the backend —
 * that action belongs to the contact's device, not the agent console.
 */
import { ref } from "vue";
import type { InteractiveOption } from "@/chat/chat";

defineProps<{
  open: boolean;
  kind: "list" | "button";
  header?: string;
  body?: string;
  footer?: string;
  options: InteractiveOption[];
}>();

const emit = defineEmits<{ close: [] }>();

const highlighted = ref<string | null>(null);

function selectOption(id: string) {
  // Display-only: marks which row the agent is looking at. Intentionally
  // does NOT call any send/dispatch function — this is not a live action.
  highlighted.value = id;
}

function close() {
  highlighted.value = null;
  emit("close");
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="iod__overlay" @click.self="close">
      <div class="iod__panel" role="dialog" aria-modal="true">
        <header class="iod__header">
          <span class="iod__title">{{
            kind === "list" ? "List message" : "Button message"
          }}</span>
          <button class="iod__close" aria-label="Close" @click="close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
              <path
                d="M6 6l12 12M18 6L6 18"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
              />
            </svg>
          </button>
        </header>

        <div class="iod__body">
          <ul class="iod__options">
            <li
              v-for="option in options"
              :key="option.id"
              class="iod__option"
              :class="{ 'iod__option--active': highlighted === option.id }"
              @click="selectOption(option.id)"
            >
              <span class="iod__option-title">{{ option.title }}</span>
              <span v-if="option.description" class="iod__option-desc">{{
                option.description
              }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.iod__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 60;
}
.iod__panel {
  width: 360px;
  max-height: 80vh;
  overflow-y: auto;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
}
.iod__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 18px;
  border-bottom: 1px solid #ececec;
}
.iod__title {
  font-size: 14px;
  font-weight: 600;
  color: #1a1a1a;
}
.iod__close {
  border: none;
  background: transparent;
  color: #8b8b8b;
  display: flex;
  cursor: pointer;
}
.iod__body {
  padding: 16px 18px 18px;
}
.iod__lead {
  font-size: 13.5px;
  line-height: 1.5;
  color: #1a1a1a;
  margin: 0 0 12px;
}
.iod__lead--header {
  font-weight: 600;
}
.iod__options {
  list-style: none;
  margin: 0 0 12px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.iod__option {
  border: 1px solid #ececec;
  border-radius: 12px;
  padding: 10px 12px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.iod__option--active {
  border-color: #1a1a1a;
  background: #f9f9f9;
}
.iod__option-title {
  font-size: 13.5px;
  font-weight: 500;
  color: #1a1a1a;
}
.iod__option-desc {
  font-size: 12px;
  color: #8b8b8b;
}
.iod__footer {
  font-size: 12px;
  color: #8b8b8b;
  margin: 0 0 10px;
}
.iod__note {
  font-size: 11px;
  color: #b3b3b3;
  margin: 0;
  font-style: italic;
}
</style>
