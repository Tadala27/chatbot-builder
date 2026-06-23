<script setup lang="ts">
import { computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";

const route = useRoute();
const router = useRouter();

// Read directly from route.query
const code = computed(
  () => (route.query.code as string) || "ACCOUNT_DEACTIVATED",
);
const message = computed(
  () => (route.query.message as string) || "Your account has been deactivated.",
);
const shareholderStatus = computed(() => (route.query.status as string) || "");

const errorConfig = computed(() => {
  switch (code.value) {
    case "ACCOUNT_DEACTIVATED":
      return {
        icon: "$lock",
        color: "error",
        title: "Account Deactivated",
        description:
          message.value ||
          "Your account has been deactivated. Please contact the administrator for assistance.",
      };
    case "SHAREHOLDER_NOT_ACTIVE":
      return {
        icon: "$accountLock",
        color: "warning",
        title: getShareholderTitle(shareholderStatus.value),
        description:
          message.value ||
          "Your shareholder account is not active. Please contact the administrator.",
      };
    case "NO_SHAREHOLDER_PROFILE":
      return {
        icon: "$accountOff",
        color: "secondary",
        title: "Profile Not Found",
        description:
          message.value ||
          "No shareholder profile found for your account. Please contact the administrator.",
      };
    default:
      return {
        icon: "$alertCircle",
        color: "error",
        title: "Access Denied",
        description: message.value || "You do not have access to this system.",
      };
  }
});

const getShareholderTitle = (status: string) => {
  switch (status) {
    case "Inactive":
      return "Account Inactive";
    case "Suspended":
      return "Account Suspended";
    case "Deceased":
      return "Account Unavailable";
    default:
      return "Account Not Active";
  }
};

const handleLogout = () => {
  router.push("/login");
};

const contactSupport = () => {
  window.location.href =
    "mailto:support@example.com?subject=Account Access Issue";
};

onMounted(() => {
  console.log("Account error page mounted:", {
    code: code.value,
    message: message.value,
    status: shareholderStatus.value,
  });
});
</script>

<template>
  <VContainer fluid class="bg-containerBg">
    <VContainer
      style="height: calc(100vh - 32px); display: flex; align-items: center"
    >
      <VRow no-gutters>
        <!-- Left Side: Illustration (hidden on mobile, visible on md+) -->
        <VCol
          cols="12"
          md="5"
          class="d-none d-md-flex align-center justify-center"
        >
          <div class="ComingsoonWrapper my-6">
            <img src="@images/maintenance/423.jpg" alt="Account restricted" />
          </div>
        </VCol>

        <!-- Right Side: Error Content -->
        <VCol cols="12" md="7" class="d-flex align-center justify-center">
          <div class="text-center account-error-content">
            <!-- Large Icon -->
            <div class="error-icon-wrapper">
              <VAvatar :color="errorConfig.color" size="140" variant="tonal">
                <VIcon :icon="errorConfig.icon" size="70" />
              </VAvatar>
            </div>

            <!-- Title -->
            <h1 class="text-h1 mb-2 mt-sm-12">
              {{ errorConfig.title }}
            </h1>

            <!-- Description -->
            <p class="text-h6 text-lightText error-description mb-6">
              {{ errorConfig.description }}
            </p>

            <!-- Status Badge (if shareholder status exists) -->
            <VChip
              v-if="shareholderStatus"
              :color="errorConfig.color"
              size="large"
              variant="tonal"
              class="mb-8"
            >
              <VIcon start :icon="errorConfig.icon" size="20" />
              Status: {{ shareholderStatus }}
            </VChip>

            <!-- Action Buttons -->
            <div class="d-flex flex-column flex-sm-row justify-center mb-6">
              <VBtn
                color="primary"
                variant="flat"
                prepend-icon="$email"
                @click="contactSupport"
              >
                Contact Support
              </VBtn>
              <VBtn
                color="secondary"
                variant="outlined"
                class="mx-2"
                prepend-icon="$login"
                @click="handleLogout"
              >
                Return to Login
              </VBtn>
            </div>

            <!-- Additional Info -->
            <VAlert
              type="info"
              variant="tonal"
              density="compact"
              class="mx-auto text-left"
              max-width="600"
            >
              <div class="text-caption">
                If you believe this is an error, please contact your
                administrator with your account details.
              </div>
            </VAlert>

            <!-- Footer -->
            <div class="mt-8">
              <p class="text-caption text-lightText">
                © {{ new Date().getFullYear() }} Your Company. All rights
                reserved.
              </p>
            </div>
          </div>
        </VCol>
      </VRow>
    </VContainer>
  </VContainer>
</template>

<style lang="scss" scoped>
.ComingsoonWrapper {
  display: flex;
  justify-content: center;

  img {
    height: 440px;

    @media (max-width: 1280px) {
      height: 350px;
    }

    @media (max-width: 960px) {
      height: 300px;
    }

    @media (max-width: 600px) {
      height: 280px;
    }
  }
}

.account-error-content {
  max-width: 800px;
  padding-inline: 1.5rem;

  @media (min-width: 768px) {
    padding-inline: 2rem;
  }
}

.error-description {
  width: 90%;
  margin-inline: auto;

  @media (min-width: 768px) {
    width: 80%;
  }
}

.error-icon-wrapper {
  display: inline-block;
  margin-inline: auto;
}
</style>
