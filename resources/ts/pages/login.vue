<script setup lang="ts">
import { ref } from "vue";
import { useRouter } from "vue-router";
import { useUserStore } from "@/stores/user";

definePage({
  meta: {
    layout: "blank",
    unauthenticatedOnly: true,
    public: true,
  },
});

const router = useRouter();
const userStore = useUserStore();

// Carousel slides (unchanged)
const slides = [
  {
    icon: "$currencyUsd",
    title: "Secure & Transparent Payments",
    description:
      "Record payments instantly with multiple methods. All transactions are verified, logged, and reflected in your personal statement for complete transparency.",
  },
  {
    icon: "$accountTie",
    title: "Personal Shareholder Portal",
    description:
      "Access your profile, view invoices, track payments, download statements, and manage your membership — all in a modern, easy-to-use dashboard.",
  },
  {
    icon: "$chartTimelineVariant",
    title: "Real-Time Reports & Analytics",
    description:
      "Admins get powerful visual reports: shareholder summaries, payment history, arrears tracking, and monthly contributions — all updated live.",
  },
  {
    icon: "$shieldCheck",
    title: "Secure & Compliance-Focused",
    description:
      "Role-based access, activity logging, data encryption, and audit trails ensure your cooperative's information is protected and compliant.",
  },
  {
    icon: "$rocketLaunch",
    title: "Ready for Your Cooperative",
    description:
      "Designed specifically for savings and credit cooperatives. Easy to set up, intuitive for members and admins alike — start managing today.",
  },
];

// Form state
const form = ref({
  email: "",
  password: "",
});

// UI states
const showPassword = ref(false);
const rememberMe = ref(false);
const apiError = ref("");

// Validation rules
const required = (v: any) => !!v || "Required";
const emailRules = [
  (v: string) => !!v || "Email is required",
  (v: string) => /.+@.+\..+/.test(v) || "Email must be valid",
];
const passwordRules = [
  (v: string) => !!v || "Password is required",
  (v: string) => v.length >= 6 || "Password must be at least 6 characters",
];

// Login function using the store
const login = async () => {
  apiError.value = "";

  const result = await userStore.login({
    email: form.value.email,
    password: form.value.password,
  });

  if (result.success) {
    // Redirect to dashboard (or intended route)
    router.push({ name: "dashboard" });
  } else {
    apiError.value = result.error || "Login failed. Please check your credentials.";
  }
};
</script>

<template>
  <VRow class="ma-0" style="min-height: 100vh;">
    <!-- Left column: login form -->
    <VCol cols="12" md="7" class="d-flex flex-column pa-8">
      <!-- Logo -->
      <div class="mb-6 d-flex justify-start justify-md-start offset-md-2">
        <img
          :src="Logo"
          style="max-height: 100px;"
          alt="Company Logo"
          width="170"
          class="rounded-md"
        />
      </div>

      <div class="flex-grow-1 d-flex align-start align-md-center justify-center">
        <VCol cols="12" sm="12" md="10" lg="8">
          <div class="text-left mb-2">
            <h4 class="text-h4">Welcome to NICO Performance Plus</h4>
            <div class="text-h6 text-secondary">
              Sign in to access your account
            </div>
          </div>

          <!-- Error alert -->
          <VAlert
            v-if="apiError"
            color="error"
            variant="tonal"
            closable
            @click:close="apiError = ''"
            class="mb-6"
          >
            {{ apiError }}
          </VAlert>

          <!-- Microsoft button (placeholder) -->
          <VBtn
            color="primary"
            variant="outlined"
            block
            size="large"
            class="mb-4 text-none"
            prepend-icon="$microsoft"
          >
            Sign in with Microsoft
          </VBtn>

          <VDivider class="my-6">
            <span class="text-caption text-medium-emphasis">OR</span>
          </VDivider>

          <VForm @submit.prevent="login">
            <VTextField
              v-model="form.email"
              label="Email"
              type="email"
              variant="outlined"
              :rules="emailRules"
              prepend-inner-icon="$email"
              required
            />

            <VTextField
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              label="Password"
              variant="outlined"
              :rules="passwordRules"
              class="mt-4"
              required
            >
              <template #append-inner>
                <VIcon
                  :icon="showPassword ? '$eye' : '$eyeOff'"
                  @click="showPassword = !showPassword"
                  class="cursor-pointer"
                />
              </template>
            </VTextField>

            <!-- Remember me & Forgot password -->
            <div class="d-flex align-center mb-6">
              <VCheckbox v-model="rememberMe" label="Remember me" hide-details />
              <RouterLink
                to="/forgot-password"
                class="ms-auto text-primary text-decoration-none"
              >
                Forgot Password?
              </RouterLink>
            </div>

            <VBtn
              type="submit"
              color="primary"
              size="large"
              block
              :loading="userStore.isLoading"
              :disabled="userStore.isLoading"
            >
              {{ userStore.isLoading ? "Signing in..." : "Sign In" }}
            </VBtn>
          </VForm>

          <!-- Demo accounts section -->
          <div class="text-center text-caption mt-6">
            <p class="text-medium-emphasis">Demo Accounts:</p>
            <p class="mt-2"><strong>Super Admin:</strong> admin@chatbot.com</p>
            <p><strong>TechCorp:</strong> john@techcorp.com</p>
            <p><strong>RetailHub:</strong> sarah@retailhub.com</p>
            <p class="mt-2 text-disabled">Password: password</p>
          </div>
        </VCol>
      </div>
    </VCol>

    <!-- Right column: carousel -->
    <VCol cols="12" md="5" class="d-none d-md-flex align-center justify-center pa-8 bg-primary">
      <div class="text-center text-white" style="max-width: 400px;">
        <VCarousel cycle hide-delimiters height="auto" :show-arrows="false" class="mb-4">
          <VCarouselItem v-for="(slide, i) in slides" :key="i">
            <div class="pa-6">
              <VAvatar size="64" variant="tonal" color="white" class="mb-4">
                <VIcon :icon="slide.icon" color="white" size="36" />
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

<style scoped>
/* Minimal styling */
</style>