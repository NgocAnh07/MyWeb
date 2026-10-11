<script setup lang="ts">

import { onMounted, ref } from 'vue'

import axios from 'axios'
import { API_URL } from '../config/api'

import { useRouter } from 'vue-router'

const router = useRouter()

const username = ref('Admin')

const productCount = ref<number | null>(null)

const blogCount = ref<number | null>(null)

const loading = ref(true)

const apiStatus = ref<'loading' | 'online' | 'partial' | 'offline'>('loading')

const errorMessage = ref('')

// Đọc thông tin tài khoản đã đăng nhập

function loadUserInfo() {

  try {

    const savedUser = localStorage.getItem('auth_user')

    if (savedUser) {

      const user = JSON.parse(savedUser)

      if (user?.username) {

        username.value = user.username

      }

    }

  } catch {

    username.value = 'Admin'

  }

}

// Đếm số bản ghi từ các dạng JSON phổ biến của Laravel

function countRecords(payload: unknown): number {
  if (Array.isArray(payload)) {
    return payload.length
  }

  if (typeof payload !== 'object' || payload === null) {
    return 0
  }

  const data = payload as Record<string, unknown>

  if (typeof data.total === 'number') {
    return data.total
  }

  if (Array.isArray(data.data)) {
    const meta = data.meta

    if (typeof meta === 'object' && meta !== null) {
      const metaTotal = (meta as Record<string, unknown>).total
      if (typeof metaTotal === 'number') {
        return metaTotal
      }
    }

    return data.data.length
  }

  return 0
}

// Tải thống kê từ API

async function loadDashboard() {

  loading.value = true

  errorMessage.value = ''

  const token = localStorage.getItem('auth_token')

  const config = {

    headers: {

      Accept: 'application/json',

      ...(token ? { Authorization: `Bearer ${token}` } : {}),

    },

  }

  const results = await Promise.allSettled([

    axios.get(`${API_URL}/products`, config),

    axios.get(`${API_URL}/blogs`, config),

  ])

  const productsResult = results[0]

  const blogsResult = results[1]

  if (productsResult.status === 'fulfilled') {

    productCount.value = countRecords(productsResult.value.data)

  } else {

    productCount.value = null

  }

  if (blogsResult.status === 'fulfilled') {

    blogCount.value = countRecords(blogsResult.value.data)

  } else {

    blogCount.value = null

  }

  const successCount = results.filter(

    result => result.status === 'fulfilled',

  ).length

  if (successCount === 2) {

    apiStatus.value = 'online'

  } else if (successCount === 1) {

    apiStatus.value = 'partial'

    errorMessage.value = 'Một trong hai API chưa thể tải dữ liệu.'

  } else {

    apiStatus.value = 'offline'

    errorMessage.value =

      'Không tải được thống kê. Hãy kiểm tra Laravel và kết nối API.'

  }

  loading.value = false

}

// Đăng xuất

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

    // Vẫn xóa phiên đăng nhập phía trình duyệt nếu API lỗi

  } finally {

    localStorage.removeItem('auth_token')

    localStorage.removeItem('auth_user')

    window.dispatchEvent(new Event('auth-changed'))

    await router.replace('/login')

  }

}

function formatNumber(value: number | null): string {

  if (value === null) return '—'

  return new Intl.NumberFormat('vi-VN').format(value)

}

onMounted(() => {

  loadUserInfo()

  loadDashboard()

})

</script>

<template>

  <div class="dash-layout">

    <!-- SIDEBAR -->

    <aside class="dash-sidebar">

      <router-link to="/" class="dash-brand">

        <img src="/assets/logo.jpeg" alt="Duy Chân Dung" />

        <span>

          <strong>Duy Chân Dung</strong>

          <small>ADMIN PANEL</small>

        </span>

      </router-link>

      <div class="dash-menu-label">TỔNG QUAN</div>

      <nav class="dash-nav">
          <router-link to="/admin" class="dash-nav-item dash-nav-active">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <rect x="3" y="3" width="7" height="7" rx="1.5" />
              <rect x="14" y="3" width="7" height="7" rx="1.5" />
              <rect x="3" y="14" width="7" height="7" rx="1.5" />
              <rect x="14" y="14" width="7" height="7" rx="1.5" />
            </svg>
            Dashboard
          </router-link>

          <router-link to="/admin/products" class="dash-nav-item">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <rect x="3" y="3" width="18" height="18" rx="2" />
              <circle cx="8.5" cy="8.5" r="1.5" />
              <path d="m21 15-5-5L5 21" />
            </svg>
            Quản lý tranh
          </router-link>

          <router-link to="/admin/categories" class="dash-nav-item">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M4 5h16v14H4z" />
              <path d="M8 9h8M8 13h8M8 17h5" />
            </svg>
            Danh mục sản phẩm
          </router-link>

         
        <router-link
  to="/admin/blogs"
  class="dash-nav-item"
