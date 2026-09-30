<script setup lang="ts">
// S02 会計（08 §5.3）。タブレット横は左に商品（62%）・右に注文と合計（38%）。
// スマホ縦は上に税区分とカテゴリ、中央に商品、下に固定の合計バー（注文はシートで開く）。
// 商品のタップから合計の表示までは通信しない（AC-S02-1）。起動時に GET /register/bootstrap を 1 回呼ぶ
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { fetchOrders } from '@/api/orders'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import CheckoutDialog from '@/components/register/CheckoutDialog.vue'
import DiscountForm from '@/components/register/DiscountForm.vue'
import HeldList from '@/components/register/HeldList.vue'
import OptionPicker from '@/components/register/OptionPicker.vue'
import OrderPanel from '@/components/register/OrderPanel.vue'
import OrderPickDialog from '@/components/register/OrderPickDialog.vue'
import ProductArea from '@/components/register/ProductArea.vue'
import SaleDone from '@/components/register/SaleDone.vue'
import { fmt, ja } from '@/i18n/ja'
import { useIsTablet } from '@/lib/breakpoint'
import { canAddOne } from '@/lib/cart'
import { useWakeLock } from '@/lib/wakeLock'
import { useRegisterStore, type ConfirmExtra, type Discount, type StaleOrders } from '@/stores/register'
import type { Product, Sale } from '@/types/api'

const t = ja.register
const route = useRoute()
const router = useRouter()
const register = useRegisterStore()
const isTablet = useIsTablet()
useWakeLock()

onMounted(() => {
  void register.load()
  // S15［会計へ］（?table=ID）：そのテーブルの注文を選んだ状態で開き、URL から外す。
  // S13［送信して会計へ］（?order=ID）：その注文だけを選んだ状態で開く
  const table = Number(route.query.table)
  const order = Number(route.query.order)
  if (Number.isSafeInteger(table) && table > 0) {
    pickTable.value = table
    pickOpen.value = true
    void router.replace({ name: 'register' })
  } else if (Number.isSafeInteger(order) && order > 0) {
    pickOrder.value = order
    pickOpen.value = true
    void router.replace({ name: 'register' })
  } else {
    void refreshUnpaid()
  }
})

// ── 注文から会計（12 §8.6）。件数は画面を開いたときとダイアログを開いたときに取る（取れなければ件数なしで出す）
const unpaidCount = ref<number | null>(null)
const pickOpen = ref(false)
const pickTable = ref<number | null>(null)
const pickOrder = ref<number | null>(null)

async function refreshUnpaid(): Promise<void> {
  try {
    unpaidCount.value = (await fetchOrders('unpaid')).length
  } catch {
    unpaidCount.value = null
  }
}

function openPick(): void {
  pickTable.value = null
  pickOrder.value = null
  orderSheet.value = false
  pickOpen.value = true
}

function closePick(): void {
  pickOpen.value = false
  pickTable.value = null
  pickOrder.value = null
}

const stale = ref<StaleOrders | null>(null)

function orderNos(ids: readonly number[]): string {
  return register.linkedOrders.filter((o) => ids.includes(o.id)).map((o) => `#${o.order_no}`).join('、')
}

function removeStale(): void {
  if (stale.value) register.removeOrders(stale.value.ids)
  stale.value = null
  void refreshUnpaid()
}

// ── 商品（カテゴリのタブと商品ボタンは ProductArea）
function canPress(product: Product): boolean {
  return canAddOne(register.lines, product)
}

const picking = ref<Product | null>(null)

function press(product: Product): void {
  if (!canPress(product)) return
  if (product.options.length > 0) picking.value = product
  else register.add(product)
}

function addWithOptions(optionIds: number[]): void {
  if (picking.value) register.add(picking.value, optionIds)
  picking.value = null
}

// ── シート・ダイアログ
const orderSheet = ref(false)
const heldOpen = ref(false)
const discountOpen = ref(false)

function applyDiscount(value: Discount | null): void {
  register.setDiscount(value)
  discountOpen.value = false
}

// ── お会計
const checkoutOpen = ref(false)
const checkoutError = ref<string | null>(null)
const done = ref<Sale | null>(null)
const undoing = ref(false)

function openCheckout(): void {
  if (!register.canCheckout) return
  register.startCheckout()
  checkoutError.value = null
  orderSheet.value = false
  checkoutOpen.value = true
}

