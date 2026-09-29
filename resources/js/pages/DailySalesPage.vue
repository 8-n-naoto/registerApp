<script setup lang="ts">
// S04 日次売上（08 §5.5）：/sales/daily?date=。admin は ?store_id= で店舗を指定して閲覧のみ。
// 開くときの API は GET /reports/daily の 1 回だけ（AC-S04-5）。会計の行をタップすると S05 を開く
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { fetchDailyReport, type DailyReport } from '@/api/reports'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import SaleDetailDialog from '@/components/sales/SaleDetailDialog.vue'
import StatTile from '@/components/StatTile.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatBusinessDate, formatTime, shiftDate } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { permilleToPercent } from '@/lib/percent'
import { applyCancel, closingState } from '@/lib/report'
import { useAuthStore } from '@/stores/auth'
import type { Sale } from '@/types/api'
import '@/styles/admin.css'

const t = ja.daily
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const report = ref<DailyReport | null>(null)
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const openSaleId = ref<number | null>(null)

const storeId = computed(() => {
  const raw = route.query.store_id
  return auth.isAdmin && typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null
})

/** 表示する日。staff は常に現在の営業日（省略）。形式の違う ?date= は無視する */
const queryDate = computed(() => {
  const raw = route.query.date
  return !auth.isStaff && typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : null
})

const canChangeDate = computed(() => !auth.isStaff)
const readOnly = computed(() => auth.isAdmin)
const today = computed(() => auth.me?.current_business_date ?? null)
const closing = computed(() => (report.value ? closingState(report.value) : 'none'))
const closingLabel = computed(() => ({ none: t.closingNone, done: t.closingDone, changed: t.closingChanged })[closing.value])

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    report.value = await fetchDailyReport(queryDate.value, storeId.value)
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

watch([queryDate, storeId], load, { immediate: true })

function goTo(date: string | null): void {
  const query = { ...route.query }
  if (date === null) delete query.date
  else query.date = date
  void router.replace({ query })
}

function shift(days: number): void {
  if (report.value) goTo(shiftDate(report.value.date, days))
}

function onPick(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) goTo(value)
}

function onCancelled(sale: Sale): void {
  if (report.value) report.value = applyCancel(report.value, sale)
}
</script>

