<script setup lang="ts">
// S11 アカウント（08 §5.12）：パスワード変更・端末名・ホーム画面に追加の案内
import { ref } from 'vue'
import { updatePassword } from '@/api/auth'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { DEVICE_NAME_MAX, loadDeviceName, saveDeviceName } from '@/lib/deviceName'
import { isIos, isStandalone } from '@/lib/platform'

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const passwordDone = ref(false)
const passwordFailed = ref<string | null>(null)

async function changePassword(): Promise<void> {
  if (saving.value) return
  saving.value = true
  errors.value = {}
  passwordDone.value = false
  passwordFailed.value = null
  try {
    await updatePassword({
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: confirmPassword.value,
    })
    currentPassword.value = ''
    newPassword.value = ''
    confirmPassword.value = ''
    passwordDone.value = true
  } catch (err) {
    if (errorStatus(err) === 422) {
      errors.value = fieldErrors(err)
    } else if (!isNetworkError(err)) {
      passwordFailed.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    saving.value = false
  }
}

const deviceName = ref(loadDeviceName())
const deviceMessage = ref<string | null>(null)
function saveDevice(): void {
  deviceMessage.value = saveDeviceName(deviceName.value) ? ja.account.deviceSaved : ja.account.deviceUnavailable
  deviceName.value = loadDeviceName()
}

const showInstall = isIos() && !isStandalone()
</script>

<template>
  <div class="page">
    <AppHeader :title="ja.account.title" />
    <main class="page__body">
      <section class="panel r-card">
        <h2 class="panel__title r-h2">
          {{ ja.account.passwordHeading }}
        </h2>
        <form
          class="form"
          novalidate
          @submit.prevent="changePassword"
        >
          <!-- パスワード管理ソフトのための ID 欄（画面には出さない） -->
          <input
            type="text"
            name="username"
            autocomplete="username"
            class="visually-hidden"
            tabindex="-1"
            aria-hidden="true"
          >
          <div class="field r-field">
            <label
              for="current-password"
              class="field__label r-label"
            >{{ ja.account.currentPassword }}</label>
            <input
              id="current-password"
              v-model="currentPassword"
              class="field__input r-input"
              type="password"
              autocomplete="current-password"
              maxlength="255"
              :aria-invalid="errors.current_password ? 'true' : undefined"
              :aria-describedby="errors.current_password ? 'current-password-error' : undefined"
            >
            <p
              v-if="errors.current_password"
              id="current-password-error"
              class="field__error adm-error"
            >
              {{ errors.current_password }}
            </p>
          </div>
          <div class="field r-field">
            <label
              for="new-password"
              class="field__label r-label"
            >{{ ja.account.newPassword }}</label>
            <input
              id="new-password"
              v-model="newPassword"
              class="field__input r-input"
              type="password"
              autocomplete="new-password"
              maxlength="72"
              :aria-invalid="errors.password ? 'true' : undefined"
              :aria-describedby="errors.password ? 'new-password-error' : undefined"
            >
            <p
              v-if="errors.password"
              id="new-password-error"
              class="field__error adm-error"
            >
              {{ errors.password }}
            </p>
          </div>
          <div class="field r-field">
            <label
              for="confirm-password"
              class="field__label r-label"
            >{{ ja.account.confirmPassword }}</label>
            <input
              id="confirm-password"
              v-model="confirmPassword"
              class="field__input r-input"
              type="password"
              autocomplete="new-password"
              maxlength="72"
            >
          </div>
          <p
            v-if="passwordDone"
            class="form__ok adm-ok"
            role="status"
          >
            {{ ja.account.passwordChanged }}
          </p>
          <p
            v-if="passwordFailed"
            class="field__error adm-error"
            role="alert"
          >
            {{ passwordFailed }}
          </p>
          <BigButton
            type="submit"
            :loading="saving"
          >
            {{ ja.account.changePassword }}
          </BigButton>
        </form>
      </section>

      <section
        id="device"
        class="panel r-card"
      >
        <h2 class="panel__title r-h2">
          {{ ja.account.deviceHeading }}
        </h2>
        <form
          class="form"
          @submit.prevent="saveDevice"
        >
          <div class="field r-field">
            <label
              for="device-name"
              class="field__label r-label"
            >{{ ja.account.deviceHeading }}</label>
            <input
              id="device-name"
              v-model="deviceName"
              class="field__input r-input"
              type="text"
              autocomplete="off"
              :maxlength="DEVICE_NAME_MAX"
              aria-describedby="device-help"
            >
            <p
              id="device-help"
              class="field__help"
            >
              {{ ja.account.deviceHelp }}
            </p>
          </div>
          <p
            v-if="deviceMessage"
            class="form__ok adm-ok"
            role="status"
          >
            {{ deviceMessage }}
          </p>
          <BigButton
            type="submit"
            variant="secondary"
          >
            {{ ja.common.save }}
          </BigButton>
        </form>
      </section>

      <section
        v-if="showInstall"
        class="panel r-card"
      >
        <h2 class="panel__title r-h2">
          {{ ja.account.installHeading }}
        </h2>
        <p>{{ ja.account.installSteps }}</p>
      </section>
    </main>
  </div>
</template>

<style scoped>
.page { min-height: 100dvh; background: var(--c-surface-alt); }

.page__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 560px;
  padding: 16px calc(16px + var(--safe-right)) calc(32px + var(--safe-bottom)) calc(16px + var(--safe-left));
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 20px;
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.form { display: flex; flex-direction: column; gap: 16px; align-items: stretch; }
.field { width: 100%; }
.field__help { color: var(--c-text-sub); font-size: 15px; }
.field__error { font-weight: 700; }
.form__ok { font-weight: 700; }

@media (min-width: 768px) {
  .page__body { padding: 20px 32px calc(32px + var(--safe-bottom)) 32px; }
}
</style>