>
  <svg viewBox="0 0 24 24" aria-hidden="true">
    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
  </svg>
  Quản lý bài viết
        </router-link>

        </nav>

      <div class="dash-sidebar-bottom">

        <div class="dash-admin-profile">

          <div class="dash-avatar">

            {{ username.charAt(0).toUpperCase() }}

          </div>

          <div class="dash-profile-info">

            <strong>{{ username }}</strong>

            <span>Quản trị viên</span>

          </div>

          <span class="dash-online-dot"></span>

        </div>

        <button class="dash-logout" type="button" @click="handleLogout">

          <svg viewBox="0 0 24 24" aria-hidden="true">

            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />

            <path d="m16 17 5-5-5-5" />

            <path d="M21 12H9" />

          </svg>

          Đăng xuất

        </button>

      </div>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="dash-main">

      <!-- TOPBAR -->

      <header class="dash-topbar">

        <div>

          <div class="dash-breadcrumb">Trang quản trị / Dashboard</div>

          <h1>Tổng quan</h1>

        </div>

        <div class="dash-topbar-right">

          <span class="dash-admin-badge">

            <span></span>

            Admin

          </span>

          <router-link to="/" class="dash-view-site">

            Xem website

            <svg viewBox="0 0 24 24" aria-hidden="true">

              <path d="M7 17 17 7M7 7h10v10" />

            </svg>

          </router-link>

        </div>

      </header>

      <div class="dash-content">

        <!-- WELCOME -->

        <section class="dash-welcome">

          <div class="dash-welcome-text">

            <span>KHÔNG GIAN QUẢN TRỊ</span>

            <h2>Xin chào, {{ username }}!</h2>

            <p>

              Chào mừng trở lại. Theo dõi tình hình website

              Duy Chân Dung ngay tại đây.

            </p>

          </div>

          <div class="dash-welcome-art" aria-hidden="true">

            <svg viewBox="0 0 180 145">

              <rect x="40" y="15" width="100" height="112" rx="9"

                fill="#ffffff" fill-opacity=".16"

                stroke="#ffffff" stroke-opacity=".65" stroke-width="2" />

              <rect x="51" y="26" width="78" height="90" rx="4"

                fill="#e3f4e8" />

              <circle cx="105" cy="49" r="10" fill="#e8c578" />

              <path d="M54 103 78 74l15 16 12-12 20 25Z"

                fill="#6fb18a" />

              <path d="m67 58 13-13 12 13"

                fill="none" stroke="#2f7650" stroke-width="3"

                stroke-linecap="round" />

              <path d="M20 117c17-9 29-8 41 0M133 18l9-8m0 16 10-3"

                fill="none" stroke="#ffffff" stroke-opacity=".8"

                stroke-width="3" stroke-linecap="round" />

            </svg>

          </div>

        </section>

        <!-- ERROR / API STATUS -->

        <div

          v-if="errorMessage"

          class="dash-notice"

          role="status"

        >

          <span class="dash-notice-dot"></span>

          {{ errorMessage }}

          <button type="button" @click="loadDashboard">

            Thử lại

          </button>

        </div>

        <!-- STAT CARDS -->

        <section class="dash-stats">

          <article class="dash-stat-card">

            <div class="dash-stat-top">

              <span class="dash-stat-icon dash-icon-green">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <rect x="3" y="3" width="18" height="18" rx="2" />

                  <circle cx="8.5" cy="8.5" r="1.5" />

                  <path d="m21 15-5-5L5 21" />

                </svg>

              </span>

              <span class="dash-stat-chip">Sản phẩm</span>

            </div>

            <p>Tổng số tranh</p>

            <strong>{{ loading ? '...' : formatNumber(productCount) }}</strong>

            <small>Số bản ghi trả về từ API sản phẩm</small>

          </article>

          <article class="dash-stat-card">

            <div class="dash-stat-top">

              <span class="dash-stat-icon dash-icon-purple">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />

                  <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />

                  <path d="M8 7h8M8 11h8" />

                </svg>

              </span>

              <span class="dash-stat-chip">Nội dung</span>

            </div>

            <p>Tổng bài viết</p>

            <strong>{{ loading ? '...' : formatNumber(blogCount) }}</strong>

            <small>Số bản ghi trả về từ API bài viết</small>

          </article>

          <article class="dash-stat-card">

            <div class="dash-stat-top">

              <span class="dash-stat-icon dash-icon-blue">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />

                  <path d="m9 12 2 2 4-4" />

                </svg>

              </span>

              <span class="dash-stat-chip">Bảo mật</span>

            </div>

            <p>Quyền tài khoản</p>

            <strong class="dash-stat-role">Quản trị viên</strong>

            <small>Đang ở khu vực dành cho Admin</small>

          </article>

          <article class="dash-stat-card">

            <div class="dash-stat-top">

              <span class="dash-stat-icon dash-icon-orange">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <rect x="3" y="4" width="18" height="16" rx="2" />

                  <path d="M3 10h18M8 4v6M16 4v6" />

                </svg>

              </span>

              <span

                class="dash-status-pill"

                :class="{

                  'dash-status-online': apiStatus === 'online',

                  'dash-status-partial': apiStatus === 'partial',

                  'dash-status-offline': apiStatus === 'offline',

                }"

              >

                {{ apiStatus === 'loading'

                  ? 'Đang kiểm tra'

                  : apiStatus === 'online'

                    ? 'Đang hoạt động'

                    : apiStatus === 'partial'

                      ? 'Một phần lỗi'

                      : 'Mất kết nối'

                }}

              </span>

            </div>

            <p>Trạng thái API</p>

            <strong class="dash-stat-role">

              {{ apiStatus === 'loading'

                ? 'Đang tải'

                : apiStatus === 'online'

                  ? 'Ổn định'

                  : apiStatus === 'partial'

                    ? 'Cần kiểm tra'

                    : 'Không kết nối'

              }}

            </strong>

            <small>Kiểm tra API tranh và bài viết</small>

          </article>

        </section>

        <!-- LOWER PANELS -->

        <section class="dash-bottom-grid">

          <article class="dash-panel">

            <div class="dash-panel-heading">

              <div>

                <h3>Tình trạng hệ thống</h3>

                <p>Thông tin kết nối dữ liệu</p>

              </div>

              <button

                class="dash-refresh"

                type="button"

                :disabled="loading"

                @click="loadDashboard"

                aria-label="Làm mới dữ liệu"

              >

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <path d="M20 7v5h-5" />

                  <path d="M4 17v-5h5" />

                  <path d="M5.6 9A7 7 0 0 1 18 6l2 6M4 12l2 6a7 7 0 0 0 12.4-3" />

                </svg>

                Làm mới

              </button>

            </div>

            <div class="dash-system-row">

              <span class="dash-system-icon">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <path d="M4 5h16v14H4z" />

                  <path d="M8 9h8M8 13h5" />

                </svg>

              </span>

              <div class="dash-system-info">

                <strong>API sản phẩm</strong>

                <small>/api/products</small>

              </div>

              <span

                class="dash-system-state"

                :class="productCount === null ? 'state-error' : 'state-ok'"

              >

                {{ loading ? 'Đang kiểm tra' : productCount === null ? 'Lỗi' : 'Kết nối được' }}

              </span>

            </div>

            <div class="dash-system-row">

              <span class="dash-system-icon">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <path d="M4 5h16v14H4z" />

                  <path d="M8 9h8M8 13h5" />

                </svg>

              </span>

              <div class="dash-system-info">

                <strong>API bài viết</strong>

                <small>/api/blogs</small>

              </div>

              <span

                class="dash-system-state"

                :class="blogCount === null ? 'state-error' : 'state-ok'"

              >

                {{ loading ? 'Đang kiểm tra' : blogCount === null ? 'Lỗi' : 'Kết nối được' }}

              </span>

            </div>

            <div class="dash-system-row">

              <span class="dash-system-icon">

                <svg viewBox="0 0 24 24" aria-hidden="true">

                  <rect x="5" y="10" width="14" height="11" rx="2" />

                  <path d="M8 10V7a4 4 0 0 1 8 0v3" />

                </svg>

              </span>

              <div class="dash-system-info">

                <strong>Xác thực Sanctum</strong>

                <small>Phiên quản trị hiện tại</small>

              </div>

              <span class="dash-system-state state-ok">Đã đăng nhập</span>

            </div>

          </article>

          
