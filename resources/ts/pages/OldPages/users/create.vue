<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import axios from "axios";
import ExcelJS from "exceljs";
import { useUserStore } from '@/stores/user'
import Swal from 'sweetalert2'

// Search functionality
const searchField = ref('fullname')
const searchValue = ref('')

// Reactive data
const file = ref(null)
const renewOption = ref("create")
const selectedRole = ref('')
const auth = ref({
    tenant_id: "",
    firstname: "",
    lastname: "",
    email: "",
    employee_number: "",
    phone: "",
    user_role: "",
    password: "",
    is_active: true,
    level: "",
    grade: "",
    is_board_member: false,
    role: "", // For Spatie role assignment
    fetch_from_microsoft: true
})
const users = ref([])
const tenants = ref([])
const roleOptions = ref([])
const userRoleOptions = ref([
    { text: 'Admin', value: 'admin' },
    { text: 'Manager', value: 'manager' },
    { text: 'Employee', value: 'employee' },
    { text: 'Viewer', value: 'viewer' }
])
const selectedTenant = ref(null)
const isSaving = ref(false)
const isLoading = ref(true)
const isFetchingFromMicrosoft = ref(false)
const showAll = ref(false)
const resultQuery = ref([])
const searchQuery = ref(null)
const emailInput = ref('')

// Form references
const createForm = ref(null)
const importForm = ref(null)
const fileInput = ref(null)
const emailListForm = ref(null)

// Snackbar states
const snackbar = ref({
    show: false,
    message: '',
    color: 'success',
    timeout: 5000
})

const page = ref(1)
const rowsPerPage = ref(10)
const totalItems = ref(0)

// Validation rules
const firstnameRules = [
    (v: string) => !!v || 'First name is required',
    (v: string) => (v && v.length >= 2) || 'First name must be at least 2 characters',
    (v: string) => (v && v.length <= 100) || 'First name must be less than 100 characters',
]

const lastnameRules = [
    (v: string) => !!v || 'Last name is required',
    (v: string) => (v && v.length >= 2) || 'Last name must be at least 2 characters',
    (v: string) => (v && v.length <= 100) || 'Last name must be less than 100 characters',
]

const emailRules = [
    (v: string) => !!v || 'Email is required',
    (v: string) => /.+@.+\..+/.test(v) || 'Email must be valid',
    (v: string) => (v && v.length <= 100) || 'Email must be less than 100 characters'
]

const tenantRules = [
    (v: any) => !!v || 'Tenant is required',
]

const userRoleRules = [
    (v: any) => !!v || 'User role is required',
]

const passwordRules = [
    (v: string) => {
        if (!auth.value.fetch_from_microsoft) {
            return !!v || 'Password is required when not fetching from Microsoft';
        }
        return true;
    },
    (v: string) => {
        if (v && v.length > 0) {
            return v.length >= 8 || 'Password must be at least 8 characters';
        }
        return true;
    },
]

// Add computed for filtered users based on search
const filteredUsers = computed(() => {
    if (!searchQuery.value) {
        return users.value.map((user, index) => ({ ...user, originalIndex: index }));
    }
    const query = searchQuery.value.toLowerCase();
    return users.value
        .map((user, index) => ({ ...user, originalIndex: index }))
        .filter(user => {
            const fullname = `${user.firstname} ${user.lastname}`.toLowerCase();
            const email = user.email?.toLowerCase() || '';
            const employeeNumber = user.employee_number?.toLowerCase() || '';
            return fullname.includes(query) || email.includes(query) || employeeNumber.includes(query);
        });
});

// Update paginatedUsers to work with filtered users
const paginatedUsers = computed(() => {
    const start = (page.value - 1) * rowsPerPage.value;
    const end = start + rowsPerPage.value;
    return filteredUsers.value.slice(start, end);
});

// Watch for search changes to reset pagination
watch(searchQuery, () => {
    page.value = 1;
});

