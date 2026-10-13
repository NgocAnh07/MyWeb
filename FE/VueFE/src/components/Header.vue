<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'
import { useRouter } from 'vue-router'
import { API_URL, STORAGE_BASE_URL } from '../config/api'

const router = useRouter()
const isLoggedIn = ref(false)
const mobileMenuOpen = ref(false)

interface SearchProduct {
  id: number
  slug?: string
  name: string
  description?: string | null
  img?: string | null
  category_id?: number | null
  category?: { id?: number; name?: string } | null
}

const searchQuery = ref('')
const searchProducts = ref<SearchProduct[]>([])
const searchSuggestionsOpen = ref(false)
const searchProductsLoading = ref(false)
const searchProductsError = ref('')
const searchProductsLoaded = ref(false)
let searchProductsRequest: Promise<void> | null = null
let searchBlurTimer: ReturnType<typeof setTimeout> | undefined

function extractProductList(payload: unknown): SearchProduct[] {
  if (Array.isArray(payload)) return payload as SearchProduct[]
  if (typeof payload === 'object' && payload !== null) {
    const data = (payload as Record<string, unknown>).data
    if (Array.isArray(data)) return data as SearchProduct[]
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

// Chuẩn hóa dấu tiếng Việt để tìm "chan dung" vẫn khớp "chân dung".
function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[đĐ]/g, character => character === 'Đ' ? 'D' : 'd')
    .toLocaleLowerCase('vi-VN')
    .trim()
}

const matchingProducts = computed(() => {
  const query = normalizeSearchText(searchQuery.value)
  if (!query) return []

  const words = query.split(/\s+/).filter(Boolean)

  return searchProducts.value
    .map(product => {
      const name = normalizeSearchText(product.name || '')
      const description = normalizeSearchText(product.description || '')
      const combined = `${name} ${description}`
      const matches = name.includes(query) ||
        words.every(word => name.includes(word)) ||
        combined.includes(query) ||
        words.every(word => combined.includes(word))

      let score = 4
      if (name === query) score = 0
      else if (name.startsWith(query)) score = 1
      else if (name.includes(query)) score = 2
      else if (words.every(word => name.includes(word))) score = 3

      return { product, matches, score }
    })
    .filter(item => item.matches)
    .sort((a, b) => a.score - b.score || a.product.name.localeCompare(b.product.name, 'vi'))
    .slice(0, 6)
    .map(item => item.product)
})

function ensureSearchProductsLoaded(): Promise<void> {
  if (searchProductsLoaded.value) return Promise.resolve()
  if (searchProductsRequest) return searchProductsRequest

  searchProductsLoading.value = true
  searchProductsError.value = ''

  const request = (async () => {
    const firstResponse = await axios.get(`${API_URL}/products?page=1`)
    const firstPayload: unknown = firstResponse.data
    const products = extractProductList(firstPayload)
    const lastPage = extractLastPage(firstPayload)

    if (lastPage > 1) {
      const results = await Promise.allSettled(
        Array.from({ length: lastPage - 1 }, (_, index) =>
          axios.get(`${API_URL}/products?page=${index + 2}`),
        ),
      )

      for (const result of results) {
        if (result.status === 'fulfilled') {
          products.push(...extractProductList(result.value.data))
        }
      }
    }

    const uniqueProducts = new Map<number, SearchProduct>()
    for (const product of products) uniqueProducts.set(product.id, product)
    searchProducts.value = Array.from(uniqueProducts.values())
    searchProductsLoaded.value = true
  })()
    .catch((error: unknown) => {
      console.error('Không tải được sản phẩm tìm kiếm:', error)
      searchProductsError.value = 'Không tải được sản phẩm. Hãy thử lại.'
      searchProducts.value = []
    })
    .finally(() => {
      searchProductsLoading.value = false
      searchProductsRequest = null
    })

  searchProductsRequest = request
  return request
}

function handleSearchInput(event: Event) {
  // Read the current value directly from the input. This avoids timing issues
  // where the input handler runs before v-model has updated searchQuery.
  const input = event.target as HTMLInputElement | null
  if (input) searchQuery.value = input.value

  if (searchBlurTimer) {
    clearTimeout(searchBlurTimer)
    searchBlurTimer = undefined
  }

  const hasQuery = searchQuery.value.trim().length > 0
  searchSuggestionsOpen.value = hasQuery

  // Open the suggestion panel immediately; show a loading message while
  // the product list is being fetched for the first time.
  if (hasQuery) void ensureSearchProductsLoaded()
}

