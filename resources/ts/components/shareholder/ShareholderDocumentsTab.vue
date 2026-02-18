<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import axios from "axios"

import Swal from 'sweetalert2'

const props = defineProps<{
    shareholder: any
}>()

const emit = defineEmits<{
    (e: 'documents-updated'): void
}>()

const showDialog = ref(false)
const fileInput = ref()
const uploading = ref(false)

// Document form
const document = reactive({
    name: '',
    category: '',
    file: null as any
})

// Snackbar
const snackbar = reactive({
    show: false,
    message: '',
    color: 'success'
})

// Document categories
const categories = [
    'National ID',
    'Passport',
    'Proof of Residence',
    'Bank Statement',
    'Share Certificate',
    'Other'
]

// Computed
const documents = computed(() => props.shareholder?.documents || [])

// Validation rules
const rules = {
    required: (v: any) => !!v || 'This field is required'
}

// Methods
const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement
    const file = target.files?.[0]
    if (!file) return

    if (file.size > 5 * 1024 * 1024) {
        showSnackbar('File is too large (max 5MB)', 'error')
        return
    }

    document.file = {
        name: file.name,
        size: file.size,
        type: file.type,
        preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
        raw: file
    }
}

const handleDrop = (e: DragEvent) => {
    const file = e.dataTransfer?.files[0]
    if (file) {
        handleFileChange({ target: { files: [file] } } as any)
    }
}

const removeDocument = () => {
    if (document.file?.preview) {
        URL.revokeObjectURL(document.file.preview)
    }
    document.file = null
}

