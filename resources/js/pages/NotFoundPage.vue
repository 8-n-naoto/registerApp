<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
</script>

<template>
  <main class="not-found">
    <AppIcon
      name="info"
      :size="48"
    />
    <h1 class="not-found__title">
      {{ ja.notFound.title }}
    </h1>
    <RouterLink
      :to="{ name: 'home' }"
      class="not-found__link r-btn r-btn--primary r-btn--lg"
    >
      {{ ja.notFound.toHome }}
    </RouterLink>
  </main>
</template>

<style scoped>
.not-found {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  min-height: 100dvh;
  padding: var(--gutter);
  color: var(--c-text-sub);
  text-align: center;
}

.not-found__title { color: var(--c-text); font-size: var(--fs-heading); font-weight: 800; }
.not-found__link { text-decoration: none; }
</style>