function handleSearchFocus() {
  if (searchBlurTimer) {
    clearTimeout(searchBlurTimer)
    searchBlurTimer = undefined
  }

  if (searchQuery.value.trim()) {
    searchSuggestionsOpen.value = true
    void ensureSearchProductsLoaded()
  }
}

function handleSearchBlur() {
  if (searchBlurTimer) clearTimeout(searchBlurTimer)
  // Give clicks on suggestions time to register, but do not rely on clicking
  // the input again to reopen the results.
  searchBlurTimer = setTimeout(() => {
    searchSuggestionsOpen.value = false
    searchBlurTimer = undefined
  }, 300)
}

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  const normalized = path.replace(/^\/+/, '')
  if (normalized.startsWith('storage/')) return `${STORAGE_BASE_URL}/${normalized}`
  return `${STORAGE_BASE_URL}/storage/${normalized}`
}

function submitSearch() {
  const query = searchQuery.value.trim()
  searchSuggestionsOpen.value = false
  closeMobileMenu()

  router.push({
    path: '/search-products',
    query: query ? { q: query } : {},
  })
}


function openSuggestedProduct(product: SearchProduct) {
  searchSuggestionsOpen.value = false
  searchQuery.value = ''
  closeMobileMenu()

  if (product.slug) {
    router.push({
      name: 'product-detail',
      params: { slug: product.slug },
    })
  } else {
    router.push({
      name: 'product-detail-legacy',
      params: { id: product.id },
    })
  }
}


// Đồng bộ trạng thái đăng nhập từ localStorage.
function syncAuthState() {
  isLoggedIn.value = !!localStorage.getItem('auth_token')
}

function closeMobileMenu() {
  mobileMenuOpen.value = false
}

// Đăng xuất.
async function handleLogout() {
  const token = localStorage.getItem('auth_token')

  try {
    if (token) {
      await axios.post(
        `${API_URL}/logout`,
        {},
        {
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
          },
        },
      )
    }
  } catch {
    // Vẫn xóa trạng thái đăng nhập nếu API không phản hồi.
  } finally {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    isLoggedIn.value = false
    closeMobileMenu()
    window.dispatchEvent(new Event('auth-changed'))
    await router.push('/')
  }
}

onMounted(() => {
  syncAuthState()
  window.addEventListener('auth-changed', syncAuthState)
  window.addEventListener('storage', syncAuthState)
})

onBeforeUnmount(() => {
  window.removeEventListener('auth-changed', syncAuthState)
  window.removeEventListener('storage', syncAuthState)
  if (searchBlurTimer) clearTimeout(searchBlurTimer)
})
</script>

