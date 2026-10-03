<script setup lang="ts">
// 14 §7.6 ホーム（owner）：確認が必要なオフライン会計があれば件数と［確認する］を出す。読めなければ何も出さない
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { fetchOfflineIssues } from '@/api/register'
import { fmt, ja } from '@/i18n/ja'

const count = ref(0)

onMounted(async () => {
  try {
    count.value = (await fetchOfflineIssues()).length
  } catch {
    count.value = 0
  }
})
</script>

<template>
  <div
    v-if="count > 0"
    class="offline-issues r-banner r-banner--warn"
    role="status"
    data-testid="offline-issues-banner"
  >
    <span class="grow r-banner__t">{{ fmt(ja.offlineIssues.banner, { n: count }) }}</span>
    <RouterLink
      :to="{ name: 'sales-offline' }"
      class="r-btn r-btn--secondary r-btn--sm"
    >
      {{ ja.offlineIssues.open }}
    </RouterLink>
  </div>
</template>

<style scoped>
.offline-issues { flex-wrap: wrap; align-items: center; }
</style>
