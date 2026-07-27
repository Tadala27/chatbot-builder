<script setup lang="ts">
import { ref, watch, onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import { useCustomizerStore } from "@layouts/stores/customizer";
import { useUserStore } from "@/stores/user"; // ✅ Use the store
import ProfileDD from "@layouts/components/vertical-header/ProfileDD.vue";

const router = useRouter();
const customizer = useCustomizerStore();
const userStore = useUserStore(); // ✅ Get the store

const messagedrawer = ref(false);
const priority = ref(customizer.isHorizontalLayout ? 1 : 0);

// ✅ Use computed to get user data from store
const userData = computed(() => userStore.user);
const isLoading = computed(() => userStore.isLoading);
const error = computed(() => userStore.error);

// Watch for priority changes
watch(priority, (newPriority) => {
  priority.value = newPriority;
});

// ✅ Fetch user on mount using the store
onMounted(async () => {
  if (!userStore.isLoaded) {
    await userStore.fetchUser();
  }
});
</script>

<template>
  <VProgressLinear
    v-if="isLoading"
    indeterminate
    color="primary"
    height="4"
    class="mb-6"
  />

  <VAppBar
    v-else
    elevation="0"
    :priority="priority"
    height="55"
    class="px-sm-10 px-5 bg-white"
  >
    <!-- Search mobile -->
    <VMenu
      :close-on-content-click="false"
      class="hidden-lg-and-up"
      offset="10, 0"
    >
      <template #activator="{ props }">
        <VBtn
          class="hidden-lg-and-up ml-1"
          color="secondary"
          icon
          rounded="sm"
          variant="text"
          size="small"
          v-bind="props"
        >
          <SvgSprite name="custom-search" style="width: 16px; height: 16px" />
        </VBtn>
      </template>
      <VSheet
        class="search-sheet v-col-12 pa-0"
        elevation="24"
        width="320"
        rounded="md"
      >
        <VTextField
          persistent-placeholder
          placeholder="Search here.."
          rounded="md"
          color="primary"
          variant="solo"
          hide-details
        >
          <template #prepend-inner>
            <SvgSprite name="custom-search" style="width: 16px; height: 16px" />
          </template>
        </VTextField>
      </VSheet>
    </VMenu>

    <VSpacer />

    <!-- Notifications -->
    <!-- <NotificationDD /> -->

    <!-- User profile -->
    <VMenu :close-on-content-click="false" offset="8, 0">
      <template #activator="{ props }">
        <VBtn
          class="profileBtn me-0"
          aria-label="profile"
          variant="text"
          rounded="circle"
          icon
          v-bind="props"
        >
          <VAvatar
            v-if="userData?.avatar"
            size="40"
            rounded="circle"
            class="py-2"
          >
            <img
              :src="userData.avatar"
              width="40"
              class="rounded-circle"
              alt="user"
            />
          </VAvatar>
          <VAvatar
            v-else
            variant="tonal"
            color="primary"
            rounded="circle"
            class="py-2"
          >
            <SvgSprite
              name="custom-user-fill"
              style="width: 20px; height: 20px"
            />
          </VAvatar>
        </VBtn>
      </template>
      <VSheet rounded="md" width="290">
        <ProfileDD />
      </VSheet>
    </VMenu>
  </VAppBar>

  <VDivider />
</template>
