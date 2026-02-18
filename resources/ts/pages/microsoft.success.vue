<!-- pages/auth/microsoft/success.vue -->
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useAbility } from '@casl/vue'
import axios from 'axios'

definePage({
    meta: {
        layout: 'blank',
        public: true
    }
})

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const ability = useAbility()

onMounted(async () => {
    const token = route.query.token as string

    if (!token) {
        router.push('/login?error=invalid_token')
        return
    }

    try {
        // Set the token
        userStore.accessToken = token
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`

        // Fetch user data
        const { data } = await axios.get('/api/auth/user')

        userStore.setUser(
            data.data,
            data.permissions || [],
            token,
            [] // Will be populated if needed
        )

        if (data.permissions) {
            ability.update(data.permissions)
        }

        // Redirect to dashboard
        router.push('/dashboard')
    } catch (error) {
        console.error('Failed to fetch user after Microsoft login', error)
        router.push('/login?error=auth_failed')
    }
})
</script>

<template>
    <div class="d-flex align-center justify-center" style="min-height: 100vh;">
        <VProgressCircular indeterminate color="primary" size="64" />
        <div class="ml-4 text-h6">Completing sign in...</div>
    </div>
</template>