import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import App from '../../pages/public/App.vue'
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

const router = createRouter({
  history: createWebHistory('/'),
  routes: [
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
  ],
})

const app = createApp(App)

// Register Element Plus icons globally
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component as any)
}

app.use(ElementPlus, { locale: zhCn })
app.use(router)
app.mount('#app')
