<script setup lang="ts">
// S00 ホーム（08 §3.1・§3.2）。WP 0-6 では見た目の骨格のみ。役割による出し分けとアカウントメニューは WP 2 で入れる
import { RouterLink } from 'vue-router'
import WaveBackground from '@/components/WaveBackground.vue'
import { ja } from '@/i18n/ja'

interface HomeLink { label: string; to: string }
interface HomeCard { key: string; title: string; links: HomeLink[] }

const cards: HomeCard[] = [
  { key: 'register', title: ja.home.card.register, links: [{ label: ja.home.btn.checkout, to: '/register' }] },
  {
    key: 'products',
    title: ja.home.card.products,
    links: [
      { label: ja.home.btn.productEdit, to: '/products' },
      { label: ja.home.btn.productImport, to: '/products?import=1' },
    ],
  },
  {
    key: 'sales',
    title: ja.home.card.sales,
    links: [
      { label: ja.home.btn.daily, to: '/sales/daily' },
      { label: ja.home.btn.closing, to: '/closing' },
      { label: ja.home.btn.summary, to: '/sales/summary' },
    ],
  },
  {
    key: 'settings',
    title: ja.home.card.settings,
    links: [
      { label: ja.home.btn.staff, to: '/settings/staff' },
      { label: ja.home.btn.store, to: '/settings/store' },
      { label: ja.home.btn.logs, to: '/logs' },
    ],
  },
]
</script>

<template>
  <div class="home">
    <WaveBackground />
    <header class="home__top">
      <h1 class="home__title">
        {{ ja.app.title }}
      </h1>
    </header>
    <main class="home__cards">
      <section
        v-for="card in cards"
        :key="card.key"
        class="home-card"
      >
        <h2 class="home-card__title">
          {{ card.title }}
        </h2>
        <div class="home-card__links">
          <RouterLink
            v-for="link in card.links"
            :key="link.to"
            :to="link.to"
            class="home-card__btn"
          >
            {{ link.label }}
          </RouterLink>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.home {
  min-height: 100dvh;
  padding: calc(16px + var(--safe-top)) calc(var(--gutter) + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(var(--gutter) + var(--safe-left));
  color: var(--c-on-primary);
}

.home__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 24px;
}

.home__title {
  font-size: var(--fs-home-title);
  font-weight: 800;
  line-height: 1.1;
  letter-spacing: 0.02em;
}

.home__cards {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.home-card {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 16px;
  padding: 16px;
  border: 1px solid var(--c-card-border);
  border-radius: var(--radius-card);
  background: var(--c-card-bg);
}

.home-card__title {
  font-size: var(--fs-home-card);
  font-weight: 700;
}

.home-card__links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.home-card__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: var(--btn-h);
  padding: 0 16px;
  border: 2px solid var(--c-on-primary);
  border-radius: var(--radius);
  color: var(--c-on-primary);
  font-size: var(--fs-home-btn);
  font-weight: 700;
  text-decoration: none;
}

.home-card__btn:active { background: rgba(255, 255, 255, 0.2); }

@media (min-width: 768px) {
  .home__top { margin-bottom: 48px; }

  /* 4 列の等幅。staff でカードが 2 枚でも幅は同じにして左寄せ */
  .home__cards { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; }

  .home-card {
    flex-direction: column;
    align-items: stretch;
    padding: 24px;
  }

  .home-card__links { flex-direction: column; gap: 12px; }
  .home-card__btn { width: 100%; }
}
</style>
