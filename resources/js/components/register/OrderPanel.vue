<script setup lang="ts">
// S02 の注文一覧と合計欄（08 §5.3）：保留、明細（−・数量・＋・×、左スワイプで削除）、小計・値引き・合計・税・点数、
// 支払方法、［お会計へ］。タブレットは右の列、スマホは下から出るシートに置く
import { computed, ref } from 'vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { canAddOne, type CartLine } from '@/lib/cart'
import { formatYen } from '@/lib/money'
import { useRegisterStore } from '@/stores/register'

// unpaidCount：未会計の注文の件数（12 §8.6。取れていなければ null）
defineProps<{ unpaidCount: number | null }>()
const emit = defineEmits<{ checkout: []; discount: []; held: []; orders: [] }>()

const t = ja.register
const register = useRegisterStore()

const rows = computed(() =>
  register.lines.map((line, i) => {
    const product = register.products.get(line.product_id)
    return {
      line,
      name: product?.name ?? '',
      memo: product?.memo ?? null,
      options: line.option_ids.map((id) => product?.options.find((o) => o.id === id)?.name ?? '').filter((n) => n !== ''),
      amount: register.amounts?.line_totals[i] ?? null,
      canIncrease: product !== undefined && canAddOne(register.lines, product, line.key),
    }
  }),
)

const linkedText = computed(() =>
  register.linkedOrders
    .map((o) => (o.place === '' ? fmt(t.linkedOrder, { no: o.order_no }) : fmt(t.linkedOrderAt, { no: o.order_no, place: o.place })))
    .join('、'),
)

const taxText = computed(() => {
  const amounts = register.amounts
  if (!amounts || !register.bootstrap) return ''
  const template = register.bootstrap.store.price_mode === 'tax_included' ? t.taxIncluded : t.taxExcluded
  return fmt(template, { amount: formatYen(amounts.tax_amount) })
})

// ── 左スワイプで削除（80px 以上、縦より横に大きく動いたとき）
const SWIPE_PX = 80
let swipe: { key: string; x: number; y: number } | null = null
const swipingKey = ref<string | null>(null)
const swipeDx = ref(0)

function onPointerDown(line: CartLine, e: PointerEvent): void {
  swipe = { key: line.key, x: e.clientX, y: e.clientY }
}

function onPointerMove(e: PointerEvent): void {
  if (!swipe) return
  const dx = e.clientX - swipe.x
  if (Math.abs(dx) > Math.abs(e.clientY - swipe.y) && dx < 0) {
    swipingKey.value = swipe.key
    swipeDx.value = Math.max(dx, -SWIPE_PX * 1.5)
  }
}

function onPointerUp(): void {
  if (swipe && swipingKey.value === swipe.key && swipeDx.value <= -SWIPE_PX) register.removeLine(swipe.key)
  swipe = null
  swipingKey.value = null
  swipeDx.value = 0
}

// ── クリア
const confirmClear = ref(false)
function clear(): void {
  register.clearOrder()
  confirmClear.value = false
}
</script>

<template>
  <div class="order">
    <button
      type="button"
      class="order__from"
      data-from-orders
      @click="emit('orders')"
    >
      {{ unpaidCount === null ? t.fromOrdersUnknown : fmt(t.fromOrders, { n: unpaidCount }) }}
    </button>
    <div class="order__tools">
      <button
        type="button"
        class="order__tool"
        :disabled="register.lines.length === 0"
        @click="register.hold()"
      >
        {{ t.hold }}
      </button>
      <button
        type="button"
        class="order__tool"
        @click="emit('held')"
      >
        {{ fmt(t.heldList, { n: register.held.length }) }}
      </button>
      <button
        type="button"
        class="order__tool order__tool--end"
        :disabled="register.lines.length === 0"
        @click="confirmClear = true"
      >
        {{ t.clear }}
      </button>
    </div>

    <p
      v-if="linkedText !== ''"
      class="order__linked"
      data-linked-orders
    >
      {{ fmt(t.linkedOrders, { orders: linkedText }) }}
    </p>

    <p
      v-if="register.lines.length === 0"
      class="order__empty"
    >
      {{ t.empty }}
    </p>
    <ul
      v-else
      class="order__lines"
      :aria-label="t.order"
    >
      <li
        v-for="row in rows"
        :key="row.line.key"
        class="line"
        :style="swipingKey === row.line.key ? { transform: `translateX(${swipeDx}px)` } : undefined"
        @pointerdown="onPointerDown(row.line, $event)"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
      >
        <div class="line__name">
          <span>{{ row.name }}</span>
          <span
            v-if="row.memo"
            class="line__memo"
          >{{ row.memo }}</span>
          <span
            v-if="row.options.length > 0"
            class="line__options"
          >{{ row.options.join('・') }}</span>
        </div>
        <div class="line__qty">
          <button
            type="button"
            class="line__btn"
            :aria-label="fmt(t.decrease, { name: row.name })"
            @click="register.decrement(row.line.key)"
          >
            −
          </button>
          <span
            class="line__count tabular"
            :aria-label="fmt(t.quantity, { n: row.line.quantity })"
          >{{ row.line.quantity }}</span>
          <button
            type="button"
            class="line__btn"
            :aria-label="fmt(t.increase, { name: row.name })"
            :disabled="!row.canIncrease"
            @click="register.increment(row.line.key)"
          >
            ＋
          </button>
        </div>
        <MoneyText
          v-if="row.amount !== null"
          class="line__amount"
          :amount="row.amount"
        />
        <button
          type="button"
          class="line__remove"
          :aria-label="fmt(t.removeLine, { name: row.name })"
          @click="register.removeLine(row.line.key)"
        >
          ×
        </button>
      </li>
    </ul>

    <dl class="order__sums">
      <div class="sum">
        <dt>{{ t.subtotal }}</dt>
        <dd><MoneyText :amount="register.amounts?.subtotal ?? 0" /></dd>
      </div>
      <div class="sum">
        <dt>
          <button
            type="button"
            class="order__discount"
            :disabled="register.lines.length === 0"
            @click="emit('discount')"
          >
            {{ t.discount }}<template v-if="register.discount?.type === 'percent'">
              （{{ register.discount.value }}%）
            </template>
          </button>
        </dt>
        <dd>
          <MoneyText
            :amount="-(register.amounts?.discount_amount ?? 0)"
            :tone="(register.amounts?.discount_amount ?? 0) > 0 ? 'danger' : 'default'"
          />
        </dd>
      </div>
      <div class="sum sum--total">
        <dt>{{ t.total }}</dt>
        <dd>
          <MoneyText
            :amount="register.amounts?.total ?? 0"
            size="total"
            tone="money"
          />
        </dd>
      </div>
      <div class="sum sum--sub">
        <dt>{{ fmt(t.count, { n: register.itemCount }) }}</dt>
        <dd>{{ taxText }}</dd>
      </div>
    </dl>
    <p
      v-if="register.pricingError"
      class="order__error"
      role="alert"
    >
      {{ register.pricingError }}
    </p>

    <div
      class="order__pay"
      role="radiogroup"
      :aria-label="t.paymentMethod"
    >
      <button
        v-for="method in register.bootstrap?.payment_methods ?? []"
        :key="method.id"
        type="button"
        role="radio"
        class="pay"
        :class="{ 'pay--on': register.paymentMethodId === method.id }"
        :aria-checked="register.paymentMethodId === method.id"
        @click="register.setPaymentMethod(method.id)"
      >
        {{ method.name }}
      </button>
    </div>

    <BigButton
      size="xl"
      block
      :disabled="!register.canCheckout"
      @click="emit('checkout')"
    >
      {{ t.toCheckout }}
    </BigButton>

    <ConfirmDialog
      :open="confirmClear"
      :title="t.clearTitle"
      :confirm-label="t.clearConfirm"
      danger
      @confirm="clear"
      @cancel="confirmClear = false"
    />
  </div>
