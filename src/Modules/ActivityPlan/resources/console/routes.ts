const routes = [
  {
    path: 'activity/calendar',
    name: 'activity-calendar',
    component: () => import('./ui/element-plus/views/ActivityCalendar.vue'),
    meta: { title: '活动日历', requiresAuth: true, module: 'activity_plan' },
  },
  {
    path: 'activity/plans',
    name: 'activity-plans',
    component: () => import('./ui/element-plus/views/ActivityPlans.vue'),
    meta: { title: '活动计划', requiresAuth: true, module: 'activity_plan' },
  },
]

export default routes
