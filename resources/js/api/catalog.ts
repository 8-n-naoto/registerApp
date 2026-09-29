import { http } from '@/api/client'
import type { Category, Product, ProductColor, ProductOption } from '@/types/api'

// 06 §7 商品・カテゴリ・オプション（owner のみ）

export interface ProductInput {
  code: string // 新規で空欄なら自動採番
  name: string
  memo: string | null
  price: number
  category_id: number | null
  color: ProductColor
  is_active: boolean
  track_stock: boolean
}

export interface ProductCreateInput extends ProductInput {
  stock_qty: number
}

export type StockMode = 'set' | 'add'

export interface OptionInput {
  name: string
  price: number
  is_active: boolean
}

export interface ImportRow {
  line: number
  category: string | null
  name: string
  price: number
  color: ProductColor
  track_stock: boolean
  stock_qty: number
  is_active: boolean
  code: string | null // null は自動採番
  memo: string | null
}

export interface ImportLineMessages {
  line: number // 0 はファイル全体
  messages: string[]
}

export interface ImportResult {
  dry_run: boolean
  valid_count: number
  new_categories: string[]
  errors: ImportLineMessages[]
  warnings: ImportLineMessages[]
  rows: ImportRow[]
}

export async function fetchCatalog(): Promise<{ categories: Category[]; products: Product[] }> {
  return (await http.get<{ categories: Category[]; products: Product[] }>('/products')).data
}

export async function createProduct(input: ProductCreateInput): Promise<Product> {
  return (await http.post<Product>('/products', input)).data
}

export async function updateProduct(id: number, input: ProductInput): Promise<Product> {
  return (await http.put<Product>(`/products/${id}`, input)).data
}

export async function deleteProduct(id: number): Promise<void> {
  await http.delete(`/products/${id}`)
}

export async function changeStock(id: number, mode: StockMode, value: number): Promise<Product> {
  return (await http.patch<Product>(`/products/${id}/stock`, { mode, value })).data
}

export async function reorderProducts(ids: number[]): Promise<void> {
  await http.put('/products/order', { ids })
}

export async function importProducts(file: File, dryRun: boolean): Promise<ImportResult> {
  const form = new FormData()
  form.append('file', file)
  form.append('dry_run', dryRun ? '1' : '0')
  return (await http.post<ImportResult>('/products/import', form)).data
}

export async function createCategory(name: string): Promise<Category> {
  return (await http.post<Category>('/categories', { name })).data
}

export async function updateCategory(id: number, name: string): Promise<Category> {
  return (await http.put<Category>(`/categories/${id}`, { name })).data
}

export async function deleteCategory(id: number): Promise<void> {
  await http.delete(`/categories/${id}`)
}

export async function reorderCategories(ids: number[]): Promise<void> {
  await http.put('/categories/order', { ids })
}

export async function createOption(productId: number, input: OptionInput): Promise<ProductOption> {
  return (await http.post<ProductOption>(`/products/${productId}/options`, input)).data
}

export async function updateOption(id: number, input: OptionInput): Promise<ProductOption> {
  return (await http.put<ProductOption>(`/options/${id}`, input)).data
}

export async function deleteOption(id: number): Promise<void> {
  await http.delete(`/options/${id}`)
}

export async function reorderOptions(productId: number, ids: number[]): Promise<void> {
  await http.put(`/products/${productId}/options/order`, { ids })
}
