<template>
    <!-- Inside <template> -->
    <div v-if="canManageSettings" class="workflow-configuration">
        <v-card elevation="0" rounded="0">
            <v-card-title class="text-h5">
                Recruitment Workflow Configuration
            </v-card-title>
            <v-card-subtitle>
                Select and customize the stages for your recruitment process. You can enable/disable stages and
                configure
                their features.
            </v-card-subtitle>

            <v-card-text>
                <div v-if="isLoading" class="text-center py-8">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <div class="mt-2">Loading...</div>
                </div>

                <div v-else class="workflow-configuration">

                    <!-- Selected Stages Accordion -->
                    <div v-if="form.selectedStages.length === 0" class="empty-stages text-center py-8">
                        <v-icon size="64" color="grey-lighten-2" class="mb-4">$plusCircleOutline</v-icon>
                        <h4 class="text-h6 text-medium-emphasis mb-2">No stages selected yet</h4>
                        <p class="text-body-2 text-medium-emphasis">
                            Add stages from the available stages below to build your recruitment workflow.
                        </p>
                    </div>

                    <v-expansion-panels v-else multiple elevation="0" rounded="0">
                        <v-expansion-panel v-for="(stage, index) in form.selectedStages" :key="stage.id">
                            <v-expansion-panel-title>
                                <div class="d-flex align-center justify-space-between w-100">
                                    <div>
                                        <span class="text-h6 text-error">{{ stage.name }}</span>
                                        <span class="text-caption text-medium-emphasis ml-2">{{ stage.description
                                        }}</span>
                                    </div>
                                    <div class="d-flex align-center">
                                        <v-chip size="small" color="primary" class="mr-2">Order: {{ index + 1
                                        }}</v-chip>
                                        <v-switch v-model="stage.is_enabled" @change="updateStageEnabled(stage)"
                                            hide-details density="compact" class="mr-2 " color="error" />
                                        <v-btn icon="$trashCanOutline" size="small" variant="text" color="error"
                                            @click="removeStage(index)" />
                                    </div>
                                </div>
                            </v-expansion-panel-title>

                            <v-expansion-panel-text>
                                <div v-if="stage.is_enabled && stage.features && stage.features.length">
                                    <v-divider class="mb-4"></v-divider>
                                    <h4 class="text-h6 mb-3">Stage Features</h4>

                                    <v-row>
                                        <VCol v-for="feature in stage.features" :key="feature.id || feature.feature_key"
                                            cols="12" md="6">
                                            <v-card variant="outlined" class="h-100">
                                                <v-card-title class="d-flex align-start justify-space-between pb-2">
                                                    <div>
                                                        <div class="text-subtitle-1">{{
                                                            formatFeatureName(feature.feature_key) }}</div>
                                                        <div class="text-caption text-medium-emphasis">{{
                                                            feature.description }}</div>
                                                    </div>
                                                    <v-switch v-model="feature.is_enabled" color="primary"
                                                        @change="updateFeatureEnabled(stage, feature)" hide-details
                                                        density="compact" />
                                                </v-card-title>

                                                <v-card-text v-if="feature.is_enabled">
                                                    <!-- Boolean feature -->
                                                    <v-checkbox color="error"
                                                        v-if="feature.feature_type === 'boolean' && feature.feature_key !== 'main_toggle'"
                                                        v-model="feature.value" :label="feature.feature_name"
                                                        hide-details />

                                                    <!-- Number feature -->
                                                    <VTextField v-else-if="feature.feature_type === 'number'"
                                                        v-model.number="feature.value" type="number"
                                                        :label="feature.feature_name"
                                                        :placeholder="`Default: ${feature.default_value || 'Not set'}`"
                                                        variant="outlined" density="compact"
                                                        :hint="feature.validation_rules ? `Constraints: ${feature.validation_rules}` : undefined"
                                                        persistent-hint />

                                                    <!-- Text feature -->
                                                    <VTextField v-else-if="feature.feature_type === 'text'"
                                                        v-model="feature.value" :label="feature.feature_name"
                                                        :placeholder="`Default: ${feature.default_value || 'Not set'}`"
                                                        variant="outlined" density="compact" />

                                                    <!-- Select feature -->
                                                    <VAutocomplete v-else-if="feature.feature_type === 'select'"
                                                        v-model="feature.value" :items="getSelectOptions(feature)"
                                                        :label="feature.feature_name" variant="outlined"
                                                        density="compact" />

                                                    <!-- Multiselect feature -->
                                                    <VAutocomplete v-else-if="feature.feature_type === 'multiselect'"
                                                        v-model="feature.value" :items="getMultiselectOptions(feature)"
                                                        :label="feature.feature_name" multiple chips variant="outlined"
                                                        density="compact" />

                                                    <!-- JSON / Array feature -->
                                                    <v-textarea
                                                        v-else-if="feature.feature_type === 'json' || feature.feature_type === 'array'"
                                                        v-model="feature.jsonValue" @blur="updateJsonFeature(feature)"
                                                        :label="feature.feature_name" rows="3"
                                                        :placeholder="feature.feature_type === 'json' ? 'Enter JSON configuration...' : 'Enter array configuration (JSON format)...'"
                                                        variant="outlined" persistent-hint />

                                                    <!-- Approval Roles -->
                                                    <div v-show="shouldShowFeature(stage, 'approval_roles')"
                                                        v-else-if="feature.feature_key === 'approval_roles' && stage.slug === 'requisition'">
                                                        <div v-if="loadingRoles" class="text-center py-4">
                                                            <v-progress-circular indeterminate size="24"
                                                                class="mr-2"></v-progress-circular>
                                                            <span class="text-medium-emphasis">Loading tenant
                                                                roles...</span>
                                                        </div>

                                                        <div v-else class="approval-roles-config">
                                                            <v-card v-for="(approvalLevel, levelIndex) in feature.value"
                                                                :key="levelIndex" variant="outlined" class="mb-3 pa-3">
                                                                <div
                                                                    class="d-flex align-center justify-space-between mb-3">
                                                                    <v-chip color="primary" size="small">Level {{
                                                                        approvalLevel.level }}</v-chip>
                                                                    <v-btn v-if="feature.value.length > 1"
                                                                        icon="$trashCanOutline" size="small"
                                                                        variant="text" color="error"
                                                                        @click="removeApprovalLevel(feature, levelIndex)"></v-btn>
                                                                </div>
                                                                <VAutocomplete v-model="approvalLevel.role_id"
                                                                    @update:model-value="updateApprovalRole(feature, levelIndex, $event)"
                                                                    :items="rolesStore.roles" item-title="name"
                                                                    item-value="id"
                                                                    :label="`Approval Role - Level ${approvalLevel.level}`"
                                                                    placeholder="Select a role..." variant="outlined"
                                                                    density="compact" hide-details />
                                                                <div v-if="approvalLevel.role"
                                                                    class="text-caption text-medium-emphasis mt-1">
                                                                    Selected: {{ approvalLevel.role }}
                                                                </div>
                                                            </v-card>

                                                            <v-btn variant="outlined" color="primary" size="small"
                                                                prepend-icon="$plusCircleOutline"
                                                                @click="addApprovalLevel(feature)" class="mt-2">
                                                                Add Approval Level
                                                            </v-btn>
                                                        </div>
                                                    </div>
                                                    <div v-show="shouldShowFeature(stage, 'offer_approval_roles')"
                                                        v-else-if="feature.feature_key === 'offer_approval_roles' && stage.slug === 'offer-management'">
                                                        <div v-if="loadingRoles" class="text-center py-4">
                                                            <v-progress-circular indeterminate size="24"
                                                                class="mr-2"></v-progress-circular>
                                                            <span class="text-medium-emphasis">Loading tenant
                                                                roles...</span>
                                                        </div>

                                                        <VAutocomplete v-else multiple v-model="feature.value_ids"
                                                            :model-value="feature.value.map(r => r.role_id)"
                                                            @update:model-value="updateApprovalRoles(feature, $event)"
                                                            label="Select approval roles" variant="outlined"
                                                            density="compact" :items="rolesStore.roles"
                                                            item-title="name" item-value="id" />

                                                        <small class="text-caption text-medium-emphasis mt-1">
                                                            Users with any of these roles can sign off on offers.
                                                        </small>
                                                    </div>
                                                    <div v-show="shouldShowFeature(stage, 'unplanned_requisition_approval')"
                                                        v-else-if="feature.feature_key === 'unplanned_requisition_approval'">
                                                        <div v-if="loadingRoles" class="text-center py-4">
                                                            <v-progress-circular indeterminate size="24"
                                                                class="mr-2"></v-progress-circular>
                                                            <span class="text-medium-emphasis">Loading tenant
                                                                roles...</span>
                                                        </div>
                                                        <VAutocomplete v-else v-model="feature.value.role_id"
                                                            @update:model-value="updateUnplannedApprovalRole(feature, $event)"
                                                            label="Select a role for unbudgeted positions"
                                                            variant="outlined" density="compact"
                                                            :items="rolesStore.roles" item-title="name"
                                                            item-value="id" />

                                                        <small class="text-caption text-medium-emphasis mt-1">
                                                            This role will handle approvals for unbudgeted/unplanned
                                                            positions after standard approval workflow.
                                                        </small>
                                                    </div>

                                                </v-card-text>
                                            </v-card>
                                        </VCol>
                                    </v-row>
                                </div>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>

                    <div class="add-stages-section mt-6">
                        <v-divider class="mb-4"></v-divider>
                        <h4 class="text-h6 mb-4">Available Stages</h4>

                        <v-expansion-panels multiple>
                            <v-expansion-panel v-for="stage in filteredAvailableStages" :key="stage.id">
                                <v-expansion-panel-title :disabled="isStageSelected(stage)">
                                    <div class="d-flex justify-space-between w-100 align-center">
                                        <span>{{ stage.name || 'Unnamed Stage' }}</span>
                                        <v-btn color="primary" variant="outlined" size="small"
                                            :disabled="isStageSelected(stage)" prepend-icon="$plusCircleOutline"
                                            @click.stop="addStage(stage)">
                                            Add
                                        </v-btn>
                                    </div>
                                </v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <p class="text-body-2 text-medium-emphasis">{{ stage.description ||
                                        'No description available' }}</p>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>
                    </div>

                    <!-- Workflow Preview -->
                    <div class="review-section mt-8">
                        <v-divider class="mb-4"></v-divider>
                        <h4 class="text-h6 mb-4">Workflow Preview</h4>
                        <v-card variant="outlined">
                            <v-card-text>
                                <div class="d-flex align-center mb-3">
                                    <v-icon color="success" class="mr-2">$checkCircleOutline</v-icon>
                                    <span class="text-h6">Recruitment Workflow ({{ enabledStages.length }}
                                        stages)</span>
                                </div>
                                <div class="workflow-preview">
                                    <div class="d-flex flex-wrap align-center">
                                        <template v-for="(stage, index) in enabledStages" :key="stage.id">
                                            <v-chip color="primary" variant="elevated" class="ma-1">
                                                {{ stage.customStageName || stage.name }}
                                                <v-tooltip activator="parent" location="top">
                                                    {{ getEnabledFeaturesCount(stage) }} features enabled
                                                </v-tooltip>
                                            </v-chip>
                                            <v-icon v-if="index < enabledStages.length - 1" color="medium-emphasis"
                                                class="mx-2">
                                                $arrowRight
                                            </v-icon>
                                        </template>
                                    </div>
                                </div>
                            </v-card-text>
                        </v-card>
                    </div>

                </div>
            </v-card-text>

            <v-card-actions class="justify-center pa-4">
                <v-btn color="primary" size="large" :loading="saving" @click="saveConfiguration"
                    prepend-icon="$contentSave">
                    {{ saving ? 'Saving...' : 'Save Configuration' }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </div>

    <div v-else class="text-center py-12">
        <v-icon size="80" color="warning" class="mb-4">$lockOutline</v-icon>
        <h3 class="text-h5 mb-2">Access Restricted</h3>
        <p class="text-body-1 text-medium-emphasis">
            You need the <strong>manage settings</strong> permission to edit the workflow.
        </p>
        <v-btn color="primary" @click="router.push({ name: 'dashboard' })">
            Back to Dashboard
        </v-btn>
    </div>

</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useTenantConfigStore } from '@/stores/tenantConfig'
import { useTenantRolesStore } from '@/stores/tenantRoles'
import { useUserStore } from '@/stores/user'
import { useAbility } from '@casl/vue'
import Swal from 'sweetalert2'

