/**
 * 工具 slug → 人性化操作名的映射（运营人员看得懂的动作描述）
 *
 * 用于工具卡片（ChatMessage）与 L2 确认卡片（ActionConfirmCard）的标题展示，
 * 不暴露 slug / 英文注册名等系统内部标识。新增写工具时在此补词条。
 */
const TOOL_LABELS: Record<string, string> = {
  // ── 查询/导航类 ──
  system_kb_search: '搜索知识库',
  get_data_dictionary: '查阅数据字典',
  navigate: '导航页面',
  list_agents: '查看可用的数字员工',
  enable_agent: '启用数字员工',
  delegate_to_agent: '转接数字员工',
  ask_user_choice: '给出选项供你选择',
  fetch_site_metadata: '提取官网品牌要素',
  search_customer: '查询客户',
  get_customer_tags: '查看客户标签',
  list_coupon_templates: '查看优惠券模板',
  list_poster_templates: '查看海报模板',
  get_points_balance: '查询积分余额',
  list_sms_signatures: '查看短信签名',
  sms_list_templates: '查看短信模板',
  list_moments_sop: '查看朋友圈 SOP',
  list_mass_push: '查看群发任务',
  product_list: '查看商品列表',
  coupon_list: '查看优惠券列表',
  campaign_status: '查询活动进度',
  list_task_chains: '查看任务链目录',
  thread_review: '回顾工作脉络',
  thread_track: '建立脉络跟踪',
  thread_untrack: '取消脉络跟踪',

  // ── 策划/任务链类 ──
  campaign_plan_draft: '起草活动方案',
  campaign_plan_commit: '定稿活动计划',
  start_task_chain: '启动任务链',
  advance_task_chain: '推进任务链',

  // ── 写操作类（L2 需确认）──
  suggest_form_fill: '生成表单填充建议',
  suggest_kb_update: '提交知识库更新提案',
  update_tenant_branding: '更新品牌信息',
  update_tenant_settings: '更新租户设置',
  update_tenant_domain: '绑定自定义域名',
  tag_customer: '为客户打标签',
  create_script_draft: '创建话术草稿',
  save_oauth_config: '保存授权配置',
  create_distribution_plan: '创建分销计划',
  manage_tags: '管理标签',
  ai_auto_tag: 'AI 自动打标签',
  create_live_code: '创建活码',
  send_message: '发送消息',
  create_product: '创建商品',
  issue_coupon: '发放优惠券',
  create_sms_signature: '创建短信签名',
  send_sms_batch: '群发短信',
  schedule_sms_batch: '预约群发短信',
  create_poster: '创建海报',
  adjust_points: '调整积分',
  create_moments_sop: '创建朋友圈 SOP',
  create_mass_push: '创建群发任务',

  // ── 下游 SCRM 工具 ──
  plan_campaign: '策划营销方案',
  generate_image_prompt: '生成海报创意提示词',
  generate_video_script: '生成视频脚本',
  analyze_trend: '分析趋势',
  get_event_info: '查看活动详情',
  create_event: '创建活动',
  create_campaign: '创建营销活动',
}

/**
 * 解析工具的人性化展示名
 *
 * @param slug 工具标识（tool_slug / function name）
 * @param fallback 未登记词条时的兜底名（缺省回退为「执行操作」）
 */
export function toolLabel(slug: string, fallback?: string): string {
  return TOOL_LABELS[slug] || fallback || '执行操作'
}

// ─────────────────────── 确认卡片参数去技术化 ───────────────────────

/** 参数键 → 中文词条（确认卡片上用户看得懂的字段名） */
const FIELD_LABELS: Record<string, string> = {
  title: '标题',
  name: '名称',
  subject: '主题',
  summary: '摘要',
  description: '描述',
  content: '内容',
  message: '消息内容',
  goal: '活动目标',
  objective: '活动目标',
  budget: '预算',
  amount: '金额',
  points: '积分',
  price: '价格',
  quantity: '数量',
  count: '数量',
  total: '总额',
  start_date: '开始日期',
  end_date: '结束日期',
  start_time: '开始时间',
  end_time: '结束时间',
  date: '日期',
  time: '时间',
  deadline: '截止时间',
  scheduled_at: '计划时间',
  send_at: '发送时间',
  duration: '时长',
  target_audience: '目标人群',
  audience: '目标人群',
  customer_name: '客户姓名',
  phone: '手机号',
  remark: '备注',
  reason: '原因',
  channel: '渠道',
  template: '模板',
  template_id: '短信模板',
  signature: '短信签名',
  tags: '标签',
  tag_names: '标签',
  domain: '域名',
  url: '链接',
  image_url: '图片链接',
  options: '选项',
  choices: '选项',
  handoff_message: '转派说明',
  agent_role: '员工角色',
  module: '模块',
  page: '页面',
  plan: '方案内容',
  plan_doc: '方案内容',
}

/** 纯内部键：对用户无意义，确认卡片上一律隐藏 */
const HIDDEN_FIELDS = new Set<string>([
  // 内部标识/关联键（chain_key 单独处理：命中链名映射时展示可读链名，否则隐藏）
  'task_chain_id', 'run_id', 'step_key', 'plan_id',
  'conversation_id', 'agent_id', 'tenant_id', 'message_id',
  'user_id', 'operator_id', 'created_by', 'updated_by',
  // 安全/协议键
  'token', 'confirm_token', 'args_hash', 'nonce',
  // 其他技术键
  'metadata', 'schema', 'version', 'trace_id', 'idempotency_key',
])

/** 任务链 key → 可读链名（未收录的 chain_key 整行隐藏） */
const CHAIN_LABELS: Record<string, string> = {
  launch_marketing_campaign: '营销活动上线',
  demo_poster_flow: '海报生成流程',
}

/** 单行超长截断阈值（确认卡片只看要点） */
const MAX_FIELD_VALUE_LENGTH = 120

/**
 * 将工具原始参数转为确认卡片友好的展示行
 *
 * 规则：隐藏纯内部键；参数键翻译为中文；chain_key 命中映射表时
 * 以「任务链：可读链名」展示，未命中则隐藏。
 */
export function friendlyFieldEntries(
  args: Record<string, unknown> | null | undefined,
): Array<{ key: string; value: string }> {
  const entries: Array<{ key: string; value: string }> = []

  for (const [key, value] of Object.entries(args || {})) {
    if (HIDDEN_FIELDS.has(key)) continue

    if (key === 'chain_key') {
      const label = CHAIN_LABELS[String(value)]
      if (label) entries.push({ key: '任务链', value: label })
      continue
    }

    let text = typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '')
    if (text.length > MAX_FIELD_VALUE_LENGTH) text = text.slice(0, MAX_FIELD_VALUE_LENGTH) + '…'

    entries.push({ key: FIELD_LABELS[key] || key, value: text })
  }

  return entries
}
