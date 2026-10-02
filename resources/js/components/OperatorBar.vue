<script setup lang="ts">
// 13 §7 ホームの担当者の欄：名前・状態（勤務中 9:02〜／休憩中）、休憩の記録、担当者の切替
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '@/components/AppIcon.vue'
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
const initial = computed(() => Array.from(name.value)[0] ?? '')
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
    class="op-bar h-op"
    :aria-label="ja.operator.label"
  >
    <p class="op-bar__who op">
      <span
        class="op__av"
        aria-hidden="true"
      >{{ initial }}</span>
      <span class="op-bar__label">{{ ja.operator.label }}</span>
      <strong class="op-bar__name op__n clamp1">{{ name }}</strong>
      <span
        v-if="status"
        class="op-bar__status r-chip"
        :class="auth.onBreak ? 'r-chip--warn op-bar__status--break' : 'r-chip--ok'"
      >{{ status }}</span>
    </p>
    <div class="op-bar__actions">
      <button
        v-if="auth.working"
        type="button"
        class="op-bar__btn r-btn r-btn--secondary r-btn--sm"
        :disabled="busy"
        @click="toggleBreak"
      >
        <AppIcon
          :name="auth.onBreak ? 'play' : 'pause'"
          :size="20"
        />
        <span>{{ auth.onBreak ? ja.operator.breakEnd : ja.operator.breakStart }}</span>
      </button>
      <button
        type="button"
        class="op-bar__btn r-btn r-btn--secondary r-btn--sm"
        @click="sheetOpen = true"
      >
        <AppIcon
          name="swap"
          :size="20"
        />
        <span>{{ ja.operator.switch }}</span>
      </button>
    </div>
    <p
      v-if="error"
      class="op-bar__error r-err"
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
.op-bar { justify-content: space-between; gap: 8px 12px; }
.op-bar__who { flex: 1 1 auto; flex-wrap: wrap; margin: 0; }
.op-bar__name { min-width: 0; }
.op-bar__label { color: var(--c-text-sub); font-size: 15px; }
.op-bar__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.op-bar__btn:disabled { opacity: 0.6; }
.op-bar__error { flex-basis: 100%; }
</style>
