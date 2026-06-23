<script setup>
import { ref, reactive } from "vue";
import { useRouter } from "vue-router";
import { useUserStore } from "@/stores/user";

const router = useRouter();
const authStore = useUserStore();

const loading = ref(false);
const error = ref("");

const form = reactive({
  email: "",
  password: "",
});

async function handleLogin() {
  error.value = "";
  loading.value = true;

  try {
    const result = await authStore.systemLogin({
      email: form.email,
      password: form.password,
    });

    if (!result.success) {
      if (result.locked) {
        error.value = `Account locked. Try again in ${result.minutesLeft} minute(s).`;
        return;
      }
      if (result.passwordResetRequired) {
        router.push("/reset-password");
        return;
      }
      if (result.redirectTo) {
        router.push(result.redirectTo);
        return;
      }
      error.value = result.error ?? "Login failed.";
      return;
    }

    router.push({ name: "dashboard" });
  } catch (err) {
    error.value = err?.response?.data?.message ?? "Login failed.";
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="sa-login-wrap">
    <div class="sa-bg">
      <div class="sa-bg-grid"></div>
      <div class="sa-bg-orb sa-bg-orb--1"></div>
      <div class="sa-bg-orb sa-bg-orb--2"></div>
    </div>

    <div class="sa-card">
      <div class="sa-brand">
        <img src="/images/logos/NICO-Tech.png" width="150" alt="logo" />
      </div>

      <h1 class="sa-title">Super Admin Portal</h1>
      <p class="sa-subtitle">Restricted access — authorised personnel only</p>

      <div v-if="error" class="sa-error">
        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
          <path
            fill-rule="evenodd"
            d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
            clip-rule="evenodd"
          />
        </svg>
        {{ error }}
      </div>

      <form @submit.prevent="handleLogin" class="sa-form">
        <div class="sa-field">
          <label class="sa-label">Email Address</label>
          <input
            v-model="form.email"
            type="email"
            class="sa-input"
            placeholder="admin@nico.io"
            autocomplete="username"
            required
          />
        </div>
        <div class="sa-field">
          <label class="sa-label">Password</label>
          <input
            v-model="form.password"
            type="password"
            class="sa-input"
            placeholder="••••••••••••"
            autocomplete="current-password"
            required
          />
        </div>
        <button type="submit" class="sa-btn" :disabled="loading">
          <span v-if="loading" class="sa-spinner"></span>
          <span v-else>Access Admin Panel</span>
        </button>
      </form>

      <div class="sa-back">
        <a href="/login" class="sa-back-link">← Back to tenant login</a>
      </div>
    </div>
  </div>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap");

.sa-login-wrap {
  --primary: #273775;
  --primary-dark: #1f2c5f;
  --primary-darker: #172147;
  --primary-light: #4f63b3;
  --primary-soft: rgba(39, 55, 117, 0.08);
  --primary-glow: rgba(39, 55, 117, 0.15);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 50%, #eef2ff 100%);
  font-family: "DM Sans", sans-serif;
  position: relative;
  overflow: hidden;
  padding: 24px;
}
.sa-bg {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.sa-bg-grid {
  position: absolute;
  inset: 0;
  background-image: linear-gradient(
      rgba(39, 55, 117, 0.04) 1px,
      transparent 1px
    ),
    linear-gradient(90deg, rgba(39, 55, 117, 0.04) 1px, transparent 1px);
  background-size: 48px 48px;
}
.sa-bg-orb {
  position: absolute;
  border-radius: 50%;
  filter: blur(90px);
}
.sa-bg-orb--1 {
  width: 480px;
  height: 480px;
  background: rgba(39, 55, 117, 0.12);
  top: -120px;
  right: -80px;
}
.sa-bg-orb--2 {
  width: 360px;
  height: 360px;
  background: rgba(79, 99, 179, 0.12);
  bottom: -80px;
  left: -60px;
}
.sa-card {
  position: relative;
  z-index: 1;
  background: #fff;
  border: 1px solid rgba(39, 55, 117, 0.08);
  border-radius: 18px;
  padding: 40px 44px;
  width: 100%;
  max-width: 440px;
  box-shadow:
    0 20px 40px rgba(15, 23, 42, 0.08),
    0 4px 12px rgba(15, 23, 42, 0.04);
}
.sa-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 28px;
}
.sa-title {
  font-size: 24px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 6px;
}
.sa-subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0 0 28px;
}
.sa-error {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 20px;
}
.sa-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.sa-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.sa-label {
  font-size: 12px;
  color: #4b5563;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.sa-input {
  background: #fff;
  border: 1px solid #dbe3f0;
  border-radius: 10px;
  padding: 12px 14px;
  font-size: 14px;
  color: #111827;
  font-family: "DM Sans", sans-serif;
  outline: none;
  transition:
    border-color 0.2s,
    box-shadow 0.2s;
}
.sa-input::placeholder {
  color: #9ca3af;
}
.sa-input:hover {
  border-color: #c7d2fe;
}
.sa-input:focus {
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-glow);
}
.sa-btn {
  margin-top: 4px;
  background: linear-gradient(135deg, var(--primary-dark), var(--primary));
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 13px;
  font-size: 14px;
  font-weight: 600;
  font-family: "DM Sans", sans-serif;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition:
    transform 0.15s,
    box-shadow 0.2s,
    opacity 0.2s;
}
.sa-btn:hover:not(:disabled) {
  box-shadow: 0 10px 20px rgba(39, 55, 117, 0.25);
  transform: translateY(-1px);
}
.sa-btn:active:not(:disabled) {
  transform: scale(0.98);
}
.sa-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.sa-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: sa-spin 0.7s linear infinite;
}
@keyframes sa-spin {
  to {
    transform: rotate(360deg);
  }
}
.sa-back {
  margin-top: 24px;
  text-align: center;
}
.sa-back-link {
  font-size: 13px;
  color: var(--primary);
  text-decoration: none;
  font-weight: 500;
}
.sa-back-link:hover {
  text-decoration: underline;
}
@media (max-width: 480px) {
  .sa-card {
    padding: 28px 24px;
  }
  .sa-title {
    font-size: 22px;
  }
}
</style>