const downloadTemplate = async () => {
    const workbook = new ExcelJS.Workbook();
    const worksheet = workbook.addWorksheet('Users Import');

    // Define Columns
    worksheet.columns = [
        { header: 'Email', key: 'email', width: 30 },
        { header: 'Tenant', key: 'tenant', width: 20 },
        { header: 'User Role', key: 'user_role', width: 15 },
        { header: 'Level', key: 'level', width: 10 },
        { header: 'Grade', key: 'grade', width: 10 },
        { header: 'Is Board Member', key: 'is_board_member', width: 15 },
    ];

    // Style Header
    const headerRow = worksheet.getRow(1);
    headerRow.font = { bold: true };
    headerRow.fill = {
        type: 'pattern',
        pattern: 'solid',
        fgColor: { argb: 'FFE0E0E0' }
    };
    headerRow.alignment = { vertical: 'middle', horizontal: 'center' };

    // Get tenant and role names
    const tenantNames = tenants.value.map(t => t.name);
    const userRoles = userRoleOptions.value.map(r => r.value);

    // Add Example Row
    worksheet.addRow({
        email: 'john.doe@company.com',
        tenant: tenantNames[0] || 'Company Ltd',
        user_role: 'employee',
        level: 'Senior',
        grade: 'A',
        is_board_member: 'No'
    });

    // Add Dropdown Validation
    const totalRows = 1000;

    // Tenant Column (B)
    for (let row = 2; row <= totalRows; row++) {
        worksheet.getCell(`B${row}`).dataValidation = {
            type: 'list',
            allowBlank: true,
            formulae: [`"${tenantNames.join(',')}"`],
            showDropDown: true,
            showErrorMessage: true,
            errorStyle: 'error',
            errorTitle: 'Invalid Tenant',
            error: 'Please select a valid tenant from the dropdown.',
        };
    }

    // User Role Column (C)
    for (let row = 2; row <= totalRows; row++) {
        worksheet.getCell(`C${row}`).dataValidation = {
            type: 'list',
            allowBlank: true,
            formulae: [`"${userRoles.join(',')}"`],
            showDropDown: true,
            showErrorMessage: true,
            errorStyle: 'error',
            errorTitle: 'Invalid User Role',
            error: 'Please select a valid user role from the dropdown.',
        };
    }

    // Is Board Member Column (F)
    for (let row = 2; row <= totalRows; row++) {
        worksheet.getCell(`F${row}`).dataValidation = {
            type: 'list',
            allowBlank: true,
            formulae: ['"Yes,No"'],
            showDropDown: true,
        };
    }

    // Add note about Microsoft fetch
    const noteRow = worksheet.addRow([]);
    const noteCell = worksheet.getCell('A' + noteRow.number);
    noteCell.value = 'Note: Firstname, Lastname, Phone, and Employee Number will be automatically fetched from Microsoft';
    noteCell.font = { italic: true, color: { argb: 'FF0000FF' } };
    worksheet.mergeCells(`A${noteRow.number}:F${noteRow.number}`);

    // Generate & Download
    try {
        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'users-microsoft-import-template.xlsx';
        a.click();
        window.URL.revokeObjectURL(url);
        showSnackbar('Template downloaded successfully!', 'success');
    } catch (error) {
        console.error('Error generating template:', error);
        showSnackbar('Failed to generate template', 'error');
    }
};

// Computed properties
const canDownloadTemplate = computed(() =>
    tenants.value.length > 0
);

// Methods
const showSnackbar = (message: string, color = 'success', timeout = 5000) => {
    snackbar.value = {
        show: true,
        message,
        color,
        timeout
    };
}

