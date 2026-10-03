<script setup lang="ts">
// お会計ダイアログ（08 §5.3）：詳細（明細・小計・値引き・税・点数）、合計、支払方法、現金なら預かり金（即入力・数字キー）とお釣り / 「あと ¥N」、
// 客数、メモ、［確定］［戻る］。
// 15 §8.1 プリンターがある店舗は［レシート］の切替（開くたびに「印刷しない」）。
// タブレットは中央、スマホは全画面。通信エラーで閉じずに残るよう、入力は開き直したときだけ初期化する
import { computed, nextTick, ref, watch } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import CheckoutDetail from '@/components/register/CheckoutDetail.vue'
import MoneyText from '@/components/MoneyText.vue'
import NumericKeypad from '@/components/NumericKeypad.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'
import { PricingError, settle } from '@/lib/pricing'
import type { ConfirmExtra } from '@/stores/register'
import type { PaymentMethod } from '@/types/api'

const MAX_RECEIVED = 99_999_999 // 06 §4.2
const MAX_CUSTOMERS = 999
const MEMO_MAX = 200

const props = defineProps<{
  open: boolean
  total: number
  paymentMethod: PaymentMethod
  paymentMethods: PaymentMethod[]
  submitting: boolean
  error: string | null
  /** 店舗にプリンターがあるときだけ true（切替を出す） */
  printable?: boolean
}>()

const emit = defineEmits<{ confirm: [extra: ConfirmExtra, print: boolean]; back: []; pay: [id: number] }>()

const t = ja.register
const received = ref<number | null>(null)
const customerCount = ref<number | null>(null)
const memo = ref('')
const print = ref<'off' | 'on'>('off')
const PRINT_OPTIONS = [
  { value: 'off', label: ja.print.off },
  { value: 'on', label: ja.print.on },
] as const
const panel = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    received.value = null
    customerCount.value = null
    memo.value = ''
    print.value = 'off'
    await nextTick()
    panel.value?.focus()
  },
)

/** 現金の精算。預かり金が足りない間は shortage（「あと ¥N」）を出し、確定させない */
const cash = computed<{ change: number | null; shortage: number | null }>(() => {
  if (!props.paymentMethod.is_cash) return { change: null, shortage: null }
  if (received.value === null) return { change: null, shortage: props.total }
  try {
    return { change: settle(props.total, true, received.value).change_amount, shortage: null }
  } catch (err) {
    if (err instanceof PricingError) return { change: null, shortage: props.total - received.value }
    throw err
  }
})

const canConfirm = computed(() => !props.submitting && cash.value.shortage === null)

const QUICK = [1000, 5000, 10000] as const

function stepCustomers(delta: 1 | -1): void {
  const next = (customerCount.value ?? 0) + delta
  customerCount.value = next < 1 ? null : Math.min(next, MAX_CUSTOMERS)
}

function confirm(): void {
  if (!canConfirm.value) return
  const text = memo.value.trim()
  emit('confirm', {
    received: props.paymentMethod.is_cash ? received.value : null,
    customer_count: customerCount.value,
    memo: text === '' ? null : text,
  }, props.printable === true && print.value === 'on')
}

