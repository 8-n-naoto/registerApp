import { defineStore } from 'pinia'
import { ref } from 'vue'

/** 画面をまたぐ表示の状態 */
export const useUiStore = defineStore('ui', () => {
  /** 通信エラーの赤い帯（08 §8） */
  const networkError = ref(false)
  /** ログイン画面に出すお知らせ（停止された・ログインが切れた等） */
  const loginNotice = ref<string | null>(null)

  return { networkError, loginNotice }
})
