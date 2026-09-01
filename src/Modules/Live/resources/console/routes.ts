const routes = [
  {
    path: 'live-rooms',
    name: 'live-console-rooms',
    component: () => import('./ui/element-plus/views/LiveRooms.vue'),
    meta: { title: '直播间管理', requiresAuth: true, module: 'live' },
  },
]

export default routes
