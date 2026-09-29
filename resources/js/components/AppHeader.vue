<script setup lang="ts">
// 管理系画面の青い帯（08 §3.3）。右端はアカウントメニュー
import { RouterLink } from 'vue-router'
import AccountMenu from '@/components/AccountMenu.vue'
import { ja } from '@/i18n/ja'

withDefaults(
  defineProps<{
    title: string
    showHome?: boolean
    viewingStoreName?: string | null
  }>(),
  { showHome: true, viewingStoreName: null },
)
</script>

<template>
  <header class="app-header">
    <RouterLink
      v-if="showHome"
      :to="{ name: 'home' }"
      class="app-header__home"
    >
      <span aria-hidden="true">←</span> {{ ja.common.home }}
    </RouterLink>
    <h1 class="app-header__title">
      {{ title }}
    </h1>
    <p
      v-if="viewingStoreName"
      class="app-header__viewing"
    >
      {{ viewingStoreName }}
    </p>
    <div class="app-header__end">
      <slot name="end">
        <AccountMenu />
      </slot>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  gap: 16px;
  min-height: calc(var(--header-h) + var(--safe-top));
  padding: var(--safe-top) calc(var(--gutter) + var(--safe-right)) 0 calc(var(--gutter) + var(--safe-left));
  background: var(--c-primary);
  color: var(--c-on-primary);
}

.app-header__home {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: var(--tap-min);
  min-width: var(--tap-min);
  padding: 0 12px;
  border: 2px solid var(--c-on-primary);
  border-radius: var(--radius);
  color: inherit;
  font-weight: 700;
  text-decoration: none;
}

.app-header__home:active { background: rgba(255, 255, 255, 0.2); }

.app-header__title {
  font-size: 20px;
  font-weight: 700;
}

.app-header__viewing {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.2);
  font-size: 16px;
}

.app-header__end { margin-left: auto; }

@media (min-width: 768px) {
  .app-header__title { font-size: var(--fs-heading); }
}
</style>
