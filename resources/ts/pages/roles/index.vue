<script setup lang="ts">
import { ref, computed, onMounted, watch, reactive } from 'vue'
import axios from "axios"

import moment from 'moment'
import Swal from 'sweetalert2'

// ────────────────────────────────────────
// Reactive state
// ────────────────────────────────────────
const isLoading = ref(true)
const allRoles = ref<any[]>([])
const allPermissions = ref<any[]>([])
const roleSearch = ref('')

// Pagination
const page = ref(1)
const rowsPerPage = ref(5)

// Dialogs
const editDialog = ref(false)
const createDialog = ref(false)
const dialogMode = ref<'edit' | 'create'>('create')

// Edit role
const selectedRole = ref<any>(null)
const updatedPermissions = ref<any[]>([])

// Create role
const newRoleName = ref('')

// Snackbar
const snackbar = ref({ show: false, message: '', color: 'success', timeout: 4000 })
const showSnackbar = (msg: string, color = 'success', timeout = 4000) => {
    snackbar.value = { show: true, message: msg, color, timeout }
}

// ────────────────────────────────────────
// Computed – table rows
// ────────────────────────────────────────
const tableRoles = computed(() => {
    return allRoles.value.map((role: any) => ({
        ...role,
        formatted_date: formatDate(role.created_at)
    }))
})

const filteredRoles = computed(() => {
    let list = tableRoles.value

    if (roleSearch.value) {
        const q = roleSearch.value.toLowerCase()
        list = list.filter(r =>
            r.name.toLowerCase().includes(q) ||
            r.permissions.some((p: any) => p.name.toLowerCase().includes(q))
        )
    }

    // Keep protected roles on top
    const protectedRoles = ['Super Admin', 'Hr', 'IT Administrator', 'Applicant', 'HOD']
    return list.slice().sort((a, b) => {
        const aProtected = protectedRoles.includes(a.name)
        const bProtected = protectedRoles.includes(b.name)
        return aProtected === bProtected ? 0 : aProtected ? -1 : 1
    })
})

const paginatedRoles = computed(() => {
    const start = (page.value - 1) * rowsPerPage.value
    const end = start + rowsPerPage.value
    return filteredRoles.value.slice(start, end)
})

// Permissions for autocomplete (exclude already assigned)
const availablePermissions = computed(() => {
    return allPermissions.value.filter(
        p => !updatedPermissions.value.some(up => up.id === p.id)
    )
})

// ────────────────────────────────────────
// Helpers
// ────────────────────────────────────────
const formatDate = (date: string) => {
    if (!date) return ''
    return moment(date).format('DD MMMM YYYY')
}
const clearFilters = () => {
    roleSearch.value = ''
    page.value = 1
}

// ────────────────────────────────────────
// API
// ────────────────────────────────────────
const fetchPermissions = async () => {
    try {
        const { data } = await axios.get('/api/permissions')
        allPermissions.value = data
    } catch (e: any) {
        if (e.response?.status === 403) {
            showSnackbar(e.response.data.message, 'warning')
            setTimeout(() => window.history.back(), 3000)
        } else {
            showSnackbar('Failed to load permissions', 'error')
        }
    }
}

const fetchRoles = async () => {
    try {
        const { data } = await axios.get('/api/roles')
        allRoles.value = data.roles
    } catch (e: any) {
        if (e.response?.status === 403) {
            showSnackbar(e.response.data.message, 'warning')
            setTimeout(() => window.history.back(), 3000)
        } else {
            showSnackbar('Failed to load roles', 'error')
        }
    } finally {
        isLoading.value = false
    }
}

// ────────────────────────────────────────
// Actions
// ────────────────────────────────────────
const openCreateDialog = () => {
    newRoleName.value = ''
    updatedPermissions.value = []
    dialogMode.value = 'create'
    createDialog.value = true
}

const openEditDialog = (role: any) => {
    selectedRole.value = { ...role }
    updatedPermissions.value = [...role.permissions]
    dialogMode.value = 'edit'
    editDialog.value = true
}

const closeDialog = () => {
    editDialog.value = false
    createDialog.value = false
    selectedRole.value = null
    updatedPermissions.value = []
    newRoleName.value = ''
}

// Create role
const createRole = async () => {
    if (!newRoleName.value.trim() || updatedPermissions.value.length === 0) {
        showSnackbar('Please enter a role name and select at least one permission', 'warning')
        return
    }
    try {
        await axios.post('/api/roles', {
            name: newRoleName.value,
            permissions: updatedPermissions.value
        })
        showSnackbar('Role created successfully', 'success')
        closeDialog()
        fetchRoles()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Failed to create role', 'error')
    }
}