<article class="dash-panel dash-shortcuts">
  <div class="dash-panel-heading">
    <div>
      <h3>Quản lý nội dung</h3>
      <p>Các khu vực quản trị</p>
    </div>
  </div>

  <!-- Truy cập quản lý tranh -->
  <router-link
    to="/admin/products"
    class="dash-shortcut dash-shortcut-link"
    aria-label="Mở trang quản lý tranh"
  >
    <span class="dash-shortcut-icon dash-icon-green">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <rect x="3" y="3" width="18" height="18" rx="2" />
        <circle cx="8.5" cy="8.5" r="1.5" />
        <path d="m21 15-5-5L5 21" />
      </svg>
    </span>

    <div class="dash-shortcut-info">
      <strong>Quản lý tranh</strong>
      <small>
        {{ productCount === null
          ? 'Chưa có dữ liệu thống kê'
          : `${formatNumber(productCount)} sản phẩm từ API`
        }}
      </small>
    </div>

    <span class="dash-shortcut-tag">Mở trang ↗</span>
  </router-link>

  <!-- Truy cập quản lý bài viết -->
  <router-link
    to="/admin/blogs"
    class="dash-shortcut dash-shortcut-link"
    aria-label="Mở trang quản lý bài viết"
  >
    <span class="dash-shortcut-icon dash-icon-purple">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
      </svg>
    </span>

    <div class="dash-shortcut-info">
      <strong>Quản lý bài viết</strong>
      <small>
        {{ blogCount === null
          ? 'Chưa có dữ liệu thống kê'
          : `${formatNumber(blogCount)} bài viết từ API`
        }}
      </small>
    </div>

    <span class="dash-shortcut-tag">Mở trang ↗</span>
  </router-link>
