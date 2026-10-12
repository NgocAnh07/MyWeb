
<script setup lang="ts">
import { onMounted, nextTick, ref } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'

interface FeaturedCategory {
  id: number
  name: string
  is_active?: boolean | number | string
}

interface FeaturedProduct {
  id: number
  name: string
  img: string | null
  category_id?: number | null
  category?: FeaturedCategory
}

const featuredProducts = ref<FeaturedProduct[]>([])
const featuredLoading = ref(false)
const featuredError = ref('')

interface BlogPost {
  id: number
  title: string
  img: string | null
  description: string
  created_at?: string
}

const randomBlogs = ref<BlogPost[]>([])
const blogsLoading = ref(false)
const blogsError = ref('')

function blogImageUrl(path: string | null | undefined): string {
  if (!path) return ''

  if (/^https?:\/\//i.test(path)) return path

  const normalizedPath = path.replace(/^\/+/, '')

  if (normalizedPath.startsWith('storage/')) {
    return `${STORAGE_BASE_URL}/${normalizedPath}`
  }

  return `${STORAGE_BASE_URL}/storage/${normalizedPath}`
}

function blogExcerpt(description: string | null | undefined): string {
  if (!description) return ''
  const template = document.createElement('template')
  template.innerHTML = description
  return (template.content.textContent ?? '').replace(/\s+/g, ' ').trim()
}

function formatBlogDate(date: string | undefined): string {
  if (!date) return ''

  const parsedDate = new Date(date)

  if (Number.isNaN(parsedDate.getTime())) return ''

  return new Intl.DateTimeFormat('vi-VN', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(parsedDate)
}

async function loadRandomBlogs() {
  blogsLoading.value = true
  blogsError.value = ''

  try {
    // Backend cần hỗ trợ tham số random=3 để chọn ngẫu nhiên
    // từ toàn bộ bảng blogs.
    const response = await axios.get(`${API_URL}/blogs?random=3`)
    const blogs = extractList<BlogPost>(response.data)

    // Phòng trường hợp API trả về nhiều hơn 3 bài.
    randomBlogs.value = blogs.slice(0, 3)

    await nextTick()

    const win = window as any
    win.AOS?.refreshHard?.()
    win.lucide?.createIcons()
  } catch (error) {
    console.error('Không tải được bài viết:', error)
    randomBlogs.value = []
    blogsError.value = 'Chưa tải được bài viết. Vui lòng thử lại sau.'
  } finally {
    blogsLoading.value = false
  }
}

function extractList<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) {
    return payload as T[]
  }

  if (typeof payload === 'object' && payload !== null) {
    const data = (payload as Record<string, unknown>).data

    if (Array.isArray(data)) {
      return data as T[]
    }
  }

  return []
}

function extractLastPage(payload: unknown): number {
  if (typeof payload !== 'object' || payload === null) {
    return 1
  }

  const data = payload as Record<string, unknown>

  if (typeof data.last_page === 'number') {
    return Math.max(1, data.last_page)
  }

  const meta = data.meta

  if (typeof meta === 'object' && meta !== null) {
    const lastPage = (meta as Record<string, unknown>).last_page

    if (typeof lastPage === 'number') {
      return Math.max(1, lastPage)
    }
  }

  return 1
}

function isCategoryActive(category: FeaturedCategory): boolean {
  return (
    category.is_active === undefined ||
    category.is_active === true ||
    category.is_active === 1 ||
    category.is_active === '1'
  )
}

function featuredImageUrl(path: string | null | undefined): string {
  if (!path) return ''

  // Nếu Backend trả URL đầy đủ thì sử dụng trực tiếp.
  if (/^https?:\/\//i.test(path)) {
    return path
  }

  const normalizedPath = path.replace(/^\/+/, '')

  // Hỗ trợ đường dẫn đã chứa storage/.
  if (normalizedPath.startsWith('storage/')) {
    return `${STORAGE_BASE_URL}/${normalizedPath}`
  }

  // Ví dụ products/anh.jpg -> /storage/products/anh.jpg.
  return `${STORAGE_BASE_URL}/storage/${normalizedPath}`
}

