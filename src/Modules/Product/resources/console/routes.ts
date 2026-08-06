import type { RouteRecordRaw } from 'vue-router'
import { view } from '@/module-loader'

const routes: RouteRecordRaw[] = [
  // 商品管理
  { path: 'products', name: 'ProductManagement', component: view('product', 'ProductManagement'), meta: { title: '商品管理' } },
]

export default routes
