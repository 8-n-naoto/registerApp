// デモ用：Service Worker を登録しない
import { ref } from 'vue'

export const needRefresh = ref(false)
export function setupPwa(): void {}
export async function applyUpdate(): Promise<void> {}
