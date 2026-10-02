<script setup lang="ts">
// S02 の注文一覧と合計欄（08 §5.3）：保留、明細（−・数量・＋・×、左スワイプで削除）、小計・値引き・合計・税・点数、
// ［お会計へ］（支払方法はお会計ダイアログで選ぶ）。タブレットは右の列、スマホは下から出るシートに置く
import { computed, ref } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { canAddOne, type CartLine } from '@/lib/cart'
import { formatYen } from '@/lib/money'
import { useRegisterStore } from '@/stores/register'

// unpaidCount：未会計の注文の件数（12 §8.6。取れていなければ null）
// inSheet：スマホのシートに置くとき。明細を内側でスクロールさせず、シート全体でスクロールする
defineProps<{ unpaidCount: number | null; inSheet?: boolean }>()
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
  <div
    class="order"
    :class="{ 'order--sheet': inSheet }"
  >
    <div class="order__tools r-card__head">
      <button
        type="button"
        class="order__from r-btn r-btn--quiet r-btn--sm"
        data-from-orders
        @click="emit('orders')"
      >
        <AppIcon
          name="receipt"
          :size="24"
        />
        <span class="clamp1">{{ unpaidCount === null ? t.fromOrdersUnknown : fmt(t.fromOrders, { n: unpaidCount }) }}</span>
      </button>
      <button
        type="button"
        class="order__tool r-btn r-btn--quiet r-btn--sm"
        @click="emit('held')"
      >
        <AppIcon
          name="history"
          :size="24"
        />
        <span>{{ fmt(t.heldList, { n: register.held.length }) }}</span>
      </button>
      <span class="grow" />
      <button
        type="button"
        class="order__tool order__tool--end r-btn r-btn--danger r-btn--sm"
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
        class="line o-line"
        :style="swipingKey === row.line.key ? { transform: `translateX(${swipeDx}px)` } : undefined"
        @pointerdown="onPointerDown(row.line, $event)"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
      >
        <div class="line__name o-line__main">
          <span class="o-line__name clamp2">{{ row.name }}</span>
          <span
            v-if="row.memo"
            class="line__memo o-line__opt"
          >{{ row.memo }}</span>
          <span
            v-if="row.options.length > 0"
            class="line__options o-line__opt"
          >{{ row.options.join('・') }}</span>
        </div>
        <div class="line__qty r-qty">
          <button
            type="button"
            class="line__btn r-qty__b"
            :aria-label="fmt(t.decrease, { name: row.name })"
            @click="register.decrement(row.line.key)"
          >
            <AppIcon
              name="minus"
              :size="24"
            />
          </button>
          <span
            class="line__count r-qty__v num tabular"
            :aria-label="fmt(t.quantity, { n: row.line.quantity })"
          >{{ row.line.quantity }}</span>
          <button
            type="button"
            class="line__btn r-qty__b"
            :aria-label="fmt(t.increase, { name: row.name })"
            :disabled="!row.canIncrease"
            @click="register.increment(row.line.key)"
          >
            <AppIcon
              name="plus"
              :size="24"
            />
          </button>
        </div>
        <MoneyText
          v-if="row.amount !== null"
          class="line__amount o-line__amt"
          :amount="row.amount"
        />
        <button
          type="button"
          class="line__remove r-qty__b"
          :aria-label="fmt(t.removeLine, { name: row.name })"
          @click="register.removeLine(row.line.key)"
        >
          <AppIcon
            name="x"
            :size="24"
          />
        </button>
      </li>
    </ul>

    <div class="order__foot r-actionbar">
      <dl class="order__sums">
        <div class="sum sum--line">
          <dt>{{ t.subtotal }}</dt>
          <dd><MoneyText :amount="register.amounts?.subtotal ?? 0" /></dd>
        </div>
        <div class="sum sum--line">
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
          <dt class="r-sum__l">
            {{ t.total }}
          </dt>
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

      <div class="order__acts">
        <BigButton
          variant="secondary"
          size="lg"
          :disabled="register.lines.length === 0"
          @click="register.hold()"
        >
          {{ t.hold }}
        </BigButton>
        <BigButton
          size="xl"
          block
          class="order__checkout"
          :disabled="!register.canCheckout"
          @click="emit('checkout')"
        >
          <template #icon>
            <AppIcon
              name="yen"
              :size="24"
            />
          </template>
          {{ t.toCheckout }}
        </BigButton>
      </div>
    </div>

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
  min-height: 0;
  height: 100%;
}

/* シートの中では高さを中身に合わせる（シートの高さに合わせると明細が 1 行分まで潰れ、残りの行が見えなくなる） */
.order--sheet { flex-shrink: 0; height: auto; }
.order--sheet .order__lines { flex: none; min-height: 0; overflow-y: visible; }

.order__tools { flex: none; flex-wrap: wrap; }
.order__from { min-width: 0; max-width: 100%; }
.order__linked { margin: 0; padding: 8px 16px 0; color: var(--c-primary-ink); font-weight: 700; overflow-wrap: anywhere; }

.order__empty { flex: 1 1 auto; margin: 0; padding: 32px 16px; color: var(--c-text-sub); text-align: center; }

.order__lines {
  flex: 1 1 auto;
  min-height: 96px;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.line { background: var(--c-surface); touch-action: pan-y; }
.line__name { overflow-wrap: anywhere; }
.line__remove { border-color: transparent; background: transparent; color: var(--c-text-sub); }

.order__foot { gap: 8px; }
.order__sums { margin: 0; }
.sum { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-width: 0; min-height: 32px; }
.sum dd { margin: 0; }
.sum--line { font-size: 16px; }
.sum--total { align-items: baseline; }
.sum--sub { color: var(--c-text-sub); font-size: 16px; }

.order__discount {
  min-height: var(--tap-min);
  margin-left: -12px;
  padding: 0 12px;
  border: 0;
  background: transparent;
  color: var(--c-primary-ink);
  font-size: 16px;
  font-weight: 700;
  text-decoration: underline;
}
.order__discount:disabled { color: var(--c-text-sub); text-decoration: none; }

.order__error { margin: 0; color: var(--c-danger); font-weight: 700; }

.order__acts { display: flex; gap: 12px; }
.order__checkout { flex: 1 1 auto; min-width: 0; }
</style>
