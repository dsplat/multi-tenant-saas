const routes = [
  {
    path: 'ibot-settings',
    name: 'ibot-console-settings',
    component: () => import('./ui/element-plus/views/IbotSettings.vue'),
    meta: { title: '随身助理渠道', requiresAuth: true, module: 'ibot' },
  },
  {
    path: 'my-ibot-bindings',
    name: 'ibot-my-bindings',
    component: () => import('./ui/element-plus/views/MyIbotBindings.vue'),
    meta: { title: '我的随身助理', requiresAuth: true, module: 'ibot' },
  },
]

export default routes
