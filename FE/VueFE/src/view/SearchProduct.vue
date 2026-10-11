<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'
import { useRoute, useRouter } from 'vue-router'

interface Category {
  id: number
  name: string
  slug?: string
  is_active?: boolean | number | string
  products_count?: number
}

interface Product {
  id: number
  name: string
  description: string
  img: string | null
  category_id?: number | null
  category?: Category | null
}

const route = useRoute()
const router = useRouter()

const products = ref<Product[]>([])
const categories = ref<Category[]>([])
const loading = ref(false)
const errorMessage = ref('')
const currentPage = ref(1)
const pageSize = 20

function extractList<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[]

  if (typeof payload === 'object' && payload !== null) {
    const data = (payload as Record<string, unknown>).data
    if (Array.isArray(data)) return data as T[]
  }

  return []
}

function extractLastPage(payload: unknown): number {
  if (typeof payload !== 'object' || payload === null) return 1

  const data = payload as Record<string, unknown>
  if (typeof data.last_page === 'number') return Math.max(1, data.last_page)

  const meta = data.meta
  if (typeof meta === 'object' && meta !== null) {
    const lastPage = (meta as Record<string, unknown>).last_page
    if (typeof lastPage === 'number') return Math.max(1, lastPage)
  }

  return 1
}

// Từ khóa được truyền từ ô tìm kiếm ở Header qua URL ?q=...
const searchTerm = computed(() => {
  const value = route.query.q
  return typeof value === 'string' ? value.trim() : ''
})

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[đĐ]/g, character => character === 'Đ' ? 'D' : 'd')
    .toLocaleLowerCase('vi-VN')
    .trim()
}

function isActiveCategory(category: Category): boolean {
  return category.is_active === undefined ||
    category.is_active === true ||
    category.is_active === 1 ||
    category.is_active === '1'
}

const selectedCategoryId = computed<number | null>(() => {
  const value = route.query.category
  if (typeof value !== 'string' || value.trim() === '') return null

  const id = Number(value)
  return Number.isInteger(id) && id > 0 ? id : null
})

function categoryName(product: Product): string {
  const id = product.category_id ?? product.category?.id
  if (product.category?.name) return product.category.name
  if (id == null) return 'Chưa phân loại'
  return categories.value.find(category => category.id === id)?.name ?? 'Chưa phân loại'
}

const selectedCategoryName = computed(() => {
  if (searchTerm.value) return 'Kết quả tìm kiếm'
  if (selectedCategoryId.value === null) return 'Tất cả sản phẩm'
  return categories.value.find(category => category.id === selectedCategoryId.value)?.name
    ?? 'Danh mục sản phẩm'
})

const filteredProducts = computed(() => {
  const query = normalizeSearchText(searchTerm.value)
  const words = query.split(/\s+/).filter(Boolean)

  return products.value.filter(product => {
    const id = product.category_id ?? product.category?.id
    const matchesCategory = selectedCategoryId.value === null || id === selectedCategoryId.value
    if (!matchesCategory) return false
    if (!query) return true

    const searchableText = normalizeSearchText(
      `${product.name ?? ''} ${product.description ?? ''}`,
    )

    return searchableText.includes(query) || words.every(word => searchableText.includes(word))
  })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredProducts.value.length / pageSize)))

const paginatedProducts = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  return filteredProducts.value.slice(start, start + pageSize)
})

const pageNumbers = computed(() =>
  Array.from({ length: totalPages.value }, (_, index) => index + 1),
)

watch([selectedCategoryId, searchTerm], () => {
  currentPage.value = 1
})

function goToPage(page: number) {
  if (page < 1 || page > totalPages.value) return
  currentPage.value = page
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const categoryCounts = computed(() => {
  const counts = new Map<number, number>()
  for (const product of products.value) {
    const id = product.category_id ?? product.category?.id
    if (typeof id === 'number') counts.set(id, (counts.get(id) ?? 0) + 1)
  }
  return counts
})

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path

  const normalized = path.replace(/^\/+/, '')
  if (normalized.startsWith('storage/')) return `${STORAGE_BASE_URL}/${normalized}`
  return `${STORAGE_BASE_URL}/storage/${normalized}`
}