// ────────────────────────────────────────
// Stores & Router
// ────────────────────────────────────────
const tenantStore = useTenantConfigStore()
const rolesStore = useTenantRolesStore()
const userStore = useUserStore()
const ability = useAbility()
const router = useRouter()

// ────────────────────────────────────────
// Reactive State
// ────────────────────────────────────────
const saving = ref(false)
const isLoading = ref(true)
const form = ref({
    selectedStages: [] as any[]
})

// ────────────────────────────────────────
// Permission Check
// ────────────────────────────────────────
const canManageSettings = computed(() => {
    const perms = userStore.user?.permissions || []
    return perms.includes('manage settings')
})

const isReadOnly = computed(() => !canManageSettings.value)

// ────────────────────────────────────────
// Computed: Stages & Features
// ────────────────────────────────────────
const availableStages = computed(() => tenantStore.config?.availableStages || [])
const filteredAvailableStages = computed(() => {
    return availableStages.value.filter(
        stage => !form.value.selectedStages.some(s => s.recruitment_stage_id === stage.id)
    )
})
const enabledStages = computed(() => form.value.selectedStages.filter(s => s.is_enabled))
const loadingRoles = computed(() => rolesStore.isLoading)

// ────────────────────────────────────────
// Helpers
// ────────────────────────────────────────
const formatFeatureName = (key: string) =>
    key.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')