<template>
  <div class="adm-page">
    <AppHeader
      :title="t.title"
      :viewing-store-name="readOnly ? t.viewOnly : null"
    />
    <main class="adm-body">
      <div
        class="daily-nav"
        :class="{ 'daily-nav--fixed': !canChangeDate }"
      >
        <button
          v-if="canChangeDate"
          type="button"
          class="adm-btn"
          :disabled="!report"
          @click="shift(-1)"
        >
          {{ t.prevDay }}
        </button>
        <h2 class="daily-nav__date tabular">
          {{ report ? formatBusinessDate(report.date) : '' }}
        </h2>
        <template v-if="canChangeDate">
          <button
            type="button"
            class="adm-btn"
            :disabled="!report"
            @click="shift(1)"
          >
            {{ t.nextDay }}
          </button>
          <input
            type="date"
            class="adm-input daily-nav__picker"
            :value="report?.date ?? ''"
            :aria-label="t.pickDate"
            @change="onPick"
          >
          <button
            type="button"
            class="adm-btn"
            :disabled="report !== null && today !== null && report.date === today"
            @click="goTo(null)"
          >
            {{ t.today }}
          </button>
        </template>
      </div>

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
          class="daily-tiles"
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
        <p class="daily-sub tabular">
          <span>{{ fmt(t.discountTotal, { amount: formatYen(report.totals.discount_total) }) }}</span>
          <span>{{ fmt(t.cancelledCount, { n: report.totals.cancelled_count }) }}</span>
        </p>

        <section
          class="adm-panel daily-closing"
          aria-labelledby="closing-heading"
        >
          <h2
            id="closing-heading"
            class="adm-panel__title"
          >
            {{ t.closingHeading }}
          </h2>
          <span
            class="daily-closing__state"
            :class="`daily-closing__state--${closing}`"
            data-test="closing-state"
          >{{ closingLabel }}</span>
          <RouterLink
            v-if="!readOnly"
            :to="{ name: 'closing', query: auth.isStaff ? {} : { date: report.date } }"
            class="adm-btn"
          >
            {{ t.toClosing }}
          </RouterLink>
        </section>

        <section
          class="adm-panel"
          aria-labelledby="sales-heading"
        >
          <h2
            id="sales-heading"
            class="adm-panel__title"
          >
            {{ t.sales }}
          </h2>
          <p v-if="report.sales.length === 0">
            {{ t.salesEmpty }}
          </p>
          <ul
            v-else
            class="adm-list"
          >
            <li
              v-for="s in report.sales"
              :key="s.id"
            >
              <button
                type="button"
                class="sale-row"
                :class="{ 'sale-row--cancelled': s.status === 'cancelled' }"
                :aria-label="fmt(t.openSale, { time: formatTime(s.sold_at), amount: formatYen(s.total) })"
                @click="openSaleId = s.id"
              >
                <span class="sale-row__time tabular">{{ formatTime(s.sold_at) }}</span>
                <MoneyText
                  class="sale-row__total"
                  :amount="s.total"
                  tone="inherit"
                />
                <span class="sale-row__pay">{{ s.payment_method_name }}</span>
                <span class="sale-row__user">{{ s.user_name }}</span>
                <span
                  class="adm-badge"
                  :class="{ 'sale-row__badge--cancelled': s.status === 'cancelled' }"
                >{{ s.status === 'cancelled' ? t.cancelled : t.completed }}</span>
              </button>
            </li>
          </ul>
        </section>

        <div class="daily-tables">
          <section
            class="adm-panel"
            aria-labelledby="tax-heading"
          >
            <h2
              id="tax-heading"
              class="adm-panel__title"
            >
              {{ t.byTax }}
            </h2>
            <div class="table-wrap">
              <table class="daily-table">
                <thead>
                  <tr>
                    <th scope="col">
                      {{ t.taxName }}
                    </th>
                    <th scope="col">
                      {{ t.taxRate }}
                    </th>
                    <th scope="col">
                      {{ t.taxTaxable }}
                    </th>
                    <th scope="col">
                      {{ t.taxAmount }}
                    </th>
                    <th scope="col">
                      {{ t.taxTotal }}
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
              {{ t.byPayment }}
            </h2>
            <div class="table-wrap">
              <table class="daily-table">
                <thead>
                  <tr>
                    <th scope="col">
                      {{ t.payName }}
                    </th>
                    <th scope="col">
                      {{ t.payCount }}
                    </th>
                    <th scope="col">
                      {{ t.payTotal }}
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

        <section
          class="adm-panel"
          aria-labelledby="product-heading"
        >
          <h2
            id="product-heading"
            class="adm-panel__title"
          >
            {{ t.byProduct }}
          </h2>
          <p class="adm-help">
            {{ t.productNote }}
          </p>
          <div class="table-wrap">
            <table class="daily-table">
              <thead>
                <tr>
                  <th scope="col">
                    {{ t.productName }}
                  </th>
                  <th scope="col">
                    {{ t.productQuantity }}
                  </th>
                  <th scope="col">
                    {{ t.productAmount }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="r in report.by_product"
                  :key="`${r.product_id}-${r.product_name}`"
                >
                  <td>{{ r.product_name }}</td>
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
      </template>
    </main>
    <SaleDetailDialog
      :sale-id="openSaleId"
      :store-id="storeId"
      :read-only="readOnly"
      :staff-date="auth.isStaff ? (report?.date ?? null) : null"
      @close="openSaleId = null"
      @cancelled="onCancelled"
      @stale="load"
    />
  </div>
</template>

<style scoped>
.daily-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.daily-nav__date { min-width: 8em; font-size: var(--fs-heading); text-align: center; }
.daily-nav--fixed .daily-nav__date { text-align: left; }
.daily-nav__picker { width: auto; }

.daily-tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.daily-sub { display: flex; flex-wrap: wrap; gap: 8px 24px; color: var(--c-text-sub); font-weight: 700; }

.daily-closing { flex-direction: row; flex-wrap: wrap; align-items: center; justify-content: space-between; }
.daily-closing__state { padding: 4px 16px; border-radius: 999px; font-size: 18px; font-weight: 800; }
.daily-closing__state--none { background: var(--c-surface-alt); color: var(--c-text-sub); border: 1px solid var(--c-border); }
.daily-closing__state--done { background: var(--c-success); color: var(--c-on-primary); }
.daily-closing__state--changed { background: var(--c-change); color: var(--c-on-primary); }

.sale-row {
  display: grid;
  grid-template-columns: 4em minmax(6em, auto) 1fr auto;
  grid-template-areas: 'time total pay badge' 'time total user badge';
  align-items: center;
  gap: 2px 12px;
  width: 100%;
  min-height: var(--tap-min);
  padding: 8px 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: var(--fs-body);
  text-align: left;
}

.sale-row:active { background: var(--c-surface-alt); }
.sale-row__time { grid-area: time; font-weight: 700; }
.sale-row__total { grid-area: total; font-weight: 800; }
.sale-row__pay { grid-area: pay; }
.sale-row__user { grid-area: user; color: var(--c-text-sub); }
.sale-row .adm-badge { grid-area: badge; }
.sale-row--cancelled { background: var(--c-surface-alt); color: var(--c-soldout); }
.sale-row--cancelled .sale-row__total { text-decoration: line-through; }
.sale-row__badge--cancelled { border-color: var(--c-danger); color: var(--c-danger); }

.daily-tables { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; }
.table-wrap { overflow-x: auto; }
.daily-table { width: 100%; border-collapse: collapse; font-size: var(--fs-body); }
.daily-table th,
.daily-table td { padding: 10px 8px; border-bottom: 1px solid var(--c-border); text-align: left; white-space: nowrap; }
.daily-table th { color: var(--c-text-sub); font-size: 16px; }
.daily-table .num { text-align: right; font-variant-numeric: tabular-nums; }

@media (min-width: 768px) {
  .daily-tiles { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .sale-row { grid-template-columns: 5em 8em 1fr 1fr auto; grid-template-areas: 'time total pay user badge'; }
  .daily-tables { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
