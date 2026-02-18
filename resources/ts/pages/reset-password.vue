<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from "axios"

import Logo from '@images/nbs-bank.png'
import Swal from 'sweetalert2'


definePage({
    meta: {
        layout: 'blank',
        public: true,
        unauthenticatedOnly: true,
    },
})

const router = useRouter()
const route = useRoute()
const formRef = ref()
const valid = ref(false)
const loading = ref(false)
const errors = reactive<{ apiError?: string }>({})

const email = ref(route.query.email as string || '')
const sessionToken = ref(route.query.session_token as string || '')
const password = ref('')
const passwordConfirm = ref('')
const showPassword = ref(false)
const showPasswordConfirm = ref(false)

const passwordRules = [
    (v: string) => !!v || 'Password is required',
    (v: string) => v.length >= 8 || 'Password must be at least 8 characters',
    (v: string) => /[A-Z]/.test(v) || 'Password must contain at least one uppercase letter',
    (v: string) => /[a-z]/.test(v) || 'Password must contain at least one lowercase letter',
    (v: string) => /\d/.test(v) || 'Password must contain at least one number',
    (v: string) => /[^A-Za-z\d]/.test(v) || 'Password must contain at least one special character',
]

const passwordConfirmRules = [
    (v: string) => !!v || 'Password confirmation is required',
    (v: string) => v === password.value || 'Passwords do not match',
]

onMounted(() => {
    if (!email.value || !sessionToken.value) {
        console.log('No session Token')
        router.push({ name: 'forgot-password' })
    }
})

async function resetPassword() {
    errors.apiError = undefined
    const isValid = await formRef.value?.validate()
    if (!isValid) return

    loading.value = true
    try {
        const payload: any = {
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirm.value,
            session_token: sessionToken.value
        }

        await axios.post('/api/password/reset', payload)

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Password reset successfully. Redirecting to login...',
            timer: 2000,
            showConfirmButton: false
        })

        router.push({ name: 'login' })
    } catch (err: any) {
        errors.apiError = err.response?.data?.message || `Failed to reset password:${err}`
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <VRow class="ma-0" style="min-height: 100vh;">
        <VCol cols="12" class="d-flex flex-column align-center justify-center pa-8">
            <div class="mb-6 d-flex justify-center">
                <img :src="Logo" alt="Logo" width="200" class="rounded-md" />
            </div>

            <VCol cols="12" sm="10" md="6" lg="5">
                <VForm ref="formRef" v-model="valid" lazy-validation class="loginForm" @submit.prevent="resetPassword">
                    <h4 class="text-h4 mb-4 text-center">Reset Password</h4>
                    <div class="mb-4 w-100 text-center">
                        <h6 class="text-h6 text-lightText">
                            Enter your new password for <strong>{{ email }}</strong>
                        </h6>
                        <p class="text-caption text-medium-emphasis mt-2">
                            Your password must be at least 8 characters with uppercase, lowercase, number, and special
                            character.
                        </p>
                    </div>

                    <div v-if="errors.apiError" class="mb-4 text-center">
                        <VAlert color="error" variant="tonal" icon="$alertCircle" rounded="md" closable>
                            {{ errors.apiError }}
                        </VAlert>
                    </div>

                    <div class="mb-6 mt-6">
                        <VTextField v-model="password" label="New Password" :rules="passwordRules"
                            :type="showPassword ? 'text' : 'password'" density="comfortable" hide-details="auto"
                            variant="outlined" :disabled="loading">
                            <template #append-inner>
                                <VBtn icon variant="text" @click="showPassword = !showPassword">
                                    <SvgSprite :name="showPassword ? 'custom-eye' : 'custom-eye-invisible'" />
                                </VBtn>
                            </template>
                        </VTextField>

                        <VTextField class="mt-4" v-model="passwordConfirm" label="Confirm Password"
                            :rules="passwordConfirmRules" :type="showPasswordConfirm ? 'text' : 'password'"
                            density="comfortable" hide-details="auto" variant="outlined" :disabled="loading">
                            <template #append-inner>
                                <VBtn icon variant="text" @click="showPasswordConfirm = !showPasswordConfirm">
                                    <SvgSprite :name="showPasswordConfirm ? 'custom-eye' : 'custom-eye-invisible'" />
                                </VBtn>
                            </template>
                        </VTextField>
                    </div>

                    <VBtn color="primary" block class="mt-4" variant="flat" rounded="md" size="large" type="submit"
                        :loading="loading" :disabled="!valid || loading">
                        <span v-if="!loading">
                            Reset Password
                            <SvgSprite name="custom-arrow-right" style="width: 20px; height: 20px" />
                        </span>
                        <span v-else>
                            Resetting...
                        </span>
                    </VBtn>

                    <div class="text-center mt-4">
                        <RouterLink :to="{ name: 'login' }" class="text-primary text-decoration-none">
                            Return to Sign In
                        </RouterLink>
                    </div>
                </VForm>
            </VCol>
        </VCol>
    </VRow>
</template>