const getEnabledFeaturesCount = (stage: any) =>
    stage.features?.filter((f: any) => f.is_enabled).length || 0

const getSelectOptions = (feature: any) =>
    feature.options?.map((opt: string) => ({ title: formatFeatureName(opt), value: opt })) || []

const getMultiselectOptions = (feature: any) => {
    let opts = feature.options
    if (typeof opts === 'string') {
        try { opts = JSON.parse(opts) } catch { opts = {} }
    }
    if (!opts || typeof opts !== 'object') return []
    return Object.keys(opts).map(key => ({ title: formatFeatureName(key), value: key }))
}

// ────────────────────────────────────────
// Feature Value Parsing
// ────────────────────────────────────────
const getFeatureValue = (feature: any) => {
    let val = feature.value !== undefined ? feature.value : feature.default_value
    if (typeof val === 'string' && ['json', 'multiselect'].includes(feature.feature_type)) {
        try { val = JSON.parse(val) } catch { }
    }
    switch (feature.feature_type) {
        case 'multiselect':
            if (val && !Array.isArray(val)) return Object.keys(val).filter(k => val[k])
            return Array.isArray(val) ? val : []
        case 'boolean': return Boolean(val)
        case 'number': return Number(val) || 0
        case 'json': return val && typeof val === 'object' ? val : {}
        default: return val || ''
    }
}

