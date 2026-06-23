<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();

const form = ref({
    email: '',
    name: '',
    role: 'viewer',
});

const isSubmitting = ref(false);
const snackbar = ref({ show: false, message: '', color: 'success' });

const roles = [
    { 
        title: 'Tenant Admin', 
        value: 'tenant-admin',
        description: 'Full access to manage tenant, team, and settings',
        permissions: ['All permissions']
    },
    { 
        title: 'Bot Builder', 
        value: 'bot-builder',
        description: 'Create and edit chatbots and flows',
        permissions: ['Create/edit bots', 'Build flows', 'Manage functions']
    },
    { 
        title: 'Agent', 
        value: 'agent',
        description: 'Handle conversations and handoffs',
        permissions: ['View conversations', 'Respond to users', 'Handoff conversations']
    },
    { 
        title: 'Viewer', 
        value: 'viewer',
        description: 'Read-only access to view data',
        permissions: ['View bots', 'View conversations', 'View analytics']
    },
];

const selectedRole = ref(roles.find(r => r.value === 'viewer'));

const selectRole = (role: any) => {
    form.value.role = role.value;
    selectedRole.value = role;
};

const submit = async () => {
    if (!form.value.email || !form.value.name) {
        snackbar.value = {
            show: true,
            message: 'Email and name are required',
            color: 'error',
        };
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await axios.post('/api/team/invite', form.value);

        snackbar.value = {
            show: true,
            message: response.data.message || 'Invitation sent successfully',
            color: 'success',
        };

        setTimeout(() => {
            router.push('/team/members');
        }, 1000);
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to send invitation',
            color: 'error',
        };
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center">
                    <VBtn icon variant="text" @click="router.push('/team/members')">
                        <VIcon icon="$arrowLeft" />
                    </VBtn>
                    <div class="ml-4">
                        <h2 class="text-h4 mb-2">Invite Team Member</h2>
                        <p class="text-medium-emphasis">Send an invitation to join your team</p>
                    </div>
                </div>
            </VCol>
        </VRow>

        <VCard>
            <VCardText class="pa-6">
                <VForm @submit.prevent="submit">
                    <VRow>
                        <VCol cols="12" md="6">
                            <VTextField
                                v-model="form.name"
                                label="Full Name *"
                                placeholder="John Doe"
                                variant="outlined"
                                required
                            />
                        </VCol>
                        <VCol cols="12" md="6">
                            <VTextField
                                v-model="form.email"
                                label="Email Address *"
                                type="email"
                                placeholder="john@example.com"
                                variant="outlined"
                                required
                            />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12">
                            <h3 class="text-h6 mb-4">Select Role</h3>
                            <VRadioGroup v-model="form.role">
                                <VRow>
                                    <VCol v-for="role in roles" :key="role.value" cols="12" md="6">
                                        <VCard
                                            variant="outlined"
                                            :class="{ 'border-primary': form.role === role.value }"
                                            @click="selectRole(role)"
                                            class="cursor-pointer"
                                        >
                                            <VCardText>
                                                <div class="d-flex align-center mb-2">
                                                    <VRadio :value="role.value" class="mr-2" />
                                                    <h4 class="text-h6">{{ role.title }}</h4>
                                                </div>
                                                <p class="text-caption text-medium-emphasis mb-3">
                                                    {{ role.description }}
                                                </p>
                                                <div>
                                                    <p class="text-caption font-weight-bold mb-1">Permissions:</p>
                                                    <VChip
                                                        v-for="(perm, index) in role.permissions"
                                                        :key="index"
                                                        size="x-small"
                                                        class="mr-1 mb-1"
                                                    >
                                                        {{ perm }}
                                                    </VChip>
                                                </div>
                                            </VCardText>
                                        </VCard>
                                    </VCol>
                                </VRow>
                            </VRadioGroup>
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VAlert type="info" variant="tonal" class="mb-6">
                        <VAlertTitle>Invitation Details</VAlertTitle>
                        An email will be sent to <strong>{{ form.email || 'the user' }}</strong> with instructions to join your team.
                        They will be assigned the <strong>{{ selectedRole?.title }}</strong> role.
                    </VAlert>

                    <VRow>
                        <VCol cols="12" class="d-flex justify-end gap-3">
                            <VBtn
                                variant="outlined"
                                size="large"
                                @click="router.push('/team/members')"
                                :disabled="isSubmitting"
                            >
                                Cancel
                            </VBtn>
                            <VBtn
                                type="submit"
                                color="primary"
                                size="large"
                                prepend-icon="$send"
                                :loading="isSubmitting"
                            >
                                Send Invitation
                            </VBtn>
                        </VCol>
                    </VRow>
                </VForm>
            </VCardText>
        </VCard>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.gap-3 { gap: 12px; }
.border-primary { 
    border-color: rgb(var(--v-theme-primary)) !important; 
    border-width: 2px !important; 
}
.cursor-pointer { cursor: pointer; }
</style>