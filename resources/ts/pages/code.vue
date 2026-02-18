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
const otp = ref('')
const email = ref(route.query.email as string || '')
const errors = reactive<{ apiError?: string }>({})
const loading = ref(false)

const otpRules = [
    (v: string) => !!v || 'Verification code is required',
    (v: string) => v.length === 4 || 'Code must be 4 digits',
]

onMounted(() => {
    if (!email.value) {
        router.push({ name: 'forgot-password' })
    }
})

async function verifyOtp() {
    errors.apiError = undefined
    const isValid = await formRef.value?.validate()
    if (!isValid) return

    loading.value = true
    try {
        const response = await axios.post('/api/password/verify-otp', {
            email: email.value,
            otp: otp.value
        })

        // Store session token for password reset
        const { session_token } = response.data
        console.log(session_token);

        Swal.fire({
            icon: 'success',
            title: 'Verified!',
            text: 'You can now reset your password.',
            timer: 1500,
            showConfirmButton: false
        })

        // Redirect to password reset with session token
        router.push({
            name: 'reset-password',
            query: {
                email: email.value,
                session_token
            }
        })
    } catch (err: any) {
        errors.apiError = err.response?.data?.message || 'Invalid or expired code'
        if (err.response?.status === 400) {
            otp.value = ''
        }
    } finally {
        loading.value = false
    }
}

async function resendOtp() {
    try {
        loading.value = true
        await axios.post('/api/password/resend-otp', { email: email.value })
        Swal.fire({
            icon: 'info',
            title: 'Code resent',
            text: 'Check your email for the new verification code.',
            timer: 2000
        })
        otp.value = ''
    } catch (err: any) {
        errors.apiError = err.response?.data?.message || 'Failed to resend code'
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
                <VForm ref="formRef" v-model="valid" lazy-validation class="loginForm" @submit.prevent="verifyOtp">
                    <h4 class="text-h4 mb-4 text-center">Verify Email</h4>
                    <div class="mb-4 w-100 text-center">
                        <h6 class="text-h6 text-lightText">
                            We've sent a verification code to <strong>{{ email }}</strong>.<br>
                            Enter the code to reset your password.
                        </h6>
                        <div class="mt-4">
                            <VBtn variant="text" color="primary" :disabled="loading" @click="resendOtp" size="small">
                                Resend Code
                            </VBtn>
                        </div>
                    </div>

                    <div v-if="errors.apiError" class="mb-4 text-center">
                        <VAlert color="error" variant="tonal" icon="$alertCircle" rounded="md" closable>
                            {{ errors.apiError }}
                        </VAlert>
                    </div>

                    <div class="mb-6 mt-6">
                        <v-otp-input v-model="otp" :rules="otpRules" class="mb-8" divider="•" length="4"
                            variant="outlined" :disabled="loading"></v-otp-input>
                    </div>

                    <VBtn color="primary" block class="mt-4" variant="flat" rounded="md" size="large" type="submit"
                        :loading="loading" :disabled="loading">
                        <span v-if="!loading">
                            Verify Code
                            <SvgSprite name="custom-arrow-right" style="width: 20px; height: 20px" />
                        </span>
                        <span v-else>
                            Verifying...
                        </span>
                    </VBtn>

                    <div class="text-center mt-4">
                        <RouterLink :to="{ name: 'forgot-password' }" class="text-primary text-decoration-none">
                            Back to Forgot Password
                        </RouterLink>
                    </div>
                </VForm>
            </VCol>
        </VCol>
    </VRow>
</template>

<style lang="scss">
.loginForm {
    .v-otp-input {
        padding: 0;

        .v-otp-input__content {
            max-width: 100%;
            padding: 0;
        }

        .v-otp-input__field {
            font-size: 23px;
        }
    }
}
</style>
