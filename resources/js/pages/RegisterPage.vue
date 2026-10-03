<script setup lang="ts">
// S02 会計（08 §5.3）。タブレット横は左に商品（62%）・右に注文と合計（38%）。
// スマホ縦は上に税区分とカテゴリ、中央に商品、下に固定の合計バー（注文はシートで開く）。
// 商品のタップから合計の表示までは通信しない（AC-S02-1）。起動時に GET /register/bootstrap を 1 回呼ぶ
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { fetchOrders } from '@/api/orders'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import MoneyText from '@/components/MoneyText.vue'
import ReceiptPrint from '@/components/ReceiptPrint.vue'
import CheckoutDialog from '@/components/register/CheckoutDialog.vue'
import DiscountForm from '@/components/register/DiscountForm.vue'
import HeldList from '@/components/register/HeldList.vue'
import OptionPicker from '@/components/register/OptionPicker.vue'
import OrderPanel from '@/components/register/OrderPanel.vue'
import OrderPickDialog from '@/components/register/OrderPickDialog.vue'
import OutboxBanner from '@/components/register/OutboxBanner.vue'
import ProductArea from '@/components/register/ProductArea.vue'
import SaleDone from '@/components/register/SaleDone.vue'
import { fmt, ja } from '@/i18n/ja'
import { useIsTablet } from '@/lib/breakpoint'
import { canAddOne } from '@/lib/cart'
import { useWakeLock } from '@/lib/wakeLock'
import { saleKey, useReceiptPrinterStore } from '@/stores/receiptPrinter'
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
  // S13［送信して会計へ］（?order=ID）：その注文だけを選んだ状態で開く。
  // ホームの［レジ］（?view=order）：スマホは注文一覧のシートを開いた状態で開く（タブレットは注文一覧が常に右に出ている）
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
    if (route.query.view === 'order') {
      if (!isTablet.value) orderSheet.value = true
      void router.replace({ name: 'register' })
    }
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
const undone = ref(false)
const undoError = ref<string | null>(null)

// ── レシート（15 §8.2）。送信は裏で続け、ポップアップを閉じた後の送信中・失敗は上の帯に出す
const printerStore = useReceiptPrinterStore()
const printer = computed(() => register.bootstrap?.store?.printer ?? null)
const printSale = ref<Sale | null>(null)
const printBanner = computed(() => {
  const sale = printSale.value
  if (done.value !== null || sale === null || printer.value === null) return null
  const job = printerStore.jobFor(saleKey(sale))
  return job !== null && job.state !== 'printed' ? sale : null
})

function startPrint(sale: Sale): void {
  printSale.value = sale
  if (printer.value) void printerStore.print(printer.value, sale, 'receipt')
}

function closePrintBanner(): void {
  printerStore.dismiss()
  printSale.value = null
}

function openCheckout(): void {
  if (!register.canCheckout) return
  register.startCheckout()
  checkoutError.value = null
  orderSheet.value = false
  checkoutOpen.value = true
}

