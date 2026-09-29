/** 同じ名前の商品を見分けるための表示名。メモがあれば「商品名（メモ）」にする */
export function nameWithMemo(name: string, memo: string | null | undefined): string {
  return memo ? `${name}（${memo}）` : name
}

/** 集計の商品行の 2 行目（メモ・商品コード）。どちらも無ければ空文字 */
export function productSub(row: { product_code: string; product_memo: string | null }): string {
  return [row.product_memo ?? '', row.product_code].filter((s) => s !== '').join('・')
}
