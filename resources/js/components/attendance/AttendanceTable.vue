<script setup lang="ts">
// 13 §6.4 打刻の一覧。日ごとに時間帯の帯（勤務と休憩を色分け）と、右に勤務時間を出す。owner は行の［修正］で直す
import { computed } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatMonthDayTime, formatTime } from '@/lib/date'
import { formatDay, formatMinutes, weekdayOf } from '@/lib/labor'
import type { Attendance } from '@/types/api'

const props = withDefaults(defineProps<{ attendances: Attendance[]; showName?: boolean; editable?: boolean }>(), {
  showName: false,
  editable: false,
})
const emit = defineEmits<{ edit: [row: Attendance] }>()

const t = ja.attendance

/** 退勤が出勤の営業日と違う日付なら日付も出す */
function clockOut(row: Attendance): string {
  if (!row.clock_out_at) return '—'
  return row.clock_out_at.slice(0, 10) === row.clock_in_at.slice(0, 10) ? formatTime(row.clock_out_at) : formatMonthDayTime(row.clock_out_at)
}

const AXIS_START = 6
const HOUR = 60

/** 営業日の 0:00 からの分（日をまたぐと 1440 を超える）。読めなければ null */
function minutesFrom(businessDate: string, iso: string | null): number | null {
  if (!iso) return null
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso)
  const b = /^(\d{4})-(\d{2})-(\d{2})$/.exec(businessDate)
  if (!m || !b) return null
  const days = (Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) - Date.UTC(Number(b[1]), Number(b[2]) - 1, Number(b[3]))) / 86400000
  return days * 1440 + Number(m[4]) * HOUR + Number(m[5])
}

interface Bar { cls: string; left: number; width: number }

/** 帯の目盛りは 6 時から。24 時を超える打刻があるときだけ右に延ばす（2 時間単位） */
const axisEnd = computed(() => {
  let end = 24
  for (const row of props.attendances) {
    const out = minutesFrom(row.business_date, row.clock_out_at) ?? minutesFrom(row.business_date, row.clock_in_at)
    if (out !== null) end = Math.max(end, Math.ceil(out / HOUR / 2) * 2)
  }
  return Math.min(end, 30)
})
const axisTicks = computed(() => {
  const ticks: number[] = []
  for (let h = AXIS_START; h <= axisEnd.value; h += 6) ticks.push(h)
  return ticks
})

function bar(cls: string, from: number, to: number): Bar | null {
  const span = (axisEnd.value - AXIS_START) * HOUR
  const left = Math.max(0, ((from - AXIS_START * HOUR) / span) * 100)
  const right = Math.min(100, ((to - AXIS_START * HOUR) / span) * 100)
  return right > left ? { cls, left, width: right - left } : null
}

function bars(row: Attendance): Bar[] {
  const start = minutesFrom(row.business_date, row.clock_in_at)
  if (start === null) return []
  const end = minutesFrom(row.business_date, row.clock_out_at)
  const out: (Bar | null)[] = [bar(row.clock_out_at ? 'tl__work' : 'tl__live', start, end ?? start + 30)]
  for (const b of row.breaks) {
    const s = minutesFrom(row.business_date, b.started_at)
    if (s === null) continue
    out.push(bar('tl__break', s, minutesFrom(row.business_date, b.ended_at) ?? s + 15))
  }
  return out.filter((x): x is Bar => x !== null)
}

function dow(ymd: string): string {
  const d = weekdayOf(ymd)
  return d === 0 ? 'sun' : d === 6 ? 'sat' : ''
}

function datePart(ymd: string): string {
  return formatDay(ymd).split('（')[0] ?? ymd
}

function weekPart(ymd: string): string {
  return formatDay(ymd).split('（')[1]?.replace('）', '') ?? ''
}

function chipClass(status: Attendance['status']): string {
  return status === 'stale' ? 'r-chip--danger' : status === 'on_break' ? 'r-chip--warn' : 'r-chip--ok'
}
</script>

<template>
  <div class="att-wrap">
    <span class="tl-legend att-legend"><span><i style="background:var(--tl-work)" />{{ t.col.work }}</span><span><i class="tl__break" />{{ t.col.break }}</span></span>
    <div class="r-list att-list">
      <div
        v-for="row in attendances"
        :key="row.id"
        class="day att-day"
        :aria-label="`${showName ? row.user_name + ' ' : ''}${formatDay(row.business_date)}`"
      >
        <span class="day__d">
          <b>{{ datePart(row.business_date) }}</b>
          <span :class="dow(row.business_date)">{{ weekPart(row.business_date) }}</span>
        </span>
        <span class="att-day__main">
          <span
            v-if="showName"
            class="att-day__name clamp1"
          >{{ row.user_name }}</span>
          <span
            class="tl"
            role="img"
            :aria-label="`${t.col.clockIn} ${formatTime(row.clock_in_at)}・${t.col.clockOut} ${clockOut(row)}`"
          >
            <span
              v-for="(b, i) in bars(row)"
              :key="i"
              class="tl__seg"
              :class="b.cls"
              :style="{ left: `${b.left}%`, width: `${b.width}%` }"
            />
          </span>
          <span class="att-day__times">
            {{ formatTime(row.clock_in_at) }}〜{{ clockOut(row) }}
            <span
              v-if="row.status !== 'closed'"
              class="r-chip att-day__status"
              :class="chipClass(row.status)"
            >{{ t.status[row.status] }}</span>
          </span>
        </span>
        <span class="day__v">
          <b>{{ formatMinutes(row.work_minutes) }}</b>
          <span>{{ t.col.break }} {{ formatMinutes(row.break_minutes) }}</span>
          <span v-if="row.edited">{{ t.edited }}</span>
        </span>
        <button
          v-if="editable"
          type="button"
          class="r-btn r-btn--plain att-day__edit"
          :aria-label="fmt(ja.common.editNamed, { name: `${row.user_name} ${formatDay(row.business_date)}` })"
          @click="emit('edit', row)"
        >
          <AppIcon
            name="edit"
            :size="22"
          />
          <span class="att-day__edit-t">{{ ja.common.edit }}</span>
        </button>
      </div>
      <div
        class="att-list__axis"
        :class="{ 'att-list__axis--edit': editable }"
        aria-hidden="true"
      >
        <span class="tl-axis">
          <span
            v-for="h in axisTicks"
            :key="h"
          >{{ h }}</span>
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.att-wrap { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.att-list { overflow: hidden; }
.att-day { min-width: 0; }
.att-day__main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.att-day__name { font-size: 16px; font-weight: 800; }
.att-day__times { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; color: var(--c-text-sub); font-size: 14px; font-variant-numeric: tabular-nums; }
.att-day__status { min-height: 28px; padding: 2px 10px; font-size: 14px; }
.att-day__edit { flex: none; min-width: 48px; min-height: 48px; padding: 0 8px; }
.att-day__edit-t { display: none; }
.att-list__axis { padding: 4px 120px 10px 84px; border-top: 1px solid var(--c-border-soft); }

.att-list__axis--edit { padding-right: 70px; }

@media (min-width: 700px) {
  .att-list__axis--edit { padding-right: 180px; }
  .att-day__edit-t { display: inline; }
}
</style>