</template>

<style scoped>
.order {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 0;
  height: 100%;
}

.order__from {
  flex-shrink: 0;
  min-height: 56px;
  padding: 0 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 18px;
  font-weight: 800;
}
.order__linked { margin: 0; color: var(--c-primary); font-weight: 700; overflow-wrap: anywhere; }

.order__tools { display: flex; gap: 8px; flex-wrap: wrap; }
.order__tool {
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-weight: 700;
}
.order__tool--end { margin-left: auto; border-color: var(--c-border); color: var(--c-text-sub); }
.order__tool:disabled { opacity: 0.5; }

.order__empty { flex: 1 1 auto; padding: 24px 8px; color: var(--c-text-sub); text-align: center; }

.order__lines {
  flex: 1 1 auto;
  min-height: 96px;
  overflow-y: auto;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--c-border);
}

.line {
  display: grid;
  grid-template-columns: 1fr auto;
  grid-template-areas: "name name" "qty amount-remove";
  align-items: center;
  gap: 4px 8px;
  padding: 8px 0;
  border-bottom: 1px solid var(--c-border);
  background: var(--c-surface);
  font-size: var(--fs-order-line);
  touch-action: pan-y;
}

@media (min-width: 1100px) {
  .line { grid-template-columns: 1fr auto auto auto; grid-template-areas: none; }
}

.line__name { display: flex; flex-direction: column; min-width: 0; font-weight: 700; overflow-wrap: anywhere; }
.line__memo,
.line__options { color: var(--c-text-sub); font-size: 14px; font-weight: 400; }
.line__qty { display: flex; align-items: center; gap: 4px; }
.line__count { min-width: 2.5em; text-align: center; font-weight: 700; }
.line__btn,
.line__remove {
  width: var(--qty-btn);
  height: var(--qty-btn);
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface-alt);
  font-size: 24px;
  font-weight: 700;
}
.line__btn:disabled { opacity: 0.4; }
.line__amount { margin-left: auto; font-weight: 700; }
.line__remove { border-color: transparent; background: transparent; color: var(--c-text-sub); }

.order__sums { margin: 0; }
.sum { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 32px; }
.sum dd { margin: 0; }
.sum--total { padding-top: 4px; border-top: 2px solid var(--c-text); }
.sum--total dt { font-size: 22px; font-weight: 800; }
.sum--sub { color: var(--c-text-sub); font-size: 16px; }

.order__discount {
  min-height: var(--tap-min);
  padding: 0 12px;
  margin-left: -12px;
  border: 0;
  background: transparent;
  color: var(--c-primary);
  font-weight: 700;
  text-decoration: underline;
}
.order__discount:disabled { color: var(--c-text-sub); text-decoration: none; }

.order__error { color: var(--c-danger); font-weight: 700; }

.order__pay { display: flex; flex-wrap: wrap; gap: 8px; }
.pay {
  flex: 1 1 0;
  min-width: 96px;
  min-height: var(--btn-h);
  padding: 0 12px;
  border: 2px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  font-size: 18px;
  font-weight: 700;
}
.pay--on { border-color: var(--c-primary); background: var(--c-primary); color: var(--c-on-primary); }
</style>
