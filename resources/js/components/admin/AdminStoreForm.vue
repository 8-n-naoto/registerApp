<script setup lang="ts">
// A01 店舗の追加（06 §11.4・08 §5.13）：店舗とオーナーを同時に作る。入力の制限は AdminStoreRequest と揃える
import { reactive, ref } from 'vue'
import { createAdminStore, type AdminStoreCreated } from '@/api/admin'
import BigButton from '@/components/BigButton.vue'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { isPasswordLengthValid, PASSWORD_MAX_BYTES } from '@/lib/password'
import '@/styles/admin.css'

const emit = defineEmits<{ created: [store: AdminStoreCreated]; cancel: [] }>()

const t = ja.admin

const form = reactive({ name: '', owner_name: '', owner_login_id: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)

/** 送る前に分かる誤りは画面で止める。パスワードは入力のまま比べる（前後の空白も値の一部） */
function validate(): Record<string, string> {
  const found: Record<string, string> = {}
  if (form.name.trim() === '') found.name = t.storeNameRequired
  if (form.owner_name.trim() === '') found.owner_name = t.ownerNameRequired
  if (!/^[A-Za-z0-9_.-]{3,50}$/.test(form.owner_login_id.trim())) found.owner_login_id = t.ownerLoginIdInvalid
  if (!isPasswordLengthValid(form.password)) found.password = ja.staff.passwordTooShort
  else if (form.password !== form.password_confirmation) found.password_confirmation = t.ownerPasswordMismatch
  return found
}

async function submit(): Promise<void> {
  if (saving.value) return
  failed.value = null
  errors.value = validate()
  if (Object.keys(errors.value).length > 0) return
  saving.value = true
  try {
    emit('created', await createAdminStore({
      ...form,
      name: form.name.trim(),
      owner_name: form.owner_name.trim(),
      owner_login_id: form.owner_login_id.trim(),
    }))
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else failed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? ja.error.unexpected)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form
    class="adm-form store-form"
    novalidate
    :aria-label="t.addTitle"
    @submit.prevent="submit"
  >
    <h2 class="store-form__title">
      {{ t.addTitle }}
    </h2>
    <p class="store-form__help r-help">
      {{ t.addHelp }}
    </p>
    <p
      v-if="failed"
      class="adm-error r-err"
      role="alert"
    >
      {{ failed }}
    </p>
    <div class="adm-field r-field">
      <label
        for="store-new-name"
        class="adm-field__label r-label"
      >{{ t.storeName }}</label>
      <input
        id="store-new-name"
        v-model="form.name"
        class="adm-input r-input"
        maxlength="100"
        autocomplete="off"
        :aria-invalid="errors.name ? 'true' : undefined"
      >
      <p
        v-if="errors.name"
        class="adm-error r-err"
      >
        {{ errors.name }}
      </p>
    </div>
    <div class="adm-field r-field">
      <label
        for="store-new-owner-name"
        class="adm-field__label r-label"
      >{{ t.ownerName }}</label>
      <input
        id="store-new-owner-name"
        v-model="form.owner_name"
        class="adm-input r-input"
        maxlength="50"
        autocomplete="off"
        :aria-invalid="errors.owner_name ? 'true' : undefined"
      >
      <p
        v-if="errors.owner_name"
        class="adm-error r-err"
      >
        {{ errors.owner_name }}
      </p>
    </div>
    <div class="adm-field r-field">
      <label
        for="store-new-owner-login"
        class="adm-field__label r-label"
      >{{ t.ownerLoginId }}</label>
      <input
        id="store-new-owner-login"
        v-model="form.owner_login_id"
        class="adm-input r-input"
        maxlength="50"
        autocomplete="off"
        autocapitalize="off"
        spellcheck="false"
        :aria-invalid="errors.owner_login_id ? 'true' : undefined"
      >
      <p
        v-if="errors.owner_login_id"
        class="adm-error r-err"
      >
        {{ errors.owner_login_id }}
      </p>
    </div>
    <div class="adm-field r-field">
      <label
        for="store-new-password"
        class="adm-field__label r-label"
      >{{ t.ownerPassword }}</label>
      <input
        id="store-new-password"
        v-model="form.password"
        class="adm-input r-input"
        type="password"
        :maxlength="PASSWORD_MAX_BYTES"
        autocomplete="new-password"
        :aria-invalid="errors.password ? 'true' : undefined"
      >
      <p
        v-if="errors.password"
        class="adm-error r-err"
      >
        {{ errors.password }}
      </p>
    </div>
    <div class="adm-field r-field">
      <label
        for="store-new-password-confirm"
        class="adm-field__label r-label"
      >{{ t.ownerPasswordConfirm }}</label>
      <input
        id="store-new-password-confirm"
        v-model="form.password_confirmation"
        class="adm-input r-input"
        type="password"
        :maxlength="PASSWORD_MAX_BYTES"
        autocomplete="new-password"
        :aria-invalid="errors.password_confirmation ? 'true' : undefined"
      >
      <p
        v-if="errors.password_confirmation"
        class="adm-error r-err"
      >
        {{ errors.password_confirmation }}
      </p>
    </div>
    <div class="adm-actions">
      <BigButton
        type="submit"
        :loading="saving"
      >
        {{ t.create }}
      </BigButton>
      <BigButton
        variant="secondary"
        @click="emit('cancel')"
      >
        {{ ja.common.cancel }}
      </BigButton>
    </div>
  </form>
</template>

<style scoped>
.store-form__title { margin: 0; font-size: var(--fs-heading); font-weight: 800; }
.store-form__help { margin: 0; }
</style>