async function loadFeaturedProducts() {
  featuredLoading.value = true
  featuredError.value = ''

  try {
    const categoryResponse = await axios.get(`${API_URL}/categories`)
    const categories = extractList<FeaturedCategory>(
      categoryResponse.data,
    ).filter(isCategoryActive)

    // Lấy trang sản phẩm đầu tiên để biết tổng số trang từ Laravel.
    const firstResponse = await axios.get(`${API_URL}/products?page=1`)
    const firstPayload: unknown = firstResponse.data
    const allProducts = extractList<FeaturedProduct>(firstPayload)
    const lastPage = extractLastPage(firstPayload)

    // Tải những trang còn lại nếu danh sách sản phẩm đã được phân trang.
    if (lastPage > 1) {
      const remainingPages = await Promise.allSettled(
        Array.from({ length: lastPage - 1 }, (_, index) =>
          axios.get(`${API_URL}/products?page=${index + 2}`),
        ),
      )

      for (const result of remainingPages) {
        if (result.status === 'fulfilled') {
          allProducts.push(
            ...extractList<FeaturedProduct>(result.value.data),
          )
        }
      }
    }

    const selectedProducts: FeaturedProduct[] = []

    // Mỗi danh mục chỉ lấy một sản phẩm, tối đa 6 danh mục.
    for (const category of categories) {
      const product = allProducts.find(item => {
        const productCategoryId =
          item.category_id ?? item.category?.id

        return productCategoryId === category.id
      })

      if (!product) continue

      selectedProducts.push({
        ...product,
        category: product.category ?? category,
      })

      if (selectedProducts.length >= 6) break
    }

    featuredProducts.value = selectedProducts

    await nextTick()

    // Làm mới AOS và Lucide sau khi Vue render danh sách.
    const win = window as any
    win.AOS?.refreshHard?.()
    win.lucide?.createIcons()
  } catch (error) {
    console.error('Không tải được sản phẩm nổi bật:', error)
    featuredProducts.value = []
    featuredError.value = 'Chưa tải được sản phẩm. Vui lòng thử lại sau.'
  } finally {
    featuredLoading.value = false
  }
}

onMounted(async () => {
  await nextTick()

  const win = window as any

  // Khởi tạo hiệu ứng AOS.
  if (win.AOS) {
    win.AOS.init({
      duration: 900,
      once: true,
      easing: 'ease-in-out',
      offset: 60,
    })

    win.AOS.refreshHard?.()
  }

  // Khởi tạo icon Lucide.
  win.lucide?.createIcons()

  // Tải sản phẩm đại diện của các danh mục.
  await Promise.all([
  loadFeaturedProducts(),
  loadRandomBlogs(),
])
})
</script>
<template>

<!-- ===================== HERO ===================== -->
<section id="home" class="hero-section flex items-center justify-center relative pt-4">

  <!-- Floating Circles Deco: giữ nguyên -->
  <div class="absolute top-20 right-16 w-72 h-72 bg-red-600/10 rounded-full blur-3xl pointer-events-none"></div>
  <div class="absolute bottom-40 left-10 w-56 h-56 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>

  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-28 lg:py-26 relative z-10">
    <div class="grid lg:grid-cols-2 gap-12 items-center">

      <!-- Left Content -->
      <div>
        <h1
          class="font-heading font-extrabold text-4xl lg:text-5xl text-white leading-tight mb-6"
          data-aos="fade-up"
          data-aos-delay="100"
        >
          Duy
          <span class="text-red-400">Chân Dung</span>
        </h1>

        <p
          class="text-slate-300 text-lg leading-relaxed mb-8 max-w-lg"
          data-aos="fade-up"
          data-aos-delay="200"
        >
          Vẽ tranh theo yêu cầu
        </p>

        <div
          class="flex flex-wrap gap-4 mb-10"
          data-aos="fade-up"
          data-aos-delay="300"
        >
          <a href="#contact" class="btn-primary text-base py-3.5 px-7">
            <i data-lucide="calendar-plus" class="w-5 h-5"></i>
            Đặt vẽ ngay
          </a>

          <a href="#products" class="btn-outline text-base py-3.5 px-7">
            <i data-lucide="compass" class="w-5 h-5"></i>
            Xem mẫu tranh
          </a>
        </div>
      </div>

      <!-- Right: Banner -->
      <div
  class="flex justify-center relative w-full mt-8 lg:mt-0"
  data-aos="fade-left"
  data-aos-delay="200"
