<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import { useRouter } from 'vue-router'
import axios from "axios"

import moment from 'moment'
import Swal from 'sweetalert2'

const router = useRouter()

// ────────────────────────────────────────
// Reactive State
// ────────────────────────────────────────
const isLoading = ref(true)
const Loading = ref(false)
const users = ref<any[]>([])
const pagination = ref({
    total: 0,
    per_page: 20,
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
    has_more: false
})
const userTypeCounts = ref<Record<string, number>>({})
const hovered = ref('')
const searchQuery = ref('')
const selectedUserType = ref('') // 'staff' or '' (all)
const sortBy = ref('created_at')
const sortOrder = ref('desc')

const snackbar = ref({ show: false, message: '', color: 'success', timeout: 4000 })
const showSnackbar = (msg: string, color = 'success', timeout = 4000) => {
    snackbar.value = { show: true, message: msg, color, timeout }
}
const toggleSort = (field: string) => {
    if (sortBy.value === field) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortBy.value = field
        sortOrder.value = 'asc'
    }
}

// Edit Dialog
const editDialog = ref(false)
const currentEditId = ref<number | null>(null)
const editForm = ref({
    firstname: '',
    middlename: '',
    lastname: '',
    email: '',
    role_id: '',
    department_id: '',
    user_type: ''
})

const allRoles = ref<any[]>([])
const allDepartments = ref<any[]>([])

// ────────────────────────────────────────
// UI Options
// ────────────────────────────────────────
const userTypeOptions = computed(() => {
    const options = [{ title: 'All Users', value: '' }]
    Object.entries(userTypeCounts.value).forEach(([type, count]) => {
        const label = type === 'staff' ? 'Staff' : type.replace('_', ' ').charAt(0).toUpperCase() + type.replace('_', ' ').slice(1)
        options.push({ title: `${label} (${count})`, value: type })
    })
    return options
})

const sortOptions = [
    { title: 'Created At', value: 'created_at' },
    { title: 'First Name', value: 'firstname' },
    { title: 'Last Name', value: 'lastname' },
    { title: 'Email', value: 'email' }
]

// ────────────────────────────────────────
// Helpers
// ────────────────────────────────────────
const formatDate = (date: string) => moment(date).format('DD MMM YYYY')
const getStatus = (active: boolean) => {
    return active
        ? { color: 'text-success', icon: '$checkCircleOutline', label: 'Active' }
        : { color: 'text-error', icon: '$closeCircleOutline', label: 'Inactive' }
}

// ────────────────────────────────────────
// API Calls
// ────────────────────────────────────────
const fetchUsers = async () => {
    try {
        Loading.value = true

        const params = {
            page: pagination.value.current_page,
            per_page: pagination.value.per_page,
            search: searchQuery.value || undefined,
            user_type: selectedUserType.value || undefined,
            sort_by: sortBy.value,
            sort_order: sortOrder.value,
        }

        const { data } = await axios.get('/api/users', { params })

        if (data.success) {
            users.value = data.data.data

            pagination.value = {
                total: data.data.total,
                per_page: data.data.per_page,
                current_page: data.data.current_page,
                last_page: data.data.last_page,
                from: data.data.from,
                to: data.data.to,
                has_more: !!data.data.next_page_url
            }

            if (!selectedUserType.value) {
                userTypeCounts.value = data.user_type_counts || {}
            }
        }
    } catch (e: any) {
        if (e.response?.status === 403) {
            Swal.fire('Access Denied', e.response.data.message, 'error')
                .then(() => window.history.back())
        } else {
            console.error(e)
            showSnackbar(e.response?.data?.message || 'Failed to load users', 'error')
        }
    } finally {
        Loading.value = false
        isLoading.value = false
    }
}

const fetchRolesAndDepartments = async () => {
    try {
        const [rolesRes, deptRes] = await Promise.all([
            axios.get('/api/roles'),
            axios.get('/api/business-units')
        ])
        allRoles.value = rolesRes.data.roles || rolesRes.data
        allDepartments.value = deptRes.data
    } catch (e) { console.error(e) }
}

