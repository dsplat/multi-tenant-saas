import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import App from '../../pages/public/App.vue'
import UserLayout from '../../pages/public/layouts/UserLayout.vue'
import Home from '../../pages/public/views/index.vue'
import Login from '../../pages/public/views/Login.vue'
import Register from '../../pages/public/views/Register.vue'
import VerifyEmail from '../../pages/public/views/VerifyEmail.vue'
import ForgotPassword from '../../pages/public/views/ForgotPassword.vue'
import ResetPassword from '../../pages/public/views/ResetPassword.vue'
import Apply from '../../pages/public/views/Apply.vue'
import ApplyStatus from '../../pages/public/views/ApplyStatus.vue'
import Onboarding from '../../pages/public/views/Onboarding.vue'
import AcceptInvite from '../../pages/public/views/AcceptInvite.vue'
import OAuthCallback from '../../pages/public/views/OAuthCallback.vue'
import MfaVerify from '../../pages/public/views/MfaVerify.vue'
import UserDashboard from '../../pages/public/views/user/Dashboard.vue'
import UserProfile from '../../pages/public/views/user/Profile.vue'
import UserSecurity from '../../pages/public/views/user/Security.vue'
import UserOAuthBindings from '../../pages/public/views/user/OAuthBindings.vue'
import NotificationIndex from '../../pages/public/views/notifications/Index.vue'
import NotificationPreferences from '../../pages/public/views/notifications/Preferences.vue'

const router = createRouter({
  history: createWebHistory('/'),
  routes: [
    // 公开页面
    { path: '/', name: 'home', component: Home },
    { path: '/login', name: 'login', component: Login },
    { path: '/register', name: 'register', component: Register },
    { path: '/verify-email', name: 'verify-email', component: VerifyEmail },
    { path: '/forgot-password', name: 'forgot-password', component: ForgotPassword },
    { path: '/reset-password', name: 'reset-password', component: ResetPassword },
    { path: '/apply', name: 'apply', component: Apply },
    { path: '/apply/status/:code', name: 'apply-status', component: ApplyStatus, props: true },
    { path: '/onboarding', name: 'onboarding', component: Onboarding },
    { path: '/accept-invite', name: 'accept-invite', component: AcceptInvite },
    { path: '/oauth/:provider/callback', name: 'oauth-callback', component: OAuthCallback },
    { path: '/mfa/verify', name: 'mfa-verify', component: MfaVerify },

    // 用户中心（需认证）
    {
      path: '/',
      component: UserLayout,
      meta: { requiresAuth: true },
      children: [
        { path: 'dashboard', name: 'dashboard', component: UserDashboard },
        { path: 'profile', name: 'profile', component: UserProfile },
        { path: 'security', name: 'security', component: UserSecurity },
        { path: 'oauth-bindings', name: 'oauth-bindings', component: UserOAuthBindings },
        { path: 'notifications', name: 'notifications', component: NotificationIndex },
        { path: 'notifications/preferences', name: 'notification-preferences', component: NotificationPreferences },
      ],
    },
  ],
})

// 认证守卫
router.beforeEach((to) => {
  if (to.meta.requiresAuth || to.matched.some(r => r.meta.requiresAuth)) {
    const token = localStorage.getItem('user_token')
    if (!token) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }
})

const app = createApp(App)

// Register Element Plus icons globally
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component as any)
}

app.use(ElementPlus, { locale: zhCn })
app.use(router)
app.mount('#app')