async function confirm(extra: ConfirmExtra): Promise<void> {
  checkoutError.value = null
  const outcome = await register.confirm(extra)
  if (outcome.ok) {
    checkoutOpen.value = false
    done.value = outcome.sale
    void refreshUnpaid()
    return
  }
  if (outcome.staleOrders) {
    checkoutOpen.value = false
    stale.value = outcome.staleOrders
  } else if (outcome.closeDialog) {
    checkoutOpen.value = false
    register.notice = { kind: 'error', text: outcome.message }
  } else {
    checkoutError.value = outcome.message
  }
}

function next(): void {
  done.value = null
  register.forgetLastSale()
}

function showReceipt(): void {
  const sale = done.value
  next()
  if (sale) void router.push({ name: 'receipt', params: { id: sale.id } })
}

async function undo(): Promise<void> {
  undoing.value = true
  await register.undoLastSale()
  undoing.value = false
  done.value = null
}
</script>

<template>
  <div
    class="register"
    :class="{ 'register--tablet': isTablet }"
  >
    <header class="register__top">
      <RouterLink
        :to="{ name: 'home' }"
        class="register__home"
      >
        <span aria-hidden="true">←</span> {{ ja.common.home }}
      </RouterLink>
      <div
        v-if="register.bootstrap"
        class="tabs tabs--tax"
        role="radiogroup"
        :aria-label="t.taxType"
      >
        <button
          v-for="tax in register.bootstrap.tax_types"
          :key="tax.id"
          type="button"
          role="radio"
          class="tab"
          :class="{ 'tab--on': register.taxTypeId === tax.id }"
          :aria-checked="register.taxTypeId === tax.id"
          @click="register.setTaxType(tax.id)"
        >
          {{ tax.name }}
        </button>
      </div>
    </header>

    <div
      v-if="register.notice"
      class="register__notice"
      :class="`register__notice--${register.notice.kind}`"
      :role="register.notice.kind === 'error' ? 'alert' : 'status'"
    >
      <span>{{ register.notice.text }}</span>
      <button
        type="button"
        class="register__notice-close"
        @click="register.notice = null"
      >
        {{ ja.common.close }}
      </button>
    </div>
    <p
      v-if="!register.storageOk"
      class="register__notice register__notice--error"
    >
      {{ t.storageUnavailable }}
    </p>

    <p
      v-if="register.loading && !register.bootstrap"
      class="register__message"
    >
      {{ ja.common.loading }}
    </p>
    <div
      v-else-if="register.loadError && !register.bootstrap"
      class="register__message"
      role="alert"
    >
      <p>{{ register.loadError }}</p>
      <BigButton @click="register.load()">
        {{ t.retry }}
      </BigButton>
    </div>

    <div
      v-else-if="register.bootstrap"
      class="register__body"
    >
      <ProductArea
        :products="register.bootstrap.products"
        :categories="register.bootstrap.categories"
        :can-press="canPress"
        :tablet="isTablet"
        :label="t.title"
        @press="press"
      />

      <aside
        v-if="isTablet"
        class="register__order"
      >
        <OrderPanel
          :unpaid-count="unpaidCount"
          @checkout="openCheckout"
          @discount="discountOpen = true"
          @held="heldOpen = true"
          @orders="openPick"
        />
      </aside>
    </div>

    <div
      v-if="!isTablet && register.bootstrap"
      class="total-bar"
    >
      <button
        type="button"
        class="total-bar__summary"
        @click="orderSheet = true"
      >
        <span class="total-bar__label">{{ t.totalBar }}・{{ fmt(t.count, { n: register.itemCount }) }}</span>
        <MoneyText
          :amount="register.amounts?.total ?? 0"
          size="amount"
          tone="inherit"
        />
        <span class="total-bar__view">{{ t.viewOrder }}</span>
      </button>
      <BigButton
        size="lg"
        :disabled="!register.canCheckout"
        @click="openCheckout"
      >
        {{ t.toCheckout }}
      </BigButton>
    </div>

    <BottomSheet
      :open="orderSheet"
      :title="t.order"
      @close="orderSheet = false"
    >
      <OrderPanel
        :unpaid-count="unpaidCount"
        @checkout="openCheckout"
        @discount="discountOpen = true"
        @held="heldOpen = true"
        @orders="openPick"
      />
    </BottomSheet>

    <OptionPicker
      :product="picking"
      @add="addWithOptions"
      @cancel="picking = null"
    />
    <DiscountForm
      :open="discountOpen"
      :current="register.discount"
      @apply="applyDiscount"
      @cancel="discountOpen = false"
    />
    <HeldList
      :open="heldOpen"
      @close="heldOpen = false"
    />
    <OrderPickDialog
      :open="pickOpen && register.bootstrap !== null"
      :preselect-table="pickTable"
      :preselect-order="pickOrder"
      @loaded="unpaidCount = $event"
      @close="closePick"
    />
    <ConfirmDialog
      :open="stale !== null"
      :title="t.staleOrdersTitle"
      :message="stale ? fmt(t.staleOrdersMessage, { message: stale.message, orders: orderNos(stale.ids) }) : ''"
      :confirm-label="t.staleOrdersRemove"
      danger
      @confirm="removeStale"
      @cancel="stale = null"
    />
    <CheckoutDialog
      v-if="register.paymentMethod && register.amounts"
      :open="checkoutOpen"
      :total="register.amounts.total"
      :payment-method="register.paymentMethod"
      :submitting="register.submitting"
      :error="checkoutError"
      @confirm="confirm"
      @back="checkoutOpen = false"
    />
    <SaleDone
      v-if="done"
      :sale="done"
      :undoing="undoing"
      @receipt="showReceipt"
      @next="next"
      @undo="undo"
    />
  </div>
