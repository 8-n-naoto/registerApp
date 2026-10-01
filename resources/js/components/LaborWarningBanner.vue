<script setup lang="ts">
// 13 §6.6 未入力の労働条件の警告（owner）。ホームと勤怠画面に出す
import { RouterLink } from 'vue-router'
import { ja } from '@/i18n/ja'
import type { LaborWarning } from '@/types/api'

withDefaults(defineProps<{ warnings: LaborWarning[]; showLink?: boolean }>(), { showLink: true })
</script>

<template>
  <div
    v-if="warnings.length > 0"
    class="labor-warn"
    role="alert"
  >
    <p class="labor-warn__title">
      {{ ja.laborWarning.title }}
    </p>
    <ul class="labor-warn__items">
      <li
        v-for="w in warnings"
        :key="w"
      >
        {{ ja.laborWarning.items[w] }}
      </li>
    </ul>
    <RouterLink
      v-if="showLink"
      :to="{ name: 'attendance', query: { tab: 'labor' } }"
      class="labor-warn__link"
    >
      {{ ja.laborWarning.toSettings }}
    </RouterLink>
  </div>
</template>

<style scoped>
.labor-warn {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 16px;
  border-left: 6px solid var(--c-danger);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
}

.labor-warn__title { color: var(--c-danger-press); font-size: 18px; font-weight: 700; }
.labor-warn__items { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 0; padding-left: 20px; }

.labor-warn__link {
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  color: var(--c-primary);
  font-weight: 700;
  text-decoration: none;
}
</style>
