<script setup lang="ts">
// S06 期間集計（08 §5.7）：/sales/summary?from=&to=。admin は ?store_id= の店舗を閲覧のみ。
// グラフ（Chart.js）は BarChart が表示されたときに読み込む（AC-S06-4）
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { exportUrl, fetchSummary, type ExportType, type SummaryReport } from '@/api/reports'
import AppHeader from '@/components/AppHeader.vue'
import BarChart from '@/components/BarChart.vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import StatTile from '@/components/StatTile.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatBusinessDate } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { permilleToPercent } from '@/lib/percent'
import { productSub } from '@/lib/productLabel'
import { isYmd, matchPreset, periodDays, periodError, presetPeriod, tokyoToday, type Period, type PeriodPreset } from '@/lib/period'
import { useAdminStore } from '@/stores/admin'
import { useAuthStore } from '@/stores/auth'
import '@/styles/admin.css'

const t = ja.summary
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const admin = useAdminStore()

const storeId = computed(() => {
  const raw = route.query.store_id
  return auth.isAdmin && typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null
})
const needsStore = computed(() => auth.isAdmin && storeId.value === null)
const today = computed(() => auth.me?.current_business_date ?? tokyoToday())

/** 表示する期間。?from=&to= が無ければ今月 */
const period = computed<Period>(() => {
  const { from, to } = route.query
  return isYmd(from) && isYmd(to) ? { from, to } : presetPeriod('thisMonth', today.value)
})
const periodProblem = computed(() => periodError(period.value))
const activePreset = computed(() => matchPreset(period.value, today.value))

const customOpen = ref(false)
const draft = ref<Period>({ ...period.value })
const draftProblem = ref<string | null>(null)

const report = ref<SummaryReport | null>(null)
const loading = ref(false)
const loadFailed = ref<string | null>(null)
const exportType = ref<ExportType>('daily')

const problemText = (p: ReturnType<typeof periodError>): string | null => (p === null ? null : t[p])

