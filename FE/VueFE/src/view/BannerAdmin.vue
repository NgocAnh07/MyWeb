<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { API_URL, STORAGE_BASE_URL } from '../config/api'

interface BannerResponse {
  path: string | null
  updated_at?: number | null
  message?: string
}

const currentBanner = ref('')
const selectedFile = ref<File | null>(null)
const previewUrl = ref('')
const loading = ref(false)
const uploading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

function getConfig() {
  const token = localStorage.getItem('auth_token')

  return {
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  }
}

function imageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path

  const normalized = path.replace(/^\/+/, '')

  if (normalized.startsWith('storage/')) {
    return `${STORAGE_BASE_URL}/${normalized}`
  }

  return `${STORAGE_BASE_URL}/storage/${normalized}`
}

async function loadBanner() {
  loading.value = true
  errorMessage.value = ''

  try {
    const response = await axios.get<BannerResponse>(
      `${API_URL}/banner`,
      getConfig(),
    )

    currentBanner.value = imageUrl(response.data.path)
  } catch (error: unknown) {
    console.error('Không tải được banner:', error)
    errorMessage.value = 'Không tải được banner hiện tại. Hãy kiểm tra Backend.'
  } finally {
    loading.value = false
  }
}

function clearPreview() {
  if (previewUrl.value.startsWith('blob:')) {
    URL.revokeObjectURL(previewUrl.value)
  }

  previewUrl.value = ''
}

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null

  selectedFile.value = null
  clearPreview()
  errorMessage.value = ''
  successMessage.value = ''

  if (!file) return

  if (!file.type.startsWith('image/')) {
    errorMessage.value = 'Vui lòng chọn một file ảnh.'
    input.value = ''
    return
  }

  if (file.size > 5 * 1024 * 1024) {
    errorMessage.value = 'Ảnh không được vượt quá 5 MB.'
    input.value = ''
    return
  }

  selectedFile.value = file
  previewUrl.value = URL.createObjectURL(file)
}

async function uploadBanner() {
  if (!selectedFile.value || uploading.value) return

  const confirmed = window.confirm(
    'Thay banner hiện tại bằng ảnh mới đã chọn?',
  )

  if (!confirmed) return

  uploading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const body = new FormData()
    body.append('image', selectedFile.value)

    const response = await axios.post<BannerResponse>(
      `${API_URL}/banner`,
      body,
      getConfig(),
    )

    currentBanner.value = imageUrl(response.data.path)

    selectedFile.value = null
    clearPreview()

    if (fileInput.value) {
      fileInput.value.value = ''
    }

    successMessage.value =
      response.data.message ?? 'Cập nhật banner thành công.'

  } catch (error: unknown) {
    console.error('Không cập nhật được banner:', error)

    if (axios.isAxiosError(error)) {
      const data = error.response?.data as
        | { message?: string; errors?: Record<string, string[]> }
        | undefined

      errorMessage.value =
        Object.values(data?.errors ?? {})[0]?.[0] ??
        data?.message ??
        'Không cập nhật được banner. Hãy kiểm tra quyền Admin và Backend.'
    } else {
      errorMessage.value = 'Đã xảy ra lỗi khi tải banner.'
    }
  } finally {
    uploading.value = false
  }
}

onMounted(loadBanner)

onBeforeUnmount(clearPreview)
</script>

