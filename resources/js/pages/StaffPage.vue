<script setup lang="ts">
// S10 スタッフ管理（08 §5.11）：一覧、追加、行をタップして編集（表示名・有効 / 停止・パスワード再設定）
import { onMounted, ref } from 'vue'
import { createStaff, fetchStaff, resetStaffPassword, updateStaff } from '@/api/staff'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatDateTime } from '@/lib/date'
import type { User } from '@/types/api'
import '@/styles/admin.css'

const t = ja.staff
const PASSWORD_MIN = 8
const PASSWORD_MAX = 72

const staff = ref<User[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)

const editingId = ref<number | 'new' | null>(null)
const form = ref({ name: '', login_id: '', password: '', is_active: true })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const saving = ref(false)

const newPassword = ref('')
const passwordError = ref<string | null>(null)
const passwordDone = ref(false)
const resetting = ref(false)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    staff.value = await fetchStaff()
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function resetForms(): void {
  errors.value = {}
  failed.value = null
  notice.value = null
  newPassword.value = ''
  passwordError.value = null
  passwordDone.value = false
}

function startNew(): void {
  resetForms()
  editingId.value = 'new'
  form.value = { name: '', login_id: '', password: '', is_active: true }
}

function startEdit(user: User): void {
  if (editingId.value === user.id) return
  resetForms()
  editingId.value = user.id
  form.value = { name: user.name, login_id: user.login_id, password: '', is_active: user.is_active }
}

function cancel(): void {
  editingId.value = null
}

function handleError(err: unknown): void {
  if (errorStatus(err) === 422) errors.value = fieldErrors(err)
  else if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
}

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  failed.value = null
  const id = editingId.value
  saving.value = true
  try {
    const name = form.value.name.trim()
    if (id === 'new') {
      const created = await createStaff({ name, login_id: form.value.login_id.trim(), password: form.value.password })
      staff.value = [...staff.value, created]
      notice.value = fmt(t.created, { name: created.name })
    } else if (id !== null) {
      const saved = await updateStaff(id, { name, is_active: form.value.is_active })
      staff.value = staff.value.map((x) => (x.id === saved.id ? saved : x))
      notice.value = t.updated
    }
    editingId.value = null
  } catch (err) {
    handleError(err)
  } finally {
    saving.value = false
  }
}