// Update role
const updateRole = async () => {
    if (!selectedRole.value?.name.trim()) {
        showSnackbar('Role name is required', 'error')
        return
    }
    try {
        await axios.put(`/api/roles/${selectedRole.value.id}`, {
            name: selectedRole.value.name,
            permissions: updatedPermissions.value
        })
        showSnackbar('Role updated successfully', 'success')
        closeDialog()
        fetchRoles()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Failed to update role', 'error')
    }
}

// Delete role
const deleteRole = async (id: number) => {
    const { isConfirmed } = await Swal.fire({
        title: 'Delete Role',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!'
    })
    if (!isConfirmed) return

    try {
        await axios.delete('/roles/' + id)
        showSnackbar('Role deleted successfully', 'success')
        fetchRoles()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Failed to delete role', 'error')
    }
}

// ────────────────────────────────────────
// Watchers
// ────────────────────────────────────────
watch(roleSearch, () => {
    page.value = 1
})

// ────────────────────────────────────────
// Lifecycle
// ────────────────────────────────────────
onMounted(async () => {
    await Promise.all([fetchPermissions(), fetchRoles()])
})
</script>

<template>
    <div>
        <!-- Loading -->
        <v-container v-if="isLoading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" size="64" />
        </v-container>

        <!-- Main Content -->
        <div v-else>
            <!-- Header -->
            <div class="d-flex justify-space-between align-center mb-5">
                <div>
                    <h1 class="text-h4">All Roles</h1>
                    <p class="text-subtitle-1 text-medium-emphasis">Role Management</p>
                </div>
                <v-btn color="primary" @click="openCreateDialog" prepend-icon="$plus">
                    Add Role
                </v-btn>
            </div>

            <!-- Search -->
            <div class="d-flex align-center justify-space-between mb-5 gap-3">
                <v-text-field v-model="roleSearch" label="Search roles..." prepend-inner-icon="$magnify"
                    variant="outlined" clearable hide-details placeholder="Search by name or permission..."
                    class="flex-grow-1" style="max-width: 400px;" />
            </div>

            <!-- Table -->
            <v-table class="bordered-table" density="comfortable" hide-default-header hide-default-footer>
                <thead class="bg-gray text-uppercase">
                    <tr class="text-secondary">
                        <th class="text-left pa-4">Role</th>
                        <th style="width: 35%" class="text-left pa-4">Permissions</th>
                        <th style="width: 20%" class="text-left pa-4">Created</th>
                        <th style="width: 15%" class="text-left pa-4">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-containerBg">
                    <!-- Empty State -->
                    <tr v-if="!filteredRoles.length">
                        <td colspan="4" class="text-center py-8">
                            <v-icon size="64" color="grey-lighten-1">$shieldAccountOutline</v-icon>
                            <h3 class="text-h6 text-grey mt-4">No roles found</h3>
                            <p class="text-grey">
                                {{ roleSearch ? 'Try adjusting your search' : 'No roles have been created yet' }}
                            </p>
                            <v-btn color="primary" variant="outlined" @click="clearFilters; openCreateDialog()"
                                class="mt-4">
                                Create First Role
                            </v-btn>
                        </td>
                    </tr>

                    <!-- Rows -->
                    <tr v-for="role in paginatedRoles" :key="role.id">
                        <!-- Role Name -->
                        <td class="pa-4">
                            <div class="d-flex align-center gap-2">
                                <span class="text-subtitle-1 font-weight-medium"
                                    :class="['Hr', 'Hod'].includes(role.name) ? 'text-uppercase' : ''">
                                    {{ role.name }}
                                </span>
                                <v-chip
                                    v-if="['Super Admin', 'Hr', 'IT Administrator', 'Applicant', 'HOD'].includes(role.name)"
                                    color="error" size="small" variant="tonal">
                                    Protected
                                </v-chip>
                            </div>
                        </td>

                        <!-- Permissions -->
                        <td class="pa-4">
                            <div class="d-flex flex-wrap gap-2">
                                <v-chip v-for="(perm, i) in role.permissions.slice(0, 3)" :key="i" size="small"
                                    class="text-capitalize mt-2 ms-2">
                                    {{ perm.name }}
                                </v-chip>
                                <v-btn v-if="role.permissions.length > 3" variant="text" color="primary" size="small"
                                    class="mt-2" @click="openEditDialog(role)">
                                    +{{ role.permissions.length - 3 }} more
                                </v-btn>
                            </div>
                        </td>

                        <!-- Created -->
                        <td class="pa-4">
                            <div class="d-flex align-center text-lightText">
                                <v-icon size="16" class="mr-2">$calendarOutline</v-icon>
                                {{ role.formatted_date }}
                            </div>
                        </td>

                        <!-- Actions -->
                        <td class="pa-4 text-left align-middle">
                            <v-menu>
                                <template #activator="{ props }">
                                    <v-btn v-bind="props" icon variant="text" color="grey" size="large">
                                        <v-icon>$dotsVertical</v-icon>
                                    </v-btn>
                                </template>
                                <v-list>
                                    <v-list-item
                                        v-if="!['Super Admin', 'Hr', 'IT Administrator', 'Applicant', 'HOD'].includes(role.name)"
                                        @click="openEditDialog(role)">
                                        <v-list-item-title>
                                            <v-icon start>$pencil</v-icon>Edit Role
                                        </v-list-item-title>
                                    </v-list-item>
                                    <v-list-item
                                        v-if="!['Super Admin', 'Hr', 'IT Administrator', 'Applicant', 'HOD'].includes(role.name)"
                                        @click="deleteRole(role.id)" class="text-error">
                                        <v-list-item-title>
                                            <v-icon start>$trashCanOutline</v-icon>Delete Role
                                        </v-list-item-title>
                                    </v-list-item>
                                    <v-list-item v-else disabled>
                                        <v-list-item-title>Unauthorized</v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <v-card-text v-if="filteredRoles.length" class="pt-4">
                <VRow class="align-center text-center text-sm-start" justify="space-between">

                    <!-- Left: Results info -->
                    <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                        <span class="text-medium-emphasis">
                            Showing
                            {{ ((page - 1) * rowsPerPage) + 1 }}–{{ Math.min(page * rowsPerPage, filteredRoles.length)
                            }}
                            of
                            {{ filteredRoles.length }}
                            roles

                            <span v-if="roleSearch" class="text-caption">
                                (Search: "{{ roleSearch }}")
                            </span>
                        </span>
                    </VCol>

                    <!-- Right: Pagination -->
                    <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">
                        <v-pagination v-model="page" :length="Math.ceil(filteredRoles.length / rowsPerPage)"
                            :total-visible="7" rounded="circle" density="comfortable" variant="outlined"
                            color="primary" />
                    </VCol>

                </VRow>
            </v-card-text>

        </div>

        <!-- Edit Role Dialog -->
        <v-dialog v-model="editDialog" max-width="800" persistent>
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <span class="text-h5">Edit Role: {{ selectedRole?.name }}</span>
                    <v-btn variant="text" icon @click="closeDialog">
                        <v-icon color="error" size="small">$close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pt-4">
                    <v-form @submit.prevent="updateRole">
                        <v-row>
                            <v-col cols="12">
                                <v-text-field v-model="selectedRole.name" label="Role Name *" variant="outlined"
                                    :rules="[(v: string) => !!v || 'Name is required', (v: string) => v.length >= 2 || 'Min 2 chars']"
                                    required />
                            </v-col>
                            <v-col cols="12">
                                <v-autocomplete v-model="updatedPermissions" :items="availablePermissions"
                                    item-title="name" item-value="id" label="Assign Permissions" variant="outlined"
                                    multiple chips closable-chips clearable />
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>

                <v-card-actions
                    v-if="!['Super Admin', 'Hr', 'Applicant', 'IT Administrator', 'HOD'].includes(selectedRole.name)"
                    class="pa-4">
                    <v-spacer />
                    <v-btn color="grey" variant="text" @click="closeDialog">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" @click="updateRole">Update Role</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Create Role Dialog -->
        <v-dialog v-model="createDialog" max-width="700" persistent>
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <span class="text-h5">Create Role</span>
                    <v-btn variant="text" icon @click="closeDialog">
                        <v-icon color="error" size="small">$close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pt-4">
                    <v-form @submit.prevent="createRole">
                        <v-row>
                            <v-col cols="12">
                                <v-text-field v-model="newRoleName" label="Role Name *" variant="outlined"
                                    :rules="[(v: string) => !!v || 'Name is required', (v: string) => v.length >= 2 || 'Min 2 chars']"
                                    required />
                            </v-col>
                            <v-col cols="12">
                                <v-autocomplete v-model="updatedPermissions" :items="allPermissions" item-title="name"
                                    item-value="id" label="Select Permissions" variant="outlined" multiple chips
                                    closable-chips />
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>

                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn color="grey" variant="text" @click="closeDialog">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" @click="createRole">Create Role</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <v-btn variant="text" @click="snackbar.show = false">Close</v-btn>
            </template>
        </v-snackbar>
    </div>
</template>

<style scoped>
.bordered-table {
    border: 1px solid var(--v-theme-border);
}

.bg-gray {
    background-color: rgb(245, 245, 245);
}

.bg-containerBg {
    background-color: rgb(var(--v-theme-surface));
}

.text-lightText {
    color: rgba(var(--v-theme-on-surface), 0.6);
}
</style>