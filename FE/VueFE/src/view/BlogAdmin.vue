<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'
import { useRouter } from 'vue-router'

interface Blog {
  id: number
  slug?: string
  title: string
  img: string | null
  description: string
  created_at?: string
  updated_at?: string
}

const router = useRouter()
const blogs = ref<Blog[]>([])
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const searchQuery = ref('')
const adminUsername = ref('Admin')
const showModal = ref(false)
const editingId = ref<number | null>(null)
const selectedImage = ref<File | null>(null)
const imagePreview = ref('')
const editorRef = ref<HTMLDivElement | null>(null)
const inlineImageInput = ref<HTMLInputElement | null>(null)
const inlineImageUploading = ref(false)
let savedEditorRange: Range | null = null
const form = ref({ title: '', description: '' })
const currentPage = ref(1)
const pageSize = 20

// Các bài viết được tải từ tất cả các trang của Laravel, sau đó lọc và phân trang ở FE.
const totalBlogs = computed(() => blogs.value.length)

function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[đĐ]/g, character => character === 'Đ' ? 'D' : 'd')
    .toLocaleLowerCase('vi-VN')
    .trim()
}

function plainText(value: string | null | undefined): string {
  if (!value) return ''
  const template = document.createElement('template')
  template.innerHTML = value
  return (template.content.textContent ?? '').replace(/\s+/g, ' ').trim()
}

const filteredBlogs = computed(() => {
  const keyword = normalizeSearchText(searchQuery.value)
  if (!keyword) return blogs.value

  const terms = keyword.split(/\s+/).filter(Boolean)
  return blogs.value.filter(blog => {
    const searchable = normalizeSearchText(
      `${blog.id} ${blog.title} ${blog.slug ?? ''} ${plainText(blog.description)} ${blog.created_at ?? ''}`,
    )
    return searchable.includes(keyword) || terms.every(term => searchable.includes(term))
  })
})

const lastPage = computed(() => Math.max(1, Math.ceil(filteredBlogs.value.length / pageSize)))
const paginatedBlogs = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  return filteredBlogs.value.slice(start, start + pageSize)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

watch(lastPage, pageCount => {
  if (currentPage.value > pageCount) currentPage.value = pageCount
})

function getConfig() {
  const token = localStorage.getItem('auth_token')
  return {
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  }
}

function loadAdminUsername() {
  try {
    const savedUser = localStorage.getItem('auth_user')
    if (!savedUser) return
    const user = JSON.parse(savedUser) as { username?: unknown }
    if (typeof user.username === 'string') adminUsername.value = user.username
  } catch {
    adminUsername.value = 'Admin'
  }
}

function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    const data = error.response?.data as {
      message?: unknown
      errors?: Record<string, unknown>
    } | undefined

    if (status === 401 || status === 403) {
      return 'Phiên đăng nhập hết hạn hoặc tài khoản không có quyền quản trị.'
    }
    if (status === 422) {
      const firstError = data?.errors ? Object.values(data.errors)[0] : undefined
      if (Array.isArray(firstError) && typeof firstError[0] === 'string') return firstError[0]
      return typeof data?.message === 'string' ? data.message : 'Dữ liệu nhập vào chưa hợp lệ.'
    }
    if (typeof data?.message === 'string') return data.message
    if (!error.response) return 'Không kết nối được Laravel. Hãy kiểm tra Backend.'
  }
  return 'Đã xảy ra lỗi. Hãy kiểm tra API và thử lại.'
}

function extractList(payload: unknown): Blog[] {
  if (Array.isArray(payload)) return payload as Blog[]
  if (typeof payload === 'object' && payload !== null) {
    const data = (payload as Record<string, unknown>).data
    if (Array.isArray(data)) return data as Blog[]
  }
  return []
}

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  const cleanPath = path.replace(/^\/+/, '').replace(/^storage\//, '')
  return `${STORAGE_BASE_URL}/storage/${cleanPath}`
}

