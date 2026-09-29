<script setup lang="ts">
// S07 レジ締め（08 §5.8）：/closing?date=。staff は現在の営業日のみ。
// 現金売上は GET /closings/{date} の再計算値。あるべき現金・過不足は画面で計算して見せ、保存時はサーバーが計算し直す
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { fetchClosing, saveClosing, type ClosingView } from '@/api/reports'
import AppHeader from '@/components/AppHeader.vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { closingFigures, countCash, DENOMINATIONS, differenceKind, type Denomination } from '@/lib/closing'
import { formatBusinessDate, formatMonthDayTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { parseNonNegativeInt } from '@/lib/numberInput'
import { useAuthStore } from '@/stores/auth'
import '@/styles/admin.css'

const MAX = 99_999_999

const t = ja.closing
const route = useRoute()
const auth = useAuthStore()

const view = ref<ClosingView | null>(null)
const loading = ref(true)
const loadFailed = ref<string | null>(null)

const floatText = ref('')
const countedText = ref('')
const memo = ref('')
const counts = reactive<Partial<Record<Denomination, number | null>>>({})
const countTexts = reactive<Partial<Record<Denomination, string>>>({})

const saving = ref(false)
const saved = ref(false)
const errors = ref<Record<string, string>>({})
const saveFailed = ref<string | null>(null)

/** 締める営業日。staff は現在の営業日。owner は ?date=（無ければ現在の営業日） */
const date = computed(() => {
  const raw = route.query.date
  if (!auth.isStaff && typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw
  return auth.me?.current_business_date ?? ''
})

const floatAmount = computed(() => parseAmount(floatText.value))
const countedCash = computed(() => parseAmount(countedText.value))
const figures = computed(() =>
  view.value && floatAmount.value !== null && countedCash.value !== null
    ? closingFigures(floatAmount.value, view.value.cash_sales, countedCash.value)
    : null,
)
const expected = computed(() => (view.value && floatAmount.value !== null ? floatAmount.value + view.value.cash_sales : null))
const kind = computed(() => (figures.value ? differenceKind(figures.value.difference) : null))
const differenceText = computed(() => {
  if (!figures.value || !kind.value) return ''
  const d = figures.value.difference
  const amount = d > 0 ? `+${formatYen(d)}` : formatYen(d)
  return `${amount}（${t[kind.value]}）`
})
const denominationTotal = computed(() => countCash(counts))

function parseAmount(text: string): number | null {
  const n = parseNonNegativeInt(text)
  return n !== null && n <= MAX ? n : null
}

async function load(): Promise<void> {
  if (date.value === '') return
  loading.value = true
  loadFailed.value = null
  saved.value = false
  try {
    const data = await fetchClosing(date.value)
    view.value = data
    floatText.value = data.closing ? String(data.closing.float_amount) : ''
    countedText.value = data.closing ? String(data.closing.counted_cash) : ''
    memo.value = data.closing?.memo ?? ''
  } catch (err) {
    if (isNetworkError(err)) loadFailed.value = ja.error.network
    else loadFailed.value = errorBody(err)?.message ?? t.loadFailed
  } finally {
    loading.value = false
  }
}

watch(date, load, { immediate: true })

/** 金種の枚数を入れたら、合計を実際の現金に入れる（AC-S07-3） */
function onCount(d: Denomination, text: string): void {
  countTexts[d] = text
  const n = parseNonNegativeInt(text)
  counts[d] = n
  if (DENOMINATIONS.some((x) => (counts[x] ?? null) !== null)) countedText.value = String(denominationTotal.value)
}

async function save(): Promise<void> {
  if (saving.value || !view.value) return
  saved.value = false
  saveFailed.value = null
  const local: Record<string, string> = {}
  if (floatAmount.value === null) local.float_amount = t.amountInvalid
  if (countedCash.value === null) local.counted_cash = t.amountInvalid
  errors.value = local
  if (floatAmount.value === null || countedCash.value === null) return

  saving.value = true
  try {
    const closing = await saveClosing(date.value, {
      float_amount: floatAmount.value,
      counted_cash: countedCash.value,
      memo: memo.value.trim() === '' ? null : memo.value.trim(),
    })
    view.value = { ...view.value, cash_sales: closing.cash_sales, closing }
    saved.value = true
  } catch (err) {
    if (errorStatus(err) === 422) {
      errors.value = fieldErrors(err)
      if (errors.value.date) saveFailed.value = errors.value.date
    } else {
      saveFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? ja.error.unexpected)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="adm-page">
    <AppHeader :title="t.title" />
    <main class="adm-body">
      <div class="closing-head">
        <h2 class="closing-head__date tabular">
          {{ date ? fmt(t.businessDate, { date: formatBusinessDate(date) }) : '' }}
        </h2>
        <RouterLink
          :to="{ name: 'sales-daily', query: auth.isStaff ? {} : { date } }"
          class="adm-btn"
        >
          {{ t.toDaily }}
        </RouterLink>
      </div>

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
      <template v-else-if="view">
        <p
          v-if="view.closing?.changed_after_close"
          class="closing-alert"
          role="alert"
          data-test="changed-after-close"
        >
          {{ t.changedAfterClose }}
        </p>

        <form
          class="adm-panel closing-form"
          novalidate
          @submit.prevent="save"
        >
          <div class="closing-line">
            <span class="closing-line__label">{{ t.cashSales }}</span>
            <MoneyText
              :amount="view.cash_sales"
              size="amount"
              tone="money"
              data-test="cash-sales"
            />
            <p class="adm-help closing-line__help">
              {{ t.cashSalesHelp }}
              <template v-if="view.closing && view.closing.cash_sales !== view.cash_sales">
                ／{{ fmt(t.savedCashSales, { amount: formatYen(view.closing.cash_sales) }) }}
              </template>
            </p>
          </div>

          <div class="adm-field">
            <label
              for="closing-float"
              class="adm-field__label"
            >{{ t.floatAmount }}</label>
            <input
              id="closing-float"
              v-model="floatText"
              class="adm-input closing-input tabular"
              inputmode="numeric"
              autocomplete="off"
              :aria-invalid="errors.float_amount ? 'true' : undefined"
            >
            <p
              v-if="errors.float_amount"
              class="adm-error"
            >
              {{ errors.float_amount }}
            </p>
          </div>

          <div class="closing-line">
            <span class="closing-line__label">{{ t.expected }}</span>
            <MoneyText
              v-if="expected !== null"
              :amount="expected"
              size="amount"
              data-test="expected"
            />
            <span v-else>—</span>
          </div>

          <div class="adm-field">
            <label
              for="closing-counted"
              class="adm-field__label"
            >{{ t.counted }}</label>
            <input
              id="closing-counted"
              v-model="countedText"
              class="adm-input closing-input tabular"
              inputmode="numeric"
              autocomplete="off"
              :aria-invalid="errors.counted_cash ? 'true' : undefined"
            >
            <p
              v-if="errors.counted_cash"
              class="adm-error"
            >
              {{ errors.counted_cash }}
            </p>
          </div>

          <details class="closing-denoms">
            <summary class="closing-denoms__summary">
              {{ t.denominations }}
            </summary>
            <p class="adm-help">
              {{ t.denominationsHelp }}
            </p>
            <div class="closing-denoms__grid">
              <label
                v-for="d in DENOMINATIONS"
                :key="d"
                class="closing-denom"
              >
                <span class="closing-denom__yen tabular">{{ formatYen(d) }}</span>
                <input
                  class="adm-input closing-denom__input tabular"
                  inputmode="numeric"
                  autocomplete="off"
                  :value="countTexts[d] ?? ''"
                  :aria-label="fmt(t.denominationLabel, { yen: formatYen(d) })"
                  :data-test="`denom-${d}`"
                  @input="onCount(d, ($event.target as HTMLInputElement).value)"
                >
                <span>{{ t.pieces }}</span>
              </label>
            </div>
            <p
              class="closing-denoms__total tabular"
              data-test="denom-total"
            >
              {{ fmt(t.denominationTotal, { amount: formatYen(denominationTotal) }) }}
            </p>
          </details>

          <div
            class="closing-diff"
            :class="kind ? `closing-diff--${kind}` : ''"
          >
            <span class="closing-line__label">{{ t.difference }}</span>
            <strong
              class="closing-diff__value tabular"
              data-test="difference"
            >{{ differenceText || '—' }}</strong>
          </div>

          <div class="adm-field">
            <label
              for="closing-memo"
              class="adm-field__label"
            >{{ t.memo }}</label>
            <textarea
              id="closing-memo"
              v-model="memo"
              class="adm-input closing-memo"
              maxlength="200"
              rows="2"
              :aria-invalid="errors.memo ? 'true' : undefined"
            />
            <p
              v-if="errors.memo"
              class="adm-error"
            >
              {{ errors.memo }}
            </p>
          </div>

          <p
            v-if="saveFailed"
            class="adm-error"
            role="alert"
          >
            {{ saveFailed }}
          </p>
          <div class="adm-actions closing-actions">
            <BigButton
              type="submit"
              size="lg"
              :loading="saving"
            >
              {{ ja.common.save }}
            </BigButton>
            <span
              v-if="saved"
              class="adm-ok"
              role="status"
            >{{ ja.common.saved }}</span>
            <span
              class="adm-help"
              data-test="last-saved"
            >
              {{ view.closing ? fmt(t.lastSaved, { at: formatMonthDayTime(view.closing.updated_at), name: view.closing.user_name }) : t.notClosed }}
            </span>
          </div>
        </form>
      </template>
    </main>
  </div>
</template>

<style scoped>
.closing-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.closing-head__date { font-size: var(--fs-heading); }

.closing-alert {
  padding: 12px 16px;
  border: 2px solid var(--c-change);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-change);
  font-weight: 700;
}

.closing-form { max-width: 640px; }
.closing-line { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; }
.closing-line__label { font-weight: 700; }
.closing-line__help { flex-basis: 100%; }
.closing-input { max-width: 16em; font-size: 24px; text-align: right; }
.closing-memo { min-height: 96px; padding: 12px 14px; }

.closing-denoms { padding: 8px 12px; border: 1px solid var(--c-border); border-radius: var(--radius); }
.closing-denoms__summary { display: flex; align-items: center; min-height: var(--tap-min); font-weight: 700; cursor: pointer; }
.closing-denoms__grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; margin-top: 8px; }
.closing-denom { display: grid; grid-template-columns: 6em minmax(0, 1fr) auto; align-items: center; gap: 8px; }
.closing-denom__yen { font-weight: 700; text-align: right; }
.closing-denom__input { text-align: right; }
.closing-denoms__total { margin-top: 8px; font-size: 18px; font-weight: 800; text-align: right; }

.closing-diff {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 4px 12px;
  padding: 16px;
  border-radius: var(--radius-card);
  background: var(--c-surface-alt);
}

.closing-diff__value { font-size: var(--fs-total); font-weight: 800; line-height: 1.1; }
.closing-diff--short { color: var(--c-danger); }
.closing-diff--over { color: var(--c-change); }
.closing-diff--even { color: var(--c-success); }
.closing-actions { justify-content: flex-start; }

@media (min-width: 768px) {
  .closing-denoms__grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
</style>
