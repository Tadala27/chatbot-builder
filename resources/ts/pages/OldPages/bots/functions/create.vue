<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();

const form = ref({
    name: '',
    slug: '',
    description: '',
    function_type: 'javascript',
    code: '',
    parameters: [],
    return_type: 'string',
    is_async: false,
    timeout_seconds: 30,
});

const isSubmitting = ref(false);
const snackbar = ref({ show: false, message: '', color: 'success' });

const functionTypes = [
    { title: 'JavaScript', value: 'javascript', description: 'Execute JavaScript code' },
    { title: 'API Call', value: 'api_call', description: 'Call external API' },
    { title: 'Webhook', value: 'webhook', description: 'Trigger webhook' },
];

const returnTypes = ['string', 'number', 'boolean', 'object', 'array'];

const codeTemplates: Record<string, string> = {
    javascript: `function execute(params) {
  // Your code here
  return params.input;
}`,
    api_call: `{
  "url": "https://api.example.com/endpoint",
  "method": "GET",
  "headers": {
    "Content-Type": "application/json"
  }
}`,
    webhook: `{
  "url": "https://webhook.example.com/endpoint",
  "method": "POST"
}`,
};

const updateCodeTemplate = () => {
    if (!form.value.code) {
        form.value.code = codeTemplates[form.value.function_type] || '';
    }
};

const submit = async () => {
    if (!form.value.name || !form.value.code) {
        snackbar.value = { show: true, message: 'Name and code are required', color: 'error' };
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await axios.post('/api/functions', form.value);
        snackbar.value = { show: true, message: 'Function created successfully', color: 'success' };
        setTimeout(() => router.push('/functions'), 1000);
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to create function',
            color: 'error',
        };
    } finally {
        isSubmitting.value = false;
    }
};

updateCodeTemplate();
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center">
                    <VBtn icon variant="text" @click="router.push('/functions')">
                        <VIcon icon="$arrowLeft" />
                    </VBtn>
                    <div class="ml-4">
                        <h2 class="text-h4 mb-2">Create Custom Function</h2>
                        <p class="text-medium-emphasis">Write custom logic for your chatbots</p>
                    </div>
                </div>
            </VCol>
        </VRow>

        <VCard>
            <VCardText class="pa-6">
                <VForm @submit.prevent="submit">
                    <VRow>
                        <VCol cols="12" md="6">
                            <VTextField v-model="form.name" label="Function Name *" placeholder="e.g., Calculate Discount" variant="outlined" required />
                        </VCol>
                        <VCol cols="12" md="6">
                            <VTextField v-model="form.slug" label="Slug (optional)" placeholder="Auto-generated from name" variant="outlined" hint="Used to call the function" persistent-hint />
                        </VCol>
                        <VCol cols="12">
                            <VTextarea v-model="form.description" label="Description" placeholder="What does this function do?" variant="outlined" rows="2" />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12">
                            <h3 class="text-h6 mb-4">Function Type</h3>
                            <VRadioGroup v-model="form.function_type" @update:model-value="updateCodeTemplate">
                                <VRow>
                                    <VCol v-for="type in functionTypes" :key="type.value" cols="12" md="4">
                                        <VCard variant="outlined" :class="{ 'border-primary': form.function_type === type.value }" @click="form.function_type = type.value; updateCodeTemplate()">
                                            <VCardText>
                                                <VRadio :value="type.value" :label="type.title" />
                                                <p class="text-caption text-medium-emphasis mt-2 mb-0">{{ type.description }}</p>
                                            </VCardText>
                                        </VCard>
                                    </VCol>
                                </VRow>
                            </VRadioGroup>
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12">
                            <h3 class="text-h6 mb-2">Code</h3>
                            <VTextarea v-model="form.code" variant="outlined" rows="15" class="code-editor" placeholder="Write your code here..." />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12" md="4">
                            <VSelect v-model="form.return_type" :items="returnTypes" label="Return Type" variant="outlined" />
                        </VCol>
                        <VCol cols="12" md="4">
                            <VTextField v-model.number="form.timeout_seconds" type="number" label="Timeout (seconds)" variant="outlined" min="1" max="300" />
                        </VCol>
                        <VCol cols="12" md="4">
                            <VCheckbox v-model="form.is_async" label="Async Execution" hint="Run function asynchronously" persistent-hint />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12" class="d-flex justify-end gap-3">
                            <VBtn variant="outlined" size="large" @click="router.push('/functions')" :disabled="isSubmitting">Cancel</VBtn>
                            <VBtn type="submit" color="primary" size="large" prepend-icon="$plus" :loading="isSubmitting">Create Function</VBtn>
                        </VCol>
                    </VRow>
                </VForm>
            </VCardText>
        </VCard>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions><VBtn variant="text" @click="snackbar.show = false">Close</VBtn></template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.gap-3 { gap: 12px; }
.border-primary { border-color: rgb(var(--v-theme-primary)) !important; border-width: 2px !important; }
.code-editor { font-family: 'Monaco', 'Menlo', 'Courier New', monospace; font-size: 13px; }
</style>