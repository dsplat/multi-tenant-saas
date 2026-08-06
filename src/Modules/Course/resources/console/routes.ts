import type { RouteRecordRaw } from 'vue-router'
import { view } from '@/module-loader'

const routes: RouteRecordRaw[] = [
  // 课程管理
  { path: 'courses', name: 'CourseManagement', component: view('course', 'CourseManagement'), meta: { title: '课程管理' } },
]

export default routes
