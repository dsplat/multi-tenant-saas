import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { existsSync, copyFileSync, rmSync, unlinkSync } from 'fs'

/**
 * 清理旧的 SPA 构建产物（保留 index.php 等框架文件）
 *
 * 由于 outDir 是 public/（Laravel public 目录），不能使用 emptyOutDir: true，
 * 否则会删除 index.php。此函数只删除 SPA 相关文件：
 *  - public/index.html
 *  - public/assets/
 */
function cleanOldSpaBuild(outDir: string) {
  const indexHtml = resolve(outDir, 'index.html')
  if (existsSync(indexHtml)) unlinkSync(indexHtml)

  const assetsDir = resolve(outDir, 'assets')
  if (existsSync(assetsDir)) rmSync(assetsDir, { recursive: true, force: true })
}

export default defineConfig({
  plugins: [
    vue(),
    {
      name: 'flatten-index-html',
      buildStart() {
        // 构建前清理旧产物
        const outDir = resolve(__dirname, '../../../public')
        cleanOldSpaBuild(outDir)
      },
      closeBundle() {
        // Vite 保留 input 的相对路径，导致输出嵌套到 public/resources/pages/public/index.html
        // 此插件把它扁平化到 public/index.html
        const outDir = resolve(__dirname, '../../../public')
        const nested = resolve(outDir, 'resources/pages/public/index.html')
        const flat = resolve(outDir, 'index.html')
        if (existsSync(nested)) {
          copyFileSync(nested, flat)
          rmSync(resolve(outDir, 'resources'), { recursive: true, force: true })
        }
      },
    },
  ],
  root: resolve(__dirname, '../../..'),
  base: '/',
  build: {
    outDir: resolve(__dirname, '../../../public'),
    // 不能清空 public/，否则会删除 index.php
    emptyOutDir: false,
    rollupOptions: {
      input: resolve(__dirname, '../../pages/public/index.html'),
    },
  },
  resolve: {
    alias: {
      'vue': resolve(__dirname, 'node_modules/vue'),
      'vue-router': resolve(__dirname, 'node_modules/vue-router'),
      'axios': resolve(__dirname, 'node_modules/axios'),
      'element-plus': resolve(__dirname, 'node_modules/element-plus'),
      '@element-plus/icons-vue': resolve(__dirname, 'node_modules/@element-plus/icons-vue'),
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