async function confirm(extra: ConfirmExtra, print: boolean): Promise<void> {
  checkoutError.value = null
  const outcome = await register.confirm(extra)
  if (outcome.ok) {
    checkoutOpen.value = false
    undone.value = false
    undoError.value = null
    done.value = outcome.sale
    // 確定に成功したときだけ送る（409 などの失敗では送らない）
    if (print) startPrint(outcome.sale)
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
  undone.value = false
  undoError.value = null
  register.forgetLastSale()
}

function showReceipt(): void {
  const sale = done.value
  next()
  if (sale) void router.push({ name: 'receipt', params: { id: sale.id } })
}

// 取り消しの結果は完了のポップアップの中で見せる。成功したら「取り消しました」に切り替え、［次の会計］で閉じる
async function undo(): Promise<void> {
  if (undoing.value) return
  undoing.value = true
  const ok = await register.undoLastSale()
  undoing.value = false
  if (ok) undone.value = true
  else undoError.value = ja.register.undoFailed
}
</script>

<template>
  <div
    class="register"
    :class="{ 'register--tablet': isTablet }"
  >
    <header class="register__top r-appbar">
      <RouterLink
        :to="{ name: 'home' }"
        class="register__home r-appbar__back"
      >
        <AppIcon
          name="back"
          :size="24"
        />
        <span>{{ ja.common.home }}</span>
      </RouterLink>
      <span class="r-appbar__title">{{ t.title }}</span>
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
      class="register__notice r-banner"
      :class="[`register__notice--${register.notice.kind}`, register.notice.kind === 'error' ? 'r-banner--danger' : 'r-banner--ok']"
      :role="register.notice.kind === 'error' ? 'alert' : 'status'"
    >
      <span class="grow">{{ register.notice.text }}</span>
      <button
        type="button"
        class="register__notice-close r-btn r-btn--plain r-btn--sm"
        @click="register.notice = null"
      >
        {{ ja.common.close }}
      </button>
    </div>
    <p
      v-if="!register.storageOk"
      class="register__notice register__notice--error r-banner r-banner--danger"
    >
      {{ t.storageUnavailable }}
    </p>
    <div
      v-if="printBanner && printer"
      class="register__notice register__print r-banner"
      data-testid="print-banner"
    >
      <ReceiptPrint
        :sale="printBanner"
        :printer="printer"
        kind="receipt"
      />
      <button
        type="button"
        class="register__notice-close r-btn r-btn--plain r-btn--sm"
        @click="closePrintBanner"
      >
        {{ ja.common.close }}
      </button>
    </div>
    <OutboxBanner class="register__outbox" />

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
      class="total-bar r-actionbar"
    >
      <button
        type="button"
        class="total-bar__summary"
        @click="orderSheet = true"
      >
        <span class="total-bar__label r-sum__l">{{ t.totalBar }}・{{ fmt(t.count, { n: register.itemCount }) }}</span>
        <MoneyText
          class="total-bar__money"
          :amount="register.amounts?.total ?? 0"
          size="total"
          tone="money"
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
        in-sheet
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
      :payment-methods="register.bootstrap?.payment_methods ?? []"
      :submitting="register.submitting"
      :error="checkoutError"
      :printable="printer !== null"
      @confirm="confirm"
      @back="checkoutOpen = false"
      @pay="register.setPaymentMethod($event)"
    />
    <SaleDone
      v-if="done"
      :sale="done"
      :undoing="undoing"
      :undone="undone"
      :undo-error="undoError"
      :printer="printer"
      @receipt="showReceipt"
      @next="next"
      @undo="undo"
      @print="printSale = done"
    />
  </div>
</template>

<style scoped>
.register {
  display: flex;
  flex-direction: column;
  min-height: 100dvh;
  background: var(--c-surface);
  color: var(--c-text);
}

.register__home { text-decoration: none; }

.tabs { display: flex; gap: 8px; min-width: 0; overflow-x: auto; scrollbar-width: none; }
.tabs--tax { flex: 0 1 auto; justify-content: flex-end; }
.tab {
  flex-shrink: 0;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-on-primary);
  border-radius: 999px;
  background: transparent;
  color: var(--c-on-primary);
  font-size: 16px;
  font-weight: 700;
  white-space: nowrap;
}
.tab--on { background: var(--c-surface); color: var(--c-primary-ink); }

.register__notice { align-items: center; margin: 8px 16px 0; white-space: pre-line; }
.register__print :deep(.receipt-print) { flex: 1 1 0; justify-content: flex-start; width: auto; min-width: 0; }
.register__outbox { margin: 8px 16px 0; }
.register__notice-close { flex-shrink: 0; }

.register__message { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 48px 16px; text-align: center; }

.register__body { display: flex; flex: 1 1 auto; min-height: 0; }

/* タブレット：画面の高さに収め、商品と注文の列をそれぞれスクロールする（AC-S02-13） */
.register--tablet { height: 100dvh; }
.register--tablet .register__body { overflow: hidden; }
.register__order {
  display: flex;
  flex: 0 0 440px;
  flex-direction: column;
  min-width: 0;
  max-width: 45%;
  overflow: hidden;
  border-left: 1px solid var(--c-border-soft);
  background: var(--c-surface);
}

/* スマホ：合計の帯を画面の下に固定する（AC-S02-14）。余白は r-actionbar が持つ */
.total-bar {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 50;
  flex-direction: row;
  align-items: center;
  gap: 12px;
}

.total-bar__summary {
  display: grid;
  flex: 1 1 auto;
  grid-template-columns: minmax(0, 1fr);
  min-width: 0;
  min-height: var(--tap-min);
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
}
.total-bar__money { font-size: 32px; }
.total-bar__view { color: var(--c-primary-ink); font-size: 14px; text-decoration: underline; }
</style>
