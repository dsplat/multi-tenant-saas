<template>
  <div class="admin-layout">
    <aside class="a-sidebar">
      <div class="a-logo">
        <div class="a-logo-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        </div>
        <div class="a-logo-text"><span class="a-logo-title">SaaS Admin</span><span class="a-logo-sub">管理后台</span></div>
      </div>

      <nav class="a-nav">
        <div class="a-nav-section">
          <div class="a-nav-label">系统管理</div>
          <a href="/admin/dashboard" :class="['a-nav-item', { active: isActive('/dashboard') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
            <span>仪表盘</span>
          </a>
          <a href="/admin/tenants" :class="['a-nav-item', { active: isActive('/tenants') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z"/></svg>
            <span>租户管理</span>
          </a>
          <a href="/admin/operators" :class="['a-nav-item', { active: isActive('/operators') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/></svg>
            <span>运营人员</span>
          </a>
          <a href="/admin/roles" :class="['a-nav-item', { active: isActive('/roles') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <span>角色权限</span>
          </a>
          <a href="/admin/plans" :class="['a-nav-item', { active: isActive('/plans') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
            <span>订阅计划</span>
          </a>
        </div>

        <div class="a-nav-section">
          <div class="a-nav-label">平台配置</div>
          <a href="/admin/modules" :class="['a-nav-item', { active: isActive('/modules') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z"/></svg>
            <span>模块管理</span>
          </a>
          <a href="/admin/plugins" :class="['a-nav-item', { active: isActive('/plugins') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            <span>插件管理</span>
          </a>
          <a href="/admin/feature-flags" :class="['a-nav-item', { active: isActive('/feature-flags') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 6a3 3 0 013-3h10a1 1 0 01.8 1.6L14.25 8l2.55 3.4A1 1 0 0116 13H6a1 1 0 00-1 1v3a1 1 0 11-2 0V6z" clip-rule="evenodd"/></svg>
            <span>功能开关</span>
          </a>
          <a href="/admin/branding" :class="['a-nav-item', { active: isActive('/branding') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a2 2 0 00-2 2v11a3 3 0 106 0V4a2 2 0 00-2-2H4zm1 14a1 1 0 100-2 1 1 0 000 2zm5-1.757l4.9-4.9a2 2 0 000-2.828L13.485 5.1a2 2 0 00-2.828 0L10 5.757v8.486zM16 18H9.071l6-6H16a2 2 0 012 2v2a2 2 0 01-2 2z" clip-rule="evenodd"/></svg>
            <span>品牌配置</span>
          </a>
          <a href="/admin/sso-providers" :class="['a-nav-item', { active: isActive('/sso-providers') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>
            <span>SSO 提供商</span>
          </a>
          <a href="/admin/system-settings" :class="['a-nav-item', { active: isActive('/system-settings') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
            <span>系统设置</span>
          </a>
          <a href="/admin/retention-policies" :class="['a-nav-item', { active: isActive('/retention-policies') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
            <span>数据保留</span>
          </a>
          <a href="/admin/sandbox" :class="['a-nav-item', { active: isActive('/sandbox') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.316 3.051a1 1 0 01.633 1.265l-4 12a1 1 0 11-1.898-.632l4-12a1 1 0 011.265-.633zM5.707 6.293a1 1 0 010 1.414L3.414 10l2.293 2.293a1 1 0 11-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0zm8.586 0a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 11-1.414-1.414L16.586 10l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            <span>沙箱环境</span>
          </a>
          <a href="/admin/settings" :class="['a-nav-item', { active: isActive('/settings') }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M5 4a1 1 0 00-2 0v7.268a2 2 0 000 3.464V16a1 1 0 102 0v-1.268a2 2 0 000-3.464V4zM11 4a1 1 0 10-2 0v1.268a2 2 0 000 3.464V16a1 1 0 102 0V8.732a2 2 0 000-3.464V4zM16 3a1 1 0 011 1v7.268a2 2 0 010 3.464V16a1 1 0 11-2 0v-1.268a2 2 0 010-3.464V4a1 1 0 011-1z"/></svg>
            <span>配置中心</span>
          </a>
        </div>

        <div class="a-nav-section" :class="{ disabled: !tenantStore.hasTenant }">
          <div class="a-nav-label">
            租户管理
            <span v-if="!tenantStore.hasTenant" class="a-section-hint">请先选择租户</span>
          </div>
          <a href="/admin/users" :class="['a-nav-item', { active: isActive('/users'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
            <span>用户管理</span>
          </a>
          <a href="/admin/domains" :class="['a-nav-item', { active: isActive('/domains'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.497-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z"/></svg>
            <span>域名管理</span>
          </a>
          <a href="/admin/oauth" :class="['a-nav-item', { active: isActive('/oauth'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <span>第三方登录</span>
          </a>
          <a href="/admin/audit-logs" :class="['a-nav-item', { active: isActive('/audit-logs'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>
            <span>审计日志</span>
          </a>
          <a href="/admin/sms" :class="['a-nav-item', { active: isActive('/sms'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2 5a2 2 0 012-2h7a2 2 0 012 2v4a2 2 0 01-2 2H9l-3 3v-3H4a2 2 0 01-2-2V5z"/><path d="M15 7v2a4 4 0 01-4 4H9.828l-1.766 1.767c.28.149.599.233.938.233h2l3 3v-3h2a2 2 0 002-2V9a2 2 0 00-2-2h-1z"/></svg>
            <span>短信配置</span>
          </a>
          <a href="/admin/payments" :class="['a-nav-item', { active: isActive('/payments'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"/><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"/></svg>
            <span>支付订单</span>
          </a>
          <a href="/admin/api-tokens" :class="['a-nav-item', { active: isActive('/api-tokens'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L10 14l-1 1-1 1H6v2H2v-4l4.257-4.257A6 6 0 1118 8zm-6-4a1 1 0 100 2 2 2 0 012 2 1 1 0 102 0 4 4 0 00-4-4z" clip-rule="evenodd"/></svg>
            <span>API Token</span>
          </a>
          <a href="/admin/quotas" :class="['a-nav-item', { active: isActive('/quotas'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/></svg>
            <span>配额管理</span>
          </a>
          <a href="/admin/credits" :class="['a-nav-item', { active: isActive('/credits'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/></svg>
            <span>积分总览</span>
          </a>
          <a href="/admin/ssl" :class="['a-nav-item', { active: isActive('/ssl'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <span>SSL 证书</span>
          </a>
          <a href="/admin/webhooks" :class="['a-nav-item', { active: isActive('/webhooks'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/></svg>
            <span>Webhooks</span>
          </a>
          <a href="/admin/ip-whitelist" :class="['a-nav-item', { active: isActive('/ip-whitelist'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <span>IP 白名单</span>
          </a>
          <a href="/admin/tenant-keys" :class="['a-nav-item', { active: isActive('/tenant-keys'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L10 14l-1 1-1 1H6v2H2v-4l4.257-4.257A6 6 0 1118 8zm-6-4a1 1 0 100 2 2 2 0 012 2 1 1 0 102 0 4 4 0 00-4-4z" clip-rule="evenodd"/></svg>
            <span>租户密钥</span>
          </a>
          <a href="/admin/consents" :class="['a-nav-item', { active: isActive('/consents'), disabled: !tenantStore.hasTenant }]">
            <svg class="a-nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>
            <span>合规同意</span>
          </a>
        </div>
      </nav>

      <div class="a-footer">
        <div class="a-user">
          <div class="a-avatar">{{ (userStore.user?.name || 'A')[0] }}</div>
          <div class="a-user-info">
            <div class="a-user-name">{{ userStore.user?.name || '管理员' }}</div>
            <div class="a-user-role">{{ userStore.user?.role === 'super_admin' ? '超级管理员' : '管理员' }}</div>
          </div>
        </div>
      </div>
    </aside>

    <main class="a-main">
      <header class="a-topbar">
        <div class="a-breadcrumb">
          <svg class="a-breadcrumb-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
          <span>首页</span>
          <span v-if="route.meta.title" class="a-breadcrumb-sep">/</span>
          <span v-if="route.meta.title" class="a-breadcrumb-current">{{ route.meta.title }}</span>
        </div>
        <div class="a-actions">
          <div class="a-tenant-sel">
            <svg class="a-tenant-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z"/></svg>
            <select v-model="selectedTenantId" @change="onTenantChange" class="a-tenant-select">
              <option value="">选择租户...</option>
              <option v-for="t in tenantStore.tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }}</option>
            </select>
          </div>
          <ThemeSwitcher />
          <ColorPicker />
          <button class="a-icon-btn" @click="showThemeSettings = true" title="主题设置">
            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
          </button>
          <button class="a-logout" @click="handleLogout">
            <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
            <span>退出</span>
          </button>
        </div>
      </header>

      <section class="a-content">
        <router-view />
      </section>
    </main>
  </div>

  <ThemeSettings v-model:visible="showThemeSettings" />
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import { useTenantStore } from '../stores/tenant'
import ThemeSwitcher from '@multi-tenant-saas/ui-core/components/ThemeSwitcher.vue'
import ColorPicker from '@multi-tenant-saas/ui-core/components/ColorPicker.vue'
import ThemeSettings from '@multi-tenant-saas/ui-core/components/ThemeSettings.vue'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const tenantStore = useTenantStore()
const showThemeSettings = ref(false)
const selectedTenantId = ref('')

