<!-- src/components/organogram/PositionNode.vue -->

<template>
    <div class="position-node-wrapper" :style="{ width: nodeWidth + 'px' }">
        <!-- Current Position Card -->
        <div class="position-card-container" @mouseenter="isHovered = true" @mouseleave="isHovered = false">
            <v-card :class="[
                'position-card',
                { 'vacant-position': node.is_vacant },
                { 'supervisor-position': node.license_type === 'supervisor' },
                { 'card-hovered': isHovered }
            ]" elevation="3" @click="handleCardClick">
                <v-card-text class="pa-3">
                    <div class="d-flex align-center mb-2">
                        <v-avatar :color="node.is_vacant
                            ? 'grey-lighten-2'
                            : node.license_type === 'supervisor'
                                ? 'success'
                                : 'primary'
                            " size="40">
                            <v-icon v-if="node.is_vacant">$accountOff</v-icon>
                            <v-icon v-else color="white">
                                {{
                                    node.license_type === 'supervisor'
                                        ? '$accountStar'
                                        : '$account'
                                }}
                            </v-icon>
                        </v-avatar>
                        <div class="ml-2 flex-grow-1">
                            <div class="text-subtitle-2 font-weight-bold">
                                {{ node.name }}
                            </div>
                            <div class="text-caption text-grey">
                                <v-icon size="small" class="mr-1">$officeBuilding</v-icon>
                                {{
                                    node?.business_unit?.name
                                        ? node?.business_unit?.name
                                        : node?.code
                                            ? node?.code
                                            : 'N/A'
                                }}
                            </div>
                        </div>
                        <div v-if="hasChildren" class="d-flex flex-column gap-1 align-center">
                            <v-chip size="x-small" color="primary" variant="tonal">
                                {{ node.children.length }}
                            </v-chip>
                            <v-btn :icon="isExpanded ? '$chevronUp' : '$chevronDown'" size="x-small" variant="text"
                                color="primary" @click.stop="toggleExpand"></v-btn>
                        </div>
                    </div>
                    <v-divider class="my-2" />
                    <div v-if="node.current_holder" class="text-caption">
                        <div class="d-flex align-center mb-1">
                            <v-icon size="small" class="mr-1">$account</v-icon>
                            <strong>{{ node.current_holder.name }}</strong>
                        </div>
                        <div class="text-grey">
                            {{ node.current_holder.email }}
                        </div>
                    </div>
                    <div v-else class="text-caption text-error">
                        <div class="d-flex align-center mb-1">
                            <v-icon size="small" class="mr-1">$alertCircle</v-icon>
                            Position Vacant
                        </div>
                        <div class="text-grey">
                            <br />
                        </div>

                    </div>
                    <div class="mt-2 d-flex gap-1">
                        <v-chip v-if="node.level" size="x-small" :color="parseInt(node.level) <= 2
                            ? 'error'
                            : parseInt(node.level) <= 4
                                ? 'warning'
                                : 'info'
                            ">
                            Level {{ node.level }}
                        </v-chip>
                        <v-chip size="x-small" :color="node.is_vacant ? 'error' : 'success'" variant="outlined">
                            {{ node.is_vacant ? 'Vacant' : 'Filled' }}
                        </v-chip>
                    </div>
                </v-card-text>
            </v-card>
        </div>

        <!-- SVG Connectors and Children -->
        <div v-if="hasChildren && isExpanded" class="children-section">
            <!-- SVG Canvas for Connectors -->
            <svg class="connectors-svg" :width="nodeWidth" :height="connectorHeight + 20"
                :class="{ 'connectors-animated': isHovered }">
                <!-- Vertical line from parent to horizontal line -->
                <line :x1="nodeWidth / 2" :y1="0" :x2="nodeWidth / 2" :y2="verticalLineLength"
                    class="connector-line connector-vertical" :class="{ 'line-highlighted': isHovered }" />

                <!-- Horizontal line spanning children (with spacing from above) -->
                <line v-if="childPositions.length > 1" :x1="childPositions[0].x" :y1="verticalLineLength"
                    :x2="childPositions[childPositions.length - 1].x" :y2="verticalLineLength"
                    class="connector-line connector-horizontal" :class="{ 'line-highlighted': isHovered }" />

                <!-- Vertical lines from horizontal line to each child -->
                <line v-for="(pos, idx) in childPositions" :key="idx" :x1="pos.x" :y1="verticalLineLength" :x2="pos.x"
                    :y2="connectorHeight + 20" class="connector-line connector-vertical"
                    :class="{ 'line-highlighted': isHovered }" />

                <!-- Animated dots on lines (decorative) -->
                <circle v-if="isHovered" :cx="nodeWidth / 2" :cy="(verticalLineLength) / 2" r="4" class="connector-dot">
                    <animate attributeName="cy" :from="0" :to="verticalLineLength" dur="1s" repeatCount="indefinite" />
                </circle>
            </svg>

            <!-- Children container with calculated positions -->
            <div class="children-container" :style="{ width: nodeWidth + 'px' }">
                <div v-for="(child, index) in node.children" :key="child.id" class="child-wrapper" :style="{
                    position: 'absolute',
                    left: childPositions[index].x - childPositions[index].width / 2 + 'px',
                    top: 0
                }">
                    <PositionNode :node="child" :view-type="viewType" @show-details="$emit('show-details', $event)"
                        @width-calculated="(w) => updateChildWidth(index, w)" />
                </div>
            </div>
        </div>

        <!-- Collapsed indicator -->
        <div v-if="hasChildren && !isExpanded" class="collapsed-indicator">
            <v-chip size="small" color="primary" variant="outlined">
                {{ node.children.length }} position{{ node.children.length > 1 ? 's' : '' }} hidden
            </v-chip>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue';

