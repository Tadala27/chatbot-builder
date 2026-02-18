<script setup lang="ts">
import { computed } from "vue";

const props = defineProps<{
    statistics: any;
}>();

const stats = computed(() => props.statistics?.stats || {});
const levelsDistribution = computed(
    () => props.statistics?.levels_distribution || []
);

// Calculate percentages
const vacancyRate = computed(() => {
    if (!stats.value.total_positions || stats.value.total_positions === 0) return 0;
    return Math.round(
        (stats.value.vacant_positions / stats.value.total_positions) * 100
    );
});

const fillRate = computed(() => {
    return 100 - vacancyRate.value;
});

// Format numbers
const formatNumber = (num: number) => {
    return new Intl.NumberFormat().format(num || 0);
};

// Get color for vacancy rate
const getVacancyColor = (rate: number) => {
    if (rate < 10) return "success";
    if (rate < 25) return "warning";
    return "error";
};
</script>

<template>
    <v-card elevation="2">
        <v-card-title class="bg-grey-lighten-4">
            <v-icon start>$chartBox</v-icon>
            Organization Statistics
        </v-card-title>

        <v-card-text>
            <!-- Additional Stats -->
            <v-row class="mt-2">
                <v-col cols="12" md="4">
                    <v-card variant="outlined">
                        <v-card-text>
                            <h6 class="text-subtitle-2 mb-3">Position Types</h6>
                            <v-row dense>
                                <v-col cols="6">
                                    <div class="d-flex align-center">
                                        <v-icon color="warning" class="mr-2">$accountStar</v-icon>
                                        <div>
                                            <div class="text-body-2 font-weight-bold">
                                                {{ formatNumber(stats.supervisor_positions) }}
                                            </div>
                                            <div class="text-caption text-grey">Supervisors</div>
                                        </div>
                                    </div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="d-flex align-center">
                                        <v-icon color="primary" class="mr-2">$account</v-icon>
                                        <div>
                                            <div class="text-body-2 font-weight-bold">
                                                {{ formatNumber(stats.subordinate_positions) }}
                                            </div>
                                            <div class="text-caption text-grey">Subordinates</div>
                                        </div>
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="4">
                    <v-card variant="outlined">
                        <v-card-text>
                            <h6 class="text-subtitle-2 mb-3">Organization Positions</h6>
                            <v-row dense>
                                <v-col cols="6">
                                    <div class="d-flex align-center">
                                        <v-icon color="info" class="mr-2">$account</v-icon>
                                        <div>
                                            <div class="text-body-2 font-weight-bold">
                                                {{ formatNumber(stats.filled_positions) }}
                                            </div>
                                            <div class="text-caption text-grey">Filled Positions</div>
                                        </div>
                                    </div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="d-flex align-center">
                                        <v-icon color="warning" class="mr-2">$accountOff</v-icon>
                                        <div>
                                            <div class="text-body-2 font-weight-bold">
                                                {{ formatNumber(stats.vacant_positions) }}
                                            </div>
                                            <div class="text-caption text-grey">Vacant Positions</div>
                                        </div>
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>
                <v-col cols="12" md="4">
                    <v-card variant="outlined">
                        <v-card-text>
                            <h6 class="text-subtitle-2 mb-3">Organization Metrics</h6>
                            <v-row dense>
                                <v-col cols="6">
                                    <div class="d-flex align-center">
                                        <v-icon color="info" class="mr-2">$officeBuilding</v-icon>
                                        <div>
                                            <div class="text-body-2 font-weight-bold">
                                                {{ formatNumber(stats.business_units) }}
                                            </div>
                                            <div class="text-caption text-grey">Business Units</div>
                                        </div>
                                    </div>
                                </v-col>
                                <v-col cols="6">
                                    <div class="d-flex align-center">
                                        <v-icon color="success" class="mr-2">$chartLine</v-icon>
                                        <div>
                                            <div class="text-body-2 font-weight-bold">
                                                {{ stats.span_of_control || 0 }}
                                            </div>
                                            <div class="text-caption text-grey">Span of Control</div>
                                        </div>
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Levels Distribution -->
            <v-row v-if="levelsDistribution.length > 0" class="mt-2">
                <v-col cols="12">
                    <v-card variant="outlined">
                        <v-card-text>
                            <h6 class="text-subtitle-2 mb-3">
                                <v-icon size="small" class="mr-1">$stairs</v-icon>
                                Position Levels Distribution
                            </h6>
                            <v-row dense>
                                <v-col v-for="level in levelsDistribution" :key="level.level" cols="6" sm="4" md="2">
                                    <div class="text-center pa-2 rounded border">
                                        <div class="text-h6 font-weight-bold text-primary">
                                            {{ level.count }}
                                        </div>
                                        <div class="text-caption">Level {{ level.level }}</div>
                                        <div class="text-caption text-grey">
                                            {{ level.filled }} filled, {{ level.vacant }} vacant
                                        </div>
                                    </div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.v-progress-linear {
    border-radius: 4px;
}
</style>