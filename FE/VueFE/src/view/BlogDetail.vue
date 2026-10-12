<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'
import { useRoute } from 'vue-router'

interface BlogPost {
  id: number
  title: string
  img: string | null
  description: string
  created_at?: string
  updated_at?: string
}

const route = useRoute()
const blog = ref<BlogPost | null>(null)
const relatedBlogs = ref<BlogPost[]>([])
const loading = ref(false)
const relatedLoading = ref(false)
const errorMessage = ref('')

const blogId = computed(() => {
  const id = Number(route.params.id)
  return Number.isInteger(id) && id > 0 ? id : null
})

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  const normalizedPath = path.replace(/^\/+/, '')
  return normalizedPath.startsWith('storage/')
    ? `${STORAGE_BASE_URL}/${normalizedPath}`
    : `${STORAGE_BASE_URL}/storage/${normalizedPath}`
}

function formatDate(value: string | undefined): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('vi-VN', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(date)
}

function extractList(payload: unknown): BlogPost[] {
  if (Array.isArray(payload)) return payload as BlogPost[]
  if (typeof payload !== 'object' || payload === null) return []
  const object = payload as Record<string, unknown>
  if (Array.isArray(object.data)) return object.data as BlogPost[]
  if (Array.isArray(object.blogs)) return object.blogs as BlogPost[]
  return []
}

