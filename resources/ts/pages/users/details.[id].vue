<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import axios from "axios"

import { useRoute, useRouter } from 'vue-router'
import moment from 'moment'

const route = useRoute()
const router = useRouter()
// Breadcrumb

const breadcrumbs = ref([
    { title: 'Users', disabled: false, href: '/users' },
    { title: 'Account', disabled: false, href: '#' },
])

// Tab state
const tab = ref('tab-profile')

// User data from backend
const user = ref<any>(null)
const loading = ref(true)
const saving = ref(false)
const downloadingFiles = ref({})

const downloadFile = (document) => {
    if (document.file_path) {
        window.open(document.file_path, '_blank')
    }
}

// Form data
const profileForm = ref({
    title: '',
    firstname: '',
    middlename: '',
    lastname: '',
    email: '',
    phone: '',
    linkedin_url: '',
    bio: '',
    country: '',
    district: '',
    village: '',
    physical_address: '',
    postal_address: ''
})

const passwordForm = ref({
    current_password: '',
    password: '',
    password_confirmation: ''
})

const settingsForm = ref({
    language: 'English',
    signing_method: 'Email'
})

// Toggle states
const secureMode = ref(true)
const loginNotifications = ref(true)
const loginApprovals = ref(false)
const showCurrentPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

// Static options
const experience = ref(['Start up', '6 months', '1 year', '2 years', '3 years', '4 years', '5 years', '6 years', '10+ years'])
const signingOptions = ref(['Email', 'Firebase - Auth', 'Facebook', 'Twitter', 'Gmail', 'JWT', 'AUTH0'])

/* ---------- FORM FIELDS ---------- */
const cpassword = ref('')
const npassword = ref('')
const conpassword = ref('')

const show1 = ref(false)
const show2 = ref(false)
const show3 = ref(false)

const snackbar = reactive({
    show: false,
    message: '',
    color: 'success'
})

/* ---------- PASSWORD RULES (dynamic) ---------- */
interface Rule {
    title: string
    valid: boolean
    test: (pwd: string) => boolean
}

const baseRules = shallowRef<Rule[]>([
    {
        title: 'At least 12 characters',
        valid: false,
        test: (p) => p.length >= 12,
    },
    {
        title: 'At least 1 lower letter (a-z)',
        valid: false,
        test: (p) => /[a-z]/.test(p),
    },
    {
        title: 'At least 1 uppercase letter (A-Z)',
        valid: false,
        test: (p) => /[A-Z]/.test(p),
    },
    {
        title: 'At least 1 special character',
        valid: false,
        test: (p) => /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>/?]/.test(p),
    },
    // ← NEW RULE HERE
    {
        title: 'Passwords should match',
        valid: false,
        // This one doesn't depend only on the new password, so we'll override it in the computed
        test: () => true, // placeholder, will be overridden
    },
])

const passwordRules = computed(() => {
    return baseRules.value.map((r, index) => {
        if (index === baseRules.value.length - 1) {
            // "Passwords match" rule
            return {
                ...r,
                title: 'Passwords should match',
                valid: conpassword.value.length > 0 ? passwordsMatch.value : false,
            }
        }
        // All other rules
        return {
            ...r,
            valid: r.test(npassword.value),
        }
    })
})

/* ---------- CONFIRM PASSWORD MATCH ---------- */
const passwordsMatch = computed(() => npassword.value === conpassword.value)

/* ---------- FORM SUBMISSION ---------- */

const showSnackbar = (message: string, color: string = 'success') => {
    snackbar.message = message
    snackbar.color = color
    snackbar.show = true
}

async function changePassword() {
    if (!passwordsMatch.value) {
        showSnackbar('Passwords do not match', 'error')
        return
    }
    if (passwordRules.value.some(r => !r.valid)) {
        showSnackbar('Password does not meet all requirements', 'error')
        return
    }

    loading.value = true
    try {
        await axios.post(`/user/change-password/${route.params.id}`, {
            current_password: cpassword.value,
            password: npassword.value,
            password_confirmation: conpassword.value,
        })

        showSnackbar('Password changed successfully', 'success')
        resetForm()
        // optional: reset form
        cpassword.value = ''
        npassword.value = ''
        conpassword.value = ''
    } catch (err: any) {
        const msg = err.response?.data?.message || 'Failed to change password'
        showSnackbar(msg, 'error')
    } finally {
        loading.value = false
    }
}

