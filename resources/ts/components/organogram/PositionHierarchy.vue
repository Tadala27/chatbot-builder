<!-- src/components/organogram/PositionHierarchy.vue -->

<template>
    <div>

        <!-- Tree View Controls -->
        <v-card class="mb-4" elevation="0">
            <v-card-text class="d-flex gap-2 align-center">
                <v-btn variant="outlined" size="small" class="mx-2" prepend-icon="$unfoldMoreHorizontal"
                    @click="expandAll">
                    Expand All
                </v-btn>
                <v-btn variant="outlined" size="small" prepend-icon="$unfoldLessHorizontal" @click="collapseAll">
                    Collapse All
                </v-btn>
                <v-spacer></v-spacer>
                <div class="me-2">
                    <v-btn-toggle v-model="useTreeView" color="primary" variant="outlined" divided mandatory
                        density="compact">
                        <v-btn :value="true" size="small" prepend-icon="$fileTree">
                            Tree View
                        </v-btn>
                        <v-btn :value="false" size="small" prepend-icon="$formatListBulleted">
                            List View
                        </v-btn>
                    </v-btn-toggle>
                </div>
                <v-chip size="small" variant="tonal" color="primary">
                    <v-icon start size="small">$accountGroup</v-icon>
                    {{ stats.total_positions || 0 }} positions
                </v-chip>
            </v-card-text>
        </v-card>

        <!-- Tree View -->
        <v-card v-if="useTreeView" elevation="2">
            <v-card-title class="bg-grey-lighten-4">
                <v-icon start>$fileTree</v-icon>
                {{ viewType === "by_position" ? "Position Hierarchy" : "Business Unit Structure" }}
            </v-card-title>
            <v-card-text class="organogram-container" :style="{ cursor: isPanning ? 'grabbing' : 'grab' }">
                <div ref="containerRef" class="pan-wrapper">
                    <div class="organogram-content" :style="{
                        transform: `scale(${zoomLevel / 100}) translate(${panOffset.x}px, ${panOffset.y}px)`,
                        transformOrigin: 'top left',
                    }">
                        <div v-if="processedHierarchy.length === 0" class="text-center py-16">
                            <v-icon size="64" color="grey-lighten-2">$fileTree</v-icon>
                            <p class="text-h6 mt-4 text-grey">No organizational data available</p>
                        </div>
                        <div v-else class="hierarchy-tree">
                            <PositionNode v-for="node in processedHierarchy" :key="node.id" :node="node"
                                :view-type="viewType" @show-details="handlePositionClick"
                                @width-calculated="handleWidthCalculated" />
                        </div>
                    </div>
                </div>
            </v-card-text>
        </v-card>

        <!-- List View (for large datasets) -->
        <v-card v-else elevation="2">
            <v-card-title class="bg-grey-lighten-4">
                <v-icon start>$formatListBulleted</v-icon>
                Position List
                <v-chip size="small" class="ml-2">
                    {{ visibleNodes.length }} / {{ flattenedNodes.length }} positions
                </v-chip>
            </v-card-title>
            <v-card-text class="pa-0">
                <v-list style="max-height: 600px; overflow-y: auto;">
                    <TransitionGroup name="expand" tag="div">
                        <v-list-item v-for="item in visibleNodes" :key="item.id" class="border-b"
                            :style="{ paddingLeft: item.level * 28 + 16 + 'px' }">
                            <template #prepend>
                                <!-- Expand/collapse icon for items with children -->
                                <v-btn v-if="item.hasChildren" variant="text" size="small" icon
                                    @click.stop="toggleExpand(item.id)" class="mr-1">
                                    <v-icon size="small">
                                        {{ expandedNodes[item.id] ? '$chevronDown' : '$chevronRight' }}
                                    </v-icon>
                                </v-btn>
                                <div v-else style="width: 40px;"></div>

                                <v-avatar :color="item.is_vacant
                                    ? 'grey-lighten-2'
                                    : item.license_type === 'supervisor'
                                        ? 'success'
                                        : 'primary'
                                    " size="40">
                                    <v-icon v-if="item.is_vacant">$accountOff</v-icon>
                                    <v-icon v-else>
                                        {{
                                            item.license_type === "supervisor"
                                                ? "$accountStar"
                                                : "$account"
                                        }}
                                    </v-icon>
                                </v-avatar>
                            </template>

                            <v-list-item-title @click="handlePositionClick(item.id)" class="position-title">
                                {{ item.name }}
                                <v-chip v-if="item.level" size="x-small" :color="getPositionLevelColor(item.level)"
                                    class="ml-2">
                                    L{{ item.level }}
                                </v-chip>
                            </v-list-item-title>

                            <v-list-item-subtitle @click="handlePositionClick(item.id)" class="position-title">
                                <span v-if="item.current_holder">
                                    {{ item.current_holder.name }}
                                </span>
                                <span v-else class="text-error">VACANT</span>
                                <span v-if="item.business_unit" class="ml-2">
                                    | {{ item.business_unit.name }}
                                </span>
                            </v-list-item-subtitle>

                            <template #append>
                                <v-chip size="small" :color="item.is_vacant ? 'error' : 'success'" variant="tonal">
                                    {{ item.is_vacant ? "Vacant" : "Filled" }}
                                </v-chip>
                            </template>
                        </v-list-item>
                    </TransitionGroup>
                </v-list>
            </v-card-text>
        </v-card>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import PositionNode from "./PositionNode.vue";

