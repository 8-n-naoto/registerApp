<script setup lang="ts">
// 13 §6.4 打刻の一覧（日付・出勤・退勤・休憩・勤務）。owner は行をタップして修正する
import { fmt, ja } from '@/i18n/ja'
import { formatMonthDayTime, formatTime } from '@/lib/date'
import { formatDay, formatMinutes } from '@/lib/labor'
import type { Attendance } from '@/types/api'

withDefaults(defineProps<{ attendances: Attendance[]; showName?: boolean; editable?: boolean }>(), {
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
</script>

<template>
  <div class="att-table-wrap">
    <table class="att-table">
      <thead>
        <tr>
          <th scope="col">
            {{ t.col.date }}
          </th>
          <th
            v-if="showName"
            scope="col"
          >
            {{ t.col.name }}
          </th>
          <th scope="col">
            {{ t.col.clockIn }}
          </th>
          <th scope="col">
            {{ t.col.clockOut }}
          </th>
          <th scope="col">
            {{ t.col.break }}
          </th>
          <th scope="col">
            {{ t.col.work }}
          </th>
          <th
            v-if="editable"
            scope="col"
          >
            <span class="att-table__sr">{{ t.editTitle }}</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in attendances"
          :key="row.id"
        >
          <td>{{ formatDay(row.business_date) }}</td>
          <td v-if="showName">
            {{ row.user_name }}
          </td>
          <td>{{ formatTime(row.clock_in_at) }}</td>
          <td>
            {{ clockOut(row) }}
            <span
              v-if="row.status !== 'closed'"
              class="adm-badge att-table__status"
              :class="`att-table__status--${row.status}`"
            >{{ t.status[row.status] }}</span>
          </td>
          <td>{{ formatMinutes(row.break_minutes) }}</td>
          <td>
            {{ formatMinutes(row.work_minutes) }}
            <span
              v-if="row.edited"
              class="att-table__edited"
            >{{ t.edited }}</span>
          </td>
          <td v-if="editable">
            <button
              type="button"
              class="adm-btn"
              :aria-label="fmt(ja.common.editNamed, { name: `${row.user_name} ${formatDay(row.business_date)}` })"
              @click="emit('edit', row)"
            >
              {{ ja.common.edit }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.att-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.att-table { width: 100%; border-collapse: collapse; font-size: 16px; white-space: nowrap; }
.att-table th,
.att-table td { padding: 8px 10px; border-bottom: 1px solid var(--c-border); text-align: left; vertical-align: middle; }
.att-table th { color: var(--c-text-sub); font-weight: 700; }
.att-table__status { margin-left: 6px; }
.att-table__status--stale { border-color: var(--c-danger); color: var(--c-danger); }
.att-table__status--on_break { border-color: var(--c-change); color: var(--c-change); }
.att-table__status--working { border-color: var(--c-success); color: var(--c-success); }
.att-table__edited { margin-left: 6px; color: var(--c-text-sub); font-size: 16px; }

.att-table__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}
</style>
