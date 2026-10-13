
<script setup lang="ts">
import { shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import type { Component } from 'vue'

import { API_URL } from '../config/api'
import ProductDetail from './ProductDetail.vue'
import BlogDetail from './BlogDetail.vue'

const route = useRoute()

const resolvedComponent = shallowRef<Component | null>(null)
const checkingSlug = shallowRef(true)

let requestVersion = 0

async function resolveSlug() {
  const version = ++requestVersion
  const slug = route.params.slug

  resolvedComponent.value = null
  checkingSlug.value = true

  if (typeof slug !== 'string' || !slug.trim()) {
    checkingSlug.value = false
    return
  }

  const identifier = encodeURIComponent(slug)

  try {
    // Kiểm tra slug có phải sản phẩm không
    await axios.get(`${API_URL}/products/${identifier}`)

    if (version === requestVersion) {
      resolvedComponent.value = ProductDetail
    }
  } catch {
    try {
      // Không tìm thấy sản phẩm thì kiểm tra blog
      await axios.get(`${API_URL}/blogs/${identifier}`)

      if (version === requestVersion) {
        resolvedComponent.value = BlogDetail
      }
    } catch {
      if (version === requestVersion) {
        resolvedComponent.value = null
      }
    }
  } finally {
    if (version === requestVersion) {
      checkingSlug.value = false
    }
  }
}

watch(
  () => route.params.slug,
  () => {
    void resolveSlug()
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="checkingSlug" class="slug-loading">
    Đang tải nội dung...
  </div>

  <component
    :is="resolvedComponent"
    v-else-if="resolvedComponent"
  />

  <main v-else class="slug-not-found">
    <h1>Không tìm thấy nội dung</h1>
    <p>Đường dẫn không tồn tại hoặc nội dung đã bị xóa.</p>
    <router-link to="/">Về trang chủ</router-link>
  </main>
</template>

<style scoped>
.slug-loading,
.slug-not-found {
  min-height: 300px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 24px;
  text-align: center;
}

.slug-not-found h1 {
  font-size: 28px;
  font-weight: 700;
}

.slug-not-found a {
  color: #dc2626;
}
</style>
