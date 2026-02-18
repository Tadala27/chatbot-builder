<script setup lang="ts">
import { ref, watch } from "vue";

const props = defineProps<{
    modelValue: string;
    results: any[];
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: "update:modelValue", value: string): void;
    (e: "search"): void;
    (e: "select", result: any): void;
}>();

const localValue = ref(props.modelValue);
const showResults = ref(false);
const searchTimeout = ref<any>(null);

watch(
    () => props.modelValue,
    (val) => {
        localValue.value = val;
    }
);

watch(localValue, (val) => {
    emit("update:modelValue", val);

    // Debounce search
    clearTimeout(searchTimeout.value);
    searchTimeout.value = setTimeout(() => {
        if (val && val.length >= 2) {
            emit("search");
            showResults.value = true;
        } else {
            showResults.value = false;
        }
    }, 300);
});

const handleSelect = (result: any) => {
    emit("select", result);
    showResults.value = false;
    localValue.value = "";
};

const getResultIcon = (type: string) => {
    if (type === "position") return "$briefcase";
    if (type === "user") return "$account";
    return "$helpCircle";
};

const getResultColor = (type: string) => {
    if (type === "position") return "primary";
    if (type === "user") return "success";
    return "grey";
};
</script>

<template>
    <div class="organogram-search">
        <v-text-field v-model="localValue" label="Search positions or users" prepend-inner-icon="$magnify"
            variant="outlined" density="compact" hide-details clearable :loading="loading"
            placeholder="Type to search..." />

        <v-menu v-model="showResults" :close-on-content-click="false" activator="parent" max-height="400"
            max-width="400">
            <v-card v-if="results.length > 0" elevation="8">
                <v-list density="compact">
                    <v-list-item v-for="(result, index) in results" :key="index" @click="handleSelect(result)">
                        <template #prepend>
                            <v-avatar :color="getResultColor(result.type)" variant="tonal" size="32">
                                <v-icon size="small">{{ getResultIcon(result.type) }}</v-icon>
                            </v-avatar>
                        </template>

                        <v-list-item-title>
                            {{ result.name }}
                            <v-chip size="x-small" :color="getResultColor(result.type)" class="ml-2">
                                {{ result.type }}
                            </v-chip>
                        </v-list-item-title>

                        <v-list-item-subtitle>
                            <span v-if="result.type === 'position'">
                                {{ result.code }}
                                <span v-if="result.current_holder">
                                    | {{ result.current_holder.name }}
                                </span>
                                <span v-else class="text-error">| Vacant</span>
                            </span>
                            <span v-else-if="result.type === 'user'">
                                {{ result.email }}
                                <span v-if="result.current_position">
                                    | {{ result.current_position.name }}
                                </span>
                            </span>
                        </v-list-item-subtitle>

                        <template #append>
                            <v-icon size="small">$chevronRight</v-icon>
                        </template>
                    </v-list-item>
                </v-list>
            </v-card>

            <v-card v-else-if="localValue && localValue.length >= 2" elevation="8">
                <v-card-text class="text-center py-4 text-grey">
                    No results found
                </v-card-text>
            </v-card>
        </v-menu>
    </div>
</template>

<style scoped>
.organogram-search {
    position: relative;
}
</style>