const props = defineProps<{
    hierarchy: any;
    zoomLevel: number;
    viewType: "by_position" | "by_business_unit";
}>();

const emit = defineEmits<{
    (e: "show-details", positionId: number): void;
}>();

// Container ref for panning
const containerRef = ref<HTMLElement | null>(null);
const isPanning = ref(false);
const panStart = ref({ x: 0, y: 0 });
const panOffset = ref({ x: 0, y: 0 });

// Flatten hierarchy for virtual scrolling (list view fallback)
const flattenedNodes = ref<any[]>([]);
const useTreeView = ref(true);

// Expand/Collapse state management
const expandCollapseKey = ref(0);
const expandedNodes = ref<Record<number, boolean>>({});

// Process hierarchy
const processedHierarchy = computed(() => {
    if (!props.hierarchy || !props.hierarchy.nodes) return [];
    return props.hierarchy.nodes;
});

// Statistics
const stats = computed(() => {
    return props.hierarchy?.stats || {};
});

// Flatten nodes for list view with parent tracking
const flattenHierarchy = (nodes: any[], level = 0, parentId: number | null = null): any[] => {
    let result: any[] = [];
    nodes.forEach((node) => {
        const hasChildren = node.children && node.children.length > 0;
        result.push({
            ...node,
            level,
            parentId,
            hasChildren
        });
        if (hasChildren) {
            result = result.concat(flattenHierarchy(node.children, level + 1, node.id));
        }
    });
    return result;
};

// Compute visible nodes based on expand/collapse state
const visibleNodes = computed(() => {
    const result: any[] = [];
    const nodesToCheck = flattenedNodes.value.filter(n => n.level === 0);

    const addNodeAndChildren = (nodeId: number) => {
        const node = flattenedNodes.value.find(n => n.id === nodeId);
        if (!node) return;

        result.push(node);

        // If this node is expanded and has children, add them
        if (expandedNodes.value[nodeId] && node.hasChildren) {
            const children = flattenedNodes.value.filter(n => n.parentId === nodeId);
            children.forEach(child => addNodeAndChildren(child.id));
        }
    };

    nodesToCheck.forEach(node => addNodeAndChildren(node.id));

    return result;
});

// Toggle expand/collapse for a node
const toggleExpand = (nodeId: number) => {
    expandedNodes.value[nodeId] = !expandedNodes.value[nodeId];
};