<template>
  <main class="banner-admin">
    <header class="banner-header">
      <div>
        <p>TRANG QUẢN TRỊ / GIAO DIỆN</p>
        <h1>Quản lý banner trang chủ</h1>
        <p class="banner-subtitle">
          Thay ảnh banner trên trang chủ .
        </p>
      </div>

      <router-link to="/admin" class="back-button">
        ← Quay lại Admin
      </router-link>
    </header>

    <section class="banner-panel">
      <h2>Banner hiện tại</h2>

      <div v-if="loading" class="banner-status">
        Đang tải banner...
      </div>

      <div v-else-if="currentBanner" class="banner-preview">
        <img :src="currentBanner" alt="Banner hiện tại của website" />
      </div>

      <p v-else class="banner-status">
        Chưa có banner hoặc không tải được ảnh.
      </p>

      <div class="banner-upload">
        <label for="banner-image">Chọn banner mới</label>

        <input
          id="banner-image"
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          @change="onFileChange"
        />

        <p class="banner-help">
          Hỗ trợ JPG, PNG, WebP. Dung lượng tối đa 5 MB.
        </p>

        <div v-if="previewUrl" class="banner-preview">
          <p class="preview-label">Xem trước ảnh mới</p>
          <img :src="previewUrl" alt="Xem trước banner mới" />
        </div>

        <p v-if="errorMessage" class="banner-message banner-error">
          {{ errorMessage }}
        </p>

        <p v-if="successMessage" class="banner-message banner-success">
          {{ successMessage }}
        </p>

        <button
          type="button"
          class="save-button"
          :disabled="!selectedFile || uploading"
          @click="uploadBanner"
        >
          {{ uploading ? 'Đang tải lên...' : 'Lưu banner mới' }}
        </button>
      </div>
    </section>
  </main>
</template>

<style scoped>
.banner-admin {
  box-sizing: border-box;
  min-height: 100vh;
  padding: 32px;
  background: #f5f7f5;
  color: #26372c;
  font-family: "Segoe UI", Tahoma, Arial, sans-serif;
}

.banner-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 28px;
}

.banner-header h1 {
  margin: 8px 0;
  font-size: 26px;
  font-weight: 800;
}

.banner-header > div > p:first-child {
  color: #548665;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 1px;
}

.banner-subtitle {
  color: #7b897f;
  font-size: 13px;
}

.back-button {
  flex-shrink: 0;
  padding: 11px 15px;
  border: 1px solid #dce9df;
  border-radius: 8px;
  background: white;
  color: #28784e;
  font-size: 12px;
  text-decoration: none;
}

.banner-panel {
  max-width: 1000px;
  padding: 24px;
  border: 1px solid #e7ece8;
  border-radius: 14px;
  background: white;
}

.banner-panel h2 {
  margin: 0 0 18px;
  font-size: 17px;
  font-weight: 800;
}

.banner-preview {
  width: 100%;
  overflow: hidden;
  border: 1px solid #e2e8e4;
  border-radius: 10px;
  background: #f1f5f2;
}

.banner-preview img {
  display: block;
  width: 100%;
  max-height: 520px;
  object-fit: contain;
}

.banner-upload {
  display: grid;
  gap: 12px;
  margin-top: 24px;
}

.banner-upload label {
  font-size: 13px;
  font-weight: 700;
}

.banner-upload input[type="file"] {
  width: 100%;
  padding: 12px;
  border: 1px dashed #cbd5ce;
  border-radius: 8px;
  background: #fafcfb;
  font-size: 12px;
}

.banner-help,
.preview-label {
  margin: 0;
  color: #7b897f;
  font-size: 12px;
}

.save-button {
  width: fit-content;
  padding: 12px 20px;
  border: 0;
  border-radius: 8px;
  background: #28784e;
  color: white;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}

.save-button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.banner-message {
  margin: 0;
  padding: 11px 13px;
  border-radius: 8px;
  font-size: 12px;
}

.banner-error {
  background: #fff1f2;
  color: #b91c1c;
}

.banner-success {
  background: #f0fdf4;
  color: #166534;
}

.banner-status {
  padding: 24px;
  color: #7b897f;
  font-size: 13px;
  text-align: center;
}

@media (max-width: 640px) {
  .banner-admin {
    padding: 20px 14px;
  }

  .banner-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .banner-header h1 {
    font-size: 22px;
  }

  .banner-panel {
    padding: 16px;
  }
}
</style>