// ────────────────────────────────────────
// Initialize Configuration
// ────────────────────────────────────────
const initializeConfiguration = async () => {
    const existingWorkflow = tenantStore.config?.workflow || []
    try {
        await rolesStore.ensureRolesLoaded()
    } catch (err) {
        console.warn('Failed to load roles:', err)
    }

    form.value.selectedStages = existingWorkflow.map(stage => {
        const s = { ...stage }
        s.is_enabled = stage.is_enabled
        s.customStageName = stage.custom_stage_name
        s.stageOrder = stage.stage_order

        if (s.features) {
            s.features = s.features.map((f: any) => {
                let config = {
                    ...f,
                    is_enabled: f.is_enabled || false,
                    value: getFeatureValue(f),
                    jsonValue: f.feature_type === 'json' ? JSON.stringify(f.value || f.default_value || {}, null, 2) : ''
                }
                config = initializeFeatureWithTenantRoles(config)
                return config
            })
        }
        return s
    })
}
// ────────────────────────────────────────
// Conditional Feature Visibility
// ────────────────────────────────────────
const shouldShowFeature = (stage: any, featureKey: string) => {
    if (!stage.features) return true

    // Requisition stage dependencies
    if (stage.slug === 'requisition') {
        if (featureKey === 'approval_roles' || featureKey === 'unplanned_requisition_approval') {
            const requireApprovalFeature = stage.features.find((f: any) => f.feature_key === 'require_approval')
            return requireApprovalFeature?.is_enabled !== false // show only if enabled or not set
        }
    }

    // Offer Management stage dependencies
    if (stage.slug === 'offer-management') {
        if (featureKey === 'offer_approval_roles') {
            const offerApprovalFeature = stage.features.find((f: any) => f.feature_key === 'offer_approval')
            return offerApprovalFeature?.is_enabled === true
        }
    }

    // Default: always show
    return true
}
// ────────────────────────────────────────
// Feature Initialization with Roles
// ────────────────────────────────────────
const initializeFeatureWithTenantRoles = (feature: any) => {
    if (feature.feature_key === 'approval_roles') {
        if (!feature.value || !Array.isArray(feature.value)) {
            feature.value = [
                { role: '', role_id: null, level: 1 },
                { role: '', role_id: null, level: 2 }
            ]
        } else {
            feature.value = feature.value.map((item: any) => ({ ...item, role_id: item.role_id || null }))
        }
    } else if (feature.feature_key === 'unplanned_requisition_approval') {
        if (!feature.value || typeof feature.value !== 'object') {
            feature.value = { role: '', role_id: null }
        }
    }
    return feature
}

