<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from "axios"
import { useUserStore } from '@/stores/user'
import { useAbility } from '@casl/vue'
import Logo from '../../images/logos/NICO-Tech.png'

definePage({
    meta: {
        layout: 'blank',
        unauthenticatedOnly: true,
        public: true
    }
})

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const ability = useAbility() // ✅ Use CASL only in component, NOT in store

// Dynamic carousel slides
const slides = [
    {
        icon: '$currencyUsd',
        title: 'Secure & Transparent Payments',
        description: 'Record payments instantly with multiple methods. All transactions are verified, logged, and reflected in your personal statement for complete transparency.'
    },
    {
        icon: '$accountTie',
        title: 'Personal Shareholder Portal',
        description: 'Access your profile, view invoices, track payments, download statements, and manage your membership — all in a modern, easy-to-use dashboard.'
    },
    {
        icon: '$chartTimelineVariant',
        title: 'Real-Time Reports & Analytics',
        description: 'Admins get powerful visual reports: shareholder summaries, payment history, arrears tracking, and monthly contributions — all updated live.'
    },
    {
        icon: '$shieldCheck',
        title: 'Secure & Compliance-Focused',
        description: 'Role-based access, activity logging, data encryption, and audit trails ensure your cooperative\'s information is protected and compliant.'
    },
    {
        icon: '$rocketLaunch',
        title: 'Ready for Your Cooperative',
        description: 'Designed specifically for savings and credit cooperatives. Easy to set up, intuitive for members and admins alike — start managing today.'
    }
]

// State
const loading = ref(false)
const showPassword = ref(false)
const rememberMe = ref(false)
const credentials = ref({ email: '', password: '' })
const apiError = ref('')
const logform = ref()

const required = (v: any) => !!v || 'Required'

// Check for errors from Microsoft redirect
const loginError = ref('')
if (route.query.error) {
    switch (route.query.error) {
        case 'tenant_assignment_failed':
            loginError.value = 'Unable to assign you to a tenant. Please contact support.'
            break
        case 'account_inactive':
            loginError.value = 'Your account is inactive. Please contact your administrator.'
            break
        case 'auth_failed':
            loginError.value = 'Authentication failed. Please try again.'
            break
    }
}

// Microsoft Login
const loginWithMicrosoft = () => {
    window.location.href = '/auth/microsoft'
}

async function submitLogin() {
    const { valid: isValid } = await logform.value?.validate()
    if (!isValid) return

    loading.value = true
    apiError.value = ''

    try {
        // ✅ Login returns userAbilityRules
        const result = await userStore.login({
            email: credentials.value.email,
            password: credentials.value.password,
            remember_me: rememberMe.value
        })

        // ✅ Update CASL ability in component (not in store)
        if (result?.userAbilityRules) {
            ability.update(result.userAbilityRules)
            console.log('✅ CASL ability updated with rules:', result.userAbilityRules.length)
        }

        router.push('/dashboard')
    } catch (e: any) {
        const resp = e.response
        if (resp?.status === 423 && resp.data?.locked) {
            apiError.value = resp.data.message
            return
        }
        apiError.value = resp?.data?.message || 'Invalid email or password'
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <VRow class="ma-0" style="min-height: 100vh;">
        <VCol cols="12" md="7" class="d-flex flex-column pa-8">
            <div class="mb-6 d-flex justify-start justify-md-start offset-md-2">
                <img :src="Logo" style="max-height: 100px;" alt="TMCC Logo" width="170" class="rounded-md" />
            </div>

            <div class="flex-grow-1 d-flex align-start align-md-center justify-center">
                <VCol cols="12" sm="12" md="10" lg="8">
                    <div class="text-left mb-2">
                        <h4 class="text-h4">Welcome to NICO Performance Plus</h4>
                        <div class="text-h6 text-secondary">
                            Sign in to access your account
                        </div>
                    </div>

                    <VAlert v-if="apiError" color="error" variant="tonal" closable @click:close="apiError = ''"
                        class="mb-6">
                        {{ apiError }}
                    </VAlert>
                    <!-- Microsoft Login Button -->
                    <VBtn @click="loginWithMicrosoft" color="primary" variant="outlined" block size="large"
                        class="mb-4 text-none" prepend-icon="$microsoft">
                        Sign in with Microsoft
                    </VBtn>

                    <VDivider class="my-6">
                        <span class="text-caption text-medium-emphasis">OR</span>
                    </VDivider>

                    <VForm ref="logform" @submit.prevent="submitLogin">
                        <!-- EMAIL FIELD -->
                        <VTextField v-model="credentials.email" label="Email" :rules="[required]" variant="outlined"
                            density="comfortable" class="mb-4" autocomplete="email" />

                        <!-- PASSWORD FIELD -->
                        <VTextField v-model="credentials.password" :type="showPassword ? 'text' : 'password'"
                            label="Password" :rules="[required]" variant="outlined" density="comfortable" class="mb-2"
                            autocomplete="current-password">
                            <template #append-inner>
                                <VBtn icon variant="text" size="small" @click="showPassword = !showPassword">
                                    <SvgSprite :name="showPassword ? 'custom-eye' : 'custom-eye-invisible'" />
                                </VBtn>
                            </template>
                        </VTextField>

                        <div class="d-flex align-center mb-6">
                            <VCheckbox v-model="rememberMe" label="Remember me" hide-details />
                            <RouterLink to="/forgot-password" class="ms-auto text-primary text-decoration-none">
                                Forgot Password?
                            </RouterLink>
                        </div>

                        <VBtn type="submit" color="primary" block size="large" :loading="loading" class="text-none">
                            {{ loading ? 'Signing in...' : 'Sign In' }}
                        </VBtn>
                    </VForm>
                </VCol>
            </div>
        </VCol>

        <!-- RIGHT SIDE PANEL -->
        <VCol cols="12" md="5" class="d-none d-md-flex align-center justify-center pa-8 bg-primary">
            <div class="text-center text-white" style="max-width: 400px;">
                <VCarousel cycle hide-delimiters height="auto" :show-arrows="false" class="mb-4">
                    <VCarouselItem v-for="(slide, i) in slides" :key="i">
                        <div class="pa-6">
                            <VAvatar size="64" variant="tonal" color="white" class="mb-4">
                                <v-icon :icon="slide.icon" color="white" size="36" />
                            </VAvatar>

                            <h5 class="text-h5 mb-3">{{ slide.title }}</h5>
                            <p class="text-body-1" style="line-height: 1.8;">{{ slide.description }}</p>
                        </div>
                    </VCarouselItem>
                </VCarousel>

                <div class="mt-8">
                    <p class="text-caption opacity-75">
                        © {{ new Date().getFullYear() }} NICO Technologies Ltd. All rights reserved.
                    </p>
                </div>
            </div>
        </VCol>
    </VRow>
</template>