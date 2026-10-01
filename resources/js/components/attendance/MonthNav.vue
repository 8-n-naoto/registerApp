<script setup lang="ts">
// 勤怠・勤務表の月の切替（前の月・次の月）
import { ja } from '@/i18n/ja'
import { formatMonth, shiftMonth } from '@/lib/labor'

const month = defineModel<string>({ required: true })
</script>

<template>
  <nav
    class="month-nav"
    :aria-label="formatMonth(month)"
  >
    <button
      type="button"
      class="adm-btn"
      @click="month = shiftMonth(month, -1)"
    >
      ‹ {{ ja.attendance.prevMonth }}
    </button>
    <h2
      class="month-nav__label"
      aria-live="polite"
    >
      {{ formatMonth(month) }}
    </h2>
    <button
      type="button"
      class="adm-btn"
      @click="month = shiftMonth(month, 1)"
    >
      {{ ja.attendance.nextMonth }} ›
    </button>
  </nav>
</template>

<style scoped>
.month-nav { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.month-nav__label { font-size: var(--fs-heading); text-align: center; }
</style>
