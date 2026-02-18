<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useDropzone } from 'vue3-dropzone'
import axios from "axios"

import Swal from 'sweetalert2'

const props = defineProps<{
    shareholder: any
}>()

const emit = defineEmits<{
    (e: 'profile-updated'): void
}>()

const personalForm = ref()
const saving = ref(false)
const avatarFile = ref<any>(null)

// Form data
const form = reactive({
    // User fields
    first_name: '',
    last_name: '',
    email: '',
    phone: '',

    // Shareholder fields
    gender: '',
    date_of_birth: '',
    national_id: '',
    passport_number: '',
    passport_expiry_date: '',
    marital_status: '',
    occupation: '',
    education_level: '',

    // Address
    village: '',
    traditional_authority: '',
    district: '',
    city_town: '',
    postal_zip_code: '',
    country: 'Malawi',
    physical_address: '',
    postal_address: '',

    // Next of kin
    next_of_kin_name: '',
    next_of_kin_phone: '',
    next_of_kin_relationship: ''
})

// Snackbar
const snackbar = reactive({
    show: false,
    message: '',
    color: 'success'
})

// Validation rules
const rules = {
    required: (v: any) => !!v || 'This field is required',
    email: (v: string) => {
        const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
        return !v || pattern.test(v) || 'Invalid email format'
    },
    phone: (v: string) => {
        return !v || /^[0-9+\-() ]+$/.test(v) || 'Invalid phone format'
    }
}

// Options
const genderOptions = ['Male', 'Female', 'Other']
const maritalStatusOptions = ['Single', 'Married', 'Divorced', 'Widowed']
const educationLevels = ['Primary', 'Secondary', 'Post Secondary', 'Graduate', 'Post Graduate']

// Computed
const avatarPreview = computed(() => {
    if (avatarFile.value?.preview) return avatarFile.value.preview
    if (props.shareholder?.user?.avatar) return props.shareholder.user.avatar
    return null
})

// Load shareholder data into form
const loadShareholderData = () => {
    if (props.shareholder) {
        // User fields
        form.first_name = props.shareholder.user?.first_name || ''
        form.last_name = props.shareholder.user?.last_name || ''
        form.email = props.shareholder.user?.email || ''
        form.phone = props.shareholder.user?.phone || ''

        // Shareholder fields
        form.gender = props.shareholder.gender || ''
        form.date_of_birth = props.shareholder.date_of_birth || ''
        form.national_id = props.shareholder.national_id || ''
        form.passport_number = props.shareholder.passport_number || ''
        form.passport_expiry_date = props.shareholder.passport_expiry_date || ''
        form.marital_status = props.shareholder.marital_status || ''
        form.occupation = props.shareholder.occupation || ''
        form.education_level = props.shareholder.education_level || ''

        // Address
        form.village = props.shareholder.village || ''
        form.traditional_authority = props.shareholder.traditional_authority || ''
        form.district = props.shareholder.district || ''
        form.city_town = props.shareholder.city_town || ''
        form.postal_zip_code = props.shareholder.postal_zip_code || ''
        form.country = props.shareholder.country || 'Malawi'
        form.physical_address = props.shareholder.physical_address || ''
        form.postal_address = props.shareholder.postal_address || ''

        // Next of kin
        form.next_of_kin_name = props.shareholder.next_of_kin_name || ''
        form.next_of_kin_phone = props.shareholder.next_of_kin_phone || ''
        form.next_of_kin_relationship = props.shareholder.next_of_kin_relationship || ''
    }
}

// Avatar handling
const createFileObject = (file: File) => ({
    file,
    preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
})

const { getRootProps: getAvatarRootProps, getInputProps: getAvatarInputProps } = useDropzone({
    accept: 'image/png, image/jpeg, image/jpg',
    onDrop: (acceptedFiles: File[]) => {
        if (acceptedFiles.length > 0) {
            avatarFile.value = createFileObject(acceptedFiles[0])
        }
    },
})

const avatarRootProps = getAvatarRootProps()
const avatarInputProps = getAvatarInputProps({ multiple: false })

const removeAvatar = () => {
    if (avatarFile.value?.preview) {
        URL.revokeObjectURL(avatarFile.value.preview)
    }
    avatarFile.value = null
}

