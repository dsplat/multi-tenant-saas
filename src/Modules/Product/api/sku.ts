import { http, tradeApiPrefix } from '@/shared/http'

// ========== 类型定义 ==========

export interface ProductSku {
  sku_id: number
  product_id: number
  name: string
  spec_attrs: Record<string, string> | null
  price: string | number
  points_price: number
  stock: number
  sold_count: number
  status: 'active' | 'inactive'
}

export interface SaveSkuData {
  name: string
  spec_attrs?: Record<string, string>
  price: number
  points_price?: number
  stock?: number
  status?: 'active' | 'inactive'
}

// ========== SKU 管理（挂在商品下） ==========

export async function getSkuList(productId: number): Promise<ProductSku[]> {
  const res = await http.get<ProductSku[]>(`${tradeApiPrefix()}/products/${productId}/skus`)
  return Array.isArray(res.data) ? res.data : []
}

export async function createSku(productId: number, data: SaveSkuData): Promise<ProductSku> {
  const res = await http.post<ProductSku>(`${tradeApiPrefix()}/products/${productId}/skus`, data)
  return res.data
}

export async function updateSku(
  productId: number,
  skuId: number,
  data: Partial<SaveSkuData>,
): Promise<ProductSku> {
  const res = await http.put<ProductSku>(`${tradeApiPrefix()}/products/${productId}/skus/${skuId}`, data)
  return res.data
}

export async function deleteSku(productId: number, skuId: number): Promise<void> {
  await http.delete(`${tradeApiPrefix()}/products/${productId}/skus/${skuId}`)
}
