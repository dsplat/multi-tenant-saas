const routes = [
  {
    path: 'exam-banks',
    name: 'exam-console-banks',
    component: () => import('./ui/element-plus/views/ExamBanks.vue'),
    meta: { title: '题库管理', requiresAuth: true, module: 'exam' },
  },
  {
    path: 'exam-manage',
    name: 'exam-console-manage',
    component: () => import('./ui/element-plus/views/ExamManage.vue'),
    meta: { title: '考试管理', requiresAuth: true, module: 'exam' },
  },
]

export default routes
