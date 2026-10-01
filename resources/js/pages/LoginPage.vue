<script setup lang="ts">
// S01 ログイン（08 §5.1）。端末の店舗に勤務中の人がいれば、その人への切替も出す（13 §7）
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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
  <div class="login">
    <WaveBackground />
    <main class="login__main">
      <h1 class="login__title">
        {{ ja.app.title }}
      </h1>
      <form
        class="login-card"
        novalidate
        @submit.prevent="submit"
      >
        <p
          v-if="message"
          class="login-card__notice"
          role="alert"
        >
          {{ message }}
        </p>

        <div class="field">
          <label
            for="login-id"
            class="field__label"
          >{{ ja.login.loginId }}</label>
          <input
            id="login-id"
            v-model="loginId"
            class="field__input"
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

        <div class="field">
          <label
            for="password"
            class="field__label"
          >{{ ja.login.password }}</label>
          <div class="field__row">
            <input
              id="password"
              ref="passwordInput"
              v-model="password"
              class="field__input"
              :type="showPassword ? 'text' : 'password'"
              name="password"
              autocomplete="current-password"
              maxlength="255"
              :aria-invalid="errors.login_id || errors.password ? 'true' : undefined"
              :aria-describedby="errors.login_id || errors.password ? 'login-error' : undefined"
            >
            <button
              type="button"
              class="field__toggle"
              :aria-pressed="showPassword"
              @click="showPassword = !showPassword"
            >
              {{ showPassword ? ja.login.hidePassword : ja.login.showPassword }}
            </button>
          </div>
          <p
            v-if="errors.login_id || errors.password"
            id="login-error"
            class="field__error"
          >
            {{ errors.login_id ?? errors.password }}
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
          size="lg"
          block
          :loading="submitting"
          :disabled="waitSec > 0"
        >
          {{ ja.login.submit }}
        </BigButton>
      </form>
      <div class="login-card login-card--operators">
        <OperatorPicker
          hide-when-empty
          :heading="ja.loginOperators.heading"
          :help="ja.loginOperators.help"
          @switched="afterSwitch"
        />
      </div>
    </main>
  </div>
</template>

<style scoped>
.login {
  min-height: 100dvh;
  padding: calc(24px + var(--safe-top)) calc(var(--gutter) + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(var(--gutter) + var(--safe-left));
}

.login__main {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24px;
  width: 100%;
}

.login__title {
  color: var(--c-on-primary);
  font-size: var(--fs-home-title);
  font-weight: 800;
  line-height: 1.1;
}

.login-card {
  display: flex;
  flex-direction: column;
  gap: 20px;
  width: 100%;
  max-width: 420px;
  padding: 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
  box-shadow: 0 8px 32px rgba(15, 46, 122, 0.25);
}

.login-card--operators:not(:has(.op-pick)) { display: none; }

.login-card__notice {
  padding: 12px 16px;
  border-left: 4px solid var(--c-danger);
  border-radius: 8px;
  background: var(--c-surface-alt);
  color: var(--c-danger-press);
  font-weight: 700;
}

.field { display: flex; flex-direction: column; gap: 6px; }
.field__label { font-weight: 700; }
.field__row { display: flex; gap: 8px; }

.field__input {
  flex: 1;
  min-width: 0;
  min-height: var(--btn-h);
  padding: 0 14px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 18px;
}

.field__input:focus { border-color: var(--c-focus); outline: none; }
.field__input[aria-invalid='true'] { border-color: var(--c-danger); }

.field__toggle {
  flex-shrink: 0;
  min-width: 72px;
  min-height: var(--btn-h);
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface-alt);
  font-weight: 700;
}

.field__error {
  color: var(--c-danger);
  font-weight: 700;
}

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
  .login { padding-top: calc(64px + var(--safe-top)); }
  .login__main { gap: 40px; }
  .login-card { padding: 32px; }
}
</style>
