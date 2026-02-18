<script setup lang="ts">
import { computed, watch } from "vue";

const props = defineProps<{
    modelValue: boolean;
    positionId: number | null;
    details: any;
}>();

const emit = defineEmits<{
    (e: "update:modelValue", value: boolean): void;
    (e: "refresh"): void;
}>();

const dialog = computed({
    get: () => props.modelValue,
    set: (value) => emit("update:modelValue", value),
});

const position = computed(() => props.details?.position || {});
const tenant = computed(() => props.details?.tenant || {});
const businessUnit = computed(() => props.details?.business_unit || null);
const currentHolder = computed(() => props.details?.current_holder || null);
const manager = computed(() => props.details?.manager || null);
const directReports = computed(() => props.details?.direct_reports || []);
const stats = computed(() => props.details?.stats || {});

const close = () => {
    dialog.value = false;
};
</script>

<template>
    <v-dialog v-model="dialog" max-width="800" scrollable>
        <v-card v-if="details">
            <v-card-title class="bg-primary text-white d-flex align-center">
                <v-icon start>$briefcase</v-icon>
                Position Details
                <v-spacer />
                <v-btn icon variant="text" @click="close">
                    <v-icon>$close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pa-6">
                <!-- Position Info -->
                <div class="mb-6">
                    <h6 class="text-h6 mb-3">{{ position.name }}</h6>
                    <v-chip size="small" color="primary" class="mr-2">
                        {{ position.code }}
                    </v-chip>
                    <v-chip v-if="position.level" size="small" color="info" class="mr-2">
                        Level {{ position.level }}
                    </v-chip>
                    <v-chip size="small" :color="position.license_type === 'supervisor' ? 'warning' : 'primary'">
                        {{ position.license_type === "supervisor" ? "Supervisor" : "Subordinate" }}
                    </v-chip>
                    <v-chip size="small" :color="position.is_active ? 'success' : 'error'" class="ml-2">
                        {{ position.is_active ? "Active" : "Inactive" }}
                    </v-chip>
                </div>

                <v-divider class="my-4" />

                <!-- Organization Context -->
                <v-row class="mb-4">
                    <v-col cols="12" md="6">
                        <div class="d-flex align-center mb-2">
                            <v-icon color="primary" class="mr-2">$domain</v-icon>
                            <div>
                                <div class="text-caption text-grey">Tenant</div>
                                <div class="text-body-2 font-weight-bold">
                                    {{ tenant.name }}
                                </div>
                            </div>
                        </div>
                    </v-col>
                    <v-col cols="12" md="6" v-if="businessUnit">
                        <div class="d-flex align-center mb-2">
                            <v-icon color="info" class="mr-2">$officeBuilding</v-icon>
                            <div>
                                <div class="text-caption text-grey">Business Unit</div>
                                <div class="text-body-2 font-weight-bold">
                                    {{ businessUnit.name }}
                                </div>
                            </div>
                        </div>
                    </v-col>
                </v-row>

                <v-divider class="my-4" />

                <!-- Current Holder -->
                <div class="mb-4">
                    <h6 class="text-subtitle-1 mb-3">
                        <v-icon size="small" class="mr-1">$account</v-icon>
                        Current Holder
                    </h6>
                    <v-card v-if="currentHolder" variant="tonal" color="success">
                        <v-card-text>
                            <div class="d-flex align-center">
                                <v-avatar color="success" size="48" class="mr-3">
                                    <v-icon color="white">$account</v-icon>
                                </v-avatar>
                                <div class="flex-grow-1">
                                    <div class="text-body-1 font-weight-bold">
                                        {{ currentHolder.name }}
                                    </div>
                                    <div class="text-caption text-grey">
                                        {{ currentHolder.email }}
                                    </div>
                                    <div class="text-caption text-grey">
                                        Employee #: {{ currentHolder.employee_number }}
                                    </div>
                                    <v-chip size="x-small" color="success" class="mt-1">
                                        {{ currentHolder.user_role }}
                                    </v-chip>
                                </div>
                            </div>
                        </v-card-text>
                    </v-card>
                    <v-alert v-else type="warning" variant="tonal">

                        Position is currently vacant
                    </v-alert>
                </div>

                <!-- Manager -->
                <div class="mb-4" v-if="manager">
                    <h6 class="text-subtitle-1 mb-3">
                        <v-icon size="small" class="mr-1">$accountStar</v-icon>
                        Reports To
                    </h6>
                    <v-card variant="outlined">
                        <v-card-text>
                            <div class="d-flex align-center">
                                <v-avatar color="warning" variant="tonal" size="40" class="mr-3">
                                    <v-icon>$accountStar</v-icon>
                                </v-avatar>
                                <div>
                                    <div class="text-body-2 font-weight-bold">
                                        {{ manager.name }}
                                    </div>
                                    <div v-if="manager.current_holder" class="text-caption text-grey">
                                        {{ manager.current_holder.name }}
                                    </div>
                                    <div v-else class="text-caption text-error">Vacant</div>
                                </div>
                            </div>
                        </v-card-text>
                    </v-card>
                </div>

                <!-- Direct Reports -->
                <div class="mb-4" v-if="directReports.length > 0">
                    <h6 class="text-subtitle-1 mb-3">
                        <v-icon size="small" class="mr-1">$accountGroup</v-icon>
                        Direct Reports ({{ directReports.length }})
                    </h6>
                    <v-list density="compact">
                        <v-list-item v-for="report in directReports" :key="report.id" class="border rounded mb-1">
                            <template #prepend>
                                <v-avatar color="primary" variant="tonal" size="32">
                                    <v-icon size="small">$account</v-icon>
                                </v-avatar>
                            </template>

                            <v-list-item-title>{{ report.name }}</v-list-item-title>
                            <v-list-item-subtitle>
                                <span v-if="report.current_holder">
                                    {{ report.current_holder.name }}
                                </span>
                                <span v-else class="text-error">Vacant</span>
                            </v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </div>

                <v-divider class="my-4" />

                <!-- Statistics -->
                <div>
                    <h6 class="text-subtitle-1 mb-3">
                        <v-icon size="small" class="mr-1">$chartBox</v-icon>
                        Position Statistics
                    </h6>
                    <v-row dense>
                        <v-col cols="4">
                            <v-card variant="tonal" color="primary">
                                <v-card-text class="text-center pa-2">
                                    <div class="text-h6 font-weight-bold">
                                        {{ stats.direct_reports_count || 0 }}
                                    </div>
                                    <div class="text-caption">Direct Reports</div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="4">
                            <v-card variant="tonal" color="info">
                                <v-card-text class="text-center pa-2">
                                    <div class="text-h6 font-weight-bold">
                                        {{ stats.scorecards_count || 0 }}
                                    </div>
                                    <div class="text-caption">Total Scorecards</div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                        <v-col cols="4">
                            <v-card variant="tonal" color="success">
                                <v-card-text class="text-center pa-2">
                                    <div class="text-h6 font-weight-bold">
                                        {{ stats.active_scorecards || 0 }}
                                    </div>
                                    <div class="text-caption">Active Scorecards</div>
                                </v-card-text>
                            </v-card>
                        </v-col>
                    </v-row>
                </div>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="close">Close</v-btn>
            </v-card-actions>
        </v-card>

        <v-card v-else>
            <v-card-text class="text-center py-8">
                <v-progress-circular indeterminate color="primary" size="48" />
                <p class="mt-4 text-grey">Loading position details...</p>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.border {
    border: 1px solid rgba(0, 0, 0, 0.12);
}
</style>