// Fetch user details from Microsoft
const fetchFromMicrosoft = async () => {
    if (!auth.value.email) {
        showSnackbar('Please enter an email address', 'warning');
        return;
    }

    isFetchingFromMicrosoft.value = true;

    try {
        const response = await axios.post('/api/microsoft-users/fetch', {
            email: auth.value.email
        });

        if (response.data.success) {
            const msUser = response.data.data.microsoft_user;
            const suggestedTenant = response.data.data.suggested_tenant;

            // Populate form with Microsoft data
            auth.value.firstname = msUser.firstname;
            auth.value.lastname = msUser.lastname;
            auth.value.phone = msUser.phone || '';

            // Suggest tenant if found
            if (suggestedTenant) {
                auth.value.tenant_id = suggestedTenant.id;
            }

            showSnackbar('User details fetched from Microsoft!', 'success');
        }
    } catch (error: any) {
        const errorMsg = error.response?.data?.message || 'Failed to fetch user from Microsoft';
        showSnackbar(errorMsg, 'error');
        console.error('Error fetching from Microsoft:', error);
    } finally {
        isFetchingFromMicrosoft.value = false;
    }
}

// Fetch multiple users from email list
const fetchMultipleFromMicrosoft = async () => {
    if (!emailInput.value || emailInput.value.trim() === '') {
        showSnackbar('Please enter email addresses', 'warning');
        return;
    }

    isFetchingFromMicrosoft.value = true;

    try {
        // Split emails by comma, newline, or space
        const emails = emailInput.value
            .split(/[\n,\s]+/)
            .map(e => e.trim())
            .filter(e => e.length > 0 && /.+@.+\..+/.test(e));

        if (emails.length === 0) {
            showSnackbar('No valid email addresses found', 'warning');
            return;
        }

        const response = await axios.post('/api/microsoft-users/fetch-multiple', {
            emails: emails
        });

        if (response.data.success) {
            const fetchedUsers = response.data.data;
            const errors = response.data.errors;

            // Add fetched users to the list
            fetchedUsers.forEach(item => {
                const msUser = item.microsoft_user;
                const suggestedTenant = item.suggested_tenant;

                users.value.push({
                    email: item.email,
                    firstname: msUser.firstname,
                    lastname: msUser.lastname,
                    phone: msUser.phone,
                    tenant: suggestedTenant || null,
                    tenant_id: suggestedTenant?.id || null,
                    tenant_name: suggestedTenant?.name || '',
                    user_role: '',
                    level: '',
                    grade: '',
                    is_board_member: false,
                    from_microsoft: true
                });
            });

            // Show errors if any
            if (errors.length > 0) {
                const errorEmails = errors.map(e => e.email).join(', ');
                showSnackbar(`Could not fetch: ${errorEmails}`, 'warning', 8000);
            } else {
                showSnackbar(`Fetched ${fetchedUsers.length} users from Microsoft!`, 'success');
            }

            emailInput.value = '';
            resultQuery.value = [...users.value];
        }
    } catch (error: any) {
        const errorMsg = error.response?.data?.message || 'Failed to fetch users from Microsoft';
        showSnackbar(errorMsg, 'error');
        console.error('Error fetching from Microsoft:', error);
    } finally {
        isFetchingFromMicrosoft.value = false;
    }
}

// Fixed: Methods to handle tenant and role selection
const updateUserTenant = (userIndex: number, tenant: any) => {
    if (users.value[userIndex]) {
        users.value[userIndex].tenant = tenant;
        users.value[userIndex].tenant_id = tenant?.id;
    }
}

const updateUserRole = (userIndex: number, userRole: string) => {
    if (users.value[userIndex]) {
        users.value[userIndex].user_role = userRole;
    }
}

const fetchRoles = async () => {
    try {
        const response = await axios.get('/api/roles');
        roleOptions.value = response.data.roles.map(role => ({
            text: role.name,
            value: role.name,
        }));
    } catch (error: any) {
        console.error("Error fetching roles:", error);
        showSnackbar('Error fetching roles', 'error');
    }
}