const resetForm = () => {
    cpassword.value = ''
    npassword.value = ''
    conpassword.value = ''
}
// Computed values
const fullName = computed(() => {
    if (!user.value) return ''
    return `${user.value.firstname} ${user.value.lastname}`
})

const avatarUrl = computed(() => {
    return user.value?.avatar || ''
})

const userStats = computed(() => user.value?.stats || [])

const titleOptions = computed(() => [
    { text: 'Mr', value: 'Mr' },
    { text: 'Mrs', value: 'Mrs' },
    { text: 'Miss', value: 'Miss' },
    { text: 'Dr', value: 'Dr' },
    { text: 'Hon', value: 'Hon' }
])
const contactInfo = computed(() => [
    { text: user.value?.email || '', icon: 'custom-mail-outline', color: 'dark', showTooltip: true },
    { text: user.value?.phone || 'Not provided', icon: 'custom-phone-outline-1', color: 'dark' },
    { text: user.value?.profile?.district || 'Not specified', icon: 'custom-goal-outline', color: 'dark' },
    { text: user.value?.linkedin_url || 'Not provided', icon: 'custom-link1', color: 'primary link-hover', showTooltip: true },
    { text: user.value?.profile?.dob ? moment(user.value?.profile?.dob).format('DD MMMM YYYY') : 'Not provided', icon: 'custom-calendar-outline', color: 'dark' },
    { text: user.value?.profile?.gender || 'Not specified', icon: 'custom-profile-2user-outline', color: 'dark' },
    { text: user.value?.profile?.nationality || 'Not specified', icon: 'custom-airplane', color: 'dark' },
    { text: user.value?.profile?.physical_address || 'Not provided', icon: 'custom-navigation-outline', color: 'dark', showTooltip: true }
])

const educationHistory = computed(() => {
    return user.value?.profile?.education || []
})

const experienceHistory = computed(() => {
    return user.value?.profile?.experience || []
})
const certificationHistory = computed(() => {
    return user.value?.profile?.certification || []
})
const refereeHistory = computed(() => {
    return user.value?.profile?.referee || []
})
const documentHistory = computed(() => {
    return user.value?.profile?.documents || []
})

const skills = computed(() => {
    if (!user.value?.profile?.skills) return []
    const skillsArray = typeof user.value.profile.skills === 'string'
        ? user.value.profile.skills.split(',')
        : user.value.profile.skills

    return skillsArray.map((skill: string, index: number) => ({
        title: skill.trim(),
        value: 70 + (index * 5) % 30,
        color: 'primary'
    }))
})

// Password requirements
const passwordRequirements = ref([
    { title: 'At least 8 characters' },
    { title: 'At least 1 lower letter (a-z)' },
    { title: 'At least 1 uppercase letter (A-Z)' },
    { title: 'At least 1 number (0-9)' },
    { title: 'At least 1 special character' }
])

// API calls
const fetchUserData = async () => {
    loading.value = true
    try {
        const response = await axios.get(`/user/${route.params.id}`)
        user.value = response.data

        // Populate form with user data
        profileForm.value = {
            title: user.value.title || '',
            firstname: user.value.firstname || '',
            middlename: user.value.middlename || '',
            lastname: user.value.lastname || '',
            email: user.value.email || '',
            phone: user.value.phone || '',
            linkedin_url: user.value.linkedin_url || '',
            bio: user.value.profile?.bio || '',
            country: user.value.profile?.nationality || '',
            district: user.value.profile?.district || '',
            village: user.value.profile?.village || '',
            physical_address: user.value.profile?.physical_address || '',
            postal_address: user.value.profile?.postal_address || ''
        }
    } catch (error) {
        console.error('Error fetching user data:', error)
    } finally {
        loading.value = false
    }
}
const updateProfile = async () => {
    // Optional: prevent updating someone else's profile
    if (route.params.id && route.params.id != user.value?.id) {
        showSnackbar('You do not have permission to update this profile.', 'error');
        return;
    }

    saving.value = true;

    try {
        const payload = { ...profileForm.value };

        await axios.put(`/api/user/${route.params.id}`, payload);

        showSnackbar('Profile updated successfully', 'success');

        // Refresh user data
        await fetchUserData();
    } catch (error: any) {
        const msg = error.response?.data?.message || 'Failed to update profile';
        showSnackbar(msg, 'error');
    } finally {
        saving.value = false;
    }
};


