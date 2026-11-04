
export interface SeoOptions {
  title: string
  description: string
  canonical?: string
  image?: string | null
  imageAlt?: string
  type?: 'website' | 'article' | 'product'
  robots?: string
  structuredData?: Record<string, unknown> | null
}

const SITE_NAME = 'Duy Chân Dung'
const SITE_URL = 'https://duychandung.com'
const DEFAULT_IMAGE = `${SITE_URL}/assets/logo.jpeg`
const DEFAULT_DESCRIPTION =
  'Duy Chân Dung nhận vẽ tranh chân dung theo yêu cầu từ ảnh, tranh sơn dầu và tranh quà tặng.'

/**
 * Chuyển nội dung HTML thành văn bản thuần.
 */
export function plainText(value: string | null | undefined): string {
  if (!value) return ''

  if (typeof document === 'undefined') {
    return value
      .replace(/<[^>]*>/g, ' ')
      .replace(/&nbsp;/gi, ' ')
      .replace(/\s+/g, ' ')
      .trim()
  }

  const element = document.createElement('div')
  element.innerHTML = value

  return (element.textContent || '')
    .replace(/\s+/g, ' ')
    .trim()
}

/**
 * Chuẩn hóa URL Canonical.
 * Luôn sử dụng domain chính thức, không lấy localhost.
 * Không giữ query string và hash.
 */
function normalizeCanonical(url?: string): string {
  const currentPath =
    typeof window !== 'undefined'
      ? window.location.pathname
      : '/'

  try {
    const parsed = new URL(url || currentPath, SITE_URL)

    return new URL(parsed.pathname, SITE_URL).href
  } catch {
    return `${SITE_URL}/`
  }
}

/**
 * Tạo URL ảnh tuyệt đối.
 */
export function absoluteImageUrl(
  path: string | null | undefined
): string | undefined {
  if (!path) return undefined

  try {
    const url = new URL(path.trim(), SITE_URL)

    if (!['http:', 'https:'].includes(url.protocol)) {
      return undefined
    }

    return url.href
  } catch {
    return undefined
  }
}

/**
 * Thêm hoặc cập nhật Meta.
 * Đồng thời loại bỏ các thẻ trùng lặp.
 */
function upsertMeta(
  attribute: 'name' | 'property',
  key: string,
  content: string
) {
  if (typeof document === 'undefined') return

  const selector = `meta[${attribute}="${key}"]`
  const elements = document.head.querySelectorAll<HTMLMetaElement>(
    selector
  )

  let element = elements[0]

  if (!element) {
    element = document.createElement('meta')
    element.setAttribute(attribute, key)
    document.head.appendChild(element)
  }

  element.content = content

  // Xóa thẻ trùng lặp nếu tồn tại.
  elements.forEach((duplicate, index) => {
    if (index > 0) duplicate.remove()
  })
}

/**
 * Thêm hoặc cập nhật Canonical.
 */
function upsertCanonical(url: string) {
  if (typeof document === 'undefined') return

  const elements = document.head.querySelectorAll<HTMLLinkElement>(
    'link[rel="canonical"]'
  )

  let element = elements[0]

  if (!element) {
    element = document.createElement('link')
    element.rel = 'canonical'
    document.head.appendChild(element)
  }

  element.href = url

  elements.forEach((duplicate, index) => {
    if (index > 0) duplicate.remove()
  })
}

/**
 * Cập nhật SEO Meta cho từng trang.
 */
export function setSeoMeta(options: SeoOptions) {
  if (typeof document === 'undefined') return

  // Title
  const title =
    plainText(options.title).slice(0, 120) || SITE_NAME

  // Description
  const description =
    plainText(options.description).slice(0, 160) ||
    DEFAULT_DESCRIPTION

  // Canonical
  const canonical = normalizeCanonical(options.canonical)

  // Image
  const image =
    absoluteImageUrl(options.image) || DEFAULT_IMAGE

  const imageAlt =
    plainText(options.imageAlt) || title

  // Robots
  const robots =
    options.robots || 'index, follow, max-image-preview:large'

  // Open Graph type
  const ogType =
    options.type === 'article' ? 'article' : 'website'

  // Document title
  document.title = title

  // Basic SEO
  upsertMeta('name', 'description', description)
  upsertMeta('name', 'robots', robots)
  upsertMeta('name', 'googlebot', robots)

  // Open Graph
  upsertMeta('property', 'og:type', ogType)
  upsertMeta('property', 'og:site_name', SITE_NAME)
  upsertMeta('property', 'og:locale', 'vi_VN')
  upsertMeta('property', 'og:title', title)
  upsertMeta('property', 'og:description', description)
  upsertMeta('property', 'og:url', canonical)
  upsertMeta('property', 'og:image', image)
  upsertMeta('property', 'og:image:alt', imageAlt)

  // Twitter Card
  upsertMeta(
    'name',
    'twitter:card',
    'summary_large_image'
  )
  upsertMeta('name', 'twitter:title', title)
  upsertMeta('name', 'twitter:description', description)
  upsertMeta('name', 'twitter:image', image)

  // Canonical
  upsertCanonical(canonical)

  // Structured Data
  document.head
    .querySelectorAll('script[data-seo-jsonld]')
    .forEach(script => script.remove())

  if (options.structuredData) {
    const script = document.createElement('script')

    script.type = 'application/ld+json'
    script.dataset.seoJsonld = 'true'
    script.textContent = JSON.stringify(options.structuredData)

    document.head.appendChild(script)
  }
}