async function resetPassword(id: number): Promise<void> {
  if (resetting.value) return
  passwordError.value = null
  passwordDone.value = false
  if (newPassword.value.length < PASSWORD_MIN || newPassword.value.length > PASSWORD_MAX) {
    passwordError.value = t.passwordTooShort
    return
  }
  resetting.value = true
  try {
    await resetStaffPassword(id, newPassword.value)
    newPassword.value = ''
    passwordDone.value = true
  } catch (err) {
    if (errorStatus(err) === 422) passwordError.value = fieldErrors(err).password ?? t.passwordTooShort
    else if (!isNetworkError(err)) passwordError.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    resetting.value = false
  }
}
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body">
      <p
        v-if="loading"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <div
        v-else-if="loadFailed"
        class="adm-panel r-card"
      >
        <p
          class="adm-error r-err"
          role="alert"
        >
          {{ loadFailed }}
        </p>
        <div class="adm-actions">
          <BigButton @click="load">
            {{ t.retry }}
          </BigButton>
        </div>
      </div>
      <section
        v-else
        class="adm-panel r-card"
        aria-labelledby="staff-heading"
      >
        <div class="adm-panel__head">
          <h2
            id="staff-heading"
            class="adm-panel__title r-h2"
          >
            {{ t.title }}
          </h2>
          <button
            v-if="editingId !== 'new'"
            type="button"
            class="adm-btn"
            @click="startNew"
          >
            {{ t.add }}
          </button>
        </div>
        <p
          v-if="notice"
          class="adm-ok"
          role="status"
        >
          {{ notice }}
        </p>
        <p
          v-if="failed"
          class="adm-error r-err"
          role="alert"
        >
          {{ failed }}
        </p>

        <form
          v-if="editingId === 'new'"
          class="adm-form staff-form"
          novalidate
          :aria-label="t.addTitle"
          @submit.prevent="save"
        >
          <h3>{{ t.addTitle }}</h3>
          <div class="adm-field r-field">
            <label
              for="staff-new-name"
              class="adm-field__label r-label"
            >{{ t.name }}</label>
            <input
              id="staff-new-name"
              v-model="form.name"
              class="adm-input r-input"
              maxlength="50"
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
              for="staff-new-login"
              class="adm-field__label r-label"
            >{{ t.loginId }}</label>
            <input
              id="staff-new-login"
              v-model="form.login_id"
              class="adm-input r-input"
              maxlength="50"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
              :aria-invalid="errors.login_id ? 'true' : undefined"
            >
            <p
              v-if="errors.login_id"
              class="adm-error r-err"
            >
              {{ errors.login_id }}
            </p>
          </div>
          <div class="adm-field r-field">
            <label
              for="staff-new-password"
              class="adm-field__label r-label"
            >{{ t.initialPassword }}</label>
            <input
              id="staff-new-password"
              v-model="form.password"
              class="adm-input r-input"
              type="password"
              maxlength="72"
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
          <div class="adm-actions">
            <BigButton
              type="submit"
              :loading="saving"
            >
              {{ t.create }}
            </BigButton>
            <BigButton
              variant="secondary"
              @click="cancel"
            >
              {{ ja.common.cancel }}
            </BigButton>
          </div>
        </form>

        <p v-if="staff.length === 0 && editingId !== 'new'">
          {{ t.empty }}
        </p>
        <ul
          v-else
          class="adm-list"
        >
          <li
            v-for="user in staff"
            :key="user.id"
          >
            <div
              v-if="editingId === user.id"
              class="staff-edit"
            >
              <form
                class="adm-form"
                novalidate
                :aria-label="t.editTitle"
                @submit.prevent="save"
              >
                <h3>{{ t.editTitle }}（{{ user.login_id }}）</h3>
                <div class="adm-field r-field">
                  <label
                    :for="`staff-name-${user.id}`"
                    class="adm-field__label r-label"
                  >{{ t.name }}</label>
                  <input
                    :id="`staff-name-${user.id}`"
                    v-model="form.name"
                    class="adm-input r-input"
                    maxlength="50"
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
                <label class="adm-check"><input
                  v-model="form.is_active"
                  type="checkbox"
                >{{ t.isActive }}</label>
                <div class="adm-actions">
                  <BigButton
                    type="submit"
                    :loading="saving"
                  >
                    {{ ja.common.save }}
                  </BigButton>
                  <BigButton
                    variant="secondary"
                    @click="cancel"
                  >
                    {{ ja.common.cancel }}
                  </BigButton>
                </div>
              </form>
              <form
                class="adm-form staff-password"
                novalidate
                :aria-label="t.passwordHeading"
                @submit.prevent="resetPassword(user.id)"
              >
                <h3>{{ t.passwordHeading }}</h3>
                <p class="adm-help r-help">
                  {{ t.passwordHelp }}
                </p>
                <div class="adm-field r-field">
                  <label
                    :for="`staff-password-${user.id}`"
                    class="adm-field__label r-label"
                  >{{ t.newPassword }}</label>
                  <input
                    :id="`staff-password-${user.id}`"
                    v-model="newPassword"
                    class="adm-input r-input"
                    type="password"
                    maxlength="72"
                    autocomplete="new-password"
                    :aria-invalid="passwordError ? 'true' : undefined"
                  >
                  <p
                    v-if="passwordError"
                    class="adm-error r-err"
                    role="alert"
                  >
                    {{ passwordError }}
                  </p>
                  <p
                    v-if="passwordDone"
                    class="adm-ok"
                    role="status"
                  >
                    {{ t.passwordDone }}
                  </p>
                </div>
                <div class="adm-actions">
                  <BigButton
                    type="submit"
                    variant="secondary"
                    :loading="resetting"
                  >
                    {{ t.passwordReset }}
                  </BigButton>
                </div>
              </form>
            </div>
            <button
              v-else
              type="button"
              class="adm-row staff-row"
              :class="{ 'adm-row--off': !user.is_active }"
              :aria-label="fmt(ja.common.editNamed, { name: user.name })"
              @click="startEdit(user)"
            >
              <span class="adm-row__main">{{ user.name }}</span>
              <span class="staff-row__login">{{ user.login_id }}</span>
              <span
                class="adm-badge r-chip"
                :class="{ 'adm-badge--primary': user.is_active, 'r-chip--ok': user.is_active, 'r-chip--neutral': !user.is_active }"
              >{{ user.is_active ? ja.admin.statusActive : ja.common.inactive }}</span>
              <span class="staff-row__last">{{ user.last_login_at ? fmt(t.lastLogin, { at: formatDateTime(user.last_login_at) }) : t.neverLoggedIn }}</span>
            </button>
          </li>
        </ul>
      </section>
    </main>
  </div>
</template>

<style scoped>
.staff-row { width: 100%; text-align: left; cursor: pointer; }
.staff-row__login { color: var(--c-text-sub); font-family: ui-monospace, monospace; }
.staff-row__last { color: var(--c-text-sub); font-size: 16px; }
.staff-edit,
.staff-form {
  display: flex;
  flex-direction: column;
  gap: 24px;
  padding: 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
}
.staff-password { padding-top: 16px; border-top: 1px solid var(--c-border); }
</style>
