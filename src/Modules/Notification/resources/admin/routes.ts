const routes = [
  {
    path: 'mail-templates',
    name: 'notification-mail-templates',
    component: () => import('./ui/element-plus/views/MailTemplateList.vue'),
    meta: { title: 'Mail Templates', requiresAuth: true, module: 'notification' },
  },
  {
    path: 'mail-templates/:id',
    name: 'notification-mail-template-editor',
    component: () => import('./ui/element-plus/views/MailTemplateEditor.vue'),
    meta: { title: 'Mail Template Editor', requiresAuth: true, module: 'notification' },
  },
]

export default routes
