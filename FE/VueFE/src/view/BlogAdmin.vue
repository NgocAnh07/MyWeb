<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'
import { useRouter } from 'vue-router'

interface Blog {
  id: number
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
const form = ref({ title: '', description: '' })
const currentPage = ref(1)
const lastPage = ref(1)
const totalBlogs = ref(0)

const filteredBlogs = computed(() => {
  const keyword = searchQuery.value.trim().toLocaleLowerCase('vi-VN')
  if (!keyword) return blogs.value
  return blogs.value.filter(blog =>
    blog.title.toLocaleLowerCase('vi-VN').includes(keyword) ||
    blog.description.toLocaleLowerCase('vi-VN').includes(keyword),
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

async function loadBlogs(page = currentPage.value) {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await axios.get(`${API_URL}/blogs?page=${page}`, getConfig())
    const payload: unknown = response.data
    blogs.value = extractList(payload)
    if (typeof payload === 'object' && payload !== null && !Array.isArray(payload)) {
      const data = payload as Record<string, unknown>
      currentPage.value = typeof data.current_page === 'number' ? data.current_page : page
      lastPage.value = typeof data.last_page === 'number' ? data.last_page : 1
      totalBlogs.value = typeof data.total === 'number' ? data.total : blogs.value.length
    } else {
      currentPage.value = 1
      lastPage.value = 1
      totalBlogs.value = blogs.value.length
    }
  } catch (error: unknown) {
    blogs.value = []
    totalBlogs.value = 0
    errorMessage.value = getErrorMessage(error)
  } finally {
    loading.value = false
  }
}

function clearImagePreview() {
  if (imagePreview.value.startsWith('blob:')) URL.revokeObjectURL(imagePreview.value)
  imagePreview.value = ''
}

function openCreateModal() {
  editingId.value = null
  form.value = { title: '', description: '' }
  selectedImage.value = null
  clearImagePreview()
  errorMessage.value = ''
  showModal.value = true
}

function openEditModal(blog: Blog) {
  editingId.value = blog.id
  form.value = { title: blog.title, description: blog.description ?? '' }
  selectedImage.value = null
  clearImagePreview()
  imagePreview.value = imageUrl(blog.img)
  errorMessage.value = ''
  showModal.value = true
}

function closeModal() {
  if (saving.value) return
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
  if (!form.value.title.trim()) {
    errorMessage.value = 'Vui lòng nhập tiêu đề bài viết.'
    return
  }
  if (!form.value.description.trim()) {
    errorMessage.value = 'Vui lòng nhập nội dung bài viết.'
    return
  }

  const body = new FormData()
  body.append('title', form.value.title.trim())
  body.append('description', form.value.description.trim())
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
    await loadBlogs(1)
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
    const page = blogs.value.length === 1 && currentPage.value > 1
      ? currentPage.value - 1
      : currentPage.value
    await loadBlogs(page)
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
  void loadBlogs(1)
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
          <div class="blog-toolbar"><div><h3>Danh sách bài viết</h3><p>Dữ liệu được tải từ Laravel API.</p></div><div class="blog-search"><span>⌕</span><input v-model="searchQuery" type="search" placeholder="Tìm tiêu đề hoặc nội dung..." /></div></div>
          <div class="blog-table-wrap">
            <table>
              <thead><tr><th>Bài viết</th><th>Ảnh</th><th>Mô tả</th><th>Thao tác</th></tr></thead>
              <tbody>
                <tr v-if="loading"><td colspan="4" class="blog-empty">Đang tải bài viết...</td></tr>
                <tr v-else-if="filteredBlogs.length === 0"><td colspan="4" class="blog-empty">{{ searchQuery ? 'Không tìm thấy bài viết phù hợp.' : 'Chưa có bài viết nào.' }}</td></tr>
                <tr v-for="blog in filteredBlogs" :key="blog.id">
                  <td><div class="blog-title-cell"><strong>{{ blog.title }}</strong><small>#{{ blog.id }}</small></div></td>
                  <td><img v-if="blog.img" class="blog-thumbnail" :src="imageUrl(blog.img)" :alt="blog.title" /><span v-else class="blog-no-image">Chưa có ảnh</span></td>
                  <td><div class="blog-description">{{ blog.description }}</div></td>
                  <td><div class="blog-actions"><button class="blog-edit-btn" @click="openEditModal(blog)">Sửa</button><button class="blog-delete-btn" @click="deleteBlog(blog)">Xóa</button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="blog-table-footer"><span>Tổng {{ totalBlogs }} bài viết · Trang {{ currentPage }} / {{ lastPage }}</span><div class="blog-pagination"><button :disabled="loading || currentPage <= 1" @click="loadBlogs(currentPage - 1)">Trước</button><button :disabled="loading || currentPage >= lastPage" @click="loadBlogs(currentPage + 1)">Sau</button></div></div>
        </section>
      </section>
    </main>

    <div v-if="showModal" class="blog-modal-overlay" @click.self="closeModal">
      <section class="blog-modal" role="dialog" aria-modal="true" aria-labelledby="blog-modal-title">
        <header class="blog-modal-header"><div><span>QUẢN LÝ NỘI DUNG</span><h2 id="blog-modal-title">{{ editingId !== null ? 'Cập nhật bài viết' : 'Thêm bài viết mới' }}</h2></div><button type="button" class="blog-modal-close" :disabled="saving" @click="closeModal">×</button></header>
        <form class="blog-form" @submit.prevent="saveBlog">
          <label>Tiêu đề bài viết <span class="required">*</span><input v-model="form.title" type="text" maxlength="255" placeholder="Nhập tiêu đề" required /></label>
          <label>Mô tả / Nội dung <span class="required">*</span><textarea v-model="form.description" rows="5" placeholder="Nhập nội dung bài viết..." required /></label>
          <label>Ảnh bài viết<input type="file" accept="image/*" @change="onImageChange" /><small>Ảnh tối đa 2 MB. Khi sửa, không chọn ảnh mới sẽ giữ ảnh cũ.</small></label>
          <div v-if="imagePreview" class="blog-image-preview"><img :src="imagePreview" alt="Xem trước ảnh bài viết" /></div>
          <p v-if="errorMessage" class="blog-form-error">{{ errorMessage }}</p>
          <footer class="blog-form-footer"><button type="button" class="blog-cancel-btn" :disabled="saving" @click="closeModal">Hủy</button><button type="submit" class="blog-primary-btn" :disabled="saving">{{ saving ? 'Đang lưu...' : editingId !== null ? 'Lưu thay đổi' : 'Thêm bài viết' }}</button></footer>
        </form>
      </section>
    </div>
  </div>
</template>

<style scoped>
.blog-layout,.blog-layout *{box-sizing:border-box}.blog-layout{--green:#28784e;--green-dark:#185938;--bg:#f5f7f5;--text:#26372c;display:flex;min-height:100vh;background:var(--bg);color:var(--text);font-family:"Segoe UI",Tahoma,Arial,sans-serif;font-size:14px}.blog-sidebar{position:sticky;top:0;display:flex;flex:0 0 245px;flex-direction:column;width:245px;height:100vh;padding:25px 16px 17px;border-right:1px solid #e7ece8;background:#fff}.blog-brand{display:flex;align-items:center;gap:10px;margin-bottom:38px;padding:0 7px;color:var(--text);text-decoration:none}.blog-brand img{width:42px;height:42px;border-radius:9px;object-fit:contain}.blog-brand strong,.blog-brand small{display:block}.blog-brand strong{font-size:14px}.blog-brand small{margin-top:4px;color:#929d95;font-size:9px;letter-spacing:1.5px}.blog-menu-label{margin:0 10px 12px;color:#a0aaa3;font-size:10px;font-weight:800;letter-spacing:1.1px}.blog-nav{display:grid;gap:7px}.blog-nav-item{display:flex;min-height:44px;align-items:center;gap:12px;padding:10px 12px;border-radius:9px;color:#6d7b70;font-size:12px;font-weight:600;text-decoration:none}.blog-nav-item span{width:19px;flex-shrink:0;font-size:18px;text-align:center}.blog-nav-active{background:#eaf5ed;color:var(--green-dark)}.blog-sidebar-bottom{margin-top:auto}.blog-profile{display:flex;align-items:center;gap:10px;padding:14px 4px;border-top:1px solid #edf0ed}.blog-avatar{display:grid;width:37px;height:37px;flex-shrink:0;place-items:center;border-radius:11px;background:#deeee2;color:#22633e;font-weight:800}.blog-profile strong,.blog-profile small{display:block}.blog-profile strong{font-size:11px}.blog-profile small{margin-top:4px;color:#89958c;font-size:10px}.blog-logout{width:100%;padding:11px;border:1px solid #e8ece9;border-radius:9px;background:white;color:#657369;cursor:pointer}.blog-logout:hover{background:#fff4f4;color:#c34242}.blog-main{min-width:0;flex:1}.blog-topbar{display:flex;min-height:88px;align-items:center;justify-content:space-between;gap:20px;padding:16px 34px;border-bottom:1px solid #e8ede9;background:#fff}.blog-topbar p{margin:0 0 6px;color:#8b978e;font-size:10px}.blog-topbar h1{margin:0;color:#23382a;font-size:22px}.blog-view-site{color:#607267;font-size:12px;text-decoration:none}.blog-content{width:100%;max-width:1450px;margin:0 auto;padding:28px 34px}.blog-intro{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:24px}.blog-intro>div>span,.blog-modal-header span{color:#548665;font-size:9px;font-weight:800;letter-spacing:1.4px}.blog-intro h2{margin:8px 0;color:#244b31;font-size:25px}.blog-intro p{margin:0;color:#7b897f;font-size:12px;line-height:1.7}.blog-primary-btn{display:inline-flex;min-height:40px;align-items:center;justify-content:center;gap:7px;padding:10px 15px;border:1px solid var(--green);border-radius:8px;background:var(--green);color:#fff;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}.blog-primary-btn:hover{background:var(--green-dark)}.blog-primary-btn:disabled{opacity:.6;cursor:wait}.blog-alert{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px;padding:12px 15px;border:1px solid;border-radius:9px;font-size:12px;line-height:1.6}.blog-alert button{border:0;background:transparent;color:inherit;cursor:pointer}.blog-alert-error{border-color:#f0d4d4;background:#fff4f4;color:#a73e3e}.blog-alert-success{border-color:#d5eadb;background:#f1faf3;color:#287346}.blog-summary{display:flex;width:fit-content;min-width:210px;align-items:center;gap:13px;margin-bottom:22px;padding:17px 20px;border:1px solid #e7ece8;border-radius:12px;background:#fff}.blog-summary-icon{display:grid;width:43px;height:43px;place-items:center;border-radius:10px;background:#e7f5eb;color:#338255;font-size:22px}.blog-summary span,.blog-summary strong{display:block}.blog-summary span{color:#7b897f;font-size:11px}.blog-summary strong{margin-top:4px;font-size:23px}.blog-table-panel{overflow:hidden;border:1px solid #e7ece8;border-radius:13px;background:#fff}.blog-toolbar{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px}.blog-toolbar h3{margin:0;color:#2b3d30;font-size:14px}.blog-toolbar p{margin:6px 0 0;color:#929d94;font-size:10px}.blog-search{display:flex;width:min(100%,300px);align-items:center;gap:8px;padding:9px 11px;border:1px solid #e5ebe6;border-radius:8px;color:#7c8b80}.blog-search input{width:100%;min-width:0;border:0;outline:none;background:transparent;color:var(--text);font:inherit;font-size:11px}.blog-table-wrap{overflow-x:auto}.blog-table-wrap table{width:100%;border-collapse:collapse;text-align:left}.blog-table-wrap th{padding:13px 18px;border-top:1px solid #edf0ed;border-bottom:1px solid #edf0ed;background:#fafcfb;color:#7c897f;font-size:10px;font-weight:700;white-space:nowrap}.blog-table-wrap td{padding:15px 18px;border-bottom:1px solid #f0f2f0;color:#59685e;font-size:11px}.blog-table-wrap tbody tr:last-child td{border-bottom:0}.blog-title-cell{min-width:160px}.blog-title-cell strong,.blog-title-cell small{display:block}.blog-title-cell strong{max-width:230px;color:#34483a;font-size:11px}.blog-title-cell small{margin-top:4px;color:#9aa49d;font-size:9px}.blog-thumbnail{width:58px;height:48px;border:1px solid #e6ece7;border-radius:7px;object-fit:cover}.blog-no-image{color:#929d94;font-size:10px;white-space:nowrap}.blog-description{display:-webkit-box;max-width:360px;overflow:hidden;line-clamp:3;-webkit-box-orient:vertical;-webkit-line-clamp:3;line-height:1.6}.blog-actions{display:flex;gap:7px}.blog-actions button{padding:6px 9px;border:1px solid;border-radius:6px;font-size:10px;cursor:pointer;white-space:nowrap}.blog-edit-btn{border-color:#dce9df!important;background:#f3faf5;color:#34764b}.blog-delete-btn{border-color:#f0dada!important;background:#fff6f6;color:#bd4545}.blog-empty{height:100px;color:#929d94!important;text-align:center}.blog-table-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 20px;border-top:1px solid #edf0ed;color:#929d94;font-size:10px}.blog-pagination{display:flex;gap:7px}.blog-pagination button{padding:7px 10px;border:1px solid #e2e9e3;border-radius:7px;background:white;color:#597362;cursor:pointer}.blog-pagination button:disabled{opacity:.45;cursor:not-allowed}.blog-modal-overlay{position:fixed;z-index:1000;inset:0;display:flex;align-items:center;justify-content:center;overflow-y:auto;padding:20px;background:rgb(22 39 28 / 45%)}.blog-modal{width:100%;max-width:550px;max-height:calc(100vh - 40px);overflow-y:auto;border:1px solid #e8eee9;border-radius:15px;background:#fff;box-shadow:0 20px 70px rgb(0 0 0 / 15%)}.blog-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;padding:23px 25px 18px;border-bottom:1px solid #edf0ed}.blog-modal-header h2{margin:7px 0 0;color:#244b31;font-size:20px}.blog-modal-close{width:30px;height:30px;border:0;border-radius:7px;background:#f3f6f3;color:#617065;font-size:23px;cursor:pointer}.blog-form{display:grid;gap:16px;padding:23px 25px 25px}.blog-form label{display:grid;gap:7px;color:#435449;font-size:11px;font-weight:700}.blog-form .required{color:#c64a4a}.blog-form input:not([type=file]),.blog-form textarea{width:100%;padding:11px 12px;border:1px solid #dfe7e1;border-radius:8px;outline:none;background:white;color:var(--text);font:inherit;font-size:12px;font-weight:400}.blog-form input[type=file]{width:100%;padding:10px;border:1px dashed #d4e1d6;border-radius:8px;background:#fafcfb;color:#59685e;font-size:11px}.blog-form input:focus,.blog-form textarea:focus{border-color:#6fa681;box-shadow:0 0 0 3px rgb(61 132 83 / 9%)}.blog-form textarea{resize:vertical}.blog-form label small{color:#929d94;font-size:10px;font-weight:400;line-height:1.6}.blog-image-preview{display:flex;justify-content:center;padding:10px;border:1px solid #edf0ed;border-radius:8px;background:#fafcfb}.blog-image-preview img{max-width:100%;max-height:200px;border-radius:6px;object-fit:contain}.blog-form-error{margin:0;color:#b63d3d;font-size:11px;line-height:1.6}.blog-form-footer{display:flex;justify-content:flex-end;gap:9px;padding-top:8px}.blog-cancel-btn{padding:10px 15px;border:1px solid #e1e7e2;border-radius:8px;background:white;color:#637267;font-size:12px;cursor:pointer}.blog-cancel-btn:disabled{opacity:.6}@media(max-width:1100px){.blog-sidebar{flex-basis:220px;width:220px}.blog-content{padding-right:22px;padding-left:22px}.blog-toolbar{align-items:flex-start;flex-direction:column}}@media(max-width:760px){.blog-layout{display:block}.blog-sidebar{position:relative;width:100%;height:auto;padding:13px 15px;border-right:0;border-bottom:1px solid #e6ece7}.blog-brand{margin-bottom:15px}.blog-menu-label,.blog-sidebar-bottom{display:none}.blog-nav{display:flex;gap:6px;overflow-x:auto}.blog-nav-item{flex-shrink:0;min-height:38px;padding:8px;font-size:10px}.blog-topbar{min-height:75px;padding:14px 18px}.blog-topbar h1{font-size:18px}.blog-content{padding:20px 15px}.blog-intro{align-items:flex-start;flex-direction:column}.blog-intro h2{font-size:22px}.blog-toolbar{padding:16px}.blog-search{width:100%}}
</style>
