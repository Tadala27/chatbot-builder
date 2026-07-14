
<template>
  <VCard class="validation-panel" variant="outlined" :class="panelClass">
    <!-- Header -->
    <div class="panel-header" @click="expanded = !expanded">
      <div class="d-flex align-center ga-2 flex-grow-1">
        <VIcon :icon="headerIcon" :color="headerColor" size="20" />
        <span class="text-subtitle-2">{{ headerText }}</span>
        <VChip v-if="errors.length" size="x-small" color="error" variant="flat" class="ml-1">
          {{ errors.length }}
        </VChip>
        <VChip v-if="warnings.length" size="x-small" color="warning" variant="flat" class="ml-1">
          {{ warnings.length }}
        </VChip>
        <VChip v-if="info.length" size="x-small" color="info" variant="flat" class="ml-1">
          {{ info.length }}
        </VChip>
      </div>
      <VBtn icon size="small" variant="text">
        <VIcon :icon="expanded ? '$chevronUp' : '$chevronDown'" size="18" />
      </VBtn>
    </div>

    <!-- Body -->
    <VExpandTransition>
      <div v-show="expanded && issues.length">
        <VDivider />

        <!-- Filter tabs -->
        <div class="d-flex ga-1 px-3 py-2 border-b">
          <VBtn
            v-for="tab in tabs"
            :key="tab.value"
            :color="activeTab === tab.value ? tab.color : 'default'"
            :variant="activeTab === tab.value ? 'tonal' : 'text'"
            size="x-small"
            @click="activeTab = tab.value"
          >
            {{ tab.label }}
            <VChip v-if="tab.count > 0" size="x-small" class="ml-1" variant="flat" :color="tab.color">
              {{ tab.count }}
            </VChip>
          </VBtn>
        </div>

        <!-- Issues list -->
        <div class="issues-list">
          <div
            v-for="(issue, idx) in filteredIssues"
            :key="`${issue.nodeId}-${issue.code}-${idx}`"
            class="issue-row"
            :class="`issue-row--${issue.severity}`"
            @click="onIssueClick(issue)"
          >
            <VIcon :icon="severityIcon(issue.severity)" :color="severityColor(issue.severity)" size="16" />
            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-center ga-2">
                <span class="text-body-2 font-weight-medium">{{ issue.message }}</span>
              </div>
              <div v-if="issue.nodeLabel || issue.path" class="text-caption text-medium-emphasis mt-1 d-flex ga-2">
                <span v-if="issue.nodeLabel">in "{{ issue.nodeLabel }}"</span>
                <span v-if="issue.path" class="path-chip">{{ issue.path }}</span>
              </div>
              <div v-if="issue.fixHint" class="text-caption text-medium-emphasis mt-1">
                💡 {{ issue.fixHint }}
              </div>
            </div>
            <VIcon v-if="issue.nodeId" icon="$arrowRight" size="14" class="text-medium-emphasis" />
          </div>
        </div>
      </div>
    </VExpandTransition>

    <!-- Empty state when no issues -->
    <VExpandTransition>
      <div v-show="expanded && !issues.length" class="pa-4 text-center">
        <VIcon icon="$checkCircle" size="36" color="success" class="mb-2" />
        <p class="text-body-2 mb-0">Flow is valid — ready to publish</p>
      </div>
    </VExpandTransition>
  </VCard>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useFlowValidator, type ValidationIssue, type IssueSeverity } from '@/composables/useFlowValidator'
import type { FlowNode } from '@/components/bots/types'

const props = defineProps<{
  nodes: FlowNode[]
  autoValidate?: boolean     // re-validate whenever nodes change (debounced)
}>()

const emit = defineEmits<{
  (e: 'validate-complete', result: { hasErrors: boolean; issues: ValidationIssue[] }): void
  (e: 'focus-node', nodeId: string, path?: string): void
}>()

const { issues, errors, warnings, info, hasErrors, validate } = useFlowValidator()

const expanded = ref(true)
const activeTab = ref<'all' | IssueSeverity>('all')

