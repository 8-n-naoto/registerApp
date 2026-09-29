<script setup lang="ts">
// 商品ボタンの見た目（08 §6）：色・売切・残数・オプション印・メモ。押せる要素は親が包む（レジと商品管理で共用）
// 商品コードは商品管理だけで出す（showCode）。レジのボタンは名前とメモで見分ける
import { computed } from 'vue'
import { fmt, ja } from '@/i18n/ja'
import { formatYen } from '@/lib/money'
import type { Product } from '@/types/api'

const props = withDefaults(
  defineProps<{
    product: Pick<Product, 'code' | 'name' | 'memo' | 'price' | 'color' | 'is_active' | 'track_stock' | 'stock_qty' | 'options'>
    layout?: 'tile' | 'row'
    showCode?: boolean
  }>(),
  { layout: 'tile', showCode: false },
)

const soldOut = computed(() => props.product.track_stock && props.product.stock_qty <= 0)
const hasOptions = computed(() => props.product.options.some((o) => o.is_active))
</script>

<template>
  <div
    class="tile"
    :class="[`tile--${layout}`, `tile--${product.color}`, { 'tile--soldout': soldOut, 'tile--stopped': !product.is_active }]"
  >
    <span
      class="tile__swatch"
      aria-hidden="true"
    />
    <span class="tile__text">
      <span class="tile__name">{{ product.name }}</span>
      <span
        v-if="product.memo"
        class="tile__memo"
      >{{ product.memo }}</span>
      <span
        v-if="showCode"
        class="tile__code tabular"
      >{{ product.code }}</span>
    </span>
    <span class="tile__price tabular">{{ formatYen(product.price) }}</span>
    <span class="tile__marks">
      <span
        v-if="!product.is_active"
        class="tile__mark tile__mark--stopped"
      >{{ ja.products.stopped }}</span>
      <span
        v-if="soldOut"
        class="tile__mark tile__mark--soldout"
      >{{ ja.products.soldOut }}</span>
      <span
        v-else-if="product.track_stock"
        class="tile__mark tabular"
      >{{ fmt(ja.products.remaining, { n: product.stock_qty }) }}</span>
      <span
        v-if="hasOptions"
        class="tile__mark"
        :title="ja.products.hasOptions"
      >＋<span class="visually-hidden">{{ ja.products.hasOptions }}</span></span>
    </span>
  </div>
</template>

<style scoped>
.tile {
  --tile-bg: var(--pc-gray-bg);
  --tile-fg: var(--pc-gray-fg);
  position: relative;
  border-radius: var(--radius);
  background: var(--tile-bg);
  color: var(--tile-fg);
}

.tile--gray { --tile-bg: var(--pc-gray-bg); --tile-fg: var(--pc-gray-fg); }
.tile--red { --tile-bg: var(--pc-red-bg); --tile-fg: var(--pc-red-fg); }
.tile--orange { --tile-bg: var(--pc-orange-bg); --tile-fg: var(--pc-orange-fg); }
.tile--yellow { --tile-bg: var(--pc-yellow-bg); --tile-fg: var(--pc-yellow-fg); }
.tile--green { --tile-bg: var(--pc-green-bg); --tile-fg: var(--pc-green-fg); }
.tile--teal { --tile-bg: var(--pc-teal-bg); --tile-fg: var(--pc-teal-fg); }
.tile--blue { --tile-bg: var(--pc-blue-bg); --tile-fg: var(--pc-blue-fg); }
.tile--indigo { --tile-bg: var(--pc-indigo-bg); --tile-fg: var(--pc-indigo-fg); }
.tile--purple { --tile-bg: var(--pc-purple-bg); --tile-fg: var(--pc-purple-fg); }
.tile--pink { --tile-bg: var(--pc-pink-bg); --tile-fg: var(--pc-pink-fg); }

.tile--tile {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 4px;
  min-height: var(--product-min-h);
  height: 100%;
  padding: 10px 12px;
}

.tile--tile .tile__swatch { display: none; }
.tile__text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }

.tile__memo,
.tile__code {
  overflow: hidden;
  font-size: 14px;
  font-weight: 400;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.tile__memo {
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}

.tile__code { white-space: nowrap; text-overflow: ellipsis; opacity: 0.85; }
.tile--row .tile__code { color: var(--c-text-sub); opacity: 1; }

.tile--tile .tile__name { font-size: var(--fs-product); font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
.tile--tile .tile__price { font-size: var(--fs-product-price); font-weight: 700; }

.tile--row {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: var(--tap-min);
  padding: 8px 12px;
  border: 1px solid var(--c-border);
  background: var(--c-surface);
  color: var(--c-text);
}

.tile--row .tile__swatch {
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: var(--tile-bg);
  border: 1px solid var(--c-border);
}

.tile--row .tile__text { flex: 1 1 auto; }
.tile--row .tile__name { font-weight: 700; overflow-wrap: anywhere; }
.tile--row .tile__price { font-weight: 700; white-space: nowrap; }

.tile__marks { display: flex; flex-wrap: wrap; gap: 4px; }
.tile--row .tile__marks { flex: 0 0 auto; }

.tile__mark {
  padding: 1px 8px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.75);
  color: var(--c-text);
  font-size: 14px;
  font-weight: 700;
  white-space: nowrap;
}

.tile--row .tile__mark { background: var(--c-surface-alt); }
.tile__mark--soldout { background: var(--c-soldout); color: var(--c-on-primary); }
.tile--row .tile__mark--soldout { background: var(--c-soldout); }
.tile__mark--stopped { background: var(--c-text-sub); color: var(--c-on-primary); }
.tile--row .tile__mark--stopped { background: var(--c-text-sub); }
.tile--tile.tile--soldout,
.tile--tile.tile--stopped { opacity: 0.6; }
</style>
