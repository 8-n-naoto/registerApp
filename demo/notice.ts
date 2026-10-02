import { ref } from 'vue'

export const noticeText = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null

export function demoNotice(text: string): void {
  noticeText.value = text
  if (timer !== null) clearTimeout(timer)
  timer = setTimeout(() => { noticeText.value = null }, 4000)
}