async function loadBlogs() {
  loading.value = true
  errorMessage.value = ''
  try {
    // Trang API đầu tiên cho biết tổng số trang của Laravel.
    const firstResponse = await axios.get(`${API_URL}/blogs?page=1`, getConfig())
    const firstPayload: unknown = firstResponse.data
    const allBlogs = extractList(firstPayload)

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

    // Lấy tiếp toàn bộ các trang API để từ khóa được so với tất cả bài viết.
    if (apiLastPage > 1) {
      const remainingPages = await Promise.allSettled(
        Array.from({ length: apiLastPage - 1 }, (_, index) =>
          axios.get(`${API_URL}/blogs?page=${index + 2}`, getConfig()),
        ),
      )

      for (const result of remainingPages) {
        if (result.status === 'fulfilled') {
          allBlogs.push(...extractList(result.value.data))
        }
      }
    }

    // Loại trùng nếu API trả về bản ghi lặp ở nhiều trang.
    const uniqueBlogs = new Map<number, Blog>()
    for (const blog of allBlogs) uniqueBlogs.set(blog.id, blog)
    blogs.value = Array.from(uniqueBlogs.values())

    // Số bài hiển thị ở footer là số lượng thực tế đã tải về.
  } catch (error: unknown) {
    blogs.value = []
    errorMessage.value = getErrorMessage(error)
  } finally {
    loading.value = false
  }
}
function clearImagePreview() {
  if (imagePreview.value.startsWith('blob:')) URL.revokeObjectURL(imagePreview.value)
  imagePreview.value = ''
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
  // Hỗ trợ các bài viết cũ đang lưu dạng văn bản thuần.
  const hasHtmlBlocks = /<\s*(p|h[2-6]|ul|ol|li|strong|b|em|i|u|blockquote|a|img|figure|br|hr)\b/i.test(trimmed)
  if (hasHtmlBlocks) return trimmed
  return trimmed
    .split(/\n\s*\n/)
    .map(paragraph => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('')
}

function syncEditorContent() {
  form.value.description = editorRef.value?.innerHTML.trim() ?? ''
}

async function applyEditorHtml(html: string) {
  await nextTick()
  if (editorRef.value) {
    const template = document.createElement('template')
    template.innerHTML = normalizeEditorHtml(html)
    template.content.querySelectorAll('img[src]').forEach(image => {
      const source = image.getAttribute('src') ?? ''
      if (source.startsWith('/storage/') || source.startsWith('storage/')) {
        image.setAttribute('src', imageUrl(source))
      }
    })
    editorRef.value.innerHTML = template.innerHTML
  }
  syncEditorContent()
}

// Lưu đường dẫn ảnh dưới dạng tương đối để khi chuyển domain không phải sửa từng bài.
function portableEditorHtml(html: string): string {
  const template = document.createElement('template')
  template.innerHTML = html
  const storagePrefix = `${STORAGE_BASE_URL}/storage/`
  template.content.querySelectorAll('img[src]').forEach(image => {
    const source = image.getAttribute('src') ?? ''
    if (source.startsWith(storagePrefix)) {
      image.setAttribute('src', `/storage/${source.slice(storagePrefix.length)}`)
    }
  })
  return template.innerHTML
}



function executeEditorCommand(command: string) {
  const editor = editorRef.value
  if (!editor) return

  restoreEditorSelection()

  const selection = window.getSelection()
  if (!selection || selection.rangeCount === 0) return

  const range = selection.getRangeAt(0)

  // Chỉ xử lý khi vùng chọn nằm trong trình soạn thảo
  if (!editor.contains(range.commonAncestorContainer)) return

  // Xử lý in đậm trực tiếp bằng thẻ strong
  if (command === 'bold') {
    if (range.collapsed) return

    const strong = document.createElement('strong')
    strong.appendChild(range.extractContents())
    range.insertNode(strong)

    // Giữ vùng chữ vừa định dạng được chọn
    const newRange = document.createRange()
    newRange.selectNodeContents(strong)
    selection.removeAllRanges()
    selection.addRange(newRange)
  } else {
    document.execCommand(command, false)
  }

  syncEditorContent()
}


function formatEditorBlock(tag: 'p' | 'h2' | 'h3') {
  if (!editorRef.value) return
  editorRef.value.focus()
  document.execCommand('formatBlock', false, tag)
  syncEditorContent()
}



function saveEditorSelection() {
  const editor = editorRef.value
  const selection = window.getSelection()
  if (!editor || !selection || selection.rangeCount === 0) return
  const range = selection.getRangeAt(0)
  if (editor.contains(range.commonAncestorContainer)) {
    savedEditorRange = range.cloneRange()
  }
}

function restoreEditorSelection() {
  const editor = editorRef.value
  if (!editor) return
  editor.focus()
  const selection = window.getSelection()
  if (selection && savedEditorRange && editor.contains(savedEditorRange.commonAncestorContainer)) {
    selection.removeAllRanges()
    selection.addRange(savedEditorRange)
  } else if (selection) {
    const range = document.createRange()
    range.selectNodeContents(editor)
    range.collapse(false)
    selection.removeAllRanges()
    selection.addRange(range)
  }
  savedEditorRange = null
}


function normalizeEditorUrl(input: string): string | null {
  const value = input.trim()
  if (!value) return null

  // URL website có sẵn http hoặc https.
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

  // Cho phép đường dẫn nội bộ.
  if (
    /^\/(?!\/)/.test(value) ||
    value.startsWith('#') ||
    value.startsWith('./') ||
    value.startsWith('../')
  ) {
    return value
  }

  // Không cho phép các giao thức nguy hiểm như javascript:.
  if (/^[a-z][a-z\d+.-]*:/i.test(value)) return null

  // Tự thêm HTTPS nếu người dùng chỉ nhập tên miền.
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
    window.alert(
      'URL không hợp lệ. Hãy nhập địa chỉ website hoặc đường dẫn nội bộ hợp lệ.',
    )
    return
  }

  restoreEditorSelection()

  if (selectedText) {
    document.execCommand('createLink', false, url)

    const currentSelection = window.getSelection()
    const node = currentSelection?.anchorNode
    const element =
      node instanceof Element ? node : node?.parentElement
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
    errorMessage.value = 'Ảnh chèn trong nội dung không được vượt quá 5 MB.'
    return
  }
  const alt = window.prompt('Nhập ALT mô tả ảnh (nên mô tả đúng nội dung ảnh):')?.trim()
  if (!alt) {
    errorMessage.value = 'Ảnh trong nội dung cần có ALT để hỗ trợ SEO và khả năng tiếp cận.'
    return
  }

  errorMessage.value = ''
  inlineImageUploading.value = true
  try {
    const body = new FormData()
    body.append('image', file)
    const response = await axios.post(`${API_URL}/blog-content-images`, body, getConfig())
    const payload = response.data as { path?: string; url?: string; image?: { path?: string; url?: string } }
    const path = payload.path ?? payload.url ?? payload.image?.path ?? payload.image?.url
    if (!path) throw new Error('API tải ảnh không trả về đường dẫn ảnh.')

    const resolvedUrl = imageUrl(path)
    restoreEditorSelection()
    document.execCommand(
      'insertHTML',
      false,
      `<figure><img src="${escapeAttribute(resolvedUrl)}" alt="${escapeAttribute(alt)}" loading="lazy"><figcaption>${escapeHtml(alt)}</figcaption></figure><p><br></p>`,
    )
    syncEditorContent()
  } catch (error: unknown) {
    restoreEditorSelection()
    errorMessage.value = getErrorMessage(error)
  } finally {
    inlineImageUploading.value = false
  }
}

