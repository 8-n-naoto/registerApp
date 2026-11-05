import { apiBaseUrl, http } from '@/api/client'

// 06 §11 admin

export interface AdminStoreRow {
  id: number
  name: string
  is_active: boolean
  owner_login_ids: string[]
  staff_count: number
  product_count: number
  today: { business_date: string; total: number; count: number; last_sold_at: string | null }
}

/** #43 */
export async function fetchAdminStores(): Promise<AdminStoreRow[]> {
  return (await http.get<{ stores: AdminStoreRow[] }>('/admin/stores')).data.stores
}

export interface AdminStoreInput {
  name: string
  owner_login_id: string
  owner_name: string
  password: string
  password_confirmation: string
}

export interface AdminStoreCreated {
  id: number
  name: string
  owner_login_id: string
}

/** #107 店舗とオーナーを同時に作る */
export async function createAdminStore(input: AdminStoreInput): Promise<AdminStoreCreated> {
  return (await http.post<AdminStoreCreated>('/admin/stores', input)).data
}

/** #44 */
export async function setStoreActive(id: number, isActive: boolean): Promise<{ id: number; is_active: boolean }> {
  return (await http.patch<{ id: number; is_active: boolean }>(`/admin/stores/${id}/active`, { is_active: isActive })).data
}

/** #45 はファイルのダウンロード。Cookie のセッションで通るため、リンクで開く */
export const backupUrl = `${apiBaseUrl}/admin/backup`