function setCategory(categoryId: number | null) {
  const query = { ...route.query }
  if (categoryId === null) delete query.category
  else query.category = String(categoryId)

  router.push({ path: '/search-products', query })
}

function formatCount(value: number): string {
  return new Intl.NumberFormat('vi-VN').format(value)
}

async function loadProductsAndCategories() {
  loading.value = true
  errorMessage.value = ''

  try {
    const [categoryResponse, firstProductResponse] = await Promise.all([
      axios.get(`${API_URL}/categories`),
      axios.get(`${API_URL}/products?page=1`),
    ])

    categories.value = extractList<Category>(categoryResponse.data)
      .filter(isActiveCategory)

    const firstPayload: unknown = firstProductResponse.data
    const allProducts = extractList<Product>(firstPayload)
    const lastPage = extractLastPage(firstPayload)

    // Tải mọi trang của Laravel paginator để trang này có đầy đủ sản phẩm.
    if (lastPage > 1) {
      const pageResults = await Promise.allSettled(
        Array.from({ length: lastPage - 1 }, (_, index) =>
          axios.get(`${API_URL}/products?page=${index + 2}`),
        ),
      )

      for (const result of pageResults) {
        if (result.status === 'fulfilled') {
          allProducts.push(...extractList<Product>(result.value.data))
        }
      }
    }

    // Loại trùng nếu API có dữ liệu lặp giữa các trang.
    const uniqueProducts = new Map<number, Product>()
    for (const product of allProducts) uniqueProducts.set(product.id, product)
    products.value = Array.from(uniqueProducts.values())
  } catch (error) {
    console.error('Không tải được danh mục hoặc sản phẩm:', error)
    errorMessage.value = 'Không tải được sản phẩm. Hãy kiểm tra kết nối Laravel rồi thử lại.'
  } finally {
    loading.value = false
  }
}

onMounted(loadProductsAndCategories)
</script>

