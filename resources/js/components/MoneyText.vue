<script setup lang="ts">
// 金額の表示（08 §6）。¥ と 3 桁区切り、桁をそろえる
import { computed } from 'vue'
import { formatYen } from '@/lib/money'

const props = withDefaults(
  defineProps<{
    amount: number
    size?: 'body' | 'amount' | 'total' | 'change' | 'daily'
    tone?: 'default' | 'money' | 'change' | 'danger' | 'inherit'
  }>(),
  { size: 'body', tone: 'default' },
)

const text = computed(() => formatYen(props.amount))
</script>

<template>
  <span
    class="money"
    :class="[`money--${size}`, `money--${tone}`]"
  >{{ text }}</span>
</template>

<style scoped>
.money {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.money--body { font-size: inherit; }
.money--amount { font-size: 24px; font-weight: 700; }
.money--total { font-size: var(--fs-total); font-weight: 800; line-height: 1.1; }
.money--change { font-size: var(--fs-change); font-weight: 800; line-height: 1.1; }
.money--daily { font-size: var(--fs-daily-total); font-weight: 800; line-height: 1.1; }

.money--default { color: var(--c-text); }
.money--money { color: var(--c-money); }
.money--change { color: var(--c-change); }
.money--danger { color: var(--c-danger); }
.money--inherit { color: inherit; }
</style>
