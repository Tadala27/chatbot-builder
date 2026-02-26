<script setup lang="ts">
defineProps<{
  flow: any;
  flowVersion: any;
  flowVersions: any[];
  versionOptions: any[];
  currentVersionLabel: string;
  selectedVersionId: number | null;
  nodesCount: number;
  saveStatus: 'saved' | 'saving' | 'unsaved';
}>();

const emit = defineEmits<{
  (e: "update:selectedVersionId", value: number): void;
  (e: "createNewVersion"): void;
  (e: "publish"): void;
  (e: "back"): void;
}>();
</script>

<template>
  <v-app-bar color="white" elevation="1" density="compact">
    <v-btn icon="$arrowLeft" @click="emit('back')" size="small" class="ml-2" />

    <v-toolbar-title>
      <span class="font-weight-bold">{{ flow?.name || "Flow Builder" }}</span>
      <v-chip
        :color="flowVersion?.status === 'published' ? 'success' : 'warning'"
        size="small"
        class="ml-2"
        variant="flat"
      >
        {{ flowVersion?.status || "draft" }}
      </v-chip>
    </v-toolbar-title>

    <!-- VERSION DROPDOWN WITH NEW VERSION BUTTON -->
    <v-menu v-if="flowVersions.length > 0">
      <template #activator="{ props }">
        <v-chip
          v-bind="props"
          size="small"
          rounded="sm"
          class="ml-2"
          variant="outlined"
          prepend-icon="$history"
        >
          {{ currentVersionLabel }}
          <v-icon icon="$chevronDown" size="x-small" class="ml-1" />
        </v-chip>
      </template>

      <v-card min-width="320">
        <v-card-title class="text-subtitle-2 bg-grey-lighten-4 d-flex align-center justify-space-between">
          <div>
            <v-icon icon="$history" size="small" class="mr-2" />
            Flow Versions
          </div>
          <v-btn
            size="x-small"
            variant="text"
            color="primary"
            prepend-icon="$plus"
            @click="emit('createNewVersion')"
            :disabled="flowVersion?.status === 'draft'"
          >
            New Version
          </v-btn>
        </v-card-title>
        <v-divider />

        <v-card-text class="pa-0">
          <v-list density="compact" max-height="400" style="overflow-y: auto">
            <v-list-item
              v-for="version in versionOptions"
              :key="version.value"
              @click="emit('update:selectedVersionId', version.value)"
              :active="version.value === selectedVersionId"
              :class="{
                'bg-primary-lighten-5': version.value === selectedVersionId,
              }"
            >
              <template #prepend>
                <v-chip
                  size="x-small"
                  :color="
                    version.status === 'published'
                      ? 'success'
                      : version.status === 'draft'
                        ? 'warning'
                        : 'default'
                  "
                  variant="flat"
                >
                  v{{ version.version_number }}
                </v-chip>
              </template>

              <v-list-item-title class="text-body-2">
                {{ version.status.toUpperCase() }}
              </v-list-item-title>

              <v-list-item-subtitle class="text-caption">
                {{ version.title.split(" - ")[1] }}
              </v-list-item-subtitle>

              <template #append v-if="version.value === selectedVersionId">
                <v-icon icon="$check" size="small" color="primary" />
              </template>
            </v-list-item>
          </v-list>
        </v-card-text>

        <!-- Tip at bottom -->
        <v-divider />
        <v-card-text class="pa-2 text-caption text-grey-darken-1 bg-grey-lighten-5">
          <v-icon icon="$information" size="x-small" class="mr-1" />
          Create a new version to work on published flows
        </v-card-text>
      </v-card>
    </v-menu>

    <v-spacer />

    <!-- NODE COUNT -->
    <span class="text-caption text-grey-darken-2 mr-4">
      {{ nodesCount }} node{{ nodesCount !== 1 ? "s" : "" }}
    </span>

    <!-- SAVE STATUS INDICATOR -->
    <v-chip
      v-if="saveStatus === 'saving'"
      size="small"
      variant="text"
      prepend-icon="$loading"
      class="mr-2"
    >
      <v-progress-circular
        indeterminate
        size="14"
        width="2"
        class="mr-2"
      />
      Saving...
    </v-chip>

    <v-chip
      v-else-if="saveStatus === 'saved'"
      size="small"
      variant="text"
      color="success"
      prepend-icon="$checkCircle"
      class="mr-2"
    >
      Saved
    </v-chip>

    <v-chip
      v-else-if="saveStatus === 'unsaved'"
      size="small"
      variant="text"
      color="warning"
      prepend-icon="$alertCircle"
      class="mr-2"
    >
      Unsaved changes
    </v-chip>

    <!-- PUBLISH BUTTON (Only for draft versions) -->
    <v-btn
      v-if="flowVersion?.status === 'draft'"
      color="success"
      @click="emit('publish')"
      prepend-icon="$publish"
      variant="flat"
      class="mr-2"
      size="small"
    >
      Publish
    </v-btn>

    <!-- INFO FOR PUBLISHED VERSIONS -->
    <v-tooltip v-else location="bottom">
      <template #activator="{ props }">
        <v-chip
          v-bind="props"
          color="success"
          variant="tonal"
          size="small"
          prepend-icon="$checkCircle"
          class="mr-2"
        >
          Published
        </v-chip>
      </template>
      This version is live. Create a new version to make changes.
    </v-tooltip>
  </v-app-bar>
</template>

<style scoped>
/* Add smooth transition for save status */
.v-chip {
  transition: all 0.3s ease;
}
</style>