<script setup lang="ts">
// S03 簡易領収書の本体（08 §5.4）。会計のスナップショット（商品名・価格・税率・支払方法）だけで組み立てる
import { computed } from 'vue'
import MoneyText from '@/components/MoneyText.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatDateTime } from '@/lib/date'
import { permilleToPercent } from '@/lib/percent'
import type { Sale } from '@/types/api'

const props = defineProps<{ sale: Sale }>()

const t = ja.receipt

const taxLabel = computed(() =>
  fmt(props.sale.price_mode === 'tax_included' ? t.taxLine : t.taxLineExcluded, { rate: permilleToPercent(props.sale.tax_rate_permille) }),
)

const discountLabel = computed(() =>
  (props.sale.discount_type === 'percent' ? `${t.discount}（${props.sale.discount_value}%）` : t.discount),
)
</script>

<template>
  <article
    class="receipt"
    :class="{ 'receipt--cancelled': sale.status === 'cancelled' }"
  >
    <h1 class="receipt__title">
      {{ t.title }}
    </h1>
    <p class="receipt__store">
      {{ sale.store_name }}
    </p>
    <p class="receipt__meta tabular">
      <span>{{ formatDateTime(sale.sold_at) }}</span>
      <span>{{ fmt(t.saleId, { id: sale.id }) }}</span>
    </p>

    <ul class="receipt__items">
      <li
        v-for="item in sale.items"
        :key="item.id"
        class="receipt__item"
      >
        <div class="receipt__item-main">
          <span class="receipt__name">{{ item.product_name }}<span
            v-if="item.product_memo"
            class="receipt__memo"
          >（{{ item.product_memo }}）</span></span>
          <MoneyText :amount="item.line_total" />
        </div>
        <div class="receipt__item-sub tabular">
          <span v-if="item.options.length > 0">{{ item.options.map((o) => o.option_name).join('・') }}</span>
          <span class="receipt__qty"><MoneyText :amount="item.unit_price + item.options_price" /> × {{ item.quantity }}</span>
        </div>
      </li>
    </ul>

    <dl class="receipt__sums">
      <div class="receipt__row">
        <dt>{{ t.subtotal }}</dt>
        <dd><MoneyText :amount="sale.subtotal" /></dd>
      </div>
      <div
        v-if="sale.discount_amount > 0"
        class="receipt__row"
      >
        <dt>{{ discountLabel }}</dt>
        <dd><MoneyText :amount="-sale.discount_amount" /></dd>
      </div>
      <div class="receipt__row receipt__row--total">
        <dt>{{ t.total }}</dt>
        <dd>
          <MoneyText
            :amount="sale.total"
            size="amount"
          />
        </dd>
      </div>
      <div class="receipt__row">
        <dt>{{ taxLabel }}</dt>
        <dd><MoneyText :amount="sale.tax_amount" /></dd>
      </div>
      <div class="receipt__row">
        <dt>{{ t.paymentMethod }}</dt>
        <dd>{{ sale.payment_method_name }}</dd>
      </div>
      <template v-if="sale.is_cash">
        <div class="receipt__row">
          <dt>{{ t.received }}</dt>
          <dd><MoneyText :amount="sale.received" /></dd>
        </div>
        <div class="receipt__row">
          <dt>{{ t.change }}</dt>
          <dd><MoneyText :amount="sale.change_amount" /></dd>
        </div>
      </template>
    </dl>

    <p
      v-if="sale.status === 'cancelled'"
      class="receipt__watermark"
    >
      {{ t.cancelled }}
    </p>
  </article>
</template>

<style scoped>
.receipt {
  position: relative;
  width: 100%;
  max-width: 360px;
  margin: 0 auto;
  padding: 24px 20px;
  overflow: hidden;
  background: #fff;
  color: #111827;
  border: 1px solid var(--c-border-soft);
  border-radius: var(--radius-card);
  box-shadow: var(--sh-card);
}

.receipt__title { font-size: 24px; text-align: center; letter-spacing: 0.3em; }
.receipt__store { margin-top: 8px; font-size: 18px; font-weight: 700; text-align: center; }
.receipt__meta { display: flex; justify-content: space-between; gap: 8px; margin-top: 8px; font-size: 14px; color: #4b5563; }

.receipt__items { margin: 12px 0 0; padding: 8px 0; list-style: none; border-top: 1px dashed #9ca3af; border-bottom: 1px dashed #9ca3af; }
.receipt__item { padding: 4px 0; }
.receipt__item-main { display: flex; justify-content: space-between; gap: 8px; font-weight: 700; }
.receipt__name { overflow-wrap: anywhere; }
.receipt__memo { font-weight: 400; }
.receipt__item-sub { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; color: #4b5563; }
.receipt__qty { margin-left: auto; white-space: nowrap; }

.receipt__sums { margin: 8px 0 0; }
.receipt__row { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
.receipt__row dd { margin: 0; }
.receipt__row--total { padding: 6px 0; font-size: 20px; font-weight: 800; }

.receipt__watermark {
  position: absolute;
  top: 40%;
  left: 50%;
  padding: 0 24px;
  border: 6px solid var(--c-danger);
  border-radius: 12px;
  color: var(--c-danger);
  font-size: 72px;
  font-weight: 900;
  opacity: 0.55;
  transform: translate(-50%, -50%) rotate(-20deg);
  pointer-events: none;
}

@media print {
  .receipt { max-width: none; border: 0; border-radius: 0; box-shadow: none; }
}
</style>