const fetchTenants = async () => {
    try {
        const response = await axios.get('/api/tenants');
        tenants.value = response.data.data.data || response.data;
    } catch (error: any) {
        if (error.response?.status === 403) {
            showSnackbar(error.response.data.message, 'warning', 5000);
            setTimeout(() => {
                window.history.back();
            }, 5000);
        } else {
            console.error("Error fetching tenants:", error);
            showSnackbar('Error fetching tenants', 'error');
        }
    } finally {
        isLoading.value = false;
    }
}

const removeUser = (originalIndex: number) => {
    users.value.splice(originalIndex, 1);
    resultQuery.value = [...users.value];
    showSnackbar('User removed from list', 'info', 3000);
}

const parseExcelFile = async (file: File) => {
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(await file.arrayBuffer());

    const worksheet = workbook.worksheets[0];
    const jsonData: any[] = [];

    // Map column indices by header name
    const headerRow = worksheet.getRow(1);
    const colMap: Record<string, number> = {};
    headerRow.eachCell((cell, colNumber) => {
        const header = cell.text.trim().toLowerCase();
        colMap[header] = colNumber;
    });

    // Required columns
    const requiredCols = ['email'];
    for (const col of requiredCols) {
        if (!colMap[col]) {
            showSnackbar(`Missing required column: ${col}`, 'error');
            return;
        }
    }

    // Create lookup maps
    const tenantMap = new Map(tenants.value.map(t => [t.name.toLowerCase().trim(), t]));

    worksheet.eachRow({ includeEmpty: false }, (row, rowNumber) => {
        if (rowNumber === 1) return; // skip header

        const getCell = (key: string) => {
            const col = colMap[key];
            return col ? row.getCell(col).text.trim() : '';
        };

        const email = getCell('email');
        if (!email) return; // Skip empty rows

        const tenantName = getCell('tenant');
        const tenantObj = tenantName ? tenantMap.get(tenantName.toLowerCase().trim()) : null;
        const isBoardMember = getCell('is board member').toLowerCase();

        jsonData.push({
            email: email,
            tenant_name: tenantName,
            tenant: tenantObj || null,
            tenant_id: tenantObj?.id || null,
            user_role: getCell('user role'),
            level: getCell('level'),
            grade: getCell('grade'),
            is_board_member: isBoardMember === 'yes',
            // These will be fetched from Microsoft
            firstname: '',
            lastname: '',
            phone: '',
            from_microsoft: true
        });
    });

    users.value = jsonData;
    resultQuery.value = [...users.value];

    // Fetch all users from Microsoft
    await fetchUsersDataFromMicrosoft();
};

const fetchUsersDataFromMicrosoft = async () => {
    if (users.value.length === 0) return;

    isFetchingFromMicrosoft.value = true;
    showSnackbar('Fetching user details from Microsoft...', 'info', 3000);

    try {
        const emails = users.value.map(u => u.email);
        const response = await axios.post('/api/microsoft-users/fetch-multiple', { emails });

        if (response.data.success) {
            const fetchedUsers = response.data.data;

            // Update users with Microsoft data
            fetchedUsers.forEach(item => {
                const msUser = item.microsoft_user;
                const userIndex = users.value.findIndex(u => u.email === item.email);

                if (userIndex !== -1) {
                    users.value[userIndex].firstname = msUser.firstname;
                    users.value[userIndex].lastname = msUser.lastname;
                    users.value[userIndex].phone = msUser.phone;

                    // Suggest tenant if not already set
                    if (!users.value[userIndex].tenant_id && item.suggested_tenant) {
                        users.value[userIndex].tenant = item.suggested_tenant;
                        users.value[userIndex].tenant_id = item.suggested_tenant.id;
                    }
                }
            });

            showSnackbar('User details fetched from Microsoft!', 'success');
        }
    } catch (error: any) {
        showSnackbar('Some users could not be fetched from Microsoft', 'warning');
    } finally {
        isFetchingFromMicrosoft.value = false;
    }
}