// ────────────────────────────────────────
// Actions
// ────────────────────────────────────────
const deleteUser = async (id: number) => {
    const { isConfirmed } = await Swal.fire({
        title: 'Delete User',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete!',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#ef4444'
    })
    if (!isConfirmed) return
    try {
        await axios.delete(`/user/${id}`)
        showSnackbar('User deleted successfully', 'success')
        fetchUsers()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Failed to delete', 'error')
    }
}

const unlockUser = async (id: number) => {
    const { isConfirmed } = await Swal.fire({
        title: 'Unlock User',
        text: 'Allow login again?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, unlock'
    })
    if (!isConfirmed) return
    try {
        await axios.post(`/user/${id}/unlock`)
        showSnackbar('User unlocked', 'success')
        fetchUsers()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Failed to unlock', 'error')
    }
}

const openEditDialog = (user: any) => {
    editForm.value = {
        firstname: user.firstname || '',
        middlename: user.middlename || '',
        lastname: user.lastname || '',
        email: user.email || '',
        role_id: user.role_id || '',
        department_id: user.department_id || '',
        user_type: user.user_type || ''
    }
    currentEditId.value = user.id
    editDialog.value = true
}
const openDetails = (user: any) => {
    // router.push(`/users/details`)
    router.push(`/users/details/${user.id}`)
}

const closeEditDialog = () => {
    editDialog.value = false
    currentEditId.value = null
}

const submitEdit = async () => {
    if (!editForm.value.firstname || !editForm.value.lastname || !editForm.value.email) {
        showSnackbar('Fill required fields', 'error')
        return
    }
    try {
        await axios.put(`/api/user/update/${currentEditId.value}`, editForm.value)
        showSnackbar('User updated', 'success')
        closeEditDialog()
        fetchUsers()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Update failed', 'error')
    }
}

// ────────────────────────────────────────
// Watchers
// ────────────────────────────────────────
watchDebounced(
    [searchQuery, selectedUserType, sortBy, sortOrder],
    () => {
        pagination.value.current_page = 1
        fetchUsers()
    },
    { debounce: 500, maxWait: 1000 }
)


watch(() => pagination.value.current_page, () => fetchUsers())

// ────────────────────────────────────────
// Lifecycle
// ────────────────────────────────────────
onMounted(() => {
    fetchUsers()
    fetchRolesAndDepartments()
})
</script>

