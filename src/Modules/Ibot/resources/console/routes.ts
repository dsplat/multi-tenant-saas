const routes = [
  {
    path: 'ibot-settings',
    name: 'ibot-console-settings',
    component: () => import('./ui/element-plus/views/IbotSettings.vue'),
    meta: { title: '随身助理', requiresAuth: true, module: 'ibot' },
  },
]

export default routes