const uploadFile = async () => {
    if (importForm.value) {
        const { valid } = await importForm.value.validate();
        if (!valid) {
            showSnackbar("Please fix validation errors.", "warning");
            return;
        }
    }

    isSaving.value = true;

    try {
        const payload = users.value.map((user, index) => {
            let errors = [];

            if (!user.tenant_id) {
                errors.push(`Tenant not selected`);
            }
            if (!user.user_role) {
                errors.push(`User role not selected`);
            }

            if (errors.length > 0) {
                showSnackbar(`Row ${index + 2}: ${errors.join('; ')}`, 'warning');
            }

            return {
                email: user.email,
                tenant_id: user.tenant_id,
                user_role: user.user_role,
                level: user.level || null,
                grade: user.grade || null,
                is_board_member: user.is_board_member || false,
            };
        }).filter(u => u.tenant_id && u.user_role);

        if (payload.length === 0) {
            showSnackbar("No valid users to create.", "error");
            return;
        }

        // Use bulk create from Microsoft endpoint
        const response = await axios.post('/api/microsoft-users/bulk-create', {
            users: payload
        });

        if (response.data.success) {
            const created = response.data.data.created.length;
            const failed = response.data.data.failed.length;

            showSnackbar(
                `Created ${created} users successfully. ${failed > 0 ? `${failed} failed.` : ''}`,
                'success'
            );

            resetForm();
        }
    } catch (error: any) {
        const msg = error.response?.data?.message || 'Upload failed';
        showSnackbar(msg, 'error');
    } finally {
        isSaving.value = false;
    }
};

const submitUser = async () => {
    // Validate form first
    if (createForm.value) {
        const { valid } = await createForm.value.validate();
        if (!valid) {
            showSnackbar("Please fix validation errors before submitting.", "warning");
            return;
        }
    }

    isSaving.value = true;

    try {
        const endpoint = auth.value.fetch_from_microsoft
            ? '/api/microsoft-users/create'
            : '/api/users';

        const response = await axios.post(endpoint, auth.value);
        showSnackbar(response.data.message || 'User created successfully', 'success', 2000);
        resetForm();
    } catch (error: any) {
        const errorMsg = error.response?.data?.message || error.response?.data?.errors
            ? Object.values(error.response.data.errors).flat().join(', ')
            : 'Error creating user';
        showSnackbar(errorMsg, 'error');
        console.error('Error creating user:', error);
    } finally {
        isSaving.value = false;
    }
}

const resetForm = () => {
    auth.value = {
        tenant_id: "",
        firstname: "",
        lastname: "",
        email: "",
        employee_number: "",
        phone: "",
        user_role: "",
        password: "",
        is_active: true,
        level: "",
        grade: "",
        is_board_member: false,
        role: "",
        fetch_from_microsoft: true
    };

    selectedRole.value = '';
    selectedTenant.value = null;
    file.value = null;
    users.value = [];
    resultQuery.value = [];
    emailInput.value = '';

    // Reset form validation
    if (createForm.value) {
        createForm.value.resetValidation();
    }
    if (importForm.value) {
        importForm.value.resetValidation();
    }
    if (emailListForm.value) {
        emailListForm.value.resetValidation();
    }
}

// Watchers
watch(file, (newFile) => {
    if (newFile) {
        parseExcelFile(newFile);
    }
})

watch(searchQuery, (newVal) => {
    searchValue.value = newVal || '';
})

const userStore = useUserStore()

watch(
    () => userStore.user,
    (user) => {
        if (!user) return;

        fetchRoles()
        fetchTenants()
    },
    { immediate: true }
)
</script>

