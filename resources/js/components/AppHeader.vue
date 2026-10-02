<script setup lang="ts">
// 画面の上部（デザインシステム「レジアプリ」）
// - 管理の画面（lib/adminNav のページ）：タブレットはグループ名＋ページタブ（左のレールは AdminShell）、
//   スマホは青い帯の［管理］戻る＋パンくず「管理 / グループ / ページ」
// - 営業の画面（注文確認・厨房など）：青い帯の［ホーム］戻る＋画面名
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import AccountMenu from '@/components/AccountMenu.vue'
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
import { groupOf, navFor, pageTo } from '@/lib/adminNav'
import { useAuthStore } from '@/stores/auth'

const props = withDefaults(
  defineProps<{
    title: string
    showHome?: boolean
    viewingStoreName?: string | null
  }>(),
  { showHome: true, viewingStoreName: null },
)

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

/** 今のページが属するグループ（役割で見えて、ルートが登録されているページだけ） */
const group = computed(() => {
  const g = groupOf(route.name)
  if (!g) return null
  const mine = navFor(auth.role).find((x) => x.key === g.key)
  if (!mine) return null
  return { ...mine, pages: mine.pages.filter((p) => router.hasRoute(p.name)) }
})
/** スマホの戻る先（管理のメニュー。無ければホーム） */
const manageTo = computed<RouteLocationRaw | null>(() => (router.hasRoute('manage') ? { name: 'manage' } : resolvable(auth.homeRoute)))
/** ホームへの戻る先（ルートが無ければ出さない） */
const homeTo = computed(() => resolvable(auth.homeRoute))

function resolvable(to: RouteLocationRaw): RouteLocationRaw | null {
  try {
    router.resolve(to)
    return to
  } catch {
    return null
  }
}
const page = computed(() => group.value?.pages.find((p) => p.name === route.name) ?? null)
const pageLabel = computed(() => page.value?.label ?? props.title)
</script>

<template>
  <template v-if="group">
    <!-- タブレット：グループ名＋ページタブ -->
    <header
      class="ad-head app-head--tablet"
      :class="{ 'ad-head--notabs': group.pages.length < 2 }"
    >
      <div class="ad-title">
        <AppIcon
          :name="group.icon"
          :size="28"
        />
        <h1 class="r-h1 clamp1">
          {{ group.label }}
        </h1>
        <span
          v-if="viewingStoreName"
          class="r-chip r-chip--info r-chip--lg"
        >{{ viewingStoreName }}</span>
        <span class="grow" />
        <slot name="end">
          <AccountMenu tone="light" />
        </slot>
      </div>
      <nav
        v-if="group.pages.length > 1"
        class="r-ptabs"
        :aria-label="group.label"
      >
        <RouterLink
          v-for="p in group.pages"
          :key="p.name"
          :to="pageTo(p)"
          :class="{ on: p.name === route.name }"
          :aria-current="p.name === route.name ? 'page' : undefined"
        >
          {{ p.label }}
        </RouterLink>
      </nav>
    </header>
    <!-- スマホ：［管理］に戻る＋パンくず -->
    <header class="r-appbar app-head--phone">
      <RouterLink
        v-if="manageTo"
        :to="manageTo"
        class="r-appbar__back"
      >
        <AppIcon name="back" />
        <span>{{ ja.nav.manage }}</span>
      </RouterLink>
      <h1 class="r-appbar__title">
        {{ pageLabel }}
      </h1>
      <slot name="end">
        <AccountMenu />
      </slot>
    </header>
    <nav
      class="ad-crumb app-head--phone"
      :aria-label="ja.nav.crumb"
    >
      <span>{{ ja.nav.manage }}</span>
      <span aria-hidden="true">/</span>
      <span>{{ group.label }}</span>
      <template v-if="group.pages.length > 1">
        <span aria-hidden="true">/</span>
        <span>{{ pageLabel }}</span>
      </template>
      <span
        v-if="viewingStoreName"
        class="r-chip r-chip--info"
      >{{ viewingStoreName }}</span>
    </nav>
  </template>

  <header
    v-else
    class="r-appbar"
  >
    <RouterLink
      v-if="showHome && homeTo"
      :to="homeTo"
      class="r-appbar__back"
    >
      <AppIcon name="back" />
      <span>{{ ja.common.home }}</span>
    </RouterLink>
    <h1 class="r-appbar__title">
      {{ title }}
    </h1>
    <span
      v-if="viewingStoreName"
      class="r-appbar__meta clamp1"
    >{{ viewingStoreName }}</span>
    <slot name="end">
      <AccountMenu />
    </slot>
  </header>
</template>

<style scoped>
.app-head--phone { display: none; }

@media (max-width: 767px) {
  .app-head--tablet { display: none; }
  .r-appbar.app-head--phone { display: flex; }
  .ad-crumb.app-head--phone { display: flex; }
}
</style>
