<script setup lang="ts">
// 集計の大きな数字のタイル（08 §6）。金額は amount、件数などは value（表示用の文字列）で渡す
import MoneyText from '@/components/MoneyText.vue'

defineProps<{ label: string; amount?: number; value?: string }>()
</script>

<template>
  <div class="stat-tile">
    <span class="stat-tile__label">{{ label }}</span>
    <MoneyText
      v-if="amount !== undefined"
      :amount="amount"
      size="daily"
      tone="money"
    />
    <span
      v-else
      class="stat-tile__value"
    >{{ value }}</span>
  </div>
</template>

<style scoped>
.stat-tile {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  padding: 16px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius-card);
  background: var(--c-surface);
}

.stat-tile__label { color: var(--c-text-sub); font-weight: 700; }

.stat-tile__value {
  color: var(--c-money);
  font-size: var(--fs-daily-total);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
  white-space: nowrap;
}
</style>