<template>
    <!-- Loading indicator -->
    <v-container v-if="isLoading" class="d-flex justify-center">
        <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
    </v-container>

    <UiParentCard v-else title="Create Users from Microsoft">
        <VRow justify="center">
            <VCol cols="12" class="d-flex justify-center">
                <v-radio-group v-model="renewOption" inline class="d-flex justify-center">
                    <v-radio label="Create Single User" value="create" />
                    <v-radio label="Bulk Import (Email List)" value="email-list" />
                    <v-radio label="Upload Excel File" value="import" />
                </v-radio-group>
            </VCol>
        </VRow>

        <!-- Create Single User Form -->
        <v-form ref="createForm" @submit.prevent="submitUser" v-if="renewOption === 'create'">
            <v-alert type="info" variant="tonal" class="mb-4">
                <div class="d-flex align-center">
                    <div>
                        <strong>Microsoft Integration Active:</strong> User details will be automatically fetched from
                        Microsoft Active Directory when you enter their email address.
                    </div>
                </div>
            </v-alert>

            <v-row>
                <VCol cols="12" md="6">
                    <VTextField v-model="auth.email" :rules="emailRules" label="Email" placeholder="Enter email address"
                        type="email" required hide-details="auto" variant="outlined">
                        <template #append>
                            <v-btn color="primary" size="small" @click="fetchFromMicrosoft"
                                :loading="isFetchingFromMicrosoft" :disabled="!auth.email || isFetchingFromMicrosoft">
                                Fetch from Microsoft
                            </v-btn>
                        </template>
                    </VTextField>
                </VCol>

                <VCol cols="12" md="6">
                    <v-autocomplete v-model="auth.tenant_id" :items="tenants" :rules="tenantRules" label="Tenant"
                        item-title="name" item-value="id" required hide-details="auto"
                        variant="outlined"></v-autocomplete>
                </VCol>

                <VCol cols="12" md="4">
                    <VTextField v-model="auth.firstname" :rules="firstnameRules" label="Firstname"
                        placeholder="Will be fetched from Microsoft" required hide-details="auto" variant="outlined">
                    </VTextField>
                </VCol>

                <VCol cols="12" md="4">
                    <VTextField v-model="auth.lastname" :rules="lastnameRules" label="Lastname"
                        placeholder="Will be fetched from Microsoft" required hide-details="auto" variant="outlined">
                    </VTextField>
                </VCol>

                <VCol cols="12" md="4">
                    <VTextField v-model="auth.phone" label="Phone" placeholder="Will be fetched from Microsoft"
                        hide-details="auto" variant="outlined"></VTextField>
                </VCol>

                <VCol cols="12" md="4">
                    <v-select v-model="auth.user_role" :items="userRoleOptions" :rules="userRoleRules" label="User Role"
                        item-title="text" item-value="value" required hide-details="auto" variant="outlined"></v-select>
                </VCol>

                <VCol cols="12" md="4">
                    <v-autocomplete v-model="auth.role" :items="roleOptions" label="Spatie Role (Optional)"
                        item-title="text" item-value="value" hide-details="auto" variant="outlined"
                        clearable></v-autocomplete>
                </VCol>

                <VCol cols="12" md="4">
                    <VTextField v-model="auth.employee_number" label="Employee Number (Optional)"
                        placeholder="Auto-generated if not provided" hide-details="auto" variant="outlined">
                    </VTextField>
                </VCol>

                <VCol cols="12" md="3">
                    <VTextField v-model="auth.level" label="Level" placeholder="e.g., Senior" hide-details="auto"
                        variant="outlined">
                    </VTextField>
                </VCol>

                <VCol cols="12" md="3">
                    <VTextField v-model="auth.grade" label="Grade" placeholder="e.g., A" hide-details="auto"
                        variant="outlined">
                    </VTextField>
                </VCol>

                <VCol cols="12" md="3">
                    <v-switch v-model="auth.is_active" label="Is Active" color="primary" hide-details></v-switch>
                </VCol>

                <VCol cols="12" md="3">
                    <v-switch v-model="auth.is_board_member" label="Is Board Member" color="primary"
                        hide-details></v-switch>
                </VCol>
            </v-row>

            <v-row class="mt-4">
                <VCol cols="12" class="text-right">
                    <v-btn color="secondary" variant="text" @click="resetForm" class="mr-2">
                        Reset
                    </v-btn>
                    <v-btn color="primary" type="submit" :loading="isSaving" :disabled="isSaving">
                        Create User
                    </v-btn>
                </VCol>
            </v-row>
        </v-form>

        <!-- Email List Form -->
        <v-form ref="emailListForm" @submit.prevent="fetchMultipleFromMicrosoft" v-if="renewOption === 'email-list'">
            <v-alert type="info" variant="tonal" class="mb-4">
                Enter email addresses (one per line, or comma-separated). User details will be fetched from Microsoft.
            </v-alert>

            <v-row>
                <VCol cols="12">
                    <v-textarea v-model="emailInput" label="Email Addresses" placeholder="john.doe@company.com
