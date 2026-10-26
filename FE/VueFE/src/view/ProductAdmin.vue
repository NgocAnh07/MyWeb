<script setup lang="ts">

import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'

import { useRouter } from 'vue-router'

interface Category {

  id: number

  name: string

  is_active?: boolean | number | string

}

interface ProductImage {
  id: number
  image: string
  sort_order?: number
}

interface Product {

  id: number

  name: string

  description: string

  img: string | null

  category_id?: number | null

  category?: Category

  images?: ProductImage[]

}

const router = useRouter()

const adminUsername = ref('Admin')

const products = ref<Product[]>([])

const categories = ref<Category[]>([])

const loading = ref(false)

const saving = ref(false)

const errorMessage = ref('')

const successMessage = ref('')

const searchQuery = ref('')

const showModal = ref(false)

const editingId = ref<number | null>(null)

const selectedImage = ref<File | null>(null)

const imagePreview = ref('')

const fileInput = ref<HTMLInputElement | null>(null)
const additionalImagesInput = ref<HTMLInputElement | null>(null)
const selectedAdditionalImages = ref<File[]>([])
const additionalImagePreviews = ref<string[]>([])
const editorRef = ref<HTMLDivElement | null>(null)
const inlineImageInput = ref<HTMLInputElement | null>(null)
const inlineImageUploading = ref(false)
let savedEditorRange: Range | null = null

const form = ref({

  name: '',

  description: '',

  category_id: '',

})

const currentPage = ref(1)
const pageSize = 20
const totalProducts = ref(0)

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[đĐ]/g, character => character === 'Đ' ? 'D' : 'd')
    .toLocaleLowerCase('vi-VN')
    .trim()
}

const filteredProducts = computed(() => {
  const keyword = normalizeSearchText(searchQuery.value)
  if (!keyword) return products.value

  return products.value.filter(product => {
    const categoryName = product.category?.name ?? getCategoryName(product.category_id)
    const searchableText = normalizeSearchText([
      product.name ?? '',
      categoryName,
      textOnly(product.description ?? ''),
      String(product.id),
    ].join(' '))

    const words = keyword.split(/\s+/).filter(Boolean)
    return words.every(word => searchableText.includes(word))
  })
})

const lastPage = computed(() =>
  Math.max(1, Math.ceil(filteredProducts.value.length / pageSize)),
)

const paginatedProducts = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  return filteredProducts.value.slice(start, start + pageSize)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function loadAdminUsername() {
  try {
    const savedUser = localStorage.getItem('auth_user')
    if (!savedUser) {
      adminUsername.value = 'Admin'
      return
    }

    const user: unknown = JSON.parse(savedUser)
    if (
      typeof user === 'object' &&
      user !== null &&
      'username' in user &&
      typeof user.username === 'string' &&
      user.username.trim()
    ) {
      adminUsername.value = user.username
    } else {
      adminUsername.value = 'Admin'
    }
  } catch {
    adminUsername.value = 'Admin'
  }
}

function getConfig() {

  const token = localStorage.getItem('auth_token')

  return {

    headers: {

      Accept: 'application/json',

      ...(token ? { Authorization: `Bearer ${token}` } : {}),

    },

  }

}

function getErrorMessage(error: unknown): string {

  if (axios.isAxiosError(error)) {

    const status = error.response?.status

    const responseData = error.response?.data

    if (status === 401 || status === 403) {

      return 'Phiên đăng nhập hết hạn hoặc tài khoản không có quyền quản trị.'

    }

    if (status === 422) {

      const errors = responseData?.errors as

        | Record<string, unknown>

        | undefined

      if (errors) {

        const firstError = Object.values(errors)[0]

        if (Array.isArray(firstError) && typeof firstError[0] === 'string') {

          return firstError[0]

        }

      }

      return responseData?.message ?? 'Dữ liệu nhập vào chưa hợp lệ.'

    }

    if (typeof responseData?.message === 'string') {

      return responseData.message

    }

    if (!error.response) {

      return 'Không kết nối được Laravel. Hãy kiểm tra Backend.'

    }

  }

  return 'Đã xảy ra lỗi. Hãy kiểm tra API và thử lại.'

}

function getCategoryName(categoryId: number | null | undefined): string {

  if (categoryId == null) return 'Chưa phân loại'

  return categories.value.find(category => category.id === categoryId)?.name

    ?? `Danh mục #${categoryId}`

}

function extractList<T>(payload: unknown): T[] {

  if (Array.isArray(payload)) return payload as T[]

  if (typeof payload === 'object' && payload !== null) {

    const data = (payload as Record<string, unknown>).data

    if (Array.isArray(data)) return data as T[]

  }

  return []

}

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''

  // Nếu API đã trả URL đầy đủ, dùng trực tiếp.
  if (/^https?:\/\//i.test(path)) return path

  // Hỗ trợ cả /storage/products/a.jpg và products/a.jpg trong DB.
  const normalizedPath = path.replace(/^\/+/, '')
  if (normalizedPath.startsWith('storage/')) {
    return `${STORAGE_BASE_URL}/${normalizedPath}`
  }

  return `${STORAGE_BASE_URL}/storage/${normalizedPath}`
}

async function loadCategories() {

  try {

    const response = await axios.get(`${API_URL}/categories`, getConfig())

    categories.value = extractList<Category>(response.data)

  } catch (error: unknown) {

    categories.value = []

    errorMessage.value =

      `Không tải được danh mục: ${getErrorMessage(error)}`

  }

}

async function loadProducts(page = 1) {
  loading.value = true
  errorMessage.value = ''

  try {
    // Laravel đang phân trang API; tải tất cả các trang trước khi phân trang
    // cục bộ 20 sản phẩm/trang để kết quả tìm kiếm không bị giới hạn ở 12 sản phẩm đầu.
    const firstResponse = await axios.get(`${API_URL}/products?page=1`, getConfig())
    const firstPayload: unknown = firstResponse.data
    const allProducts = extractList<Product>(firstPayload)

    let apiLastPage = 1
    if (typeof firstPayload === 'object' && firstPayload !== null && !Array.isArray(firstPayload)) {
      const payload = firstPayload as Record<string, unknown>
      if (typeof payload.last_page === 'number') {
        apiLastPage = Math.max(1, payload.last_page)
      } else if (typeof payload.meta === 'object' && payload.meta !== null) {
        const metaLastPage = (payload.meta as Record<string, unknown>).last_page
        if (typeof metaLastPage === 'number') apiLastPage = Math.max(1, metaLastPage)
      }
    }

    if (apiLastPage > 1) {
      const pageResults = await Promise.all(
        Array.from({ length: apiLastPage - 1 }, (_, index) =>
          axios.get(`${API_URL}/products?page=${index + 2}`, getConfig()),
        ),
      )
      for (const response of pageResults) {
        allProducts.push(...extractList<Product>(response.data))
      }
    }

    // Loại bỏ sản phẩm trùng nếu API có dữ liệu lặp giữa các trang.
    const uniqueProducts = new Map<number, Product>()
    for (const item of allProducts) uniqueProducts.set(item.id, item)
    products.value = Array.from(uniqueProducts.values())
    totalProducts.value = products.value.length

    await nextTick()
    currentPage.value = Math.min(Math.max(1, page), lastPage.value)
  } catch (error: unknown) {
    products.value = []
    totalProducts.value = 0
    currentPage.value = 1
    errorMessage.value = getErrorMessage(error)
  } finally {
    loading.value = false
  }
}

