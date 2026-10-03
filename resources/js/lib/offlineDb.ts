/**
 * 14 §7.1 端末に残す値（オフライン会計の送信待ち・商品マスタ・ログイン中の利用者）。
 * IndexedDB に書き、使えない（Safari のプライベートブラウズ・古い端末・テスト環境）ときは localStorage に書く。
 * 読むときは両方を見る（途中で IndexedDB が使えなくなっても、書いた値を失わない）。
 * 値は JSON にできるものだけ。失敗は呼び出し側へ例外で返す（送信待ちの保存に失敗したら会計を確定扱いにしない）
 */

const DB_NAME = 'regi-offline'
const STORE = 'kv'
const LS_PREFIX = 'regi-offline:'

let dbPromise: Promise<IDBDatabase | null> | null = null

function openDb(): Promise<IDBDatabase | null> {
  dbPromise ??= new Promise((resolve) => {
    if (typeof indexedDB === 'undefined') {
      resolve(null)
      return
    }
    try {
      const req = indexedDB.open(DB_NAME, 1)
      req.onupgradeneeded = () => {
        if (!req.result.objectStoreNames.contains(STORE)) req.result.createObjectStore(STORE)
      }
      req.onsuccess = () => resolve(req.result)
      req.onerror = () => resolve(null)
      req.onblocked = () => resolve(null)
    } catch {
      resolve(null)
    }
  })
  return dbPromise
}

/** 1 回の読み書き。書き込みはトランザクションの完了（oncomplete）を待ってから返す */
function run<T>(db: IDBDatabase, mode: IDBTransactionMode, op: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, mode)
    const req = op(tx.objectStore(STORE))
    tx.oncomplete = () => resolve(req.result)
    tx.onerror = () => reject(tx.error ?? new Error('IndexedDB error'))
    tx.onabort = () => reject(tx.error ?? new Error('IndexedDB aborted'))
  })
}

function lsGet(key: string): unknown {
  try {
    const raw = localStorage.getItem(LS_PREFIX + key)
    return raw === null ? undefined : (JSON.parse(raw) as unknown)
  } catch {
    return undefined
  }
}

export async function kvGet(key: string): Promise<unknown> {
  const db = await openDb()
  if (db !== null) {
    try {
      const value = await run<unknown>(db, 'readonly', (s) => s.get(key))
      if (value !== undefined) return value
    } catch {
      // localStorage を見る
    }
  }
  return lsGet(key)
}

export async function kvSet(key: string, value: unknown): Promise<void> {
  const db = await openDb()
  if (db !== null) {
    try {
      // 構造化複製できない値（Vue の Proxy など）を渡さないよう JSON で写す
      await run(db, 'readwrite', (s) => s.put(JSON.parse(JSON.stringify(value)) as unknown, key))
      return
    } catch {
      // localStorage に書く
    }
  }
  localStorage.setItem(LS_PREFIX + key, JSON.stringify(value)) // 失敗（容量超過など）は例外のまま返す
}

export async function kvDelete(key: string): Promise<void> {
  const db = await openDb()
  if (db !== null) {
    try {
      await run(db, 'readwrite', (s) => s.delete(key))
    } catch {
      // localStorage の分は消す
    }
  }
  try {
    localStorage.removeItem(LS_PREFIX + key)
  } catch {
    // 使えない
  }
}

/** prefix で始まるキーの値をすべて返す（両方にあれば IndexedDB の値） */
export async function kvList(prefix: string): Promise<Map<string, unknown>> {
  const result = new Map<string, unknown>()
  try {
    for (let i = 0; i < localStorage.length; i++) {
      const full = localStorage.key(i)
      if (full === null || !full.startsWith(LS_PREFIX + prefix)) continue
      const key = full.slice(LS_PREFIX.length)
      const value = lsGet(key)
      if (value !== undefined) result.set(key, value)
    }
  } catch {
    // 使えない
  }
  const db = await openDb()
  if (db !== null) {
    try {
      const range = IDBKeyRange.bound(prefix, `${prefix}￿`)
      const keys = await run(db, 'readonly', (s) => s.getAllKeys(range))
      const values = await run(db, 'readonly', (s) => s.getAll(range))
      keys.forEach((k, i) => {
        if (typeof k === 'string') result.set(k, values[i] as unknown)
      })
    } catch {
      // localStorage の分だけ
    }
  }
  return result
}

/** 14 §7.1：ブラウザに保存した値を、容量が足りなくなっても消さないよう求める（認められるかはブラウザが決める） */
export async function requestPersistentStorage(): Promise<boolean> {
  try {
    if (typeof navigator === 'undefined' || !navigator.storage?.persist) return false
    if (await navigator.storage.persisted()) return true
    return await navigator.storage.persist()
  } catch {
    return false
  }
}

/** テスト用：開いた DB を忘れる */
export function resetOfflineDbForTest(): void {
  dbPromise = null
}