const isActive = (path: string) => route.path.startsWith('/admin' + path)

const onTenantChange = () => {
  const tenant = tenantStore.tenants.find(t => t.tenant_id === selectedTenantId.value)
  tenantStore.selectTenant(tenant || null)
}

onMounted(async () => {
  await tenantStore.fetchTenants()
  await tenantStore.restoreSelection()
  selectedTenantId.value = tenantStore.tenantId
})

watch(() => tenantStore.tenantId, (id) => { selectedTenantId.value = id })

const handleLogout = async () => {
  await userStore.logout()
  router.push('/admin/login')
}
</script>

<style>
/* ===== THEME: light (default) — global :root ===== */
:root { --ac: var(--primary-color, #6366f1); --ac-r: 99,102,241; --sb: #ffffff; --sb-h: #f1f5f9; --sb-t: #64748b; --sb-ta: #0f172a; --sb-l: #94a3b8; --sb-b: #e2e8f0; --tb: #ffffff; --tb-b: #e2e8f0; --pg: #f1f5f9; --tx: #0f172a; --tx2: #64748b; --bg-color: #ffffff; --bg-color-page: #f1f5f9; --border-color: #e2e8f0; --fill-color: #f8fafc; --text-color-primary: #0f172a; --text-color-secondary: #64748b; }

/* ===== THEME: dark — global override ===== */
/* Badge variables */
:root { --badge-success-bg: #e6ffed; --badge-success-fg: #52c41a; --badge-danger-bg: #fff1f0; --badge-danger-fg: #f5222d; --badge-warning-bg: #fff7e6; --badge-warning-fg: #fa8c16; --badge-info-bg: #f0f0f0; --badge-info-fg: #666666; --link-color: var(--primary-color, #6366f1); --link-danger: #f5222d; --table-header-fg: #999999; }
html.dark { --badge-success-bg: #0d3320; --badge-success-fg: #73e2a3; --badge-danger-bg: #3b1219; --badge-danger-fg: #fca5a5; --badge-warning-bg: #3b2e08; --badge-warning-fg: #fcd34d; --badge-info-bg: #1e293b; --badge-info-fg: #94a3b8; --link-color: #818cf8; --link-danger: #fca5a5; --table-header-fg: #94a3b8; }

/* Global element overrides for dark mode */
html.dark h1, html.dark h2, html.dark h3, html.dark h4 { color: var(--text-color-primary, #f1f5f9); }
html.dark input, html.dark select, html.dark textarea { background: var(--bg-color, #1e293b); color: var(--text-color-primary, #f1f5f9); border-color: var(--border-color, #334155); }
html.dark select option { background: #1e293b; color: #f1f5f9; }
html.dark .page-header { background: var(--bg-color, #1e293b); padding: 16px 20px; border-radius: 8px; margin-bottom: 20px; }

html.dark { --sb: #1e293b; --sb-h: #334155; --sb-t: #94a3b8; --sb-ta: #f1f5f9; --sb-l: #64748b; --sb-b: #334155; --tb: #1e293b; --tb-b: #334155; --pg: #0f172a; --tx: #f1f5f9; --tx2: #94a3b8; --bg-color: #1e293b; --bg-color-page: #0f172a; --border-color: #334155; --fill-color: #334155; --text-color-primary: #f1f5f9; --text-color-secondary: #94a3b8; }

/* ===== LAYOUT ===== */
.admin-layout { display: flex; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }

/* ===== SIDEBAR ===== */
.admin-layout .a-sidebar { width: 260px; background: var(--sb); display: flex; flex-direction: column; flex-shrink: 0; border-right: 1px solid var(--sb-b); }
.admin-layout .a-logo { height: 64px; display: flex; align-items: center; gap: 12px; padding: 0 20px; border-bottom: 1px solid var(--sb-b); }
.admin-layout .a-logo-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--ac), color-mix(in srgb, var(--ac) 70%, #fff)); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.admin-layout .a-logo-text { display: flex; flex-direction: column; }
.admin-layout .a-logo-title { font-size: 15px; font-weight: 700; color: var(--sb-ta); letter-spacing: -0.02em; }
.admin-layout .a-logo-sub { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.admin-layout .a-nav { flex: 1; overflow-y: auto; padding: 8px 0; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent; }
.admin-layout .a-nav-section { margin-bottom: 4px; }
.admin-layout .a-nav-section.disabled { opacity: 0.4; pointer-events: none; }
.admin-layout .a-nav-label { padding: 16px 20px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--sb-l); display: flex; align-items: center; gap: 8px; }
.admin-layout .a-section-hint { font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; color: #f59e0b; }

.admin-layout .a-nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 20px; margin: 1px 8px; border-radius: 6px; color: var(--sb-t); text-decoration: none; font-size: 13.5px; font-weight: 450; transition: all 0.15s ease; position: relative; }
.admin-layout .a-nav-item:hover { background: var(--sb-h); color: var(--sb-ta); }
.admin-layout .a-nav-item.active { background: rgba(var(--ac-r),0.15); color: var(--sb-ta); font-weight: 500; }
.admin-layout .a-nav-item.active::before { content: ''; position: absolute; left: 0; top: 6px; bottom: 6px; width: 3px; border-radius: 0 3px 3px 0; background: var(--ac); }
.admin-layout .a-nav-item.disabled { opacity: 0.35; cursor: not-allowed; }
.admin-layout .a-nav-icon { width: 18px; height: 18px; flex-shrink: 0; opacity: 0.7; }
.admin-layout .a-nav-item.active .a-nav-icon { opacity: 1; }

.admin-layout .a-footer { padding: 12px 16px; border-top: 1px solid var(--sb-b); }
.admin-layout .a-user { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; }
.admin-layout .a-user:hover { background: var(--sb-h); }
.admin-layout .a-avatar { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--ac), color-mix(in srgb, var(--ac) 70%, #fff)); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0; }
.admin-layout .a-user-info { overflow: hidden; }
.admin-layout .a-user-name { font-size: 13px; font-weight: 500; color: var(--sb-ta); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.admin-layout .a-user-role { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

/* ===== MAIN ===== */
.admin-layout .a-main { flex: 1; display: flex; flex-direction: column; background: var(--pg); min-width: 0; }
.admin-layout .a-topbar { height: 56px; background: var(--tb); border-bottom: 1px solid var(--tb-b); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); z-index: 10; }
.admin-layout .a-breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 14px; color: var(--tx2); }
.admin-layout .a-breadcrumb-icon { width: 16px; height: 16px; opacity: 0.5; }
.admin-layout .a-breadcrumb-sep { color: #cbd5e1; margin: 0 2px; }
.admin-layout .a-breadcrumb-current { color: var(--tx); font-weight: 500; }
.admin-layout .a-actions { display: flex; align-items: center; gap: 8px; }

.admin-layout .a-tenant-sel { display: flex; align-items: center; gap: 6px; padding: 5px 12px; border: 1px solid var(--tb-b); border-radius: 8px; background: rgba(var(--ac-r),0.1); }
.admin-layout .a-tenant-icon { width: 16px; height: 16px; color: var(--ac); flex-shrink: 0; }
.admin-layout .a-tenant-select { border: none; background: transparent; font-size: 13px; font-weight: 500; color: var(--tx); outline: none; cursor: pointer; min-width: 140px; }

.admin-layout .a-icon-btn { width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--tb-b); background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--tx2); transition: all 0.15s; }
.admin-layout .a-icon-btn:hover { background: rgba(var(--ac-r),0.1); border-color: var(--ac); color: var(--ac); }

.admin-layout .a-logout { display: flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid var(--tb-b); border-radius: 8px; background: transparent; cursor: pointer; font-size: 13px; color: var(--tx2); transition: all 0.15s; }
.admin-layout .a-logout:hover { color: #ef4444; border-color: #fca5a5; background: #fef2f2; }

.admin-layout .a-content { flex: 1; padding: 24px; overflow-y: auto; }
</style>
