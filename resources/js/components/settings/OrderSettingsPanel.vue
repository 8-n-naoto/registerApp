<script setup lang="ts">
// S09「注文」（12 §8.8）：QR 注文の受付・店員の確認・注文できる時間・厨房の自動更新。保存はこの欄だけ（PUT /settings/orders）
import { onMounted, ref } from 'vue'
import { fetchOrderSettings, updateOrderSettings } from '@/api/settings'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import type { PollingMode } from '@/types/api'

const t = ja.storeSettings
const WINDOWS_MAX = 3 // 12 §5.13

const MODES = [
  { value: 'always', label: t.pollingAlways },
  { value: 'off', label: t.pollingOff },
  { value: 'schedule', label: t.pollingSchedule },
] as const satisfies readonly { value: PollingMode; label: string }[]

const pad = (n: number): string => String(n).padStart(2, '0')
const HOURS = Array.from({ length: 24 }, (_, i) => pad(i))
const MINUTES = Array.from({ length: 12 }, (_, i) => pad(i * 5)) // 5 分刻み
const SESSION = Array.from({ length: 24 }, (_, i) => (i + 1) * 30) // 30〜720 分

/** 選択肢に無い値（API で直接入れたもの）も消さずに出す */
function withCurrent<T>(list: readonly T[], current: T): T[] {
  return list.includes(current) ? [...list] : [current, ...list]
}

function sessionLabel(n: number): string {
  const h = Math.floor(n / 60)
  const m = n % 60
  if (h === 0) return fmt(t.minutes, { n })
  return m === 0 ? fmt(t.hours, { h }) : fmt(t.hoursMinutes, { h, m })
}

interface WindowForm { sh: string; sm: string; eh: string; em: string }

const loading = ref(true)
const loadFailed = ref<string | null>(null)
const enabled = ref(false)
const approval = ref(false)
const session = ref(180)
const mode = ref<PollingMode>('always')
const windows = ref<WindowForm[]>([])

const saving = ref(false)
const errors = ref<Record<string, string>>({})
const saved = ref(false)
const saveFailed = ref<string | null>(null)

function split(hhmm: string): [string, string] {
  const [h = '00', m = '00'] = hhmm.split(':')
  return [h, m]
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    const data = await fetchOrderSettings()
    enabled.value = data.customer_order_enabled
    approval.value = data.customer_order_approval
    session.value = data.customer_session_minutes
    mode.value = data.polling_mode
    windows.value = data.polling_windows.map((w) => {
      const [sh, sm] = split(w.start)
      const [eh, em] = split(w.end)
      return { sh, sm, eh, em }
    })
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? t.orderLoadFailed : (errorBody(err)?.message ?? t.orderLoadFailed)
  } finally {
    loading.value = false
  }
}

function setMode(value: PollingMode): void {
  mode.value = value
  if (value === 'schedule' && windows.value.length === 0) addWindow()
}

function addWindow(): void {
  if (windows.value.length >= WINDOWS_MAX) return
  windows.value = [...windows.value, { sh: '11', sm: '00', eh: '14', em: '00' }]
}

function removeWindow(index: number): void {
  windows.value = windows.value.filter((_, i) => i !== index)
}

function windowError(index: number): string | undefined {
  const w = windows.value[index]
  if (w && w.sh === w.eh && w.sm === w.em) return t.windowSame // AC-S09-7（送る前に止める）
  const e = errors.value
  return e[`polling_windows.${index}.start`] ?? e[`polling_windows.${index}.end`] ?? e[`polling_windows.${index}`]
}

