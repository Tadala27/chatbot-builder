<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from "axios"

import Logo from '@images/nbs-bank.png'
import Avatar1 from '@images/users/avatar-1.png'
import Avatar2 from '@images/users/avatar-2.png'
import Avatar3 from '@images/users/avatar-3.png'
import Swal from 'sweetalert2'

definePage({
    meta: {
        layout: 'blank',
        unauthenticatedOnly: true,
        public: true
    }
})

// Text Slider Data
interface SlideType {
    image: string
    title: string
    description: string
}

const slides = ref<SlideType[]>([
    {
        image: Avatar1,
        title: 'Allie Grater',
        description:
            'Very good customer service!👌 I liked the design and there was nothing wrong, but found out after testing that it did not quite match the functionality and overall design that I needed for my type of software. I therefore contacted customer service and it was no problem even though the deadline for refund had actually expired.😍',
    },
    {
        image: Avatar2,
        title: 'John Doe',
        description:
            'Very good customer service!👌 I liked the design and there was nothing wrong, but found out after testing that it did not quite match the functionality and overall design that I needed for my type of software. I therefore contacted customer service and it was no problem even though the deadline for refund had actually expired.😍',
    },
    {
        image: Avatar3,
        title: 'Jane Smith',
        description:
            'Very good customer service!👌 I liked the design and there was nothing wrong, but found out after testing that it did not quite match the functionality and overall design that I needed for my type of software. I therefore contacted customer service and it was no problem even though the deadline for refund had actually expired.😍',
    },
])

const router = useRouter()
const valid = ref(false)
const logform = ref()
const email = ref('')
const errors = reactive<{ apiError?: string }>({})
const loading = ref(false)

// Email validation rules
const emailRules = ref([
    (v: string) => !!v.trim() || 'E-mail is required',
    (v: string) => {
        const trimmedEmail = v.trim()
        return !/\s/.test(trimmedEmail) || 'E-mail must not contain spaces'
    },
    (v: string) => /.[^\n\r@\u2028\u2029]*@.+\..+/.test(v.trim()) || 'E-mail must be valid',
])

async function sendOtp() {
    errors.apiError = undefined
    const isValid = await logform.value?.validate()
    if (!isValid) return

    loading.value = true
    try {
        const response = await axios.post('/api/password/send-otp', {
            email: email.value.trim()
        })

        Swal.fire({
            icon: 'success',
            title: 'Check your email',
            text: response.data.message,
            timer: 3000,
            showConfirmButton: false
        })

        // Redirect to OTP verification page with email
        router.push({
            name: 'code',
            query: { email: email.value.trim() }
        })
    } catch (err: any) {
        errors.apiError = err.response?.data?.message || 'Failed to send verification code'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <VRow class="ma-0" style="min-height: 100vh;">
        <VCol cols="12" md="7" class="d-flex flex-column pa-8">
            <div class="mb-6 d-flex justify-start justify-md-start offset-md-2">
                <img :src="Logo" alt="Logo" width="200" class="rounded-md" />
            </div>

            <div class="flex-grow-1 d-flex align-start align-md-center justify-center">
                <VCol cols="12" sm="12" md="10" lg="8">
                    <VForm ref="Regform" lazy-validation class="mt-7 loginForm">
                        <div class="text-left mb-4">
                            <h4 class="text-h4 mb-4">Forgot Password</h4>
                            <div class="text-h6 text-secondary">
                                Go Back to
                                <RouterLink :to="{ name: 'login' }" class="text-primary text-decoration-none">
                                    Sign in
                                </RouterLink>
                            </div>
                            <div class="text-h6 text-secondary mt-3">
                                Don't Have an account?
                                <RouterLink :to="{ name: 'register' }" class="text-primary text-decoration-none">
                                    Create Account
                                </RouterLink>
                            </div>
                        </div>

                        <div v-if="errors.apiError" class="mb-4">
                            <VAlert color="error" variant="tonal" icon="$alertCircle" rounded="md" closable>
                                {{ errors.apiError }}
                            </VAlert>
                        </div>

                        <VForm ref="logform" v-model="valid" lazy-validation class="mt-7 loginForm"
                            @submit.prevent="sendOtp">
                            <VLabel>Email address</VLabel>
                            <VTextField v-model="email" :rules="emailRules" placeholder="Enter email address"
                                class="mt-2 mb-6" required density="comfortable" hide-details="auto" variant="outlined"
                                color="primary" @input="email = email.trim()" />

                            <VBtn color="primary" block class="mt-2" variant="flat" size="large" rounded="md"
                                :disabled="!valid || loading" type="submit">
                                <span v-if="!loading">
                                    Reset Password
                                    <SvgSprite name="custom-arrow-right" style="width: 20px; height: 20px" />
                                </span>
                                <span v-else class="d-flex align-center justify-center">
                                    <VProgressCircular indeterminate color="white" size="20" thickness="2" />
                                    Sending...
                                </span>
                            </VBtn>
                        </VForm>
                    </VForm>
                </VCol>
            </div>
        </VCol>

        <VCol cols="12" md="5" class="d-none d-md-flex align-center justify-center pa-8 bg-darkprimary">
            <div class="text-center text-white" style="max-width: 400px;">
                <VCarousel cycle hide-delimiters height="auto" :show-arrows="false" class="mb-4">
                    <VCarouselItem v-for="(slide, i) in slides" :key="i">
                        <div class="pa-6">
                            <VAvatar size="52" variant="tonal" color="white" class="mb-4">
                                <img :src="slide.image" width="52" alt="avatar">
                            </VAvatar>
                            <h5 class="text-h5 mb-2">
                                {{ slide.title }}
                            </h5>
                            <p class="text-caption mb-4 opacity-75">
                                @alliegrater
                            </p>
                            <VRating :model-value="3" color="warning" density="compact" readonly size="small"
                                class="mb-4" />
                            <p class="text-body-2" style="line-height: 1.6;">
                                {{ slide.description }}
                            </p>
                        </div>
                    </VCarouselItem>
                </VCarousel>
            </div>
        </VCol>
    </VRow>
</template>