function changePage(page: number) {
  if (page < 1 || page > lastPage.value) return
  currentPage.value = page
}

function clearImagePreview() {
  if (imagePreview.value.startsWith('blob:')) {
    URL.revokeObjectURL(imagePreview.value)
  }
  imagePreview.value = ''
}

function clearAdditionalImagePreviews() {
  for (const preview of additionalImagePreviews.value) {
    if (preview.startsWith('blob:')) URL.revokeObjectURL(preview)
  }
  additionalImagePreviews.value = []
}

function onAdditionalImagesChange(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''

  if (files.length === 0) return
  if (files.length > 3) {
    errorMessage.value = 'Mỗi sản phẩm chỉ được chọn tối đa 3 ảnh phụ.'
    return
  }

  const invalidFile = files.find(file => !file.type.startsWith('image/'))
  if (invalidFile) {
    errorMessage.value = 'Tất cả ảnh phụ phải là file ảnh hợp lệ.'
    return
  }

  const oversizedFile = files.find(file => file.size > 5 * 1024 * 1024)
  if (oversizedFile) {
    errorMessage.value = 'Mỗi ảnh phụ không được vượt quá 5 MB.'
    return
  }

  clearAdditionalImagePreviews()
  selectedAdditionalImages.value = files
  additionalImagePreviews.value = files.map(file => URL.createObjectURL(file))
  errorMessage.value = ''
}



