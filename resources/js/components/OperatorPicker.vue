<script setup lang="ts">
// 13 §6.2 担当者の切替：端末の店舗で勤務中の人（#66）を並べ、選んで切り替える（#67）。owner へはパスワードが要る
import { nextTick, onMounted, ref } from 'vue'
import { fetchOperators } from '@/api/attendance'
import BigButton from '@/components/BigButton.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError, retryAfterSeconds } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type { Operator } from '@/types/api'

const props = withDefaults(
  defineProps<{
    /** 一覧から外す人（いま操作している人） */
    excludeId?: number | null
    /** 勤務中の人がいない・読み込めないときは何も出さない（ログイン画面） */
    hideWhenEmpty?: boolean
    heading?: string
    help?: string
  }>(),
  { excludeId: null, hideWhenEmpty: false, heading: '', help: '' },
)
const emit = defineEmits<{ switched: [] }>()

const auth = useAuthStore()
const operators = ref<Operator[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const selected = ref<Operator | null>(null)
const password = ref('')
const error = ref<string | null>(null)
const switching = ref(false)
const passwordInput = ref<HTMLInputElement | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    operators.value = (await fetchOperators()).filter((op) => op.id !== props.excludeId)
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function choose(op: Operator): Promise<void> {
  error.value = null
  if (op.role === 'owner') {
    selected.value = op
    password.value = ''
    await nextTick()
    passwordInput.value?.focus()
    return
  }
  selected.value = null
  await doSwitch(op)
}

async function doSwitch(op: Operator, pw?: string): Promise<void> {
  if (switching.value) return
  switching.value = true
  error.value = null
  try {
    await auth.switchOperator(op.id, pw)
    selected.value = null
    password.value = ''
    emit('switched')
  } catch (err) {
    const status = errorStatus(err)
    if (status === 422) {
      error.value = fieldErrors(err).password ?? ja.error.login
      password.value = ''
    } else if (status === 404) {
      error.value = ja.operator.unavailable
      selected.value = null
      await load()
    } else if (status === 429) {
      error.value = fmt(ja.login.throttled, { sec: retryAfterSeconds(err) })
    } else if (!isNetworkError(err)) {
      error.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    switching.value = false
  }
}

function submitPassword(): void {
  if (selected.value) void doSwitch(selected.value, password.value)
}
</script>

<template>
  <section
    v-if="!hideWhenEmpty || operators.length > 0"
    class="op-pick"
    :aria-label="heading || ja.operator.switchTitle"
    :aria-busy="loading"
  >
    <h2
      v-if="heading"
      class="op-pick__heading"
    >
      {{ heading }}
    </h2>
    <p
      v-if="help"
      class="op-pick__help"
    >
      {{ help }}
    </p>
    <p
      v-if="loading"
      role="status"
    >
      {{ ja.common.loading }}
    </p>
    <div
      v-else-if="loadFailed"
      class="op-pick__failed"
    >
      <p role="alert">
        {{ ja.operator.loadFailed }}
      </p>
      <button
        type="button"
        class="op-pick__btn r-btn r-btn--secondary"
        @click="load"
      >
        {{ ja.operator.retry }}
      </button>
    </div>
    <p v-else-if="operators.length === 0">
      {{ ja.operator.none }}
    </p>
    <ul
      v-else
      class="op-pick__list r-list"
    >
      <li
        v-for="op in operators"
        :key="op.id"
      >
        <button
          type="button"
          class="op-pick__btn r-li"
          :class="{ 'op-pick__btn--on': selected?.id === op.id, on: selected?.id === op.id }"
          :aria-pressed="selected?.id === op.id"
          :disabled="switching"
          @click="choose(op)"
        >
          <span class="op">
            <span
              class="op__av op-pick__av"
              :data-initial="Array.from(op.name)[0] ?? ''"
              aria-hidden="true"
            />
          </span>
          <span class="r-li__main">
            <span class="r-li__title clamp1">{{ op.name }}</span>
            <span class="op-pick__meta r-li__meta">{{ ja.role[op.role] }}<template v-if="op.on_break">・{{ ja.operator.onBreak }}</template></span>
          </span>
        </button>
      </li>
    </ul>
    <form
      v-if="selected"
      class="op-pick__form r-field"
      novalidate
      @submit.prevent="submitPassword"
    >
      <label
        for="operator-password"
        class="op-pick__label r-label"
      >{{ fmt(ja.operator.passwordFor, { name: selected.name }) }}</label>
      <input
        id="operator-password"
        ref="passwordInput"
        v-model="password"
        class="op-pick__input r-input"
        type="password"
        autocomplete="current-password"
        maxlength="255"
        :aria-invalid="error ? 'true' : undefined"
      >
      <BigButton
        type="submit"
        block
        :loading="switching"
      >
        {{ ja.operator.doSwitch }}
      </BigButton>
    </form>
    <p
      v-if="error"
      class="op-pick__error r-err"
      role="alert"
    >
      {{ error }}
    </p>
  </section>
</template>

<style scoped>
.op-pick { display: flex; flex-direction: column; gap: 12px; color: var(--c-text); }
.op-pick__av::before { content: attr(data-initial); }
.op-pick__heading { font-size: 20px; font-weight: 800; }
.op-pick__help { color: var(--c-text-sub); font-size: 16px; }
.op-pick__list li + li .r-li { border-top: 1px solid var(--c-border-soft); }
.op-pick__btn:disabled { opacity: 0.55; }
.op-pick__failed { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; color: var(--c-danger); font-weight: 700; }
.op-pick__form { gap: 8px; }
</style>
