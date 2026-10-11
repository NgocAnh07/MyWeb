<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import { API_URL } from '../config/api'
import { useRouter } from 'vue-router'

interface Category {
  id: number
  name: string
  slug: string
  description: string | null
  is_active: boolean | number | string
  products_count?: number
}

const router = useRouter()
const username = ref('Admin')
const categories = ref<Category[]>([])
const loading = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const searchQuery = ref('')
const showModal = ref(false)
const editingId = ref<number | null>(null)
const slugManuallyEdited = ref(false)

const form = ref({
  name: '',
  slug: '',
  description: '',
  is_active: true,
})

const filteredCategories = computed(() => {
  const keyword = searchQuery.value.trim().toLowerCase()
  if (!keyword) return categories.value

  return categories.value.filter(category =>
    category.name.toLowerCase().includes(keyword) ||
    category.slug.toLowerCase().includes(keyword),
  )
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

function loadUserInfo() {
  try {
    const savedUser = localStorage.getItem('auth_user')
    if (savedUser) {
      const user = JSON.parse(savedUser)
      if (typeof user?.username === 'string' && user.username.trim()) {
        username.value = user.username
      }
    }
  } catch {
    username.value = 'Admin'
  }
}

function isActive(category: Category): boolean {
  return category.is_active === true ||
    category.is_active === 1 ||
    category.is_active === '1'
}

function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    const data = error.response?.data as
      | { message?: unknown; errors?: Record<string, unknown> }
      | undefined

    if (status === 401 || status === 403) {
      return 'Phiên đăng nhập hết hạn hoặc tài khoản không có quyền quản trị.'
    }

    if (data?.errors && typeof data.errors === 'object') {
      const first = Object.values(data.errors)[0]
      if (Array.isArray(first) && typeof first[0] === 'string') {
        return first[0]
      }
    }

    if (typeof data?.message === 'string') return data.message
    if (!error.response) return 'Không kết nối được Laravel. Hãy kiểm tra Backend.'
  }

  return 'Đã xảy ra lỗi. Hãy kiểm tra API và thử lại.'
}

function createSlug(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function updateSlug() {
  if (!slugManuallyEdited.value && editingId.value === null) {
    form.value.slug = createSlug(form.value.name)
  }
}

async function loadCategories() {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await axios.get(`${API_URL}/categories`, getConfig())
    const payload: unknown = response.data

    if (Array.isArray(payload)) {
      categories.value = payload as Category[]
    } else if (
      typeof payload === 'object' && payload !== null &&
      Array.isArray((payload as Record<string, unknown>).data)
    ) {
      categories.value = (payload as { data: Category[] }).data
    } else {
      categories.value = []
    }
  } catch (error: unknown) {
    categories.value = []
    errorMessage.value = getErrorMessage(error)
  } finally {
    loading.value = false
  }
}

function openCreateModal() {
  editingId.value = null
  slugManuallyEdited.value = false
  form.value = { name: '', slug: '', description: '', is_active: true }
  errorMessage.value = ''
  successMessage.value = ''
  showModal.value = true
}

function openEditModal(category: Category) {
  editingId.value = category.id
  slugManuallyEdited.value = true
  form.value = {
    name: category.name,
    slug: category.slug,
    description: category.description ?? '',
    is_active: isActive(category),
  }
  errorMessage.value = ''
  successMessage.value = ''
  showModal.value = true
}

function closeModal() {
  if (saving.value) return
  showModal.value = false
}

async function saveCategory() {
  errorMessage.value = ''
  successMessage.value = ''

  if (!form.value.name.trim()) {
    errorMessage.value = 'Vui lòng nhập tên danh mục.'
    return
  }
  if (!form.value.slug.trim()) {
    errorMessage.value = 'Vui lòng nhập slug danh mục.'
    return
  }

  const payload = {
    name: form.value.name.trim(),
    slug: form.value.slug.trim(),
    description: form.value.description.trim() || null,
    is_active: form.value.is_active,
  }

  saving.value = true
  try {
    if (editingId.value !== null) {
      await axios.put(`${API_URL}/categories/${editingId.value}`, payload, getConfig())
      successMessage.value = 'Cập nhật danh mục thành công.'
    } else {
      await axios.post(`${API_URL}/categories`, payload, getConfig())
      successMessage.value = 'Thêm danh mục thành công.'
    }

    showModal.value = false
    await loadCategories()
  } catch (error: unknown) {
    errorMessage.value = getErrorMessage(error)
  } finally {
    saving.value = false
  }
}