<template>
  <main class="search-page">
    <section class="search-body">
      <div class="search-body-inner">
        <aside class="category-sidebar">
          <div class="sidebar-heading">
            <span class="sidebar-heading-icon">▤</span>
            <div>
              <h2>Danh mục tranh</h2>
              <p>Lọc theo dòng tranh</p>
            </div>
          </div>

          <button
            type="button"
            class="category-filter"
            :class="{ 'category-filter-active': selectedCategoryId === null }"
            @click="setCategory(null)"
          >
            <span class="category-filter-name"><span class="category-dot all-dot"></span>Tất cả sản phẩm</span>
            <span class="category-count">{{ products.length }}</span>
          </button>

          <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            class="category-filter"
            :class="{ 'category-filter-active': selectedCategoryId === category.id }"
            @click="setCategory(category.id)"
          >
            <span class="category-filter-name"><span class="category-dot"></span>{{ category.name }}</span>
            <span class="category-count">{{ categoryCounts.get(category.id) ?? 0 }}</span>
          </button>

          <div v-if="categories.length === 0 && !loading" class="category-hint">
            Chưa có danh mục hoặc không tải được danh mục.
          </div>
        </aside>

        <div class="products-area">
          <div class="products-heading">
            <div>
              <span class="products-kicker">BỘ SƯU TẬP</span>
              <h2>{{ selectedCategoryName }}</h2>
              <p v-if="!loading">
                <template v-if="searchTerm">Từ khóa: “{{ searchTerm }}” · </template>
                {{ formatCount(filteredProducts.length) }} sản phẩm phù hợp
              </p>
              <p v-else>Đang tải dữ liệu...</p>
            </div>

          </div>

          <div v-if="errorMessage" class="status-message status-error">
            <span>{{ errorMessage }}</span>
            <button type="button" @click="loadProductsAndCategories">Thử lại</button>
          </div>

          <div v-else-if="loading" class="product-grid">
            <div v-for="n in 6" :key="n" class="product-skeleton"></div>
          </div>

          <template v-else-if="filteredProducts.length > 0">
            <div class="product-grid">
              <router-link
                v-for="product in paginatedProducts"
                :key="product.id"
                :to="`/products/${product.id}`"
                class="search-product-card"
                :aria-label="`Xem chi tiết ${product.name}`"
              >
                <div class="search-product-image">
                  <img v-if="product.img" :src="imageUrl(product.img)" :alt="product.name" loading="lazy" />
                  <div v-else class="no-product-image"><span>Chưa có ảnh</span></div>
                </div>
                <div class="search-product-info">
                  <span class="search-product-category">{{ categoryName(product) }}</span>
                  <h3>{{ product.name }}</h3>
                  <p>{{ product.description }}</p>
                  <span class="search-product-details-link">Xem chi tiết <span aria-hidden="true">→</span></span>
                </div>
              </router-link>
            </div>

            <nav v-if="totalPages > 1" class="pagination" aria-label="Phân trang sản phẩm">
              <button
                type="button"
                class="pagination-arrow"
                :disabled="currentPage === 1"
                @click="goToPage(currentPage - 1)"
              >Trước</button>

              <button
                v-for="page in pageNumbers"
                :key="page"
                type="button"
                class="pagination-number"
                :class="{ 'pagination-number-active': currentPage === page }"
                :aria-current="currentPage === page ? 'page' : undefined"
                @click="goToPage(page)"
              >{{ page }}</button>

              <button
                type="button"
                class="pagination-arrow"
                :disabled="currentPage === totalPages"
                @click="goToPage(currentPage + 1)"
              >Tiếp</button>
            </nav>

            <p class="pagination-summary">
              Hiển thị {{ (currentPage - 1) * pageSize + 1 }}–{{ Math.min(currentPage * pageSize, filteredProducts.length) }}
              trong {{ formatCount(filteredProducts.length) }} sản phẩm
            </p>
          </template>

          <div v-else-if="!errorMessage && !loading" class="empty-products">
            <h3>Không tìm thấy sản phẩm</h3>
            <p>Thử chọn danh mục khác hoặc xem tất cả sản phẩm.</p>
            <button type="button" class="clear-empty-button" @click="setCategory(null)">Xem tất cả sản phẩm</button>
          </div>
        </div>
      </div>
    </section>

    <section class="search-cta">
      <div>
        <span>DUY CHÂN DUNG</span>
        <h2>Bạn đã có ý tưởng cho bức tranh của mình?</h2>
        <p>Liên hệ để trao đổi về tác phẩm được vẽ theo yêu cầu.</p>
      </div>
      <router-link to="/#contact" class="search-cta-button">Liên hệ ngay ↗</router-link>
    </section>
  </main>
</template>