function textOnly(value: string): string {
  const template = document.createElement('template')
  template.innerHTML = value
  return (template.content.textContent ?? '').replace(/\s+/g, ' ').trim()
}

async function openCreateModal() {
  editingId.value = null
  form.value = { title: '', description: '' }
  selectedImage.value = null
  clearImagePreview()
  errorMessage.value = ''
  showModal.value = true
  await applyEditorHtml('')
}

async function openEditModal(blog: Blog) {
  editingId.value = blog.id
  form.value = { title: blog.title, description: blog.description ?? '' }
  selectedImage.value = null
  clearImagePreview()
  imagePreview.value = imageUrl(blog.img)
  errorMessage.value = ''
  showModal.value = true
  await applyEditorHtml(blog.description ?? '')
}

function closeModal() {
  if (saving.value || inlineImageUploading.value) return
  showModal.value = false
  selectedImage.value = null
  clearImagePreview()
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

async function saveBlog() {
  errorMessage.value = ''
  successMessage.value = ''

  const title = form.value.title.trim()
  const rawContentHtml = editorRef.value?.innerHTML.trim() ?? form.value.description.trim()
  const contentHtml = portableEditorHtml(rawContentHtml)
  const contentText = textOnly(rawContentHtml)
  const contentHasImage = Boolean(editorRef.value?.querySelector('img'))

  if (!title) {
    errorMessage.value = 'Vui lòng nhập tiêu đề bài viết.'
    return
  }
  if (!contentText && !contentHasImage) {
    errorMessage.value = 'Vui lòng nhập nội dung bài viết.'
    return
  }

  const body = new FormData()
  body.append('title', title)
  body.append('description', contentHtml)
  if (selectedImage.value) body.append('img', selectedImage.value)

  saving.value = true
  try {
    if (editingId.value !== null) {
      body.append('_method', 'PUT')
      await axios.post(`${API_URL}/blogs/${editingId.value}`, body, getConfig())
      successMessage.value = 'Cập nhật bài viết thành công.'
    } else {
      await axios.post(`${API_URL}/blogs`, body, getConfig())
      successMessage.value = 'Thêm bài viết thành công.'
    }
    showModal.value = false
    selectedImage.value = null
    clearImagePreview()
    currentPage.value = 1
    await loadBlogs()
  } catch (error: unknown) {
    errorMessage.value = getErrorMessage(error)
  } finally {
    saving.value = false
  }
}

async function deleteBlog(blog: Blog) {
  if (!window.confirm(`M có chắc chắn muốn xóa bài viết "${blog.title}" không?`)) return
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await axios.delete(`${API_URL}/blogs/${blog.id}`, getConfig())
    successMessage.value = 'Xóa bài viết thành công.'
    await loadBlogs()
  } catch (error: unknown) {
    errorMessage.value = getErrorMessage(error)
  }
}

