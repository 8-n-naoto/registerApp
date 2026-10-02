<script setup lang="ts">
// 13 §6.6 未入力の労働条件の警告（owner）。ホームと勤怠画面に出す
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
import type { LaborWarning } from '@/types/api'

withDefaults(defineProps<{ warnings: LaborWarning[]; showLink?: boolean }>(), { showLink: true })
</script>

<template>
  <div
    v-if="warnings.length > 0"
    class="labor-warn r-banner r-banner--warn"
    role="alert"
  >
    <AppIcon
      name="alert"
      :size="24"
    />
    <span class="labor-warn__body">
      <b class="labor-warn__title r-banner__t">{{ ja.laborWarning.title }}</b>
      <ul class="labor-warn__items r-banner__d">
        <li
          v-for="w in warnings"
          :key="w"
        >
          {{ ja.laborWarning.items[w] }}
        </li>
      </ul>
    </span>
    <RouterLink
      v-if="showLink"
      :to="{ name: 'attendance', query: { tab: 'labor' } }"
      class="labor-warn__link r-btn r-btn--secondary r-btn--sm"
    >
      {{ ja.laborWarning.toSettings }}
    </RouterLink>
  </div>
</template>

<style scoped>
.labor-warn { flex-wrap: wrap; }
.labor-warn__body { flex: 1 1 220px; min-width: 0; }
.labor-warn__items { display: flex; flex-wrap: wrap; gap: 2px 16px; margin: 0; padding: 0; list-style: none; }
.labor-warn__items > li::before { content: '・'; }
.labor-warn__link { margin-left: auto; text-decoration: none; }
</style>
