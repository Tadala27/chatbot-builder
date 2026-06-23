<script setup lang="ts">
import { computed } from "vue";
import { useRouter, useRoute } from "vue-router";
import { useUserStore } from "@/stores/user";
import { sidebarItems } from "@layouts/components/vertical-sidebar/sidebarItem";
import Logo from "@layouts/components/LogoMain.vue";

const router = useRouter();
const route = useRoute();
const userStore = useUserStore();

// ── Flatten sidebar items — collapse all groups into a single icon list ───────
// Children are flattened up one level; grandchildren are dropped.
// Items with no icon or that are pure dividers are kept as dividers.
interface FlatItem {
  type: "divider" | "item";
  title?: string;
  icon?: string;
  to?: string | object;
  href?: string;
  disabled?: boolean;
}

const flatItems = computed<FlatItem[]>(() => {
  const result: FlatItem[] = [];

  for (const item of sidebarItems.value) {
    if (item.divider) {
      result.push({ type: "divider" });
      continue;
    }

    // Group header: push its children as flat items, skip the label itself
    if (item.children?.length) {
      for (const child of item.children) {
        if (child.divider) {
          result.push({ type: "divider" });
          continue;
        }
        if (child.icon) {
          result.push({
            type: "item",
            title: child.title,
            icon: child.icon,
            to: child.to,
            disabled: child.disabled,
          });
        }
      }
      continue;
    }

    // Top-level single item
    if (item.icon) {
      result.push({
        type: "item",
        title: item.title,
        icon: item.icon,
        to: item.to,
        disabled: item.disabled,
      });
    }
  }

  return result;
});

// ── Active state ──────────────────────────────────────────────────────────────
function isActive(item: FlatItem): boolean {
  if (!item.to) return false;
  const resolved = router.resolve(item.to as any);
  return route.path.startsWith(resolved.path);
}

function navigate(item: FlatItem): void {
  if (item.disabled) return;
  if (item.to) {
    router.push(item.to as any);
  } else if (item.href) {
    window.open(item.href, "_blank");
  }
}
</script>

<template>
  <nav class="icon-sidebar" aria-label="Navigation">
    <!-- Logo mark -->
    <div class="icon-sidebar__logo">
      <Logo />
    </div>

    <!-- Loading -->
    <div
      v-if="!userStore.isLoaded || userStore.isLoading"
      class="icon-sidebar__loading"
    >
      <VProgressCircular indeterminate color="primary" size="22" width="2" />
    </div>

    <!-- Items -->
    <ul v-else class="icon-sidebar__list" role="menubar">
      <template v-for="(item, i) in flatItems" :key="i">
        <!-- Divider -->
        <li
          v-if="item.type === 'divider'"
          class="icon-sidebar__divider"
          role="separator"
        />

        <!-- Nav button -->
        <li v-else role="none">
          <VTooltip location="right" :text="item.title" :open-delay="120">
            <template #activator="{ props: tip }">
              <button
                v-bind="tip"
                class="icon-sidebar__btn"
                :class="{
                  'icon-sidebar__btn--active': isActive(item),
                  'icon-sidebar__btn--disabled': item.disabled,
                }"
                :aria-label="item.title"
                :aria-current="isActive(item) ? 'page' : undefined"
                :disabled="item.disabled"
                role="menuitem"
                @click="navigate(item)"
              >
                <VIcon :icon="item.icon" size="20" />
              </button>
            </template>
          </VTooltip>
        </li>
      </template>
    </ul>

    <!-- Bottom: user avatar shortcut -->
    <div class="icon-sidebar__footer">
      <VTooltip
        location="right"
        :text="userStore.displayName || 'Profile'"
        :open-delay="120"
      >
        <template #activator="{ props: tip }">
          <button
            v-bind="tip"
            class="icon-sidebar__avatar"
            aria-label="Profile"
            @click="router.push('/profile')"
          >
            <span class="icon-sidebar__avatar-initials">
              {{ (userStore.displayName || "?")[0].toUpperCase() }}
            </span>
          </button>
        </template>
      </VTooltip>
    </div>
  </nav>
