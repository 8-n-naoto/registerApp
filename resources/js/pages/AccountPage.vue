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
      <section class="panel">
        <h2 class="panel__title">
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
          <div class="field">
            <label
              for="current-password"
              class="field__label"
            >{{ ja.account.currentPassword }}</label>
            <input
              id="current-password"
              v-model="currentPassword"
              class="field__input"
              type="password"
              autocomplete="current-password"
              maxlength="255"
              :aria-invalid="errors.current_password ? 'true' : undefined"
              :aria-describedby="errors.current_password ? 'current-password-error' : undefined"
            >
            <p
              v-if="errors.current_password"
              id="current-password-error"
              class="field__error"
            >
              {{ errors.current_password }}
            </p>
          </div>
          <div class="field">
            <label
              for="new-password"
              class="field__label"
            >{{ ja.account.newPassword }}</label>
            <input
              id="new-password"
              v-model="newPassword"
              class="field__input"
              type="password"
              autocomplete="new-password"
              maxlength="72"
              :aria-invalid="errors.password ? 'true' : undefined"
              :aria-describedby="errors.password ? 'new-password-error' : undefined"
            >
            <p
              v-if="errors.password"
              id="new-password-error"
              class="field__error"
            >
              {{ errors.password }}
            </p>
          </div>
          <div class="field">
            <label
              for="confirm-password"
              class="field__label"
            >{{ ja.account.confirmPassword }}</label>
            <input
              id="confirm-password"
              v-model="confirmPassword"
              class="field__input"
              type="password"
              autocomplete="new-password"
              maxlength="72"
            >
          </div>
          <p
            v-if="passwordDone"
            class="form__ok"
            role="status"
          >
            {{ ja.account.passwordChanged }}
          </p>
          <p
            v-if="passwordFailed"
            class="field__error"
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
        class="panel"
      >
        <h2 class="panel__title">
          {{ ja.account.deviceHeading }}
        </h2>
        <form
          class="form"
          @submit.prevent="saveDevice"
        >
          <div class="field">
            <label
              for="device-name"
              class="field__label"
            >{{ ja.account.deviceHeading }}</label>
            <input
              id="device-name"
              v-model="deviceName"
              class="field__input"
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
            class="form__ok"
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
        class="panel"
      >
        <h2 class="panel__title">
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
  gap: 24px;
  max-width: 640px;
  margin: 0 auto;
  padding: 24px calc(var(--gutter) + var(--safe-right)) calc(32px + var(--safe-bottom)) calc(var(--gutter) + var(--safe-left));
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 24px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius-card);
  background: var(--c-surface);
  scroll-margin-top: calc(var(--header-h) + 16px);
}

.panel__title { font-size: var(--fs-heading); }

.form { display: flex; flex-direction: column; gap: 16px; align-items: flex-start; }
.field { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.field__label { font-weight: 700; }

.field__input {
  min-height: var(--btn-h);
  padding: 0 14px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 18px;
}

.field__input:focus { border-color: var(--c-focus); outline: none; }
.field__input[aria-invalid='true'] { border-color: var(--c-danger); }
.field__help { color: var(--c-text-sub); font-size: 16px; }
.field__error { color: var(--c-danger); font-weight: 700; }
.form__ok { color: var(--c-success); font-weight: 700; }

@media (min-width: 768px) {
  .page__body { padding-top: 32px; padding-left: 32px; padding-right: 32px; }
}
</style>
