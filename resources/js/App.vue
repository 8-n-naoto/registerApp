<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import NetworkErrorBar from '@/components/NetworkErrorBar.vue'
import { ja } from '@/i18n/ja'
import { applyUpdate, needRefresh } from '@/lib/pwa'

const route = useRoute()
// 会計中に勝手に再読み込みしないよう、S02 では更新のお知らせを出さない（08 §9）
// お客さんの画面（C01）は店員向けの帯を出さない（エラーは画面の中で出す）
const showUpdate = computed(() => needRefresh.value && route.name !== 'register' && !route.meta.customer)
</script>

<template>
  <NetworkErrorBar v-if="!route.meta.customer" />
  <div
    v-if="showUpdate"
    class="update-bar"
    role="status"
  >
    <span>{{ ja.pwa.newVersion }}</span>
    <button
      type="button"
      class="update-bar__btn"
      @click="applyUpdate"
    >
      {{ ja.pwa.update }}
    </button>
  </div>
  <RouterView />
</template>

<style scoped>
.update-bar {
  position: fixed;
  left: 50%;
  bottom: calc(16px + var(--safe-bottom));
  z-index: 100;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 8px 8px 8px 20px;
  border-radius: var(--radius);
  background: var(--c-text);
  color: var(--c-on-primary);
  transform: translateX(-50%);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
}

.update-bar__btn {
  min-height: var(--tap-min);
  padding: 0 20px;
  border: 0;
  border-radius: var(--radius);
  background: var(--c-primary);
  color: var(--c-on-primary);
  font-weight: 700;
}
</style>
