/**
 * AiStreaming Node 引擎构建脚本
 *
 * esbuild 将 src/server.ts 及全部依赖打包为 ../dist/server.mjs 单文件，
 * 用户零 node_modules 依赖，`node dist/server.mjs` 即可运行。
 *
 * 由 CI（split.yml）在发布模块包时执行；本地开发：
 *   cd node && npm install && node build.mjs
 */

import { build } from 'esbuild'
import { statSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const outfile = resolve(here, '../dist/server.mjs')

await build({
  entryPoints: [resolve(here, 'src/server.ts')],
  outfile,
  bundle: true,
  platform: 'node',
  target: 'node20',
  format: 'esm',
  minify: true,
  sourcemap: false,
  // esm bundle 中部分 CJS 依赖需要 require shim
  banner: {
    js: "import { createRequire } from 'node:module'; const require = createRequire(import.meta.url);",
  },
  logLevel: 'info',
})

const sizeMb = (statSync(outfile).size / 1048576).toFixed(2)
console.log(`[ai-streaming] bundle ready: ${outfile} (${sizeMb} MB)`)
