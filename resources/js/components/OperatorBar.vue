<script setup lang="ts">
// 13 §7 ホームの担当者の欄：名前・状態（勤務中 9:02〜／休憩中）、休憩の記録、担当者の切替
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import BottomSheet from '@/components/BottomSheet.vue'
import OperatorPicker from '@/components/OperatorPicker.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, isNetworkError } from '@/lib/apiError'
import { formatTime } from '@/lib/date'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const sheetOpen = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

const name = computed(() => auth.me?.user.name ?? '')
const status = computed(() => {
  const a = auth.me?.attendance
  if (!a) return ''
  return a.on_break ? ja.operator.onBreak : fmt(ja.operator.working, { time: formatTime(a.clock_in_at) })
})

/** 別の端末でログアウト（退勤）した等で勤務中でなくなっていれば S22 へ */
async function refresh(): Promise<void> {
  try {
    await auth.refreshMe()
  } catch {
    return // 通信できないときは手元の状態のまま
  }
  if (!auth.me) await router.replace({ name: 'login' })
  else if (auth.needsClockIn) await router.replace({ name: 'clock-in' })
}

onMounted(refresh)

async function toggleBreak(): Promise<void> {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    if (auth.onBreak) await auth.endBreak()
    else await auth.startBreak()
  } catch (err) {
    if (errorStatus(err) === 409) {
      // 別の端末で記録済み。いまの状態を読み直す
      await refresh()
    } else if (!isNetworkError(err)) {
      error.value = errorBody(err)?.message ?? ja.operator.breakFailed
    }
  } finally {
    busy.value = false
  }
}

function onSwitched(): void {
  sheetOpen.value = false
  error.value = null
}
</script>

<template>
  <section
    class="op-bar"
    :aria-label="ja.operator.label"
  >
    <p class="op-bar__who">
      <span class="op-bar__label">{{ ja.operator.label }}</span>
      <strong class="op-bar__name">{{ name }}</strong>
      <span
        v-if="status"
        class="op-bar__status"
        :class="{ 'op-bar__status--break': auth.onBreak }"
      >{{ status }}</span>
    </p>
    <div class="op-bar__actions">
      <button
        v-if="auth.working"
        type="button"
        class="op-bar__btn"
        :disabled="busy"
        @click="toggleBreak"
      >
        {{ auth.onBreak ? ja.operator.breakEnd : ja.operator.breakStart }}
      </button>
      <button
        type="button"
        class="op-bar__btn"
        @click="sheetOpen = true"
      >
        {{ ja.operator.switch }}
      </button>
    </div>
    <p
      v-if="error"
      class="op-bar__error"
      role="alert"
    >
      {{ error }}
    </p>
    <BottomSheet
      :open="sheetOpen"
      :title="ja.operator.switchTitle"
      @close="sheetOpen = false"
    >
      <OperatorPicker
        v-if="sheetOpen"
        :exclude-id="auth.me?.user.id ?? null"
        :help="ja.operator.switchHelp"
        @switched="onSwitched"
      />
    </BottomSheet>
  </section>
</template>

<style scoped>
.op-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 16px;
  margin-bottom: 16px;
  padding: 12px 16px;
  border: 1px solid var(--c-card-border);
  border-radius: var(--radius-card);
  background: var(--c-card-bg);
}

.op-bar__who { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; font-size: 18px; }
.op-bar__label { font-size: 16px; }
.op-bar__name { font-size: 22px; }

.op-bar__status {
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--c-surface);
  color: var(--c-success);
  font-size: 16px;
  font-weight: 700;
}

.op-bar__status--break { color: var(--c-change); }
.op-bar__actions { display: flex; flex-wrap: wrap; gap: 8px; }

.op-bar__btn {
  min-height: var(--tap-min);
  padding: 0 16px;
  border: 2px solid var(--c-on-primary);
  border-radius: var(--radius);
  background: transparent;
  color: var(--c-on-primary);
  font-size: 18px;
  font-weight: 700;
}

.op-bar__btn:active { background: rgba(255, 255, 255, 0.2); }
.op-bar__btn:disabled { opacity: 0.6; }

.op-bar__error {
  flex-basis: 100%;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--c-surface);
  color: var(--c-danger);
  font-weight: 700;
}
</style>
