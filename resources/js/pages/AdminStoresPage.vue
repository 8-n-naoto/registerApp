<script setup lang="ts">
// A01 店舗一覧（08 §3.4・§5.13）：店舗ごとのカード（状態・本日の売上・オーナー）、閲覧（S04 / S06 / S12）、停止 / 再開、バックアップ
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { backupUrl, fetchAdminStores, setStoreActive, type AdminStoreRow } from '@/api/admin'
import AccountMenu from '@/components/AccountMenu.vue'
import AppIcon from '@/components/AppIcon.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, isNetworkError } from '@/lib/apiError'
import { formatBusinessDate, formatTime } from '@/lib/date'
import { formatYen } from '@/lib/money'
import { useAdminStore } from '@/stores/admin'

const t = ja.admin
const router = useRouter()
const admin = useAdminStore()

const stores = ref<AdminStoreRow[]>([])
const loading = ref(true)
const loadFailed = ref<string | null>(null)
const notice = ref<string | null>(null)

const target = ref<AdminStoreRow | null>(null)
const toggling = ref(false)
const toggleError = ref<string | null>(null)

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = null
  try {
    stores.value = await fetchAdminStores()
  } catch (err) {
    loadFailed.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? t.loadFailed)
  } finally {
    loading.value = false
  }
}

onMounted(load)

type ViewRoute = 'sales-daily' | 'sales-summary' | 'logs'

function view(store: AdminStoreRow, name: ViewRoute): void {
  admin.view(store.id, store.name)
  void router.push({ name, query: { store_id: String(store.id) } })
}

function askToggle(store: AdminStoreRow): void {
  notice.value = null
  toggleError.value = null
  target.value = store
}

async function confirmToggle(): Promise<void> {
  const store = target.value
  if (!store || toggling.value) return
  toggling.value = true
  toggleError.value = null
  try {
    const res = await setStoreActive(store.id, !store.is_active)
    stores.value = stores.value.map((s) => (s.id === res.id ? { ...s, is_active: res.is_active } : s))
    notice.value = fmt(res.is_active ? t.resumed : t.suspended, { name: store.name })
    target.value = null
  } catch (err) {
    toggleError.value = isNetworkError(err) ? ja.error.network : (errorBody(err)?.message ?? ja.error.unexpected)
  } finally {
    toggling.value = false
  }
}
</script>

