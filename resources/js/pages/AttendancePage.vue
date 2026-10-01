<script setup lang="ts">
// S20 勤怠（13 §6.3〜§6.6）。staff は本人の月の打刻（時間のみ）。owner は［集計］［打刻］［労働条件］
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchAttendances } from '@/api/attendance'
import AttendanceRecordsPanel from '@/components/attendance/AttendanceRecordsPanel.vue'
import AttendanceSummaryPanel from '@/components/attendance/AttendanceSummaryPanel.vue'
import AttendanceTable from '@/components/attendance/AttendanceTable.vue'
import LaborSettingsPanel from '@/components/attendance/LaborSettingsPanel.vue'
import MonthNav from '@/components/attendance/MonthNav.vue'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatMinutes } from '@/lib/labor'
import { useAuthStore } from '@/stores/auth'
import type { Attendance } from '@/types/api'
import '@/styles/admin.css'

type Tab = 'summary' | 'records' | 'labor'

const t = ja.attendance
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

function initialMonth(): string {
  const q = route.query.month
  if (typeof q === 'string' && /^\d{4}-\d{2}$/.test(q)) return q
  const today = auth.me?.current_business_date
  if (today) return today.slice(0, 7)
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

function initialTab(): Tab {
  const q = route.query.tab
  return q === 'records' || q === 'labor' ? q : 'summary'
}

const month = ref(initialMonth())
const tab = ref<Tab>(initialTab())
const tabOptions = [
  { value: 'summary', label: t.tabs.summary },
  { value: 'records', label: t.tabs.records },
  { value: 'labor', label: t.tabs.labor },
] as const satisfies readonly { value: Tab; label: string }[]
const summaryKey = ref(0)

watch([month, tab], ([m, tb]) => {
  void router.replace({ query: { ...route.query, month: m, tab: auth.isOwner ? tb : undefined } })
})

// staff：本人の打刻
const mine = ref<Attendance[]>([])
const loading = ref(false)
const loadFailed = ref<string | null>(null)
const myDays = computed(() => new Set(mine.value.map((a) => a.business_date)).size)
const myMinutes = computed(() => mine.value.reduce((sum, a) => sum + (a.work_minutes ?? 0), 0))

async function loadMine(): Promise<void> {
  if (auth.isOwner) return
  loading.value = true
  loadFailed.value = null
  try {
    mine.value = (await fetchAttendances(month.value)).attendances
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(loadMine)
watch(month, loadMine)
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body">
      <MonthNav
        v-if="!(auth.isOwner && tab === 'labor')"
        v-model="month"
      />

      <template v-if="auth.isOwner">
        <SegmentedControl
          v-model="tab"
          :options="tabOptions"
          :label="t.title"
        />
        <AttendanceSummaryPanel
          v-if="tab === 'summary'"
          :month="month"
          :reload-key="summaryKey"
          @open-labor="tab = 'labor'"
        />
        <AttendanceRecordsPanel
          v-else-if="tab === 'records'"
          :month="month"
          @changed="summaryKey++"
        />
        <LaborSettingsPanel
          v-else
          @changed="summaryKey++"
        />
      </template>

      <section
        v-else
        class="adm-panel"
        aria-labelledby="att-mine-heading"
      >
        <h2
          id="att-mine-heading"
          class="adm-panel__title"
        >
          {{ fmt(t.myTotal, { days: myDays, time: formatMinutes(myMinutes) }) }}
        </h2>
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
            <BigButton @click="loadMine">
              {{ t.retry }}
            </BigButton>
          </div>
        </template>
        <p v-else-if="mine.length === 0">
          {{ t.empty }}
        </p>
        <AttendanceTable
          v-else
          :attendances="mine"
        />
      </section>
    </main>
  </div>
</template>
