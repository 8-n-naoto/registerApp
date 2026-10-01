<script setup lang="ts">
// 13 §6.8 S21［希望を出す］：日ごとに 出られる / 出られない を選び、その月の分をまとめて送る（#86・#87）
import { computed, onMounted, ref, watch } from 'vue'
import { fetchMyShiftRequests, submitMyShiftRequests, type ShiftRequestInput } from '@/api/shifts'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatDay, monthDays, normalizeShiftTime, weekdayOf } from '@/lib/labor'
import type { ShiftMonth, ShiftRequestKind } from '@/types/api'

const props = defineProps<{ month: string }>()
const emit = defineEmits<{ submitted: [] }>()

const t = ja.shifts

type Kind = ShiftRequestKind | 'none'
interface DayForm { date: string; kind: Kind; start_time: string; end_time: string; note: string }

const kindOptions = [
  { value: 'none', label: t.kind.none },
  { value: 'available', label: t.kind.available },
  { value: 'unavailable', label: t.kind.unavailable },
] as const satisfies readonly { value: Kind; label: string }[]

const info = ref<ShiftMonth | null>(null)
const days = ref<DayForm[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const notice = ref<string | null>(null)
const submitting = ref(false)

const accepting = computed(() => info.value?.accepting_requests ?? false)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  errors.value = {}
  failed.value = null
  notice.value = null
  try {
    const res = await fetchMyShiftRequests(props.month)
    info.value = res.month
    const byDate = new Map(res.requests.map((r) => [r.date, r]))
    days.value = monthDays(props.month).map((date) => {
      const r = byDate.get(date)
      return { date, kind: r?.kind ?? 'none', start_time: r?.start_time ?? '', end_time: r?.end_time ?? '', note: r?.note ?? '' }
    })
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => props.month, load)

/** 送った配列の添字のエラーを日付に付け替える */
function mapErrors(sent: ShiftRequestInput[], raw: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [key, message] of Object.entries(raw)) {
    const m = /^requests\.(\d+)\./.exec(key)
    const date = m ? sent[Number(m[1])]?.date : undefined
    if (date && !out[date]) out[date] = message
  }
  return out
}

async function submit(): Promise<void> {
  if (submitting.value) return
  errors.value = {}
  failed.value = null
  notice.value = null
  const sent: ShiftRequestInput[] = []
  for (const d of days.value) {
    if (d.kind === 'none') continue
    const available = d.kind === 'available'
    const start = available ? normalizeShiftTime(d.start_time) : ''
    const end = available ? normalizeShiftTime(d.end_time) : ''
    if (available) {
      d.start_time = start
      d.end_time = end
    }
    sent.push({ date: d.date, kind: d.kind, start_time: start === '' ? null : start, end_time: end === '' ? null : end, note: d.note.trim() === '' ? null : d.note.trim() })
  }
  submitting.value = true
  try {
    const res = await submitMyShiftRequests(props.month, sent)
    info.value = res.month
    notice.value = t.submitted
    emit('submitted')
  } catch (err) {
    const status = errorStatus(err)
    if (status === 422 && errorBody(err)?.code === 'SHIFT_REQUEST_CLOSED') {
      await load()
      failed.value = t.closed
    } else if (status === 422) {
      errors.value = mapErrors(sent, fieldErrors(err))
      if (Object.keys(errors.value).length === 0) failed.value = errorBody(err)?.message ?? ja.error.unexpected
    } else if (!isNetworkError(err)) {
      failed.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="shift-req-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="shift-req-heading"
        class="adm-panel__title"
      >
        {{ t.tabs.requests }}
      </h2>
      <span
        v-if="info"
        class="adm-badge"
      >{{ info.request_deadline ? fmt(t.deadline, { date: formatDay(info.request_deadline) }) : t.noDeadline }}</span>
    </div>
    <p
      v-if="loading"
      role="status"
    >
      {{ ja.common.loading }}
    </p>
    <template v-else-if="loadFailed">
      <p
        class="adm-error"
        role="alert"
      >
        {{ loadFailed }}
      </p>
      <div class="adm-actions">
        <BigButton @click="load">
          {{ t.retry }}
        </BigButton>
      </div>
    </template>
    <form
      v-else
      class="adm-form"
      novalidate
      @submit.prevent="submit"
    >
      <p
        v-if="!accepting"
        class="adm-error"
        role="status"
      >
        {{ t.closed }}
      </p>
      <p
        v-else
        class="adm-help"
      >
        {{ t.requestHelp }}
      </p>
      <ol class="req-days">
        <li
          v-for="d in days"
          :key="d.date"
          class="req-day"
          :class="{ 'req-day--sun': weekdayOf(d.date) === 0, 'req-day--sat': weekdayOf(d.date) === 6 }"
        >
          <h3 class="req-day__date">
            {{ formatDay(d.date) }}
          </h3>
          <SegmentedControl
            v-model="d.kind"
            :options="kindOptions"
            :label="formatDay(d.date)"
            :disabled="!accepting"
          />
          <div
            v-if="d.kind === 'available'"
            class="req-day__times"
          >
            <div class="adm-field">
              <label
                :for="`req-start-${d.date}`"
                class="adm-field__label"
              >{{ t.start }}</label>
              <input
                :id="`req-start-${d.date}`"
                v-model="d.start_time"
                class="adm-input"
                inputmode="numeric"
                placeholder="10:00"
                maxlength="5"
                autocomplete="off"
                :disabled="!accepting"
                @blur="d.start_time = normalizeShiftTime(d.start_time)"
              >
            </div>
            <div class="adm-field">
              <label
                :for="`req-end-${d.date}`"
                class="adm-field__label"
              >{{ t.end }}</label>
              <input
                :id="`req-end-${d.date}`"
                v-model="d.end_time"
                class="adm-input"
                inputmode="numeric"
                placeholder="15:00"
                maxlength="5"
                autocomplete="off"
                :disabled="!accepting"
                @blur="d.end_time = normalizeShiftTime(d.end_time)"
              >
            </div>
          </div>
          <div
            v-if="d.kind !== 'none'"
            class="adm-field"
          >
            <label
              :for="`req-note-${d.date}`"
              class="adm-field__label"
            >{{ t.note }}</label>
            <input
              :id="`req-note-${d.date}`"
              v-model="d.note"
              class="adm-input"
              maxlength="100"
              autocomplete="off"
              :disabled="!accepting"
            >
          </div>
          <p
            v-if="errors[d.date]"
            class="adm-error"
          >
            {{ errors[d.date] }}
          </p>
        </li>
      </ol>
      <p
        v-if="notice"
        class="adm-ok"
        role="status"
      >
        {{ notice }}
      </p>
      <p
        v-if="failed"
        class="adm-error"
        role="alert"
      >
        {{ failed }}
      </p>
      <div
        v-if="accepting"
        class="adm-actions req-submit"
      >
        <BigButton
          type="submit"
          :loading="submitting"
        >
          {{ t.submit }}
        </BigButton>
      </div>
    </form>
  </section>
</template>

<style scoped>
.req-days { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }

.req-day {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
}

.req-day__date { font-size: 18px; }
.req-day--sun .req-day__date { color: var(--c-danger); }
.req-day--sat .req-day__date { color: var(--c-primary); }
.req-day__times { display: flex; flex-wrap: wrap; gap: 12px; }
.req-day__times .adm-field { flex: 1 1 140px; }

.req-submit {
  position: sticky;
  bottom: 0;
  padding: 12px 0 calc(12px + var(--safe-bottom));
  background: var(--c-surface);
}
</style>
