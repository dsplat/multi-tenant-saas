import type { RouteRecordRaw } from 'vue-router'
import { view } from '@/module-loader'

const routes: RouteRecordRaw[] = [
  // 订单中心
  { path: 'orders', name: 'OrderManagement', component: view('order', 'OrderManagement'), meta: { title: '订单中心' } },
]

export default routes