async function save(): Promise<void> {
  if (saving.value) return
  errors.value = {}
  saved.value = false
  saveFailed.value = null
  const schedule = mode.value === 'schedule'
  if (schedule && windows.value.some((_, i) => windowError(i) !== undefined)) return
  saving.value = true
  try {
    const data = await updateOrderSettings({
      customer_order_enabled: enabled.value,
      customer_order_approval: approval.value,
      customer_session_minutes: session.value,
      polling_mode: mode.value,
      polling_windows: schedule ? windows.value.map((w) => ({ start: `${w.sh}:${w.sm}`, end: `${w.eh}:${w.em}` })) : [],
    })
    mode.value = data.polling_mode
    saved.value = true
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) saveFailed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="order-settings-heading"
  >
    <h2
      id="order-settings-heading"
      class="adm-panel__title"
    >
      {{ t.orderHeading }}
    </h2>
    <p
      v-if="loading"
      role="status"
    >
      {{ ja.common.loading }}
    </p>
    <p
      v-else-if="loadFailed"
      class="adm-error"
      role="alert"
    >
      {{ loadFailed }}
    </p>
    <form
      v-else
      class="adm-form"
      novalidate
      @submit.prevent="save"
    >
      <div class="adm-field">
        <label class="adm-check"><input
          v-model="enabled"
          type="checkbox"
          data-customer-order
        >{{ t.customerOrderEnabled }}</label>
        <p
          v-if="errors.customer_order_enabled"
          class="adm-error"
        >
          {{ errors.customer_order_enabled }}
        </p>
      </div>

      <div class="adm-field">
        <label class="adm-check"><input
          v-model="approval"
          type="checkbox"
          aria-describedby="order-approval-help"
          data-approval
        >{{ t.customerOrderApproval }}</label>
        <p
          id="order-approval-help"
          class="adm-help"
        >
          {{ t.customerOrderApprovalHelp }}
        </p>
      </div>

      <div class="adm-field">
        <label
          for="order-session"
          class="adm-field__label"
        >{{ t.sessionMinutes }}</label>
        <select
          id="order-session"
          v-model.number="session"
          class="adm-select"
        >
          <option
            v-for="n in withCurrent(SESSION, session)"
            :key="n"
            :value="n"
          >
            {{ sessionLabel(n) }}
          </option>
        </select>
        <p
          v-if="errors.customer_session_minutes"
          class="adm-error"
        >
          {{ errors.customer_session_minutes }}
        </p>
      </div>

      <div class="adm-field">
        <span class="adm-field__label">{{ t.polling }}</span>
        <SegmentedControl
          :model-value="mode"
          :options="MODES"
          :label="t.polling"
          @update:model-value="setMode"
        />
        <p
          v-if="errors.polling_mode || errors.polling_windows"
          class="adm-error"
        >
          {{ errors.polling_mode ?? errors.polling_windows }}
        </p>
      </div>

      <template v-if="mode === 'schedule'">
        <fieldset
          v-for="(w, i) in windows"
          :key="i"
          class="adm-field window"
          :data-window="i"
        >
          <legend class="adm-field__label">
            {{ fmt(t.window, { n: i + 1 }) }}
          </legend>
          <div class="adm-actions">
            <select
              v-model="w.sh"
              class="adm-select tabular"
              :aria-label="fmt(t.windowHour, { label: `${fmt(t.window, { n: i + 1 })} ${t.windowStart}` })"
            >
              <option
                v-for="h in withCurrent(HOURS, w.sh)"
                :key="h"
                :value="h"
              >
                {{ h }}
              </option>
            </select>
            <span aria-hidden="true">:</span>
            <select
              v-model="w.sm"
              class="adm-select tabular"
              :aria-label="fmt(t.windowMinute, { label: `${fmt(t.window, { n: i + 1 })} ${t.windowStart}` })"
            >
              <option
                v-for="m in withCurrent(MINUTES, w.sm)"
                :key="m"
                :value="m"
              >
                {{ m }}
              </option>
            </select>
            <span aria-hidden="true">〜</span>
            <select
              v-model="w.eh"
              class="adm-select tabular"
              :aria-label="fmt(t.windowHour, { label: `${fmt(t.window, { n: i + 1 })} ${t.windowEnd}` })"
            >
              <option
                v-for="h in withCurrent(HOURS, w.eh)"
                :key="h"
                :value="h"
              >
                {{ h }}
              </option>
            </select>
            <span aria-hidden="true">:</span>
            <select
              v-model="w.em"
              class="adm-select tabular"
              :aria-label="fmt(t.windowMinute, { label: `${fmt(t.window, { n: i + 1 })} ${t.windowEnd}` })"
            >
              <option
                v-for="m in withCurrent(MINUTES, w.em)"
                :key="m"
                :value="m"
              >
                {{ m }}
              </option>
            </select>
            <button
              type="button"
              class="adm-btn adm-btn--danger"
              :disabled="windows.length <= 1"
              @click="removeWindow(i)"
            >
              {{ t.windowDelete }}
            </button>
          </div>
          <p
            v-if="windowError(i)"
            class="adm-error"
            role="alert"
          >
            {{ windowError(i) }}
          </p>
        </fieldset>
        <p class="adm-help">
          {{ t.windowsHelp }}
        </p>
        <div class="adm-actions">
          <button
            v-if="windows.length < WINDOWS_MAX"
            type="button"
            class="adm-btn"
            @click="addWindow"
          >
            {{ t.windowAdd }}
          </button>
        </div>
      </template>

      <p
        v-if="saved"
        class="adm-ok"
        role="status"
      >
        {{ ja.common.saved }}
      </p>
      <p
        v-if="saveFailed"
        class="adm-error"
        role="alert"
      >
        {{ saveFailed }}
      </p>
      <div class="adm-actions">
        <BigButton
          type="submit"
          :loading="saving"
          data-save-orders
        >
          {{ ja.common.save }}
        </BigButton>
      </div>
    </form>
  </section>
</template>

<style scoped>
.window { margin: 0; padding: 0; border: 0; }
</style>