</article>


        </section>

        <footer class="dash-footer">

          <span>© {{ new Date().getFullYear() }} Duy Chân Dung</span>

          <span>Trang quản trị nội bộ</span>

        </footer>

      </div>

    </main>

  </div>

</template>

<style scoped>

.dash-layout,

.dash-layout * {

  box-sizing: border-box;

}

.dash-layout {

  --dash-green: #28784e;

  --dash-green-dark: #185938;

  --dash-bg: #f5f7f5;

  --dash-text: #26372c;

  --dash-muted: #8a968e;

  display: flex;

  min-height: 100vh;

  background: var(--dash-bg);

  color: var(--dash-text);

  font-family: "Segoe UI", Tahoma, Arial, sans-serif;

  font-size: 14px;

}

/* SIDEBAR */

.dash-sidebar {

  position: sticky;

  top: 0;

  display: flex;

  flex: 0 0 245px;

  flex-direction: column;

  width: 245px;

  height: 100vh;

  padding: 25px 16px 17px;

  border-right: 1px solid #e7ece8;

  background: #fff;

}

.dash-brand {

  display: flex;

  align-items: center;

  gap: 10px;

  margin-bottom: 38px;

  padding: 0 7px;

  color: var(--dash-text);

  text-decoration: none;

}

.dash-brand img {

  width: 42px;

  height: 42px;

  flex-shrink: 0;

  border-radius: 9px;

  object-fit: contain;

}

.dash-brand strong,

.dash-brand small {

  display: block;

}

.dash-brand strong {

  font-size: 14px;

  font-weight: 750;

}