<style scoped>
.search-page,
.search-page * {
  box-sizing: border-box;
  /* Ép font hỗ trợ đầy đủ dấu tiếng Việt, tránh bị font heading toàn cục ghi đè. */
  font-family: Arial, "Segoe UI", Tahoma, sans-serif !important;
  font-kerning: normal;
}
.search-page {
  min-height: 70vh;
  color: #1e293b;
  background: #f8fafc;
  font-family: Arial, "Segoe UI", Tahoma, sans-serif;
}
.search-body { padding: 36px 24px 58px; }
.search-body-inner { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 28px; width: 100%; max-width: 1240px; margin: 0 auto; align-items: start; }
.category-sidebar { position: sticky; top: 22px; padding: 19px 13px; border: 1px solid #e2e8f0; border-radius: 16px; background: white; box-shadow: 0 5px 22px rgb(15 23 42 / 4%); }
.sidebar-heading { display: flex; align-items: center; gap: 11px; margin: 0 4px 16px; padding: 0 0 16px; border-bottom: 1px solid #edf2f7; }
.sidebar-heading-icon { display: grid; width: 38px; height: 38px; flex-shrink: 0; place-items: center; border-radius: 11px; background: #fee2e2; color: #dc2626; font-size: 20px; }
.sidebar-heading h2 { margin: 0; color: #1e293b; font-size: 14px; font-weight: 800; }
.sidebar-heading p { margin: 4px 0 0; color: #94a3b8; font-size: 10px; }
.category-filter { display: flex; width: 100%; min-height: 42px; align-items: center; justify-content: space-between; gap: 8px; margin-top: 4px; padding: 10px 9px; border: 0; border-radius: 9px; background: transparent; color: #64748b; text-align: left; cursor: pointer; transition: background .18s, color .18s; }
.category-filter:hover { background: #f8fafc; color: #b91c1c; }
.category-filter-active { background: #fff1f2; color: #b91c1c; font-weight: 700; }
.category-filter-name { display: flex; min-width: 0; align-items: center; gap: 9px; font-size: 12px; }
.category-filter-name .category-dot { width: 7px; height: 7px; flex-shrink: 0; border-radius: 50%; background: #cbd5e1; }
.category-filter-active .category-dot { background: #ef4444; }
.category-filter-name .all-dot { background: #64748b; }
.category-count { min-width: 25px; padding: 3px 6px; border-radius: 6px; background: #f1f5f9; color: #64748b; font-size: 10px; text-align: center; }
.category-filter-active .category-count { background: #fecdd3; color: #9f1239; }
.category-hint { padding: 10px 7px; color: #94a3b8; font-size: 11px; line-height: 1.6; }
.products-area { min-width: 0; }
.products-heading { display: flex; align-items: end; justify-content: space-between; gap: 15px; margin: 1px 0 20px; }
.pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; margin-top: 30px; }
.pagination-number, .pagination-arrow { display: inline-flex; min-width: 38px; height: 38px; align-items: center; justify-content: center; padding: 0 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: white; color: #475569; font-size: 12px; font-weight: 700; cursor: pointer; transition: background .2s, color .2s, border-color .2s; }
.pagination-number:hover, .pagination-arrow:not(:disabled):hover { border-color: #fca5a5; background: #fff1f2; color: #b91c1c; }
.pagination-number-active { border-color: #dc2626; background: #dc2626; color: white; }
.pagination-number-active:hover { border-color: #b91c1c; background: #b91c1c; color: white; }
.pagination-arrow:disabled { opacity: .4; cursor: not-allowed; }
.pagination-summary { margin: 12px 0 0; color: #94a3b8; font-size: 11px; text-align: center; }
.products-heading h2 { margin: 6px 0 4px; color: #0f172a; font-size: 25px; font-weight: 800; letter-spacing: -.4px; }
.products-heading p { margin: 0; color: #94a3b8; font-size: 11px; }
.product-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
.search-product-card { display: block; color: inherit; text-decoration: none; min-width: 0; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 17px; background: white; box-shadow: 0 5px 18px rgb(15 23 42 / 4%); transition: transform .22s ease, box-shadow .22s ease; }
.search-product-card:hover { transform: translateY(-4px); box-shadow: 0 13px 28px rgb(15 23 42 / 10%); }
.search-product-image { height: 220px; overflow: hidden; background: #e2e8f0; }
.search-product-image img { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform .35s ease; }
.search-product-card:hover .search-product-image img { transform: scale(1.04); }
.no-product-image { display: flex; width: 100%; height: 100%; align-items: center; justify-content: center; color: #64748b; font-size: 12px; }
.search-product-info { padding: 15px 16px 18px; }
.search-product-category { display: inline-block; max-width: 100%; overflow: hidden; padding: 5px 8px; border-radius: 6px; background: #fff1f2; color: #be123c; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }
.search-product-info h3 { display: -webkit-box; min-height: 42px; margin: 10px 0 7px; overflow: hidden; color: #0f172a; font-size: 15px; font-weight: 800; line-height: 1.4; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; }
.search-product-info p { display: -webkit-box; margin: 0; overflow: hidden; color: #64748b; font-size: 12px; line-height: 1.7; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 3; line-clamp: 3; }
.search-product-details-link { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; color: #dc2626; font-size: 12px; font-weight: 800; }
.search-product-card:hover .search-product-details-link { color: #991b1b; }
.product-skeleton { height: 320px; border-radius: 17px; background: linear-gradient(100deg, #e2e8f0 20%, #f1f5f9 40%, #e2e8f0 60%); background-size: 200% 100%; animation: skeleton-shimmer 1.4s infinite; }
@keyframes skeleton-shimmer { to { background-position-x: -200%; } }
.status-message { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px; border-radius: 10px; font-size: 12px; line-height: 1.6; }
.status-error { border: 1px solid #fecaca; background: #fff1f2; color: #9f1239; }
.status-error button { flex-shrink: 0; padding: 7px 10px; border: 1px solid #fda4af; border-radius: 7px; background: white; color: #9f1239; cursor: pointer; }
.empty-products { padding: 55px 20px; border: 1px dashed #cbd5e1; border-radius: 16px; background: white; text-align: center; }
.empty-products h3 { margin: 0; color: #1e293b; font-size: 17px; font-weight: 800; }
.empty-products p { margin: 8px 0 18px; color: #94a3b8; font-size: 12px; }
.clear-empty-button { padding: 10px 15px; border: 0; border-radius: 8px; background: #dc2626; color: white; font-size: 12px; font-weight: 700; cursor: pointer; }
.search-cta { display: flex; max-width: 1240px; align-items: center; justify-content: space-between; gap: 24px; margin: 0 auto 55px; padding: 28px 32px; border: 1px solid #fee2e2; border-radius: 18px; background: linear-gradient(120deg, #fff1f2, #f8fafc); }
.search-cta > div > span { color: #dc2626; font-size: 9px; font-weight: 800; letter-spacing: 1.7px; }
.search-cta h2 { margin: 8px 0 5px; color: #0f172a; font-size: 21px; font-weight: 800; }
.search-cta p { margin: 0; color: #64748b; font-size: 12px; line-height: 1.7; }
.search-cta-button { flex-shrink: 0; padding: 12px 17px; border-radius: 9px; background: #dc2626; color: white; font-size: 12px; font-weight: 700; text-decoration: none; transition: background .2s; }
.search-cta-button:hover { background: #b91c1c; }

@media (max-width: 1100px) {
  .search-body-inner { grid-template-columns: 220px minmax(0, 1fr); gap: 20px; }
  .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .search-product-image { height: 210px; }
}

@media (max-width: 760px) {
  .search-body { padding: 25px 15px 38px; }
  .search-body-inner { grid-template-columns: minmax(0, 1fr); gap: 20px; }
  .category-sidebar { position: static; padding: 15px 12px; }
  .category-sidebar .sidebar-heading { margin-bottom: 10px; }
  .category-sidebar { display: flex; flex-wrap: wrap; gap: 5px; }
  .sidebar-heading { width: 100%; }
  .category-filter { width: auto; min-height: 36px; margin-top: 0; padding: 8px 10px; border: 1px solid #e2e8f0; }
  .category-filter-name { font-size: 11px; }
  .category-count { min-width: 20px; }
  .category-hint { width: 100%; }
  .products-heading h2 { font-size: 22px; }
  .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .search-product-image { height: 180px; }
  .search-product-info { padding: 12px; }
  .search-product-info h3 { font-size: 13px; }
  .search-product-info p { font-size: 11px; }
  .search-cta { flex-direction: column; align-items: flex-start; margin: 0 15px 35px; padding: 22px; }
}

@media (max-width: 420px) {
  .product-grid { grid-template-columns: minmax(0, 1fr); }
  .search-product-image { height: 240px; }
}
</style>