// Auto-validate on nodes change (debounced)
let debounceTimer: ReturnType<typeof setTimeout> | null = null
watch(
  () => props.nodes,
  () => {
    if (!props.autoValidate) return
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(runValidate, 400)
  },
  { deep: true, immediate: true }
)

function runValidate() {
  validate(props.nodes)
  emit('validate-complete', { hasErrors: hasErrors.value, issues: issues.value })
}

// Public method for parent to trigger manually
defineExpose({ validate: runValidate })

const tabs = computed(() => [
  { value: 'all' as const,     label: 'All',       color: 'default', count: issues.value.length  },
  { value: 'error' as const,   label: 'Errors',    color: 'error',   count: errors.value.length  },
  { value: 'warning' as const, label: 'Warnings',  color: 'warning', count: warnings.value.length },
  { value: 'info' as const,    label: 'Notices',   color: 'info',    count: info.value.length    },
])

const filteredIssues = computed(() => {
  if (activeTab.value === 'all') return issues.value
  return issues.value.filter(i => i.severity === activeTab.value)
})

const headerIcon = computed(() => {
  if (errors.value.length) return '$alertCircle'
  if (warnings.value.length) return '$alert'
  if (issues.value.length) return '$informationOutline'
  return '$checkCircle'
})

const headerColor = computed(() => {
  if (errors.value.length) return 'error'
  if (warnings.value.length) return 'warning'
  if (issues.value.length) return 'info'
  return 'success'
})

const headerText = computed(() => {
  if (!issues.value.length) return 'Flow is valid'
  const parts: string[] = []
  if (errors.value.length)   parts.push(`${errors.value.length} error${errors.value.length === 1 ? '' : 's'}`)
  if (warnings.value.length) parts.push(`${warnings.value.length} warning${warnings.value.length === 1 ? '' : 's'}`)
  if (info.value.length)     parts.push(`${info.value.length} notice${info.value.length === 1 ? '' : 's'}`)
  return parts.join(', ')
})

const panelClass = computed(() => {
  if (errors.value.length)   return 'panel--error'
  if (warnings.value.length) return 'panel--warning'
  if (issues.value.length)   return 'panel--info'
  return 'panel--success'
})

function severityIcon(s: IssueSeverity) {
  return s === 'error' ? '$alertCircle'
       : s === 'warning' ? '$alert'
       : '$informationOutline'
}

function severityColor(s: IssueSeverity) {
  return s === 'error' ? 'error'
       : s === 'warning' ? 'warning'
       : 'info'
}

function onIssueClick(issue: ValidationIssue) {
  if (issue.nodeId) emit('focus-node', issue.nodeId, issue.path)
}
</script>

<style scoped lang="scss">
.validation-panel {
  border-left: 3px solid transparent;
  transition: border-color 0.15s;

  &.panel--error   { border-left-color: rgb(var(--v-theme-error)); }
  &.panel--warning { border-left-color: rgb(var(--v-theme-warning)); }
  &.panel--info    { border-left-color: rgb(var(--v-theme-info)); }
  &.panel--success { border-left-color: rgb(var(--v-theme-success)); }
}

.panel-header {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  cursor: pointer;
  user-select: none;
  transition: background 0.15s;

  &:hover {
    background: rgba(var(--v-theme-on-surface), 0.04);
  }
}

.issues-list {
  max-height: 360px;
  overflow-y: auto;
}

.issue-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  cursor: pointer;
  transition: background 0.15s;

  &:hover {
    background: rgba(var(--v-theme-on-surface), 0.04);
  }

  &:last-child {
    border-bottom: none;
  }

  &--error {
    border-left: 2px solid rgb(var(--v-theme-error));
  }
  &--warning {
    border-left: 2px solid rgb(var(--v-theme-warning));
  }
  &--info {
    border-left: 2px solid rgb(var(--v-theme-info));
  }
}

.path-chip {
  font-family: ui-monospace, 'SF Mono', Menlo, monospace;
  font-size: 0.72rem;
  padding: 1px 6px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 4px;
}

.border-b {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
