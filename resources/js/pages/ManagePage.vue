<script setup lang="ts">
// 管理のメニュー（スマホ）。タブレットのレール（大分類）の代わりに、グループの見出し＋ページの行を同じ並びで出す
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
import { navFor, pageTo } from '@/lib/adminNav'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const groups = computed(() => navFor(auth.role))
</script>

<template>
  <div class="manage">
    <header class="r-appbar">
      <RouterLink
        :to="auth.homeRoute"
        class="r-appbar__back"
      >
        <AppIcon name="back" />
        <span>{{ ja.common.home }}</span>
      </RouterLink>
      <h1 class="r-appbar__title">
        {{ ja.nav.manage }}
      </h1>
    </header>
    <main class="manage__body">
      <section
        v-for="g in groups"
        :key="g.key"
        class="col gap1"
      >
        <h2 class="r-label">
          {{ g.label }}
        </h2>
        <ul class="r-list">
          <li
            v-for="p in g.pages"
            :key="p.name"
          >
            <RouterLink
              :to="pageTo(p)"
              class="r-li"
            >
              <span class="r-li__ic"><AppIcon :name="g.icon" /></span>
              <span class="r-li__main"><span class="r-li__title">{{ p.label }}</span></span>
              <AppIcon
                name="chev"
                class="chev"
              />
            </RouterLink>
          </li>
        </ul>
      </section>
    </main>
  </div>
</template>

<style scoped>
.manage { min-height: 100dvh; background: var(--c-surface-alt); }

.manage__body {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 640px;
  margin: 0 auto;
  padding: 16px calc(16px + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(16px + var(--safe-left));
}

.r-list > li { border-top: 1px solid var(--c-border-soft); }
.r-list > li:first-child { border-top: 0; }
.r-list > li > .r-li { border-top: 0; }
</style>
