<script setup lang="ts">

import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'

import { useRouter } from 'vue-router'

interface Category {

  id: number

  name: string

  is_active?: boolean | number | string

}

interface Product {

  id: number

  name: string

  description: string

  img: string | null

  category_id?: number | null

  category?: Category

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

const form = ref({

  name: '',

  description: '',

  category_id: '',

})

const currentPage = ref(1)

const lastPage = ref(1)

const totalProducts = ref(0)

const filteredProducts = computed(() => {

  const keyword = searchQuery.value.trim().toLowerCase()

  if (!keyword) return products.value

  return products.value.filter(product => {

    const categoryName =

      product.category?.name ??

      getCategoryName(product.category_id)

    return (

      product.name.toLowerCase().includes(keyword) ||

      categoryName.toLowerCase().includes(keyword)

    )

  })

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

async function loadProducts(page = currentPage.value) {

  loading.value = true

  errorMessage.value = ''

  try {

    const response = await axios.get(

      `${API_URL}/products?page=${page}`,

      getConfig(),

    )

    const payload = response.data

    products.value = extractList<Product>(payload)

    if (

      typeof payload === 'object' &&

      payload !== null &&

      !Array.isArray(payload)

    ) {

      const data = payload as Record<string, unknown>

      currentPage.value =

        typeof data.current_page === 'number' ? data.current_page : page

      lastPage.value =

        typeof data.last_page === 'number' ? data.last_page : 1

      totalProducts.value =

        typeof data.total === 'number' ? data.total : products.value.length

    } else {

      currentPage.value = 1

      lastPage.value = 1

      totalProducts.value = products.value.length

    }

  } catch (error: unknown) {

    products.value = []

    totalProducts.value = 0

    errorMessage.value = getErrorMessage(error)

  } finally {

    loading.value = false

  }

}

function clearImagePreview() {

  if (imagePreview.value.startsWith('blob:')) {

    URL.revokeObjectURL(imagePreview.value)

  }

  imagePreview.value = ''

}

function openCreateModal() {

  editingId.value = null

  form.value = {

    name: '',

    description: '',

    category_id: '',

  }

  selectedImage.value = null

  clearImagePreview()

  errorMessage.value = ''

  showModal.value = true

}

function openEditModal(product: Product) {

  editingId.value = product.id

  form.value = {

    name: product.name,

    description: product.description ?? '',

    category_id: String(

      product.category_id ?? product.category?.id ?? '',

    ),

  }

  selectedImage.value = null

  clearImagePreview()

  imagePreview.value = imageUrl(product.img)

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

async function saveProduct() {

  errorMessage.value = ''

  successMessage.value = ''

  if (!form.value.name.trim()) {

    errorMessage.value = 'Vui lòng nhập tên tranh.'

    return

  }

  if (!form.value.description.trim()) {

    errorMessage.value = 'Vui lòng nhập mô tả tranh.'

    return

  }

  if (!form.value.category_id) {

    errorMessage.value = 'Vui lòng chọn danh mục sản phẩm.'

    return

  }

  const body = new FormData()

  body.append('name', form.value.name.trim())

  body.append('description', form.value.description.trim())

  body.append('category_id', form.value.category_id)

  if (selectedImage.value) {

    body.append('img', selectedImage.value)

  }

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

                placeholder="Tìm tên tranh hoặc danh mục..."

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

                <tr v-for="product in filteredProducts" :key="product.id">

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

                      {{ product.description }}

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

              Tổng {{ totalProducts }} sản phẩm · Trang {{ currentPage }} /

              {{ lastPage }}

            </span>

            <div class="product-pagination">

              <button

                :disabled="loading || currentPage <= 1"

                @click="loadProducts(currentPage - 1)"

              >

                Trước

              </button>

              <button

                :disabled="loading || currentPage >= lastPage"

                @click="loadProducts(currentPage + 1)"

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

          <label>

            Mô tả <span class="required">*</span>

            <textarea

              v-model="form.description"

              rows="4"

              placeholder="Mô tả nội dung tranh..."

              required

            ></textarea>

          </label>

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

          <p v-if="errorMessage" class="product-form-error">

            {{ errorMessage }}

          </p>

          <footer class="product-form-footer">

            <button

              type="button"

              class="product-cancel-btn"

              :disabled="saving"

              @click="closeModal"

            >

              Hủy

            </button>

            <button

              type="submit"

              class="product-primary-btn"

              :disabled="saving || categories.length === 0"

            >

              {{

                saving

                  ? 'Đang lưu...'

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

</style>
