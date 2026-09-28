<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ja } from '@/i18n/ja'
</script>

<template>
  <main class="not-found">
    <h1 class="not-found__title">
      {{ ja.notFound.title }}
    </h1>
    <RouterLink
      :to="{ name: 'home' }"
      class="not-found__link"
    >
      {{ ja.notFound.toHome }}
    </RouterLink>
  </main>
</template>

<style scoped>
.not-found {
  display: grid;
  place-content: center;
  gap: 24px;
  min-height: 100dvh;
  padding: var(--gutter);
  text-align: center;
}

.not-found__title { font-size: var(--fs-heading); }

.not-found__link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: var(--btn-h);
  padding: 0 24px;
  border-radius: var(--radius);
  background: var(--c-primary);
  color: var(--c-on-primary);
  font-weight: 700;
  text-decoration: none;
}
</style>
