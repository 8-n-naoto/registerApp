<script setup lang="ts">
// 数値のタイル（デザインシステム「レジアプリ」の r-stat）。金額は amount、件数などは value（表示用の文字列）で渡す
// tone：main は売上合計など主役の数字、warn は注意（時間外の超過など）
import MoneyText from '@/components/MoneyText.vue'

withDefaults(defineProps<{ label: string; amount?: number; value?: string; sub?: string; tone?: 'normal' | 'main' | 'warn' }>(), {
  amount: undefined,
  value: undefined,
  sub: undefined,
  tone: 'normal',
})
</script>

<template>
  <div
    class="stat-tile r-stat"
    :class="{ 'r-stat--main': tone === 'main', 'r-stat--warn': tone === 'warn' }"
  >
    <span class="r-stat__l">{{ label }}</span>
    <span
      v-if="amount !== undefined"
      class="r-stat__v"
    >
      <MoneyText
        :amount="amount"
        :tone="tone === 'main' ? 'money' : 'inherit'"
      />
    </span>
    <span
      v-else
      class="r-stat__v"
    >{{ value }}</span>
    <span
      v-if="sub"
      class="r-stat__s"
    >{{ sub }}</span>
  </div>
</template>
