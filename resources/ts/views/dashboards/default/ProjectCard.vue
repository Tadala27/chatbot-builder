<script setup lang="ts">
import { shallowRef } from "vue";

const projectitems = shallowRef([
  {
    color: "warning",
    name: "Horizontal Layout",
    task: "2",
  },
  {
    color: "warning",
    name: "Invoice Generator",
  },
  {
    color: "primary",
    name: "Package Upgrades",
  },
  {
    color: "success",
    name: "Figma Auto layout",
  },
]);
</script>

<template>
  <CardHeader title="Project - NICO Technologies Ltd" class="overflow-hidden">
    <div class="pa-6">
      <div class="d-flex justify-space-between mb-2">
        <p class="text-body-1 mb-0">Release v1.2.0</p>
        <p class="text-body-1 mb-0">72%</p>
      </div>
      <VProgressLinear
        aria-label="progressbar"
        rounded
        color="primary"
        model-value="72"
        height="6"
      />
      <VList class="py-5" aria-busy="true" aria-label="project list">
        <VListItem
          v-for="(item, index) in projectitems"
          :key="index"
          :value="index"
          rounded="md"
        >
          <template #prepend>
            <VAvatar size="8" :color="item.color" />
          </template>
          <span class="text-body-1">{{ item.name }}</span>
          <template #append>
            <VChip v-if="item.task" size="small" rounded="md">
              <template #prepend>
                <SvgSprite
                  name="custom-link1"
                  class="mr-1"
                  style="width: 12px; height: 12px"
                />
              </template>
              {{ item.task }}
            </VChip>
          </template>
        </VListItem>
      </VList>
      <VBtn color="primary" variant="flat" rounded="md" block>
        <template #prepend>
          <SvgSprite name="custom-plus" style="width: 18px; height: 18px" />
        </template>
        Add Task
      </VBtn>
    </div>
  </CardHeader>
</template>
