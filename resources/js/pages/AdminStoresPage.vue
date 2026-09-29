<script setup lang="ts">
// A01 店舗一覧（08 §3.4・§5.13）：店舗ごとのカード（状態・本日の売上・オーナー）、閲覧（S04 / S06 / S12）、停止 / 再開、バックアップ
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { backupUrl, fetchAdminStores, setStoreActive, type AdminStoreRow } from '@/api/admin'
import AccountMenu from '@/components/AccountMenu.vue'
import BigButton from '@/components/BigButton.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import WaveBackground from '@/components/WaveBackground.vue'
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
    <WaveBackground />
    <header class="admin-home__top">
      <div>
        <h1 class="admin-home__title">
          {{ ja.app.title }}
        </h1>
        <p class="admin-home__sub">
          {{ t.storesTitle }}
        </p>
      </div>
      <AccountMenu />
    </header>

    <div class="admin-home__tools">
      <a
        class="admin-home__tool"
        :href="backupUrl"
        download
        data-test="backup"
      >{{ t.backup }}</a>
      <RouterLink
        class="admin-home__tool"
        :to="{ name: 'logs' }"
      >
        {{ t.allLogs }}
      </RouterLink>
    </div>
    <p class="admin-home__help">
      {{ t.backupHelp }}
    </p>

    <main class="admin-home__list">
      <p
        v-if="notice"
        class="admin-home__notice"
        role="status"
      >
        {{ notice }}
      </p>
      <p
        v-if="loading"
        class="admin-home__card"
        role="status"
      >
        {{ ja.common.loading }}
      </p>
      <div
        v-else-if="loadFailed"
        class="admin-home__card"
      >
        <p
          class="admin-home__error"
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
        class="admin-home__card"
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
          class="admin-home__card store-card"
          :class="{ 'store-card--off': !store.is_active }"
          data-test="store"
        >
          <div class="store-card__head">
            <h2 class="store-card__name">
              {{ store.name }}
            </h2>
            <span
              class="store-card__status"
              :class="store.is_active ? 'store-card__status--on' : 'store-card__status--off'"
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
              class="store-btn"
              @click="view(store, 'sales-daily')"
            >
              {{ t.viewDaily }}
            </button>
            <button
              type="button"
              class="store-btn"
              @click="view(store, 'sales-summary')"
            >
              {{ t.viewSummary }}
            </button>
            <button
              type="button"
              class="store-btn"
              @click="view(store, 'logs')"
            >
              {{ t.viewLogs }}
            </button>
            <button
              type="button"
              class="store-btn"
              :class="{ 'store-btn--danger': store.is_active }"
              @click="askToggle(store)"
            >
              {{ store.is_active ? t.suspend : t.resume }}
            </button>
          </div>
        </li>
      </ul>
    </main>

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
  margin-bottom: 16px;
}

.admin-home__title {
  font-size: var(--fs-home-title);
  font-weight: 800;
  line-height: 1.1;
}

.admin-home__sub { margin-top: 8px; font-size: 20px; font-weight: 700; }

.admin-home__tools { display: flex; flex-wrap: wrap; gap: 12px; }

.admin-home__tool {
  display: inline-flex;
  align-items: center;
  min-height: var(--tap-min);
  padding: 0 20px;
  border: 2px solid var(--c-on-primary);
  border-radius: var(--radius);
  color: var(--c-on-primary);
  font-weight: 700;
  text-decoration: none;
}

.admin-home__help { margin: 8px 0 24px; font-size: 16px; }

.admin-home__list { display: flex; flex-direction: column; gap: 12px; }
.admin-home__notice { font-weight: 700; }

.admin-home__card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 20px 24px;
  border-radius: var(--radius-card);
  background: var(--c-surface);
  color: var(--c-text);
}

.admin-home__error { color: var(--c-danger); font-weight: 700; }

.store-list { display: flex; flex-direction: column; gap: 12px; margin: 0; padding: 0; list-style: none; }
.store-card--off { background: var(--c-surface-alt); }
.store-card__head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.store-card__name { font-size: var(--fs-heading); overflow-wrap: anywhere; }

.store-card__status {
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 16px;
  font-weight: 700;
}

.store-card__status--on { background: var(--c-primary); color: var(--c-on-primary); }
.store-card__status--off { background: var(--c-danger); color: var(--c-on-primary); }

.store-card__today,
.store-card__meta { display: flex; flex-wrap: wrap; gap: 4px 16px; }
.store-card__meta { color: var(--c-text-sub); font-size: 16px; overflow-wrap: anywhere; }
.store-card__actions { display: flex; flex-wrap: wrap; gap: 8px; }

.store-btn {
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-primary);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-primary);
  font-size: 16px;
  font-weight: 700;
}

.store-btn--danger { border-color: var(--c-danger); color: var(--c-danger); }
</style>