>
        <div class="relative">
          <div
  class="w-64 h-64 sm:w-80 sm:h-80 xl:w-96 xl:h-96 bg-gradient-to-br from-red-600/20 to-slate-600/20 rounded-3xl border border-white/10 flex items-center justify-center overflow-hidden"
  style="animation: float 6s ease-in-out infinite;"
>
            <img
              src="/assets/banner.jpg"
              alt="Banner Duy Chân Dung"
              class="w-full h-full object-cover"
            />
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Scroll Indicator: giữ nguyên hiệu ứng -->
  <div
    class="absolute bottom-8 left-1/2 -translate-x-1/2 z-20 hidden md:flex flex-col items-center gap-2 text-slate-400 text-xs"
  >
    <span>Cuộn xuống</span>
    <div class="w-px h-12 bg-gradient-to-b from-slate-400 to-transparent"></div>
  </div>

</section>
  <!-- ===================== ABOUT  ===================== -->
  <section id="about" class="section-padding" style="background-color: #0f172a;">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid lg:grid-cols-2 gap-16 items-center">
        <!-- Image -->
        <div data-aos="fade-right" class="relative">
          <div class="bg-gradient-to-br from-red-600 to-slate-800 rounded-3xl p-1 shadow-2xl max-w-md mx-auto lg:mx-0">
            <div class="bg-slate-100 rounded-3xl p-8 text-center">
              <img src="/assets/avata.jpg" />
              <div class="mt-4">
                <div class="font-heading font-bold text-xl text-slate-800">Duy Art</div>
                <div class="text-red-600 font-medium text-sm">Họa sĩ vẽ tranh Chân Dung- Tranh Sơn dầu Nghệ Thuật</div>
              </div>
            </div>
          </div>
          
        </div>

        <!-- Content -->
        <div data-aos="fade-left" data-aos-delay="100">
          <span class="section-tag mb-4">
            <i data-lucide="user-check" class="w-3.5 h-3.5"></i>
            meet the artist
          </span>
          <h2 class="font-heading font-bold text-3xl lg:text-4xl text-white mt-3 mb-5">
            Đôi bàn tay tài hoa lướt trên khung canvas, đánh thức những sắc màu và tạo nên tác phẩm để đời.
          </h2>
          <p class="text-slate-300 leading-relaxed mb-6">
            Duy art là họa sĩ tranh sơn dầu kết hợp sự kiên nhẫn bậc thầy và tư duy màu sắc tinh tế để thổi hồn vào từng lớp màu đặc quánh.
          </p>

          <div class="grid grid-cols-2 gap-4 mb-8">
            <div class="bg-white dark:bg-slate-700 rounded-xl p-4 border border-gray-100 dark:border-slate-600">
              <div class="font-heading font-bold text-2xl text-red-600">20+</div>
              <div class="text-sm text-slate-500">Năm kinh nghiệm</div>
            </div>
            <div class="bg-white dark:bg-slate-700 rounded-xl p-4 border border-gray-100 dark:border-slate-600">
              <div class="font-heading font-bold text-2xl text-red-600">7K+</div>
              <div class="text-sm text-slate-500">Tác phẩm tranh sơn dầu</div>
            </div>
            <div class="bg-white dark:bg-slate-700 rounded-xl p-4 border border-gray-100 dark:border-slate-600">
              <div class="font-heading font-bold text-2xl text-red-600">4k+</div>
              <div class="text-sm text-slate-500">Tranh chân dung</div>
            </div>
            <div class="bg-white dark:bg-slate-700 rounded-xl p-4 border border-gray-100 dark:border-slate-600">
              <div class="font-heading font-bold text-2xl text-red-600">50+</div>
              <div class="text-sm text-slate-500">khách hàng quay lại sử dụng dịch vụ</div>
            </div>
          </div>

          <br>

          <a href="#contact" class="btn-primary-dark inline-flex">
            <i data-lucide="calendar-plus" class="w-5 h-5"></i>
            Liên hệ ngay
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- ===================== SERVICES ===================== -->
  <section id="services" class="section-padding" style="background-color: #172033;">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-12" data-aos="fade-up">
        <span class="section-tag mb-4">
          <i data-lucide="heart-pulse" class="w-3.5 h-3.5"></i>
          Dịch vụ của chúng tôi
        </span>
        <h2 class="font-heading font-bold text-3xl lg:text-4xl text-white mt-3">Vẽ tranh theo yêu cầu</h2>
      </div>

      <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Service Card 1 -->
        <div class="card-medical dark:bg-slate-800 dark:border-slate-700 group" data-aos="fade-up" data-aos-delay="0">
          <div
            class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-red-600 transition-colors">
            <i class="fa-solid fa-user"></i>
          </div>
          <h3 class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-2">Tranh chân dung
          </h3>
          
          <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Chúng tôi sẽ biến bức ảnh của bạn thành bức tranh sơn dầu đầy màu sắc.</p>
          <a href="heart-health.html"
            class="mt-4 flex items-center gap-1 text-red-600 text-sm font-semibold hover:gap-2 transition-all">Xem thêm <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
        </div>

        <!-- Service Card 2 -->
        <div class="card-medical dark:bg-slate-800 dark:border-slate-700 group" data-aos="fade-up" data-aos-delay="100">
          <div
            class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-blue-600 transition-colors">
           <i
  data-lucide="mountain"
  class="w-6 h-6 text-blue-600 group-hover:text-white transition-colors"
