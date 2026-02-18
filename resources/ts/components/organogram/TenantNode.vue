<!-- TenantNode.vue -->
<template>
    <div class="tenant-node" :style="{ marginLeft: level * 40 + 'px' }">
        <v-card class="mb-3 tenant-card" :class="{ 'is-parent': node.is_parent }" elevation="1"
            @click="$emit('navigate-to-tenant', node.id)" style="cursor: pointer">
            <v-card-text class="d-flex align-center py-3">
                <v-avatar :color="node.is_parent ? 'primary' : 'secondary'" size="48" class="mr-4">
                    <v-icon color="white">
                        {{ node.is_parent ? '$domain' : '$officeBuilding' }}
                    </v-icon>
                </v-avatar>

                <div class="flex-grow-1">
                    <div class="d-flex align-center">
                        <h6 class="text-h6 mb-0">{{ node.name }}</h6>
                        <v-chip size="x-small" class="ml-2" variant="outlined">
                            {{ node.code }}
                        </v-chip>
                        <v-chip v-if="node.is_parent" size="x-small" color="primary" class="ml-2">
                            Parent
                        </v-chip>
                    </div>

                    <div class="d-flex gap-4 mt-2">
                        <div class="d-flex align-center">
                            <v-icon size="small" class="mr-1">$account</v-icon>
                            <span class="text-caption">
                                {{ node.stats?.users_count ?? 0 }} Users
                            </span>
                        </div>
                        <div class="d-flex align-center">
                            <v-icon size="small" class="mr-1">$briefcase</v-icon>
                            <span class="text-caption">
                                {{ node.stats?.positions_count ?? 0 }} Positions
                            </span>
                        </div>
                        <div class="d-flex align-center">
                            <v-icon size="small" class="mr-1">$chartBox</v-icon>
                            <span class="text-caption">
                                {{ node.stats?.active_scorecards ?? 0 }} Active Scorecards
                            </span>
                        </div>
                    </div>
                </div>

                <v-icon class="ml-2">$chevronRight</v-icon>
            </v-card-text>
        </v-card>

        <!-- Recursive children -->
        <div v-if="node.children?.length" class="tenant-children">
            <TenantNode v-for="child in node.children" :key="child.id" :node="child" :level="level + 1"
                @navigate-to-tenant="$emit('navigate-to-tenant', $event)" />
        </div>
    </div>
</template>

<script setup lang="ts">
defineProps<{
    node: any;
    level: number;
}>();

defineEmits<{
    (e: 'navigate-to-tenant', tenantId: number): void;
}>();
</script>

<style scoped>
.tenant-node {
    position: relative;
}

.tenant-card {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.tenant-card:hover {
    transform: translateX(4px);
    border-left-color: rgb(var(--v-theme-primary));
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}

.tenant-card.is-parent {
    border-left-color: rgba(var(--v-theme-primary), 0.3);
}

.tenant-children {
    position: relative;
}

.tenant-children::before {
    content: "";
    position: absolute;
    left: 24px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: rgba(var(--v-theme-primary), 0.2);
}
</style>