const uploadAvatar = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('avatar', file);

    try {
        await axios.post(`/user/avatar/${route.params.id}`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        });

        await fetchUserData();
        showSnackbar('Avatar updated successfully', 'success');
    } catch (error: any) {
        const msg = error.response?.data?.message || 'Failed to upload avatar';
        showSnackbar(msg, 'error');
    }
};

const formatDate = (date: string | null) => {
    return date ? moment(date).format('DD MMM YYYY') : '';
}

onMounted(() => {
    fetchUserData()
})
</script>

<template>
    <BaseBreadcrumb :breadcrumbs="breadcrumbs" />

    <div v-if="loading" class="text-center py-10">
        <VProgressCircular indeterminate color="primary" />
    </div>

    <VRow v-else>
        <VCol cols="12">
            <VCard variant="flat" rounded="lg">
                <VCard variant="outlined" rounded="lg">
                    <VCardText class="px-0 pb-0">
                        <VTabs v-model="tab" color="primary" class="mx-6" grow>
                            <VTab value="tab-profile">
                                <SvgSprite name="custom-user-outline" class="v-icon--start"
                                    style="width: 18px; height: 18px" />
                                Profile
                            </VTab>
                            <VTab value="tab-personal">
                                <SvgSprite name="custom-document-text-outline" class="v-icon--start"
                                    style="width: 18px; height: 18px" />
                                Personal
                            </VTab>
                            <VTab value="tab-password">
                                <SvgSprite name="custom-lock-2" class="v-icon--start"
                                    style="width: 18px; height: 18px" />
                                Change Password
                            </VTab>
                        </VTabs>
                        <VDivider class="mx-6" />

                        <VTabsWindow v-model="tab" class="px-6 pb-6 pt-6">
                            <!-- Profile Tab -->
                            <VTabsWindowItem value="tab-profile">
                                <VRow>
                                    <VCol cols="12" xl="3" md="4">
                                        <VCard variant="outlined" rounded="lg">
                                            <VCardText>
                                                <div class="text-center pb-4">
                                                    <div class="text-end text-capitalize">
                                                        <VChip color="error" size="small" rounded="sm" class="mt-2"
                                                            variant="tonal">
                                                            {{ user?.user_type || 'User' }}
                                                        </VChip>
                                                    </div>
                                                    <VAvatar v-if="avatarUrl" size="64" color="lightprimary">
                                                        <img :src="avatarUrl" :alt="fullName" width="64"
                                                            class="v-avatar">
                                                    </VAvatar>
                                                    <VAvatar v-else variant="tonal" size="64" color="primary"
                                                        class="me-2">
                                                        <SvgSprite name="custom-user-fill"
                                                            style="width: 30px; height: 30px" />
                                                    </VAvatar>
                                                    <h5 class="text-h5 mt-4 mb-1">{{ fullName }}</h5>
                                                    <span class="text-h6 text-lightText">{{ user?.role || 'User'
                                                        }}</span>
                                                </div>
                                                <VDivider />
                                                <div class="my-4 d-flex align-center ga-2">
                                                    <div v-for="(stat, i) in userStats" :key="i"
                                                        class="text-center w-100">
                                                        <h5 class="text-h5 mb-1">{{ stat.value }}</h5>
                                                        <span class="text-h6 text-lightText">{{ stat.label }}</span>
                                                    </div>
                                                </div>

                                                <VDivider />
                                                <VList density="compact" class="pb-0">
                                                    <VListItem v-for="(item, i) in contactInfo" :key="i"
                                                        class="py-0 px-0">
                                                        <template #prepend>
                                                            <SvgSprite :name="item.icon" class="text-lightText me-2"
                                                                style="width: 18px; height: 18px" />
                                                        </template>
                                                        <VListItemTitle :class="`text-h6 text-end text-${item.color}`">
                                                            {{ item.text }}
                                                            <VTooltip v-if="item.showTooltip" activator="parent"
                                                                location="top">
                                                                {{ item.text }}
                                                            </VTooltip>
                                                        </VListItemTitle>
                                                    </VListItem>
                                                </VList>
                                            </VCardText>
                                        </VCard>

                                        <VCard variant="outlined" class="mt-6" rounded="lg" v-if="skills.length > 0">
                                            <VCardText>
                                                <h5 class="text-subtitle-1 mb-0">Skills</h5>
                                            </VCardText>
                                            <VDivider />
                                            <VCardItem>
                                                <VList class="py-0" density="compact">
                                                    <VListItem v-for="(skill, i) in skills" :key="i" class="pa-0">
                                                        <div class="d-flex justify-space-between align-center">
                                                            <h5 class="text-h6 mb-0 text-lightText">{{ skill.title }}
                                                            </h5>
                                                            <div class="d-flex align-center">
                                                                <VProgressLinear :bg-color="skill.color" height="6"
                                                                    bg-opacity="0.4" rounded :model-value="skill.value"
                                                                    :color="skill.color"
                                                                    style="min-width: 64px; width: 64px" />
                                                                <span class="text-caption ms-2 text-lightText">{{
                                                                    skill.value }}%</span>
                                                            </div>
                                                        </div>
                                                    </VListItem>
                                                </VList>
                                            </VCardItem>
                                        </VCard>
                                        <VCard variant="outlined" class="mt-6" rounded="lg">
                                            <VCardText>
                                                <h5 class="text-subtitle-1 mb-0">Documents</h5>
                                            </VCardText>
                                            <VDivider />
                                            <VCardItem>
                                                <VList class="py-0" density="compact">
                                                    <VListItem v-if="documentHistory.length > 0"
                                                        v-for="(doc, i) in documentHistory" :key="i" class="pa-0">
                                                        <div class="d-flex justify-space-between align-center">
                                                            <h5 class="text-h6 mb-0 text-lightText">{{ doc.name }}
                                                            </h5>
                                                            <div class="d-flex align-center mt-1">
                                                                <VChip color="primary" size="small" class="mr-2">
                                                                    <VIcon start icon="$fileDocument" />
                                                                    {{ doc.category }}
                                                                </VChip>

                                                                <VBtn variant="text" size="small" color="primary"
                                                                    @click="downloadFile(doc)"
                                                                    :loading="downloadingFiles[doc.id]">
                                                                    <VIcon start icon="$trayArrowDown" />
                                                                    Download
                                                                </VBtn>
                                                            </div>
                                                        </div>
                                                    </VListItem>

                                                    <VListItem v-else class="pa-0">
                                                        <VEmptyState class="w-100 py-1 d-flex justify-center">
                                                            <template #media>
                                                                <VIcon color="secondary" size="25">$linkVariant</VIcon>
                                                            </template>

                                                            <template #headline>
                                                                <div class="text-h6 text-secondary">No Documents
                                                                    Uploaded
                                                                </div>
                                                            </template>
                                                        </VEmptyState>
                                                    </VListItem>

                                                </VList>
                                            </VCardItem>
                                        </VCard>
                                    </VCol>

                                    <VCol cols="12" xl="9" md="8">
                                        <VCard variant="outlined" rounded="lg">
                                            <VList>
                                                <VListItem>
                                                    <template #title>
                                                        <h5 class="text-subtitle-1 mb-0">About Me</h5>
                                                    </template>
                                                </VListItem>
                                            </VList>
                                            <VDivider />
                                            <VCardText>
                                                <p class="text-lightText mb-0">
                                                    {{ user?.profile?.bio || 'No bio available.' }}
                                                </p>
                                            </VCardText>
                                        </VCard>

                                        <VCard variant="outlined" class="mt-6" rounded="lg"
                                            v-if="educationHistory.length > 0">
                                            <div class="px-5 py-6">
                                                <h5 class="text-subtitle-1 mb-0">Education</h5>
                                            </div>
                                            <VDivider />
                                            <VCardText>
                                                <VRow v-for="(edu, i) in educationHistory" :key="i" class="pt-3">
                                                    <VCol md="6">
                                                        <span class="text-h6">{{ edu.institution }}</span>
                                                        <h4 class="text-h6 text-lightText mb-0">{{ edu.qualification }}
                                                        </h4>
                                                    </VCol>
                                                    <VCol md="6">
                                                        <h4 class="text-h6  mb-0">{{ formatDate(
                                                            edu.start_date) }} -
                                                            {{ formatDate(edu.end_date) ||
                                                                formatDate(edu.graduation_date) || 'Not specified' }}
                                                        </h4>
                                                        <span class="text-h6 text-lightText">{{ edu.grade }}</span>
                                                    </VCol>
                                                </VRow>
                                            </VCardText>
                                        </VCard>

                                        <VCard variant="outlined" class="mt-6" rounded="lg"
                                            v-if="experienceHistory.length > 0">
                                            <div class="px-5 py-6">
                                                <h5 class="text-subtitle-1 mb-0">Work Experience</h5>
                                            </div>
                                            <VDivider />
                                            <VCardText>
                                                <VRow v-for="(exp, i) in experienceHistory" :key="i" class="pt-3">
                                                    <VCol md="4">
                                                        <h4 class="text-h6 mb-0">{{ exp.position }}</h4>
                                                        <span class="text-h6 text-lightText">{{ exp.company }}</span>
                                                    </VCol>
                                                    <VCol md="8">
                                                        <h4 class="text-h6 mb-0">{{
                                                            formatDate(exp.start_date) }} - {{
                                                                exp.is_current_job ? 'Present' : formatDate(exp.end_date)
                                                            }}</h4>
                                                        <span class="text-h6">{{ exp.responsibilities }}</span>
                                                    </VCol>
                                                </VRow>
                                            </VCardText>
                                        </VCard>
                                        <VCard variant="outlined" class="mt-6" rounded="lg">
                                            <div class="px-5 py-6">
                                                <h5 class="text-subtitle-1 mb-0">Certifications</h5>
                                            </div>
                                            <VDivider />
                                            <VCardText>
                                                <VRow v-for="(cert, i) in certificationHistory" :key="i" class="pt-3">
                                                    <VCol md="4">
                                                        <h4 class="text-h6  mb-0">{{ cert.certification }}
                                                        </h4>
                                                        <span class="text-h6 text-lightText">{{
                                                            cert.issuing_organization }}</span>
                                                    </VCol>
                                                    <VCol md="8">
                                                        <h4 class="text-h6 mb-0">{{
                                                            formatDate(cert.start_date) }} - {{
                                                                cert.does_not_expire ? 'Does Not Expire' :
                                                                    formatDate(cert.end_date)
                                                            }}</h4>
                                                    </VCol>
                                                </VRow>
                                            </VCardText>
                                        </VCard>
                                        <VCard variant="outlined" class="mt-6" rounded="lg">
                                            <div class="px-5 py-6">
                                                <h5 class="text-subtitle-1 mb-0">Referee Details</h5>
                                            </div>
                                            <VDivider />
                                            <VCardText>
                                                <VRow v-for="(ref, i) in refereeHistory" :key="i" class="pt-3">
                                                    <VCol md="4">
                                                        <h4 class="text-h6 mb-0">{{ ref.name }}
                                                        </h4>
                                                        <span class="text-h6 text-lightText">{{ ref.relationship
                                                            }}</span>
                                                    </VCol>
                                                    <VCol md="8">
                                                        <h4 class="text-h6  mb-0">{{
                                                            ref.company }} ({{ ref.position }}) </h4>
                                                        <span class="text-h6 text-lightText">{{ ref.email }}</span>

                                                    </VCol>
                                                </VRow>
                                            </VCardText>
                                        </VCard>
                                    </VCol>
                                </VRow>
                            </VTabsWindowItem>
                            <!-- Personal Details Tab -->
                            <VTabsWindowItem value="tab-personal">
                                <VRow>
                                    <VCol cols="12" md="6">
                                        <VCard variant="outlined" rounded="lg">
                                            <div class="pa-5">
                                                <h5 class="text-subtitle-1 mb-0">Personal Information</h5>
                                            </div>
                                            <VDivider />
                                            <VCardText>
                                                <VRow>
                                                    <VCol cols="12" class="text-center">
                                                        <label
                                                            style="cursor: pointer; display: inline-block; position: relative;">
                                                            <VAvatar v-if="avatarUrl" size="80" variant="flat"
                                                                color="lightprimary">
                                                                <img :src="avatarUrl" width="80" alt="profile">
                                                            </VAvatar>
                                                            <VAvatar v-else variant="tonal" size="80" color="primary"
                                                                class="me-2">
                                                                <SvgSprite name="custom-user-fill"
                                                                    style="width: 40px; height: 40px" />
                                                            </VAvatar>
                                                            <input type="file" class="preview-upload"
                                                                @change="uploadAvatar" accept="image/*"
                                                                style="position: absolute; opacity: 0; width: 100%; height: 100%; top: 0; left: 0; cursor: pointer;">
                                                        </label>
                                                    </VCol>

                                                    <VCol cols="12" lg="6">
                                                        <VLabel class="mb-2">Title</VLabel>
                                                        <VSelect :items="titleOptions" v-model="profileForm.title"
                                                            label="Select Title" item-title="text" item-value="value"
                                                            density="comfortable" required hide-details="auto"
                                                            variant="outlined">
                                                        </VSelect>
                                                    </VCol>
                                                    <VCol cols="12" lg="6">
                                                        <VLabel class="mb-2">First Name</VLabel>
                                                        <VTextField v-model="profileForm.firstname" color="primary"
                                                            variant="outlined" density="comfortable" hide-details />
                                                    </VCol>
                                                    <VCol cols="12" lg="6">
                                                        <VLabel class="mb-2">Middle Name</VLabel>
                                                        <VTextField v-model="profileForm.middlename" color="primary"
                                                            variant="outlined" density="comfortable" hide-details />
                                                    </VCol>
                                                    <VCol cols="12" lg="6">
                                                        <VLabel class="mb-2">Last Name</VLabel>
                                                        <VTextField v-model="profileForm.lastname" color="primary"
                                                            variant="outlined" density="comfortable" hide-details />
                                                    </VCol>

                                                </VRow>
                                            </VCardText>
                                        </VCard>
                                    </VCol>

                                    <VCol cols="12" md="6">
                                        <VCard variant="outlined" rounded="lg">
                                            <div class="pa-5">
                                                <h5 class="text-subtitle-1 mb-0">Contact Information</h5>
                                            </div>
                                            <VDivider />
                                            <VCardText>
                                                <VRow>
                                                    <VCol cols="12">
                                                        <VLabel class="mb-2">Email Address</VLabel>
                                                        <VTextField v-model="profileForm.email" color="primary"
                                                            variant="outlined" density="comfortable" type="email"
                                                            hide-details />
                                                    </VCol>
                                                    <VCol cols="12">
                                                        <VLabel class="mb-2">Phone Number</VLabel>
                                                        <VTextField v-model="profileForm.phone" color="primary"
                                                            variant="outlined" density="comfortable" hide-details />
                                                    </VCol>
                                                    <VCol cols="12">
                                                        <VLabel class="mb-2">LinkedIn URL</VLabel>
                                                        <VTextField v-model="profileForm.linkedin_url" color="primary"
                                                            variant="outlined" density="comfortable" hide-details />
                                                    </VCol>

                                                </VRow>
                                            </VCardText>
                                        </VCard>
                                    </VCol>

                                    <VCol cols="12" class="text-end">
                                        <VBtn color="primary" rounded="md" variant="flat" @click="updateProfile"
                                            :loading="saving">
                                            Update Profile
                                        </VBtn>
                                    </VCol>
                                </VRow>
                            </VTabsWindowItem>
                            <!-- Chnage Password Tab -->
                            <VTabsWindowItem value="tab-password">
                                <VRow>
                                    <VCol cols="12">
                                        <VCard variant="flat" rounded="lg">
                                            <VCard variant="outlined" rounded="lg">
                                                <div class="pa-5">
                                                    <h5 class="text-subtitle-1 mb-0">
                                                        Change Password
                                                    </h5>
                                                </div>
                                                <VDivider />
                                                <VCardText>
                                                    <VRow>
                                                        <VCol cols="12" md="6">
                                                            <VRow>
                                                                <VCol cols="12">
                                                                    <VLabel class="mb-2">
                                                                        Old Password
                                                                    </VLabel>
                                                                    <VTextField v-model="cpassword" color="primary"
                                                                        single-line placeholder="Enter Old Password"
                                                                        variant="outlined" density="comfortable"
                                                                        :type="show1 ? 'text' : 'password'"
                                                                        hide-details>
                                                                        <template #append-inner>
                                                                            <SvgSprite v-if="show1 === false"
                                                                                name="custom-eye-invisible"
                                                                                class="text-secondary"
                                                                                style="width: 20px; height: 20px"
                                                                                @click="show1 = !show1" />
                                                                            <SvgSprite v-if="show1 === true"
                                                                                name="custom-eye" class="text-secondary"
                                                                                style="width: 20px; height: 20px"
                                                                                @click="show1 = !show1" />
                                                                        </template>
                                                                    </VTextField>
                                                                </VCol>
                                                                <VCol cols="12">
                                                                    <VLabel class="mb-2">
                                                                        New Password
                                                                    </VLabel>
                                                                    <VTextField v-model="npassword" color="primary"
                                                                        single-line placeholder="Enter New Password"
                                                                        variant="outlined" density="comfortable"
                                                                        :type="show2 ? 'text' : 'password'"
                                                                        hide-details>
                                                                        <template #append-inner>
                                                                            <SvgSprite v-if="show2 === false"
                                                                                name="custom-eye-invisible"
                                                                                class="text-secondary"
                                                                                style="width: 20px; height: 20px"
                                                                                @click="show2 = !show2" />
                                                                            <SvgSprite v-if="show2 === true"
                                                                                name="custom-eye" class="text-secondary"
                                                                                style="width: 20px; height: 20px"
                                                                                @click="show2 = !show2" />
                                                                        </template>
                                                                    </VTextField>
                                                                </VCol>
                                                                <VCol cols="12">
                                                                    <VLabel class="mb-2">
                                                                        Confirm Password
                                                                    </VLabel>
                                                                    <VTextField v-model="conpassword" color="primary"
                                                                        single-line placeholder="Enter Confirm Password"
                                                                        variant="outlined" density="comfortable"
                                                                        :type="show3 ? 'text' : 'password'"
                                                                        hide-details>
                                                                        <template #append-inner>
                                                                            <SvgSprite v-if="show3 === false"
                                                                                name="custom-eye-invisible"
                                                                                class="text-secondary"
                                                                                style="width: 20px; height: 20px"
                                                                                @click="show3 = !show3" />
                                                                            <SvgSprite v-if="show3 === true"
                                                                                name="custom-eye" class="text-secondary"
                                                                                style="width: 20px; height: 20px"
                                                                                @click="show3 = !show3" />
                                                                        </template>
                                                                    </VTextField>
                                                                </VCol>
                                                            </VRow>
                                                        </VCol>
                                                        <VCol cols="12" md="6">
                                                            <h5 class="text-h5">New Password must contain:</h5>

                                                            <VList aria-label="content">
                                                                <VListItem v-for="(rule, i) in passwordRules" :key="i"
                                                                    border
                                                                    :class="{ 'text-success': rule.valid, 'text-error': !rule.valid }">
                                                                    <template #prepend>
                                                                        <SvgSprite
                                                                            :name="rule.valid ? 'custom-check-circle-fill' : 'custom-line'"
                                                                            class="me-2"
                                                                            style="width:24px;height:24px" />
                                                                    </template>

                                                                    <h6 class="text-h6 mb-0">
                                                                        {{ rule.title }}
                                                                    </h6>
                                                                </VListItem>
                                                            </VList>
                                                        </VCol>
                                                    </VRow>
                                                    <div class="text-end mt-4">
                                                        <VBtn color="secondary" variant="outlined" rounded="md"
                                                            class="me-2" @click="resetForm">
                                                            Cancel
                                                        </VBtn>

                                                        <VBtn color="primary" rounded="md" variant="flat"
                                                            :loading="loading" @click="changePassword">
                                                            Save
                                                        </VBtn>
                                                    </div>
                                                </VCardText>
                                            </VCard>
                                        </VCard>
                                    </VCol>
                                </VRow>
                            </VTabsWindowItem>


                        </VTabsWindow>
                    </VCardText>
                </VCard>
            </VCard>
        </VCol>
    </VRow>

    <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000" location="top right">
        {{ snackbar.message }}
        <template #actions>
            <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
        </template>
    </VSnackbar>
</template>

<style scoped>
.preview-upload {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.link-hover {
    cursor: pointer;
}

.cursor-pointer {
    cursor: pointer;
}
</style>