></i>
          </div>
          <h3 class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-2">Tranh phong cảnh</h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Tái hiện trọn vẹn những khung cảnh diễm lệ nhất của thế giới vào trong kiệt tác</p>
          <a href="ECG-diagnostics.html"
            class="mt-4 flex items-center gap-1 text-red-600 text-sm font-semibold hover:gap-2 transition-all">Xem thêm <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
        </div>

        <!-- Service Card 3 -->
        <div class="card-medical dark:bg-slate-800 dark:border-slate-700 group" data-aos="fade-up" data-aos-delay="200">
          <div
            class="w-12 h-12 bg-purple-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-purple-600 transition-colors">
           <i
  data-lucide="paintbrush"
  class="w-6 h-6 text-purple-600 group-hover:text-white transition-colors"
></i>
          </div>
          <h3 class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-2">Tranh ghép mặt
          </h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Tái hiện bản thân vào khung cảnh lịch sử hoặc nhân vật lịch sử</p>
          <a href="hypertension-management.html"
            class="mt-4 flex items-center gap-1 text-red-600 text-sm font-semibold hover:gap-2 transition-all">Xem thêm <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
        </div>

        <!-- Service Card 4 -->
        <div class="card-medical dark:bg-slate-800 dark:border-slate-700 group" data-aos="fade-up" data-aos-delay="0">
          <div
            class="w-12 h-12 bg-green-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-green-600 transition-colors">
            <i
  data-lucide="apple"
  class="w-6 h-6 text-green-600 group-hover:text-white transition-colors"
></i>
          </div>
          <h3 class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-2">Tranh vẽ hoa quả</h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Lớp màu sơn dầu khắc họa vẻ đẹp mọng nước, tràn đầy sức sống của cỏ cây</p>
          <a href="cardiac-rehabilitation.html"
            class="mt-4 flex items-center gap-1 text-red-600 text-sm font-semibold hover:gap-2 transition-all">Xem thêm <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
        </div>

        <!-- Service Card 5 -->
        <div class="card-medical dark:bg-slate-800 dark:border-slate-700 group" data-aos="fade-up" data-aos-delay="100">
          <div
            class="w-12 h-12 bg-yellow-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-yellow-500 transition-colors">
            <i
  data-lucide="flower-2"
  class="w-6 h-6 text-yellow-600 group-hover:text-white transition-colors"
></i>
          </div>
          <h3 class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-2">Tranh vẽ hoa</h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Đôi bàn tay tài hoa đã thổi nhịp sống vào nét vẽ, làm bừng sáng không gian bằng sắc hoa không bao giờ phai tàn</p>
          <a href="preventive-cardiology.html"
            class="mt-4 flex items-center gap-1 text-red-600 text-sm font-semibold hover:gap-2 transition-all">Xem thêm <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
        </div>

        <!-- Service Card 6 -->
        <div class="card-medical dark:bg-slate-800 dark:border-slate-700 group" data-aos="fade-up" data-aos-delay="200">
          <div
            class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center mb-4 group-hover:bg-red-600 transition-colors">
            <i
  data-lucide="trophy"
  class="w-6 h-6 text-yellow-600 group-hover:text-white transition-colors"
