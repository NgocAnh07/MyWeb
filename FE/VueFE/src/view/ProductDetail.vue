<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'
import { useRoute } from 'vue-router'

interface Category {
  id: number
  name: string
  slug?: string
}

interface Product {
  id: number
  slug?: string
  name: string
  description: string
  img: string | null
  category_id?: number | null
  category?: Category | null
}

const route = useRoute()
const product = ref<Product | null>(null)
const categories = ref<Category[]>([])
const loading = ref(false)
const errorMessage = ref('')
const descriptionExpanded = ref(false)
const descriptionContentRef = ref<HTMLDivElement | null>(null)
const isDescriptionLong = ref(false)
const relatedProducts = ref<Product[]>([])
const relatedLoading = ref(false)

// Bộ ảnh riêng cho trang chi tiết: ảnh chính lấy đúng từ product.img,
// ảnh phụ được tìm theo tên file cùng gốc với hậu tố -1, -2, -3.
const selectedGalleryIndex = ref(0)
const failedGalleryImages = ref<Set<number>>(new Set())

function safeDescriptionHtml(value: string): string {
  const raw = value.trim()
  if (!raw) return ''
  const hasMarkup = /<\s*(p|h[2-4]|ul|ol|li|strong|b|em|i|u|blockquote|a|img|figure|figcaption|br|hr)\b/i.test(raw)
  const template = document.createElement('template')
  if (!hasMarkup) {
    const paragraph = document.createElement('p')
    paragraph.textContent = raw
    template.content.appendChild(paragraph)
    return template.innerHTML
  }
  template.innerHTML = raw
  const allowed = new Set(['P', 'BR', 'HR', 'H2', 'H3', 'H4', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A', 'IMG', 'FIGURE', 'FIGCAPTION'])
  template.content.querySelectorAll('*').forEach(element => {
    if (!allowed.has(element.tagName)) {
      if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH'].includes(element.tagName)) element.remove()
      else element.replaceWith(...Array.from(element.childNodes))
      return
    }
    const allowedAttrs = element.tagName === 'A' ? new Set(['href', 'title', 'target']) : element.tagName === 'IMG' ? new Set(['src', 'alt', 'loading']) : new Set<string>()
    Array.from(element.attributes).forEach(attribute => {
      if (!allowedAttrs.has(attribute.name.toLowerCase())) element.removeAttribute(attribute.name)
    })
    if (element.tagName === 'A') {
      const href = (element.getAttribute('href') ?? '').trim()
      if (!/^(https?:\/\/|\/(?!\/)|#|\.\/|\.\.\/)/i.test(href)) element.removeAttribute('href')
      if (element.getAttribute('target') === '_blank') element.setAttribute('rel', 'noopener noreferrer')
      else element.removeAttribute('target')
    }
    if (element.tagName === 'IMG') {
      const src = (element.getAttribute('src') ?? '').trim()
      if (!/^(https?:\/\/|\/storage\/|storage\/)/i.test(src)) element.remove()
      const alt = element.getAttribute('alt')
      if (alt === null || !alt.trim()) element.setAttribute('alt', '')
    }
  })
  return template.innerHTML
}

const renderedDescription = computed(() => safeDescriptionHtml(product.value?.description ?? ''))

// Xác định mô tả dài dựa trên chiều cao nội dung thực tế, không dựa vào số ký tự.
async function measureDescriptionOverflow() {
  await nextTick()
  if (descriptionExpanded.value) return

  const element = descriptionContentRef.value
  if (!element) {
    isDescriptionLong.value = false
    return
  }

  isDescriptionLong.value = element.scrollHeight > element.clientHeight + 2
}

watch(renderedDescription, () => {
  descriptionExpanded.value = false
  isDescriptionLong.value = false
  void measureDescriptionOverflow()
})

// Trình xem ảnh phóng to
const imageViewerOpen = ref(false)
const zoomLevel = ref(1)
const panX = ref(0)
const panY = ref(0)
const isDragging = ref(false)
const dragStartX = ref(0)
const dragStartY = ref(0)
const panStartX = ref(0)
const panStartY = ref(0)
const imageViewerStage = ref<HTMLElement | null>(null)
const imageViewerImage = ref<HTMLImageElement | null>(null)

// Pointer tracking supports mouse, touch and two-finger pinch on mobile.
const activePointers = new Map<number, { x: number; y: number }>()
let pinchStartDistance = 0
let pinchStartZoom = 1

const productIdentifier = computed(() => {
  const value = route.params.slug ?? route.params.id

  if (typeof value !== 'string' || !value.trim()) {
    return null
  }

  // URL cũ /products/:id phải có ID là số nguyên dương.
  if (route.params.id !== undefined) {
    const id = Number(value)
    return Number.isInteger(id) && id > 0 ? String(id) : null
  }

  return value
})

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  const normalized = path.replace(/^\/+/, '')
  return normalized.startsWith('storage/')
    ? `${STORAGE_BASE_URL}/${normalized}`
    : `${STORAGE_BASE_URL}/storage/${normalized}`
}

function alternateImagePath(path: string, index: number): string {
  // Giữ nguyên thư mục, phần mở rộng và query string (nếu có).
  if (/^https?:\/\//i.test(path)) {
    try {
      const url = new URL(path)
      const pathname = url.pathname
      url.pathname = /\.[^./]+$/.test(pathname)
        ? pathname.replace(/(\.[^./]+)$/i, `-${index}$1`)
        : `${pathname}-${index}.jpg`
      return url.toString()
    } catch {
      return path
    }
  }

  const match = path.match(/^([^?#]*)([?#].*)?$/)
  const pathname = match?.[1] ?? path
  const suffix = match?.[2] ?? ''
  const alternate = /\.[^./]+$/i.test(pathname)
    ? pathname.replace(/(\.[^./]+)$/i, `-${index}$1`)
    : `${pathname}-${index}.jpg`
  return `${alternate}${suffix}`
}

const galleryImages = computed(() => {
  const current = product.value
  if (!current?.img) return []

  return [
    {
      index: 0,
      src: imageUrl(current.img),
      alt: current.name,
      label: 'Ảnh chính',
    },
    ...[1, 2, 3].map(index => ({
      index,
      src: imageUrl(alternateImagePath(current.img as string, index)),
      alt: `${current.name} - ảnh phụ ${index}`,
      label: `Ảnh phụ ${index}`,
    })),
  ]
})

const selectedGalleryImage = computed(() =>
  galleryImages.value.find(image => image.index === selectedGalleryIndex.value)
    ?? galleryImages.value[0]
    ?? null,
)

function markGalleryImageFailed(index: number) {
  const updated = new Set(failedGalleryImages.value)
  updated.add(index)
  failedGalleryImages.value = updated

  // Nếu ảnh phụ không tồn tại, quay về ảnh chính thay vì để khung lớn bị trống.
  if (selectedGalleryIndex.value === index && index !== 0) {
    selectedGalleryIndex.value = 0
  }
}

function selectGalleryImage(index: number) {
  if (failedGalleryImages.value.has(index)) return
  selectedGalleryIndex.value = index
  resetImageZoom()
}

function getCategoryName(item: Product): string {
  if (item.category?.name) return item.category.name
  const categoryId = item.category_id
  if (categoryId == null) return 'Chưa phân loại'
  return categories.value.find(category => category.id === categoryId)?.name ?? 'Chưa phân loại'
}

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

async function loadRelatedProducts() {
  const currentProduct = product.value
  const categoryId = currentProduct?.category_id ?? currentProduct?.category?.id
  relatedProducts.value = []
  if (!currentProduct || categoryId == null) return

  relatedLoading.value = true
  try {
    // Lấy đủ các trang để không bỏ sót sản phẩm cùng danh mục nằm ở trang sau.
    const firstResponse = await axios.get(`${API_URL}/products?page=1`)
    const firstPayload: unknown = firstResponse.data
    const allProducts = extractList<Product>(firstPayload)
    const lastPage = extractLastPage(firstPayload)

    if (lastPage > 1) {
      const remainingPages = await Promise.allSettled(
        Array.from({ length: lastPage - 1 }, (_, index) =>
          axios.get(`${API_URL}/products?page=${index + 2}`),
        ),
      )
      for (const result of remainingPages) {
        if (result.status === 'fulfilled') {
          allProducts.push(...extractList<Product>(result.value.data))
        }
      }
    }

    const currentCategoryId = Number(categoryId)
    relatedProducts.value = allProducts
      .filter(item => item.id !== currentProduct.id)
      .filter(item => Number(item.category_id ?? item.category?.id) === currentCategoryId)
      .slice(0, 4)
  } catch (error) {
    console.error('Không tải được sản phẩm cùng danh mục:', error)
    relatedProducts.value = []
  } finally {
    relatedLoading.value = false
  }
}

function extractProduct(payload: unknown): Product | null {
  if (typeof payload !== 'object' || payload === null) return null
  const object = payload as Record<string, unknown>
  const candidate = object.product && typeof object.product === 'object'
    ? object.product as Record<string, unknown>
    : object
  if (typeof candidate.id !== 'number' || typeof candidate.name !== 'string') return null
  return candidate as unknown as Product
}

async function loadProduct() {
  closeImageViewer()
  selectedGalleryIndex.value = 0
  failedGalleryImages.value = new Set()
  descriptionExpanded.value = false
  relatedProducts.value = []

  if (productIdentifier.value === null) {
    product.value = null
    errorMessage.value = 'Đường dẫn sản phẩm không hợp lệ.'
    return
  }

  loading.value = true
  errorMessage.value = ''
  product.value = null

  try {
    const [productResult, categoryResult] = await Promise.allSettled([
  axios.get(`${API_URL}/products/${encodeURIComponent(productIdentifier.value!)}`),
  axios.get(`${API_URL}/categories`),
])

    if (productResult.status !== 'fulfilled') {
      throw productResult.reason
    }

    product.value = extractProduct(productResult.value.data)
    if (!product.value) {
      throw new Error('Dữ liệu sản phẩm không đúng định dạng.')
    }

    if (categoryResult.status === 'fulfilled') {
      const payload: unknown = categoryResult.value.data
      if (Array.isArray(payload)) {
        categories.value = payload as Category[]
      } else if (typeof payload === 'object' && payload !== null) {
        const data = (payload as Record<string, unknown>).data
        categories.value = Array.isArray(data) ? data as Category[] : []
      }
    }

    void loadRelatedProducts()
  } catch (error) {
    console.error('Không tải được chi tiết sản phẩm:', error)
    if (axios.isAxiosError(error) && error.response?.status === 404) {
      errorMessage.value = 'Không tìm thấy sản phẩm này.'
    } else {
      errorMessage.value = 'Không tải được chi tiết sản phẩm. Hãy kiểm tra kết nối Backend.'
    }
  } finally {
    loading.value = false
  }
}

function openImageViewer() {
  if (!selectedGalleryImage.value?.src || failedGalleryImages.value.has(selectedGalleryImage.value.index)) return
  imageViewerOpen.value = true
  resetImageZoom()
}

function closeImageViewer() {
  imageViewerOpen.value = false
  resetImageZoom()
}

function resetImageZoom() {
  zoomLevel.value = 1
  panX.value = 0
  panY.value = 0
  isDragging.value = false
  clearViewerPointers()
}

function clampPan() {
  const stage = imageViewerStage.value
  const image = imageViewerImage.value
  if (!stage || !image || zoomLevel.value <= 1) {
    panX.value = 0
    panY.value = 0
    return
  }

  // Limit movement so the zoomed image stays reachable inside the viewer.
  const maxX = Math.max(0, (image.clientWidth * zoomLevel.value - stage.clientWidth) / 2)
  const maxY = Math.max(0, (image.clientHeight * zoomLevel.value - stage.clientHeight) / 2)
  panX.value = Math.max(-maxX, Math.min(maxX, panX.value))
  panY.value = Math.max(-maxY, Math.min(maxY, panY.value))
}

function zoomIn() {
  zoomLevel.value = Math.min(zoomLevel.value + 0.25, 3)
  requestAnimationFrame(clampPan)
}

function zoomOut() {
  zoomLevel.value = Math.max(zoomLevel.value - 0.25, 1)
  if (zoomLevel.value === 1) {
    panX.value = 0
    panY.value = 0
  } else {
    requestAnimationFrame(clampPan)
  }
}

function handleImageWheel(event: WheelEvent) {
  if (event.deltaY < 0) zoomIn()
  else zoomOut()
}

function pointerDistance() {
  const points = Array.from(activePointers.values())
  const first = points[0]
  const second = points[1]
  if (!first || !second) return 0

  const dx = second.x - first.x
  const dy = second.y - first.y
  return Math.hypot(dx, dy)
}

function handleViewerPointerDown(event: PointerEvent) {
  activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY })
  const stage = event.currentTarget as HTMLElement
  try {
    stage.setPointerCapture(event.pointerId)
  } catch {
    // Some mobile browsers may not allow capture for every pointer.
  }

  if (activePointers.size >= 2) {
    isDragging.value = false
    pinchStartDistance = pointerDistance()
    pinchStartZoom = zoomLevel.value
    return
  }

  if (zoomLevel.value > 1) {
    isDragging.value = true
    dragStartX.value = event.clientX
    dragStartY.value = event.clientY
    panStartX.value = panX.value
    panStartY.value = panY.value
  }
}

function handleViewerPointerMove(event: PointerEvent) {
  if (!activePointers.has(event.pointerId)) return
  activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY })

  if (activePointers.size >= 2) {
    const distance = pointerDistance()
    if (pinchStartDistance > 0) {
      zoomLevel.value = Math.min(
        3,
        Math.max(1, pinchStartZoom * (distance / pinchStartDistance)),
      )
      if (zoomLevel.value === 1) {
        panX.value = 0
        panY.value = 0
      } else {
        clampPan()
      }
    }
    return
  }

  if (isDragging.value && zoomLevel.value > 1) {
    panX.value = panStartX.value + event.clientX - dragStartX.value
    panY.value = panStartY.value + event.clientY - dragStartY.value
    clampPan()
  }
}

function handleViewerPointerUp(event: PointerEvent) {
  // Ignore the follow-up lostpointercapture event for a pointer already released.
  if (!activePointers.has(event.pointerId)) return
  activePointers.delete(event.pointerId)

  if (activePointers.size === 1) {
    const remaining = Array.from(activePointers.entries())[0]

    if (remaining) {
      const [, point] = remaining
      // Continue dragging smoothly if one finger remains after a pinch.
      dragStartX.value = point.x
      dragStartY.value = point.y
      panStartX.value = panX.value
      panStartY.value = panY.value
      isDragging.value = zoomLevel.value > 1
    }
  } else {
    isDragging.value = false
    pinchStartDistance = 0
  }
}

function clearViewerPointers() {
  activePointers.clear()
  isDragging.value = false
  pinchStartDistance = 0
}

function handleViewerKeydown(event: KeyboardEvent) {
  if (!imageViewerOpen.value) return
  if (event.key === 'Escape') closeImageViewer()
  if (event.key === '+' || event.key === '=') zoomIn()
  if (event.key === '-') zoomOut()
}

function goBack() {
  if (window.history.length > 1) {
    window.history.back()
  } else {
    window.location.href = '/search-products'
  }
}

watch(productIdentifier, loadProduct)
onMounted(() => {
  window.addEventListener('keydown', handleViewerKeydown)
  loadProduct()
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleViewerKeydown)
})
</script>

<template>
  <main class="detail-page">
    <section class="detail-hero">
      <div class="detail-container">
        <router-link to="/search-products" class="detail-back">
          <span aria-hidden="true">←</span> Quay lại bộ sưu tập
        </router-link>
        <span class="detail-kicker">DUY CHÂN DUNG · GALLERY</span>
        <h1>Chi tiết <span>tác phẩm</span></h1>
        <p>Khám phá câu chuyện và thông tin của tác phẩm bạn yêu thích.</p>
      </div>
    </section>

    <section class="detail-content">
      <div v-if="loading" class="detail-state">Đang tải thông tin sản phẩm...</div>

      <div v-else-if="errorMessage" class="detail-state detail-error">
        <h2>Không thể mở sản phẩm</h2>
        <p>{{ errorMessage }}</p>
        <router-link to="/search-products" class="detail-primary-button">Về danh sách sản phẩm</router-link>
      </div>

      <div v-else-if="product" class="detail-layout">
        <div class="detail-image-panel">
          <div v-if="galleryImages.length" class="detail-gallery">
            <button
              v-if="selectedGalleryImage && !failedGalleryImages.has(selectedGalleryImage.index)"
              type="button"
              class="detail-image-trigger"
              :aria-label="`Phóng to ${selectedGalleryImage.alt}`"
              title="Bấm để phóng to ảnh"
              @click="openImageViewer"
            >
              <img
                :src="selectedGalleryImage.src"
                :alt="selectedGalleryImage.alt"
                class="detail-image"
                @error="markGalleryImageFailed(selectedGalleryImage.index)"
              />
              <span class="detail-image-hint">⌕ Bấm để xem ảnh lớn</span>
            </button>
            <div v-else class="detail-no-image">Tác phẩm chưa có ảnh</div>

            <div class="detail-gallery-thumbnails" aria-label="Các ảnh của tác phẩm">
              <template v-for="image in galleryImages" :key="image.index">
                <button
                  v-if="!failedGalleryImages.has(image.index)"
                  type="button"
                  class="detail-gallery-thumb"
                  :class="{ 'detail-gallery-thumb-active': selectedGalleryIndex === image.index }"
                  :aria-label="`Chọn ${image.label}`"
                  :aria-pressed="selectedGalleryIndex === image.index"
                  @click="selectGalleryImage(image.index)"
                >
                  <img
                    :src="image.src"
                    :alt="image.alt"
                    loading="lazy"
                    @error="markGalleryImageFailed(image.index)"
                  />
                  <span>{{ image.label }}</span>
                </button>
                <div v-else class="detail-gallery-thumb detail-gallery-thumb-missing" aria-disabled="true">
                  <span>{{ image.index === 0 ? 'Ảnh chính' : `Ảnh phụ ${image.index}` }}</span>
                </div>
              </template>
            </div>
          </div>
          <div v-else class="detail-no-image">Tác phẩm chưa có ảnh</div>
        </div>

        <article class="detail-info-panel">
          <span class="detail-category">{{ getCategoryName(product) }}</span>
          <p class="detail-eyebrow">TÁC PHẨM #{{ product.id }}</p>
          <h2>{{ product.name }}</h2>
          <div class="detail-divider"></div>
          <div class="detail-actions">
            <router-link to="/#contact" class="detail-primary-button">Liên hệ đặt vẽ <span aria-hidden="true">↗</span></router-link>
            <button type="button" class="detail-secondary-button" @click="goBack">Quay lại</button>
          </div>
        </article>

        <section class="detail-description-panel" aria-labelledby="product-description-title">
          <h3 id="product-description-title">Thông tin tác phẩm</h3>
          <div
            ref="descriptionContentRef"
            class="detail-description"
            :class="{ 'detail-description-collapsed': !descriptionExpanded }"
            v-html="renderedDescription || '<p>Chưa có mô tả cho tác phẩm này.</p>'"
            @load.capture="measureDescriptionOverflow"
          ></div>
          <button
            v-if="isDescriptionLong"
            type="button"
            class="detail-description-toggle"
            :aria-expanded="descriptionExpanded"
            @click="descriptionExpanded = !descriptionExpanded"
          >
            {{ descriptionExpanded ? 'Thu gọn' : 'Xem thêm' }}
            <span aria-hidden="true">{{ descriptionExpanded ? '↑' : '↓' }}</span>
          </button>
        </section>
      </div>

      <section v-if="product" class="related-products-section" aria-labelledby="related-products-title">
        <div class="related-products-heading">
          <div>
            <p class="related-products-eyebrow">CÓ THỂ BẠN SẼ THÍCH</p>
            <h2 id="related-products-title">Tác phẩm cùng danh mục</h2>
          </div>
          <span class="related-products-count">Tối đa 4 tác phẩm</span>
        </div>

        <p v-if="relatedLoading" class="related-products-state">Đang tìm tác phẩm tương tự...</p>
        <div v-else-if="relatedProducts.length" class="related-products-grid">
          <router-link
            v-for="item in relatedProducts"
            :key="item.id"
            :to="item.slug
  ? { name: 'product-detail', params: { slug: item.slug } }
  : { name: 'product-detail-legacy', params: { id: item.id } }"
            class="related-product-card"
            :aria-label="`Xem chi tiết ${item.name}`"
          >
            <div class="related-product-image-wrap">
              <img
                v-if="item.img"
                :src="imageUrl(item.img)"
                :alt="item.name"
                class="related-product-image"
                loading="lazy"
              />
              <div v-else class="related-product-no-image">Chưa có ảnh</div>
            </div>
            <div class="related-product-info">
              <span class="related-product-category">{{ getCategoryName(item) }}</span>
              <h3>{{ item.name }}</h3>
              <span class="related-product-link">Xem tác phẩm <span aria-hidden="true">↗</span></span>
            </div>
          </router-link>
        </div>
        <p v-else-if="!relatedLoading" class="related-products-state">Chưa có tác phẩm khác trong danh mục này.</p>
      </section>
    </section>

    <!-- Trình xem ảnh toàn màn hình -->
    <div
      v-if="imageViewerOpen && selectedGalleryImage && !failedGalleryImages.has(selectedGalleryImage.index)"
      class="image-viewer-overlay"
      role="dialog"
      aria-modal="true"
      :aria-label="`Xem ảnh lớn: ${selectedGalleryImage?.alt ?? 'Ảnh sản phẩm'}`"
      @click.self="closeImageViewer"
      @wheel.prevent="handleImageWheel"
    >
      <button
        type="button"
        class="image-viewer-close"
        aria-label="Đóng ảnh"
        title="Đóng (Esc)"
        @click="closeImageViewer"
      >×</button>

      <div class="image-viewer-toolbar" @click.stop>
        <button type="button" :disabled="zoomLevel <= 1" aria-label="Thu nhỏ ảnh" title="Thu nhỏ" @click="zoomOut">−</button>
        <span class="image-viewer-zoom-label">{{ Math.round(zoomLevel * 100) }}%</span>
        <button type="button" :disabled="zoomLevel >= 3" aria-label="Phóng to ảnh" title="Phóng to" @click="zoomIn">+</button>
        <button type="button" class="image-viewer-reset" @click="resetImageZoom">Đặt lại</button>
      </div>

      <div
        ref="imageViewerStage"
        class="image-viewer-stage"
        :class="{ 'is-dragging': isDragging, 'is-zoomed': zoomLevel > 1 }"
        @pointerdown="handleViewerPointerDown"
        @pointermove="handleViewerPointerMove"
        @pointerup="handleViewerPointerUp"
        @pointercancel="handleViewerPointerUp"
        @lostpointercapture="handleViewerPointerUp"
      >
        <img
          :src="selectedGalleryImage.src"
          :alt="selectedGalleryImage.alt"
          ref="imageViewerImage"
          class="image-viewer-image"
          :class="{ 'is-dragging': isDragging, 'is-zoomed': zoomLevel > 1 }"
          :style="{ transform: `translate(${panX}px, ${panY}px) scale(${zoomLevel})` }"
          draggable="false"
        />
      </div>

      <p class="image-viewer-hint-text">
        Máy tính: dùng nút +/− hoặc cuộn chuột. Điện thoại: chụm hai ngón để zoom, kéo để di chuyển ảnh. Kéo ảnh khi đã phóng to để xem các vùng khác. Nhấn Esc để đóng.
      </p>
    </div>
  </main>
</template>

<style scoped>
.detail-page,
.detail-page * {
  box-sizing: border-box;
  font-family: Arial, "Segoe UI", Tahoma, sans-serif;
}
.detail-page { min-height: 70vh; background: #f8fafc; color: #1e293b; }
.detail-container, .detail-content { width: 100%; max-width: 1240px; margin: 0 auto; }
.detail-hero { padding: 36px 24px 48px; color: #fff; background: radial-gradient(circle at 88% 10%, rgb(220 38 38 / 20%), transparent 32%), linear-gradient(125deg, #0f172a, #1e293b 65%, #3b1724); }
.detail-back { display: inline-flex; align-items: center; gap: 8px; color: #cbd5e1; font-size: 13px; text-decoration: none; }
.detail-back:hover { color: #fff; }
.detail-kicker { display: block; margin-top: 30px; color: #f87171; font-size: 10px; font-weight: 800; letter-spacing: 2px; }
.detail-hero h1 { margin: 10px 0 8px; font-size: clamp(30px, 4vw, 44px); font-weight: 800; letter-spacing: -1px; }
.detail-hero h1 span { color: #fb7185; font-weight: 500; }
.detail-hero p { margin: 0; color: #cbd5e1; font-size: 13px; line-height: 1.8; }
.detail-content { padding: 42px 24px 64px; }
.detail-layout { display: grid; grid-template-columns: minmax(0, 1.08fr) minmax(0, .92fr); grid-template-rows: auto auto; gap: 22px 34px; align-items: start; }
.detail-image-panel { grid-column: 1; grid-row: 1; }
.detail-info-panel { grid-column: 2; grid-row: 1; }
.detail-description-panel { grid-column: 1 / -1; grid-row: 2; min-width: 0; padding: 24px 26px; border: 1px solid #e2e8f0; border-radius: 20px; background: #fff; box-shadow: 0 8px 25px rgb(15 23 42 / 4%); }
.detail-description-panel h3 { margin: 0 0 14px; color: #334155; font-size: 16px; font-weight: 800; line-height: 1.4; }
.detail-image-panel { display: flex; min-height: 420px; align-items: center; justify-content: center; overflow: hidden; padding: 18px; border: 1px solid #e2e8f0; border-radius: 22px; background: #cbd5e1; box-shadow: 0 12px 28px rgb(15 23 42 / 8%); }
.detail-gallery { width: 100%; min-width: 0; }
.detail-gallery-thumbnails { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px; width: 100%; margin-top: 14px; }
.detail-gallery-thumb { position: relative; display: flex; min-width: 0; min-height: 82px; flex-direction: column; align-items: center; justify-content: center; gap: 5px; padding: 5px; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 9px; background: #fff; color: #64748b; cursor: pointer; }
.detail-gallery-thumb img { display: block; width: 100%; height: 65px; object-fit: contain; }
.detail-gallery-thumb > span { max-width: 100%; overflow: hidden; font-size: 10px; line-height: 1.3; text-align: center; text-overflow: ellipsis; white-space: nowrap; }
.detail-gallery-thumb-active { border-color: #dc2626; box-shadow: 0 0 0 1px #dc2626; color: #b91c1c; }
.detail-gallery-thumb-missing { border-style: dashed; background: #f8fafc; cursor: default; }
.detail-image-trigger { position: relative; display: flex; width: 100%; align-items: center; justify-content: center; padding: 0; overflow: hidden; border: 0; border-radius: 14px; background: transparent; cursor: zoom-in; }
.detail-image { display: block; width: 100%; max-height: 650px; border-radius: 14px; object-fit: contain; }
.detail-image-hint { position: absolute; right: 12px; bottom: 12px; padding: 8px 11px; border: 1px solid rgb(255 255 255 / 22%); border-radius: 999px; background: rgb(15 23 42 / 78%); color: #fff; font-size: 11px; opacity: 0; transform: translateY(4px); transition: opacity .2s, transform .2s; pointer-events: none; }
.detail-image-trigger:hover .detail-image-hint, .detail-image-trigger:focus-visible .detail-image-hint { opacity: 1; transform: translateY(0); }
.detail-image-trigger:focus-visible { outline: 3px solid #fb7185; outline-offset: 3px; }
.image-viewer-overlay { position: fixed; z-index: 9999; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 64px 20px 42px; background: rgb(2 6 23 / 94%); backdrop-filter: blur(8px); }
.image-viewer-close { position: absolute; top: 18px; right: 22px; display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid rgb(255 255 255 / 22%); border-radius: 50%; background: rgb(255 255 255 / 10%); color: #fff; font-size: 30px; line-height: 1; cursor: pointer; transition: background .2s, transform .2s; }
.image-viewer-close:hover { transform: rotate(90deg); background: rgb(255 255 255 / 20%); }
.image-viewer-toolbar { position: absolute; z-index: 2; top: 20px; left: 50%; display: flex; align-items: center; gap: 9px; padding: 7px; border: 1px solid rgb(255 255 255 / 18%); border-radius: 999px; background: rgb(15 23 42 / 92%); transform: translateX(-50%); }
.image-viewer-toolbar button { display: grid; min-width: 36px; height: 36px; place-items: center; padding: 0 10px; border: 0; border-radius: 999px; background: #dc2626; color: #fff; font-size: 21px; cursor: pointer; transition: background .2s; }
.image-viewer-toolbar button:hover:not(:disabled) { background: #b91c1c; }
.image-viewer-toolbar button:disabled { opacity: .35; cursor: not-allowed; }
.image-viewer-toolbar .image-viewer-reset { padding: 0 13px; background: rgb(255 255 255 / 12%); font-size: 12px; font-weight: 700; }
.image-viewer-zoom-label { min-width: 48px; color: #fff; font-size: 12px; font-weight: 800; text-align: center; }
.image-viewer-stage { display: flex; width: 100%; height: 100%; min-height: 0; align-items: center; justify-content: center; overflow: hidden; touch-action: none; overscroll-behavior: contain; cursor: grab; user-select: none; -webkit-user-select: none; }
.image-viewer-stage.is-zoomed { cursor: grab; }
.image-viewer-stage.is-dragging { cursor: grabbing; }
.image-viewer-image { display: block; max-width: min(92vw, 1200px); max-height: 78vh; border-radius: 8px; object-fit: contain; transform-origin: center center; cursor: zoom-in; pointer-events: none; user-select: none; -webkit-user-select: none; -webkit-user-drag: none; transition: transform .12s ease-out; }
.image-viewer-image.is-zoomed { cursor: grab; }
.image-viewer-image.is-dragging { cursor: grabbing; transition: none; }
.image-viewer-hint-text { position: absolute; bottom: 13px; margin: 0; color: #cbd5e1; font-size: 11px; text-align: center; line-height: 1.6; }
@media (max-width: 600px) { .image-viewer-overlay { padding: 78px 8px 52px; height: 100dvh; } .image-viewer-stage { min-height: 0; } .image-viewer-image { max-width: 98vw; max-height: 74dvh; } .image-viewer-close { top: 18px; right: 12px; width: 38px; height: 38px; } .image-viewer-toolbar { top: 18px; left: 12px; transform: none; gap: 5px; } .image-viewer-toolbar button { min-width: 32px; height: 32px; } .image-viewer-toolbar .image-viewer-reset { padding: 0 9px; } .image-viewer-image { max-width: 96vw; max-height: 75vh; } .image-viewer-hint-text { right: 12px; left: 12px; bottom: 10px; font-size: 10px; } }
.detail-no-image { display: grid; width: 100%; min-height: 380px; place-items: center; border-radius: 14px; background: #111827; color: #cbd5e1; font-size: 14px; }
.related-products-section { margin-top: 54px; padding-top: 34px; border-top: 1px solid #e2e8f0; }
.related-products-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
.related-products-eyebrow { margin: 0 0 7px; color: #dc2626; font-size: 10px; font-weight: 800; letter-spacing: 1.7px; }
.related-products-heading h2 { margin: 0; color: #0f172a; font-size: clamp(22px, 3vw, 30px); font-weight: 800; line-height: 1.25; }
.related-products-count { flex-shrink: 0; padding: 7px 10px; border: 1px solid #e2e8f0; border-radius: 999px; color: #64748b; font-size: 11px; }
.related-products-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; }
.related-product-card { display: flex; min-width: 0; flex-direction: column; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; color: inherit; text-decoration: none; box-shadow: 0 5px 16px rgb(15 23 42 / 4%); transition: transform .2s, box-shadow .2s, border-color .2s; }
.related-product-card:hover { transform: translateY(-4px); border-color: #fecaca; box-shadow: 0 12px 25px rgb(15 23 42 / 9%); }
.related-product-image-wrap { display: flex; height: 190px; align-items: center; justify-content: center; overflow: hidden; background: #f1f5f9; }
.related-product-image { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform .3s; }
.related-product-card:hover .related-product-image { transform: scale(1.04); }
.related-product-no-image { color: #94a3b8; font-size: 12px; }
.related-product-info { display: flex; flex: 1; flex-direction: column; align-items: flex-start; padding: 15px; }
.related-product-category { margin-bottom: 8px; color: #be123c; font-size: 10px; font-weight: 800; }
.related-product-info h3 { display: -webkit-box; min-height: 42px; margin: 0; overflow: hidden; color: #0f172a; font-size: 15px; font-weight: 800; line-height: 1.4; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.related-product-link { display: inline-flex; align-items: center; gap: 6px; margin-top: 14px; color: #dc2626; font-size: 12px; font-weight: 800; }
.related-products-state { margin: 0; padding: 24px; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; font-size: 13px; text-align: center; }
.detail-info-panel { padding: 30px; border: 1px solid #e2e8f0; border-radius: 20px; background: #fff; box-shadow: 0 8px 25px rgb(15 23 42 / 4%); }
.detail-category { display: inline-block; padding: 7px 10px; border-radius: 7px; background: #fff1f2; color: #be123c; font-size: 11px; font-weight: 700; }
.detail-eyebrow { margin: 23px 0 7px; color: #94a3b8; font-size: 10px; font-weight: 800; letter-spacing: 1.5px; }
.detail-info-panel h2 { margin: 0; color: #0f172a; font-size: clamp(25px, 3vw, 34px); font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
.detail-divider { height: 1px; margin: 23px 0; background: #e2e8f0; }
.detail-info-panel h3 { margin: 0 0 10px; color: #334155; font-size: 14px; font-weight: 800; }
.detail-description { margin: 0; color: #64748b; font-size: 14px; line-height: 1.9; overflow-wrap: anywhere; }
.detail-description :deep(p) { margin: 0 0 12px; }
.detail-description :deep(strong), .detail-description :deep(b) { color: #334155; font-weight: 800 !important; }
.detail-description :deep(h2) { margin: 22px 0 10px; color: #0f172a; font-size: 22px; font-weight: 800; line-height: 1.4; }
.detail-description :deep(h3) { margin: 18px 0 8px; color: #334155; font-size: 18px; font-weight: 800; line-height: 1.4; }
.detail-description :deep(ul), .detail-description :deep(ol) { margin: 0 0 14px; padding-left: 24px; }
.detail-description :deep(ul) { list-style: disc; }
.detail-description :deep(ol) { list-style: decimal; }
.detail-description :deep(a) { color: #dc2626; text-decoration: underline; }
.detail-description :deep(img) { display: block; max-width: 100%; height: auto; margin: 16px auto; border-radius: 10px; }
.detail-description :deep(figure) { margin: 18px 0; text-align: center; }
.detail-description :deep(figcaption) { margin-top: 6px; color: #64748b; font-size: 12px; font-style: italic; }
.detail-description-collapsed { max-height: calc(1.9em * 4); overflow: hidden; }
.detail-description-toggle { display: inline-flex; align-items: center; gap: 7px; margin-top: 10px; padding: 5px 0; border: 0; background: transparent; color: #dc2626; font-size: 13px; font-weight: 800; cursor: pointer; }
.detail-description-toggle:hover { color: #991b1b; text-decoration: underline; }
.detail-description-toggle:focus-visible { outline: 2px solid #dc2626; outline-offset: 4px; border-radius: 3px; }
.detail-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 28px; }
.detail-primary-button, .detail-secondary-button { display: inline-flex; min-height: 42px; align-items: center; justify-content: center; gap: 8px; padding: 11px 16px; border: 1px solid transparent; border-radius: 9px; font-size: 12px; font-weight: 800; text-decoration: none; cursor: pointer; transition: background .2s, transform .2s; }
.detail-primary-button { background: #dc2626; color: #fff; }
.detail-primary-button:hover { transform: translateY(-2px); background: #b91c1c; }
.detail-secondary-button { border-color: #e2e8f0; background: #fff; color: #475569; }
.detail-secondary-button:hover { background: #f8fafc; }
.detail-state { max-width: 760px; margin: 0 auto; padding: 60px 22px; color: #64748b; text-align: center; }
.detail-state h2 { margin: 0 0 8px; color: #0f172a; font-size: 22px; }
.detail-state p { margin: 0 0 20px; line-height: 1.7; }
.detail-error { border: 1px solid #fecaca; border-radius: 16px; background: #fff; }
@media (max-width: 850px) {
  .related-products-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .related-product-image-wrap { height: 210px; }
  .detail-layout { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto; gap: 20px; }
  .detail-image-panel, .detail-info-panel, .detail-description-panel { grid-column: 1; grid-row: auto; }
  .detail-image-panel { min-height: 300px; }
  .detail-image { max-height: 520px; }
  .detail-info-panel { padding: 23px; }
  .detail-description-panel { padding: 23px; }
}
@media (max-width: 480px) { .detail-gallery-thumbnails { gap: 6px; } .detail-gallery-thumb { min-height: 68px; padding: 4px; } .detail-gallery-thumb img { height: 48px; } .detail-gallery-thumb > span { font-size: 9px; }
  .related-products-section { margin-top: 38px; padding-top: 26px; }
  .related-products-heading { align-items: flex-start; flex-direction: column; gap: 10px; }
  .related-products-grid { gap: 11px; }
  .related-product-image-wrap { height: 145px; }
  .related-product-info { padding: 11px; }
  .related-product-info h3 { font-size: 13px; }
  .detail-hero { padding: 25px 17px 34px; }
  .detail-content { padding: 25px 15px 42px; }
  .detail-image-panel { min-height: 240px; padding: 10px; border-radius: 16px; }
  .detail-info-panel { padding: 19px; }
  .detail-description-panel { padding: 19px; }
  .detail-actions { flex-direction: column; }
  .detail-primary-button, .detail-secondary-button { width: 100%; }
}
</style>
