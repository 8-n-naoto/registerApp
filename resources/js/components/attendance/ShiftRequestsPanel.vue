<script setup lang="ts">
// 13 §6.8 S21［希望を出す］：日ごとに 区分 / その他（メモ）/ 出られない を選び、その月の分をまとめて送る（#86・#87）
import { computed, onMounted, ref, watch } from 'vue'
import { fetchMyShiftRequests, submitMyShiftRequests, type ShiftRequestInput } from '@/api/shifts'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatDay, formatMinutes, monthDays, segmentsText, weekdayOf } from '@/lib/labor'
import type { ShiftMonth, ShiftPattern, ShiftSegment } from '@/types/api'

const props = defineProps<{ month: string }>()
const emit = defineEmits<{ submitted: [] }>()

const t = ja.shifts

/** 区分は `p:<id>`。区分に合わないときは memo（メモ必須） */
type Choice = 'none' | 'memo' | 'unavailable' | `p:${number}`
/** 使わなくなった区分でも、その日にすでに出していたものはそのまま選べる */
interface OwnPattern { id: number; name: string; segments: ShiftSegment[] }
interface DayForm { date: string; choice: Choice; note: string; own: OwnPattern | null }

const info = ref<ShiftMonth | null>(null)
const patterns = ref<ShiftPattern[]>([])
const days = ref<DayForm[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const notice = ref<string | null>(null)
const submitting = ref(false)

const accepting = computed(() => info.value?.accepting_requests ?? false)
const patternChoices = computed(() => patterns.value.map((p) => ({ value: `p:${p.id}` as Choice, label: p.name })))

function optionsFor(d: DayForm): { value: Choice; label: string }[] {
  const own = d.own !== null && !patterns.value.some((p) => p.id === d.own?.id)
    ? [{ value: `p:${d.own.id}` as Choice, label: d.own.name }]
    : []
  return [
    { value: 'none', label: t.choice.none },
    ...patternChoices.value,
    ...own,
    { value: 'memo', label: t.choice.memo },
    { value: 'unavailable', label: t.choice.unavailable },
  ]
}

function patternIdOf(choice: Choice): number | null {
  return choice.startsWith('p:') ? Number(choice.slice(2)) : null
}

function chosenSegments(d: DayForm): ShiftSegment[] | null {
  const id = patternIdOf(d.choice)
  if (id === null) return null
  return patterns.value.find((p) => p.id === id)?.segments ?? (d.own?.id === id ? d.own.segments : null)
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  errors.value = {}
  failed.value = null
  notice.value = null
  try {
    const res = await fetchMyShiftRequests(props.month)
    info.value = res.month
    patterns.value = res.patterns
    const byDate = new Map(res.requests.map((r) => [r.date, r]))
    days.value = monthDays(props.month).map((date) => {
      const r = byDate.get(date)
      if (!r) return { date, choice: 'none', note: '', own: null }
      if (r.kind === 'unavailable') return { date, choice: 'unavailable', note: r.note ?? '', own: null }
      if (r.pattern_id !== null) {
        const own = { id: r.pattern_id, name: r.pattern_name ?? '', segments: r.segments ?? [] }
        return { date, choice: `p:${r.pattern_id}`, note: r.note ?? '', own }
      }
      // 区分の導入前に時間で出した希望はメモとして残す
      const legacy = r.start_time && r.end_time ? `${r.start_time}〜${r.end_time}` : ''
      return { date, choice: 'memo', note: r.note ?? legacy, own: null }
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
  const missing: Record<string, string> = {}
  for (const d of days.value) {
    if (d.choice === 'none') continue
    const note = d.note.trim() === '' ? null : d.note.trim()
    if (d.choice === 'memo' && note === null) missing[d.date] = t.memoRequired
    sent.push({
      date: d.date,
      kind: d.choice === 'unavailable' ? 'unavailable' : 'available',
      pattern_id: patternIdOf(d.choice),
      note,
    })
  }
  if (Object.keys(missing).length > 0) {
    errors.value = missing
    return
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
      <section
        v-if="patterns.length > 0"
        class="req-legend"
        aria-labelledby="req-legend-heading"
      >
        <h3
          id="req-legend-heading"
          class="req-legend__title"
        >
          {{ t.patternLegend }}
        </h3>
        <dl class="req-legend__list">
          <div
            v-for="(p, pi) in patterns"
            :key="p.id"
            class="req-legend__row"
            :class="['sh--a', 'sh--b', 'sh--c'][pi % 3]"
          >
            <dt class="req-legend__name">
              {{ p.name }}
            </dt>
            <dd class="req-legend__time">
              {{ segmentsText(p.segments) }}<template v-if="p.break_minutes > 0">
                （{{ fmt(t.patternBreak, { time: formatMinutes(p.break_minutes) }) }}）
              </template>
            </dd>
          </div>
        </dl>
      </section>
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
            v-model="d.choice"
            :options="optionsFor(d)"
            :label="formatDay(d.date)"
            :disabled="!accepting"
          />
          <p
            v-if="chosenSegments(d)"
            class="req-day__segments"
          >
            {{ segmentsText(chosenSegments(d) ?? []) }}
          </p>
          <div
            v-if="d.choice !== 'none'"
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
              :aria-invalid="errors[d.date] ? 'true' : undefined"
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
.req-legend { padding: 12px 16px; border: 1px solid var(--c-border-soft); border-radius: var(--radius-card); background: var(--c-surface); }
.req-legend__title { margin-bottom: 8px; font-size: 16px; font-weight: 800; }
.req-legend__list { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
.req-legend__row { display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 10px; max-width: 100%; padding: 6px 12px; border-radius: 8px; }
.req-legend__name { min-width: 1.5em; font-size: 16px; font-weight: 800; overflow-wrap: anywhere; }
.req-legend__time { margin: 0; font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.req-days { display: flex; flex-direction: column; gap: 0; margin: 0; padding: 0; overflow: hidden; border: 1px solid var(--c-border-soft); border-radius: var(--radius-card); background: var(--c-surface); list-style: none; }

.req-day {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  padding: 12px 16px;
  border-top: 1px solid var(--c-border-soft);
}

.req-day:first-child { border-top: 0; }
.req-day__date { font-size: 18px; font-weight: 800; font-variant-numeric: tabular-nums; }
.req-day--sun .req-day__date { color: var(--c-danger); }
.req-day--sat .req-day__date { color: var(--c-primary-ink); }
.req-day__segments { font-variant-numeric: tabular-nums; font-weight: 700; overflow-wrap: anywhere; }

.req-submit {
  position: sticky;
  bottom: 0;
  padding: 12px 0 calc(12px + var(--safe-bottom));
  background: var(--c-surface);
}
</style>
