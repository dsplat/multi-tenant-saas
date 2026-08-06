import { http, extractListResult, tradeApiPrefix } from '@/shared/http'

// 后端 products 原始结构：status 为字符串枚举，图片存于 media_assets
interface RawProduct {
  product_id: number
  category_id: number | null
  name: string
  description: string | null
  price: string | number
  market_price: string | number | null
  stock: number
  status: 'draft' | 'active' | 'inactive'
  media_assets: { type?: string; url?: string }[] | null
  created_at: string
  updated_at: string
}

export interface Product {
  product_id: number
  name: string
  image: string
  price: number
  stock: number
  category: string
  status: 'draft' | 'active' | 'inactive'
  created_at: string
  updated_at: string
}

function normalizeProduct(raw: RawProduct): Product {
  return {
    product_id: raw.product_id,
    name: raw.name,
    image: raw.media_assets?.[0]?.url ?? '',
    price: Number(raw.price ?? 0),
    stock: raw.stock ?? 0,
    category: raw.category_id != null ? String(raw.category_id) : '',
    status: raw.status,
    created_at: raw.created_at,
    updated_at: raw.updated_at,
  }
}

export interface ProductListParams {
  page: number
  pageSize: number
  name?: string
  category?: string
  status?: string
}

export interface ProductListResult {
  data: Product[]
  total: number
}

export interface CreateProductData {
  name: string
  image: string
  price: number
  stock: number
  category: string
  status: 'active' | 'inactive'
}

export interface UpdateProductData {
  name?: string
  image?: string
  price?: number
  stock?: number
  category?: string
  status?: 'active' | 'inactive'
}

function toBackendPayload(data: Partial<CreateProductData>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}
  if (data.name !== undefined) payload.name = data.name
  if (data.price !== undefined) payload.price = data.price
  if (data.stock !== undefined) payload.stock = data.stock
  if (data.image !== undefined) {
    payload.media_assets = data.image ? [{ type: 'image', url: data.image }] : []
  }
  return payload
}

export async function getProductList(params: ProductListParams): Promise<ProductListResult> {
  const res = await http.get<RawProduct[]>(`${tradeApiPrefix()}/products`, { params })
  const { data, total } = extractListResult(res)
  return { data: data.map(normalizeProduct), total }
}

export async function getProductDetail(id: number): Promise<Product> {
  const res = await http.get<RawProduct>(`${tradeApiPrefix()}/products/${id}`)
  return normalizeProduct(res.data)
}

export async function createProduct(data: CreateProductData): Promise<Product> {
  const res = await http.post<RawProduct>(`${tradeApiPrefix()}/products`, toBackendPayload(data))
  const product = normalizeProduct(res.data)
  // 后端创建默认 draft，选择上架时需追加 publish（要求库存>0）
  if (data.status === 'active') {
    await updateProductStatus(product.product_id, 'active')
    product.status = 'active'
  }
  return product
}

export async function updateProduct(id: number, data: UpdateProductData): Promise<Product> {
  const res = await http.put<RawProduct>(`${tradeApiPrefix()}/products/${id}`, toBackendPayload(data))
  const product = normalizeProduct(res.data)
  if (data.status !== undefined && data.status !== product.status) {
    await updateProductStatus(id, data.status)
    product.status = data.status
  }
  return product
}

export async function deleteProduct(id: number): Promise<void> {
  await http.delete(`${tradeApiPrefix()}/products/${id}`)
}

export async function updateProductStatus(id: number, status: 'active' | 'inactive'): Promise<void> {
  // 后端无 PUT /status 路由，上架/下架分别对应 publish/unpublish
  await http.post(`${tradeApiPrefix()}/products/${id}/${status === 'active' ? 'publish' : 'unpublish'}`)
}
