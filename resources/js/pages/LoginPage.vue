<script setup lang="ts">
// S01 ログイン（08 §5.1）。端末の店舗に勤務中の人がいれば、その人への切替も出す（13 §7）
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import OperatorPicker from '@/components/OperatorPicker.vue'
import WaveBackground from '@/components/WaveBackground.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError, retryAfterSeconds } from '@/lib/apiError'
import { safeRedirect } from '@/lib/redirect'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const loginId = ref('')
const password = ref('')
const remember = ref(false)
const showPassword = ref(false)
const submitting = ref(false)
const errors = ref<Record<string, string>>({})
const passwordInput = ref<HTMLInputElement | null>(null)

// 回数制限（AC-S01-3）：残り秒数を数え、その間ボタンを無効にする
const waitSec = ref(0)
let timer: ReturnType<typeof setInterval> | undefined
function startWait(sec: number): void {
  waitSec.value = sec
  clearInterval(timer)
  timer = setInterval(() => {
    waitSec.value -= 1
    if (waitSec.value <= 0) clearInterval(timer)
  }, 1000)
}
onBeforeUnmount(() => clearInterval(timer))

const message = computed(() => (waitSec.value > 0 ? fmt(ja.login.throttled, { sec: waitSec.value }) : ui.loginNotice))

async function afterSwitch(): Promise<void> {
  ui.loginNotice = null
  await router.replace(safeRedirect(route.query.redirect) ?? auth.homeRoute)
}

async function submit(): Promise<void> {
  if (submitting.value || waitSec.value > 0) return
  submitting.value = true
  errors.value = {}
  ui.loginNotice = null
  try {
    await auth.login({ login_id: loginId.value.trim(), password: password.value, remember: remember.value })
    await router.replace(safeRedirect(route.query.redirect) ?? auth.homeRoute)
  } catch (err) {
    const status = errorStatus(err)
    if (status === 422) {
      errors.value = fieldErrors(err)
      password.value = ''
      passwordInput.value?.focus()
    } else if (status === 429) {
      startWait(retryAfterSeconds(err))
    } else if (status === 403) {
      // 停止中の店舗・アカウント（AC-S01-4）。client の停止時の処理が loginNotice を設定済み
      ui.loginNotice ??= errorBody(err)?.message ?? ja.error.unexpected
    } else if (!isNetworkError(err)) {
      ui.loginNotice = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="login home">
    <WaveBackground />
    <main class="home__in login__main">
      <h1 class="home__title login__title">
        {{ ja.app.title }}
      </h1>
      <form
        class="login-card r-card"
        novalidate
        @submit.prevent="submit"
      >
        <div class="r-card__body login-card__body">
          <div
            v-if="message"
            class="r-banner r-banner--danger"
            role="alert"
          >
            <AppIcon
              name="alert"
              :size="24"
            />
            <span class="r-banner__d">{{ message }}</span>
          </div>

          <div class="field r-field">
            <label
              for="login-id"
              class="field__label r-label"
            >{{ ja.login.loginId }}</label>
            <input
              id="login-id"
              v-model="loginId"
              class="field__input r-input"
              type="text"
              name="username"
              autocomplete="username"
              autocapitalize="none"
              autocorrect="off"
              spellcheck="false"
              maxlength="50"
              :aria-invalid="errors.login_id ? 'true' : undefined"
              :aria-describedby="errors.login_id ? 'login-error' : undefined"
            >
          </div>

          <div class="field r-field">
            <label
              for="password"
              class="field__label r-label"
            >{{ ja.login.password }}</label>
            <div class="field__row">
              <input
                id="password"
                ref="passwordInput"
                v-model="password"
                class="field__input r-input"
                :type="showPassword ? 'text' : 'password'"
                name="password"
                autocomplete="current-password"
                maxlength="255"
                :aria-invalid="errors.login_id || errors.password ? 'true' : undefined"
                :aria-describedby="errors.login_id || errors.password ? 'login-error' : undefined"
              >
              <button
                type="button"
                class="field__toggle r-btn r-btn--secondary"
                :aria-pressed="showPassword"
                @click="showPassword = !showPassword"
              >
                {{ showPassword ? ja.login.hidePassword : ja.login.showPassword }}
              </button>
            </div>
            <p
              v-if="errors.login_id || errors.password"
              id="login-error"
              class="field__error r-err"
            >
              <AppIcon
                name="alert"
                :size="18"
              />
              <span>{{ errors.login_id ?? errors.password }}</span>
            </p>
          </div>

          <label class="check">
            <input
              v-model="remember"
              type="checkbox"
              class="check__box"
            >
            <span>{{ ja.login.remember }}</span>
          </label>

          <BigButton
            type="submit"
            size="xl"
            block
            :loading="submitting"
            :disabled="waitSec > 0"
          >
            {{ ja.login.submit }}
          </BigButton>
        </div>
      </form>
      <div class="login-card login-card--operators r-card">
        <div class="r-card__body">
          <OperatorPicker
            hide-when-empty
            :heading="ja.loginOperators.heading"
            :help="ja.loginOperators.help"
            @switched="afterSwitch"
          />
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.login__main {
  align-items: center;
  gap: 20px;
  padding-top: calc(48px + var(--safe-top));
}

.login-card { width: 100%; max-width: 420px; }
.login-card__body { gap: 16px; }
.login-card--operators:not(:has(.op-pick)) { display: none; }

.field__row { display: flex; gap: 8px; }
.field__toggle { flex: none; min-width: 72px; }

.check {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: var(--tap-min);
  cursor: pointer;
}

.check__box {
  width: 28px;
  height: 28px;
  margin: 0;
  accent-color: var(--c-primary);
}

@media (min-width: 768px) {
  .login__main { gap: 32px; padding-top: calc(64px + var(--safe-top)); }
}
</style>
