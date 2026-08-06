import type { RouteRecordRaw } from 'vue-router'
import { view } from '@/module-loader'

const routes: RouteRecordRaw[] = [
  // 销售折现配置
  { path: 'sales-config', name: 'SalesConfig', component: view('pay', 'SalesConfig'), meta: { title: '销售折现配置' } },
]

export default routes