function textOnly(value: string): string {
  const template = document.createElement('template')
  template.innerHTML = value
  return (template.content.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function escapeAttribute(value: string): string {
  return escapeHtml(value)
}

function normalizeEditorHtml(value: string): string {
  const trimmed = value.trim()
  if (!trimmed) return ''
  if (/<\s*(p|h[2-6]|ul|ol|li|strong|b|em|i|u|blockquote|a|img|figure|br|hr)\b/i.test(trimmed)) {
    return trimmed
  }
  return trimmed.split(/\n\s*\n/)
    .map(paragraph => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('')
}

function syncEditorContent() {
  form.value.description = editorRef.value?.innerHTML.trim() ?? ''
}

async function applyEditorHtml(html: string) {
  await nextTick()
  const editor = editorRef.value
  if (!editor) return
  const template = document.createElement('template')
  template.innerHTML = normalizeEditorHtml(html)
  template.content.querySelectorAll('img[src]').forEach(image => {
    const source = image.getAttribute('src') ?? ''
    if (source.startsWith('/storage/') || source.startsWith('storage/')) {
      image.setAttribute('src', imageUrl(source))
    }
  })
  editor.innerHTML = template.innerHTML
  syncEditorContent()
}

function saveEditorSelection() {
  const editor = editorRef.value
  const selection = window.getSelection()
  if (!editor || !selection || selection.rangeCount === 0) return
  const range = selection.getRangeAt(0)
  if (editor.contains(range.commonAncestorContainer)) savedEditorRange = range.cloneRange()
}

function restoreEditorSelection() {
  const editor = editorRef.value
  if (!editor) return
  editor.focus()
  const selection = window.getSelection()
  if (!selection) return
  if (savedEditorRange && editor.contains(savedEditorRange.commonAncestorContainer)) {
    selection.removeAllRanges()
    selection.addRange(savedEditorRange)
  }
  savedEditorRange = null
}

function executeEditorCommand(command: 'bold' | 'italic' | 'insertUnorderedList' | 'insertOrderedList') {
  const editor = editorRef.value
  if (!editor) return
  restoreEditorSelection()
  const selection = window.getSelection()
  if (!selection || selection.rangeCount === 0) return
  const range = selection.getRangeAt(0)
  if (!editor.contains(range.commonAncestorContainer)) return
  // execCommand preserves native contenteditable formatting and typing state.
  document.execCommand(command, false)
  syncEditorContent()
  saveEditorSelection()
}

function formatEditorBlock(tag: 'p' | 'h2' | 'h3') {
  const editor = editorRef.value
  if (!editor) return
  restoreEditorSelection()
  document.execCommand('formatBlock', false, tag)
  syncEditorContent()
  saveEditorSelection()
}


function normalizeEditorUrl(input: string): string | null {
  const value = input.trim()
  if (!value) return null

  // Chấp nhận website có sẵn http:// hoặc https://
  if (/^https?:\/\//i.test(value)) {
    try {
      const url = new URL(value)
      if (!url.hostname || !['http:', 'https:'].includes(url.protocol)) {
        return null
      }
      return url.href
    } catch {
      return null
    }
  }

  // Chấp nhận đường dẫn nội bộ
  if (
    /^\/(?!\/)/.test(value) ||
    value.startsWith('#') ||
    value.startsWith('./') ||
    value.startsWith('../')
  ) {
    return value
  }

  // Chặn các giao thức nguy hiểm như javascript:
  if (/^[a-z][a-z\d+.-]*:/i.test(value)) return null

  // Tự thêm HTTPS khi chỉ nhập tên miền
  const domain = value.startsWith('//') ? value.slice(2) : value

  if (/[\s<>"'`\\]/.test(domain)) return null

  try {
    const url = new URL(`https://${domain}`)

    if (!url.hostname.includes('.') && url.hostname !== 'localhost') {
      return null
    }

    return url.href
  } catch {
    return null
  }
}

function insertInternalLink() {
  const editor = editorRef.value
  if (!editor) return

  saveEditorSelection()

  const selection = window.getSelection()
  const selectedText = selection?.toString().trim() ?? ''

  const input = window.prompt(
    'Nhập URL website (ví dụ google.com, https://youtube.com) hoặc đường dẫn nội bộ (/products/1):',
    '',
  )

  if (!input?.trim()) return

  const url = normalizeEditorUrl(input)

  if (!url) {
    window.alert('URL không hợp lệ. Hãy nhập địa chỉ website hoặc đường dẫn nội bộ hợp lệ.')
    return
  }

  restoreEditorSelection()

  if (selectedText) {
    document.execCommand('createLink', false, url)

    const currentSelection = window.getSelection()
    const node = currentSelection?.anchorNode
    const element = node instanceof Element ? node : node?.parentElement
    const anchor = element?.closest('a')

    if (anchor && editor.contains(anchor)) {
      anchor.setAttribute('target', '_blank')
      anchor.setAttribute('rel', 'noopener noreferrer')
    }
  } else {
    const linkText = window.prompt('Nhập nội dung liên kết:', 'Xem thêm')?.trim()
    if (!linkText) return

    document.execCommand(
      'insertHTML',
      false,
      `<a href="${escapeAttribute(url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(linkText)}</a>`,
    )
  }

  syncEditorContent()
  saveEditorSelection()
}



function openInlineImagePicker() {
  saveEditorSelection()
  inlineImageInput.value?.click()
}

async function onInlineImageSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (!file) return
  if (!file.type.startsWith('image/')) {
    errorMessage.value = 'Vui lòng chọn một file ảnh hợp lệ.'
    return
  }
  if (file.size > 5 * 1024 * 1024) {
    errorMessage.value = 'Ảnh chèn trong mô tả không được vượt quá 5 MB.'
    return
  }
  const alt = window.prompt('Nhập ALT mô tả ảnh:')?.trim()
  if (!alt) {
    errorMessage.value = 'Ảnh trong mô tả cần có ALT.'
    return
  }
  errorMessage.value = ''
  inlineImageUploading.value = true
  try {
    const body = new FormData()
    body.append('image', file)
    // Route này đã được giới hạn bởi auth:sanctum và AdminMiddleware.
    const response = await axios.post(`${API_URL}/blog-content-images`, body, getConfig())
    const payload = response.data as { path?: string; url?: string; image?: { path?: string; url?: string } }
    const path = payload.path ?? payload.url ?? payload.image?.path ?? payload.image?.url
    if (!path) throw new Error('API tải ảnh không trả về đường dẫn ảnh.')
    restoreEditorSelection()
    document.execCommand('insertHTML', false,
      `<figure><img src="${escapeAttribute(imageUrl(path))}" alt="${escapeAttribute(alt)}" loading="lazy"><figcaption>${escapeHtml(alt)}</figcaption></figure><p><br></p>`)
    syncEditorContent()
  } catch (error: unknown) {
    errorMessage.value = axios.isAxiosError(error)
      ? ((error.response?.data as { message?: string } | undefined)?.message ?? 'Không tải được ảnh. Kiểm tra Backend và quyền Admin.')
      : (error instanceof Error ? error.message : 'Không tải được ảnh nội dung.')
  } finally {
    inlineImageUploading.value = false
  }
}


async function openCreateModal() {
  editingId.value = null
  form.value = { name: '', description: '', category_id: '' }
  selectedImage.value = null
  clearImagePreview()
  clearAdditionalImagePreviews()
  selectedAdditionalImages.value = []
  if (fileInput.value) fileInput.value.value = ''
  if (additionalImagesInput.value) additionalImagesInput.value.value = ''
  errorMessage.value = ''
  successMessage.value = ''
  showModal.value = true
  await applyEditorHtml('')
}

async function openEditModal(product: Product) {
  // Danh sách sản phẩm chỉ cần ảnh chính; gọi API chi tiết để lấy cả ảnh phụ.
  let detailedProduct = product
  try {
    const response = await axios.get(`${API_URL}/products/${product.id}`, getConfig())
    const payload: unknown = response.data
    let candidate: unknown = payload
    if (typeof payload === 'object' && payload !== null && 'product' in payload) {
      const wrappedProduct = (payload as { product?: unknown }).product
      if (wrappedProduct && typeof wrappedProduct === 'object') {
        candidate = wrappedProduct
      }
    }
    if (
      typeof candidate === 'object' &&
      candidate !== null &&
      'id' in candidate &&
      candidate.id === product.id
    ) {
      detailedProduct = candidate as Product
    }
  } catch {
    // Vẫn cho phép sửa thông tin sản phẩm nếu không tải được danh sách ảnh phụ.
  }

  editingId.value = product.id
  form.value = {
    name: detailedProduct.name,
    description: detailedProduct.description ?? '',
    category_id: String(detailedProduct.category_id ?? detailedProduct.category?.id ?? ''),
  }
  selectedImage.value = null
  clearImagePreview()
  imagePreview.value = imageUrl(detailedProduct.img)
  clearAdditionalImagePreviews()
  selectedAdditionalImages.value = []
  if (fileInput.value) fileInput.value.value = ''
  if (additionalImagesInput.value) additionalImagesInput.value.value = ''
  additionalImagePreviews.value = (detailedProduct.images ?? [])
    .slice(0, 3)
    .map(item => imageUrl(item.image))
    .filter(Boolean)
  errorMessage.value = ''
  successMessage.value = ''
  showModal.value = true
  await applyEditorHtml(detailedProduct.description ?? '')
}

function closeModal() {

  if (saving.value || inlineImageUploading.value) return

  showModal.value = false

  selectedImage.value = null
  clearImagePreview()
  clearAdditionalImagePreviews()
  selectedAdditionalImages.value = []
  if (fileInput.value) fileInput.value.value = ''
  if (additionalImagesInput.value) additionalImagesInput.value.value = ''
}

function onImageChange(event: Event) {

  const input = event.target as HTMLInputElement

  const file = input.files?.[0] ?? null

  selectedImage.value = null

  clearImagePreview()

  if (!file) return

  if (!file.type.startsWith('image/')) {

    errorMessage.value = 'Vui lòng chọn một file ảnh hợp lệ.'

    input.value = ''

    return

  }

  if (file.size > 5 * 1024 * 1024) {

    errorMessage.value = 'Ảnh không được vượt quá 5 MB.'

    input.value = ''

    return

  }

  errorMessage.value = ''

  selectedImage.value = file

  imagePreview.value = URL.createObjectURL(file)

}

async function saveProduct() {

  errorMessage.value = ''

  successMessage.value = ''

  if (!form.value.name.trim()) {

    errorMessage.value = 'Vui lòng nhập tên tranh.'

    return

  }

  const descriptionHtml = editorRef.value?.innerHTML.trim() ?? form.value.description.trim()
  const descriptionText = textOnly(descriptionHtml)
  const hasInlineImage = Boolean(editorRef.value?.querySelector('img'))

  if (!descriptionText && !hasInlineImage) {

    errorMessage.value = 'Vui lòng nhập mô tả tranh.'

    return

  }

  if (!form.value.category_id) {

    errorMessage.value = 'Vui lòng chọn danh mục sản phẩm.'

    return

  }

  const body = new FormData()

  body.append('name', form.value.name.trim())

  body.append('description', descriptionHtml)

  body.append('category_id', form.value.category_id)

  if (selectedImage.value) {
    body.append('img', selectedImage.value)
  }

  // Laravel nhận danh sách ảnh phụ qua trường images[]. Không gửi trường này
  // khi sửa mà người dùng không chọn bộ ảnh mới để giữ nguyên ảnh cũ.
  selectedAdditionalImages.value.forEach(file => {
    body.append('images[]', file)
  })

  saving.value = true

  try {

    if (editingId.value !== null) {

      // Laravel xử lý multipart PUT ổn định qua POST + _method.

      body.append('_method', 'PUT')

      await axios.post(

        `${API_URL}/products/${editingId.value}`,

        body,

        getConfig(),

      )

      successMessage.value = 'Cập nhật tranh thành công.'

    } else {

      if (!selectedImage.value) {

        errorMessage.value = 'Vui lòng chọn ảnh cho sản phẩm.'

        saving.value = false

        return

      }

      await axios.post(`${API_URL}/products`, body, getConfig())

      successMessage.value = 'Thêm tranh thành công.'

    }

    showModal.value = false

    selectedImage.value = null
    clearImagePreview()
    clearAdditionalImagePreviews()
    selectedAdditionalImages.value = []
    if (fileInput.value) fileInput.value.value = ''
    if (additionalImagesInput.value) additionalImagesInput.value.value = ''
    await loadProducts(1)

  } catch (error: unknown) {

    errorMessage.value = getErrorMessage(error)

  } finally {

    saving.value = false

  }

}

async function deleteProduct(product: Product) {

  const confirmed = window.confirm(

    `M có chắc chắn muốn xóa tranh "${product.name}" không?`,

  )

  if (!confirmed) return

  errorMessage.value = ''

  successMessage.value = ''

  try {

    await axios.delete(

      `${API_URL}/products/${product.id}`,

      getConfig(),

    )

    successMessage.value = 'Xóa tranh thành công.'

    const targetPage =

      products.value.length === 1 && currentPage.value > 1

        ? currentPage.value - 1

        : currentPage.value

    await loadProducts(targetPage)

  } catch (error: unknown) {

    errorMessage.value = getErrorMessage(error)

  }

}

async function handleLogout() {

  const token = localStorage.getItem('auth_token')

  try {

    if (token) {

      await axios.post(`${API_URL}/logout`, {}, getConfig())

    }

  } catch {

    // Vẫn xóa thông tin đăng nhập tại trình duyệt nếu API lỗi.

  } finally {

    localStorage.removeItem('auth_token')

    localStorage.removeItem('auth_user')

    window.dispatchEvent(new Event('auth-changed'))

    await router.replace('/login')

  }

}

onMounted(async () => {

  loadAdminUsername()

  await Promise.all([loadCategories(), loadProducts(1)])

})

onBeforeUnmount(() => {
  clearImagePreview()
  clearAdditionalImagePreviews()
})

</script>

<template>

  <div class="product-layout">

    <aside class="product-sidebar">

      <router-link to="/" class="product-brand">

        <img src="/assets/logo.jpeg" alt="Duy Chân Dung" />

        <span>

          <strong>Duy Chân Dung</strong>

          <small>ADMIN PANEL</small>

        </span>

      </router-link>

      <div class="product-menu-label">TỔNG QUAN</div>

      <nav class="product-nav">

        <router-link to="/admin" class="product-nav-item">

          <span class="product-nav-icon">▦</span>

          Dashboard

        </router-link>

        <router-link

          to="/admin/products"

          class="product-nav-item product-nav-active"

        >

          <span class="product-nav-icon">▧</span>

          Quản lý tranh

        </router-link>

        <router-link to="/admin/categories" class="product-nav-item">

          <span class="product-nav-icon">▤</span>

          Danh mục sản phẩm

        </router-link>

        
<router-link
  to="/admin/blogs"
  class="product-nav-item"
  active-class="product-nav-active"
>
  <span class="product-nav-icon">▤</span>
  Quản lý bài viết
</router-link>

        <router-link
          to="/admin/banner"
          class="product-nav-item"
          active-class="product-nav-active"
        >
          <span class="product-nav-icon">▧</span>
          Thay banner
        </router-link>


      </nav>

      <div class="product-sidebar-bottom">

        <div class="product-profile">
          <div class="product-avatar">
            {{ adminUsername.charAt(0).toUpperCase() }}
          </div>
          <div>
            <strong>{{ adminUsername }}</strong>
            <small>Quản trị viên</small>
          </div>
        </div>

        <button class="product-logout" @click="handleLogout">

          Đăng xuất

        </button>

      </div>

    </aside>

    <main class="product-main">

      <header class="product-topbar">

        <div>

          <p>Trang quản trị / Quản lý tranh</p>

          <h1>Quản lý tranh</h1>

        </div>

        <router-link to="/" class="product-view-site">

          Xem website ↗

        </router-link>

      </header>

      <section class="product-content">

        <div class="product-intro">

          <div>

            <span>QUẢN LÝ SẢN PHẨM</span>

            <h2>Danh sách tranh</h2>

            <p>Thêm, cập nhật và phân loại tranh trên website.</p>

          </div>

          <button class="product-primary-btn" @click="openCreateModal">

            ＋ Thêm tranh

          </button>

        </div>

        <div v-if="errorMessage" class="product-alert product-alert-error">

          <span>{{ errorMessage }}</span>

          <button @click="errorMessage = ''">Đóng</button>

        </div>

        <div v-if="successMessage" class="product-alert product-alert-success">

          <span>{{ successMessage }}</span>

          <button @click="successMessage = ''">Đóng</button>

        </div>

        <div class="product-summary">

          <div class="product-summary-icon">▧</div>

          <div>

            <span>Tổng số tranh</span>

            <strong>{{ totalProducts }}</strong>

          </div>

        </div>

        <section class="product-table-panel">

          <div class="product-table-toolbar">

            <div>

              <h3>Danh sách sản phẩm</h3>

              <p>Dữ liệu lấy trực tiếp từ Laravel API.</p>

            </div>

            <div class="product-search">

              <span>⌕</span>

              <input

                v-model="searchQuery"

                type="search"

                placeholder="Tìm tên tranh, danh mục hoặc mô tả..."

              />

            </div>

          </div>

          <div class="product-table-wrap">

            <table>

              <thead>

                <tr>

                  <th>Tranh</th>

                  <th>Danh mục</th>

                  <th>Mô tả</th>

                  <th>Thao tác</th>

                </tr>

              </thead>

              <tbody>

                <tr v-if="loading">

                  <td colspan="4" class="product-empty">Đang tải sản phẩm...</td>

                </tr>

                <tr v-else-if="filteredProducts.length === 0">

                  <td colspan="4" class="product-empty">

                    {{

                      searchQuery

                        ? 'Không tìm thấy sản phẩm phù hợp.'

                        : 'Chưa có sản phẩm nào.'

                    }}

                  </td>

                </tr>

                <tr v-for="product in paginatedProducts" :key="product.id">

                  <td>

                    <div class="product-name-cell">

                      <img

                        v-if="product.img"

                        :src="imageUrl(product.img)"

                        :alt="product.name"

                        class="product-thumbnail"

                      />

                      <div v-else class="product-thumbnail product-no-image">

                        Ảnh

                      </div>

                      <div>

                        <strong>{{ product.name }}</strong>

                        <small>#{{ product.id }}</small>

                      </div>

                    </div>

                  </td>

                  <td>

                    <span class="product-category-tag">

                      {{

                        product.category?.name

                          ?? getCategoryName(product.category_id)

                      }}

                    </span>

                  </td>

                  <td>

                    <div class="product-description">

                      {{ textOnly(product.description) }}

                    </div>

                  </td>

                  <td>

                    <div class="product-actions">

                      <button

                        class="product-edit-btn"

                        @click="openEditModal(product)"

                      >

                        Sửa

                      </button>

                      <button

                        class="product-delete-btn"

                        @click="deleteProduct(product)"

                      >

                        Xóa

                      </button>

                    </div>

                  </td>

                </tr>

              </tbody>

            </table>

          </div>

          <div class="product-table-footer">

            <span>

              Tìm thấy {{ filteredProducts.length }} sản phẩm
              <template v-if="searchQuery.trim()">phù hợp</template>
              · Trang {{ currentPage }} / {{ lastPage }}

            </span>

            <div class="product-pagination">

              <button

                :disabled="loading || currentPage <= 1"

                @click="changePage(currentPage - 1)"

              >

                Trước

              </button>

              <button

                :disabled="loading || currentPage >= lastPage"

                @click="changePage(currentPage + 1)"

              >

                Sau

              </button>

            </div>

          </div>

        </section>

      </section>

    </main>

    <!-- MODAL THÊM / SỬA -->

    <div

      v-if="showModal"

      class="product-modal-overlay"

      @click.self="closeModal"

    >

      <section class="product-modal" role="dialog" aria-modal="true">

        <header class="product-modal-header">

          <div>

            <span>QUẢN LÝ SẢN PHẨM</span>

            <h2>

              {{ editingId !== null ? 'Cập nhật tranh' : 'Thêm tranh mới' }}

            </h2>

          </div>

          <button

            class="product-modal-close"

            type="button"

            :disabled="saving"

            @click="closeModal"

          >

            ×

          </button>

        </header>

        <form class="product-form" @submit.prevent="saveProduct">

          <label>

            Tên tranh <span class="required">*</span>

            <input

              v-model="form.name"

              type="text"

              maxlength="255"

              placeholder="Nhập tên tranh"

              required

            />

          </label>

          <label>

            Danh mục <span class="required">*</span>

            <select v-model="form.category_id" required>

              <option value="" disabled>-- Chọn danh mục --</option>

              <option

                v-for="category in categories"

                :key="category.id"

                :value="String(category.id)"

              >

                {{ category.name }}

              </option>

            </select>

            <small v-if="categories.length === 0">

              Chưa tải được danh mục hoặc database chưa có danh mục.

            </small>

          </label>

          <div class="product-editor-field">
            <label for="product-description-editor">Mô tả sản phẩm <span class="required">*</span></label>
            <div class="product-editor-toolbar" role="toolbar" aria-label="Định dạng mô tả sản phẩm">
              <button type="button" title="Đoạn văn" @mousedown.prevent="saveEditorSelection" @click="formatEditorBlock('p')">Đoạn văn</button>
              <button type="button" title="Tiêu đề cấp 2" @mousedown.prevent="saveEditorSelection" @click="formatEditorBlock('h2')">H2</button>
              <button type="button" title="Tiêu đề cấp 3" @mousedown.prevent="saveEditorSelection" @click="formatEditorBlock('h3')">H3</button>
              <span class="product-editor-divider"></span>
              <button type="button" title="In đậm" @mousedown.prevent="saveEditorSelection" @click="executeEditorCommand('bold')"><strong>B</strong></button>
              <button type="button" title="In nghiêng" @mousedown.prevent="saveEditorSelection" @click="executeEditorCommand('italic')"><em>I</em></button>
              <button type="button" title="Danh sách gạch đầu dòng" @mousedown.prevent="saveEditorSelection" @click="executeEditorCommand('insertUnorderedList')">• Danh sách</button>
              <button type="button" title="Danh sách đánh số" @mousedown.prevent="saveEditorSelection" @click="executeEditorCommand('insertOrderedList')">1. Danh sách</button>
              <button
  type="button"
  title="Chèn liên kết website hoặc nội bộ"
  @mousedown.prevent="saveEditorSelection"
  @click="insertInternalLink"
>
  ↗ Liên kết
</button>
              <button type="button" title="Chèn ảnh có ALT" :disabled="inlineImageUploading" @mousedown.prevent="saveEditorSelection" @click="openInlineImagePicker">{{ inlineImageUploading ? 'Đang tải ảnh…' : '＋ Ảnh + ALT' }}</button>
            </div>
            <div
              id="product-description-editor"
              ref="editorRef"
              class="product-rich-editor"
              contenteditable="true"
              role="textbox"
              aria-multiline="true"
              aria-label="Mô tả sản phẩm có định dạng"
              data-placeholder="Viết mô tả sản phẩm. Dùng H2/H3 để chia mục, B để in đậm..."
              @input="syncEditorContent"
              @blur="syncEditorContent"
            ></div>
            <input ref="inlineImageInput" class="product-inline-image-input" type="file" accept="image/*" @change="onInlineImageSelected" />
            <small class="product-editor-help">Có thể định dạng chữ, tạo H2/H3, danh sách, liên kết và chèn ảnh có ALT. Ảnh tối đa 5 MB.</small>
          </div>

          <label>

            Ảnh tranh

            <input

              ref="fileInput"

              type="file"

              accept="image/*"

              @change="onImageChange"

            />

            <small>

              Chọn ảnh tối đa 2 MB.

              {{

                editingId !== null

                  ? 'Không chọn ảnh mới thì giữ nguyên ảnh hiện tại.'

                  : 'Bắt buộc chọn ảnh khi thêm mới.'

              }}

            </small>

          </label>

          <div v-if="imagePreview" class="product-image-preview">

            <img :src="imagePreview" alt="Xem trước ảnh tranh" />

          </div>

          <div class="product-additional-images-field">
            <label for="product-additional-images">Ảnh phụ (tối đa 3 ảnh)</label>
            <input
              id="product-additional-images"
              ref="additionalImagesInput"
              class="product-additional-images-input"
              type="file"
              accept="image/*"
              multiple
              @change="onAdditionalImagesChange"
            />
            <small>
              Chọn tối đa 3 ảnh, mỗi ảnh tối đa 5 MB.
              Khi sửa sản phẩm, nếu không chọn ảnh phụ mới thì ảnh phụ hiện tại được giữ nguyên.
              Nếu chọn bộ ảnh mới, bộ ảnh phụ cũ sẽ được thay thế.
            </small>

            <div v-if="additionalImagePreviews.length" class="product-additional-preview-grid">
              <div
                v-for="(preview, index) in additionalImagePreviews"
                :key="`${preview}-${index}`"
                class="product-additional-preview-item"
              >
                <img :src="preview" :alt="`Ảnh phụ ${index + 1}`" />
                <span>Ảnh phụ {{ index + 1 }}</span>
              </div>
            </div>
          </div>

          <p v-if="errorMessage" class="product-form-error">

            {{ errorMessage }}

          </p>

          <footer class="product-form-footer">

            <button

              type="button"

              class="product-cancel-btn"

              :disabled="saving || inlineImageUploading"

              @click="closeModal"

            >

              Hủy

            </button>

            <button

              type="submit"

              class="product-primary-btn"

              :disabled="saving || inlineImageUploading || categories.length === 0"

            >

              {{

                saving

                  ? 'Đang lưu...'
                  : inlineImageUploading
                    ? 'Đang tải ảnh...'

                  : editingId !== null

                    ? 'Lưu thay đổi'

                    : 'Thêm tranh'

              }}

            </button>

          </footer>

        </form>

      </section>

    </div>

  </div>

</template>

<style scoped>

.product-layout,

.product-layout * {

  box-sizing: border-box;

}

.product-layout {

  --green: #28784e;

  --green-dark: #185938;

  --bg: #f5f7f5;

  --text: #26372c;

  --muted: #89958c;

  display: flex;

  min-height: 100vh;

  background: var(--bg);

  color: var(--text);

  font-family: "Segoe UI", Tahoma, Arial, sans-serif;

  font-size: 14px;

}

.product-sidebar {

  position: sticky;

  top: 0;

  display: flex;

  flex: 0 0 245px;

  flex-direction: column;

  width: 245px;

  height: 100vh;

  padding: 25px 16px 17px;

  border-right: 1px solid #e7ece8;

  background: white;

}

.product-brand {

  display: flex;

  align-items: center;

  gap: 10px;

  margin-bottom: 38px;

  padding: 0 7px;

  color: var(--text);

  text-decoration: none;

}

.product-brand img {

  width: 42px;

  height: 42px;

  border-radius: 9px;

  object-fit: contain;

}

.product-brand strong,

.product-brand small {

  display: block;

}

.product-brand strong {

  font-size: 14px;

}

.product-brand small {

  margin-top: 4px;

  color: #929d95;

  font-size: 9px;

  letter-spacing: 1.5px;

}

.product-menu-label {

  margin: 0 10px 12px;

  color: #a0aaa3;

  font-size: 10px;

  font-weight: 800;

  letter-spacing: 1.1px;

}

.product-nav {

  display: grid;

  gap: 7px;

}

.product-nav-item {

  display: flex;

  min-height: 44px;

  align-items: center;

  gap: 12px;

  padding: 10px 12px;

  border-radius: 9px;

  color: #6d7b70;

  font-size: 12px;

  font-weight: 600;
cursor: pointer;
  text-decoration: none;

}

.product-nav-icon {

  width: 19px;

  flex-shrink: 0;

  font-size: 18px;

  text-align: center;

}

.product-nav-active {

  background: #eaf5ed;

  color: var(--green-dark);

}

.product-nav-disabled {

  opacity: 0.65;

}

.product-nav-disabled small {

  margin-left: auto;

  font-size: 9px;

}

.product-sidebar-bottom {

  margin-top: auto;

}

.product-profile {

  display: flex;

  align-items: center;

  gap: 10px;

  padding: 14px 4px;

  border-top: 1px solid #edf0ed;

}

.product-avatar {

  display: grid;

  width: 37px;

  height: 37px;

  flex-shrink: 0;

  place-items: center;

  border-radius: 11px;

  background: #deeee2;

  color: #22633e;

  font-weight: 800;

}

.product-profile strong,

.product-profile small {

  display: block;

}

.product-profile strong {

  font-size: 11px;

}

.product-profile small {

  margin-top: 4px;

  color: var(--muted);

  font-size: 10px;

}

.product-logout {

  width: 100%;

  padding: 11px;

  border: 1px solid #e8ece9;

  border-radius: 9px;

  background: white;

  color: #657369;

  cursor: pointer;

}

.product-logout:hover {

  background: #fff4f4;

  color: #c34242;

}

.product-main {

  min-width: 0;

  flex: 1;

}

.product-topbar {

  display: flex;

  min-height: 88px;

  align-items: center;

  justify-content: space-between;

  gap: 20px;

  padding: 16px 34px;

  border-bottom: 1px solid #e8ede9;

  background: white;

}

.product-topbar p {

  margin: 0 0 6px;

  color: #8b978e;

  font-size: 10px;

}

.product-topbar h1 {

  margin: 0;

  color: #23382a;

  font-size: 22px;

}

.product-view-site {

  color: #607267;

  font-size: 12px;

  text-decoration: none;

}

.product-content {

  width: 100%;

  max-width: 1450px;

  margin: 0 auto;

  padding: 28px 34px;

}

.product-intro {

  display: flex;

  align-items: center;

  justify-content: space-between;

  gap: 20px;

  margin-bottom: 24px;

}

.product-intro > div > span,

.product-modal-header span {

  color: #548665;

  font-size: 9px;

  font-weight: 800;

  letter-spacing: 1.4px;

}

.product-intro h2 {

  margin: 8px 0;

  color: #244b31;

  font-size: 25px;

}

.product-intro p {

  margin: 0;

  color: #7b897f;

  font-size: 12px;

  line-height: 1.7;

}

.product-primary-btn {

  display: inline-flex;

  min-height: 40px;

  align-items: center;

  justify-content: center;

  gap: 7px;

  padding: 10px 15px;

  border: 1px solid var(--green);

  border-radius: 8px;

  background: var(--green);

  color: white;

  font-size: 12px;

  font-weight: 700;

  cursor: pointer;

  white-space: nowrap;

}

.product-primary-btn:hover {

  background: var(--green-dark);

}

.product-primary-btn:disabled {

  opacity: 0.6;

  cursor: wait;

}

.product-alert {

  display: flex;

  align-items: center;

  justify-content: space-between;

  gap: 12px;

  margin-bottom: 15px;

  padding: 12px 15px;

  border: 1px solid;

  border-radius: 9px;

  font-size: 12px;

  line-height: 1.6;

}

.product-alert button {

  flex-shrink: 0;

  border: 0;

  background: transparent;

  color: inherit;

  font-size: 11px;

  cursor: pointer;

}

.product-alert-error {

  border-color: #f0d4d4;

  background: #fff4f4;

  color: #a73e3e;

}

.product-alert-success {

  border-color: #d5eadb;

  background: #f1faf3;

  color: #287346;

}

.product-summary {

  display: flex;

  width: fit-content;

  min-width: 210px;

  align-items: center;

  gap: 13px;

  margin-bottom: 22px;

  padding: 17px 20px;

  border: 1px solid #e7ece8;

  border-radius: 12px;

  background: white;

}

.product-summary-icon {

  display: grid;

  width: 43px;

  height: 43px;

  place-items: center;

  border-radius: 10px;

  background: #e7f5eb;

  color: #338255;

  font-size: 22px;

}

.product-summary span,

.product-summary strong {

  display: block;

}

.product-summary span {

  color: #7b897f;

  font-size: 11px;

}

.product-summary strong {

  margin-top: 4px;

  font-size: 23px;

}

.product-table-panel {

  overflow: hidden;

  border: 1px solid #e7ece8;

  border-radius: 13px;

  background: white;

}

.product-table-toolbar {

  display: flex;

  align-items: center;

  justify-content: space-between;

  gap: 20px;

  padding: 22px;

}

.product-table-toolbar h3 {

  margin: 0;

  color: #2b3d30;

  font-size: 14px;

}

.product-table-toolbar p {

  margin: 6px 0 0;

  color: #929d94;

  font-size: 10px;

}

.product-search {

  display: flex;

  width: min(100%, 300px);

  align-items: center;

  gap: 8px;

  padding: 9px 11px;

  border: 1px solid #e5ebe6;

  border-radius: 8px;

  color: #7c8b80;

}

.product-search input {

  width: 100%;

  min-width: 0;

  border: 0;

  outline: none;

  background: transparent;

  color: var(--text);

  font: inherit;

  font-size: 11px;

}

.product-table-wrap {

  overflow-x: auto;

}

.product-table-wrap table {

  width: 100%;

  border-collapse: collapse;

  text-align: left;

}

.product-table-wrap th {

  padding: 13px 18px;

  border-top: 1px solid #edf0ed;

  border-bottom: 1px solid #edf0ed;

  background: #fafcfb;

  color: #7c897f;

  font-size: 10px;

  font-weight: 700;

  white-space: nowrap;

}

.product-table-wrap td {

  padding: 15px 18px;

  border-bottom: 1px solid #f0f2f0;

  color: #59685e;

  font-size: 11px;

}

.product-table-wrap tbody tr:last-child td {

  border-bottom: 0;

}

.product-name-cell {

  display: flex;

  min-width: 190px;

  align-items: center;

  gap: 10px;

}

.product-thumbnail {

  width: 48px;

  height: 48px;

  flex-shrink: 0;

  border: 1px solid #e6ece7;

  border-radius: 7px;

  background: #f8faf8;

  object-fit: cover;

}

.product-no-image {

  display: grid;

  place-items: center;

  color: #929d94;

  font-size: 10px;

}

.product-name-cell strong,

.product-name-cell small {

  display: block;

}

.product-name-cell strong {

  max-width: 200px;

  color: #34483a;

  font-size: 11px;

}

.product-name-cell small {

  margin-top: 4px;

  color: #9aa49d;

  font-size: 9px;

}

.product-category-tag {

  display: inline-block;

  padding: 5px 8px;

  border-radius: 5px;

  background: #f1f7f2;

  color: #34764b;

  white-space: nowrap;

}

.product-description {

  display: -webkit-box;

  max-width: 300px;

  overflow: hidden;

  -webkit-box-orient: vertical;

  line-clamp: 3;

  -webkit-line-clamp: 3;

  line-height: 1.6;

}

.product-actions {

  display: flex;

  gap: 7px;

}

.product-actions button {

  padding: 6px 9px;

  border: 1px solid;

  border-radius: 6px;

  font-size: 10px;

  cursor: pointer;

  white-space: nowrap;

}

.product-edit-btn {

  border-color: #dce9df !important;

  background: #f3faf5;

  color: #34764b;

}

.product-delete-btn {

  border-color: #f0dada !important;

  background: #fff6f6;

  color: #bd4545;

}

.product-empty {

  height: 100px;

  color: #929d94 !important;

  text-align: center;

}

.product-table-footer {

  display: flex;

  align-items: center;

  justify-content: space-between;

  gap: 12px;

  padding: 15px 20px;

  border-top: 1px solid #edf0ed;

  color: #929d94;

  font-size: 10px;

}

.product-pagination {

  display: flex;

  gap: 7px;

}

.product-pagination button {

  padding: 7px 10px;

  border: 1px solid #e2e9e3;

  border-radius: 7px;

  background: white;

  color: #597362;

  cursor: pointer;

}

.product-pagination button:disabled {

  opacity: 0.45;

  cursor: not-allowed;

}

.product-modal-overlay {

  position: fixed;

  z-index: 1000;

  inset: 0;

  display: flex;

  align-items: center;

  justify-content: center;

  overflow-y: auto;

  padding: 20px;

  background: rgb(22 39 28 / 45%);

}

.product-modal {

  width: 100%;

  max-width: 550px;

  max-height: calc(100vh - 40px);

  overflow-y: auto;

  border: 1px solid #e8eee9;

  border-radius: 15px;

  background: white;

  box-shadow: 0 20px 70px rgb(0 0 0 / 15%);

}

.product-modal-header {

  display: flex;

  align-items: flex-start;

  justify-content: space-between;

  gap: 15px;

  padding: 23px 25px 18px;

  border-bottom: 1px solid #edf0ed;

}

.product-modal-header h2 {

  margin: 7px 0 0;

  color: #244b31;

  font-size: 20px;

}

.product-modal-close {

  width: 30px;

  height: 30px;

  border: 0;

  border-radius: 7px;

  background: #f3f6f3;

  color: #617065;

  font-size: 23px;

  cursor: pointer;

}

.product-form {

  display: grid;

  gap: 16px;

  padding: 23px 25px 25px;

}

.product-form label {

  display: grid;

  gap: 7px;

  color: #435449;

  font-size: 11px;

  font-weight: 700;

}

.product-form .required {

  color: #c64a4a;

}

.product-form input:not([type="file"]),

.product-form select,

.product-form textarea {

  width: 100%;

  padding: 11px 12px;

  border: 1px solid #dfe7e1;

  border-radius: 8px;

  outline: none;

  background: white;

  color: var(--text);

  font: inherit;

  font-size: 12px;

  font-weight: 400;

}

.product-form input[type="file"] {

  width: 100%;

  padding: 10px;

  border: 1px dashed #d4e1d6;

  border-radius: 8px;

  background: #fafcfb;

  color: #59685e;

  font-size: 11px;

}

.product-form input:focus,

.product-form select:focus,

.product-form textarea:focus {

  border-color: #6fa681;

  box-shadow: 0 0 0 3px rgb(61 132 83 / 9%);

}

.product-form textarea {

  resize: vertical;

}

.product-form label small {

  color: #929d94;

  font-size: 10px;

  font-weight: 400;

  line-height: 1.6;

}

.product-image-preview {

  display: flex;

  justify-content: center;

  padding: 10px;

  border: 1px solid #edf0ed;

  border-radius: 8px;

  background: #fafcfb;

}

.product-image-preview img {

  max-width: 100%;

  max-height: 180px;

  border-radius: 6px;

  object-fit: contain;

}

.product-form-error {

  margin: 0;

  color: #b63d3d;

  font-size: 11px;

  line-height: 1.6;

}

.product-form-footer {

  display: flex;

  justify-content: flex-end;

  gap: 9px;

  padding-top: 8px;

}

.product-cancel-btn {

  padding: 10px 15px;

  border: 1px solid #e1e7e2;

  border-radius: 8px;

  background: white;

  color: #637267;

  font-size: 12px;

  cursor: pointer;

}

.product-cancel-btn:disabled {

  opacity: 0.6;

}

@media (max-width: 1100px) {

  .product-sidebar {

    flex-basis: 220px;

    width: 220px;

  }

  .product-content {

    padding-right: 22px;

    padding-left: 22px;

  }

  .product-table-toolbar {

    align-items: flex-start;

    flex-direction: column;

  }

}

@media (max-width: 760px) {

  .product-layout {

    display: block;

  }

  .product-sidebar {

    position: relative;

    width: 100%;

    height: auto;

    padding: 13px 15px;

    border-right: 0;

    border-bottom: 1px solid #e6ece7;

  }

  .product-brand {

    margin-bottom: 15px;

  }

  .product-menu-label,

  .product-sidebar-bottom {

    display: none;

  }

  .product-nav {

    display: flex;

    gap: 6px;

    overflow-x: auto;

  }

  .product-nav-item {

    flex-shrink: 0;

    min-height: 38px;

    padding: 8px;

    font-size: 10px;

  }

  .product-nav-disabled small {

    display: none;

  }

  .product-topbar {

    min-height: 75px;

    padding: 14px 18px;

  }

  .product-topbar h1 {

    font-size: 18px;

  }

  .product-content {

    padding: 20px 15px;

  }

  .product-intro {

    align-items: flex-start;

    flex-direction: column;

  }

  .product-intro h2 {

    font-size: 22px;

  }

  .product-table-toolbar {

    padding: 16px;

  }

  .product-search {

    width: 100%;

  }

}



/* Ảnh phụ của sản phẩm */
.product-additional-images-field { display: grid; gap: 8px; min-width: 0; }
.product-additional-images-field > label { display: block; color: #435449; font-size: 11px; font-weight: 700; }
.product-additional-images-input { width: 100%; padding: 10px; border: 1px dashed #d4e1d6; border-radius: 8px; background: #fafcfb; color: #59685e; font-size: 11px; }
.product-additional-images-field > small { color: #879389; font-size: 10px; line-height: 1.65; }
.product-additional-preview-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.product-additional-preview-item { min-width: 0; overflow: hidden; border: 1px solid #e1e9e2; border-radius: 8px; background: #fafcfb; text-align: center; }
.product-additional-preview-item img { display: block; width: 100%; height: 110px; object-fit: contain; background: #f3f6f3; }
.product-additional-preview-item span { display: block; padding: 7px 4px; color: #647267; font-size: 10px; }
@media (max-width: 480px) { .product-additional-preview-grid { gap: 6px; } .product-additional-preview-item img { height: 82px; } }

/* Rich text editor cho mô tả sản phẩm */
.product-editor-field { display: grid; gap: 8px; min-width: 0; }
.product-editor-field > label { display: block; color: #435449; font-size: 11px; font-weight: 700; }
.product-editor-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px; border: 1px solid #dfe7e1; border-bottom: 0; border-radius: 9px 9px 0 0; background: #f7faf7; }
.product-editor-toolbar button { min-height: 30px; padding: 6px 9px; border: 1px solid #dce6de; border-radius: 6px; background: #fff; color: #435449; font-size: 11px; font-weight: 650; cursor: pointer; }
.product-editor-toolbar button:hover { border-color: #94b7a0; background: #edf7ef; }
.product-editor-toolbar button:disabled { opacity: .55; cursor: wait; }
.product-editor-divider { width: 1px; height: 23px; margin: 0 3px; background: #dce6de; }
.product-rich-editor { min-height: 220px; max-height: 45vh; overflow: auto; padding: 14px; border: 1px solid #dfe7e1; border-radius: 0 0 9px 9px; outline: none; background: #fff; color: #26372c; font: 13px/1.8 "Segoe UI", Tahoma, Arial, sans-serif; overflow-wrap: anywhere; }
.product-rich-editor:focus { border-color: #6fa681; box-shadow: 0 0 0 3px rgb(61 132 83 / 9%); }
.product-rich-editor:empty::before { content: attr(data-placeholder); color: #a1aaa4; pointer-events: none; }
.product-rich-editor :deep(strong), .product-rich-editor :deep(b) { font-weight: 700 !important; }
.product-rich-editor :deep(p) { margin: 0 0 10px; }
.product-rich-editor :deep(h2) { margin: 18px 0 9px; color: #23382a; font-size: 21px; font-weight: 800; line-height: 1.35; }
.product-rich-editor :deep(h3) { margin: 15px 0 7px; color: #2f4938; font-size: 17px; font-weight: 800; line-height: 1.4; }
.product-rich-editor :deep(ul) { margin: 0 0 12px; padding-left: 24px; list-style: disc; }
.product-rich-editor :deep(ol) { margin: 0 0 12px; padding-left: 24px; list-style: decimal; }
.product-rich-editor :deep(li) { margin: 4px 0; }
.product-rich-editor :deep(a) { color: #16834b; text-decoration: underline; }
.product-rich-editor :deep(figure) { margin: 16px 0; text-align: center; }
.product-rich-editor :deep(img) { display: inline-block; max-width: 100%; height: auto; border-radius: 8px; object-fit: contain; }
.product-rich-editor :deep(figcaption) { margin-top: 6px; color: #7b897f; font-size: 11px; font-style: italic; }
.product-inline-image-input { display: none !important; }
.product-editor-help { display: block; color: #879389; font-size: 10px; font-weight: 400; line-height: 1.65; }

</style>
