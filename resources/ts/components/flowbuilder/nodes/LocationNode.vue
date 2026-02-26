<script setup lang="ts">
import type { FlowNode } from "../types";

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
}>();

// Initialize location fields if not set
if (props.node.locationLatitude === undefined) {
  props.node.locationLatitude = 0;
}
if (props.node.locationLongitude === undefined) {
  props.node.locationLongitude = 0;
}
</script>

<template>
  <div>
    <v-row>
      <v-col cols="6">
        <v-text-field
          v-model.number="node.locationLatitude"
          label="Latitude"
          placeholder="-25.7479"
          variant="outlined"
          density="compact"
          type="number"
          step="any"
          hint="Example: -25.7479"
          persistent-hint
        >
          <template #prepend-inner>
            <v-icon icon="$latitude" size="small" />
          </template>
        </v-text-field>
      </v-col>
      <v-col cols="6">
        <v-text-field
          v-model.number="node.locationLongitude"
          label="Longitude"
          placeholder="28.2293"
          variant="outlined"
          density="compact"
          type="number"
          step="any"
          hint="Example: 28.2293"
          persistent-hint
        >
          <template #prepend-inner>
            <v-icon icon="$longitude" size="small" />
          </template>
        </v-text-field>
      </v-col>
    </v-row>

    <v-text-field
      v-model="node.locationName"
      label="Location Name"
      placeholder="OneNICO Office"
      variant="outlined"
      density="compact"
      class="mt-2"
      hint="Display name for the location"
      persistent-hint
    >
      <template #prepend-inner>
        <v-icon icon="$mapMarker" size="small" />
      </template>
    </v-text-field>

    <v-textarea
      v-model="node.locationAddress"
      label="Address (optional)"
      placeholder="123 Main Street, City"
      variant="outlined"
      density="compact"
      rows="2"
      class="mt-3"
      hint="Full address of the location"
      persistent-hint
    >
      <template #prepend-inner>
        <v-icon icon="$home" size="small" />
      </template>
    </v-textarea>

    <v-divider class="my-4" />

    <!-- Preview -->
    <v-card variant="tonal" class="mb-3">
      <v-card-text class="text-caption">
        <div class="d-flex align-center mb-2">
          <v-icon icon="$mapMarker" size="small" class="mr-2" color="error" />
          <span class="font-weight-medium">LOCATION PIN</span>
        </div>
        <div v-if="node.locationName">
          <strong>{{ node.locationName }}</strong>
        </div>
        <div
          v-if="node.locationLatitude && node.locationLongitude"
          class="text-grey"
        >
          {{ node.locationLatitude }}, {{ node.locationLongitude }}
        </div>
        <div v-if="node.locationAddress" class="mt-1">
          {{ node.locationAddress }}
        </div>
        <v-alert
          v-if="!node.locationLatitude || !node.locationLongitude"
          type="warning"
          variant="tonal"
          density="compact"
          class="mt-2"
        >
          Please enter valid coordinates
        </v-alert>
      </v-card-text>
    </v-card>

    <v-select
      v-model="node.goTo"
      label="Then go to"
      :items="nodeOptions.filter((o) => o.value !== node.id)"
      variant="outlined"
      density="compact"
    >
      <template #prepend-inner>
        <v-icon icon="$navigationVariant" size="small" />
      </template>
    </v-select>
  </div>
</template>