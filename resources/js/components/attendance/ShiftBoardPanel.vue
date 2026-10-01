<script setup lang="ts">
// 13 §6.7 S21 勤務表：日ごとの予定（自分を強調）。owner は希望も並べ、予定を追加・修正・削除（#83〜#85）
import { computed, ref } from 'vue'
import { createShift, deleteShift, updateShift } from '@/api/shifts'
import BigButton from '@/components/BigButton.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import { fmt, ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import { formatDay, formatMinutes, monthDays, normalizeShiftTime, weekdayOf } from '@/lib/labor'
import type { Shift, ShiftBoard, ShiftRequest } from '@/types/api'

const props = defineProps<{ board: ShiftBoard; isOwner: boolean; myId: number | null }>()
const emit = defineEmits<{ changed: [] }>()

const t = ja.shifts

const names = computed(() => new Map(props.board.members.map((m) => [m.id, m.name])))
const days = computed(() =>
  monthDays(props.board.month.month).map((date) => ({
    date,
    weekday: weekdayOf(date),
    shifts: props.board.shifts.filter((s) => s.date === date),
    requests: props.board.requests.filter((r) => r.date === date),
  })),
)
const myTotal = computed(() => props.board.shifts.filter((s) => s.user_id === props.myId).reduce((sum, s) => sum + s.planned_minutes, 0))
const activeMembers = computed(() => props.board.members.filter((m) => m.is_active))

function nameOf(userId: number): string {
  return names.value.get(userId) ?? ''
}

function requestLabel(r: ShiftRequest): string {
  const time = r.start_time && r.end_time ? ` ${r.start_time}〜${r.end_time}` : ''
  return `${nameOf(r.user_id)}：${t.kind[r.kind]}${time}${r.note ? `（${r.note}）` : ''}`
}

// 予定の追加・修正
const editing = ref<Shift | 'new' | null>(null)
const form = ref({ user_id: 0, date: '', start_time: '', end_time: '', break_minutes: '0', note: '' })
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const notice = ref<string | null>(null)
const saving = ref(false)
const confirmingDelete = ref(false)
const deleting = ref(false)
const deleteError = ref<string | null>(null)
const editingShift = computed(() => (editing.value !== null && editing.value !== 'new' ? editing.value : null))
const sheetTitle = computed(() => (editing.value === 'new' ? t.addTitle : t.editTitle))

function startNew(date: string): void {
  errors.value = {}
  failed.value = null
  notice.value = null
  form.value = { user_id: activeMembers.value[0]?.id ?? 0, date, start_time: '', end_time: '', break_minutes: '0', note: '' }
  editing.value = 'new'
}

function startEdit(s: Shift): void {
  if (!props.isOwner) return
  errors.value = {}
  failed.value = null
  notice.value = null
  form.value = { user_id: s.user_id, date: s.date, start_time: s.start_time, end_time: s.end_time, break_minutes: String(s.break_minutes), note: s.note ?? '' }
  editing.value = s
}