</template>

<style scoped>
/* ── Shell ─────────────────────────────────────────────────────────────────── */
.icon-sidebar {
  --sidebar-w: 56px;
  --sidebar-bg: #ffffff;
  --sidebar-border: rgba(0, 0, 0, 0.07);
  --item-radius: 10px;
  --active-color: #273775; /* matches your brand primary */
  --active-bg: rgba(39, 55, 117, 0.09);
  --hover-bg: rgba(39, 55, 117, 0.05);
  --icon-color: #6b7280;
  --icon-active: #273775;

  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  width: var(--sidebar-w);
  background: var(--sidebar-bg);
  border-right: 1px solid var(--sidebar-border);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0;
  z-index: 1000;
  box-shadow: 1px 0 12px rgba(0, 0, 0, 0.04);
  /* No transition, no expand — permanently collapsed */
}

/* ── Logo ──────────────────────────────────────────────────────────────────── */
.icon-sidebar__logo {
  width: 100%;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border-bottom: 1px solid var(--sidebar-border);
  padding: 0 8px;
  overflow: hidden;
}

/* Clip the full logo to icon-only width — works if LogoMain renders an SVG or img */
.icon-sidebar__logo :deep(img),
.icon-sidebar__logo :deep(svg) {
  max-width: 32px;
  max-height: 32px;
  object-fit: contain;
}

/* ── Loading ───────────────────────────────────────────────────────────────── */
.icon-sidebar__loading {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ── List ──────────────────────────────────────────────────────────────────── */
.icon-sidebar__list {
  list-style: none;
  margin: 0;
  padding: 10px 0;
  flex: 1;
  width: 100%;
  overflow-y: auto;
  overflow-x: hidden;
  scrollbar-width: none;
}
.icon-sidebar__list::-webkit-scrollbar {
  display: none;
}

/* ── Divider ───────────────────────────────────────────────────────────────── */
.icon-sidebar__divider {
  height: 1px;
  background: var(--sidebar-border);
  margin: 6px 10px;
}

/* ── Nav button ────────────────────────────────────────────────────────────── */
.icon-sidebar__btn {
  position: relative;
  width: 36px;
  height: 36px;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: var(--item-radius);
  background: transparent;
  color: var(--icon-color);
  cursor: pointer;
  transition:
    background 0.15s,
    color 0.15s;
  /* Center within sidebar */
  left: 50%;
  transform: translateX(-50%);
}

.icon-sidebar__btn:hover:not(:disabled) {
  background: var(--hover-bg);
  color: var(--active-color);
}

.icon-sidebar__btn--active {
  background: var(--active-bg) !important;
  color: var(--icon-active) !important;
}

.icon-sidebar__btn--disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

/* ── Active pip (small indicator dot on the left edge) ─────────────────────── */
.icon-sidebar__pip {
  position: absolute;
  left: -10px;
  top: 50%;
  transform: translateY(-50%);
  width: 3px;
  height: 18px;
  background: var(--active-color);
  border-radius: 0 3px 3px 0;
}

/* ── Footer (user avatar) ──────────────────────────────────────────────────── */
.icon-sidebar__footer {
  width: 100%;
  padding: 10px 0 14px;
  display: flex;
  justify-content: center;
  border-top: 1px solid var(--sidebar-border);
  flex-shrink: 0;
}

.icon-sidebar__avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: var(--active-bg);
  color: var(--active-color);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s;
}
.icon-sidebar__avatar:hover {
  background: rgba(39, 55, 117, 0.16);
}

.icon-sidebar__avatar-initials {
  font-size: 13px;
  font-weight: 600;
  line-height: 1;
  user-select: none;
}
</style>
