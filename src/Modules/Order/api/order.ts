import { http, extractListResult, tradeApiPrefix } from '@/shared/http'

// ========== 类型定义 ==========

export interface OrderItem {
  item_id: number
  order_id: number
  sku_id: number | null
  product_id: number | null
  item_type: string | null
  ref_id: number | null
  item_name: string
  spec: string | null
  quantity: number
  unit_price: string | number
  points_unit_price: number
  amount: string | number
}

export interface Order {
  order_id: number
  order_no: string
  user_id: number | null
  order_type: 'registration' | 'product' | 'course' | 'exchange'
  total_amount: string | number
  points_amount: number
  pay_method: 'cash' | 'points' | 'mixed'
  status: 'pending' | 'paid' | 'refunded' | 'cancelled'
  paid_at: string | null
  refunded_at?: string | null
  source?: Record<string, unknown> | null
  metadata?: Record<string, unknown> | null
  created_at: string
  items?: OrderItem[]
}

export interface OrderListParams {
  page: number
  pageSize: number
  order_type?: string
  status?: string
  user_id?: number
}

export interface OrderListResult {
  data: Order[]
  total: number
}

// ========== 订单 ==========

export async function getOrderList(params: OrderListParams): Promise<OrderListResult> {
  const query: Record<string, unknown> = {
    page: params.page,
    per_page: params.pageSize,
  }
  if (params.order_type) query.order_type = params.order_type
  if (params.status) query.status = params.status
  if (params.user_id) query.user_id = params.user_id
  const res = await http.get<Order[]>(`${tradeApiPrefix()}/orders`, { params: query })
  return extractListResult(res)
}

export async function getOrderDetail(orderNo: string): Promise<Order> {
  const res = await http.get<Order>(`${tradeApiPrefix()}/orders/${orderNo}`)
  return res.data
}

export async function refundOrder(orderNo: string, reason?: string): Promise<Order> {
  const res = await http.post<Order>(`${tradeApiPrefix()}/orders/${orderNo}/refund`, { reason })
  return res.data
}
