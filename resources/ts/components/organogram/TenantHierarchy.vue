<!-- TenantHierarchy.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import TenantNode from './TenantNode.vue';   // ← import here

const props = defineProps<{
    hierarchy: any;
    currentTenant: any;
}>();

const emit = defineEmits<{
    (e: 'navigate-to-tenant', tenantId: number): void;
}>();

const hierarchyData = computed(() => {
    if (Array.isArray(props.hierarchy)) {
        return props.hierarchy;
    }
    return props.hierarchy ? [props.hierarchy] : [];
});

const handleTenantClick = (tenantId: number) => {
    emit('navigate-to-tenant', tenantId);
};
</script>

<template>
    <v-card elevation="2">
        <v-card-title class="bg-primary text-white">
            <v-icon start>$domain</v-icon>
            Tenant Hierarchy
            <v-chip v-if="currentTenant" size="small" color="white" class="ml-2">
                {{ currentTenant.name }}
            </v-chip>
        </v-card-title>

        <v-card-text class="pa-6">
            <div class="tenant-hierarchy-container">
                <div v-for="node in hierarchyData" :key="node.id" class="tenant-node-wrapper">
                    <TenantNode :node="node" :level="0" @navigate-to-tenant="handleTenantClick" />
                </div>

                <v-alert v-if="!hierarchyData.length" type="info" density="compact">
                    No tenant hierarchy data available
                </v-alert>
            </div>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.tenant-hierarchy-container {
    min-height: 400px;
}
</style>