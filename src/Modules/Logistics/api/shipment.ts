import { http, extractListResult, tradeApiPrefix } from '@/shared/http'

// ========== 类型定义 ==========

export interface Shipment {
  shipment_id: number
  order_id: number
  order_no: string
  user_id: number | null
  carrier: string | null
  tracking_no: string | null
  status: 'pending' | 'shipped' | 'delivered' | 'cancelled'
  receiver_name: string | null
  receiver_phone: string | null
  receiver_address: string | null
  items?: Record<string, unknown>[] | null
  remark: string | null
  shipped_at: string | null
  delivered_at: string | null
  created_at: string
}

export interface ShipmentListParams {
  page: number
  pageSize: number
  status?: string
  order_no?: string
  tracking_no?: string
}

// ========== 发货单 ==========

export async function getShipmentList(params: ShipmentListParams): Promise<{ data: Shipment[]; total: number }> {
  const query: Record<string, unknown> = {
    page: params.page,
    per_page: params.pageSize,
  }
  if (params.status) query.status = params.status
  if (params.order_no) query.order_no = params.order_no
  if (params.tracking_no) query.tracking_no = params.tracking_no
  const res = await http.get<Shipment[]>(`${tradeApiPrefix()}/shipments`, { params: query })
  return extractListResult(res)
}

export async function createShipment(data: {
  order_no: string
  carrier?: string
  tracking_no?: string
  receiver_name?: string
  receiver_phone?: string
  receiver_address?: string
  remark?: string
}): Promise<Shipment> {
  const res = await http.post<Shipment>(`${tradeApiPrefix()}/shipments`, data)
  return res.data
}

export async function shipShipment(shipmentId: number, data: { carrier?: string; tracking_no: string }): Promise<Shipment> {
  const res = await http.post<Shipment>(`${tradeApiPrefix()}/shipments/${shipmentId}/ship`, data)
  return res.data
}

export async function deliverShipment(shipmentId: number): Promise<Shipment> {
  const res = await http.post<Shipment>(`${tradeApiPrefix()}/shipments/${shipmentId}/deliver`)
  return res.data
}

export async function cancelShipment(shipmentId: number): Promise<Shipment> {
  const res = await http.post<Shipment>(`${tradeApiPrefix()}/shipments/${shipmentId}/cancel`)
  return res.data
}

export async function getShipmentsByOrder(orderNo: string): Promise<Shipment[]> {
  const res = await http.get<Shipment[]>(`${tradeApiPrefix()}/shipments/by-order/${orderNo}`)
  return Array.isArray(res.data) ? res.data : []
}
