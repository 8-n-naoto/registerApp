<script setup lang="ts">
// S21 勤務表（13 §6.7〜§6.9）。公開済みの勤務表は全員分が見える。owner は予定を組み、締切・公開と区分を決める
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchShiftBoard } from '@/api/shifts'
import MonthNav from '@/components/attendance/MonthNav.vue'
import ShiftBoardPanel from '@/components/attendance/ShiftBoardPanel.vue'
import ShiftMonthPanel from '@/components/attendance/ShiftMonthPanel.vue'
import ShiftPatternsPanel from '@/components/attendance/ShiftPatternsPanel.vue'
import ShiftRequestsPanel from '@/components/attendance/ShiftRequestsPanel.vue'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type { ShiftBoard, ShiftMonth } from '@/types/api'
import '@/styles/admin.css'

type Tab = 'board' | 'requests' | 'patterns'

const t = ja.shifts
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

/** 既定は今の営業日の月 */
function initialMonth(): string {
  const q = route.query.month
  if (typeof q === 'string' && /^\d{4}-\d{2}$/.test(q)) return q
  const today = auth.me?.current_business_date
  if (today) return today.slice(0, 7)
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const month = ref(initialMonth())
function initialTab(): Tab {
  const q = route.query.tab
  if (q === 'requests') return 'requests'
  if (q === 'patterns' && auth.isOwner) return 'patterns'
  return 'board'
}

const tab = ref<Tab>(initialTab())
/** ［区分］は owner だけ */
const tabOptions = computed<{ value: Tab; label: string }[]>(() => [
  { value: 'board', label: t.tabs.board },
  { value: 'requests', label: t.tabs.requests },
  ...(auth.isOwner ? [{ value: 'patterns' as const, label: t.tabs.patterns }] : []),
])

const board = ref<ShiftBoard | null>(null)
const loading = ref(true)
const loadFailed = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    board.value = await fetchShiftBoard(month.value)
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(month, load)
watch([month, tab], ([m, tb]) => {
  void router.replace({ query: { ...route.query, month: m, tab: tb } })
})

function onMonthSaved(saved: ShiftMonth): void {
  if (board.value) board.value = { ...board.value, month: saved }
}
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body">
      <MonthNav v-model="month" />
      <SegmentedControl
        v-model="tab"
        :options="tabOptions"
        :label="t.title"
      />
      <ShiftRequestsPanel
        v-if="tab === 'requests'"
        :month="month"
        @submitted="auth.isOwner && load()"
      />
      <ShiftPatternsPanel
        v-else-if="tab === 'patterns' && auth.isOwner"
        @changed="load"
      />
      <template v-else>
        <p
          v-if="loading && !board"
          role="status"
        >
          {{ ja.common.loading }}
        </p>
        <section
          v-else-if="loadFailed"
          class="adm-panel"
        >
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
        </section>
        <template v-else-if="board">
          <ShiftMonthPanel
            v-if="auth.isOwner"
            :month="board.month"
            @saved="onMonthSaved"
          />
          <ShiftBoardPanel
            :board="board"
            :is-owner="auth.isOwner"
            :my-id="auth.me?.user.id ?? null"
            @changed="load"
          />
        </template>
      </template>
    </main>
  </div>
</template>
