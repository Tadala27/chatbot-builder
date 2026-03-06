<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';

const functions = ref([]);
const isLoading = ref(true);
const searchQuery = ref('');
const categoryFilter = ref('all');
const snackbar = ref({ show: false, message: '', color: 'success' });

const categories = computed(() => {
    const cats = new Set<string>();
    functions.value.forEach((fn: any) => cats.add(fn.category));
    return ['all', ...Array.from(cats)];
});

const filteredFunctions = computed(() => {
    let filtered = functions.value;

    if (categoryFilter.value !== 'all') {
        filtered = filtered.filter((fn: any) => fn.category === categoryFilter.value);
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter((fn: any) => 
            fn.name?.toLowerCase().includes(query) ||
            fn.description?.toLowerCase().includes(query)
        );
    }

    return filtered;
});

const groupedFunctions = computed(() => {
    const grouped: Record<string, any[]> = {};
    filteredFunctions.value.forEach((fn: any) => {
        if (!grouped[fn.category]) grouped[fn.category] = [];
        grouped[fn.category].push(fn);
    });
    return grouped;
});

const fetchBuiltInFunctions = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/built-in-functions');
        functions.value = response.data.functions || [];
    } catch (error: any) {
        snackbar.value = { show: true, message: 'Failed to load functions', color: 'error' };
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => {
    fetchBuiltInFunctions();
});
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <h2 class="text-h4 mb-2">Built-in Functions</h2>
                <p class="text-medium-emphasis">40+ functions available in all chatbots</p>
            </VCol>
        </VRow>

        <VRow class="mb-4">
            <VCol cols="12" md="8">
                <VTextField v-model="searchQuery" placeholder="Search functions..." variant="outlined" prepend-inner-icon="$magnify" clearable hide-details />
            </VCol>
            <VCol cols="12" md="4">
                <VSelect v-model="categoryFilter" :items="categories" label="Category" variant="outlined" hide-details />
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <div v-else>
            <VCard v-for="(fns, category) in groupedFunctions" :key="category" class="mb-4">
                <VCardTitle class="text-h6 text-capitalize">{{ category }}</VCardTitle>
                <VDivider />
                <VList>
                    <VListItem v-for="fn in fns" :key="fn.id">
                        <VListItemTitle class="d-flex align-center">
                            <VIcon icon="$function" color="primary" size="20" class="mr-2" />
                            <code class="mr-2">{{ fn.name }}</code>
                            <VChip size="x-small" color="info">{{ fn.return_type }}</VChip>
                        </VListItemTitle>
                        <VListItemSubtitle class="mt-2">{{ fn.description }}</VListItemSubtitle>
                        <VListItemSubtitle v-if="fn.parameters" class="mt-1">
                            <strong>Parameters:</strong> {{ JSON.parse(fn.parameters).join(', ') }}
                        </VListItemSubtitle>
                        <VListItemSubtitle v-if="fn.example_usage" class="mt-1">
                            <strong>Example:</strong> <code>{{ fn.example_usage }}</code>
                        </VListItemSubtitle>
                    </VListItem>
                </VList>
            </VCard>
        </div>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions><VBtn variant="text" @click="snackbar.show = false">Close</VBtn></template>
        </VSnackbar>
    </div>
</template>

<style scoped>
code {
    background: rgba(var(--v-theme-primary), 0.1);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 13px;
}
</style>