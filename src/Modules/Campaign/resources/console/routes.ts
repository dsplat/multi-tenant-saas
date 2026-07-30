const routes = [
  {
    path: 'campaign/calendar',
    name: 'campaign-calendar',
    component: () => import('./ui/element-plus/views/CampaignCalendar.vue'),
    meta: { title: '活动日历', requiresAuth: true, module: 'campaign' },
  },
  {
    path: 'campaign/plans',
    name: 'campaign-plans',
    component: () => import('./ui/element-plus/views/CampaignPlans.vue'),
    meta: { title: '活动计划', requiresAuth: true, module: 'campaign' },
  },
]

export default routes
