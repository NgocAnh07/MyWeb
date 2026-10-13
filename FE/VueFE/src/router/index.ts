
import { createRouter, createWebHistory } from 'vue-router'
import axios from 'axios'
import { API_URL } from '../config/api'

import HomeView from '../view/HomeView.vue'
import LoginView from '../view/LoginView.vue'
import AdminView from '../view/AdminView.vue'
import CategoryAdmin from '../view/CategoryAdmin.vue'
import ProductAdmin from '../view/ProductAdmin.vue'
import BlogAdmin from '../view/BlogAdmin.vue'
import SearchProduct from '../view/SearchProduct.vue'
import ProductDetail from '../view/ProductDetail.vue'
import BlogDetail from '../view/BlogDetail.vue'

const router = createRouter({
  history: createWebHistory(),

  // Xử lý cuộn trang khi chuyển đến một mục trên trang chủ.
  scrollBehavior(to, from, savedPosition) {
    // Khi người dùng bấm nút Back/Forward của trình duyệt.
    if (savedPosition) {
      return savedPosition
    }

    // Có hash thì cuộn đến section tương ứng.
    if (to.hash) {
      return {
        el: to.hash,
        top: 90,
        behavior: 'smooth',
      }
    }

    // Nếu không có hash, trở về đầu trang.
    return {
      left: 0,
      top: 0,
    }
  },

  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
    },
    {
      path: '/admin',
      name: 'admin',
      component: AdminView,
      meta: { requiresAdmin: true },
    },
    {
      path: '/admin/products',
      name: 'admin-products',
      component: ProductAdmin,
      meta: { requiresAdmin: true },
    },
    {
      path: '/admin/categories',
      name: 'admin-categories',
      component: CategoryAdmin,
      meta: { requiresAdmin: true },
    },
    {
      path: '/admin/blogs',
      name: 'admin-blogs',
      component: BlogAdmin,
      meta: { requiresAdmin: true },
    },
    {
      path: '/search-products',
      name: 'search-products',
      component: SearchProduct,
    },
    {
      path: '/products/:id',
      name: 'product-detail-legacy',
      component: ProductDetail,
    },
    {
      path: '/blogs/:id',
      name: 'blog-detail',
      component: BlogDetail,
    },
    {
  path: '/:slug',
  name: 'product-detail',
  component: ProductDetail,
}
  ],
})

// Bảo vệ toàn bộ trang quản trị.
router.beforeEach(async (to) => {
  const requiresAdmin = to.matched.some(
    route => route.meta.requiresAdmin === true,
  )

  // Trang công khai vẫn truy cập bình thường.
  if (!requiresAdmin) {
    return true
  }

  const token = localStorage.getItem('auth_token')

  // Chưa đăng nhập: chuyển về trang đăng nhập.
  if (!token) {
    return {
      name: 'login',
      query: { redirect: to.fullPath },
    }
  }

  try {
    // Xác minh token trực tiếp với Laravel.
    const response = await axios.get(`${API_URL}/me`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
    })

    const payload: unknown = response.data
    const candidates: unknown[] = []

    if (typeof payload === 'object' && payload !== null) {
      const root = payload as Record<string, unknown>
      const data = root.data

      candidates.push(root.user)

      if (typeof data === 'object' && data !== null) {
        const nestedData = data as Record<string, unknown>
        candidates.push(nestedData.user)
        candidates.push(data)
      }

      candidates.push(payload)
    }

    const user = candidates.find(
      (item): item is Record<string, unknown> =>
        typeof item === 'object' &&
        item !== null &&
        'role' in item,
    )

    // Chỉ tài khoản Admin mới được tiếp tục.
    if (user?.role !== 'admin') {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')

      return { name: 'login' }
    }

    localStorage.setItem('auth_user', JSON.stringify(user))

    return true
  } catch (error: unknown) {
    // Xóa token khi phiên đăng nhập không còn hợp lệ.
    if (
      axios.isAxiosError(error) &&
      [401, 403].includes(error.response?.status ?? 0)
    ) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
    }

    // Không xác minh được thì không cho truy cập Admin.
    return {
      name: 'login',
      query: { redirect: to.fullPath },
    }
  }
})

export default router