async function handleLogout() {
  const token = localStorage.getItem('auth_token')
  try {
    if (token) await axios.post(`${API_URL}/logout`, {}, getConfig())
  } catch {
    // Xóa phiên ở trình duyệt kể cả khi API logout lỗi.
  } finally {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    window.dispatchEvent(new Event('auth-changed'))
    await router.replace('/login')
  }
}

onMounted(() => {
  loadAdminUsername()
  void loadBlogs()
})

onBeforeUnmount(clearImagePreview)
</script>

<template>
  <div class="blog-layout">
    <aside class="blog-sidebar">
      <router-link to="/" class="blog-brand">
        <img src="/assets/logo.jpeg" alt="Duy Chân Dung" />
        <span><strong>Duy Chân Dung</strong><small>ADMIN PANEL</small></span>
      </router-link>
      <div class="blog-menu-label">TỔNG QUAN</div>
      <nav class="blog-nav">
        <router-link to="/admin" class="blog-nav-item"><span>▦</span>Dashboard</router-link>
        <router-link to="/admin/products" class="blog-nav-item"><span>▧</span>Quản lý tranh</router-link>
        <router-link to="/admin/categories" class="blog-nav-item"><span>▤</span>Danh mục sản phẩm</router-link>
        <router-link to="/admin/blogs" class="blog-nav-item blog-nav-active"><span>▤</span>Quản lý bài viết</router-link>
        <router-link to="/admin/banner" class="blog-nav-item" active-class="blog-nav-active"><span>▧</span>Thay banner</router-link>
        
      </nav>
      <div class="blog-sidebar-bottom">
        <div class="blog-profile"><div class="blog-avatar">{{ adminUsername.charAt(0).toUpperCase() }}</div><div><strong>{{ adminUsername }}</strong><small>Quản trị viên</small></div></div>
        <button class="blog-logout" type="button" @click="handleLogout">Đăng xuất</button>
      </div>
    </aside>

    <main class="blog-main">
      <header class="blog-topbar">
        <div><p>Trang quản trị / Quản lý bài viết</p><h1>Quản lý bài viết</h1></div>
        <router-link to="/" class="blog-view-site">Xem website ↗</router-link>
      </header>

      <section class="blog-content">
        <div class="blog-intro">
          <div><span>QUẢN LÝ NỘI DUNG</span><h2>Danh sách bài viết</h2><p>Thêm, chỉnh sửa và quản lý ảnh bài viết trên website.</p></div>
          <button class="blog-primary-btn" type="button" @click="openCreateModal">＋ Thêm bài viết</button>
        </div>

        <div v-if="errorMessage && !showModal" class="blog-alert blog-alert-error"><span>{{ errorMessage }}</span><button @click="errorMessage = ''">Đóng</button></div>
        <div v-if="successMessage" class="blog-alert blog-alert-success"><span>{{ successMessage }}</span><button @click="successMessage = ''">Đóng</button></div>

        <div class="blog-summary"><div class="blog-summary-icon">▤</div><div><span>Tổng bài viết</span><strong>{{ totalBlogs }}</strong></div></div>

        <section class="blog-table-panel">
          <div class="blog-toolbar"><div><h3>Danh sách bài viết</h3><p>Dữ liệu được tải từ Laravel API.</p></div><div class="blog-search"><span>⌕</span><input v-model="searchQuery" type="search" placeholder="Tìm tiêu đề, nội dung hoặc ID..." /></div></div>
          <div class="blog-table-wrap">
            <table>
              <thead><tr><th>Bài viết</th><th>Ảnh</th><th>Mô tả</th><th>Thao tác</th></tr></thead>
              <tbody>
                <tr v-if="loading"><td colspan="4" class="blog-empty">Đang tải bài viết...</td></tr>
                <tr v-else-if="filteredBlogs.length === 0"><td colspan="4" class="blog-empty">{{ searchQuery ? 'Không tìm thấy bài viết phù hợp.' : 'Chưa có bài viết nào.' }}</td></tr>
                <tr v-for="blog in paginatedBlogs" :key="blog.id">
                  <td><div class="blog-title-cell"><strong>{{ blog.title }}</strong><small>#{{ blog.id }}</small></div></td>
                  <td><img v-if="blog.img" class="blog-thumbnail" :src="imageUrl(blog.img)" :alt="blog.title" /><span v-else class="blog-no-image">Chưa có ảnh</span></td>
                  <td><div class="blog-description">{{ textOnly(blog.description) }}</div></td>
                  <td><div class="blog-actions"><button class="blog-edit-btn" @click="openEditModal(blog)">Sửa</button><button class="blog-delete-btn" @click="deleteBlog(blog)">Xóa</button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="blog-table-footer"><span>Hiển thị {{ filteredBlogs.length ? (currentPage - 1) * pageSize + 1 : 0 }}–{{ Math.min(currentPage * pageSize, filteredBlogs.length) }} / {{ filteredBlogs.length }} kết quả · Tổng {{ totalBlogs }} bài viết · Trang {{ currentPage }} / {{ lastPage }}</span><div class="blog-pagination"><button :disabled="loading || currentPage <= 1" @click="currentPage--">Trước</button><button :disabled="loading || currentPage >= lastPage" @click="currentPage++">Sau</button></div></div>
        </section>
      </section>
    </main>

    <div v-if="showModal" class="blog-modal-overlay" @click.self="closeModal">
      <section class="blog-modal" role="dialog" aria-modal="true" aria-labelledby="blog-modal-title">
        <header class="blog-modal-header"><div><span>QUẢN LÝ NỘI DUNG</span><h2 id="blog-modal-title">{{ editingId !== null ? 'Cập nhật bài viết' : 'Thêm bài viết mới' }}</h2></div><button type="button" class="blog-modal-close" :disabled="saving || inlineImageUploading" @click="closeModal">×</button></header>
        <form class="blog-form" @submit.prevent="saveBlog">
          
          <div class="blog-editor-field">
            <label for="blog-title-input">Tiêu đề bài viết <span class="required">*</span></label>
            <input id="blog-title-input" v-model="form.title" type="text" maxlength="255" placeholder="Nhập tiêu đề bài viết" required />
            <small class="blog-editor-help">Tiêu đề này sẽ tự trở thành thẻ H1 ở trang chi tiết. Không cần tự chèn H1 trong nội dung.</small>
          </div>
          <div class="blog-editor-field">
            <label>Nội dung bài viết <span class="required">*</span></label>
            <div class="blog-editor-toolbar" role="toolbar" aria-label="Định dạng nội dung bài viết">
              <button type="button" title="Đoạn văn" @mousedown.prevent @click="formatEditorBlock('p')">Đoạn văn</button>
              <button type="button" title="Tiêu đề cấp 2" @mousedown.prevent @click="formatEditorBlock('h2')">H2</button>
              <button type="button" title="Tiêu đề cấp 3" @mousedown.prevent @click="formatEditorBlock('h3')">H3</button>
              <span class="blog-toolbar-divider"></span>
              <button
  type="button"
  title="In đậm"
  @mousedown.prevent="saveEditorSelection"
  @click="executeEditorCommand('bold')"