// Update flattened nodes when hierarchy changes
watch(
    () => props.hierarchy,
    () => {
        if (processedHierarchy.value.length > 0) {
            flattenedNodes.value = flattenHierarchy(processedHierarchy.value);
            // Initialize all nodes as expanded
            flattenedNodes.value.forEach(node => {
                if (node.hasChildren) {
                    expandedNodes.value[node.id] = true;
                }
            });
        }
    },
    { immediate: true }
);

// Auto-switch to list view for very large datasets
watch(
    () => flattenedNodes.value.length,
    (count) => {
        if (count > 500) {
            useTreeView.value = false;
        }
    }
);

// Handle position click
const handlePositionClick = (positionId: number) => {
    emit("show-details", positionId);
};

// Handle width calculation from child nodes
const handleWidthCalculated = (width: number) => {
    // This allows the parent container to adjust if needed
};

// Expand/Collapse all functionality
const expandAll = () => {
    flattenedNodes.value.forEach(node => {
        if (node.hasChildren) {
            expandedNodes.value[node.id] = true;
        }
    });
};

const collapseAll = () => {
    flattenedNodes.value.forEach(node => {
        if (node.hasChildren) {
            expandedNodes.value[node.id] = false;
        }
    });
};

// Panning functionality
const startPan = (e: MouseEvent) => {
    if (!containerRef.value) return;
    isPanning.value = true;
    panStart.value = {
        x: e.clientX - panOffset.value.x,
        y: e.clientY - panOffset.value.y,
    };
};

const doPan = (e: MouseEvent) => {
    if (!isPanning.value) return;
    panOffset.value = {
        x: e.clientX - panStart.value.x,
        y: e.clientY - panStart.value.y,
    };
};

const endPan = () => {
    isPanning.value = false;
};

// Mount and cleanup listeners
onMounted(() => {
    if (!containerRef.value) return;
    containerRef.value.addEventListener("mousedown", startPan);
    window.addEventListener("mousemove", doPan);
    window.addEventListener("mouseup", endPan);
});

onUnmounted(() => {
    if (!containerRef.value) return;
    containerRef.value.removeEventListener("mousedown", startPan);
    window.removeEventListener("mousemove", doPan);
    window.removeEventListener("mouseup", endPan);
});

// Format position level
const getPositionLevelColor = (level: string | number) => {
    const levelNum = typeof level === 'string' ? parseInt(level) : level;
    if (levelNum <= 2) return "error";
    if (levelNum <= 4) return "success";
    if (levelNum <= 6) return "info";
    return "success";
};
</script>

<style scoped>
.organogram-container {
    min-height: 600px;
    max-height: 800px;
    overflow: auto;
    position: relative;
    background: linear-gradient(to bottom, #f5f5f5 0%, #ffffff 100%);
}

.pan-wrapper {
    width: 100%;
    min-height: 100%;
}

.organogram-content {
    min-width: 100%;
    min-height: 100%;
    padding: 32px;
    transition: transform 0.1s ease-out;
}

.hierarchy-tree {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
    min-height: 400px;
}

/* List view styles */
.border-b {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.position-title {
    cursor: pointer;
    transition: color 0.2s;
}

.position-title:hover {
    color: rgb(var(--v-theme-primary));
}

/* Expand / collapse animation */
.expand-enter-active,
.expand-leave-active {
    transition:
        max-height 260ms ease,
        opacity 220ms ease,
        transform 220ms ease;
}

.expand-enter-from,
.expand-leave-to {
    max-height: 0;
    opacity: 0;
    transform: translateY(-6px);
}

.expand-enter-to,
.expand-leave-from {
    max-height: 80px;
    /* enough for one list item */
    opacity: 1;
    transform: translateY(0);
}

:deep(.v-list-item:hover) {
    background-color: rgba(var(--v-theme-primary), 0.05);
}
</style>