const uploadAvatar = async () => {
    if (!avatarFile.value?.file) return

    try {
        const formData = new FormData()
        formData.append('avatar', avatarFile.value.file)

        await axios.post(`/api/shareholders/${props.shareholder.id}/avatar`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        showSnackbar('Avatar updated successfully', 'success')
        removeAvatar()
        emit('profile-updated')
    } catch (error: any) {
        showSnackbar(error.response?.data?.message || 'Failed to upload avatar', 'error')
    }
}

// Save personal info
const savePersonalInfo = async () => {
    const { valid } = await personalForm.value.validate()
    if (!valid) return

    saving.value = true
    try {
        await axios.put(`/api/shareholders/${props.shareholder.id}`, form)
        showSnackbar('Personal information updated successfully', 'success')
        emit('profile-updated')
    } catch (error: any) {
        showSnackbar(error.response?.data?.message || 'Failed to update information', 'error')
    } finally {
        saving.value = false
    }
}

const showSnackbar = (message: string, color: string = 'success') => {
    snackbar.message = message
    snackbar.color = color
    snackbar.show = true
}

// Lifecycle
onMounted(() => {
    loadShareholderData()
})

// Watch for prop changes
watch(() => props.shareholder, () => {
    loadShareholderData()
}, { deep: true })
</script>

<template>
    <div>
        <!-- Basic Information -->
        <div class="px-5 py-6">
            <h5 class="text-h5 mb-0">Basic Information</h5>
        </div>

        <VRow>
            <!-- Avatar Section -->
            <VCol cols="12" md="4" class="d-flex justify-center justify-md-start">
                <div v-bind="avatarRootProps" class="d-flex flex-column align-center">
                    <input v-bind="avatarInputProps" />
                    <VLabel class="mb-2 ms-1">Profile Picture</VLabel>
                    <div class="d-flex flex-column align-center justify-center border-dashed border-primary border-thin rounded-lg overflow-hidden bg-lightsecondary"
                        style="width: 250px; height: 250px; cursor: pointer;">
                        <img v-if="avatarPreview" :src="avatarPreview" alt="Profile Picture"
                            class="w-100 h-100 object-cover" />
                        <div v-else class="text-center px-4">
                            <VIcon icon="$cloudUploadOutline" size="55" class="text-secondary" />
                            <p class="text-body1 font-weight-medium">Browse photo or drop here</p>
                            <p class="text-caption mt-1 text-medium-emphasis">
                                A photo larger than 400px works best<br />
                                Max size 5MB<br />
                                Allowed: .png, .jpg, .jpeg
                            </p>
                        </div>
                    </div>

                    <div class="d-flex ga-2 mt-3" v-if="avatarFile">
                        <VBtn color="error" variant="text" @click="removeAvatar" :loading="saving">
                            Reset
                        </VBtn>
                        <VBtn color="primary" variant="text" @click="uploadAvatar" :loading="saving">
                            Save Avatar
                        </VBtn>
                    </div>
                </div>
            </VCol>

            <!-- Form Fields -->
            <VCol cols="12" md="8">
                <VForm ref="personalForm" @submit.prevent="savePersonalInfo">
                    <VRow>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">First Name *</VLabel>
                            <VTextField v-model="form.first_name" placeholder="Enter first name" density="comfortable"
                                variant="outlined" :rules="[rules.required]" />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Last Name *</VLabel>
                            <VTextField v-model="form.last_name" placeholder="Enter last name" density="comfortable"
                                variant="outlined" :rules="[rules.required]" />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Email *</VLabel>
                            <VTextField v-model="form.email" type="email" placeholder="Enter email"
                                density="comfortable" variant="outlined" :rules="[rules.required, rules.email]" />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Phone *</VLabel>
                            <VTextField v-model="form.phone" placeholder="Enter phone number" density="comfortable"
                                variant="outlined" :rules="[rules.required, rules.phone]" />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Gender</VLabel>
                            <VSelect v-model="form.gender" :items="genderOptions" placeholder="Select gender"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Date of Birth</VLabel>
                            <VTextField v-model="form.date_of_birth" type="date" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">National ID</VLabel>
                            <VTextField v-model="form.national_id" placeholder="Enter national ID" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Marital Status</VLabel>
                            <VSelect v-model="form.marital_status" :items="maritalStatusOptions"
                                placeholder="Select status" density="comfortable" variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Occupation</VLabel>
                            <VTextField v-model="form.occupation" placeholder="Enter occupation" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Education Level</VLabel>
                            <VSelect v-model="form.education_level" :items="educationLevels" placeholder="Select level"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                    </VRow>

                    <!-- Address Section -->
                    <div class="px-0 py-6">
                        <h5 class="text-h5 mb-0">Address Information</h5>
                    </div>

                    <VRow>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Village</VLabel>
                            <VTextField v-model="form.village" placeholder="Enter village" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Traditional Authority</VLabel>
                            <VTextField v-model="form.traditional_authority" placeholder="Enter T/A"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">District</VLabel>
                            <VTextField v-model="form.district" placeholder="Enter district" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">City/Town</VLabel>
                            <VTextField v-model="form.city_town" placeholder="Enter city/town" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Country</VLabel>
                            <VTextField v-model="form.country" placeholder="Enter country" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VLabel class="mb-2">Postal/Zip Code</VLabel>
                            <VTextField v-model="form.postal_zip_code" placeholder="Enter postal code"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12">
                            <VLabel class="mb-2">Physical Address</VLabel>
                            <VTextarea v-model="form.physical_address" placeholder="Enter physical address" rows="2"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12">
                            <VLabel class="mb-2">Postal Address</VLabel>
                            <VTextarea v-model="form.postal_address" placeholder="Enter postal address" rows="2"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                    </VRow>

                    <!-- Next of Kin -->
                    <div class="px-0 py-6">
                        <h5 class="text-h5 mb-0">Next of Kin</h5>
                    </div>

                    <VRow>
                        <VCol cols="12" sm="4">
                            <VLabel class="mb-2">Name</VLabel>
                            <VTextField v-model="form.next_of_kin_name" placeholder="Enter name" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="4">
                            <VLabel class="mb-2">Phone</VLabel>
                            <VTextField v-model="form.next_of_kin_phone" placeholder="Enter phone" density="comfortable"
                                variant="outlined" hide-details />
                        </VCol>
                        <VCol cols="12" sm="4">
                            <VLabel class="mb-2">Relationship</VLabel>
                            <VTextField v-model="form.next_of_kin_relationship" placeholder="Enter relationship"
                                density="comfortable" variant="outlined" hide-details />
                        </VCol>
                    </VRow>

                    <VBtn type="submit" color="primary" rounded="md" variant="flat" class="mt-5" :loading="saving">
                        Save Changes
                    </VBtn>
                </VForm>
            </VCol>
        </VRow>

        <!-- Snackbar -->
        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.bg-lightsecondary {
    background-color: rgb(var(--v-theme-surface));
}

.object-cover {
    object-fit: cover;
}
</style>