// ────────────────────────────────────────
// Stage Management
// ────────────────────────────────────────
const enforceStageOrder = () => {
    const orderMap = availableStages.value.reduce((acc: any, stage: any, i: number) => {
        acc[stage.slug] = i
        return acc
    }, {})
    form.value.selectedStages.sort((a, b) => orderMap[a.slug] - orderMap[b.slug])
    form.value.selectedStages.forEach((s, i) => s.stageOrder = i + 1)
}

const addStage = (stage: any) => {
    if (!stage?.id) return
    const newStage = { ...stage }
    newStage.recruitment_stage_id = stage.id
    newStage.is_enabled = true
    newStage.customStageName = ''
    newStage.stageOrder = form.value.selectedStages.length + 1
    newStage.features = (stage.features || []).map((f: any) => {
        const config = {
            ...f,
            is_enabled: false,
            id: f.id || f.feature_key || Math.random().toString(36).substr(2, 9),
            value: getFeatureValue(f),
            jsonValue: f.feature_type === 'json' ? JSON.stringify(f.default_value || {}, null, 2) : ''
        }
        return initializeFeatureWithTenantRoles(config)
    })
    form.value.selectedStages.push(newStage)
    enforceStageOrder()
}

const removeStage = (index: number) => {
    form.value.selectedStages.splice(index, 1)
}

const isStageSelected = (stage: any) =>
    form.value.selectedStages.some(s => s.recruitment_stage_id === stage.id)

// ────────────────────────────────────────
// Feature Updates
// ────────────────────────────────────────
const updateStageEnabled = (stage: any) => {
    console.log('Stage enabled:', stage.name, stage.is_enabled)
}