.dash-brand small {

  margin-top: 4px;

  color: #929d95;

  font-size: 9px;

  letter-spacing: 1.5px;

}

.dash-menu-label {

  margin: 0 10px 12px;

  color: #a0aaa3;

  font-size: 10px;

  font-weight: 800;

  letter-spacing: 1.1px;

}

.dash-nav {

  display: grid;

  gap: 7px;

}

.dash-nav-item {

  display: flex;

  min-height: 44px;

  align-items: center;

  gap: 12px;

  padding: 10px 12px;

  border: 0;

  border-radius: 9px;

  color: #6d7b70;

  font-size: 12px;

  font-weight: 600;

  text-decoration: none;
   cursor: pointer;
}

.dash-nav-item svg {

  width: 19px;

  height: 19px;

  flex-shrink: 0;

  fill: none;

  stroke: currentColor;

  stroke-width: 1.7;

  stroke-linecap: round;

  stroke-linejoin: round;

}

.dash-nav-active {

  background: #eaf5ed;

  color: var(--dash-green-dark);

}

.dash-nav-disabled {

  opacity: 0.82;

}

.dash-coming {

  margin-left: auto;

  color: #9aa59d;

  font-size: 9px;

}

.dash-sidebar-bottom {

  margin-top: auto;

}

.dash-admin-profile {

  display: flex;

  align-items: center;

  gap: 10px;

  padding: 14px 4px;

  border-top: 1px solid #edf0ed;

}

.dash-avatar {

  display: flex;

  width: 37px;

  height: 37px;

  flex-shrink: 0;

  align-items: center;

  justify-content: center;

  border-radius: 11px;

  background: #deeee2;

  color: #22633e;

  font-weight: 800;

}

.dash-profile-info {

  min-width: 0;

  flex: 1;

}

.dash-profile-info strong,

.dash-profile-info span {

  display: block;

  overflow: hidden;

  text-overflow: ellipsis;

  white-space: nowrap;

}

.dash-profile-info strong {

  font-size: 11px;

}

.dash-profile-info span {

  margin-top: 4px;

  color: #89968d;

  font-size: 10px;

}

.dash-online-dot,

.dash-admin-badge span {

  width: 7px;

  height: 7px;

  flex-shrink: 0;

  border-radius: 50%;

  background: #31ad68;

}

.dash-logout {

  display: flex;

  width: 100%;

  align-items: center;

  gap: 10px;

  padding: 11px 12px;

  border: 1px solid #e8ece9;

  border-radius: 9px;

  background: #fff;

  color: #657369;

  font-size: 12px;

  font-weight: 600;

  cursor: pointer;

  transition: color 0.2s, background 0.2s;

}

.dash-logout:hover {

  border-color: #f0d5d5;

  background: #fff4f4;

  color: #c34242;

}

.dash-logout svg {

  width: 18px;

  height: 18px;

  fill: none;

  stroke: currentColor;

  stroke-width: 1.7;

  stroke-linecap: round;

  stroke-linejoin: round;

}

/* MAIN */

.dash-main {

  min-width: 0;

  flex: 1;

}

.dash-topbar {

  display: flex;

  min-height: 88px;

  align-items: center;

  justify-content: space-between;

  gap: 20px;

  padding: 16px 34px;

  border-bottom: 1px solid #e8ede9;

  background: #fff;

}

.dash-breadcrumb {

  margin-bottom: 6px;

  color: #8b978e;

  font-size: 10px;

}

.dash-topbar h1 {

  margin: 0;

  color: #23382a;

  font-size: 22px;

  font-weight: 800;

}

.dash-topbar-right {

  display: flex;

  align-items: center;

  gap: 18px;

}

.dash-admin-badge {

  display: inline-flex;

  align-items: center;

  gap: 7px;

  padding: 8px 11px;

  border: 1px solid #dfeee3;

  border-radius: 50px;

  background: #f6fbf7;

  color: #34764b;

  font-size: 10px;

  font-weight: 700;

}

.dash-view-site {

  display: inline-flex;

  align-items: center;

  gap: 6px;

  color: #607267;

  font-size: 11px;

  font-weight: 600;

  text-decoration: none;

}

.dash-view-site:hover {

  color: var(--dash-green);

}