const uploadDocument = async () => {
    if (!document.name || !document.category || !document.file) {
        showSnackbar('Please fill all required fields', 'warning')
        return
    }

    uploading.value = true
    try {
        const formData = new FormData()
        formData.append('name', document.name)
        formData.append('category', document.category)
        formData.append('file', document.file.raw)

        await axios.post(`/api/shareholders/${props.shareholder.id}/documents`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        showSnackbar('Document uploaded successfully', 'success')

        // Reset form
        document.name = ''
        document.category = ''
        removeDocument()
        showDialog.value = false

        emit('documents-updated')
    } catch (error: any) {
        showSnackbar(error.response?.data?.message || 'Failed to upload document', 'error')
    } finally {
        uploading.value = false
    }
}

const deleteDocument = async (id: number) => {
    const result = await Swal.fire({
        title: 'Delete Document',
        text: 'Are you sure you want to delete this document?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete it',
        confirmButtonColor: '#ef4444'
    })

    if (!result.isConfirmed) return

    try {
        await axios.delete(`/api/shareholders/${props.shareholder.id}/documents/${id}`)
        showSnackbar('Document deleted successfully', 'success')
        emit('documents-updated')
    } catch (error: any) {
        showSnackbar(error.response?.data?.message || 'Failed to delete document', 'error')
    }
}

const downloadDocument = (doc: any) => {
    if (doc.file_path) {
        window.open(doc.file_path, '_blank')
    }
}

const formatFileSize = (size: number) => {
    if (!size) return '0 B'
    const units = ['B', 'KB', 'MB', 'GB']
    let i = 0
    while (size >= 1024 && i < units.length - 1) {
        size /= 1024
        i++
    }
    return `${size.toFixed(2)} ${units[i]}`
}

const truncateText = (text: string, length = 20) => {
    if (!text) return ''
    return text.length > length ? text.substring(0, length) + '...' : text
}

const showSnackbar = (message: string, color: string = 'success') => {
    snackbar.message = message
    snackbar.color = color
    snackbar.show = true
}
</script>

<template>
    <div>
        <div class="px-5 py-6">
            <h5 class="text-h5 mb-0">Documents</h5>
            <p class="text-caption text-medium-emphasis">Manage shareholder documents and certificates</p>
        </div>

        <VRow>
            <!-- Existing Documents -->
            <VCol v-for="doc in documents" :key="doc.id" cols="12" sm="6" md="4">
                <VCard elevation="0" rounded="lg">
                    <VCard variant="outlined" rounded="lg">
                        <VCardText>
                            <div class="d-flex align-center justify-space-between">
                                <div class="d-flex align-center flex-grow-1 overflow-hidden">
                                    <SvgSprite name="custom-document-text-outline" style="width: 44px; height: 44px"
                                        class="text-primary me-3 flex-shrink-0" />
                                    <div class="overflow-hidden">
                                        <h5 class="text-h6 mb-1">
                                            {{ truncateText(doc.name, 15) }}
                                            <VTooltip activator="parent" location="bottom">
                                                {{ doc.name }}
                                            </VTooltip>
                                        </h5>
                                        <span class="text-caption text-lightText">
                                            {{ doc.category }} • {{ formatFileSize(doc.file_size) }}
                                        </span>
                                    </div>
                                </div>

                                <VMenu location="bottom">
                                    <template #activator="{ props }">
                                        <VBtn v-bind="props" icon variant="text" size="small" color="grey">
                                            <VIcon size="20">$dotsVertical</VIcon>
                                        </VBtn>
                                    </template>
                                    <VList class="cursor-pointer">
                                        <VListItem class="text-primary" @click="downloadDocument(doc)">
                                            <VListItemTitle>
                                                <VIcon size="20" class="me-2">$trayArrowDown</VIcon>
                                                Download
                                            </VListItemTitle>
                                        </VListItem>
                                        <VListItem class="text-error" @click="deleteDocument(doc.id)">
                                            <VListItemTitle>
                                                <VIcon size="20" class="me-2">$trashCan</VIcon>
                                                Delete
                                            </VListItemTitle>
                                        </VListItem>
                                    </VList>
                                </VMenu>
                            </div>
                        </VCardText>
                    </VCard>
                </VCard>
            </VCol>

            <!-- Add Document Card -->
            <VCol cols="12" sm="6" md="4">
                <VCard elevation="0" rounded="lg" @click="showDialog = true" style="cursor: pointer;">
                    <VCard variant="outlined" rounded="lg"
                        class="border-dashed border-primary border-thin bg-lightsecondary">
                        <VCardText>
                            <div class="d-flex align-center justify-space-between">
                                <div class="d-flex align-center">
                                    <VIcon icon="$plusCircleOutline" size="44" class="text-primary me-3" />
                                    <div>
                                        <h5 class="text-h6 mb-1">Add Document</h5>
                                        <span class="text-caption text-lightText">Upload new file</span>
                                    </div>
                                </div>
                            </div>
                        </VCardText>
                    </VCard>
                </VCard>
            </VCol>
        </VRow>

        <!-- Empty State -->
        <VAlert v-if="documents.length === 0" type="info" variant="tonal" class="mt-4">
            No documents uploaded yet. Click "Add Document" to upload.
        </VAlert>

        <!-- Upload Dialog -->
        <VDialog v-model="showDialog" max-width="700">
            <VCard>
                <VCardTitle class="text-h6">Upload Document</VCardTitle>
                <VCardText>
                    <VRow>
                        <VCol cols="12" sm="6">
                            <VTextField v-model="document.name" label="Document Name *" placeholder="Enter name"
                                variant="outlined" :rules="[rules.required]" />
                        </VCol>
                        <VCol cols="12" sm="6">
                            <VSelect v-model="document.category" label="Category *" :items="categories"
                                placeholder="Select category" variant="outlined" :rules="[rules.required]" />
                        </VCol>
                        <VCol cols="12">
                            <div class="d-flex flex-column align-center justify-center border-dashed border-primary border-thin rounded-lg bg-lightsecondary"
                                style="width: 100%; min-height: 200px; cursor: pointer; position: relative;"
                                @drop.prevent="handleDrop" @dragover.prevent @click="$refs.fileInput.click()">

                                <!-- Hidden file input -->
                                <input ref="fileInput" type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                    class="position-absolute w-100 h-100 opacity-0" style="cursor: pointer;"
                                    @change="handleFileChange" />

                                <!-- Preview for images -->
                                <img v-if="document.file?.preview" :src="document.file.preview"
                                    :alt="document.file.name" class="w-100 h-100 object-cover" />

                                <!-- Placeholder -->
                                <div v-else class="text-center px-4">
                                    <VIcon icon="$cloudUploadOutline" size="55" class="text-secondary" />
                                    <p class="text-body1 font-weight-medium">Browse file or drop here</p>
                                    <p class="text-caption mt-1 text-medium-emphasis">
                                        Max size: 5MB<br />
                                        Allowed: .pdf, .doc, .docx, .jpg, .jpeg, .png
                                    </p>
                                </div>
                            </div>

                            <!-- File info -->
                            <div v-if="document.file" class="d-flex flex-column mt-2">
                                <p class="text-body-2 mb-1">Selected: {{ document.file.name }}</p>
                                <p class="text-caption text-medium-emphasis mb-1">
                                    Size: {{ (document.file.size / 1024 / 1024).toFixed(2) }} MB
                                </p>
                                <VBtn color="error" variant="text" @click="removeDocument">
                                    Remove File
                                </VBtn>
                            </div>
                        </VCol>
                    </VRow>
                </VCardText>

                <VCardActions>
                    <VBtn text @click="showDialog = false">Cancel</VBtn>
                    <VSpacer />
                    <VBtn color="primary" :loading="uploading"
                        :disabled="!document.name || !document.category || !document.file" @click="uploadDocument">
                        Upload
                    </VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

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
.text-lightText {
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.bg-lightsecondary {
    background-color: rgb(var(--v-theme-surface));
}

.object-cover {
    object-fit: cover;
}

.cursor-pointer {
    cursor: pointer;
}
</style>