function extractBlog(payload: unknown): BlogPost | null {
  if (typeof payload !== 'object' || payload === null) return null
  const object = payload as Record<string, unknown>
  const candidate = object.blog ?? object.data ?? payload
  if (typeof candidate !== 'object' || candidate === null) return null
  const item = candidate as Record<string, unknown>
  if (typeof item.id !== 'number' || typeof item.title !== 'string') return null
  return {
    id: item.id,
    title: item.title,
    img: typeof item.img === 'string' ? item.img : null,
    description: typeof item.description === 'string' ? item.description : '',
    created_at: typeof item.created_at === 'string' ? item.created_at : undefined,
    updated_at: typeof item.updated_at === 'string' ? item.updated_at : undefined,
  }
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function isSafeLink(value: string): boolean {
  return /^https?:\/\//i.test(value) || (/^\/(?!\/)/.test(value)) || value.startsWith('#') || value.startsWith('./') || value.startsWith('../')
}

function safeDescriptionHtml(rawValue: string): string {
  const value = rawValue ?? ''
  const hasHtmlBlocks = /<\s*(p|h[2-6]|ul|ol|li|strong|b|em|i|u|blockquote|a|img|figure|br|hr)\b/i.test(value)
  const inputHtml = hasHtmlBlocks
    ? value
    : value.trim().split(/\n\s*\n/).map(paragraph => `<p>${escapeHtml(paragraph).replace(/\n/g, '<br>')}</p>`).join('')

  const template = document.createElement('template')
  template.innerHTML = inputHtml
  template.content.querySelectorAll('script,style,iframe,object,embed,svg,math,form,input,button,textarea,select,video,audio,link,meta').forEach(node => node.remove())

  const allowedTags = new Set(['DIV', 'P', 'BR', 'HR', 'H2', 'H3', 'H4', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'BLOCKQUOTE', 'A', 'IMG', 'FIGURE', 'FIGCAPTION'])
  const walk = (parent: ParentNode) => {
    Array.from(parent.childNodes).forEach(node => {
      if (!(node instanceof HTMLElement)) return
      walk(node)
      if (!allowedTags.has(node.tagName)) {
        node.replaceWith(...Array.from(node.childNodes))
        return
      }

      const allowedAttributes = node.tagName === 'A'
        ? new Set(['href', 'title', 'target', 'rel'])
        : node.tagName === 'IMG'
          ? new Set(['src', 'alt', 'title', 'width', 'height', 'loading'])
          : new Set(['title'])

      Array.from(node.attributes).forEach(attribute => {
        if (!allowedAttributes.has(attribute.name.toLowerCase())) node.removeAttribute(attribute.name)
      })

      if (node.tagName === 'A') {
        const href = node.getAttribute('href') ?? ''
        if (!isSafeLink(href)) node.removeAttribute('href')
        if (node.getAttribute('target') === '_blank') node.setAttribute('rel', 'noopener noreferrer')
        else {
          node.removeAttribute('target')
          node.removeAttribute('rel')
        }
      }

      if (node.tagName === 'IMG') {
        const src = node.getAttribute('src') ?? ''
        if (!src || !isSafeLink(src) && !/^storage\//i.test(src)) {
          node.remove()
          return
        }
        if (/^storage\//i.test(src)) {
          node.setAttribute('src', imageUrl(src))
        } else if (src.startsWith('/storage/')) {
          node.setAttribute('src', imageUrl(src))
        }
        node.setAttribute('alt', node.getAttribute('alt') ?? '')
        node.setAttribute('loading', 'lazy')
      }
    })
  }

  walk(template.content)
  return template.innerHTML
}

async function loadRelatedBlogs(currentId: number) {
  relatedLoading.value = true
  try {
    const response = await axios.get(`${API_URL}/blogs?page=1`)
    relatedBlogs.value = extractList(response.data)
      .filter(item => item.id !== currentId)
      .slice(0, 3)
  } catch (error) {
    console.error('Không tải được bài viết liên quan:', error)
    relatedBlogs.value = []
  } finally {
    relatedLoading.value = false
  }
}

async function loadBlog() {
  blog.value = null
  relatedBlogs.value = []
  errorMessage.value = ''
  document.title = 'Chi tiết bài viết | Duy Chân Dung'

  if (blogId.value === null) {
    errorMessage.value = 'Đường dẫn bài viết không hợp lệ.'
    return
  }

  loading.value = true
  try {
    const response = await axios.get(`${API_URL}/blogs/${blogId.value}`)
    const result = extractBlog(response.data)
    if (!result) throw new Error('Dữ liệu bài viết không đúng định dạng.')

    blog.value = result
    document.title = `${result.title} | Duy Chân Dung`
    void loadRelatedBlogs(result.id)
  } catch (error) {
    console.error('Không tải được chi tiết bài viết:', error)
    errorMessage.value = axios.isAxiosError(error) && error.response?.status === 404
      ? 'Không tìm thấy bài viết này.'
      : 'Không tải được bài viết. Hãy kiểm tra kết nối Backend.'
  } finally {
    loading.value = false
  }
}

onMounted(loadBlog)
watch(blogId, () => { void loadBlog() })
</script>

<template>
  <main class="blog-detail-page">
    <section class="blog-detail-hero">
      <div class="blog-detail-shell">
        <router-link to="/" class="blog-detail-back">← Về trang chủ</router-link>
        <p class="blog-detail-eyebrow">GÓC CHIA SẺ · DUY CHÂN DUNG</p>
        <p class="blog-detail-hero-title">Góc chia sẻ <span>&amp; Câu chuyện</span></p>
        <p class="blog-detail-hero-text">Những câu chuyện, cảm hứng và kinh nghiệm xoay quanh nghệ thuật chân dung.</p>
      </div>
    </section>

    <section v-if="loading" class="blog-detail-state">
      <span class="blog-detail-spinner" aria-hidden="true"></span>
      <p>Đang tải bài viết...</p>
    </section>

    <section v-else-if="errorMessage" class="blog-detail-state">
      <div class="blog-detail-error">
        <h2>Không thể mở bài viết</h2>
        <p>{{ errorMessage }}</p>
        <button type="button" class="blog-detail-button" @click="loadBlog">Thử lại</button>
        <router-link to="/" class="blog-detail-text-link">Về trang chủ</router-link>
      </div>
    </section>

    <div v-else-if="blog" class="blog-detail-shell blog-detail-content">
      <nav class="blog-detail-breadcrumb" aria-label="Điều hướng">
        <router-link to="/">Trang chủ</router-link>
        <span>/</span>
        <span>Blog</span>
        <span>/</span>
        <span class="blog-detail-breadcrumb-current">{{ blog.title }}</span>
      </nav>

      <article class="blog-detail-article">
        <header class="blog-detail-article-header">
          <span class="blog-detail-tag">BÀI VIẾT</span>
          <h1>{{ blog.title }}</h1>
          <div class="blog-detail-meta">
            <span class="blog-detail-meta-icon" aria-hidden="true">◷</span>
            <time v-if="blog.created_at" :datetime="blog.created_at">{{ formatDate(blog.created_at) }}</time>
            <span v-else>Bài viết từ Duy Chân Dung</span>
          </div>
        </header>

        <figure v-if="blog.img" class="blog-detail-cover">
          <img :src="imageUrl(blog.img)" :alt="blog.title" />
        </figure>
        <div v-else class="blog-detail-cover blog-detail-cover-empty">
          <span>DUY CHÂN DUNG</span>
          <p>{{ blog.title }}</p>
        </div>

        <div class="blog-detail-body" v-html="safeDescriptionHtml(blog.description)"></div>

        <footer class="blog-detail-article-footer">
          <router-link to="/" class="blog-detail-text-link">← Quay lại trang chủ</router-link>
          <span>Được chia sẻ bởi <strong>Duy Chân Dung</strong></span>
        </footer>
      </article>

      <section class="blog-related-section">
        <div class="blog-related-heading">
          <div>
            <p class="blog-related-eyebrow">ĐỌC THÊM</p>
            <h2>Bài viết mới nhất</h2>
          </div>
          <router-link to="/#blog" class="blog-related-all">Khám phá thêm <span aria-hidden="true">→</span></router-link>
        </div>

        <p v-if="relatedLoading" class="blog-related-state">Đang tải bài viết...</p>
        <p v-else-if="relatedBlogs.length === 0" class="blog-related-state">Chưa có bài viết liên quan.</p>
        <div v-else class="blog-related-grid">
          <router-link
            v-for="item in relatedBlogs"
            :key="item.id"
            :to="`/blogs/${item.id}`"
            class="blog-related-card"
          >
            <div class="blog-related-image">
              <img v-if="item.img" :src="imageUrl(item.img)" :alt="item.title" loading="lazy" />
              <span v-else>DUY CHÂN DUNG</span>
            </div>
            <div class="blog-related-info">
              <time v-if="item.created_at">{{ formatDate(item.created_at) }}</time>
              <h3>{{ item.title }}</h3>
              <span class="blog-related-read">Đọc bài viết <span aria-hidden="true">→</span></span>
            </div>
          </router-link>
        </div>
      </section>
    </div>
  </main>
</template>

<style scoped>
/* Font thống nhất cho TOÀN BỘ trang chi tiết blog, có đủ glyph tiếng Việt.
   Dùng !important để tránh font heading/global CSS của template ghi đè. */
.blog-detail-page,
.blog-detail-page *,
.blog-detail-page *::before,
.blog-detail-page *::after {
  font-family: "Segoe UI", Arial, Tahoma, sans-serif !important;
  font-synthesis: weight style;
  font-kerning: normal;
}

/* Giữ tiếng Việt dễ đọc, tránh các phần nội dung bị kế thừa font khác. */
.blog-detail-page {
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
}

.blog-detail-page button,
.blog-detail-page input,
.blog-detail-page textarea,
.blog-detail-page select {
  font: inherit;
}
.blog-detail-page{min-height:70vh;background:#f8fafc;color:#1e293b}
.blog-detail-shell{width:min(1120px,calc(100% - 40px));margin:0 auto}
.blog-detail-hero{padding:34px 0 46px;color:#fff;background:radial-gradient(circle at 88% 12%,rgb(220 38 38 / 22%),transparent 32%),linear-gradient(125deg,#0f172a,#1e293b 65%,#3b1724)}
.blog-detail-back{display:inline-flex;align-items:center;gap:8px;color:#cbd5e1;font-size:13px;text-decoration:none;transition:color .2s}
.blog-detail-back:hover{color:#fff}
.blog-detail-eyebrow{margin:30px 0 10px;color:#f87171;font-size:10px;font-weight:800;letter-spacing:2px}
.blog-detail-hero-title{margin:0;color:#fff;font-size:clamp(30px,5vw,50px);font-weight:900;line-height:1.15;letter-spacing:-1px}
.blog-detail-hero-title span{color:#fb7185;font-weight:500}
.blog-detail-hero-text{max-width:620px;margin:14px 0 0;color:#cbd5e1;font-size:14px;line-height:1.8}
.blog-detail-content{padding-top:28px;padding-bottom:64px}
.blog-detail-breadcrumb{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin-bottom:24px;color:#94a3b8;font-size:12px}
.blog-detail-breadcrumb a{color:#64748b;text-decoration:none}
.blog-detail-breadcrumb a:hover{color:#dc2626}
.blog-detail-breadcrumb-current{max-width:360px;overflow:hidden;color:#334155;text-overflow:ellipsis;white-space:nowrap}
.blog-detail-article{overflow:hidden;border:1px solid #e2e8f0;border-radius:22px;background:#fff;box-shadow:0 10px 35px rgb(15 23 42 / 5%)}
.blog-detail-article-header{max-width:880px;margin:0 auto;padding:clamp(24px,5vw,54px) clamp(20px,5vw,58px) 30px;text-align:center}
.blog-detail-tag{display:inline-block;padding:7px 11px;border-radius:999px;background:#fff1f2;color:#be123c;font-size:10px;font-weight:900;letter-spacing:1.2px}
.blog-detail-article-header h1{margin:20px 0 16px;color:#0f172a;font-size:clamp(26px,4vw,42px);font-weight:900;line-height:1.28;overflow-wrap:anywhere}
.blog-detail-meta{display:flex;align-items:center;justify-content:center;gap:7px;color:#94a3b8;font-size:12px}
.blog-detail-meta-icon{font-size:18px;color:#dc2626}
.blog-detail-cover{display:flex;max-height:620px;min-height:220px;align-items:center;justify-content:center;overflow:hidden;margin:0;background:#e2e8f0}
.blog-detail-cover img{display:block;width:100%;max-height:620px;object-fit:cover;object-position:center}
.blog-detail-cover-empty{min-height:260px;flex-direction:column;background:linear-gradient(135deg,#1e293b,#7f1d1d);color:#fff;text-align:center}
.blog-detail-cover-empty span{font-size:11px;font-weight:900;letter-spacing:3px;color:#fda4af}
.blog-detail-cover-empty p{max-width:80%;font-size:26px;font-weight:800}
.blog-detail-body{max-width:850px;margin:0 auto;padding:clamp(26px,5vw,54px) clamp(20px,5vw,45px);color:#475569;font-size:16px;line-height:1.9;overflow-wrap:anywhere}
.blog-detail-body :deep(p){margin:0 0 1.15em}
.blog-detail-body :deep(h2){margin:1.8em 0 .65em;color:#0f172a;font-size:clamp(22px,3vw,30px);font-weight:850;line-height:1.35}
.blog-detail-body :deep(h3){margin:1.5em 0 .55em;color:#1e293b;font-size:clamp(18px,2.5vw,23px);font-weight:800;line-height:1.4}
.blog-detail-body :deep(h4){margin:1.25em 0 .5em;color:#334155;font-size:18px;font-weight:800}
.blog-detail-body :deep(ul),.blog-detail-body :deep(ol){margin:0 0 1.2em;padding-left:1.5em}
.blog-detail-body :deep(ul){list-style:disc}.blog-detail-body :deep(ol){list-style:decimal}
.blog-detail-body :deep(li){margin:.35em 0;padding-left:.25em}
.blog-detail-body :deep(blockquote){margin:1.3em 0;padding:12px 18px;border-left:4px solid #dc2626;background:#f8fafc;color:#334155}
.blog-detail-body :deep(a){color:#16834b;text-decoration:underline;text-underline-offset:3px;overflow-wrap:anywhere}
.blog-detail-body :deep(figure){margin:1.7em 0;text-align:center}
.blog-detail-body :deep(img){display:block;max-width:100%;max-height:700px;height:auto;margin:0 auto;border-radius:12px;object-fit:contain}
.blog-detail-body :deep(figcaption){margin-top:8px;color:#64748b;font-size:12px;font-style:italic}
.blog-detail-body :deep(hr){margin:2em 0;border:0;border-top:1px solid #e2e8f0}

.blog-detail-article-footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:20px clamp(20px,5vw,45px);border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px}
.blog-detail-article-footer strong{color:#dc2626;font-weight:900}
.blog-detail-text-link{color:#dc2626;font-size:13px;font-weight:800;text-decoration:none}
.blog-detail-text-link:hover{text-decoration:underline}
.blog-detail-state{display:grid;min-height:300px;place-content:center;justify-items:center;gap:10px;padding:36px 20px;color:#64748b;text-align:center}
.blog-detail-error{max-width:480px;padding:28px;border:1px solid #fecaca;border-radius:16px;background:#fff}
.blog-detail-error h2{margin:0 0 8px;color:#0f172a;font-size:22px}
.blog-detail-error p{line-height:1.7}
.blog-detail-button{margin-right:12px;padding:10px 16px;border:0;border-radius:9px;background:#dc2626;color:#fff;font-weight:800;cursor:pointer}
.blog-detail-spinner{width:30px;height:30px;border:3px solid #e2e8f0;border-top-color:#dc2626;border-radius:50%;animation:blog-spin .8s linear infinite}
@keyframes blog-spin{to{transform:rotate(360deg)}}
.blog-related-section{margin-top:48px}
.blog-related-heading{display:flex;flex-wrap:wrap;align-items:end;justify-content:space-between;gap:16px;margin-bottom:20px}
.blog-related-eyebrow{margin:0 0 7px;color:#dc2626;font-size:10px;font-weight:900;letter-spacing:1.8px}
.blog-related-heading h2{margin:0;color:#0f172a;font-size:clamp(23px,3vw,30px);font-weight:900}
.blog-related-all{display:inline-flex;align-items:center;gap:8px;color:#dc2626;font-size:12px;font-weight:800;text-decoration:none}
.blog-related-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.blog-related-card{display:flex;min-width:0;flex-direction:column;overflow:hidden;border:1px solid #e2e8f0;border-radius:16px;background:#fff;color:inherit;text-decoration:none;box-shadow:0 5px 16px rgb(15 23 42 / 4%);transition:transform .2s,box-shadow .2s,border-color .2s}
.blog-related-card:hover{transform:translateY(-4px);border-color:#fecaca;box-shadow:0 12px 25px rgb(15 23 42 / 9%)}
.blog-related-image{display:flex;height:190px;align-items:center;justify-content:center;overflow:hidden;background:#e2e8f0;color:#94a3b8;font-size:11px;font-weight:900;letter-spacing:2px}
.blog-related-image img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
.blog-related-card:hover .blog-related-image img{transform:scale(1.04)}
.blog-related-info{display:flex;flex:1;flex-direction:column;align-items:flex-start;padding:18px}
.blog-related-info time{margin-bottom:9px;color:#94a3b8;font-size:11px}
.blog-related-info h3{display:-webkit-box;min-height:44px;margin:0;overflow:hidden;color:#0f172a;font-size:16px;font-weight:900;line-height:1.4;overflow-wrap:anywhere;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2}
.blog-related-read{display:inline-flex;align-items:center;gap:7px;margin-top:15px;color:#dc2626;font-size:12px;font-weight:900}
.blog-related-state{padding:24px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;text-align:center}
@media(max-width:760px){.blog-detail-shell{width:min(100% - 28px,1120px)}.blog-detail-hero{padding-top:25px;padding-bottom:35px}.blog-detail-eyebrow{margin-top:24px}.blog-detail-article{border-radius:15px}.blog-detail-cover{min-height:180px}.blog-detail-cover img{max-height:420px}.blog-detail-body{font-size:15px;line-height:1.9}.blog-related-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.blog-related-image{height:145px}.blog-related-info{padding:13px}.blog-related-info h3{font-size:14px}.blog-detail-article-footer{align-items:flex-start;flex-direction:column}}
@media(max-width:420px){.blog-related-grid{grid-template-columns:1fr}.blog-related-image{height:190px}.blog-detail-breadcrumb-current{max-width:170px}}
</style>