></i>
          </div>
          <h3 class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-2">Tranh vẽ Bác Hồ
          </h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm leading-relaxed">Bằng ngôn ngữ hội họa tinh tế, người nghệ sĩ đã khắc nét nên hình ảnh Cha già dân tộc vừa thanh cao, vừa vô cùng gần gũi</p>
          <a href="Emergency.html"
            class="mt-4 flex items-center gap-1 text-red-600 text-sm font-semibold hover:gap-2 transition-all">xem thêm
            <i data-lucide="arrow-right" class="w-4 h-4"></i></a>
        </div>
      </div>
    </div>
  </section>

<!-- ===================== FEATURED PRODUCTS ===================== -->
<section
  id="products"
  class="section-padding bg-gradient-to-br from-slate-900 to-slate-800 text-white"
>
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

    <!-- Tiêu đề -->
    <div class="text-center mb-12" data-aos="fade-up">
      <span
        class="inline-flex items-center gap-2 bg-red-600/20 text-red-400 text-xs font-bold px-4 py-2 rounded-full uppercase tracking-wide mb-4"
      >
        <i data-lucide="palette" class="w-3.5 h-3.5"></i>
        Bộ sưu tập
      </span>

      <h2 class="font-heading font-bold text-3xl lg:text-4xl mt-3">
        Khám phá các dòng tranh
      </h2>

      <p class="text-slate-400 mt-3 max-w-xl mx-auto">
        Những tác phẩm nổi bật được lựa chọn từ các danh mục tranh.
      </p>
    </div>

    <!-- Trạng thái tải dữ liệu -->
    <div
      v-if="featuredLoading"
      class="py-12 text-center text-slate-300"
    >
      Đang tải sản phẩm...
    </div>

    <!-- Lỗi tải dữ liệu -->
    <div
      v-else-if="featuredError"
      class="py-12 text-center text-slate-300"
    >
      {{ featuredError }}
    </div>

    
<!-- Danh sách sản phẩm và nút Xem thêm -->
<template v-else-if="featuredProducts.length > 0">
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    <router-link
  v-for="(product, index) in featuredProducts"
  :key="product.id"
  :to="{ name: 'product-detail', params: { id: product.id } }"
  class="featured-product-card"
  data-aos="zoom-in"
  :data-aos-delay="index * 80"
>
      <div class="featured-product-image">
        <img
          v-if="product.img"
          :src="featuredImageUrl(product.img)"
          :alt="product.name"
          loading="lazy"
        />

        <div v-else class="featured-product-no-image">
          <span>Chưa có ảnh</span>
        </div>
      </div>

      <div class="featured-product-name">
        <h3>{{ product.name }}</h3>
      </div>
    </router-link>
  </div>

  <!-- Nút Xem thêm -->
  <div class="featured-view-more-wrap" data-aos="fade-up">
  <router-link
    to="/search-products"
    class="featured-view-more-btn"
  >
    Xem thêm sản phẩm
    <i data-lucide="arrow-right" class="w-4 h-4"></i>
  </router-link>
</div>
</template>

<!-- Không có sản phẩm -->
<div
  v-else
  class="py-12 text-center text-slate-300"
>
  Hiện chưa có sản phẩm trong các danh mục.
</div>


  </div>
</section>

<!-- ===================== BLOG ===================== -->
<section id="blog" class="section-padding" style="background-color: #0f172a;">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

    <div class="text-center mb-12" data-aos="fade-up">
      <span class="section-tag mb-4">
        <i data-lucide="rss" class="w-3.5 h-3.5"></i>
        Tin tức mới
      </span>

      <h2
        class="font-heading font-bold text-3xl lg:text-4xl text-white mt-3"
      >
        Góc chia sẻ
      </h2>

      <p class="text-slate-300 mt-3 max-w-xl mx-auto">
        Khám phá những câu chuyện và kiến thức thú vị về hội họa.
      </p>
    </div>

    <!-- Đang tải -->
    <div
      v-if="blogsLoading"
      class="py-12 text-center text-slate-500 dark:text-slate-400"
    >
      Đang tải bài viết...
    </div>

    <!-- Lỗi tải -->
    <div
      v-else-if="blogsError"
      class="py-12 text-center text-slate-500 dark:text-slate-400"
    >
      {{ blogsError }}
    </div>

    <!-- Danh sách 3 bài viết -->
    <div
      v-else-if="randomBlogs.length > 0"
      class="grid md:grid-cols-2 lg:grid-cols-3 gap-6"
    >
      
