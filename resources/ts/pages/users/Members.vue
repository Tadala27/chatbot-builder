<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';
import moment from 'moment';

const members = ref([]);
const isLoading = ref(true);
const searchQuery = ref('');
const roleFilter = ref('all');
const snackbar = ref({ show: false, message: '', color: 'success' });
const editDialog = ref(false);
const selectedMember = ref<any>(null);
const newRole = ref('');

const roles = [
    { title: 'All Roles', value: 'all' },
    { title: 'Tenant Admin', value: 'tenant-admin' },
    { title: 'Bot Builder', value: 'bot-builder' },
    { title: 'Agent', value: 'agent' },
    { title: 'Viewer', value: 'viewer' },
];

const roleColors: Record<string, string> = {
    'tenant-admin': 'error',
    'bot-builder': 'primary',
    'agent': 'success',
    'viewer': 'info',
};

const filteredMembers = computed(() => {
    let filtered = members.value;

    if (roleFilter.value !== 'all') {
        filtered = filtered.filter((m: any) => m.roles?.includes(roleFilter.value));
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter((m: any) => 
            m.name?.toLowerCase().includes(query) ||
            m.email?.toLowerCase().includes(query)
        );
    }

    return filtered;
});

const fetchMembers = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/team/members');
        members.value = response.data.members || [];
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to load team members',
            color: 'error',
        };
    } finally {
        isLoading.value = false;
    }
};

const openEditDialog = (member: any) => {
    selectedMember.value = member;
    newRole.value = member.roles?.[0] || '';
    editDialog.value = true;
};

const updateRole = async () => {
    if (!selectedMember.value || !newRole.value) return;

    try {
        await axios.put(`/api/team/members/${selectedMember.value.id}/role`, {
            role: newRole.value,
        });

        snackbar.value = {
            show: true,
            message: 'Role updated successfully',
            color: 'success',
        };

        editDialog.value = false;
        fetchMembers();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to update role',
            color: 'error',
        };
    }
};

const removeMember = async (member: any) => {
    if (!confirm(`Remove ${member.name} from the team?`)) return;

    try {
        await axios.delete(`/api/team/members/${member.id}`);
        snackbar.value = {
            show: true,
            message: 'Member removed successfully',
            color: 'success',
        };
        fetchMembers();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to remove member',
            color: 'error',
        };
    }
};

onMounted(() => {
    fetchMembers();
});
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-h4 mb-2">Team Members</h2>
                        <p class="text-medium-emphasis">Manage your team and roles</p>
                    </div>
                    <VBtn color="primary" prepend-icon="$accountPlus" to="/team/invite">
                        Invite Member
                    </VBtn>
                </div>
            </VCol>
        </VRow>

        <VRow class="mb-4">
            <VCol cols="12" md="8">
                <VTextField
                    v-model="searchQuery"
                    placeholder="Search members..."
                    variant="outlined"
                    prepend-inner-icon="$magnify"
                    clearable
                    hide-details
                />
            </VCol>
            <VCol cols="12" md="4">
                <VSelect
                    v-model="roleFilter"
                    :items="roles"
                    item-title="title"
                    item-value="value"
                    variant="outlined"
                    label="Filter by Role"
                    hide-details
                />
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <VRow v-else-if="filteredMembers.length === 0" justify="center">
            <VCol cols="12" md="8">
                <VCard class="pa-8 text-center" variant="outlined">
                    <VIcon icon="$accountGroup" size="80" color="primary" class="mb-4" />
                    <h3 class="text-h5 mb-2">No Team Members Found</h3>
                    <p class="text-medium-emphasis mb-6">
                        {{ searchQuery ? 'Try adjusting your search' : 'Invite team members to collaborate' }}
                    </p>
                    <VBtn v-if="!searchQuery" color="primary" size="large" to="/team/invite">
                        Invite Your First Member
                    </VBtn>
                </VCard>
            </VCol>
        </VRow>

        <VCard v-else>
            <VList>
                <VListItem v-for="(member, index) in filteredMembers" :key="member.id">
                    <template #prepend>
                        <VAvatar color="primary" size="48">
                            <span class="text-h6">{{ member.name?.charAt(0).toUpperCase() }}</span>
                        </VAvatar>
                    </template>

                    <VListItemTitle class="d-flex align-center">
                        <span class="text-h6 mr-2">{{ member.name }}</span>
                        <VChip
                            v-for="role in member.roles"
                            :key="role"
                            :color="roleColors[role] || 'grey'"
                            size="small"
                            class="mr-1"
                        >
                            {{ role }}
                        </VChip>
                    </VListItemTitle>

                    <VListItemSubtitle class="mt-1">
                        <div class="d-flex align-center gap-4 flex-wrap">
                            <div class="d-flex align-center">
                                <VIcon icon="$email" size="16" class="mr-1" />
                                <span>{{ member.email }}</span>
                            </div>
                            <div class="d-flex align-center">
                                <VIcon icon="$calendar" size="16" class="mr-1" />
                                <span>Joined {{ moment(member.joined_at).fromNow() }}</span>
                            </div>
                        </div>
                    </VListItemSubtitle>

                    <template #append>
                        <div class="d-flex gap-2">
                            <VBtn
                                icon
                                variant="text"
                                size="small"
                                @click="openEditDialog(member)"
                            >
                                <VIcon icon="$pencil" />
                            </VBtn>
                            <VBtn
                                icon
                                variant="text"
                                size="small"
                                color="error"
                                @click="removeMember(member)"
                            >
                                <VIcon icon="$delete" />
                            </VBtn>
                        </div>
                    </template>

                    <VDivider v-if="index < filteredMembers.length - 1" class="mt-4" />
                </VListItem>
            </VList>
        </VCard>

        <!-- Edit Role Dialog -->
        <VDialog v-model="editDialog" max-width="500">
            <VCard>
                <VCardTitle>Update Member Role</VCardTitle>
                <VDivider />
                <VCardText class="pa-6">
                    <VSelect
                        v-model="newRole"
                        :items="roles.filter(r => r.value !== 'all')"
                        item-title="title"
                        item-value="value"
                        label="Select Role"
                        variant="outlined"
                    />
                </VCardText>
                <VCardActions class="pa-6">
                    <VSpacer />
                    <VBtn variant="text" @click="editDialog = false">Cancel</VBtn>
                    <VBtn color="primary" @click="updateRole">Update Role</VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
</style>