jane.smith@company.com
or comma-separated: john@company.com, jane@company.com" rows="8" variant="outlined" hide-details="auto"></v-textarea>
                </VCol>

                <VCol cols="12" class="text-right">
                    <v-btn color="primary" @click="fetchMultipleFromMicrosoft" :loading="isFetchingFromMicrosoft"
                        :disabled="!emailInput || isFetchingFromMicrosoft" prepend-icon="$cloudDownloadOutline">
                        Fetch Users from Microsoft
                    </v-btn>
                </VCol>
            </v-row>

            <!-- Users table (same as import) -->
            <template v-if="users.length">
                <v-card variant="outlined" class="bg-surface overflow-hidden mt-4" rounded="0">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <h5 class="text-h5 mb-0">
                                Assign Tenant & Role per User
                            </h5>
                        </div>
                    </v-card-text>

                    <v-card-item class="pa-0">
                        <v-row justify="space-between" class="align-center mb-2 px-6">
                            <VCol cols="12" xl="6" md="6" sm="6">
                                <VTextField v-model="searchQuery" type="text" variant="outlined" persistent-placeholder
                                    placeholder="Search users..." hide-details clearable>
                                    <template #prepend-inner>
                                        <div class="text-lightText d-flex align-center">
                                            <v-icon>$magnify</v-icon>
                                        </div>
                                    </template>
                                </VTextField>
                            </VCol>
                        </v-row>

                        <v-divider />

                        <!-- Vuetify Table -->
                        <v-table class="bordered-table">
                            <thead class="bg-gray text-uppercase">
                                <tr class="text-secondary">
                                    <th class="text-left pa-4">User</th>
                                    <th class="text-left pa-4">Tenant</th>
                                    <th class="text-left pa-4">User Role</th>
                                    <th class="text-center pa-4" style="width: 80px;">Action</th>
                                </tr>
                            </thead>

                            <tbody class="bg-containerBg">
                                <tr v-if="!filteredUsers.length">
                                    <td colspan="4" class="text-center py-8">
                                        <v-icon size="64" color="grey-lighten-1">$accountMultipleOutline</v-icon>
                                        <h3 class="text-h6 text-grey mt-4">No users found</h3>
                                        <p class="text-grey">Enter emails above to fetch users from Microsoft.</p>
                                    </td>
                                </tr>

                                <tr v-for="(user, index) in paginatedUsers" :key="index">
                                    <td class="pa-4">
                                        <div class="d-flex align-center gap-3">
                                            <v-avatar color="primary" size="40">
                                                <span class="text-white text-h6">
                                                    {{ (user.firstname?.[0] || '') + (user.lastname?.[0] || '') }}
                                                </span>
                                            </v-avatar>
                                            <div class="mx-2">
                                                <div class="text-subtitle-1 font-weight-medium">
                                                    {{ user.firstname }} {{ user.lastname }}
                                                </div>
                                                <div class="text-lightText text-caption">
                                                    {{ user.email }}
                                                </div>
                                                <div class="text-lightText text-caption" v-if="user.phone">
                                                    {{ user.phone }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="pa-4">
                                        <v-autocomplete :model-value="users[user.originalIndex]?.tenant"
                                            @update:model-value="updateUserTenant(user.originalIndex, $event)"
                                            :items="tenants" :rules="tenantRules" label="Select Tenant"
                                            item-title="name" item-value="id" return-object density="compact"
                                            variant="outlined" hide-details style="min-width: 200px;"></v-autocomplete>
                                    </td>

                                    <td class="pa-4">
                                        <v-select :model-value="users[user.originalIndex]?.user_role"
                                            @update:model-value="updateUserRole(user.originalIndex, $event)"
                                            :items="userRoleOptions" label="Select Role" :rules="userRoleRules"
                                            item-title="text" item-value="value" density="compact" variant="outlined"
                                            hide-details style="min-width: 180px;"></v-select>
                                    </td>

                                    <td class="pa-4 text-center">
                                        <v-btn icon size="small" variant="text" color="error"
                                            @click="removeUser(user.originalIndex)">
                                            <v-icon>$close</v-icon>
                                            <v-tooltip activator="parent">Remove user</v-tooltip>
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <v-card-text v-if="filteredUsers.length > 0" class="pt-4">
                            <VRow class="align-center text-center text-sm-start" justify="space-between">
                                <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                                    <span class="text-medium-emphasis">
                                        Showing {{ ((page - 1) * rowsPerPage) + 1 }}–{{ Math.min(page * rowsPerPage,
                                            filteredUsers.length) }} of {{ filteredUsers.length }} users
                                    </span>
                                </VCol>

                                <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">
                                    <v-pagination v-model="page" :length="Math.ceil(filteredUsers.length / rowsPerPage)"
                                        :total-visible="5" rounded="circle" variant="outlined" color="primary" />
                                </VCol>
                            </VRow>
                        </v-card-text>
                    </v-card-item>
                </v-card>

                <v-row class="mt-4">
                    <VCol cols="12" class="text-right">
                        <v-btn color="secondary" variant="text" @click="resetForm" class="mr-2">
                            Reset
                        </v-btn>
                        <v-btn color="primary" @click="uploadFile" :loading="isSaving"
                            :disabled="isSaving || !users.length">
                            Create Users
                        </v-btn>
                    </VCol>
                </v-row>
            </template>
        </v-form>

        <!-- Import Excel Form (similar to email list but reads from file) -->
        <v-form ref="importForm" @submit.prevent="uploadFile" v-if="renewOption === 'import'">
            <v-alert type="info" variant="tonal" class="mb-4">
                Upload an Excel file with email addresses. User details will be fetched from Microsoft automatically.
            </v-alert>

            <v-row>
                <VCol cols="12" md="6">
                    <v-file-input ref="fileInput" v-model="file" label="Select Excel File" accept=".xlsx,.xls"
                        class="cursor-pointer" prepend-icon="" required hide-details="auto"
                        variant="outlined"></v-file-input>
                </VCol>
                <VCol cols="12" md="6" class="text-right">
                    <v-btn color="secondary" variant="text" @click="downloadTemplate" :disabled="!canDownloadTemplate"
                        prepend-icon="$trayArrowDown">
                        Download Template
                        <v-tooltip activator="parent" v-if="!canDownloadTemplate">
                            Loading tenants...
                        </v-tooltip>
                    </v-btn>
                </VCol>
            </v-row>

            <!-- Same table as email list -->
            <template v-if="users.length">
                <!-- (Reuse the same table component from email-list) -->
                <v-card variant="outlined" class="bg-surface overflow-hidden mt-4" rounded="0">
                    <!-- Same content as above -->
                </v-card>
            </template>
        </v-form>
    </UiParentCard>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" top right>
        {{ snackbar.message }}
        <template v-slot:action="{ attrs }">
            <v-btn text v-bind="attrs" @click="snackbar.show = false">
                Close
            </v-btn>
        </template>
    </v-snackbar>
</template>

<style scoped>
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