<router-link
  v-for="(blog, index) in randomBlogs"
  :key="blog.id"
  :to="{ name: 'blog-detail', params: { id: blog.id } }"
  class="blog-card-link"
>
  <article
    class="blog-card dark:bg-slate-700"
    data-aos="fade-up"
    :data-aos-delay="index * 100"
  >

        <!-- Ảnh bài viết -->
        <div
          class="blog-img h-48 relative overflow-hidden bg-slate-200 dark:bg-slate-600"
        >
          <img
  v-if="blog.img"
  :src="blogImageUrl(blog.img)"
  :alt="blog.title"
  loading="lazy"
  class="absolute inset-0 w-full h-full object-cover object-center"
/>

          <div
            v-else
            class="absolute inset-0 flex items-center justify-center text-slate-400"
          >
            <i data-lucide="image" class="w-12 h-12"></i>
          </div>

          
        </div>

        <!-- Nội dung bài viết -->
        <div class="p-5">
          <div
            v-if="blog.created_at"
            class="text-xs text-slate-400 mb-2 flex items-center gap-2"
          >
            <i data-lucide="calendar" class="w-3.5 h-3.5"></i>
            {{ formatBlogDate(blog.created_at) }}
          </div>

          <h3
            class="font-heading font-semibold text-lg text-slate-800 dark:text-white mb-3 leading-tight"
          >
            {{ blog.title }}
          </h3>

          <p
            class="blog-description text-slate-500 dark:text-slate-400 text-sm leading-relaxed"
          >
            {{ blogExcerpt(blog.description) }}
          </p>
        </div>
        </article>
</router-link>
    </div>

    <!-- Chưa có bài viết -->
    <div
      v-else
      class="py-12 text-center text-slate-500 dark:text-slate-400"
    >
      Chưa có bài viết để hiển thị.
    </div>

  </div>
</section>

  <!-- ===================== CONTACT ===================== -->
  <section id="contact" class="section-padding" style="background-color: #172033;">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="text-center mb-12" data-aos="fade-up">
        <span class="section-tag mb-4">
          <i data-lucide="map-pin" class="w-3.5 h-3.5"></i>
          Liên hệ
        </span>
        <h2 class="font-heading font-bold text-3xl lg:text-4xl text-white mt-3">Get In Touch</h2>
        <p class="text-slate-300 mt-3">Liên hệ theo thông tin bên dưới để được hỗ trợ</p>
      </div>

      <div class="grid lg:grid-cols-3 gap-6 mb-10">
        <div class="card-medical dark:bg-slate-800 text-center" data-aos="fade-up" data-aos-delay="0">
          <div class="w-14 h-14 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <img
  src="/assets/zalo.webp"
  alt="Zalo"
  class="w-7 h-7 object-contain"
/>
          </div>
          <h3 class="font-heading font-semibold text-base text-slate-800 dark:text-white mb-2">ZALO</h3>
          <a href="tel:+18002273461" class="text-red-600 font-bold text-lg">034 969 1818</a>
          <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">Hoạt động 24/7 , 7ngày/tuần</p>
        </div>

        <div class="card-medical dark:bg-slate-800 text-center" data-aos="fade-up" data-aos-delay="100">
          <div class="w-14 h-14 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <img
  src="/assets/tiktok.avif"
  alt="Zalo"
  class="w-7 h-7 object-contain"
