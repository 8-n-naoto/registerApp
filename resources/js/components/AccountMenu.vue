<script setup lang="ts">
// 右上のアカウントメニュー（08 §3.1・§3.2）。パスワード変更 / 端末名の設定 / ログアウト（勤務中なら退勤。13 §6.1）
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const open = ref(false)
const loggingOut = ref(false)
const confirming = ref(false)
const root = ref<HTMLElement | null>(null)

const name = computed(() => auth.me?.user.name ?? '')
const roleLabel = computed(() => (auth.role ? ja.role[auth.role] : ''))
const label = computed(() => fmt(ja.menu.open, { name: name.value, role: roleLabel.value }))

function onDocumentPointer(event: PointerEvent): void {
  if (root.value && event.target instanceof Node && !root.value.contains(event.target)) open.value = false
}
function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') open.value = false
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointer)
    document.addEventListener('keydown', onKey)
  } else {
    document.removeEventListener('pointerdown', onDocumentPointer)
    document.removeEventListener('keydown', onKey)
  }
})
onBeforeUnmount(() => {
  open.value = false
})

/** 勤務中ならログアウト = 退勤になることを確かめる */
function requestLogout(): void {
  if (auth.working) {
    open.value = false
    confirming.value = true
    return
  }
  void logout()
}

async function logout(): Promise<void> {
  loggingOut.value = true
  try {
    await auth.logout()
  } catch {
    // 通信できなくても端末側はログアウト扱いにする（サーバーのセッションは期限で切れる）
  } finally {
    loggingOut.value = false
  }
  open.value = false
  confirming.value = false
  // replace にして、戻る操作でログイン後の画面に戻らないようにする（AC-S00-5）
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div
    ref="root"
    class="account-menu"
  >
    <button
      type="button"
      class="account-menu__toggle"
      :aria-expanded="open"
      aria-haspopup="menu"
      :aria-label="label"
      @click="open = !open"
    >
      <svg
        class="account-menu__icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <circle
          cx="12"
          cy="8"
          r="4"
        />
        <path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7" />
      </svg>
      <span class="account-menu__name">{{ name }}（{{ roleLabel }}）</span>
      <span
        class="account-menu__caret"
        aria-hidden="true"
      >▼</span>
    </button>
    <div
      v-if="open"
      class="account-menu__panel"
      role="menu"
    >
      <p class="account-menu__who">
        {{ name }}（{{ roleLabel }}）
      </p>
      <RouterLink
        :to="{ name: 'account' }"
        class="account-menu__item"
        role="menuitem"
        @click="open = false"
      >
        {{ ja.menu.password }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'account', hash: '#device' }"
        class="account-menu__item"
        role="menuitem"
        @click="open = false"
      >
        {{ ja.menu.device }}
      </RouterLink>
      <button
        type="button"
        class="account-menu__item account-menu__item--danger"
        role="menuitem"
        :disabled="loggingOut"
        @click="requestLogout"
      >
        {{ ja.menu.logout }}
      </button>
    </div>
    <ConfirmDialog
      :open="confirming"
      :title="ja.menu.logoutWorkingTitle"
      :message="fmt(ja.menu.logoutWorkingMessage, { name })"
      :confirm-label="ja.menu.logoutWorkingConfirm"
      danger
      :loading="loggingOut"
      @confirm="logout"
      @cancel="confirming = false"
    />
  </div>
</template>

<style scoped>
.account-menu { position: relative; }

.account-menu__toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: var(--tap-min);
  min-height: var(--tap-min);
  padding: 0;
  border: 2px solid var(--c-on-primary);
  border-radius: var(--radius);
  background: transparent;
  color: var(--c-on-primary);
  font-weight: 700;
}

.account-menu__toggle:active { background: rgba(255, 255, 255, 0.2); }

.account-menu__icon {
  width: 26px;
  height: 26px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
}

/* スマホ：アイコンだけのボタン（08 §3.2） */
.account-menu__name,
.account-menu__caret { display: none; }

.account-menu__panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 50;
  display: flex;
  flex-direction: column;
  min-width: 240px;
  padding: 8px;
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.account-menu__who {
  padding: 8px 12px;
  color: var(--c-text-sub);
  font-size: 16px;
}

.account-menu__item {
  display: flex;
  align-items: center;
  min-height: var(--btn-h);
  padding: 0 16px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--c-text);
  font-weight: 700;
  text-align: left;
  text-decoration: none;
}

.account-menu__item:active { background: var(--c-surface-alt); }
.account-menu__item--danger { color: var(--c-danger); }

@media (min-width: 768px) {
  .account-menu__toggle { padding: 0 16px; }
  .account-menu__icon { display: none; }
  .account-menu__name,
  .account-menu__caret { display: inline; }
  .account-menu__caret { font-size: 14px; }
  .account-menu__who { display: none; }
}
</style>