async function save(): Promise<void> {
  if (saving.value || editing.value === null) return
  errors.value = {}
  failed.value = null
  form.value.start_time = normalizeShiftTime(form.value.start_time)
  form.value.end_time = normalizeShiftTime(form.value.end_time)
  const breakMinutes = /^\d+$/.test(form.value.break_minutes.trim()) ? Number(form.value.break_minutes.trim()) : Number.NaN
  if (Number.isNaN(breakMinutes)) {
    errors.value = { break_minutes: ja.attendance.numberInvalid }
    return
  }
  const input = {
    user_id: form.value.user_id,
    date: form.value.date,
    start_time: form.value.start_time,
    end_time: form.value.end_time,
    break_minutes: breakMinutes,
    note: form.value.note.trim() === '' ? null : form.value.note.trim(),
  }
  saving.value = true
  try {
    if (editing.value === 'new') await createShift(input)
    else await updateShift(editing.value.id, input)
    editing.value = null
    notice.value = t.saved
    emit('changed')
  } catch (err) {
    if (errorStatus(err) === 422) {
      errors.value = fieldErrors(err)
      if (Object.keys(errors.value).length === 0) failed.value = errorBody(err)?.message ?? ja.error.unexpected
    } else if (errorStatus(err) === 404) {
      editing.value = null
      emit('changed')
    } else if (!isNetworkError(err)) {
      failed.value = errorBody(err)?.message ?? ja.error.unexpected
    }
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  const s = editingShift.value
  if (!s || deleting.value) return
  deleting.value = true
  deleteError.value = null
  try {
    await deleteShift(s.id)
    confirmingDelete.value = false
    editing.value = null
    notice.value = t.deleted
    emit('changed')
  } catch (err) {
    if (!isNetworkError(err)) deleteError.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="shift-board-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="shift-board-heading"
        class="adm-panel__title"
      >
        {{ t.tabs.board }}
      </h2>
      <span
        v-if="myId !== null"
        class="adm-badge"
      >{{ fmt(t.plannedTotal, { time: formatMinutes(myTotal) }) }}</span>
    </div>
    <p
      v-if="board.month.memo"
      class="shift-memo"
    >
      {{ board.month.memo }}
    </p>
    <p
      v-if="notice"
      class="adm-ok"
      role="status"
    >
      {{ notice }}
    </p>
    <p v-if="!isOwner && board.month.published_at === null">
      {{ t.notPublished }}
    </p>
    <ol
      v-else
      class="shift-days"
    >
      <li
        v-for="day in days"
        :key="day.date"
        class="shift-day"
        :class="{ 'shift-day--sun': day.weekday === 0, 'shift-day--sat': day.weekday === 6 }"
      >
        <div class="shift-day__head">
          <h3 class="shift-day__date">
            {{ formatDay(day.date) }}
          </h3>
          <button
            v-if="isOwner"
            type="button"
            class="adm-btn"
            :aria-label="`${formatDay(day.date)} ${t.addShift}`"
            @click="startNew(day.date)"
          >
            {{ t.addShift }}
          </button>
        </div>
        <p
          v-if="day.shifts.length === 0"
          class="shift-day__none"
        >
          {{ t.noShift }}
        </p>
        <ul
          v-else
          class="shift-list"
        >
          <li
            v-for="s in day.shifts"
            :key="s.id"
          >
            <component
              :is="isOwner ? 'button' : 'div'"
              :type="isOwner ? 'button' : undefined"
              class="shift-item"
              :class="{ 'shift-item--mine': s.user_id === myId, 'shift-item--btn': isOwner }"
              :aria-label="isOwner ? fmt(ja.common.editNamed, { name: `${nameOf(s.user_id)} ${formatDay(s.date)}` }) : undefined"
              @click="startEdit(s)"
            >
              <span class="shift-item__name">{{ nameOf(s.user_id) }}<template v-if="s.user_id === myId">（{{ t.mine }}）</template></span>
              <span class="shift-item__time">{{ s.start_time }}〜{{ s.end_time }}</span>
              <span
                v-if="s.break_minutes > 0"
                class="shift-item__sub"
              >{{ t.breakMinutes }} {{ s.break_minutes }}</span>
              <span
                v-if="s.note"
                class="shift-item__sub"
              >{{ s.note }}</span>
            </component>
          </li>
        </ul>
        <ul
          v-if="isOwner && day.requests.length > 0"
          class="shift-requests"
          :aria-label="t.requestsOf"
        >
          <li
            v-for="r in day.requests"
            :key="r.user_id"
            class="shift-request"
            :class="`shift-request--${r.kind}`"
          >
            {{ requestLabel(r) }}
          </li>
        </ul>
      </li>
    </ol>

    <BottomSheet
      :open="editing !== null"
      :title="sheetTitle"
      @close="editing = null"
    >
      <form
        class="adm-form"
        novalidate
        :aria-label="sheetTitle"
        @submit.prevent="save"
      >
        <div class="adm-field">
          <label
            for="shift-user"
            class="adm-field__label"
          >{{ t.person }}</label>
          <select
            id="shift-user"
            v-model="form.user_id"
            class="adm-select"
            :aria-invalid="errors.user_id ? 'true' : undefined"
          >
            <option
              v-for="m in board.members"
              :key="m.id"
              :value="m.id"
            >
              {{ m.name }}
            </option>
          </select>
          <p
            v-if="errors.user_id"
            class="adm-error"
          >
            {{ errors.user_id }}
          </p>
        </div>
        <div class="adm-field">
          <label
            for="shift-date"
            class="adm-field__label"
          >{{ t.date }}</label>
          <input
            id="shift-date"
            v-model="form.date"
            class="adm-input shift-form__short"
            type="date"
            :aria-invalid="errors.date ? 'true' : undefined"
          >
          <p
            v-if="errors.date"
            class="adm-error"
          >
            {{ errors.date }}
          </p>
        </div>
        <div class="shift-form__times">
          <div class="adm-field">
            <label
              for="shift-start"
              class="adm-field__label"
            >{{ t.start }}</label>
            <input
              id="shift-start"
              v-model="form.start_time"
              class="adm-input"
              inputmode="numeric"
              placeholder="10:00"
              maxlength="5"
              autocomplete="off"
              aria-describedby="shift-time-help"
              :aria-invalid="errors.start_time ? 'true' : undefined"
              @blur="form.start_time = normalizeShiftTime(form.start_time)"
            >
            <p
              v-if="errors.start_time"
              class="adm-error"
            >
              {{ errors.start_time }}
            </p>
          </div>
          <div class="adm-field">
            <label
              for="shift-end"
              class="adm-field__label"
            >{{ t.end }}</label>
            <input
              id="shift-end"
              v-model="form.end_time"
              class="adm-input"
              inputmode="numeric"
              placeholder="15:00"
              maxlength="5"
              autocomplete="off"
              aria-describedby="shift-time-help"
              :aria-invalid="errors.end_time ? 'true' : undefined"
              @blur="form.end_time = normalizeShiftTime(form.end_time)"
            >
            <p
              v-if="errors.end_time"
              class="adm-error"
            >
              {{ errors.end_time }}
            </p>
          </div>
        </div>
        <p
          id="shift-time-help"
          class="adm-help"
        >
          {{ t.timeHelp }}
        </p>
        <div class="adm-field">
          <label
            for="shift-break"
            class="adm-field__label"
          >{{ t.breakMinutes }}</label>
          <input
            id="shift-break"
            v-model="form.break_minutes"
            class="adm-input shift-form__short"
            inputmode="numeric"
            maxlength="3"
            autocomplete="off"
            :aria-invalid="errors.break_minutes ? 'true' : undefined"
          >
          <p
            v-if="errors.break_minutes"
            class="adm-error"
          >
            {{ errors.break_minutes }}
          </p>
        </div>
        <div class="adm-field">
          <label
            for="shift-note"
            class="adm-field__label"
          >{{ t.note }}</label>
          <input
            id="shift-note"
            v-model="form.note"
            class="adm-input"
            maxlength="100"
            autocomplete="off"
            :aria-invalid="errors.note ? 'true' : undefined"
          >
          <p
            v-if="errors.note"
            class="adm-error"
          >
            {{ errors.note }}
          </p>
        </div>
        <p
          v-if="failed"
          class="adm-error"
          role="alert"
        >
          {{ failed }}
        </p>
        <div class="adm-actions">
          <BigButton
            type="submit"
            :loading="saving"
          >
            {{ t.save }}
          </BigButton>
          <BigButton
            variant="secondary"
            @click="editing = null"
          >
            {{ t.cancel }}
          </BigButton>
          <BigButton
            v-if="editingShift"
            variant="danger"
            @click="confirmingDelete = true"
          >
            {{ t.delete }}
          </BigButton>
        </div>
      </form>
    </BottomSheet>

    <ConfirmDialog
      :open="confirmingDelete"
      :title="t.deleteTitle"
      :message="editingShift ? fmt(t.deleteMessage, { name: nameOf(editingShift.user_id), date: formatDay(editingShift.date) }) : ''"
      :confirm-label="t.delete"
      danger
      :loading="deleting"
      :error="deleteError"
      @confirm="remove"
      @cancel="confirmingDelete = false"
    />
  </section>
</template>

<style scoped>
.shift-memo { padding: 12px 16px; border-radius: var(--radius); background: var(--c-surface-alt); white-space: pre-wrap; }
.shift-days { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }

.shift-day {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
}

.shift-day__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.shift-day__date { font-size: 18px; }
.shift-day--sun .shift-day__date { color: var(--c-danger); }
.shift-day--sat .shift-day__date { color: var(--c-primary); }
.shift-day__none { color: var(--c-text-sub); }
.shift-list { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 0; list-style: none; }

.shift-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 12px;
  width: 100%;
  min-height: var(--tap-min);
  padding: 6px 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--radius);
  background: var(--c-surface);
  color: var(--c-text);
  font-size: 16px;
  text-align: left;
}

.shift-item--btn { cursor: pointer; }
.shift-item--mine { border: 2px solid var(--c-primary); background: var(--pc-blue-bg); }
.shift-item__name { font-weight: 700; }
.shift-item__time { font-variant-numeric: tabular-nums; font-weight: 700; }
.shift-item__sub { color: var(--c-text-sub); }
.shift-requests { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }

.shift-request {
  padding: 2px 10px;
  border: 1px solid var(--c-border);
  border-radius: 999px;
  font-size: 16px;
}

.shift-request--available { border-color: var(--c-success); color: var(--c-success); }
.shift-request--unavailable { border-color: var(--c-danger); color: var(--c-danger); }
.shift-form__short { max-width: 240px; }
.shift-form__times { display: flex; flex-wrap: wrap; gap: 12px; }
.shift-form__times .adm-field { flex: 1 1 140px; }
</style>
