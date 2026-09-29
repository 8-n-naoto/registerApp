<script setup lang="ts">
// お会計ダイアログ（08 §5.3）：合計、現金なら預かり金（即入力・数字キー）とお釣り / 「あと ¥N」、客数、メモ、［確定］［戻る］。
// タブレットは中央、スマホは全画面。通信エラーで閉じずに残るよう、入力は開き直したときだけ初期化する
import { computed, nextTick, ref, watch } from 'vue'
import BigButton from '@/components/BigButton.vue'
import MoneyText from '@/components/MoneyText.vue'
import NumericKeypad from '@/components/NumericKeypad.vue'
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
  submitting: boolean
  error: string | null
}>()

const emit = defineEmits<{ confirm: [extra: ConfirmExtra]; back: [] }>()

const t = ja.register
const received = ref<number | null>(null)
const customerCount = ref<number | null>(null)
const memo = ref('')
const panel = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    received.value = null
    customerCount.value = null
    memo.value = ''
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
  })
}

function back(): void {
  if (!props.submitting) emit('back')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="checkout-backdrop"
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
        <header class="checkout__head">
          <h2
            id="checkout-title"
            class="checkout__label"
          >
            {{ t.total }}・{{ paymentMethod.name }}
          </h2>
          <MoneyText
            :amount="total"
            size="total"
            tone="money"
          />
        </header>

        <template v-if="paymentMethod.is_cash">
          <div class="checkout__received">
            <span class="checkout__label">{{ t.received }}</span>
            <span
              class="checkout__received-value tabular"
              aria-live="polite"
            >{{ received === null ? '¥—' : formatYen(received) }}</span>
          </div>
          <div class="checkout__quick">
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
          <div
            class="checkout__change"
            aria-live="polite"
          >
            <template v-if="cash.change !== null">
              <span class="checkout__label">{{ t.change }}</span>
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
          <span class="checkout__label">{{ t.customerCount }}</span>
          <div class="checkout__stepper">
            <button
              type="button"
              class="step"
              :aria-label="t.customerDecrease"
              :disabled="submitting || customerCount === null"
              @click="stepCustomers(-1)"
            >
              −
            </button>
            <span class="step__value tabular">{{ customerCount === null ? t.customerCountEmpty : fmt(t.customerCountValue, { n: customerCount }) }}</span>
            <button
              type="button"
              class="step"
              :aria-label="t.customerIncrease"
              :disabled="submitting"
              @click="stepCustomers(1)"
            >
              ＋
            </button>
          </div>
        </div>
        <label class="checkout__memo">
          <span class="checkout__label">{{ t.memo }}</span>
          <input
            v-model="memo"
            type="text"
            :maxlength="MEMO_MAX"
            :disabled="submitting"
          >
        </label>

        <p
          v-if="error"
          class="checkout__error"
          role="alert"
        >
          {{ error }}
        </p>

        <div class="checkout__actions">
          <button
            type="button"
            class="checkout__back"
            :disabled="submitting"
            @click="back"
          >
            {{ t.back }}
          </button>
          <BigButton
            size="xl"
            class="checkout__confirm"
            :loading="submitting"
            :disabled="!canConfirm"
            @click="confirm"
          >
            {{ t.confirm }}
          </BigButton>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.checkout-backdrop {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: stretch;
  justify-content: center;
  background: rgba(17, 24, 39, 0.5);
}

.checkout {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 100%;
  overflow-y: auto;
  padding: calc(12px + var(--safe-top)) 16px calc(12px + var(--safe-bottom));
  background: var(--c-surface);
  color: var(--c-text);
  outline: none;
}

@media (min-width: 768px) {
  .checkout-backdrop { align-items: center; padding: 16px; }
  .checkout { width: min(560px, 100%); max-height: calc(100dvh - 32px); padding: 24px; border-radius: var(--radius-card); }
}

.checkout__head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.checkout__label { font-size: 18px; font-weight: 700; color: var(--c-text-sub); }

.checkout__received {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
}
.checkout__received-value { font-size: 32px; font-weight: 800; }

.checkout__quick { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.quick {
  min-height: var(--tap-min);
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 18px;
  font-weight: 700;
}

.checkout__change { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; min-height: 72px; }
.checkout__shortage { margin-left: auto; color: var(--c-danger); font-size: 32px; font-weight: 800; }

.checkout__row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.checkout__stepper { display: flex; align-items: center; gap: 8px; }
.step {
  width: var(--qty-btn);
  height: var(--qty-btn);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface-alt);
  font-size: 24px;
  font-weight: 700;
}
.step:disabled { opacity: 0.4; }
.step__value { min-width: 4em; text-align: center; font-weight: 700; }

.checkout__memo { display: flex; flex-direction: column; gap: 4px; }
.checkout__memo input {
  min-height: var(--tap-min);
  padding: 0 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  font-size: 16px;
}

.checkout__error { color: var(--c-danger); font-weight: 700; white-space: pre-line; }

.checkout__actions { display: flex; gap: 12px; margin-top: auto; }
.checkout__back {
  min-width: 96px;
  min-height: var(--btn-h-confirm);
  padding: 0 20px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 20px;
  font-weight: 700;
}
.checkout__confirm { flex: 1 1 auto; }
</style>
