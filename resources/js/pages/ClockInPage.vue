<script setup lang="ts">
// S22 出勤（13 §6.1・§7）：ログインしたままの端末を勤務中でない人が開いたとき。パスワードで出勤、または勤務中の人に切替
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
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
  <div class="clock home">
    <WaveBackground />
    <main class="home__in clock__main">
      <h1 class="home__title clock__title">
        {{ ja.clockIn.title }}
      </h1>
      <form
        class="clock-card r-card"
        novalidate
        @submit.prevent="submit"
      >
        <div class="r-card__body clock-card__body">
          <p class="clock-card__lead">
            {{ fmt(ja.clockIn.notWorking, { name }) }}
          </p>
          <p class="clock-card__help r-help">
            {{ ja.clockIn.help }}
          </p>
          <div class="clock-card__field r-field">
            <label
              for="clock-in-password"
              class="clock-card__label r-label"
            >{{ ja.login.password }}</label>
            <input
              id="clock-in-password"
              ref="passwordInput"
              v-model="password"
              class="clock-card__input r-input"
              type="password"
              autocomplete="current-password"
              maxlength="255"
              :aria-invalid="error ? 'true' : undefined"
              :aria-describedby="error ? 'clock-in-error' : undefined"
            >
            <p
              v-if="error"
              id="clock-in-error"
              class="clock-card__error r-err"
              role="alert"
            >
              <AppIcon
                name="alert"
                :size="18"
              />
              <span>{{ error }}</span>
            </p>
          </div>
          <BigButton
            type="submit"
            size="xl"
            block
            :loading="submitting"
          >
            {{ ja.clockIn.submit }}
          </BigButton>
          <button
            type="button"
            class="clock-card__other r-btn r-btn--plain"
            @click="otherLogin"
          >
            {{ ja.clockIn.otherLogin }}
          </button>
        </div>
      </form>
      <div class="clock-card r-card">
        <div class="r-card__body">
          <OperatorPicker
            :exclude-id="auth.me?.user.id ?? null"
            :heading="ja.clockIn.switchHeading"
            @switched="next"
          />
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.clock__main {
  align-items: center;
  gap: 20px;
  padding-top: calc(48px + var(--safe-top));
}

.clock-card { width: 100%; max-width: 420px; }
.clock-card__body { gap: 16px; }
.clock-card__lead { margin: 0; font-size: 20px; font-weight: 700; overflow-wrap: anywhere; }
.clock-card__help { margin: 0; }

@media (min-width: 768px) {
  .clock__main { gap: 32px; padding-top: calc(64px + var(--safe-top)); }
}
</style>