const updateFeatureEnabled = (stage: any, feature: any) => {
    console.log('Feature enabled:', feature.feature_key, feature.is_enabled)
}

const updateJsonFeature = (feature: any) => {
    try {
        feature.value = JSON.parse(feature.jsonValue)
    } catch {
        console.warn('Invalid JSON for', feature.feature_key)
    }
}

// ────────────────────────────────────────
// Approval Roles
// ────────────────────────────────────────
const addApprovalLevel = (feature: any) => {
    if (!feature.value) feature.value = []
    const next = Math.max(...feature.value.map((l: any) => l.level || 0), 0) + 1
    feature.value.push({ role: '', role_id: null, level: next })
}

const removeApprovalLevel = (feature: any, index: number) => {
    if (feature.value?.length > 1) {
        feature.value.splice(index, 1)
        feature.value.forEach((item: any, i: number) => item.level = i + 1)
    }
}

const updateApprovalRole = (feature: any, levelIndex: number, roleId: number) => {
    const role = rolesStore.findRoleById(roleId)
    if (!feature.value?.[levelIndex]) return
    feature.value[levelIndex] = {
        ...feature.value[levelIndex],
        role: role?.name || '',
        role_id: roleId,
        display_name: role?.display_name || role?.name || ''
    }
}

const updateApprovalRoles = (feature: any, roleIds: number[]) => {
    feature.value = roleIds
        .map(id => rolesStore.findRoleById(id))
        .filter(Boolean)
        .map(role => ({
            role: role.name,
            role_id: role.id,
            display_name: role.display_name || role.name
        }))
}

const updateUnplannedApprovalRole = (feature: any, roleId: number) => {
    const role = rolesStore.findRoleById(roleId)
    feature.value = role
        ? { role: role.name, role_id: roleId, display_name: role.display_name || role.name }
        : { role: '', role_id: null }
}

// ────────────────────────────────────────
// Save Preparation
// ────────────────────────────────────────
const prepareFeatureValueForSave = (feature: any): any => {
    if (feature.feature_key === 'approval_roles') {
        if (!feature.value || !Array.isArray(feature.value)) return []
        return feature.value
            .filter((item: any) => item.role_id && item.role)
            .map((item: any) => ({
                role: item.role,
                role_id: parseInt(item.role_id),
                level: item.level,
                display_name: item.display_name || item.role
            }))
            .sort((a: any, b: any) => a.level - b.level)
    }

    if (feature.feature_key === 'unplanned_requisition_approval') {
        if (!feature.value?.role_id) return null
        return {
            role: feature.value.role,
            role_id: parseInt(feature.value.role_id),
            display_name: feature.value.display_name || feature.value.role
        }
    }

    if (feature.feature_type === 'multiselect') {
        if (feature.options && typeof feature.options === 'object' && !Array.isArray(feature.options)) {
            const obj: any = { ...feature.options }
            Object.keys(obj).forEach(k => obj[k] = false)
            if (Array.isArray(feature.value)) {
                feature.value.forEach((k: string) => obj[k] = true)
            }
            return obj
        }
        return feature.value || []
    }

    if (feature.feature_type === 'json') {
        try {
            return typeof feature.value === 'string' ? JSON.parse(feature.value) : feature.value
        } catch {
            return feature.default_value || {}
        }
    }

    if (feature.feature_type === 'boolean') return Boolean(feature.value)
    if (feature.feature_type === 'number') return Number(feature.value) || 0
    return feature.value || feature.default_value || ''
}

const prepareFeatureSettings = (feature: any) => {
    const settings: any = { value: feature.value, type: feature.feature_type }
    if (feature.validation_rules) settings.validation_rules = feature.validation_rules
    if (feature.options) settings.options = feature.options
    return settings
}

