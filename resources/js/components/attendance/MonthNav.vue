<script setup lang="ts">
// 勤怠・勤務表の月の切替（前の月・次の月）
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
import { formatMonth, shiftMonth } from '@/lib/labor'

const month = defineModel<string>({ required: true })
</script>

<template>
  <nav
    class="month-nav r-period"
    :aria-label="formatMonth(month)"
  >
    <button
      type="button"
      class="r-period__b"
      @click="month = shiftMonth(month, -1)"
    >
      <AppIcon
        name="chevl"
        :size="24"
      />
      <span class="month-nav__sr">{{ ja.attendance.prevMonth }}</span>
    </button>
    <h2
      class="month-nav__label r-period__v"
      aria-live="polite"
    >
      {{ formatMonth(month) }}
      <AppIcon
        name="cal"
        :size="20"
      />
    </h2>
    <button
      type="button"
      class="r-period__b"
      @click="month = shiftMonth(month, 1)"
    >
      <AppIcon
        name="chev"
        :size="24"
      />
      <span class="month-nav__sr">{{ ja.attendance.nextMonth }}</span>
    </button>
  </nav>
</template>

<style scoped>
.month-nav__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
}

.r-period__b { position: relative; }
</style>