function back(): void {
  if (!props.submitting) emit('back')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="checkout-backdrop r-scrim"
      @keydown.esc="back"
    >
      <section
        ref="panel"
        class="checkout"
        role="dialog"
        aria-modal="true"
        aria-labelledby="checkout-title"
        tabindex="-1"
      >
        <div class="checkout__cols">
          <div class="checkout__main">
            <CheckoutDetail />
            <header class="checkout__head">
              <h2
                id="checkout-title"
                class="checkout__label r-sum__l"
              >
                {{ t.total }}
              </h2>
              <MoneyText
                :amount="total"
                size="total"
                tone="money"
              />
            </header>

            <div
              class="checkout__pay"
              role="radiogroup"
              :aria-label="t.paymentMethod"
            >
              <button
                v-for="method in paymentMethods"
                :key="method.id"
                type="button"
                role="radio"
                class="pay r-btn"
                :class="paymentMethod.id === method.id ? 'pay--on r-btn--primary' : 'r-btn--secondary'"
                :aria-checked="paymentMethod.id === method.id"
                :disabled="submitting"
                @click="emit('pay', method.id)"
              >
                {{ method.name }}
              </button>
            </div>

            <template v-if="paymentMethod.is_cash">
              <div class="checkout__received">
                <span class="checkout__label r-sum__l">{{ t.received }}</span>
                <span
                  class="checkout__received-value tabular"
                  aria-live="polite"
                >{{ received === null ? '¥—' : formatYen(received) }}</span>
              </div>
              <div
                class="checkout__change"
                aria-live="polite"
              >
                <template v-if="cash.change !== null">
                  <span class="checkout__label r-sum__l">{{ t.change }}</span>
                  <MoneyText
                    :amount="cash.change"
                    size="change"
                    tone="change"
                  />
                </template>
                <span
                  v-else-if="cash.shortage !== null"
                  class="checkout__shortage tabular"
                >{{ fmt(t.shortage, { amount: formatYen(cash.shortage) }) }}</span>
              </div>
            </template>

            <div class="checkout__row">
              <span class="checkout__label r-sum__l">{{ t.customerCount }}</span>
              <div class="checkout__stepper r-qty">
                <button
                  type="button"
                  class="step r-qty__b"
                  :aria-label="t.customerDecrease"
                  :disabled="submitting || customerCount === null"
                  @click="stepCustomers(-1)"
                >
                  <AppIcon
                    name="minus"
                    :size="24"
                  />
                </button>
                <span class="step__value r-qty__v num tabular">{{ customerCount === null ? t.customerCountEmpty : fmt(t.customerCountValue, { n: customerCount }) }}</span>
                <button
                  type="button"
                  class="step r-qty__b"
                  :aria-label="t.customerIncrease"
                  :disabled="submitting"
                  @click="stepCustomers(1)"
                >
                  <AppIcon
                    name="plus"
                    :size="24"
                  />
                </button>
              </div>
            </div>
            <label class="checkout__memo r-field">
              <span class="checkout__label r-label">{{ t.memo }}</span>
              <input
                v-model="memo"
                class="r-input"
                type="text"
                :maxlength="MEMO_MAX"
                :disabled="submitting"
              >
            </label>
            <div
              v-if="printable"
              class="checkout__row checkout__print"
              data-testid="checkout-print"
            >
              <span class="checkout__label r-sum__l">{{ ja.print.toggleLabel }}</span>
              <SegmentedControl
                v-model="print"
                :options="PRINT_OPTIONS"
                :label="ja.print.toggleLabel"
                :disabled="submitting"
              />
            </div>
          </div>

          <div
            v-if="paymentMethod.is_cash"
            class="checkout__pad"
          >
            <div class="checkout__quick r-quick">
              <button
                type="button"
                class="quick"
                :disabled="submitting"
                @click="received = total"
              >
                {{ t.exact }}
              </button>
              <button
                v-for="q in QUICK"
                :key="q"
                type="button"
                class="quick tabular"
                :disabled="submitting"
                @click="received = q"
              >
                {{ q.toLocaleString('ja-JP') }}
              </button>
            </div>
            <NumericKeypad
              v-model="received"
              :max="MAX_RECEIVED"
              :disabled="submitting"
            />
          </div>
        </div>

        <p
          v-if="error"
          class="checkout__error r-banner r-banner--danger"
          role="alert"
        >
          {{ error }}
        </p>

        <div class="checkout__actions">
          <BigButton
            variant="secondary"
            size="xl"
            class="checkout__back"
            :disabled="submitting"
            @click="back"
          >
            {{ t.back }}
          </BigButton>
          <BigButton
            size="xl"
            class="checkout__confirm"
            :loading="submitting"
            :disabled="!canConfirm"
            @click="confirm"
          >
            <template #icon>
              <AppIcon
                name="check"
                :size="24"
              />
            </template>
            {{ t.confirm }}
          </BigButton>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.checkout-backdrop { z-index: 100; padding: 0; }

.checkout {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
  height: 100%;
  overflow-y: auto;
  padding: calc(12px + var(--safe-top)) 16px calc(12px + var(--safe-bottom));
  background: var(--c-surface);
  color: var(--c-text);
  outline: none;
}

.checkout__cols { display: flex; flex-direction: column; gap: 12px; }
.checkout__main { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.checkout__pad { display: flex; flex-direction: column; gap: 12px; min-width: 0; }

@media (min-width: 768px) {
  .checkout-backdrop { align-items: center; justify-content: center; padding: 16px; }
  .checkout { width: min(880px, 100%); height: auto; max-height: calc(100dvh - 32px); padding: 24px; border-radius: var(--radius-sheet); box-shadow: var(--sh-dialog); }
  .checkout__cols { flex-direction: row; gap: 24px; }
  .checkout__main { flex: 1 1 0; }
  .checkout__pad { flex: 0 0 min(400px, 46%); }
}

.checkout__pay { display: flex; flex-wrap: wrap; gap: 8px; }
.pay { flex: 1 1 0; min-width: 96px; }

.checkout__head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }

.checkout__received {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
}
.checkout__received-value { font-size: 32px; font-weight: 800; white-space: nowrap; }

.quick { cursor: pointer; }
.quick:disabled { opacity: 0.5; }

.checkout__change { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; min-height: 64px; padding-top: 8px; border-top: 1px solid var(--c-border-soft); }
.checkout__shortage { margin-left: auto; color: var(--c-danger); font-size: 32px; font-weight: 800; white-space: nowrap; }

.checkout__row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

.checkout__error { margin: 0; white-space: pre-line; }

.checkout__actions { display: flex; gap: 12px; margin-top: auto; }
.checkout__back { flex: 0 0 auto; min-width: 96px; }
.checkout__confirm { flex: 1 1 auto; min-width: 0; }
</style>