async function deleteCategory(category: Category) {
  if (typeof category.products_count === 'number' && category.products_count > 0) {
    errorMessage.value = 'Danh mục này đang có sản phẩm. Hãy chuyển sản phẩm sang danh mục khác trước khi xóa.'
    return
  }

  if (!window.confirm(`M có chắc chắn muốn xóa danh mục "${category.name}" không?`)) return

  errorMessage.value = ''
  successMessage.value = ''
  try {
    await axios.delete(`${API_URL}/categories/${category.id}`, getConfig())
    successMessage.value = 'Xóa danh mục thành công.'
    await loadCategories()
  } catch (error: unknown) {
    errorMessage.value = getErrorMessage(error)
  }
}

async function handleLogout() {
  const token = localStorage.getItem('auth_token')
  try {
    if (token) await axios.post(`${API_URL}/logout`, {}, getConfig())
  } catch {
    // Xóa phiên ở trình duyệt kể cả khi API đăng xuất lỗi.
  } finally {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
    window.dispatchEvent(new Event('auth-changed'))
    await router.replace('/login')
  }
}

onMounted(() => {
  loadUserInfo()
  loadCategories()
})
</script>

<template>
  <div class="category-layout">
    <aside class="category-sidebar">
      <router-link to="/" class="category-brand">
        <img src="/assets/logo.jpeg" alt="Duy Chân Dung" />
        <span>
          <strong>Duy Chân Dung</strong>
          <small>ADMIN PANEL</small>
        </span>
      </router-link>

      <div class="category-menu-label">TỔNG QUAN</div>

      <nav class="category-nav">
        <router-link to="/admin" class="category-nav-item">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1.5" />
            <rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" />
            <rect x="14" y="14" width="7" height="7" rx="1.5" />
          </svg>
          Dashboard
        </router-link>

        <!-- ĐÂY LÀ LIÊN KẾT CÓ THỂ BẤM, KHÔNG DÙNG dash-nav-disabled -->
        <router-link to="/admin/products" class="category-nav-item">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="8.5" cy="8.5" r="1.5" />
            <path d="m21 15-5-5L5 21" />
          </svg>
          Quản lý tranh
        </router-link>

        <router-link
          to="/admin/categories"
          class="category-nav-item category-nav-active"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 5h16v14H4z" />
            <path d="M8 9h8M8 13h8M8 17h5" />
          </svg>
          Danh mục sản phẩm
        </router-link>


<!-- Quản lý bài viết -->
<router-link
  to="/admin/blogs"
  class="category-nav-item"
  active-class="category-nav-active"
>
  <svg viewBox="0 0 24 24" aria-hidden="true">
    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
  </svg>
  Quản lý bài viết