/>
          </div>
          <h3 class="font-heading font-semibold text-base text-slate-800 dark:text-white mb-2">TIKTOK</h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">Duy Chân Dung</p>
          <a href="https://www.tiktok.com/@duy.chn.dung" class="text-blue-600 text-sm font-medium mt-2 inline-block">Bấm vào để xem kênh tiktok →</a>
        </div>

        <div class="card-medical dark:bg-slate-800 text-center" data-aos="fade-up" data-aos-delay="100">
          <div class="w-14 h-14 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <img
  src="/assets/facebook.jpg"
  alt="Zalo"
  class="w-7 h-7 object-contain"
/>
          </div>
          <h3 class="font-heading font-semibold text-base text-slate-800 dark:text-white mb-2">FACEBOOK</h3>
          <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">Duy Art</p>
          <a href="https://www.facebook.com/art.duy.54" class="text-blue-600 text-sm font-medium mt-2 inline-block">Bấm vào để xem kênh facebook →</a>
        </div>
      </div>
    </div>
  </section>


  
</template>
<style scoped>
.featured-product-card {
  display: grid;
  grid-template-rows: minmax(0, 4fr) minmax(0, 1fr);
  width: 100%;
  height: 300px;
  min-width: 0;
  padding: 18px 18px 8px;
  overflow: hidden;
  border: 0;
  border-radius: 38px;
  background: #cbd5df;
  box-shadow: 0 5px 18px rgb(0 0 0 / 10%);
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;
}

.featured-product-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 12px 26px rgb(0 0 0 / 18%);
}

.featured-product-image {
  display: flex;
  width: 100%;
  min-width: 0;
  min-height: 0;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: 24px;
  background: #000;
}

.featured-product-image img {
  display: block;
  width: 100%;
  height: 100%;
 object-fit: cover;
  transition: transform 0.35s ease;
}

.featured-product-card:hover .featured-product-image img {
  transform: scale(1.04);
}

.featured-product-no-image {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  color: #d1d5db;
  font-size: 13px;
}

.featured-product-name {
  display: flex;
  min-width: 0;
  min-height: 0;
  align-items: center;
  justify-content: center;
  padding: 8px 10px;
  text-align: center;
}

.featured-product-name h3 {
  display: -webkit-box;
  max-width: 100%;
  overflow: hidden;
  color: #000;
  font-size: 20px;
  font-weight: 800;
  line-height: 1.3;
  overflow-wrap: anywhere;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}
.blog-description {
  display: -webkit-box;
  overflow: hidden;
  overflow-wrap: anywhere;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
  line-clamp: 3;
}
.blog-img {
  position: relative;
  width: 100%;
  height: 192px;
  overflow: hidden;
}

.blog-img img {
  position: absolute;
  inset: 0;
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
}
.featured-view-more-wrap {
  display: flex;
  justify-content: center;
  margin-top: 60px;
  padding-bottom: 12px;
}

.featured-view-more-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 14px 30px;
  border: 2px solid #fbbf24;
  border-radius: 12px;
  background: #fbbf24;
  color: #172033;
  font-size: 14px;
  font-weight: 800;
  text-decoration: none;
  box-shadow: 0 6px 18px rgb(251 191 36 / 20%);
  transition:
    background 0.25s ease,
    color 0.25s ease,
    transform 0.25s ease,
    box-shadow 0.25s ease;
}

.featured-view-more-btn:hover {
  transform: translateY(-3px);
  background: #fff;
  border-color: #fff;
  color: #b45309;
  box-shadow: 0 10px 24px rgb(255 255 255 / 12%);
}

.featured-view-more-btn i,
.featured-view-more-btn svg {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
}

@media (max-width: 480px) {
  .featured-view-more-wrap {
    margin-top: 40px;
  }

  .featured-view-more-btn {
    padding: 12px 24px;
    font-size: 13px;
  }
}
@media (max-width: 480px) {
  .featured-product-card {
    height: 270px;
    padding: 15px 15px 7px;
    border-radius: 30px;
  }

  .featured-product-image {
    border-radius: 20px;
  }

  .featured-product-name h3 {
    font-size: 17px;
  }
}
/*card blog css */

/* Toàn bộ card blog có thể bấm để xem chi tiết */
.blog-card-link {
  display: block;
  min-width: 0;
  height: 100%;
  color: inherit;
  text-decoration: none;
  cursor: pointer;
}

.blog-card-link .blog-card {
  height: 100%;
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;
}

.blog-card-link:hover .blog-card {
  transform: translateY(-4px);
}

</style>