// ────────────────────────────────────────
// Save Configuration
// ────────────────────────────────────────
const saveConfiguration = async () => {
    if (isReadOnly.value) {
        await Swal.fire({
            title: 'Unauthorized',
            text: 'You cannot save changes without proper permissions.',
            icon: 'error',
            confirmButtonText: 'OK',
            confirmButtonColor: 'rgba(var(--v-theme-primary))',
            customClass: {
                confirmButton: 'text-white',
            }
        })
        return
    }

    saving.value = true
    try {
        const configData = {
            workflow_stages: form.value.selectedStages.map((stage, i) => ({
                recruitment_stage_id: stage.recruitment_stage_id,
                is_enabled: stage.is_enabled,
                stage_order: i + 1,
                custom_stage_name: stage.customStageName || null,
                stage_features: stage.features
                    ? stage.features
                        .filter((f: any) => f.is_enabled)
                        .map((f: any) => ({
                            feature_key: f.feature_key,
                            is_enabled: f.is_enabled,
                            feature_value: prepareFeatureValueForSave(f),
                            feature_settings: prepareFeatureSettings(f)
                        }))
                    : []
            }))
        }

        await tenantStore.saveConfiguration(configData)
        await Swal.fire({
            title: 'Success!',
            text: 'Recruitment workflow saved.',
            icon: 'success',
            confirmButtonText: 'OK',
            confirmButtonColor: 'rgba(var(--v-theme-primary))',
            customClass: {
                confirmButton: 'text-white',
            }
        }).then(() => {

            window.location.reload();
        })
    } catch (err: any) {
        await Swal.fire({
            title: 'Error',
            text: err.response?.data?.message || 'Failed to save configuration.',
            icon: 'error',
            confirmButtonText: 'OK',
            confirmButtonColor: 'rgba(var(--v-theme-primary))',
            customClass: {
                confirmButton: 'text-white',
            }
        })
    } finally {
        saving.value = false
    }
}
// Auto-disable child features when parent is disabled
watch(
    () => form.value.selectedStages,
    (stages) => {
        stages.forEach(stage => {
            if (stage.slug === 'requisition') {
                const requireApproval = stage.features?.find((f: any) => f.feature_key === 'require_approval')
                const approvalRoles = stage.features?.find((f: any) => f.feature_key === 'approval_roles')
                const unplanned = stage.features?.find((f: any) => f.feature_key === 'unplanned_requisition_approval')

                if (requireApproval && !requireApproval.is_enabled) {
                    if (approvalRoles) approvalRoles.is_enabled = false
                    if (unplanned) unplanned.is_enabled = false
                }
            }

            if (stage.slug === 'offer-management') {
                const offerApproval = stage.features?.find((f: any) => f.feature_key === 'offer_approval')
                const offerRoles = stage.features?.find((f: any) => f.feature_key === 'offer_approval_roles')

                if (offerApproval && !offerApproval.is_enabled && offerRoles) {
                    offerRoles.is_enabled = false
                }
            }
        })
    },
    { deep: true }
)
// ────────────────────────────────────────
// Lifecycle – Check Permission on Mount
// ────────────────────────────────────────
onMounted(async () => {
    // Best: use preloaded data
    tenantStore.initializeFromWindow()
    rolesStore.initializeFromWindow()

    // Fallback only if not loaded
    if (!tenantStore.isLoaded) {
        await tenantStore.fetchConfig()
    }
    if (!rolesStore.isLoaded) {
        await rolesStore.ensureRolesLoaded()
    }

    await initializeConfiguration()


    if (!canManageSettings.value) {
        await Swal.fire({
            title: 'Access Denied',
            text: 'You do not have permission to manage recruitment workflow settings.',
            icon: 'warning',
            confirmButtonText: 'OK',
            allowOutsideClick: false,
            allowEscapeKey: false,
            confirmButtonColor: 'rgba(var(--v-theme-primary))',
            customClass: {
                confirmButton: 'text-white',
            }
        })

        // Go back to previous page
        window.history.back()
    }

    isLoading.value = false
})
</script>