</router-link>

      </nav>

      <div class="category-sidebar-bottom">
        <div class="category-profile">
          <div class="category-avatar">{{ username.charAt(0).toUpperCase() }}</div>
          <div class="category-profile-info">
            <strong>{{ username }}</strong>
            <small>Quản trị viên</small>
          </div>
          <span class="category-online-dot"></span>
        </div>
        <button class="category-logout" type="button" @click="handleLogout">
          Đăng xuất
        </button>
      </div>
    </aside>

    <main class="category-main">
      <header class="category-topbar">
        <div>
          <p>Trang quản trị / Danh mục sản phẩm</p>
          <h1>Danh mục sản phẩm</h1>
        </div>
        <router-link to="/" class="category-view-site">Xem website ↗</router-link>
      </header>

      <section class="category-content">
        <div class="category-intro">
          <div>
            <span>QUẢN LÝ DANH MỤC</span>
            <h2>Các danh mục tranh</h2>
            <p>Quản lý và phân loại các dòng tranh trên website Duy Chân Dung.</p>
          </div>
          <button class="category-primary-btn" type="button" @click="openCreateModal">
            ＋ Thêm danh mục
          </button>
        </div>

        <div v-if="errorMessage" class="category-alert category-alert-error" role="alert">
          <span>{{ errorMessage }}</span>
          <button type="button" @click="errorMessage = ''">Đóng</button>
        </div>
        <div v-if="successMessage" class="category-alert category-alert-success" role="status">
          <span>{{ successMessage }}</span>
          <button type="button" @click="successMessage = ''">Đóng</button>
        </div>

        <div class="category-summary">
          <div class="category-summary-icon">▤</div>
          <div>
            <span>Tổng danh mục</span>
            <strong>{{ categories.length }}</strong>
          </div>
        </div>

        <section class="category-table-panel">
          <div class="category-table-toolbar">
            <div>
              <h3>Danh sách danh mục</h3>
              <p>Dữ liệu được tải từ Laravel API.</p>
            </div>
            <div class="category-search">
              <span>⌕</span>
              <input v-model="searchQuery" type="search" placeholder="Tìm tên hoặc slug..." />
            </div>
          </div>

          <div class="category-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Danh mục</th>
                  <th>Slug</th>
                  <th>Số sản phẩm</th>
                  <th>Trạng thái</th>
                  <th>Thao tác</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="loading">
                  <td colspan="5" class="category-empty">Đang tải danh mục...</td>
                </tr>
                <tr v-else-if="filteredCategories.length === 0">
                  <td colspan="5" class="category-empty">
                    {{ searchQuery ? 'Không tìm thấy danh mục phù hợp.' : 'Chưa có danh mục nào.' }}
                  </td>
                </tr>
                <tr v-for="category in filteredCategories" :key="category.id">
                  <td>
                    <div class="category-name-cell">
                      <div class="category-row-icon">▤</div>
                      <div>
                        <strong>{{ category.name }}</strong>
                        <small>#{{ category.id }}</small>
                      </div>
                    </div>
                  </td>
                  <td><code>{{ category.slug }}</code></td>
                  <td>{{ typeof category.products_count === 'number' ? category.products_count : '—' }}</td>
                  <td>
                    <span class="category-status" :class="isActive(category) ? 'category-status-active' : 'category-status-inactive'">
                      {{ isActive(category) ? 'Hoạt động' : 'Tạm ẩn' }}
                    </span>
                  </td>
                  <td>
                    <div class="category-actions">
                      <button type="button" class="category-edit-btn" @click="openEditModal(category)">Sửa</button>
                      <button type="button" class="category-delete-btn" @click="deleteCategory(category)">Xóa</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="category-table-footer">
            Hiển thị {{ filteredCategories.length }} / {{ categories.length }} danh mục
            <button class="category-refresh-btn" type="button" :disabled="loading" @click="loadCategories">
              ↻ Làm mới
            </button>
          </div>
        </section>
      </section>
    </main>

    <div v-if="showModal" class="category-modal-overlay" @click.self="closeModal">
      <section class="category-modal" role="dialog" aria-modal="true" aria-labelledby="category-modal-title">
        <header class="category-modal-header">
          <div>
            <span>QUẢN LÝ DANH MỤC</span>
            <h2 id="category-modal-title">{{ editingId !== null ? 'Cập nhật danh mục' : 'Thêm danh mục mới' }}</h2>
          </div>
          <button class="category-modal-close" type="button" :disabled="saving" aria-label="Đóng" @click="closeModal">×</button>
        </header>

        <form class="category-form" @submit.prevent="saveCategory">
          <label>
            Tên danh mục <span class="required">*</span>
            <input v-model="form.name" type="text" maxlength="255" placeholder="Ví dụ: Tranh chân dung" required @input="updateSlug" />
          </label>
          <label>
            Slug <span class="required">*</span>
            <input v-model="form.slug" type="text" maxlength="255" placeholder="tranh-chan-dung" required @input="slugManuallyEdited = true" />
            <small>Đường dẫn thân thiện, không dấu và không có khoảng trắng.</small>
          </label>
          <label>
            Mô tả
            <textarea v-model="form.description" rows="4" placeholder="Mô tả ngắn về danh mục..."></textarea>
          </label>
          <label class="category-checkbox">
            <input v-model="form.is_active" type="checkbox" />
            <span>Đang hoạt động</span>
          </label>
          <p v-if="errorMessage" class="category-form-error">{{ errorMessage }}</p>
          <footer class="category-form-footer">
            <button type="button" class="category-cancel-btn" :disabled="saving" @click="closeModal">Hủy</button>
            <button type="submit" class="category-primary-btn" :disabled="saving">
              {{ saving ? 'Đang lưu...' : editingId !== null ? 'Lưu thay đổi' : 'Thêm danh mục' }}
            </button>
          </footer>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.category-layout, .category-layout * { box-sizing: border-box; }
