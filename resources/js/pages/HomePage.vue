<script setup lang="ts">
// S00 ホーム（08 §3.1・§3.2）。役割でカードとボタンを出し分ける
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import AccountMenu from '@/components/AccountMenu.vue'
import LaborWarningBanner from '@/components/LaborWarningBanner.vue'
import OperatorBar from '@/components/OperatorBar.vue'
import WaveBackground from '@/components/WaveBackground.vue'
import { fmt, ja } from '@/i18n/ja'
import { formatBusinessDate } from '@/lib/date'
import { useAuthStore } from '@/stores/auth'
import type { Role } from '@/types/api'

interface HomeLink { label: string; to: RouteLocationRaw; roles: Role[] }
interface HomeCard { key: string; title: string; links: HomeLink[] }

const OWNER: Role[] = ['owner']
const BOTH: Role[] = ['owner', 'staff']

// 画面ができるまで遷移先は 404 になる（各 WP で追加）。パスは 08 §4
const allCards: HomeCard[] = [
  { key: 'register', title: ja.home.card.register, links: [{ label: ja.home.btn.checkout, to: '/register', roles: BOTH }] },
  {
    key: 'orders',
    title: ja.home.card.orders,
    links: [
      { label: ja.home.btn.orderNew, to: '/orders/new', roles: BOTH },
      // 注文を受けた端末からも会計を開けるように（S02 と同じ遷移先）
      { label: ja.home.btn.orderCheckout, to: '/register', roles: BOTH },
      { label: ja.home.btn.kitchen, to: '/kitchen', roles: BOTH },
      { label: ja.home.btn.orders, to: '/orders', roles: BOTH },
    ],
  },
  {
    key: 'products',
    title: ja.home.card.products,
    links: [
      { label: ja.home.btn.productEdit, to: '/products', roles: OWNER },
      { label: ja.home.btn.productImport, to: '/products?import=1', roles: OWNER },
    ],
  },
  {
    key: 'sales',
    title: ja.home.card.sales,
    links: [
      { label: ja.home.btn.daily, to: '/sales/daily', roles: BOTH },
      { label: ja.home.btn.closing, to: '/closing', roles: BOTH },
      { label: ja.home.btn.summary, to: '/sales/summary', roles: OWNER },
    ],
  },
  {
    key: 'labor',
    title: ja.home.card.labor,
    links: [
      { label: ja.home.btn.attendance, to: '/attendance', roles: BOTH },
      { label: ja.home.btn.shifts, to: '/shifts', roles: BOTH },
    ],
  },
  {
    key: 'settings',
    title: ja.home.card.settings,
    links: [
      { label: ja.home.btn.staff, to: '/settings/staff', roles: OWNER },
      { label: ja.home.btn.store, to: '/settings/store', roles: OWNER },
      { label: ja.home.btn.tables, to: '/settings/tables', roles: OWNER },
      { label: ja.home.btn.logs, to: '/logs', roles: OWNER },
    ],
  },
]

const auth = useAuthStore()

// ボタンが 1 つも残らないカードは出さない（staff は 3 枚：AC-S00-2 に注文のカードを足した。12 §8.1）
const cards = computed(() => {
  const role = auth.role
  if (!role) return []
  return allCards
    .map((card) => ({ ...card, links: card.links.filter((link) => link.roles.includes(role)) }))
    .filter((card) => card.links.length > 0)
})

const storeName = computed(() => auth.me?.store?.name ?? '')
const businessDate = computed(() => {
  const date = auth.me?.current_business_date
  return date ? fmt(ja.home.businessDate, { date: formatBusinessDate(date) }) : ''
})
const laborWarnings = computed(() => (auth.isOwner ? (auth.me?.labor_warnings ?? []) : []))
</script>

<template>
  <div class="home">
    <WaveBackground />
    <header class="home__top">
      <div>
        <h1 class="home__title">
          {{ ja.app.title }}
        </h1>
        <p class="home__store">
          <span>{{ storeName }}</span>
          <span v-if="businessDate">{{ businessDate }}</span>
        </p>
      </div>
      <AccountMenu />
    </header>
    <OperatorBar v-if="auth.isOwner || auth.isStaff" />
    <LaborWarningBanner
      v-if="laborWarnings.length > 0"
      class="home__warn"
      :warnings="laborWarnings"
    />
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
            :key="link.label"
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

.home__store {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  margin-top: 8px;
  font-size: 18px;
  font-weight: 700;
}

.home__warn { margin-bottom: 16px; }

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
  .home__top { margin-bottom: 40px; }
  .home__store { font-size: 20px; }

  /* 4 列の等幅。staff でカードが 2 枚でも幅は同じにして左寄せ */
  .home__store {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
  margin-top: 8px;
  font-size: 18px;
  font-weight: 700;
}

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