.dash-view-site svg {

  width: 15px;

  height: 15px;

  fill: none;

  stroke: currentColor;

  stroke-width: 1.7;

  stroke-linecap: round;

  stroke-linejoin: round;

}

.dash-content {

  width: 100%;

  max-width: 1450px;

  margin: 0 auto;

  padding: 27px 34px;

}

/* WELCOME */

.dash-welcome {

  position: relative;

  display: flex;

  min-height: 185px;

  align-items: center;

  justify-content: space-between;

  gap: 20px;

  overflow: hidden;

  padding: 30px 32px;

  border: 1px solid #dcece0;

  border-radius: 16px;

  background: linear-gradient(110deg, #edf7ef 0%, #e2f1e6 65%, #d5ebdc 100%);

}

.dash-welcome-text {

  position: relative;

  z-index: 1;

  max-width: 650px;

}

.dash-welcome-text > span {

  color: #548665;

  font-size: 9px;

  font-weight: 800;

  letter-spacing: 1.5px;

}

.dash-welcome h2 {

  margin: 10px 0 8px;

  color: #204c31;

  font-size: clamp(22px, 2.2vw, 29px);

  font-weight: 800;

}

.dash-welcome p {

  max-width: 590px;

  margin: 0;

  color: #607b68;

  font-size: 12px;

  line-height: 1.8;

}

.dash-welcome-art {

  width: 150px;

  flex-shrink: 0;

  margin-right: 20px;

}

.dash-welcome-art svg {

  display: block;

  width: 100%;

}

/* MESSAGES */

.dash-notice {

  display: flex;

  align-items: center;

  gap: 9px;

  margin-top: 18px;

  padding: 13px 15px;

  border: 1px solid #efe2c5;

  border-radius: 10px;

  background: #fffaf0;

  color: #80652c;

  font-size: 11px;

  line-height: 1.6;

}

.dash-notice-dot {

  width: 7px;

  height: 7px;

  flex-shrink: 0;

  border-radius: 50%;

  background: #d6a53e;

}

.dash-notice button {

  flex-shrink: 0;

  margin-left: auto;

  padding: 5px 9px;

  border: 1px solid #e7d7b2;

  border-radius: 6px;

  background: #fff;

  color: #72551f;

  font-size: 10px;

  font-weight: 700;

  cursor: pointer;

}

/* STAT CARDS */

.dash-stats {

  display: grid;

  grid-template-columns: repeat(4, minmax(0, 1fr));

  gap: 16px;

  margin-top: 23px;

}

.dash-stat-card {

  min-width: 0;

  padding: 19px 18px;

  border: 1px solid #e7ece8;

  border-radius: 13px;

  background: #fff;

  box-shadow: 0 3px 13px rgba(31, 63, 42, 0.025);

}

.dash-stat-top {

  display: flex;

  min-width: 0;

  align-items: center;

  justify-content: space-between;

  gap: 8px;

}

.dash-stat-icon {

  display: flex;

  width: 39px;

  height: 39px;

  flex-shrink: 0;

  align-items: center;

  justify-content: center;

  border-radius: 11px;

}

.dash-stat-icon svg,

.dash-shortcut-icon svg {

  width: 20px;

  height: 20px;

  fill: none;

  stroke: currentColor;

  stroke-width: 1.6;

  stroke-linecap: round;

  stroke-linejoin: round;

}

.dash-icon-green {

  background: #e7f5eb;

  color: #338255;

}

.dash-icon-purple {

  background: #f0eafa;

  color: #8464bc;

}

.dash-icon-blue {

  background: #e7f1fb;

  color: #4d82bc;

}

.dash-icon-orange {

  background: #fff0df;

  color: #bc8640;

}

.dash-stat-chip {

  padding: 5px 7px;

  border-radius: 5px;

  background: #f4f7f4;

  color: #89968c;

  font-size: 9px;

  white-space: nowrap;

}

.dash-stat-card > p {

  margin: 17px 0 7px;

  color: #7b897f;

  font-size: 11px;

}

.dash-stat-card > strong {

  display: block;

  overflow-wrap: anywhere;

  color: #26382c;

  font-size: clamp(23px, 2vw, 30px);

  font-weight: 800;

  line-height: 1.25;

}

.dash-stat-card > .dash-stat-role {

  font-size: clamp(17px, 1.6vw, 22px);

}

.dash-stat-card > small {

  display: block;

  margin-top: 10px;

  color: #94a097;

  font-size: 9px;

  line-height: 1.6;

}

.dash-status-pill {

  display: inline-block;

  padding: 5px 7px;

  border-radius: 5px;

  font-size: 9px;

  line-height: 1.3;

  text-align: center;

}

.dash-status-online {

  background: #e7f6eb;

  color: #287b48;

}

.dash-status-partial {

  background: #fff5df;

  color: #a16f1c;

}

.dash-status-offline {

  background: #fff0f0;

  color: #b63d3d;

}

/* BOTTOM PANELS */

.dash-bottom-grid {

  display: grid;

  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);

  gap: 18px;

  margin-top: 22px;

}