async function load(): Promise<void> {
  if (needsStore.value || periodProblem.value !== null) {
    report.value = null
    return
  }
  loading.value = true
  loadFailed.value = null
  try {
    report.value = await fetchSummary(period.value.from, period.value.to, storeId.value)
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

watch([() => period.value.from, () => period.value.to, storeId], load, { immediate: true })
watch(period, (p) => {
  draft.value = { ...p }
})

function goTo(p: Period): void {
  void router.replace({ query: { ...route.query, from: p.from, to: p.to } })
}

function choosePreset(preset: PeriodPreset): void {
  customOpen.value = false
  goTo(presetPeriod(preset, today.value))
}

function applyCustom(): void {
  const problem = periodError(draft.value)
  draftProblem.value = problemText(problem)
  if (problem === null) goTo(draft.value)
}

const presets: { key: PeriodPreset; label: string }[] = [
  { key: 'thisWeek', label: t.thisWeek },
  { key: 'thisMonth', label: t.thisMonth },
  { key: 'lastMonth', label: t.lastMonth },
]

const exportTypes: { key: ExportType; label: string }[] = [
  { key: 'daily', label: t.exportDaily },
  { key: 'sales', label: t.exportSales },
  { key: 'items', label: t.exportItems },
  { key: 'tax', label: t.exportTax },
]

const downloadHref = computed(() => exportUrl(exportType.value, period.value.from, period.value.to, storeId.value))

/** 期間の表示は年を付ける：2026/9/1（火） */
function fullDate(ymd: string): string {
  return `${ymd.slice(0, 4)}/${formatBusinessDate(ymd)}`
}

function shortDate(ymd: string): string {
  const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(ymd)
  return m ? `${Number(m[1])}/${Number(m[2])}` : ymd
}

const dateLabels = computed(() => report.value?.by_date.map((r) => shortDate(r.date)) ?? [])
const dateValues = computed(() => report.value?.by_date.map((r) => r.total) ?? [])
const hourLabels = computed(() => report.value?.by_hour.map((r) => String(r.hour)) ?? [])
const hourValues = computed(() => report.value?.by_hour.map((r) => r.total) ?? [])
</script>

<template>
  <div class="adm-page">
    <AppHeader
      :title="t.title"
      :viewing-store-name="auth.isAdmin && storeId !== null ? admin.viewingLabel(storeId) : null"
    />
    <main class="adm-body">
      <div
        v-if="needsStore"
        class="adm-panel"
      >
        <p>{{ ja.viewing.chooseStore }}</p>
        <div class="adm-actions">
          <RouterLink
            :to="{ name: 'admin-stores' }"
            class="adm-btn"
          >
            {{ ja.viewing.toStores }}
          </RouterLink>
        </div>
      </div>

      <template v-else>
        <section
          class="adm-panel"
          :aria-label="t.title"
        >
          <div
            class="adm-actions"
            role="group"
            :aria-label="t.title"
          >
            <button
              v-for="p in presets"
              :key="p.key"
              type="button"
              class="adm-btn"
              :class="{ 'adm-btn--on': activePreset === p.key && !customOpen }"
              :aria-pressed="activePreset === p.key && !customOpen"
              @click="choosePreset(p.key)"
            >
              {{ p.label }}
            </button>
            <button
              type="button"
              class="adm-btn"
              :class="{ 'adm-btn--on': customOpen || activePreset === null }"
              :aria-expanded="customOpen"
              @click="customOpen = !customOpen"
            >
              {{ t.custom }}
            </button>
          </div>
          <form
            v-if="customOpen"
            class="summary-custom"
            novalidate
            @submit.prevent="applyCustom"
          >
            <label class="adm-field">
              <span class="adm-field__label">{{ t.from }}</span>
              <input
                v-model="draft.from"
                type="date"
                class="adm-input"
                :aria-invalid="draftProblem !== null"
              >
            </label>
            <label class="adm-field">
              <span class="adm-field__label">{{ t.to }}</span>
              <input
                v-model="draft.to"
                type="date"
                class="adm-input"
                :aria-invalid="draftProblem !== null"
              >
            </label>
            <BigButton type="submit">
              {{ t.show }}
            </BigButton>
            <p
              v-if="draftProblem"
              class="adm-error summary-custom__error"
              role="alert"
            >
              {{ draftProblem }}
            </p>
          </form>
          <p
            v-if="periodProblem"
            class="adm-error"
            role="alert"
          >
            {{ problemText(periodProblem) }}
          </p>
          <h2
            v-else
            class="summary-period tabular"
          >
            {{ fmt(t.period, { from: fullDate(period.from), to: fullDate(period.to), n: periodDays(period) }) }}
          </h2>
        </section>

        <p
          v-if="loading && !report"
          role="status"
        >
          {{ ja.common.loading }}
        </p>
        <div
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
        </div>
        <template v-else-if="report">
          <section
            class="summary-tiles"
            :aria-label="t.title"
          >
            <StatTile
              :label="t.total"
              :amount="report.totals.total"
            />
            <StatTile
              :label="t.count"
              :value="fmt(t.countValue, { n: report.totals.count })"
            />
            <StatTile
              :label="t.customers"
              :value="fmt(t.customersValue, { n: report.totals.customers })"
            />
            <StatTile
              :label="t.average"
              :amount="report.totals.average"
            />
          </section>
          <p class="summary-sub tabular">
            <span>{{ fmt(t.discountTotal, { amount: formatYen(report.totals.discount_total) }) }}</span>
            <span>{{ fmt(t.cancelledCount, { n: report.totals.cancelled_count }) }}</span>
          </p>

          <section
            class="adm-panel"
            aria-labelledby="by-date-heading"
          >
            <h2
              id="by-date-heading"
              class="adm-panel__title"
            >
              {{ t.byDate }}
            </h2>
            <BarChart
              :labels="dateLabels"
              :values="dateValues"
              :label="t.byDate"
            />
            <details>
              <summary class="summary-toggle">
                {{ t.showTable }}
              </summary>
              <div class="table-wrap">
                <table
                  class="summary-table"
                  data-test="by-date"
                >
                  <thead>
                    <tr>
                      <th scope="col">
                        {{ t.date }}
                      </th>
                      <th scope="col">
                        {{ t.amount }}
                      </th>
                      <th scope="col">
                        {{ t.count }}
                      </th>
                      <th scope="col">
                        {{ t.customers }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="r in report.by_date"
                      :key="r.date"
                    >
                      <td>{{ formatBusinessDate(r.date) }}</td>
                      <td class="num">
                        <MoneyText :amount="r.total" />
                      </td>
                      <td class="num">
                        {{ r.count }}
                      </td>
                      <td class="num">
                        {{ r.customers }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </section>

          <section
            class="adm-panel"
            aria-labelledby="by-hour-heading"
          >
            <h2
              id="by-hour-heading"
              class="adm-panel__title"
            >
              {{ t.byHour }}
            </h2>
            <BarChart
              :labels="hourLabels"
              :values="hourValues"
              :label="t.byHour"
            />
            <details>
              <summary class="summary-toggle">
                {{ t.showTable }}
              </summary>
              <div class="table-wrap">
                <table class="summary-table">
                  <thead>
                    <tr>
                      <th scope="col">
                        {{ t.hour }}
                      </th>
                      <th scope="col">
                        {{ t.amount }}
                      </th>
                      <th scope="col">
                        {{ t.count }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="r in report.by_hour"
                      :key="r.hour"
                    >
                      <td>{{ fmt(t.hourValue, { h: r.hour }) }}</td>
                      <td class="num">
                        <MoneyText :amount="r.total" />
                      </td>
                      <td class="num">
                        {{ r.count }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </section>

          <section
            class="adm-panel"
            aria-labelledby="ranking-heading"
          >
            <h2
              id="ranking-heading"
              class="adm-panel__title"
            >
              {{ t.ranking }}
            </h2>
            <p class="adm-help">
              {{ ja.daily.productNote }}
            </p>
            <p v-if="report.ranking.length === 0">
              {{ t.rankingEmpty }}
            </p>
            <div
              v-else
              class="table-wrap"
            >
              <table
                class="summary-table"
                data-test="ranking"
              >
                <thead>
                  <tr>
                    <th scope="col">
                      {{ t.rank }}
                    </th>
                    <th scope="col">
                      {{ ja.daily.productName }}
                    </th>
                    <th scope="col">
                      {{ ja.daily.productQuantity }}
                    </th>
                    <th scope="col">
                      {{ ja.daily.productAmount }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(r, i) in report.ranking"
                    :key="`${r.product_id}-${r.product_name}-${r.product_code}-${r.product_memo ?? ''}`"
                  >
                    <td class="num">
                      {{ i + 1 }}
                    </td>
                    <td class="product-cell">
                      <span>{{ r.product_name }}</span>
                      <span
                        v-if="productSub(r) !== ''"
                        class="product-cell__sub"
                      >{{ productSub(r) }}</span>
                    </td>
                    <td class="num">
                      {{ r.quantity }}
                    </td>
                    <td class="num">
                      <MoneyText :amount="r.amount" />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <div class="summary-tables">
            <section
              class="adm-panel"
              aria-labelledby="tax-heading"
            >
              <h2
                id="tax-heading"
                class="adm-panel__title"
              >
                {{ ja.daily.byTax }}
              </h2>
              <div class="table-wrap">
                <table class="summary-table">
                  <thead>
                    <tr>
                      <th scope="col">
                        {{ ja.daily.taxName }}
                      </th>
                      <th scope="col">
                        {{ ja.daily.taxRate }}
                      </th>
                      <th scope="col">
                        {{ ja.daily.taxTaxable }}
                      </th>
                      <th scope="col">
                        {{ ja.daily.taxAmount }}
                      </th>
                      <th scope="col">
                        {{ ja.daily.taxTotal }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="r in report.by_tax"
                      :key="`${r.tax_type_name}-${r.rate_permille}`"
                    >
                      <td>{{ r.tax_type_name }}</td>
                      <td class="num">
                        {{ permilleToPercent(r.rate_permille) }}%
                      </td>
                      <td class="num">
                        <MoneyText :amount="r.taxable_amount" />
                      </td>
                      <td class="num">
                        <MoneyText :amount="r.tax_amount" />
                      </td>
                      <td class="num">
                        <MoneyText :amount="r.total" />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>

            <section
              class="adm-panel"
              aria-labelledby="pay-heading"
            >
              <h2
                id="pay-heading"
                class="adm-panel__title"
              >
                {{ ja.daily.byPayment }}
              </h2>
              <div class="table-wrap">
                <table class="summary-table">
                  <thead>
                    <tr>
                      <th scope="col">
                        {{ ja.daily.payName }}
                      </th>
                      <th scope="col">
                        {{ ja.daily.payCount }}
                      </th>
                      <th scope="col">
                        {{ ja.daily.payTotal }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="r in report.by_payment"
                      :key="`${r.payment_method_name}-${r.is_cash}`"
                    >
                      <td>{{ r.payment_method_name }}</td>
                      <td class="num">
                        {{ r.count }}
                      </td>
                      <td class="num">
                        <MoneyText :amount="r.total" />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </template>

        <section
          v-if="periodProblem === null"
          class="adm-panel"
          aria-labelledby="export-heading"
        >
          <h2
            id="export-heading"
            class="adm-panel__title"
          >
            {{ t.exportHeading }}
          </h2>
          <div class="adm-actions">
            <label class="adm-field summary-export__type">
              <span class="adm-field__label">{{ t.exportType }}</span>
              <select
                v-model="exportType"
                class="adm-select"
              >
                <option
                  v-for="e in exportTypes"
                  :key="e.key"
                  :value="e.key"
                >
                  {{ e.label }}
                </option>
              </select>
            </label>
            <a
              class="adm-btn summary-export__link"
              :href="downloadHref"
              download
              data-test="export"
            >{{ t.exportDownload }}</a>
          </div>
          <p class="adm-help">
            {{ t.exportHelp }}
          </p>
        </section>
      </template>
    </main>
  </div>
</template>

<style scoped>
.summary-custom { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.summary-custom .adm-field { flex: 1 1 160px; }
.summary-custom__error { flex-basis: 100%; }
.summary-period { font-size: var(--fs-heading); }

.summary-tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.summary-sub { display: flex; flex-wrap: wrap; gap: 8px 24px; color: var(--c-text-sub); font-weight: 700; }

.summary-toggle { min-height: var(--tap-min); display: flex; align-items: center; color: var(--c-primary); font-weight: 700; cursor: pointer; }
.summary-tables { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; }
.summary-export__type { flex: 0 1 auto; }
.summary-export__link { align-self: flex-end; text-decoration: none; }

.table-wrap { overflow-x: auto; }
.summary-table { width: 100%; border-collapse: collapse; font-size: var(--fs-body); }
.summary-table th,
.summary-table td { padding: 10px 8px; border-bottom: 1px solid var(--c-border); text-align: left; white-space: nowrap; }
.summary-table th { color: var(--c-text-sub); font-size: 16px; }
.summary-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.summary-table .product-cell { min-width: 8em; white-space: normal; overflow-wrap: anywhere; }
.product-cell__sub { display: block; color: var(--c-text-sub); font-size: 14px; }

@media (min-width: 768px) {
  .summary-tiles { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .summary-tables { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
