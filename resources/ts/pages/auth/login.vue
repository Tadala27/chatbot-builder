<script setup>
import { ref, reactive, computed } from "vue";
import { useRouter } from "vue-router";
import { useUserStore } from "@/stores/user";

const router = useRouter();
const authStore = useUserStore();
const loading = ref(false);
const error = ref("");
const showPassword = ref(false);

const detectedSlug = computed(() => {
  const parts = window.location.hostname.split(".");
  return parts.length > 2 && parts[0] !== "www" && parts[0] !== "app"
    ? parts[0]
    : "";
});

const isTenantDomain = computed(() => !!detectedSlug.value);

const sessionRefreshed = computed(
  () =>
    new URLSearchParams(window.location.search).get("reason") ===
    "session_refreshed",
);

const form = reactive({
  email: "",
  password: "",
  tenant_slug: detectedSlug.value,
});

async function handleLogin() {
  error.value = "";
  loading.value = true;
  try {
    const result = await authStore.tenantLogin({
      email: form.email,
      password: form.password,
      tenant_slug: form.tenant_slug,
    });

    if (!result.success) {
      if (result.passwordResetRequired) {
        router.push("/reset-password");
        return;
      }
      error.value = result.error ?? "Login failed.";
      return;
    }

    if (result.passwordResetRequired) {
      router.push("/reset-password");
      return;
    }

    if (result.isEmployee) {
      router.push("/employee/dashboard");
      return;
    }

    router.push("/dashboard");
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="tl-wrap">
    <div class="tl-left">
      <div class="tl-left-inner">
        <div class="tl-brand">
          <img src="/images/logos/NICO-White.png" width="150" alt="logo" />
        </div>
        <div class="tl-hero">
          <h2 class="tl-hero-title">Payroll that<br />just works.</h2>
          <p class="tl-hero-sub">
            Multi-country. Statutory-accurate. Beautiful payslips.
          </p>
        </div>
        <ul class="tl-features">
          <li>
            <span class="tl-feat-icon">✓</span> PAYE auto-calculation per
            country
          </li>
          <li>
            <span class="tl-feat-icon">✓</span> Pension &amp; health levy
            compliant
          </li>
          <li>
            <span class="tl-feat-icon">✓</span> One-click PDF payslips &amp;
            bank export
          </li>
          <li>
            <span class="tl-feat-icon">✓</span> Full employee onboarding module
          </li>
        </ul>
        <div class="tl-left-deco">
          <div class="tl-deco-ring tl-deco-ring--1"></div>
          <div class="tl-deco-ring tl-deco-ring--2"></div>
          <div class="tl-deco-ring tl-deco-ring--3"></div>
        </div>
      </div>
    </div>

    <div class="tl-right">
      <div class="tl-form-wrap">
        <div v-if="isTenantDomain" class="tl-tenant-chip">
          <i class="bx bx-building"></i>
          <span class="text-capitalize">{{ detectedSlug }}</span>
        </div>

        <h1 class="tl-title">Welcome back</h1>
        <p class="tl-sub">Sign in to your payroll dashboard</p>

        <div v-if="sessionRefreshed" class="tl-notice">
          <i class="ri-refresh-line"></i>
          Your account permissions have been updated. Please sign in again.
        </div>

        <div v-if="error" class="tl-error">
          <i class="ri-error-warning-line"></i> {{ error }}
        </div>

        <form @submit.prevent="handleLogin" class="tl-form">
          <div v-if="!isTenantDomain" class="tl-field">
            <label class="tl-label">Organisation Slug</label>
            <div class="tl-input-wrap">
              <i class="ri-building-line tl-icon-left"></i>
              <input
                v-model="form.tenant_slug"
                type="text"
                class="tl-input tl-input--left-icon"
                placeholder="acme-corp"
                autocomplete="organization"
                required
              />
            </div>
          </div>

          <div class="tl-field">
            <label class="tl-label">Email</label>
            <div class="tl-input-wrap">
              <i class="ri-mail-line tl-icon-left"></i>
              <input
                v-model="form.email"
                type="email"
                class="tl-input tl-input--left-icon"
                placeholder="you@company.com"
                autocomplete="username"
                required
              />
            </div>
          </div>

          <div class="tl-field">
            <div class="tl-label-row">
              <label class="tl-label">Password</label>
              <a href="#" class="tl-forgot">Forgot password?</a>
            </div>
            <div class="tl-input-wrap">
              <i class="ri-lock-line tl-icon-left"></i>
              <input
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                class="tl-input tl-input--both-icons"
                placeholder="••••••••"
                autocomplete="current-password"
                required
              />
              <button
                type="button"
                class="tl-toggle-pw"
                @click="showPassword = !showPassword"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                tabindex="-1"
              >
                <i
                  :class="showPassword ? 'ri-eye-off-line' : 'ri-eye-line'"
                ></i>
              </button>
            </div>
          </div>

          <button type="submit" class="tl-btn" :disabled="loading">
            <span v-if="loading" class="tl-spinner"></span>
            <span v-else>Sign in</span>
          </button>
        </form>

        <p class="tl-admin-hint">
          System administrator?
          <a href="/system-login" class="tl-admin-link">Admin portal →</a>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap");

.tl-wrap {
  --primary: #273775;
  --primary-dark: #1e2f68;
  --primary-darker: #15214d;
  --primary-light: #4f63b3;
  --primary-soft: rgba(39, 55, 117, 0.12);
  display: flex;
  min-height: 100vh;
  font-family: "Outfit", sans-serif;
}
.tl-wrap *,
.tl-wrap *::before,
.tl-wrap *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}
.tl-left {
  width: 42%;
  flex-shrink: 0;
  background: linear-gradient(
    160deg,
    var(--primary-darker) 0%,
    var(--primary-dark) 50%,
    var(--primary) 100%
  );
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tl-left-inner {
  position: relative;
  z-index: 1;
  padding: 48px;
  max-width: 380px;
}
.tl-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 48px;
}
.tl-hero {
  margin-bottom: 36px;
}
.tl-hero-title {
  font-size: 40px;
  font-weight: 700;
  color: #fff;
  line-height: 1.15;
  margin-bottom: 12px;
}
.tl-hero-sub {
  font-size: 16px;
  color: rgba(255, 255, 255, 0.7);
}
.tl-features {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.tl-features li {
  color: rgba(255, 255, 255, 0.85);
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.tl-feat-icon {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  color: var(--primary-light);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
  flex-shrink: 0;
}
.tl-left-deco {
  position: absolute;
  bottom: -60px;
  right: -60px;
}
.tl-deco-ring {
  position: absolute;
  border-radius: 50%;
  border: 1px solid rgba(255, 255, 255, 0.1);
}
.tl-deco-ring--1 {
  width: 280px;
  height: 280px;
  top: -140px;
  left: -140px;
}
.tl-deco-ring--2 {
  width: 420px;
  height: 420px;
  top: -210px;
  left: -210px;
}
.tl-deco-ring--3 {
  width: 560px;
  height: 560px;
  top: -280px;
  left: -280px;
}
.tl-right {
  flex: 1;
  background: #f9fafb;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 24px;
}
.tl-form-wrap {
  width: 100%;
  max-width: 400px;
}
.tl-tenant-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--primary-soft);
  color: var(--primary-darker);
  border: 1px solid var(--primary-light);
  border-radius: 20px;
  padding: 5px 14px;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 24px;
}
.tl-title {
  font-size: 28px;
  font-weight: 700;
  color: #111827;
  margin-bottom: 6px;
}
.tl-sub {
  font-size: 15px;
  color: #9ca3af;
  margin-bottom: 32px;
}
.tl-notice {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  color: #1d4ed8;
  border-radius: 10px;
  padding: 11px 14px;
  font-size: 13px;
  margin-bottom: 20px;
}
.tl-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  border-radius: 10px;
  padding: 11px 14px;
  font-size: 13px;
  margin-bottom: 20px;
}
.tl-form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}
.tl-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.tl-label-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.tl-label {
  font-size: 13px;
  font-weight: 500;
  color: #374151;
}
.tl-forgot {
  font-size: 12px;
  color: var(--primary);
  text-decoration: none;
}
.tl-forgot:hover {
  text-decoration: underline;
}
.tl-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.tl-icon-left {
  position: absolute;
  left: 13px;
  color: #9ca3af;
  font-size: 16px;
  pointer-events: none;
  z-index: 1;
}
.tl-input {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  font-size: 14px;
  font-family: "Outfit", sans-serif;
  background: #fff;
  outline: none;
  color: #111827;
  transition:
    border-color 0.2s,
    box-shadow 0.2s;
}
.tl-input--left-icon {
  padding-left: 40px;
}
.tl-input--both-icons {
  padding-left: 40px;
  padding-right: 44px;
}
.tl-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-soft);
}
.tl-input::placeholder {
  color: #d1d5db;
}
.tl-toggle-pw {
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  cursor: pointer;
  color: #9ca3af;
  font-size: 16px;
  border-radius: 0 10px 10px 0;
  transition: color 0.15s;
}
.tl-toggle-pw:hover {
  color: var(--primary);
}
.tl-toggle-pw:focus {
  outline: none;
}
.tl-btn {
  width: 100%;
  padding: 13px;
  background: linear-gradient(135deg, var(--primary), var(--primary-dark));
  color: #fff;
  border: none;
  border-radius: 10px;
  font-size: 15px;
  font-weight: 600;
  font-family: "Outfit", sans-serif;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    opacity 0.2s,
    transform 0.1s;
}
.tl-btn:hover:not(:disabled) {
  opacity: 0.9;
}
.tl-btn:active:not(:disabled) {
  transform: scale(0.98);
}
.tl-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.tl-spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: tl-spin 0.7s linear infinite;
}
@keyframes tl-spin {
  to {
    transform: rotate(360deg);
  }
}
.tl-admin-hint {
  margin-top: 28px;
  text-align: center;
  font-size: 13px;
  color: #9ca3af;
}
.tl-admin-link {
  color: var(--primary);
  text-decoration: none;
  font-weight: 500;
}
.tl-admin-link:hover {
  text-decoration: underline;
}
@media (max-width: 768px) {
  .tl-left {
    display: none;
  }
  .tl-right {
    background: #fff;
  }
}
</style>
