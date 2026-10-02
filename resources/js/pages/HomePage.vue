<script setup lang="ts">
// S00 ホーム（08 §3.1・§3.2）。上にお店の操作、下に管理のグループ（並びは管理のレールと同じ）。スマホは操作をタイルで並べ、管理は［管理］へまとめる
import { computed } from 'vue'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import AccountMenu from '@/components/AccountMenu.vue'
import AppIcon from '@/components/AppIcon.vue'
import LaborWarningBanner from '@/components/LaborWarningBanner.vue'
import OperatorBar from '@/components/OperatorBar.vue'
import WaveBackground from '@/components/WaveBackground.vue'
import { fmt, ja } from '@/i18n/ja'
import { navFor, pageTo } from '@/lib/adminNav'
import { formatBusinessDate } from '@/lib/date'
import type { IconName } from '@/lib/icons'
import { useAuthStore } from '@/stores/auth'

interface ShopLink { key: string; label: string; to: RouteLocationRaw; icon: IconName }
interface HomeLink { label: string; to: RouteLocationRaw }
interface HomeCard { key: string; title: string; icon: IconName; links: HomeLink[] }

// お店の操作（毎日使う 4 つ）。パスは 08 §4。［レジ］は注文一覧（［お会計へ］がある欄）を開いた状態で開く
const shopLinks: ShopLink[] = [
  { key: 'register', label: 'レジ', to: '/register?view=order', icon: 'yen' },
  { key: 'order-new', label: '注文入力', to: '/orders/new', icon: 'cart' },
  { key: 'orders', label: ja.home.btn.orders, to: '/orders', icon: 'receipt' },
  { key: 'kitchen', label: ja.home.btn.kitchen, to: '/kitchen', icon: 'flame' },
]

const auth = useAuthStore()

// 管理のグループ（役割で見えるものだけ。アカウントは右上のメニューにある）。商品には CSV 一括登録の入口を足す
const cards = computed<HomeCard[]>(() =>
  navFor(auth.role)
    .filter((group) => !group.bottom)
    .map((group) => {
      const links: HomeLink[] = group.pages.map((page) => ({ label: page.label, to: pageTo(page) }))
      if (group.key === 'products') links.push({ label: ja.home.btn.productImport, to: '/products?import=1' })
      return { key: group.key, title: group.label, icon: group.icon, links }
    }),
)

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
    <div class="home__in home__body">
      <header class="home__top">
        <div class="home__head">
          <p class="home__store">
            <span>{{ storeName }}</span>
            <span v-if="businessDate">{{ businessDate }}</span>
          </p>
          <h1 class="home__title">
            {{ ja.app.title }}
          </h1>
        </div>
        <AccountMenu />
      </header>
      <OperatorBar v-if="auth.isOwner || auth.isStaff" />
      <LaborWarningBanner
        v-if="laborWarnings.length > 0"
        class="home__warn"
        :warnings="laborWarnings"
      />

      <section
        class="home-shop h-card"
        aria-labelledby="home-shop-title"
      >
        <h2
          id="home-shop-title"
          class="home-shop__title h-card__t"
        >
          <AppIcon
            name="cart"
            :size="28"
          />お店の操作
        </h2>
        <div class="home-shop__grid">
          <RouterLink
            v-for="(link, i) in shopLinks"
            :key="link.key"
            :to="link.to"
            class="home-shop__btn h-btn"
            :class="{ 'h-btn--main': i === 0 }"
          >
            <AppIcon
              class="home-shop__lead"
              :name="link.icon"
              :size="28"
            />
            <span class="home-shop__label">{{ link.label }}</span>
            <AppIcon
              class="home-shop__trail"
              name="chev"
              :size="24"
            />
          </RouterLink>
          <!-- スマホだけ：勤怠・勤務表と管理への入口をタイルで -->
          <RouterLink
            :to="{ name: 'attendance' }"
            class="home-shop__btn home-shop__extra h-btn"
          >
            <AppIcon
              class="home-shop__lead"
              name="clock"
              :size="28"
            />
            <span class="home-shop__label">{{ ja.home.btn.attendance }}</span>
          </RouterLink>
          <RouterLink
            :to="{ name: 'shifts' }"
            class="home-shop__btn home-shop__extra h-btn"
          >
            <AppIcon
              class="home-shop__lead"
              name="cal"
              :size="28"
            />
            <span class="home-shop__label">{{ ja.home.btn.shifts }}</span>
          </RouterLink>
          <RouterLink
            :to="{ name: 'manage' }"
            class="home-shop__btn home-shop__extra h-btn"
          >
            <AppIcon
              class="home-shop__lead"
              name="gear"
              :size="28"
            />
            <span class="home-shop__label">管理</span>
          </RouterLink>
        </div>
      </section>

      <main class="home__cards h-grid">
        <section
          v-for="card in cards"
          :key="card.key"
          class="home-card h-card"
        >
          <h2 class="home-card__title h-card__t">
            <AppIcon
              :name="card.icon"
              :size="28"
            />{{ card.title }}
          </h2>
          <RouterLink
            v-for="link in card.links"
            :key="link.label"
            :to="link.to"
            class="home-card__btn h-btn"
          >
            <span class="home-card__label">{{ link.label }}</span>
            <AppIcon
              name="chev"
              :size="24"
            />
          </RouterLink>
        </section>
      </main>
    </div>
  </div>
</template>

<style scoped>
.home__body {
  gap: 14px;
  padding: calc(16px + var(--safe-top)) calc(16px + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(16px + var(--safe-left));
}

.home__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.home__head { display: flex; flex-direction: column; min-width: 0; }
.home__store { display: flex; flex-wrap: wrap; gap: 0 12px; margin: 0; }

/* スマホ：お店の操作は 2 列のタイル。管理のカードは出さず［管理］へ */
.home-shop { padding: 0; gap: 0; border: 0; background: transparent; }
.home-shop__title { display: none; }
.home-shop__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

.home-shop__btn {
  flex-direction: column;
  align-items: flex-start;
  justify-content: space-between;
  min-height: 96px;
  padding: 12px 14px;
  border: 0;
  background: var(--c-surface);
  color: var(--c-primary-ink);
  font-size: 18px;
  font-weight: 800;
}

.home-shop__btn.home-shop__extra {
  border: 1px solid var(--c-card-border);
  background: var(--c-card-bg);
  color: var(--c-on-primary);
}

.home-shop__trail { display: none; }
.home-shop__label { min-width: 0; overflow-wrap: anywhere; }
.home__cards { display: none; }
.home-card__label { min-width: 0; }

@media (min-width: 768px) {
  .home__body { gap: 20px; padding: calc(24px + var(--safe-top)) calc(28px + var(--safe-right)) calc(28px + var(--safe-bottom)) calc(28px + var(--safe-left)); }

  .home-shop { padding: 16px; gap: 10px; border: 1px solid var(--c-card-border); background: var(--c-card-bg); }
  .home-shop__title { display: flex; }
  .home-shop__grid { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }

  .home-shop__btn {
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    min-height: 56px;
    padding: 8px 14px;
    border: 2px solid var(--c-on-primary);
    background: transparent;
    color: var(--c-on-primary);
    font-size: 18px;
    font-weight: 700;
  }

  .home-shop__btn.h-btn--main { border-color: var(--c-surface); background: var(--c-surface); color: var(--c-primary-ink); }
  .home-shop__lead { display: none; }
  .home-shop__trail { display: block; margin-left: auto; opacity: 0.8; }
  .home-shop__extra { display: none; }

  .home__cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
}
</style>