>
  <strong>B</strong>
</button>
              <button type="button" title="In nghiêng" @mousedown.prevent @click="executeEditorCommand('italic')"><em>I</em></button>
              <button type="button" title="Danh sách gạch đầu dòng" @mousedown.prevent @click="executeEditorCommand('insertUnorderedList')">• Danh sách</button>
              <button type="button" title="Chèn liên website hoặc kết nội bộ" @mousedown.prevent @click="insertInternalLink">↗ Liên kết</button>
              <button type="button" title="Chèn ảnh có ALT" :disabled="inlineImageUploading" @mousedown.prevent @click="openInlineImagePicker">{{ inlineImageUploading ? 'Đang tải ảnh…' : '＋ Ảnh + ALT' }}</button>
            </div>
            <div
              ref="editorRef"
              class="blog-rich-editor"
              contenteditable="true"
              role="textbox"
              aria-multiline="true"
              aria-label="Nội dung bài viết có định dạng"
              data-placeholder="Viết nội dung bài viết tại đây. Dùng H2 cho các mục lớn, H3 cho mục nhỏ..."
              @input="syncEditorContent"
              @blur="syncEditorContent"
            ></div>
            <input ref="inlineImageInput" class="blog-inline-image-input" type="file" accept="image/*" @change="onInlineImageSelected" />
            <small class="blog-editor-help">H1 được tạo từ tiêu đề bài viết. Dùng H2/H3 để chia mục; ảnh trong nội dung cần ALT. Đường dẫn nội bộ có thể là /blogs/1 hoặc /products/2. Ảnh tối đa 5 MB.</small>
          </div>
          <label>Ảnh bài viết<input type="file" accept="image/*" @change="onImageChange" /><small>Ảnh tối đa 5 MB. Khi sửa, không chọn ảnh mới sẽ giữ ảnh cũ.</small></label>
          <div v-if="imagePreview" class="blog-image-preview"><img :src="imagePreview" alt="Xem trước ảnh bài viết" /></div>
          <p v-if="errorMessage" class="blog-form-error">{{ errorMessage }}</p>
          <footer class="blog-form-footer"><button type="button" class="blog-cancel-btn" :disabled="saving || inlineImageUploading" @click="closeModal">Hủy</button><button type="submit" class="blog-primary-btn" :disabled="saving || inlineImageUploading">{{ saving ? 'Đang lưu...' : inlineImageUploading ? 'Đang tải ảnh...' : editingId !== null ? 'Lưu thay đổi' : 'Thêm bài viết' }}</button></footer>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.blog-layout,.blog-layout *{box-sizing:border-box}.blog-layout{--green:#28784e;--green-dark:#185938;--bg:#f5f7f5;--text:#26372c;display:flex;min-height:100vh;background:var(--bg);color:var(--text);font-family:"Segoe UI",Tahoma,Arial,sans-serif;font-size:14px}.blog-sidebar{position:sticky;top:0;display:flex;flex:0 0 245px;flex-direction:column;width:245px;height:100vh;padding:25px 16px 17px;border-right:1px solid #e7ece8;background:#fff}.blog-brand{display:flex;align-items:center;gap:10px;margin-bottom:38px;padding:0 7px;color:var(--text);text-decoration:none}.blog-brand img{width:42px;height:42px;border-radius:9px;object-fit:contain}.blog-brand strong,.blog-brand small{display:block}.blog-brand strong{font-size:14px}.blog-brand small{margin-top:4px;color:#929d95;font-size:9px;letter-spacing:1.5px}.blog-menu-label{margin:0 10px 12px;color:#a0aaa3;font-size:10px;font-weight:800;letter-spacing:1.1px}.blog-nav{display:grid;gap:7px}.blog-nav-item{display:flex;min-height:44px;align-items:center;gap:12px;padding:10px 12px;border-radius:9px;color:#6d7b70;font-size:12px;font-weight:600;text-decoration:none}.blog-nav-item span{width:19px;flex-shrink:0;font-size:18px;text-align:center}.blog-nav-active{background:#eaf5ed;color:var(--green-dark)}.blog-sidebar-bottom{margin-top:auto}.blog-profile{display:flex;align-items:center;gap:10px;padding:14px 4px;border-top:1px solid #edf0ed}.blog-avatar{display:grid;width:37px;height:37px;flex-shrink:0;place-items:center;border-radius:11px;background:#deeee2;color:#22633e;font-weight:800}.blog-profile strong,.blog-profile small{display:block}.blog-profile strong{font-size:11px}.blog-profile small{margin-top:4px;color:#89958c;font-size:10px}.blog-logout{width:100%;padding:11px;border:1px solid #e8ece9;border-radius:9px;background:white;color:#657369;cursor:pointer}.blog-logout:hover{background:#fff4f4;color:#c34242}.blog-main{min-width:0;flex:1}.blog-topbar{display:flex;min-height:88px;align-items:center;justify-content:space-between;gap:20px;padding:16px 34px;border-bottom:1px solid #e8ede9;background:#fff}.blog-topbar p{margin:0 0 6px;color:#8b978e;font-size:10px}.blog-topbar h1{margin:0;color:#23382a;font-size:22px}.blog-view-site{color:#607267;font-size:12px;text-decoration:none}.blog-content{width:100%;max-width:1450px;margin:0 auto;padding:28px 34px}.blog-intro{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:24px}.blog-intro>div>span,.blog-modal-header span{color:#548665;font-size:9px;font-weight:800;letter-spacing:1.4px}.blog-intro h2{margin:8px 0;color:#244b31;font-size:25px}.blog-intro p{margin:0;color:#7b897f;font-size:12px;line-height:1.7}.blog-primary-btn{display:inline-flex;min-height:40px;align-items:center;justify-content:center;gap:7px;padding:10px 15px;border:1px solid var(--green);border-radius:8px;background:var(--green);color:#fff;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}.blog-primary-btn:hover{background:var(--green-dark)}.blog-primary-btn:disabled{opacity:.6;cursor:wait}.blog-alert{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px;padding:12px 15px;border:1px solid;border-radius:9px;font-size:12px;line-height:1.6}.blog-alert button{border:0;background:transparent;color:inherit;cursor:pointer}.blog-alert-error{border-color:#f0d4d4;background:#fff4f4;color:#a73e3e}.blog-alert-success{border-color:#d5eadb;background:#f1faf3;color:#287346}.blog-summary{display:flex;width:fit-content;min-width:210px;align-items:center;gap:13px;margin-bottom:22px;padding:17px 20px;border:1px solid #e7ece8;border-radius:12px;background:#fff}.blog-summary-icon{display:grid;width:43px;height:43px;place-items:center;border-radius:10px;background:#e7f5eb;color:#338255;font-size:22px}.blog-summary span,.blog-summary strong{display:block}.blog-summary span{color:#7b897f;font-size:11px}.blog-summary strong{margin-top:4px;font-size:23px}.blog-table-panel{overflow:hidden;border:1px solid #e7ece8;border-radius:13px;background:#fff}.blog-toolbar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px}.blog-toolbar h3{margin:0;color:#2b3d30;font-size:14px}.blog-toolbar p{margin:6px 0 0;color:#929d94;font-size:10px}.blog-search{display:flex;width:min(100%,300px);align-items:center;gap:8px;padding:9px 11px;border:1px solid #e5ebe6;border-radius:8px;color:#7c8b80}.blog-search input{width:100%;min-width:0;border:0;outline:none;background:transparent;color:var(--text);font:inherit;font-size:11px}.blog-table-wrap{overflow-x:auto}.blog-table-wrap table{width:100%;border-collapse:collapse;text-align:left}.blog-table-wrap th{padding:13px 18px;border-top:1px solid #edf0ed;border-bottom:1px solid #edf0ed;background:#fafcfb;color:#7c897f;font-size:10px;font-weight:700;white-space:nowrap}.blog-table-wrap td{padding:15px 18px;border-bottom:1px solid #f0f2f0;color:#59685e;font-size:11px}.blog-table-wrap tbody tr:last-child td{border-bottom:0}.blog-title-cell{min-width:160px}.blog-title-cell strong,.blog-title-cell small{display:block}.blog-title-cell strong{max-width:230px;color:#34483a;font-size:11px}.blog-title-cell small{margin-top:4px;color:#9aa49d;font-size:9px}.blog-thumbnail{width:58px;height:48px;border:1px solid #e6ece7;border-radius:7px;object-fit:cover}.blog-no-image{color:#929d94;font-size:10px;white-space:nowrap}.blog-description{display:-webkit-box;max-width:360px;overflow:hidden;line-clamp:3;-webkit-box-orient:vertical;-webkit-line-clamp:3;line-height:1.6}.blog-actions{display:flex;gap:7px}.blog-actions button{padding:6px 9px;border:1px solid;border-radius:6px;font-size:10px;cursor:pointer;white-space:nowrap}.blog-edit-btn{border-color:#dce9df!important;background:#f3faf5;color:#34764b}.blog-delete-btn{border-color:#f0dada!important;background:#fff6f6;color:#bd4545}.blog-empty{height:100px;color:#929d94!important;text-align:center}.blog-table-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 20px;border-top:1px solid #edf0ed;color:#929d94;font-size:10px}.blog-pagination{display:flex;gap:7px}.blog-pagination button{padding:7px 10px;border:1px solid #e2e9e3;border-radius:7px;background:white;color:#597362;cursor:pointer}.blog-pagination button:disabled{opacity:.45;cursor:not-allowed}.blog-modal-overlay{position:fixed;z-index:1000;inset:0;display:flex;align-items:center;justify-content:center;overflow-y:auto;padding:20px;background:rgb(22 39 28 / 45%)}.blog-modal{width:100%;max-width:550px;max-height:calc(100vh - 40px);overflow-y:auto;border:1px solid #e8eee9;border-radius:15px;background:#fff;box-shadow:0 20px 70px rgb(0 0 0 / 15%)}.blog-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;padding:23px 25px 18px;border-bottom:1px solid #edf0ed}.blog-modal-header h2{margin:7px 0 0;color:#244b31;font-size:20px}.blog-modal-close{width:30px;height:30px;border:0;border-radius:7px;background:#f3f6f3;color:#617065;font-size:23px;cursor:pointer}.blog-form{display:grid;gap:16px;padding:23px 25px 25px}.blog-form label{display:grid;gap:7px;color:#435449;font-size:11px;font-weight:700}.blog-form .required{color:#c64a4a}.blog-form input:not([type=file]),.blog-form textarea{width:100%;padding:11px 12px;border:1px solid #dfe7e1;border-radius:8px;outline:none;background:white;color:var(--text);font:inherit;font-size:12px;font-weight:400}.blog-form input[type=file]{width:100%;padding:10px;border:1px dashed #d4e1d6;border-radius:8px;background:#fafcfb;color:#59685e;font-size:11px}.blog-form input:focus,.blog-form textarea:focus{border-color:#6fa681;box-shadow:0 0 0 3px rgb(61 132 83 / 9%)}.blog-form textarea{resize:vertical}.blog-form label small{color:#929d94;font-size:10px;font-weight:400;line-height:1.6}.blog-image-preview{display:flex;justify-content:center;padding:10px;border:1px solid #edf0ed;border-radius:8px;background:#fafcfb}.blog-image-preview img{max-width:100%;max-height:200px;border-radius:6px;object-fit:contain}.blog-form-error{margin:0;color:#b63d3d;font-size:11px;line-height:1.6}.blog-form-footer{display:flex;justify-content:flex-end;gap:9px;padding-top:8px}.blog-cancel-btn{padding:10px 15px;border:1px solid #e1e7e2;border-radius:8px;background:white;color:#637267;font-size:12px;cursor:pointer}.blog-cancel-btn:disabled{opacity:.6}@media(max-width:1100px){.blog-sidebar{flex-basis:220px;width:220px}.blog-content{padding-right:22px;padding-left:22px}.blog-toolbar{align-items:flex-start;flex-direction:column}}@media(max-width:760px){.blog-layout{display:block}.blog-sidebar{position:relative;width:100%;height:auto;padding:13px 15px;border-right:0;border-bottom:1px solid #e6ece7}.blog-brand{margin-bottom:15px}.blog-menu-label,.blog-sidebar-bottom{display:none}.blog-nav{display:flex;gap:6px;overflow-x:auto}.blog-nav-item{flex-shrink:0;min-height:38px;padding:8px;font-size:10px}.blog-topbar{min-height:75px;padding:14px 18px}.blog-topbar h1{font-size:18px}.blog-content{padding:20px 15px}.blog-intro{align-items:flex-start;flex-direction:column}.blog-intro h2{font-size:22px}.blog-toolbar{padding:16px}.blog-search{width:100%}}

/* Trình soạn thảo bài viết hỗ trợ cấu trúc heading và nội dung giàu định dạng */
.blog-editor-field{display:grid;gap:8px;min-width:0}
.blog-editor-field>label{display:block;color:#435449;font-size:11px;font-weight:700}
.blog-editor-field>input:not([type=file]){width:100%;padding:11px 12px;border:1px solid #dfe7e1;border-radius:8px;outline:none;background:#fff;color:var(--text);font:inherit;font-size:12px}
.blog-editor-help{display:block;color:#879389;font-size:10px;font-weight:400;line-height:1.65}
.blog-editor-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:8px;border:1px solid #dfe7e1;border-bottom:0;border-radius:9px 9px 0 0;background:#f7faf7}
.blog-editor-toolbar button{min-height:30px;padding:6px 9px;border:1px solid #dce6de;border-radius:6px;background:#fff;color:#435449;font-size:11px;font-weight:650;cursor:pointer}
.blog-editor-toolbar button:hover{border-color:#94b7a0;background:#edf7ef}
.blog-editor-toolbar button:disabled{opacity:.55;cursor:wait}
.blog-toolbar-divider{width:1px;height:23px;margin:0 3px;background:#dce6de}
.blog-rich-editor{min-height:280px;max-height:50vh;overflow:auto;padding:16px;border:1px solid #dfe7e1;border-radius:0 0 9px 9px;outline:none;background:#fff;color:#26372c;font:14px/1.8 "Segoe UI",Tahoma,Arial,sans-serif;overflow-wrap:anywhere}
.blog-rich-editor :deep(strong),
.blog-rich-editor :deep(b) {
  font-weight: 700 !important;
}
.blog-rich-editor:focus{border-color:#6fa681;box-shadow:0 0 0 3px rgb(61 132 83 / 9%)}
.blog-rich-editor:empty::before{content:attr(data-placeholder);color:#a1aaa4;pointer-events:none}
.blog-rich-editor :deep(p){margin:0 0 12px}
.blog-rich-editor :deep(h2){margin:22px 0 10px;color:#23382a;font-size:22px;font-weight:800;line-height:1.35}
.blog-rich-editor :deep(h3){margin:18px 0 8px;color:#2f4938;font-size:18px;font-weight:800;line-height:1.4}
.blog-rich-editor :deep(ul){margin:0 0 14px;padding-left:25px;list-style:disc}.blog-rich-editor :deep(ol){margin:0 0 14px;padding-left:25px;list-style:decimal}
.blog-rich-editor :deep(li){margin:4px 0}
.blog-rich-editor :deep(a){color:#16834b;text-decoration:underline}
.blog-rich-editor :deep(figure){margin:18px 0;text-align:center}
.blog-rich-editor :deep(img){display:inline-block;max-width:100%;height:auto;border-radius:8px;object-fit:contain}
.blog-rich-editor :deep(figcaption){margin-top:6px;color:#7b897f;font-size:11px;font-style:italic}
.blog-inline-image-input{display:none!important}

</style>
