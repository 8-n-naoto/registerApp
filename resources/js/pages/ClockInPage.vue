<script setup lang="ts">
// S22 出勤（13 §6.1・§7）：ログインしたままの端末を勤務中でない人が開いたとき。パスワードで出勤、または勤務中の人に切替
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BigButton from '@/components/BigButton.vue'
import OperatorPicker from '@/components/OperatorPicker.vue'
import WaveBackground from '@/components/WaveBackground.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError, retryAfterSeconds } from '@/lib/apiError'
import { safeRedirect } from '@/lib/redirect'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)
const passwordInput = ref<HTMLInputElement | null>(null)

const name = computed(() => auth.me?.user.name ?? '')

async function next(): Promise<void> {
  await router.replace(safeRedirect(route.query.redirect) ?? auth.homeRoute)
}

async function submit(): Promise<void> {
  if (submitting.value) return
  submitting.value = true
  error.value = null
  try {
    await auth.clockIn(password.value)
    await next()
  } catch (err) {
    const status = errorStatus(err)
    if (status === 422) {
      error.value = fieldErrors(err).password ?? ja.error.login
      password.value = ''
      passwordInput.value?.focus()
    } else if (status === 409) {
      // 別の端末で出勤済み
      await auth.refreshMe().catch(() => undefined)
      if (!auth.needsClockIn) await next()
    } else if (status === 429) {
      error.value = fmt(ja.login.throttled, { sec: retryAfterSeconds(err) })
    } else if (!isNetworkError(err)) {
      error.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    submitting.value = false
  }
}

async function otherLogin(): Promise<void> {
  try {
    await auth.logout()
  } catch {
    // 通信できなくても端末側はログアウト扱いにする
  }
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="clock">
    <WaveBackground />
    <main class="clock__main">
      <h1 class="clock__title">
        {{ ja.clockIn.title }}
      </h1>
      <form
        class="clock-card"
        novalidate
        @submit.prevent="submit"
      >
        <p class="clock-card__lead">
          {{ fmt(ja.clockIn.notWorking, { name }) }}
        </p>
        <p class="clock-card__help">
          {{ ja.clockIn.help }}
        </p>
        <div class="clock-card__field">
          <label
            for="clock-in-password"
            class="clock-card__label"
          >{{ ja.login.password }}</label>
          <input
            id="clock-in-password"
            ref="passwordInput"
            v-model="password"
            class="clock-card__input"
            type="password"
            autocomplete="current-password"
            maxlength="255"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? 'clock-in-error' : undefined"
          >
          <p
            v-if="error"
            id="clock-in-error"
            class="clock-card__error"
            role="alert"
          >
            {{ error }}
          </p>
        </div>
        <BigButton
          type="submit"
          size="lg"
          block
          :loading="submitting"
        >
          {{ ja.clockIn.submit }}
        </BigButton>
        <button
          type="button"
          class="clock-card__other"
          @click="otherLogin"
        >
          {{ ja.clockIn.otherLogin }}
        </button>
      </form>
      <div class="clock-card">
        <OperatorPicker
          :exclude-id="auth.me?.user.id ?? null"
          :heading="ja.clockIn.switchHeading"
          @switched="next"
        />
      </div>
    </main>
  </div>
</template>

<style scoped>
.clock {
  min-height: 100dvh;
  padding: calc(24px + var(--safe-top)) calc(var(--gutter) + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(var(--gutter) + var(--safe-left));
}

.clock__main { display: flex; flex-direction: column; align-items: center; gap: 24px; width: 100%; }

.clock__title {
  color: var(--c-on-primary);
  font-size: var(--fs-home-title);
  font-weight: 800;
  line-height: 1.1;
}

.clock-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
  max-width: 420px;
  padding: 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
  box-shadow: 0 8px 32px rgba(15, 46, 122, 0.25);
}

.clock-card__lead { font-size: 20px; font-weight: 700; }
.clock-card__help { color: var(--c-text-sub); }
.clock-card__field { display: flex; flex-direction: column; gap: 6px; }
.clock-card__label { font-weight: 700; }

.clock-card__input {
  min-height: var(--btn-h);
  padding: 0 14px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 18px;
}

.clock-card__input:focus { border-color: var(--c-focus); outline: none; }
.clock-card__input[aria-invalid='true'] { border-color: var(--c-danger); }
.clock-card__error { color: var(--c-danger); font-weight: 700; }

.clock-card__other {
  min-height: var(--tap-min);
  border: 0;
  background: transparent;
  color: var(--c-primary);
  font-weight: 700;
  text-decoration: underline;
}

@media (min-width: 768px) {
  .clock { padding-top: calc(64px + var(--safe-top)); }
}
</style>
