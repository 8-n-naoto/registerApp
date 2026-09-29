<script setup lang="ts">
// A01 店舗一覧（08 §3.4・§5.13）。WP 1-7 ではログイン後の行き先として枠だけ置く。一覧は admin の WP で実装する
import AccountMenu from '@/components/AccountMenu.vue'
import WaveBackground from '@/components/WaveBackground.vue'
import { ja } from '@/i18n/ja'
</script>

<template>
  <div class="admin-home">
    <WaveBackground />
    <header class="admin-home__top">
      <div>
        <h1 class="admin-home__title">
          {{ ja.app.title }}
        </h1>
        <p class="admin-home__sub">
          {{ ja.admin.storesTitle }}
        </p>
      </div>
      <AccountMenu />
    </header>
    <main class="admin-home__list">
      <p>{{ ja.admin.storesPending }}</p>
    </main>
  </div>
</template>

<style scoped>
.admin-home {
  min-height: 100dvh;
  padding: calc(16px + var(--safe-top)) calc(var(--gutter) + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(var(--gutter) + var(--safe-left));
  color: var(--c-on-primary);
}

.admin-home__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 24px;
}

.admin-home__title {
  font-size: var(--fs-home-title);
  font-weight: 800;
  line-height: 1.1;
}

.admin-home__sub { margin-top: 8px; font-size: 20px; font-weight: 700; }

.admin-home__list {
  padding: 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
  color: var(--c-text);
}
</style>
