/**
 * Cấu hình tập trung cho API Laravel và URL ảnh lưu trong public/storage.
 * Khi triển khai website, cập nhật VITE_API_BASE_URL và
 * VITE_STORAGE_BASE_URL trong FE/VueFE/.env.local trước khi build.
 */
const trimTrailingSlashes = (value: string): string => value.replace(/\/+$/, '')

export const API_URL = trimTrailingSlashes(
  import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000/api',
)

export const STORAGE_BASE_URL = trimTrailingSlashes(
  import.meta.env.VITE_STORAGE_BASE_URL || API_URL.replace(/\/api$/, ''),
)