.dash-panel {

  min-width: 0;

  padding: 22px;

  border: 1px solid #e7ece8;

  border-radius: 13px;

  background: #fff;

}

.dash-panel-heading {

  display: flex;

  align-items: center;

  justify-content: space-between;

  gap: 12px;

  margin-bottom: 22px;

}

.dash-panel-heading h3 {

  margin: 0;

  color: #2b3d30;

  font-size: 14px;

  font-weight: 800;

}

.dash-panel-heading p {

  margin: 6px 0 0;

  color: #929d94;

  font-size: 10px;

}

.dash-refresh {

  display: inline-flex;

  align-items: center;

  gap: 6px;

  padding: 7px 9px;

  border: 1px solid #e6ece7;

  border-radius: 7px;

  background: #fff;

  color: #5b7662;

  font-size: 10px;

  white-space: nowrap;

  cursor: pointer;

}

.dash-refresh:disabled {

  opacity: 0.6;

  cursor: wait;

}

.dash-refresh svg {

  width: 14px;

  height: 14px;

  fill: none;

  stroke: currentColor;

  stroke-width: 1.7;

  stroke-linecap: round;

  stroke-linejoin: round;

}

.dash-system-row {

  display: flex;

  min-width: 0;

  align-items: center;

  gap: 11px;

  padding: 15px 0;

  border-top: 1px solid #f0f2f0;

}

.dash-system-icon {

  display: flex;

  width: 35px;

  height: 35px;

  flex-shrink: 0;

  align-items: center;

  justify-content: center;

  border: 1px solid #e8eee9;

  border-radius: 9px;

  background: #fafcfb;

  color: #67856f;

}

.dash-system-icon svg {

  width: 18px;

  height: 18px;

  fill: none;

  stroke: currentColor;

  stroke-width: 1.6;

  stroke-linecap: round;

  stroke-linejoin: round;

}

.dash-system-info {

  min-width: 0;

  flex: 1;

}

.dash-system-info strong,

.dash-system-info small {

  display: block;

}

.dash-system-info strong {

  color: #435449;

  font-size: 11px;

  font-weight: 700;

}

.dash-system-info small {

  margin-top: 4px;

  color: #9aa49d;

  font-size: 10px;

}

.dash-system-state {

  flex-shrink: 0;

  font-size: 9px;

  font-weight: 700;

  text-align: right;

}

.state-ok {

  color: #368253;

}

.state-error {

  color: #c35454;

}

.dash-shortcut {

  display: flex;

  align-items: center;

  gap: 11px;

  padding: 14px 0;

  border-top: 1px solid #f0f2f0;

}

.dash-shortcut-icon {

  display: flex;

  width: 38px;

  height: 38px;

  flex-shrink: 0;

  align-items: center;

  justify-content: center;

  border-radius: 10px;

}

.dash-shortcut-link {
  color: inherit;
  text-decoration: none;
  border-radius: 8px;
  cursor: pointer;
  transition: background 0.2s ease;
}

.dash-shortcut-link:hover {
  background: #f7faf7;
}

.dash-shortcut-link:hover .dash-shortcut-info strong {
  color: var(--dash-green-dark);
}

.dash-shortcut-link:hover .dash-shortcut-tag {
  border-color: #bddbc5;
  background: #eaf5ed;
  color: var(--dash-green-dark);
}

