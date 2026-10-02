<script setup lang="ts">
// 管理の画面の骨格（デザインシステム「レジアプリ」）。タブレットは左にレール（大分類）、スマホはレールを出さない
// 並びは lib/adminNav。いまのグループに印を付け、押すとグループの最初のページへ
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
import { ja } from '@/i18n/ja'
import { groupOf, groupTo, navFor } from '@/lib/adminNav'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const groups = computed(() => navFor(auth.role))
const top = computed(() => groups.value.filter((g) => !g.bottom))
const bottom = computed(() => groups.value.filter((g) => g.bottom))
const current = computed(() => groupOf(route.name)?.key ?? null)
const storeName = computed(() => auth.me?.store?.name ?? '')
</script>

<template>
  <div class="ad">
    <nav
      class="ad-rail"
      :aria-label="ja.nav.rail"
    >
      <span
        v-if="storeName"
        class="ad-rail__store clamp2"
      >{{ storeName }}</span>
      <RouterLink
        :to="auth.homeRoute"
        class="ad-rail__i"
      >
        <AppIcon
          name="home"
          :size="26"
        />
        <span>{{ ja.common.home }}</span>
      </RouterLink>
      <RouterLink
        v-for="g in top"
        :key="g.key"
        :to="groupTo(g)"
        class="ad-rail__i"
        :class="{ on: g.key === current }"
        :aria-current="g.key === current ? 'page' : undefined"
      >
        <AppIcon
          :name="g.icon"
          :size="26"
        />
        <span>{{ g.label }}</span>
      </RouterLink>
      <span class="ad-rail__sp" />
      <RouterLink
        v-for="g in bottom"
        :key="g.key"
        :to="groupTo(g)"
        class="ad-rail__i"
        :class="{ on: g.key === current }"
        :aria-current="g.key === current ? 'page' : undefined"
      >
        <AppIcon
          :name="g.icon"
          :size="26"
        />
        <span>{{ g.label }}</span>
      </RouterLink>
    </nav>
    <div class="ad-main">
      <slot />
    </div>
  </div>
</template>
