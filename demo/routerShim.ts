// デモ用：アーティファクトの iframe の中でパスを変えないよう、履歴をハッシュ方式に差し替える
import { createWebHashHistory } from 'vue-router'
export * from 'vue-router'
export const createWebHistory = () => createWebHashHistory()