const props = defineProps<{
    node: any;
    viewType: 'by_position' | 'by_business_unit';
}>();

const emit = defineEmits<{
    (e: 'show-details', positionId: number): void;
    (e: 'width-calculated', width: number): void;
}>();

// State
const isExpanded = ref(true);
const isHovered = ref(false);
const childWidths = ref<number[]>([]);
const cardWidth = 340;
const minGap = 60;
const verticalLineLength = 60; // Increased to add more spacing from parent
const connectorHeight = 60; // Increased to match the vertical line length

// Computed
const hasChildren = computed(() => props.node.children && props.node.children.length > 0);

const nodeWidth = computed(() => {
    if (!hasChildren.value || !isExpanded.value) {
        return cardWidth;
    }

    // Calculate total width needed for all children with gaps
    const totalChildrenWidth = childWidths.value.reduce((sum, w) => sum + w, 0);
    const totalGaps = (childWidths.value.length - 1) * minGap;
    const calculatedWidth = totalChildrenWidth + totalGaps;

    // Return at least the card width
    return Math.max(cardWidth, calculatedWidth);
});

const childPositions = computed(() => {
    if (!hasChildren.value || childWidths.value.length === 0) return [];

    const positions = [];
    let currentX = 0;

    // Calculate starting position (center the children)
    const totalWidth = nodeWidth.value;
    const childrenWidth = childWidths.value.reduce((sum, w) => sum + w, 0) +
        (childWidths.value.length - 1) * minGap;
    currentX = (totalWidth - childrenWidth) / 2;

    for (let i = 0; i < childWidths.value.length; i++) {
        const childWidth = childWidths.value[i];
        positions.push({
            x: currentX + childWidth / 2,
            width: childWidth
        });
        currentX += childWidth + minGap;
    }

    return positions;
});

// Methods
const toggleExpand = () => {
    isExpanded.value = !isExpanded.value;
};

const handleCardClick = () => {
    emit('show-details', props.node.id);
};

const updateChildWidth = (index: number, width: number) => {
    childWidths.value[index] = width;
};

const getPositionLevelColor = (level: string) => {
    const levelNum = parseInt(level);
    if (levelNum <= 2) return 'error';
    if (levelNum <= 4) return 'warning';
    if (levelNum <= 6) return 'info';
    return 'success';
};

// Initialize child widths
watch(() => props.node.children, (children) => {
    if (children) {
        childWidths.value = new Array(children.length).fill(cardWidth);
    }
}, { immediate: true });

// Emit width changes
watch(nodeWidth, (width) => {
    nextTick(() => {
        emit('width-calculated', width);
    });
}, { immediate: true });

onMounted(() => {
    if (hasChildren.value) {
        childWidths.value = new Array(props.node.children.length).fill(cardWidth);
    }
    nextTick(() => {
        emit('width-calculated', nodeWidth.value);
    });
});
</script>

<style scoped>
.position-node-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    transition: all 0.3s ease;
}

.position-card-container {
    position: relative;
    z-index: 10;
}

.position-card {
    width: 340px;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 2px solid transparent;
    background: white;
}

.position-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15) !important;
    border-color: rgb(var(--v-theme-primary));
}

.card-hovered {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.12) !important;
}

.vacant-position {
    background: rgba(var(--v-theme-surface));
    /* optional light background */
    border-left: 4px solid rgba(var(--v-theme-error));
    /* red/error */
}

.supervisor-position {
    border-left: 4px solid rgba(var(--v-theme-success));
    /* green/success */
}

/* SVG Connectors */
.children-section {
    position: relative;
    width: 100%;
    margin-bottom: 15px;
}

.connectors-svg {
    display: block;
    margin: 0 auto;
    overflow: visible;
    position: relative;
    z-index: 1;
}

.connector-line {
    margin-bottom: 15px !important;
    stroke: rgba(var(--v-theme-info));
    /* blue/info */
    stroke-width: 2;
    fill: none;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.line-highlighted {
    stroke: rgba(var(--v-theme-info));
    /* same info color */
    stroke-width: 3;
    filter: drop-shadow(0 0 4px rgba(var(--v-theme-info)));
    /* info glow */
}

.connector-dot {
    fill: rgba(var(--v-theme-info));
    /* info */
    filter: drop-shadow(0 0 4px rgba(var(--v-theme-info)));
}

.connectors-animated .connector-line {
    stroke-dasharray: 8, 4;
    animation: dash 20s linear infinite;
}

@keyframes dash {
    to {
        stroke-dashoffset: -1000;
    }
}

/* Children container */
.children-container {
    position: relative;
    min-height: 200px;
    margin-top: 0;
}

.child-wrapper {
    transition: all 0.3s ease;
}

/* Collapsed indicator */
.collapsed-indicator {
    margin-top: 16px;
    text-align: center;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Expand/Collapse animations */
.children-section {
    animation: expandDown 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    transform-origin: top center;
}

@keyframes expandDown {
    from {
        opacity: 0;
        transform: scaleY(0.8);
    }

    to {
        opacity: 1;
        transform: scaleY(1);
    }
}

/* Responsive adjustments */
@media (max-width: 1200px) {
    .position-card {
        width: 300px;
    }
}

@media (max-width: 768px) {
    .children-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
        position: relative;
    }

    .child-wrapper {
        position: relative !important;
        left: 0 !important;
        width: 100%;
    }

    .connectors-svg {
        display: none;
    }
}
</style>