<template>
  <div class="admin-home">
    <header class="r-appbar">
      <h1 class="r-appbar__title admin-home__title">
        {{ t.storesTitle }}
      </h1>
      <AccountMenu />
    </header>

    <div class="admin-home__body">
      <div class="admin-home__tools">
        <a
          class="admin-home__tool r-btn r-btn--secondary r-btn--sm"
          :href="backupUrl"
          download
          data-test="backup"
        >{{ t.backup }}</a>
        <RouterLink
          class="admin-home__tool r-btn r-btn--secondary r-btn--sm"
          :to="{ name: 'logs' }"
        >
          {{ t.allLogs }}
        </RouterLink>
      </div>
      <p class="admin-home__help r-help">
        {{ t.backupHelp }}
      </p>

      <main class="admin-home__list">
        <div
          v-if="notice"
          class="admin-home__notice r-banner r-banner--ok"
          role="status"
        >
          <AppIcon
            name="check"
            :size="24"
          />
          <span class="r-banner__d">{{ notice }}</span>
        </div>
        <p
          v-if="loading"
          class="admin-home__card r-card r-card__body"
          role="status"
        >
          {{ ja.common.loading }}
        </p>
        <div
          v-else-if="loadFailed"
          class="admin-home__card r-card r-card__body"
        >
          <p
            class="admin-home__error r-err"
            role="alert"
          >
            {{ loadFailed }}
          </p>
          <BigButton @click="load">
            {{ t.retry }}
          </BigButton>
        </div>
        <p
          v-else-if="stores.length === 0"
          class="admin-home__card r-card r-card__body"
        >
          {{ t.empty }}
        </p>
        <ul
          v-else
          class="store-list"
        >
          <li
            v-for="store in stores"
            :key="store.id"
            class="admin-home__card store-card r-card r-card__body"
            :class="{ 'store-card--off': !store.is_active }"
            data-test="store"
          >
            <div class="store-card__head">
              <h2 class="store-card__name">
                {{ store.name }}
              </h2>
              <span
                class="store-card__status r-chip"
                :class="store.is_active ? 'store-card__status--on r-chip--ok' : 'store-card__status--off r-chip--neutral'"
              >{{ store.is_active ? t.statusActive : t.statusSuspended }}</span>
            </div>
            <p class="store-card__today tabular">
              <span>{{ fmt(t.today, { date: formatBusinessDate(store.today.business_date) }) }}</span>
              <strong>{{ fmt(t.todaySales, { amount: formatYen(store.today.total), n: store.today.count }) }}</strong>
              <span>{{ store.today.last_sold_at ? fmt(t.lastSold, { time: formatTime(store.today.last_sold_at) }) : t.noSales }}</span>
            </p>
            <p class="store-card__meta">
              <span>{{ store.owner_login_ids.length > 0 ? fmt(t.owners, { ids: store.owner_login_ids.join('、') }) : t.ownersNone }}</span>
              <span>{{ fmt(t.counts, { staff: store.staff_count, products: store.product_count }) }}</span>
            </p>
            <div class="store-card__actions">
              <button
                type="button"
                class="store-btn r-btn r-btn--secondary r-btn--sm"
                @click="view(store, 'sales-daily')"
              >
                {{ t.viewDaily }}
              </button>
              <button
                type="button"
                class="store-btn r-btn r-btn--secondary r-btn--sm"
                @click="view(store, 'sales-summary')"
              >
                {{ t.viewSummary }}
              </button>
              <button
                type="button"
                class="store-btn r-btn r-btn--secondary r-btn--sm"
                @click="view(store, 'logs')"
              >
                {{ t.viewLogs }}
              </button>
              <button
                type="button"
                class="store-btn r-btn r-btn--sm"
                :class="store.is_active ? 'store-btn--danger r-btn--danger' : 'r-btn--primary'"
                @click="askToggle(store)"
              >
                {{ store.is_active ? t.suspend : t.resume }}
              </button>
            </div>
          </li>
        </ul>
      </main>
    </div>

    <ConfirmDialog
      :open="target !== null"
      :title="target ? fmt(target.is_active ? t.suspendTitle : t.resumeTitle, { name: target.name }) : ''"
      :message="target?.is_active ? t.suspendMessage : t.resumeMessage"
      :confirm-label="target?.is_active ? t.suspend : t.resume"
      :danger="target?.is_active ?? false"
      :loading="toggling"
      :error="toggleError"
      @confirm="confirmToggle"
      @cancel="target = null"
    />
  </div>
</template>

<style scoped>
.admin-home { min-height: 100dvh; background: var(--c-surface-alt); }
.admin-home__title { flex: 1; min-width: 0; margin: 0; }
.admin-home__body { display: flex; flex-direction: column; gap: 12px; padding: 16px calc(16px + var(--safe-right)) calc(24px + var(--safe-bottom)) calc(16px + var(--safe-left)); }
.admin-home__tools { display: flex; flex-wrap: wrap; gap: 12px; }
.admin-home__tool { text-decoration: none; }
.admin-home__help { margin: 0; }
.admin-home__list { display: flex; flex-direction: column; gap: 12px; }
.admin-home__card { gap: 12px; }

.store-list { display: flex; flex-direction: column; gap: 12px; margin: 0; padding: 0; list-style: none; }
.store-card--off { background: var(--c-surface-alt); }
.store-card__head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.store-card__name { margin: 0; font-size: var(--fs-heading); font-weight: 800; overflow-wrap: anywhere; }

.store-card__today,
.store-card__meta { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 0; }
.store-card__meta { color: var(--c-text-sub); font-size: 16px; overflow-wrap: anywhere; }
.store-card__actions { display: flex; flex-wrap: wrap; gap: 8px; }

@media (min-width: 768px) {
  .admin-home__body { padding: 20px calc(32px + var(--safe-right)) calc(32px + var(--safe-bottom)) calc(32px + var(--safe-left)); }
}
</style>