.category-layout {
  --green: #28784e; --green-dark: #185938; --bg: #f5f7f5; --text: #26372c; --muted: #89958c;
  display: flex; min-height: 100vh; background: var(--bg); color: var(--text);
  font-family: "Segoe UI", Tahoma, Arial, sans-serif; font-size: 14px;
}
.category-sidebar {
  position: sticky; top: 0; display: flex; flex: 0 0 245px; flex-direction: column;
  width: 245px; height: 100vh; padding: 25px 16px 17px; border-right: 1px solid #e7ece8; background: #fff;
}
.category-brand { display: flex; align-items: center; gap: 10px; margin-bottom: 38px; padding: 0 7px; color: var(--text); text-decoration: none; }
.category-brand img { width: 42px; height: 42px; border-radius: 9px; object-fit: contain; }
.category-brand strong, .category-brand small { display: block; }
.category-brand strong { font-size: 14px; font-weight: 750; }
.category-brand small { margin-top: 4px; color: #929d95; font-size: 9px; letter-spacing: 1.5px; }
.category-menu-label { margin: 0 10px 12px; color: #a0aaa3; font-size: 10px; font-weight: 800; letter-spacing: 1.1px; }
.category-nav { display: grid; gap: 7px; }
.category-nav-item {
  display: flex; min-height: 44px; align-items: center; gap: 12px; padding: 10px 12px;
  border-radius: 9px; color: #6d7b70; font-size: 12px; font-weight: 600; text-decoration: none;
  cursor: pointer; transition: color .2s, background .2s;
}
.category-nav-item svg { width: 19px; height: 19px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.category-nav-item:hover { background: #f2f7f3; color: var(--green-dark); }
.category-nav-active { background: #eaf5ed; color: var(--green-dark); }
.category-nav-disabled { opacity: .65; cursor: not-allowed; }
.category-nav-disabled small { margin-left: auto; font-size: 9px; }
.category-sidebar-bottom { margin-top: auto; }
.category-profile { display: flex; align-items: center; gap: 10px; padding: 14px 4px; border-top: 1px solid #edf0ed; }
.category-avatar { display: grid; width: 37px; height: 37px; flex-shrink: 0; place-items: center; border-radius: 11px; background: #deeee2; color: #22633e; font-weight: 800; }
.category-profile-info { min-width: 0; flex: 1; }
.category-profile strong, .category-profile small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.category-profile strong { font-size: 11px; }
.category-profile small { margin-top: 4px; color: var(--muted); font-size: 10px; }
.category-online-dot { width: 7px; height: 7px; flex-shrink: 0; border-radius: 50%; background: #31ad68; }
.category-logout { display: flex; width: 100%; align-items: center; padding: 11px 12px; border: 1px solid #e8ece9; border-radius: 9px; background: #fff; color: #657369; font-size: 12px; font-weight: 600; cursor: pointer; }
.category-logout:hover { border-color: #f0d5d5; background: #fff4f4; color: #c34242; }
.category-main { min-width: 0; flex: 1; }
.category-topbar { display: flex; min-height: 88px; align-items: center; justify-content: space-between; gap: 20px; padding: 16px 34px; border-bottom: 1px solid #e8ede9; background: #fff; }
.category-topbar p { margin: 0 0 6px; color: #8b978e; font-size: 10px; }
.category-topbar h1 { margin: 0; color: #23382a; font-size: 22px; font-weight: 800; }
.category-view-site { color: #607267; font-size: 12px; text-decoration: none; }
.category-view-site:hover { color: var(--green); }
.category-content { width: 100%; max-width: 1450px; margin: 0 auto; padding: 28px 34px; }
.category-intro { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
.category-intro > div > span, .category-modal-header span { color: #548665; font-size: 9px; font-weight: 800; letter-spacing: 1.4px; }
.category-intro h2 { margin: 8px 0; color: #244b31; font-size: 25px; }
.category-intro p { margin: 0; color: #7b897f; font-size: 12px; line-height: 1.7; }
.category-primary-btn { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 7px; padding: 10px 15px; border: 1px solid var(--green); border-radius: 8px; background: var(--green); color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.category-primary-btn:hover { background: var(--green-dark); }
.category-primary-btn:disabled { opacity: .6; cursor: wait; }
.category-alert { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 15px; padding: 12px 15px; border: 1px solid; border-radius: 9px; font-size: 12px; line-height: 1.6; }
.category-alert button { flex-shrink: 0; border: 0; background: transparent; color: inherit; font-size: 11px; cursor: pointer; }
.category-alert-error { border-color: #f0d4d4; background: #fff4f4; color: #a73e3e; }
.category-alert-success { border-color: #d5eadb; background: #f1faf3; color: #287346; }
.category-summary { display: flex; width: fit-content; min-width: 210px; align-items: center; gap: 13px; margin-bottom: 22px; padding: 17px 20px; border: 1px solid #e7ece8; border-radius: 12px; background: #fff; }
.category-summary-icon, .category-row-icon { display: grid; flex-shrink: 0; place-items: center; border-radius: 10px; background: #e7f5eb; color: #338255; }
.category-summary-icon { width: 43px; height: 43px; font-size: 22px; }
.category-summary span, .category-summary strong { display: block; }
.category-summary span { color: #7b897f; font-size: 11px; }
.category-summary strong { margin-top: 4px; font-size: 23px; }
.category-table-panel { overflow: hidden; border: 1px solid #e7ece8; border-radius: 13px; background: #fff; }
.category-table-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 22px; }
.category-table-toolbar h3 { margin: 0; color: #2b3d30; font-size: 14px; }
.category-table-toolbar p { margin: 6px 0 0; color: #929d94; font-size: 10px; }
.category-search { display: flex; width: min(100%, 280px); align-items: center; gap: 8px; padding: 9px 11px; border: 1px solid #e5ebe6; border-radius: 8px; color: #7c8b80; }
.category-search input { width: 100%; min-width: 0; border: 0; outline: none; background: transparent; color: var(--text); font: inherit; font-size: 11px; }
.category-table-wrap { overflow-x: auto; }
.category-table-wrap table { width: 100%; border-collapse: collapse; text-align: left; }
.category-table-wrap th { padding: 13px 18px; border-top: 1px solid #edf0ed; border-bottom: 1px solid #edf0ed; background: #fafcfb; color: #7c897f; font-size: 10px; font-weight: 700; white-space: nowrap; }
.category-table-wrap td { padding: 15px 18px; border-bottom: 1px solid #f0f2f0; color: #59685e; font-size: 11px; white-space: nowrap; }
.category-table-wrap tbody tr:last-child td { border-bottom: 0; }
.category-name-cell { display: flex; align-items: center; gap: 10px; }
.category-row-icon { width: 35px; height: 35px; }
.category-name-cell strong, .category-name-cell small { display: block; }
.category-name-cell strong { color: #34483a; font-size: 11px; }
.category-name-cell small { margin-top: 4px; color: #9aa49d; font-size: 9px; }
.category-table-wrap code { padding: 4px 6px; border-radius: 5px; background: #f5f7f5; color: #5d7563; font-size: 10px; }
.category-status { display: inline-block; padding: 5px 8px; border-radius: 5px; font-size: 10px; }
.category-status-active { background: #e8f7ec; color: #287b48; }
.category-status-inactive { background: #f0f1f0; color: #7c857f; }
.category-actions { display: flex; gap: 7px; }
.category-actions button { padding: 6px 9px; border: 1px solid; border-radius: 6px; font-size: 10px; cursor: pointer; }
.category-edit-btn { border-color: #dce9df !important; background: #f3faf5; color: #34764b; }
.category-delete-btn { border-color: #f0dada !important; background: #fff6f6; color: #bd4545; }
.category-empty { height: 100px; color: #929d94 !important; text-align: center; }
.category-table-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-top: 1px solid #edf0ed; color: #929d94; font-size: 10px; }
.category-refresh-btn { padding: 7px 10px; border: 1px solid #e2e9e3; border-radius: 7px; background: #fff; color: #597362; cursor: pointer; }
.category-refresh-btn:disabled { opacity: .6; cursor: wait; }
.category-modal-overlay { position: fixed; z-index: 1000; inset: 0; display: flex; align-items: center; justify-content: center; overflow-y: auto; padding: 20px; background: rgb(22 39 28 / 45%); }
.category-modal { width: 100%; max-width: 520px; max-height: calc(100vh - 40px); overflow-y: auto; border: 1px solid #e8eee9; border-radius: 15px; background: #fff; box-shadow: 0 20px 70px rgb(0 0 0 / 15%); }
.category-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 15px; padding: 23px 25px 18px; border-bottom: 1px solid #edf0ed; }
.category-modal-header h2 { margin: 7px 0 0; color: #244b31; font-size: 20px; }
.category-modal-close { width: 30px; height: 30px; border: 0; border-radius: 7px; background: #f3f6f3; color: #617065; font-size: 23px; cursor: pointer; }
.category-form { display: grid; gap: 16px; padding: 23px 25px 25px; }
.category-form label { display: grid; gap: 7px; color: #435449; font-size: 11px; font-weight: 700; }
.category-form .required { color: #c64a4a; }
.category-form input:not([type="checkbox"]), .category-form textarea { width: 100%; padding: 11px 12px; border: 1px solid #dfe7e1; border-radius: 8px; outline: none; background: #fff; color: var(--text); font: inherit; font-size: 12px; font-weight: 400; }
.category-form input:focus, .category-form textarea:focus { border-color: #6fa681; box-shadow: 0 0 0 3px rgb(61 132 83 / 9%); }
.category-form textarea { resize: vertical; }
.category-form label > small { color: #929d94; font-size: 10px; font-weight: 400; }
.category-form .category-checkbox { display: flex; align-items: center; gap: 9px; }
.category-checkbox input { width: 16px; height: 16px; accent-color: var(--green); }
.category-form-error { margin: 0; color: #b63d3d; font-size: 11px; line-height: 1.6; }
.category-form-footer { display: flex; justify-content: flex-end; gap: 9px; padding-top: 8px; }
.category-cancel-btn { padding: 10px 15px; border: 1px solid #e1e7e2; border-radius: 8px; background: #fff; color: #637267; font-size: 12px; cursor: pointer; }
.category-cancel-btn:disabled { opacity: .6; }
@media (max-width: 1100px) { .category-sidebar { flex-basis: 220px; width: 220px; } .category-content { padding-right: 22px; padding-left: 22px; } .category-table-toolbar { align-items: flex-start; flex-direction: column; } }
@media (max-width: 760px) {
  .category-layout { display: block; }
  .category-sidebar { position: relative; width: 100%; height: auto; padding: 13px 15px; border-right: 0; border-bottom: 1px solid #e6ece7; }
  .category-brand { margin-bottom: 15px; }
  .category-menu-label, .category-sidebar-bottom { display: none; }
  .category-nav { display: flex; gap: 6px; overflow-x: auto; }
  .category-nav-item { flex-shrink: 0; min-height: 38px; padding: 8px; font-size: 10px; }
  .category-nav-item svg { width: 16px; height: 16px; }
  .category-nav-disabled small { display: none; }
  .category-topbar { min-height: 75px; padding: 14px 18px; }
  .category-topbar h1 { font-size: 18px; }
  .category-content { padding: 20px 15px; }
  .category-intro { align-items: flex-start; flex-direction: column; }
  .category-intro h2 { font-size: 22px; }
  .category-table-toolbar { padding: 16px; }
  .category-search { width: 100%; }
  .category-view-site { font-size: 10px; }
}
</style>
