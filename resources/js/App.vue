<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AdminShell from '@/components/AdminShell.vue'
import AppIcon from '@/components/AppIcon.vue'
import NetworkErrorBar from '@/components/NetworkErrorBar.vue'
import { ja } from '@/i18n/ja'
import { groupOf } from '@/lib/adminNav'
import { applyUpdate, needRefresh } from '@/lib/pwa'

const route = useRoute()
// 会計中に勝手に再読み込みしないよう、S02 では更新のお知らせを出さない（08 §9）
// お客さんの画面（C01）は店員向けの帯を出さない（エラーは画面の中で出す）
// 管理の画面は左のレール（タブレット）の中に出す
const inAdmin = computed(() => groupOf(route.name) !== null)
const showUpdate = computed(() => needRefresh.value && route.name !== 'register' && !route.meta.customer)
</script>

<template>
  <NetworkErrorBar v-if="!route.meta.customer" />
  <div
    v-if="showUpdate"
    class="update-bar r-toast"
    role="status"
  >
    <AppIcon name="refresh" />
    <span class="grow">{{ ja.pwa.newVersion }}</span>
    <button
      type="button"
      class="update-bar__btn r-btn r-btn--primary"
      @click="applyUpdate"
    >
      {{ ja.pwa.update }}
    </button>
  </div>
  <AdminShell v-if="inAdmin">
    <RouterView />
  </AdminShell>
  <RouterView v-else />
</template>

<style scoped>
.update-bar { bottom: calc(16px + var(--safe-bottom)); z-index: 100; padding: 8px 8px 8px 16px; }
.update-bar__btn { flex: none; min-height: var(--tap-min); padding: 0 20px; white-space: nowrap; }

@media (min-width: 768px) {
  .update-bar { left: 50%; right: auto; min-width: 420px; transform: translateX(-50%); }
}
</style>