.dash-shortcut-info {

  min-width: 0;

  flex: 1;

}

.dash-shortcut-info strong,

.dash-shortcut-info small {

  display: block;

}

.dash-shortcut-info strong {

  color: #3d4e42;

  font-size: 11px;

  font-weight: 750;

}

.dash-shortcut-info small {

  margin-top: 5px;

  overflow-wrap: anywhere;

  color: #94a098;

  font-size: 10px;

}

.dash-shortcut-tag {

  flex-shrink: 0;

  padding: 5px 7px;

  border: 1px solid #e7ece8;

  border-radius: 5px;

  color: #8b978e;

  font-size: 9px;

}

.dash-shortcut-note {

  margin-top: 12px;

  padding: 12px;

  border-radius: 8px;

  background: #f5f8f5;

  color: #78877c;

  font-size: 10px;

  line-height: 1.8;

}

.dash-footer {

  display: flex;

  justify-content: space-between;

  gap: 15px;

  margin-top: 28px;

  padding: 17px 1px;

  border-top: 1px solid #e4eae5;

  color: #99a39b;

  font-size: 10px;

}

/* RESPONSIVE */

@media (max-width: 1200px) {

  .dash-sidebar {

    flex-basis: 220px;

    width: 220px;

  }

  .dash-content {

    padding-right: 23px;

    padding-left: 23px;

  }

  .dash-stats {

    grid-template-columns: repeat(2, minmax(0, 1fr));

  }

  .dash-bottom-grid {

    grid-template-columns: minmax(0, 1fr);

  }

}

@media (max-width: 760px) {

  .dash-layout {

    display: block;

  }

  .dash-sidebar {

    position: relative;

    width: 100%;

    height: auto;

    padding: 13px 15px;

    border-right: 0;

    border-bottom: 1px solid #e6ece7;

  }

  .dash-brand {

    margin-bottom: 15px;

  }

  .dash-menu-label,

  .dash-sidebar-bottom {

    display: none;

  }

  .dash-nav {

    display: flex;

    gap: 7px;

    overflow-x: auto;

    padding-bottom: 2px;

  }

  .dash-nav-item {

    flex-shrink: 0;

    min-height: 38px;

    padding: 8px 10px;

    font-size: 10px;

  }

  .dash-nav-item svg {

    width: 16px;

    height: 16px;

  }

  .dash-coming {

    display: none;

  }

  .dash-topbar {

    min-height: 76px;

    padding: 14px 18px;

  }

  .dash-topbar h1 {

    font-size: 19px;

  }

  .dash-topbar-right {

    gap: 10px;

  }

  .dash-admin-badge {

    padding: 7px 8px;

  }

  .dash-view-site {

    font-size: 0;

  }

  .dash-view-site svg {

    width: 20px;

    height: 20px;

  }

  .dash-content {

    padding: 18px 15px;

  }

  .dash-welcome {

    min-height: 150px;

    padding: 22px 19px;

  }

  .dash-welcome-art {

    width: 90px;

    margin-right: -8px;

  }

  .dash-welcome h2 {

    font-size: 22px;

  }

  .dash-welcome p {

    font-size: 11px;

  }

  .dash-stats {

    gap: 11px;

    margin-top: 15px;

  }

  .dash-stat-card {

    padding: 15px 13px;

  }

  .dash-stat-chip {

    display: none;

  }

  .dash-stat-card > strong {

    font-size: 25px;

  }

  .dash-stat-card > .dash-stat-role {

    font-size: 16px;

  }

  .dash-bottom-grid {

    gap: 13px;

    margin-top: 15px;

  }

  .dash-panel {

    padding: 16px 14px;

  }

}

@media (max-width: 420px) {

  .dash-stats {

    grid-template-columns: minmax(0, 1fr);

  }

  .dash-stat-card {

    padding: 16px;

  }

  .dash-stat-chip {

    display: inline-block;

  }

  .dash-system-row {

    flex-wrap: wrap;

  }

  .dash-system-state {

    margin-left: auto;

  }

  .dash-welcome-art {

    display: none;

  }

  .dash-footer {

    flex-direction: column;

  }

}

</style>
