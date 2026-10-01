<script setup lang="ts">
// 13 §6.5 S20［集計］（owner）：人ごとの月の時間・人件費（#75）、注意、CSV（#76）
import { onMounted, ref, watch } from 'vue'
import { attendanceExportUrl, fetchAttendanceSummary } from '@/api/attendance'
import BigButton from '@/components/BigButton.vue'
import LaborWarningBanner from '@/components/LaborWarningBanner.vue'
import { ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatMinutes } from '@/lib/labor'
import { formatYen } from '@/lib/money'
import type { AttendanceSummary } from '@/types/api'

const props = defineProps<{ month: string; reloadKey?: number }>()
const emit = defineEmits<{ openLabor: [] }>()

const t = ja.attendance

function yen(amount: number | null): string {
  return amount === null ? '—' : formatYen(amount)
}

const summary = ref<AttendanceSummary | null>(null)
const loading = ref(true)
const loadFailed = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    summary.value = await fetchAttendanceSummary(props.month)
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [props.month, props.reloadKey], load)
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="att-summary-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="att-summary-heading"
        class="adm-panel__title"
      >
        {{ t.tabs.summary }}
      </h2>
      <a
        class="adm-btn"
        :href="attendanceExportUrl(month)"
        download
      >{{ t.csv }}</a>
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
    <template v-else-if="summary">
      <div v-if="summary.warnings.length > 0">
        <LaborWarningBanner
          :warnings="summary.warnings"
          :show-link="false"
        />
        <button
          type="button"
          class="adm-btn sum-to-labor"
          @click="emit('openLabor')"
        >
          {{ ja.laborWarning.toSettings }}
        </button>
      </div>
      <p
        v-if="summary.totals.total_pay === null"
        class="adm-error"
      >
        {{ t.payNull }}
      </p>
      <div class="sum-wrap">
        <table class="sum-table">
          <thead>
            <tr>
              <th scope="col">
                {{ t.col.name }}
              </th>
              <th scope="col">
                {{ t.col.days }}
              </th>
              <th scope="col">
                {{ t.col.work }}
              </th>
              <th scope="col">
                {{ t.col.overtime }}
              </th>
              <th scope="col">
                {{ t.col.over60 }}
              </th>
              <th scope="col">
                {{ t.col.night }}
              </th>
              <th scope="col">
                {{ t.col.holiday }}
              </th>
              <th scope="col">
                {{ t.col.scheduled }}
              </th>
              <th scope="col">
                {{ t.col.wage }}
              </th>
              <th scope="col">
                {{ t.col.basePay }}
              </th>
              <th scope="col">
                {{ t.col.premiumPay }}
              </th>
              <th scope="col">
                {{ t.col.totalPay }}
              </th>
              <th scope="col">
                {{ t.col.notes }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in summary.rows"
              :key="row.user_id"
              :class="{ 'sum-table__off': !row.is_active }"
            >
              <th scope="row">
                {{ row.name }}
              </th>
              <td>{{ row.days }}</td>
              <td>{{ formatMinutes(row.work_minutes) }}</td>
              <td>{{ formatMinutes(row.overtime_minutes) }}</td>
              <td>{{ formatMinutes(row.overtime_over60_minutes) }}</td>
              <td>{{ formatMinutes(row.night_minutes) }}</td>
              <td>{{ formatMinutes(row.holiday_minutes) }}</td>
              <td>{{ formatMinutes(row.scheduled_minutes) }}</td>
              <td>
                {{ yen(row.hourly_wage) }}
              </td>
              <td>
                {{ yen(row.base_pay) }}
              </td>
              <td>
                {{ yen(row.premium_pay) }}
              </td>
              <td class="sum-table__pay">
                {{ yen(row.total_pay) }}
              </td>
              <td class="sum-table__notes">
                <span
                  v-for="w in row.warnings"
                  :key="w"
                  class="adm-badge sum-table__warn"
                >{{ t.summaryWarnings[w] }}</span>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th scope="row">
                {{ t.total }}
              </th>
              <td>{{ summary.totals.days }}</td>
              <td>{{ formatMinutes(summary.totals.work_minutes) }}</td>
              <td>{{ formatMinutes(summary.totals.overtime_minutes) }}</td>
              <td />
              <td>{{ formatMinutes(summary.totals.night_minutes) }}</td>
              <td>{{ formatMinutes(summary.totals.holiday_minutes) }}</td>
              <td>{{ formatMinutes(summary.totals.scheduled_minutes) }}</td>
              <td />
              <td>
                {{ yen(summary.totals.base_pay) }}
              </td>
              <td>
                {{ yen(summary.totals.premium_pay) }}
              </td>
              <td class="sum-table__pay">
                {{ yen(summary.totals.total_pay) }}
              </td>
              <td />
            </tr>
          </tfoot>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.sum-to-labor { margin-top: 8px; }
.sum-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.sum-table { width: 100%; border-collapse: collapse; font-size: 16px; white-space: nowrap; }
.sum-table th,
.sum-table td { padding: 8px 10px; border-bottom: 1px solid var(--c-border); text-align: right; }
.sum-table th:first-child { position: sticky; left: 0; background: var(--c-surface); text-align: left; }
.sum-table thead th { color: var(--c-text-sub); }
.sum-table tfoot th,
.sum-table tfoot td { border-top: 2px solid var(--c-text); font-weight: 700; }
.sum-table__off { color: var(--c-text-sub); }
.sum-table__pay { font-weight: 700; }
.sum-table__notes { text-align: left; }
.sum-table__warn { margin-right: 4px; border-color: var(--c-danger); color: var(--c-danger); }
</style>
