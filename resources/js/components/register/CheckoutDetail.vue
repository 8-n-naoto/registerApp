<script setup lang="ts">
// お会計ダイアログの詳細：呼び出した注文、明細（商品名・メモ・オプション・数量・金額）、小計・値引き・消費税・点数。
// 明細が長いときは欄の中でスクロールする（合計・支払方法・預かり金が下に押し出されないように）
import { computed } from 'vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'
import { useRegisterStore } from '@/stores/register'

const t = ja.register
const register = useRegisterStore()

const rows = computed(() =>
  register.lines.map((line, i) => {
    const product = register.products.get(line.product_id)
    return {
      key: line.key,
      name: product?.name ?? '',
      memo: product?.memo ?? null,
      options: line.option_ids.map((id) => product?.options.find((o) => o.id === id)?.name ?? '').filter((n) => n !== ''),
      quantity: line.quantity,
      amount: register.amounts?.line_totals[i] ?? null,
    }
  }),
)

const linkedText = computed(() =>
  register.linkedOrders
    .map((o) => (o.place === '' ? fmt(t.linkedOrder, { no: o.order_no }) : fmt(t.linkedOrderAt, { no: o.order_no, place: o.place })))
    .join('、'),
)

const discountAmount = computed(() => register.amounts?.discount_amount ?? 0)

const taxText = computed(() => {
  const amounts = register.amounts
  if (!amounts || !register.bootstrap) return ''
  const template = register.bootstrap.store.price_mode === 'tax_included' ? t.taxIncluded : t.taxExcluded
  return fmt(template, { amount: formatYen(amounts.tax_amount) })
})
</script>

<template>
  <section
    class="cdetail"
    :aria-label="t.detail"
  >
    <p
      v-if="linkedText !== ''"
      class="cdetail__linked"
    >
      {{ fmt(t.linkedOrders, { orders: linkedText }) }}
    </p>
    <ul class="cdetail__lines">
      <li
        v-for="row in rows"
        :key="row.key"
        class="cdetail__line"
      >
        <div class="cdetail__name">
          <span>{{ row.name }}</span>
          <span
            v-if="row.memo"
            class="cdetail__sub"
          >{{ row.memo }}</span>
          <span
            v-if="row.options.length > 0"
            class="cdetail__sub"
          >{{ row.options.join('・') }}</span>
        </div>
        <span class="cdetail__qty tabular">{{ fmt(t.detailQuantity, { n: row.quantity }) }}</span>
        <MoneyText
          v-if="row.amount !== null"
          class="cdetail__amount"
          :amount="row.amount"
        />
      </li>
    </ul>
    <dl class="cdetail__sums">
      <div class="cdetail__sum">
        <dt>{{ t.subtotal }}</dt>
        <dd><MoneyText :amount="register.amounts?.subtotal ?? 0" /></dd>
      </div>
      <div
        v-if="discountAmount > 0"
        class="cdetail__sum"
      >
        <dt>
          {{ t.discount }}<template v-if="register.discount?.type === 'percent'">
            （{{ register.discount.value }}%）
          </template>
        </dt>
        <dd>
          <MoneyText
            :amount="-discountAmount"
            tone="danger"
          />
        </dd>
      </div>
      <div class="cdetail__sum cdetail__sum--sub">
        <dt>{{ fmt(t.count, { n: register.itemCount }) }}</dt>
        <dd>{{ taxText }}</dd>
      </div>
    </dl>
  </section>
</template>

<style scoped>
.cdetail { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.cdetail__linked { margin: 0; color: var(--c-primary-ink); font-weight: 700; overflow-wrap: anywhere; }

.cdetail__lines {
  max-height: 200px;
  margin: 0;
  padding: 0 4px 0 0;
  overflow-y: auto;
  list-style: none;
  border-bottom: 1px solid var(--c-border-soft);
}
@media (min-width: 768px) {
  .cdetail__lines { max-height: 240px; }
}

.cdetail__line {
  display: flex;
  align-items: baseline;
  gap: 12px;
  padding: 6px 0;
  border-top: 1px solid var(--c-border-soft);
  font-size: 16px;
}
.cdetail__line:first-child { border-top: 0; }
.cdetail__name { display: flex; flex: 1 1 auto; flex-direction: column; min-width: 0; overflow-wrap: anywhere; }
.cdetail__sub { color: var(--c-text-sub); font-size: 14px; }
.cdetail__qty { flex: none; color: var(--c-text-sub); }
.cdetail__amount { flex: none; }

.cdetail__sums { margin: 0; }
.cdetail__sum { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 28px; font-size: 16px; }
.cdetail__sum dd { margin: 0; }
.cdetail__sum--sub { color: var(--c-text-sub); }
</style>
