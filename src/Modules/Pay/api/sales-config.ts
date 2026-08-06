import { http, tradeApiPrefix } from '@/shared/http'

// ========== 类型定义 ==========

export interface SalesConfig {
  mixed_pay_enabled: boolean
  points_to_cash_ratio: number
  max_points_deduct_ratio: number
}

// ========== 销售折现配置（租户级） ==========

export async function getSalesConfig(): Promise<SalesConfig> {
  const res = await http.get<SalesConfig>(`${tradeApiPrefix()}/sales-config`)
  return res.data
}

export async function updateSalesConfig(data: Partial<SalesConfig>): Promise<SalesConfig> {
  const res = await http.put<SalesConfig>(`${tradeApiPrefix()}/sales-config`, data)
  return res.data
}
