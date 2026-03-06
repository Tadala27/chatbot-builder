<!-- FlowBuilderToolbar.vue -->
<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  flow: any
  flowVersion: any
  flowVersions: any[]
  selectedVersionId: number | null
  saveStatus: 'saved' | 'saving' | 'unsaved'
  saveIndicatorColor: string
  saveIndicatorIcon: string
  saveIndicatorText: string
  versionColor: Record<string, string>
}>()

const emit = defineEmits<{
  (e: 'update:selectedVersionId', value: number): void
  (e: 'createNewVersion'): void
  (e: 'publish'): void
}>()

const currentVersion = computed(() =>
  props.flowVersions.find(v => v.id === props.selectedVersionId)
)

const versionLabel = computed(() => {
  if (!currentVersion.value) return 'Select Version'
  return `v${currentVersion.value.version_number} - ${currentVersion.value.status}`
})
</script>

<template>
  <VToolbar density="compact" border="b" elevation="0" color="surface">

    <VSpacer />

    <!-- Save indicator -->
    <VChip :color="saveIndicatorColor" variant="tonal" size="small" :prepend-icon="saveIndicatorIcon" class="mr-2">
      {{ saveIndicatorText }}
    </VChip>

    <!-- Version picker with dropdown menu -->
    <VMenu v-if="flowVersions.length > 0">
      <template #activator="{ props }">
        <VChip v-bind="props" rounded="sm" class="mr-2" variant="outlined" prepend-icon="$history">
          {{ versionLabel }}
          <VIcon icon="$chevronDown" size="x-small" class="ml-1" />
        </VChip>
      </template>

      <VCard min-width="320">
        <VCardTitle class="text-subtitle-2 bg-grey-lighten-4 d-flex align-center justify-space-between">
          <div>
            <VIcon icon="$history" size="small" class="mr-2" />
            Flow Versions
          </div>
          <VBtn size="x-small" variant="text" color="primary" prepend-icon="$sourceBranchPlus"
            @click="emit('createNewVersion')"
            :disabled="flowVersions.find(v => v.id === selectedVersionId)?.status === 'draft'">
            New Version
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText class="pa-0">
          <VList density="compact" max-height="400" style="overflow-y: auto">
            <VListItem v-for="version in flowVersions" :key="version.id"
              @click="emit('update:selectedVersionId', version.id)" :active="version.id === selectedVersionId"
              :class="{ 'bg-primary-lighten-5': version.id === selectedVersionId }">
              <template #prepend>
                <VChip size="x-small"
                  :color="version.status === 'published' ? 'success' : version.status === 'draft' ? 'warning' : 'default'"
                  variant="flat">
                  v{{ version.version_number }}
                </VChip>
              </template>

              <VListItemTitle class="text-body-2 mx-1 text-capitalize">
                {{ version.status }}
              </VListItemTitle>

              <VListItemSubtitle class="text-caption">
                {{ version.title?.split(" - ")[1] || '' }}
              </VListItemSubtitle>

              <template #append v-if="version.id === selectedVersionId">
                <VIcon icon="$check" size="small" color="primary" />
              </template>
            </VListItem>
          </VList>
        </VCardText>

        <VDivider />

        <VCardText class="pa-2 text-caption text-grey-darken-1 bg-grey-lighten-5">
          <VIcon icon="$sourceBranchPlus" size="x-small" class="mr-1" />
          Create a new version to work on published flows
        </VCardText>
      </VCard>
    </VMenu>

    <VBtn v-if="flowVersion?.status !== 'published'" color="primary" size="small" rounded="lg"
      prepend-icon="$rocketLaunchOutline" @click="emit('publish')">
      Publish
    </VBtn>

    <VChip v-else color="success" variant="tonal" prepend-icon="$checkCircle" size="small">
      Live
    </VChip>

    <template #extension>
      <!-- Published read-only banner -->
      <VAlert v-if="flowVersion?.status === 'published'" type="info" variant="tonal" density="compact" rounded="0"
        border="start" class="w-100 ma-0" style="border-radius: 0 !important;" icon="$information">
        This version is live and read-only.
        <template #append>
          <VBtn size="x-small" variant="outlined" rounded="lg" @click="emit('createNewVersion')">
            Branch New Version
          </VBtn>
        </template>
      </VAlert>
    </template>
  </VToolbar>
</template>