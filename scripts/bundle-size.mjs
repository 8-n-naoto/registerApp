// 初回に読み込む JS・CSS の gzip 後の合計を出す（02 §9.1・08 §10：250KB 以内）。
// `npm run build` の後に `npm run size` で実行する。入口（isEntry）とその静的 import を辿り、動的 import は含めない。
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { gzipSync } from 'node:zlib'

const LIMIT_KB = 250
const buildDir = join(process.cwd(), 'public', 'build')

let manifest
try {
  manifest = JSON.parse(readFileSync(join(buildDir, '.vite', 'manifest.json'), 'utf8'))
} catch {
  try {
    manifest = JSON.parse(readFileSync(join(buildDir, 'manifest.json'), 'utf8'))
  } catch {
    console.error('public/build/manifest.json がありません。先に npm run build を実行してください。')
    process.exit(1)
  }
}

const files = new Set()
const seen = new Set()

function visit(key) {
  if (seen.has(key)) return
  seen.add(key)
  const chunk = manifest[key]
  if (!chunk) return
  files.add(chunk.file)
  for (const css of chunk.css ?? []) files.add(css)
  for (const dep of chunk.imports ?? []) visit(dep)
}

for (const [key, chunk] of Object.entries(manifest)) {
  if (chunk.isEntry) visit(key)
}

let total = 0
const rows = [...files].sort().map((file) => {
  const size = gzipSync(readFileSync(join(buildDir, file)), { level: 9 }).length
  total += size
  return { file, kb: (size / 1024).toFixed(2) }
})

for (const row of rows) console.log(`${row.kb.padStart(8)} KB  ${row.file}`)
const totalKb = total / 1024
console.log(`${totalKb.toFixed(2).padStart(8)} KB  初回の合計（gzip 後。上限 ${LIMIT_KB}KB）`)

if (totalKb > LIMIT_KB) {
  console.error(`初回の読み込み量が ${LIMIT_KB}KB を超えています。`)
  process.exit(1)
}