<template>
  <div>
    <!-- NAVBAR -->
    <nav
      id="navbar"
      class="sticky top-0 z-50 bg-transparent transition-all duration-300"
    >
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 lg:h-18">
          <!-- Logo: luôn quay về đầu trang chủ -->
          <router-link
            :to="{ path: '/', hash: '#home' }"
            class="flex items-center shrink-0"
            aria-label="Về trang chủ"
            @click="closeMobileMenu"
          >
            <img
              src="/assets/logo.jpeg"
              alt="Logo Duy Chân Dung"
              style="height: 36px; width: auto; max-width: 160px; object-fit: contain; flex-shrink: 0;"
            />
          </router-link>

          <!-- Desktop Nav: các link luôn điều hướng về đúng section trang chủ -->
          <div class="hidden lg:flex items-center gap-4 shrink-0">
            <router-link
              :to="{ path: '/', hash: '#home' }"
              class="nav-link text-base font-medium"
            >Trang chủ</router-link>
            <router-link
              :to="{ path: '/', hash: '#about' }"
              class="nav-link text-base font-medium"
            >Giới thiệu</router-link>
            <router-link
              :to="{ path: '/search-products' }"
              class="nav-link text-base font-medium"
            >Danh mục</router-link>
            <router-link
            
              :to="{ path: '/', hash: '#products' }"
              class="nav-link text-base font-medium"
            >Sản phẩm</router-link>
            <router-link
              :to="{ path: '/', hash: '#blog' }"
              class="nav-link text-base font-medium"
            >Tin tức</router-link>
            <router-link
              :to="{ path: '/', hash: '#contact' }"
              class="nav-link text-base font-medium"
            >Liên hệ</router-link>
          </div>

          <!-- Các nút bên phải -->
          <div class="flex items-center gap-3 lg:gap-4">
            <!-- Thanh tìm kiếm trên desktop + gợi ý sản phẩm khi nhập -->
            <form
              class="header-search-form hidden lg:flex relative shrink-0 mx-2 w-44 xl:w-60"
              role="search"
              autocomplete="off"
              @submit.prevent="submitSearch"
            >
              <input
                v-model="searchQuery"
                type="search"
                placeholder="Bạn muốn tìm gì?"
                aria-label="Tìm kiếm tranh"
                aria-autocomplete="list"
                :aria-expanded="searchSuggestionsOpen && searchQuery.trim().length > 0"
                class="text-sm bg-white text-emerald-700 placeholder:text-emerald-600 outline-none focus:ring-2 focus:ring-emerald-100"
                style="width: 100%; height: 48px; box-sizing: border-box; padding: 0 44px 0 16px; border: 1px solid #16a34a; border-radius: 12px;"
                @input="handleSearchInput"
                @focus="handleSearchFocus"
                @blur="handleSearchBlur"
              />
              <button
                type="submit"
                aria-label="Tìm kiếm"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-emerald-600 hover:text-emerald-800"
              >
                <i data-lucide="search" class="w-5 h-5"></i>
              </button>

              <div
                v-if="searchSuggestionsOpen && searchQuery.trim()"
                class="header-search-suggestions"
                role="listbox"
                aria-label="Sản phẩm phù hợp"
                @mousedown.prevent
              >
                <p class="header-search-label">Sản phẩm phù hợp</p>
                <div v-if="searchProductsLoading" class="header-search-message">Đang tìm sản phẩm...</div>
                <div v-else-if="searchProductsError" class="header-search-message header-search-error">{{ searchProductsError }}</div>
                <div v-else-if="matchingProducts.length === 0" class="header-search-message">Không tìm thấy sản phẩm phù hợp.</div>
                <button
                  v-for="product in matchingProducts"
                  :key="product.id"
                  type="button"
                  class="header-search-result"
                  role="option"
                  @click="openSuggestedProduct(product)"
                >
                  <span class="header-search-result-image">
                    <img v-if="product.img" :src="imageUrl(product.img)" :alt="product.name" />
                    <span v-else class="header-search-no-image">Ảnh</span>
                  </span>
                  <span class="header-search-result-copy">
                    <strong>{{ product.name }}</strong>
                    <small>{{ product.category?.name || 'Tranh nghệ thuật' }}</small>
                  </span>
                </button>
                <button type="submit" class="header-search-all">
                  Xem tất cả kết quả cho “{{ searchQuery.trim() }}”
                  <span aria-hidden="true">→</span>
                </button>
              </div>
            </form>

            <!-- Gọi điện -->
            <a
  href="tel:0349691818"
  class="btn-primary header-phone-cta text-base py-3.5 px-7"
>
  <i data-lucide="calendar-plus" class="w-5 h-5"></i>
  034 969 1818
