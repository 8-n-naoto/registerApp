<script setup lang="ts">
// 13 §6.7 S21 月の設定（owner）：希望の締切・お知らせ・公開（#82）
import { ref, watch } from 'vue'
import { updateShiftMonth } from '@/api/shifts'
import BigButton from '@/components/BigButton.vue'
import { ja } from '@/i18n/ja'
import { errorBody, errorStatus, fieldErrors, isNetworkError } from '@/lib/apiError'
import type { ShiftMonth } from '@/types/api'

const props = defineProps<{ month: ShiftMonth }>()
const emit = defineEmits<{ saved: [month: ShiftMonth] }>()

const t = ja.shifts

const deadline = ref('')
const memo = ref('')
const errors = ref<Record<string, string>>({})
const failed = ref<string | null>(null)
const notice = ref<string | null>(null)
const saving = ref(false)

watch(
  () => props.month,
  (m) => {
    deadline.value = m.request_deadline ?? ''
    memo.value = m.memo ?? ''
  },
  { immediate: true },
)

async function save(published: boolean, done: string): Promise<void> {
  if (saving.value) return
  errors.value = {}
  failed.value = null
  notice.value = null
  saving.value = true
  try {
    const saved = await updateShiftMonth({
      month: props.month.month,
      request_deadline: deadline.value === '' ? null : deadline.value,
      memo: memo.value.trim() === '' ? null : memo.value.trim(),
      published,
    })
    notice.value = done
    emit('saved', saved)
  } catch (err) {
    if (errorStatus(err) === 422) errors.value = fieldErrors(err)
    else if (!isNetworkError(err)) failed.value = errorBody(err)?.message ?? ja.error.unexpected
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section
    class="adm-panel"
    aria-labelledby="shift-month-heading"
  >
    <div class="adm-panel__head">
      <h2
        id="shift-month-heading"
        class="adm-panel__title"
      >
        {{ t.monthHeading }}
      </h2>
      <span
        class="adm-badge"
        :class="{ 'adm-badge--primary': month.published_at !== null }"
      >{{ month.published_at !== null ? t.published : t.unpublished }}</span>
    </div>
    <form
      class="adm-form"
      novalidate
      @submit.prevent="save(month.published_at !== null, t.monthSaved)"
    >
      <div class="adm-field">
        <label
          for="shift-deadline"
          class="adm-field__label"
        >{{ t.deadlineLabel }}</label>
        <input
          id="shift-deadline"
          v-model="deadline"
          class="adm-input shift-month__date"
          type="date"
          :aria-invalid="errors.request_deadline ? 'true' : undefined"
        >
        <p
          v-if="errors.request_deadline"
          class="adm-error"
        >
          {{ errors.request_deadline }}
        </p>
      </div>
      <div class="adm-field">
        <label
          for="shift-memo"
          class="adm-field__label"
        >{{ t.memoLabel }}</label>
        <textarea
          id="shift-memo"
          v-model="memo"
          class="adm-input shift-month__memo"
          rows="3"
          maxlength="500"
          :aria-invalid="errors.memo ? 'true' : undefined"
        />
        <p
          v-if="errors.memo"
          class="adm-error"
        >
          {{ errors.memo }}
        </p>
      </div>
      <p
        v-if="notice"
        class="adm-ok"
        role="status"
      >
        {{ notice }}
      </p>
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
          variant="secondary"
          :loading="saving"
        >
          {{ t.saveMonth }}
        </BigButton>
        <BigButton
          v-if="month.published_at === null"
          :loading="saving"
          @click="save(true, t.publishedDone)"
        >
          {{ t.publish }}
        </BigButton>
        <BigButton
          v-else
          variant="danger"
          :loading="saving"
          @click="save(false, t.unpublishedDone)"
        >
          {{ t.unpublish }}
        </BigButton>
      </div>
    </form>
  </section>
</template>

<style scoped>
.shift-month__date { max-width: 240px; }
.shift-month__memo { min-height: 96px; padding-top: 10px; padding-bottom: 10px; resize: vertical; }
</style>
