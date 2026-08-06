import type { RouteRecordRaw } from 'vue-router'
import { view } from '@/module-loader'

const routes: RouteRecordRaw[] = [
  // 发货管理
  { path: 'shipments', name: 'ShipmentManagement', component: view('logistics', 'ShipmentManagement'), meta: { title: '发货管理' } },
]

export default routes