</a>

            <!-- Đăng nhập -->
            <router-link
              v-if="!isLoggedIn"
              to="/login"
              class="inline-flex items-center gap-2 rounded-lg border border-emerald-600 px-3 sm:px-4 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-600 hover:text-white whitespace-nowrap"
              @click="closeMobileMenu"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" width="18" height="18" aria-hidden="true" style="display:block;width:18px;height:18px;flex-shrink:0;fill:currentColor;">
                <path d="M409 337C418.4 327.6 418.4 312.4 409 303.1L265 159C258.1 152.1 247.8 150.1 238.8 153.8C229.8 157.5 224 166.3 224 176L224 256L112 256C85.5 256 64 277.5 64 304L64 336C64 362.5 85.5 384 112 384L224 384L224 464C224 473.7 229.8 482.5 238.8 486.2C247.8 489.9 258.1 487.9 265 481L409 337zM416 480C398.3 480 384 494.3 384 512C384 529.7 398.3 544 416 544L480 544C533 544 576 501 576 448L576 192C576 139 533 96 480 96L416 96C398.3 96 384 110.3 384 128C384 145.7 398.3 160 416 160L480 160C497.7 160 512 174.3 512 192L512 448C512 465.7 497.7 480 480 480L416 480z" />
              </svg>
              <span>Đăng nhập</span>
            </router-link>

            <!-- Đăng xuất -->
            <button
              v-else
              type="button"
              class="inline-flex items-center gap-2 rounded-lg border border-emerald-600 px-3 sm:px-4 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-600 hover:text-white whitespace-nowrap"
              @click="handleLogout"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" width="18" height="18" aria-hidden="true" style="display:block;width:18px;height:18px;flex-shrink:0;fill:currentColor;">
                <path d="M409 337C418.4 327.6 418.4 312.4 409 303.1L265 159C258.1 152.1 247.8 150.1 238.8 153.8C229.8 157.5 224 166.3 224 176L224 256L112 256C85.5 256 64 277.5 64 304L64 336C64 362.5 85.5 384 112 384L224 384L224 464C224 473.7 229.8 482.5 238.8 486.2C247.8 489.9 258.1 487.9 265 481L409 337zM416 480C398.3 480 384 494.3 384 512C384 529.7 398.3 544 416 544L480 544C533 544 576 501 576 448L576 192C576 139 533 96 480 96L416 96C398.3 96 384 110.3 384 128C384 145.7 398.3 160 416 160L480 160C497.7 160 512 174.3 512 192L512 448C512 465.7 497.7 480 480 480L416 480z" />
              </svg>
              <span>Đăng xuất</span>
            </button>

            <!-- Mobile menu toggle -->
            <button
              id="mobile-menu-btn"
              class="lg:hidden p-2 text-slate-600 hover:text-emerald-600"
              type="button"
              :aria-label="mobileMenuOpen ? 'Đóng menu' : 'Mở menu'"
              :aria-expanded="mobileMenuOpen"
              @click="mobileMenuOpen = !mobileMenuOpen"
            >
              <svg v-if="!mobileMenuOpen" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="w-6 h-6" aria-hidden="true">
                <path d="M4 6h16M4 12h16M4 18h16" />
              </svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="w-6 h-6" aria-hidden="true">
                <path d="m6 6 12 12M18 6 6 18" />
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Mobile Menu: tự đóng sau khi chọn mục -->
      <div
        v-show="mobileMenuOpen"
        id="mobile-menu"
        class="lg:hidden bg-slate-900 border-t border-white/10"
      >
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col gap-1">
          <!-- Tìm kiếm trên mobile, dùng chung dữ liệu gợi ý với desktop -->
          <form
            class="header-search-form header-search-mobile-form relative mb-3"
            role="search"
            autocomplete="off"
            @submit.prevent="submitSearch"
          >
            <input
              v-model="searchQuery"
              type="search"
              placeholder="Tìm sản phẩm..."
              aria-label="Tìm kiếm tranh"
              aria-autocomplete="list"
              :aria-expanded="searchSuggestionsOpen && searchQuery.trim().length > 0"
              class="header-search-mobile-input"
              @input="handleSearchInput"
              @focus="handleSearchFocus"
              @blur="handleSearchBlur"
            />
            <button type="submit" class="header-search-mobile-button" aria-label="Tìm kiếm">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-4-4" />
              </svg>
            </button>
            <div
              v-if="searchSuggestionsOpen && searchQuery.trim()"
              class="header-search-suggestions header-search-suggestions-mobile"
              role="listbox"
              aria-label="Sản phẩm phù hợp"
              @mousedown.prevent
            >
              <p class="header-search-label">Sản phẩm phù hợp</p>
              <div v-if="searchProductsLoading" class="header-search-message">Đang tìm sản phẩm...</div>
              <div v-else-if="searchProductsError" class="header-search-message header-search-error">{{ searchProductsError }}</div>
              <div v-else-if="matchingProducts.length === 0" class="header-search-message">Không tìm thấy sản phẩm phù hợp.</div>
              <button
                v-for="product in matchingProducts"
                :key="product.id"
                type="button"
                class="header-search-result"
                role="option"
                @click="openSuggestedProduct(product)"
              >
                <span class="header-search-result-image">
                  <img v-if="product.img" :src="imageUrl(product.img)" :alt="product.name" />
                  <span v-else class="header-search-no-image">Ảnh</span>
                </span>
                <span class="header-search-result-copy">
                  <strong>{{ product.name }}</strong>
                  <small>{{ product.category?.name || 'Tranh nghệ thuật' }}</small>
                </span>
              </button>
              <button type="submit" class="header-search-all">
                Xem tất cả kết quả cho “{{ searchQuery.trim() }}”
                <span aria-hidden="true">→</span>
              </button>
            </div>
          </form>

          <router-link
            :to="{ path: '/', hash: '#home' }"
            class="nav-link text-slate-200 py-2.5 text-sm"
            @click="closeMobileMenu"
          >Trang chủ</router-link>
          <router-link
            :to="{ path: '/', hash: '#about' }"
            class="nav-link text-slate-200 py-2.5 text-sm"
            @click="closeMobileMenu"
          >Giới thiệu</router-link>
          <router-link
              :to="{ path: '/search-products' }"
              class="nav-link text-slate-200 py-2.5 text-sm"
              @click="closeMobileMenu"
            >Danh mục</router-link>
          <router-link
            :to="{ path: '/', hash: '#products' }"
            class="nav-link text-slate-200 py-2.5 text-sm"
            @click="closeMobileMenu"
          >Sản phẩm</router-link>
          <router-link
            :to="{ path: '/', hash: '#blog' }"
            class="nav-link text-slate-200 py-2.5 text-sm"
            @click="closeMobileMenu"
          >Tin tức</router-link>
          <router-link
            :to="{ path: '/', hash: '#contact' }"
            class="nav-link text-slate-200 py-2.5 text-sm"
            @click="closeMobileMenu"
          >Liên hệ</router-link>
          <a
            href="tel:0349691818"
            class="btn-primary mt-3 w-full justify-center"
            @click="closeMobileMenu"
          >Gọi 034 969 1818</a>
        </div>
      </div>
    </nav>
  </div>