</template>

<style scoped>
.register {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  padding: var(--safe-top) var(--safe-right) 0 var(--safe-left);
  background: var(--c-surface-alt);
  color: var(--c-text);
}

.register__top {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 12px;
  background: var(--c-primary);
  color: var(--c-on-primary);
}

.register__home {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 4px;
  min-height: var(--tap-min);
  padding: 0 12px;
  color: var(--c-on-primary);
  font-weight: 700;
  text-decoration: none;
}

.tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  scrollbar-width: none;
}

.tabs--tax { flex: 1 1 auto; justify-content: flex-end; }
.tab {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tab-h);
  padding: 0 16px;
  border: 2px solid var(--c-border);
  border-radius: 999px;
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 18px;
  font-weight: 700;
  white-space: nowrap;
}

.tab--on { border-color: var(--c-primary); background: var(--c-primary); color: var(--c-on-primary); }
.tabs--tax .tab { min-height: var(--tap-min); border-color: var(--c-on-primary); background: transparent; color: var(--c-on-primary); }
.tabs--tax .tab--on { background: var(--c-surface); color: var(--c-primary); }

.register__notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 4px 12px;
  font-weight: 700;
  white-space: pre-line;
}
.register__notice--error { background: #FEE2E2; color: var(--c-danger); }
.register__notice--info { background: #DCFCE7; color: var(--c-success); }
.register__notice-close {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  border: 0;
  background: transparent;
  color: inherit;
  font-weight: 700;
}

.register__message { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 48px 16px; text-align: center; }

.register__body { display: flex; flex: 1 1 auto; min-height: 0; }

/* タブレット：画面の高さに収め、商品と注文の列をそれぞれスクロールする（AC-S02-13） */
.register--tablet { height: 100dvh; }
.register--tablet .register__body { overflow: hidden; }
.register__order {
  flex: 0 0 38%;
  min-width: 0;
  overflow-y: auto;
  padding: 12px 12px calc(12px + var(--safe-bottom));
  border-left: 1px solid var(--c-border);
  background: var(--c-surface);
}

/* スマホ：合計バーを画面の下に固定し、ホームバーに重ねない（AC-S02-14） */
.total-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px calc(12px + var(--safe-right)) calc(8px + var(--safe-bottom)) calc(12px + var(--safe-left));
  background: var(--c-money);
  color: var(--c-on-primary);
  box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.2);
}

.total-bar__summary {
  display: grid;
  flex: 1 1 auto;
  grid-template-columns: 1fr auto;
  align-items: center;
  min-height: var(--tap-min);
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
}
.total-bar__label { font-size: 14px; }
.total-bar__view { grid-column: 1 / -1; font-size: 14px; text-decoration: underline; }
</style>