<template>
    <div>
        <!-- Loading -->
        <v-container v-if="isLoading" class="d-flex justify-center py-12">
            <v-progress-circular indeterminate color="primary" size="64" />
        </v-container>

        <!-- Content -->
        <div v-else>
            <!-- Header -->
            <v-row align="center">
                <v-col cols="12" sm="8">
                    <h1 class="text-h4 font-weight-bold">User Management</h1>
                    <p class="text-subtitle-1 text-medium-emphasis mt-1">
                        {{ selectedUserType === 'staff' ? 'All Staff' : 'All Users' }}
                        <span class="text-secondary">({{ pagination.total }})</span>
                    </p>
                </v-col>

                <v-col cols="12" sm="4" class="text-start text-sm-end">
                    <v-btn color="primary" prepend-icon="$plus" @click="router.push({ name: 'users-create' })">
                        Create User
                    </v-btn>
                </v-col>
            </v-row>

            <v-row align="center" class="mb-1">
                <v-col cols="12" sm="4">
                    <v-text-field v-model="searchQuery" label="Search..." prepend-inner-icon="$magnify"
                        variant="outlined" clearable hide-details density="comfortable" />
                </v-col>

                <v-col cols="0" sm="auto" class="flex-grow-1"></v-col>

                <v-col cols="12" sm="3" lg="2">
                    <v-select v-model="selectedUserType" :items="userTypeOptions" item-title="title" item-value="value"
                        label="User Type" variant="outlined" hide-details clearable prepend-inner-icon="$accountGroup"
                        density="comfortable" />
                </v-col>

                <v-col cols="12" sm="3" lg="2">
                    <v-select v-model="sortBy" :items="sortOptions" item-title="title" item-value="value"
                        label="Sort By" variant="outlined" hide-details density="comfortable" />
                </v-col>
            </v-row>

            <!-- Table -->
            <v-table class="bordered-table" density="comfortable">
                <thead class="bg-gray text-uppercase">
                    <tr class="text-secondary">
                        <th class="text-left pa-4 cursor-pointer" @click="toggleSort('firstname')"
                            @mouseenter="hovered = 'firstname'" @mouseleave="hovered = ''">
                            User
                            <v-icon v-if="hovered === 'firstname' || sortBy === 'firstname'" size="18">
                                {{
                                    sortBy === 'firstname'
                                        ? (sortOrder === 'asc' ? '$chevronUp' : '$chevronDown')
                                        : '$chevronUp'
                                }}
                            </v-icon>
                        </th>

                        <th class="text-left pa-4">Status</th>

                        <th class="text-left pa-4 cursor-pointer" @click="toggleSort('created_at')"
                            @mouseenter="hovered = 'created_at'" @mouseleave="hovered = ''">
                            Created
                            <v-icon v-if="hovered === 'created_at' || sortBy === 'created_at'" size="18" class="ms-1">
                                {{
                                    sortBy === 'created_at'
                                        ? (sortOrder === 'asc' ? '$chevronUp' : '$chevronDown')
                                        : '$chevronUp'
                                }}
                            </v-icon>
                        </th>

                        <th class="text-left pa-4">Actions</th>
                    </tr>
                </thead>

                <tbody v-if="Loading">
                    <tr>
                        <td colspan="4" class="py-12">
                            <div class="d-flex justify-center">
                                <v-progress-circular indeterminate color="primary" size="64" />
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tbody v-else class="bg-containerBg">
                    <!-- Empty -->
                    <tr v-if="!users.length">
                        <td colspan="4" class="text-center py-8">
                            <v-icon size="64" color="grey-lighten-1">$accountMultipleOutline</v-icon>
                            <h3 class="text-h6 text-grey mt-4">No users found</h3>
                            <p class="text-grey">Try adjusting filters or create a new user.</p>
                        </td>
                    </tr>

                    <!-- Rows -->
                    <tr v-for="user in users" :key="user.id">
                        <td class="pa-4">
                            <div class="d-flex align-center gap-3">
                                <v-avatar color="primary" size="40">
                                    <span class="text-white text-h6">{{ (user.firstname?.[0] || '') +
                                        (user.lastname?.[0] || '') }}</span>
                                </v-avatar>
                                <div>
                                    <div class="d-flex align-center gap-2 flex-wrap">
                                        <div class="title-link text-subtitle-1 font-weight-medium">
                                            {{ user.firstname }} {{ user.lastname }}
                                        </div>
                                        <v-chip v-if="user.user_role" color="primary" variant="tonal" size="small"
                                            class="text-capitalize">
                                            {{ user.user_role }}
                                        </v-chip>
                                    </div>
                                    <div class="text-lightText text-caption d-flex align-center gap-2 flex-wrap">
                                        <span>{{ user.email }}</span>
                                        <span v-if="user.department_name" class="mx-1">•</span>
                                        <span v-if="user.department_name">{{ user.department_name }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td class="pa-4">
                            <v-chip :color="getStatus(user.is_active).color.replace('text-', '')" variant="tonal"
                                size="small" :prepend-icon="getStatus(user.is_active).icon" class="text-capitalize">
                                {{ getStatus(user.is_active).label }}
                            </v-chip>
                        </td>

                        <td class="pa-4 text-lightText">
                            <div class="d-flex align-center">
                                <SvgSprite name="custom-calendar-plus" class="me-1" style="width:18px;height:18px" />
                                <span>{{ formatDate(user.created_at) }}</span>
                            </div>
                        </td>

                        <td class="pa-4">
                            <v-menu location="bottom">
                                <template #activator="{ props }">
                                    <v-btn v-bind="props" icon variant="text" color="grey">
                                        <v-icon>$dotsVertical</v-icon>
                                    </v-btn>
                                </template>
                                <v-list>
                                    <v-list-item v-if="!user.is_active" @click="unlockUser(user.id)">
                                        <v-list-item-title class="text-success">
                                            <v-icon start>$lockOpenVariantOutline</v-icon> Unlock
                                        </v-list-item-title>
                                    </v-list-item>
                                    <v-list-item @click="openDetails(user)">
                                        <v-list-item-title>
                                            <v-icon start>$eyeCircleOutline</v-icon> View
                                        </v-list-item-title>
                                    </v-list-item>
                                    <v-list-item @click="openEditDialog(user)">
                                        <v-list-item-title>
                                            <v-icon start>$pencil</v-icon> Edit
                                        </v-list-item-title>
                                    </v-list-item>
                                    <v-list-item @click="deleteUser(user.id)" class="text-error">
                                        <v-list-item-title>
                                            <v-icon start>$trashCanOutline</v-icon> Delete
                                        </v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <!-- Pagination -->
            <v-card-text v-if="pagination.total > 0" class="pt-4">
                <VRow class="align-center text-center text-sm-start" justify="space-between">
                    <!-- Left Text -->
                    <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                        <span class="text-medium-emphasis">
                            Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }} users
                        </span>
                    </VCol>
                    <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">

                        <v-pagination v-model="pagination.current_page" :length="pagination.last_page"
                            :total-visible="5" rounded="circle" density="comfortable" variant="outlined"
                            color="primary" />
                    </VCol>
                </VRow>


            </v-card-text>
        </div>

        <!-- Edit Dialog -->
        <v-dialog v-model="editDialog" max-width="1000" scrollable persistent>
            <v-card>
                <v-card-title class="d-flex justify-space-between">
                    <span class="text-h5">Edit User</span>
                    <v-btn variant="text" icon @click="closeEditDialog"><v-icon color="error">$close</v-icon></v-btn>
                </v-card-title>
                <v-card-text class="pt-4">
                    <v-form @submit.prevent="submitEdit" :disabled="editForm.user_type !== 'staff'">
                        <v-row>
                            <v-col cols="12" sm="4">
                                <v-text-field v-model="editForm.firstname" label="First Name *" variant="outlined"
                                    required />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <v-text-field v-model="editForm.middlename" label="Middle Name" variant="outlined" />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <v-text-field v-model="editForm.lastname" label="Last Name *" variant="outlined"
                                    required />
                            </v-col>
                        </v-row>
                        <v-row>
                            <v-col cols="12" sm="4">
                                <v-text-field v-model="editForm.email" label="Email *" type="email" variant="outlined"
                                    required />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <v-select v-model="editForm.role_id" :items="allRoles" item-title="name" item-value="id"
                                    label="Role" variant="outlined" />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <v-select v-model="editForm.department_id" :items="allDepartments" item-title="name"
                                    item-value="id" label="Department" variant="outlined" />
                            </v-col>
                        </v-row>
                    </v-form>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn color="grey" variant="text" @click="closeEditDialog">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" @click="submitEdit">Save</v-btn>
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
.title-link {
    color: inherit;
    text-decoration: none;
    transition: color 0.3s;
}

.title-link:hover {
    color: rgb(var(--v-theme-primary));
}

.bordered-table {
    border: 1px solid var(--v-theme-border);
}

.bg-gray {
    background-color: #f5f5f5;
}

.bg-containerBg {
    background-color: rgb(var(--v-theme-surface));
}

.gap-3 {
    gap: 12px;
}
</style>