</template>


<style scoped>
.header-search-form {
  z-index: 80;
}

.header-search-suggestions {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 1200;
  width: min(380px, calc(100vw - 24px));
  max-height: min(440px, 70vh);
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  background: #fff;
  color: #0f172a;
  box-shadow: 0 16px 42px rgb(15 23 42 / 18%);
  text-align: left;
}

.header-search-label {
  margin: 0;
  padding: 12px 14px 9px;
  border-bottom: 1px solid #f1f5f9;
  color: #64748b;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.header-search-result {
  display: flex;
  width: 100%;
  min-width: 0;
  align-items: center;
  gap: 11px;
  padding: 10px 13px;
  border: 0;
  border-bottom: 1px solid #f1f5f9;
  background: #fff;
  color: #0f172a;
  text-align: left;
  cursor: pointer;
  transition: background .15s ease;
}

.header-search-result:hover,
.header-search-result:focus-visible {
  outline: none;
  background: #ecfdf5;
}

.header-search-result-image {
  display: flex;
  width: 52px;
  height: 52px;
  flex: 0 0 52px;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: 8px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 10px;
}

.header-search-result-image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.header-search-no-image {
  font-size: 10px;
}

.header-search-result-copy {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 5px;
}

.header-search-result-copy strong {
  display: -webkit-box;
  overflow: hidden;
  color: #0f172a;
  font-size: 13px;
  font-weight: 700;
  line-height: 1.4;
  overflow-wrap: anywhere;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}

.header-search-result-copy small {
  overflow: hidden;
  color: #059669;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.header-search-message {
  padding: 18px 14px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.6;
}

.header-search-error {
  color: #b91c1c;
}

.header-search-all {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border: 0;
  background: #f8fafc;
  color: #047857;
  font-size: 12px;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.header-search-all:hover {
  background: #d1fae5;
}

.header-search-mobile-form {
  width: 100%;
  min-width: 0;
}

.header-search-mobile-input {
  display: block;
  width: 100%;
  height: 44px;
  box-sizing: border-box;
  padding: 0 45px 0 13px;
  border: 1px solid #64748b;
  border-radius: 10px;
  outline: none;
  background: #fff;
  color: #0f172a;
  font-size: 14px;
}

.header-search-mobile-input:focus {
  border-color: #10b981;
  box-shadow: 0 0 0 3px rgb(16 185 129 / 15%);
}

.header-search-mobile-button {
  position: absolute;
  top: 50%;
  right: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  transform: translateY(-50%);
  border: 0;
  background: transparent;
  color: #059669;
  cursor: pointer;
}

.header-search-suggestions-mobile {
  right: 0;
  left: 0;
  width: 100%;
  max-width: 100%;
}
/* Thu nhỏ nút gọi điện trên mobile */
@media (max-width: 767px) {
  .header-phone-cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;

    width: auto;
    min-width: 0;
    max-width: 155px;
    padding: 8px 10px !important;

    font-size: 12px !important;
    line-height: 1.3;
    white-space: nowrap;
    flex-shrink: 1;
    border-radius: 10px;
  }

  .header-phone-cta svg,
  .header-phone-cta i {
    width: 16px !important;
    height: 16px !important;
    flex-shrink: 0;
  }
}
</style>
