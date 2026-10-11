<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { API_URL } from '../config/api'
import { useRouter } from 'vue-router'

const router = useRouter()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const loading = ref(false)
const errorMessage = ref('')

async function handleLogin() {
  errorMessage.value = ''

  if (!username.value.trim() || !password.value) {
    errorMessage.value = 'Vui lòng nhập tên đăng nhập và mật khẩu.'
    return
  }

  loading.value = true

  try {
    const response = await axios.post(
      `${API_URL}/login`,
      {
        username: username.value.trim(),
        password: password.value,
      },
    )

    const user = response.data.user
    const token = response.data.token

    // Chỉ tài khoản admin được vào trang quản trị
    if (user?.role !== 'admin') {
      errorMessage.value =
        'Tài khoản này không có quyền truy cập trang quản trị.'
      return
    }

    // Lưu thông tin đăng nhập
    localStorage.setItem('auth_token', token)
    localStorage.setItem('auth_user', JSON.stringify(user))

    // Chuyển đến trang quản trị
    await router.push('/admin')
  } catch (error: unknown) {
    if (axios.isAxiosError(error)) {
      const status = error.response?.status
      const errors = error.response?.data?.errors

      if (status === 401 || status === 422) {
        errorMessage.value =
          'Sai thông tin tài khoản hoặc mật khẩu.'
      } else if (status === 403) {
        errorMessage.value =
          'Tài khoản này không có quyền truy cập trang quản trị.'
      } else {
        errorMessage.value =
          error.response?.data?.message ??
          'Không thể kết nối máy chủ. Vui lòng thử lại.'
      }

      // Ưu tiên thông báo validation nếu có
      if (errors?.username?.[0]) {
        errorMessage.value = errors.username[0]
      } else if (errors?.password?.[0]) {
        errorMessage.value = errors.password[0]
      }
    } else {
      errorMessage.value = 'Đã xảy ra lỗi. Vui lòng thử lại.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="auth-page">
    <div class="auth-card">

      <!-- Cột giới thiệu -->
      <section class="auth-showcase">
        <div class="auth-decoration auth-decoration-one"></div>
        <div class="auth-decoration auth-decoration-two"></div>

        <router-link to="/" class="auth-brand">
          <img src="/assets/logo.jpeg" alt="Duy Chân Dung" />
          <span>DUY CHÂN DUNG</span>
        </router-link>

        <div class="auth-showcase-content">
          <span class="auth-eyebrow">NGHỆ THUẬT DÀNH RIÊNG CHO BẠN</span>

          <h1>
            Mỗi nét vẽ,<br />
            <span>một câu chuyện.</span>
          </h1>

          <p>
            Lưu giữ những khoảnh khắc đáng nhớ qua các tác phẩm
            được sáng tạo theo yêu cầu và mang dấu ấn riêng của bạn.
          </p>

          <div class="auth-feature">
            <span class="auth-feature-icon">✦</span>
            <div>
              <strong>Nét vẽ độc đáo</strong>
              <small>Mỗi tác phẩm là một dấu ấn riêng.</small>
            </div>
          </div>

          <div class="auth-feature">
            <span class="auth-feature-icon">♡</span>
            <div>
              <strong>Lưu giữ kỷ niệm</strong>
              <small>Trao gửi cảm xúc qua từng bức tranh.</small>
            </div>
          </div>
        </div>

        <p class="auth-showcase-footer">
          Nghệ thuật bắt đầu từ những điều thân thuộc.
        </p>
      </section>

      <!-- Cột đăng nhập -->
      <section class="auth-form-panel">
        <div class="auth-form-content">

          <router-link to="/" class="auth-mobile-brand">
            <img src="/assets/logo.jpeg" alt="Duy Chân Dung" />
          </router-link>

          <div class="auth-heading">
            <span class="auth-eyebrow">CHÀO MỪNG BẠN TRỞ LẠI</span>
            <h2>Đăng nhập</h2>
            <p>Đăng nhập để tiếp tục khám phá thế giới tranh nghệ thuật.</p>
          </div>

          <form @submit.prevent="handleLogin">
            <div class="auth-field">
              <label for="auth-username">Tên đăng nhập</label>

              <div class="auth-input-wrap">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.7"
                  aria-hidden="true"
                >
                  <circle cx="12" cy="8" r="4"></circle>
                  <path d="M5 21v-2a7 7 0 0 1 14 0v2"></path>
                </svg>

                <input
                  id="auth-username"
                  v-model="username"
                  type="text"
                  placeholder="Nhập tên đăng nhập"
                  autocomplete="username"
                  required
                />
              </div>
            </div>

            <div class="auth-field">
              <label for="auth-password">Mật khẩu</label>

              <div class="auth-input-wrap">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.7"
                  aria-hidden="true"
                >
                  <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                  <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                </svg>

                <input
                  id="auth-password"
                  v-model="password"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="Nhập mật khẩu"
                  autocomplete="current-password"
                  required
                />

                <button
                  type="button"
                  class="auth-toggle"
                  @click="showPassword = !showPassword"
                >
                  {{ showPassword ? 'Ẩn' : 'Hiện' }}
                </button>
              </div>
            </div>

            <p v-if="errorMessage" class="auth-error" role="alert">
              {{ errorMessage }}
            </p>

            <button
              type="submit"
              class="auth-submit"
              :disabled="loading"
            >
              <span v-if="loading" class="auth-spinner"></span>
              {{ loading ? 'Đang đăng nhập...' : 'Đăng nhập' }}
              <span v-if="!loading" aria-hidden="true">→</span>
            </button>
          </form>

          <div class="auth-separator">
            <span></span>
            <small>DUY CHÂN DUNG</small>
            <span></span>
          </div>

          <router-link to="/" class="auth-back">
            ← Quay về trang chủ
          </router-link>

          <p class="auth-copyright">
            © {{ new Date().getFullYear() }} Duy Chân Dung
          </p>
        </div>
      </section>

    </div>
  </main>
</template>

<style scoped>
.auth-page {
  min-height: 100vh;
  padding: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  background:
    radial-gradient(circle at 10% 10%, #e1f1e5 0, transparent 35%),
    #f4f7f4;
  color: #263a2d;
  font-family: "Segoe UI", Tahoma, Arial, sans-serif;
}

.auth-card {
  display: grid;
  grid-template-columns: 1fr 1fr;
  width: 100%;
  max-width: 1120px;
  min-height: 650px;
  overflow: hidden;
  background: #fff;
  border-radius: 24px;
  box-shadow: 0 24px 80px rgba(28, 65, 42, 0.12);
}

/* Cột giới thiệu */

.auth-showcase {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 42px;
  overflow: hidden;
  color: #fff;
  background: linear-gradient(145deg, #194c35, #23734c);
}

.auth-brand {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  color: #fff;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 1.4px;
  text-decoration: none;
}

.auth-brand img {
  width: 44px;
  height: 44px;
  object-fit: contain;
  border-radius: 9px;
  background: #fff;
}

.auth-decoration {
  position: absolute;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 50%;
  pointer-events: none;
}

.auth-decoration-one {
  width: 340px;
  height: 340px;
  top: -120px;
  right: -120px;
  box-shadow:
    0 0 0 35px rgba(255, 255, 255, 0.025),
    0 0 0 70px rgba(255, 255, 255, 0.02);
}

.auth-decoration-two {
  width: 210px;
  height: 210px;
  right: -80px;
  bottom: 80px;
  background: rgba(255, 255, 255, 0.035);
}

.auth-showcase-content {
  position: relative;
  z-index: 1;
  margin: auto 0;
  padding: 65px 0;
}

.auth-eyebrow {
  color: #39835a;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 1.7px;
}

.auth-showcase .auth-eyebrow {
  color: #c0e5ca;
}

.auth-showcase h1 {
  margin: 19px 0;
  color: #fff;
  font-size: clamp(34px, 3.5vw, 48px);
  font-weight: 800;
  line-height: 1.2;
  letter-spacing: -1.5px;
}

.auth-showcase h1 span {
  color: #c0e5ca;
}

.auth-showcase-content > p {
  max-width: 390px;
  color: #d7e8dc;
  font-size: 14px;
  line-height: 1.9;
}

.auth-feature {
  display: flex;
  align-items: center;
  gap: 13px;
  margin-top: 25px;
}

.auth-feature-icon {
  display: flex;
  width: 43px;
  height: 43px;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid rgba(255, 255, 255, 0.18);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.08);
  font-size: 23px;
}

.auth-feature strong,
.auth-feature small {
  display: block;
}

.auth-feature strong {
  margin-bottom: 4px;
  color: #fff;
  font-size: 13px;
}

.auth-feature small {
  color: #c8dfd0;
  font-size: 11px;
}

.auth-showcase-footer {
  position: relative;
  z-index: 1;
  margin: 0;
  padding-top: 20px;
  border-top: 1px solid rgba(255, 255, 255, 0.17);
  color: #d5e6db;
  font-size: 11px;
}

/* Form */

.auth-form-panel {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 52px 48px;
  background: #fff;
}

.auth-form-content {
  width: 100%;
  max-width: 360px;
}

.auth-mobile-brand {
  display: none;
  margin-bottom: 28px;
  text-align: center;
}

.auth-mobile-brand img {
  max-width: 170px;
  height: 65px;
  object-fit: contain;
}

.auth-heading {
  margin-bottom: 30px;
}

.auth-heading h2 {
  margin: 10px 0 8px;
  color: #1d3d2b;
  font-size: 34px;
  font-weight: 800;
  letter-spacing: -1px;
}

.auth-heading p {
  margin: 0;
  color: #7b8980;
  font-size: 12px;
  line-height: 1.8;
}

.auth-field {
  margin-bottom: 21px;
}

.auth-field label {
  display: block;
  margin-bottom: 9px;
  color: #35473a;
  font-size: 12px;
  font-weight: 700;
}

.auth-input-wrap {
  display: flex;
  align-items: center;
  width: 100%;
  min-height: 51px;
  padding: 0 14px;
  gap: 11px;
  border: 1px solid #dce6de;
  border-radius: 10px;
  background: #fbfdfb;
  color: #77a087;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.auth-input-wrap:focus-within {
  border-color: #39835a;
  box-shadow: 0 0 0 3px rgba(57, 131, 90, 0.1);
}

.auth-input-wrap input {
  width: 100%;
  min-width: 0;
  height: 49px;
  padding: 0;
  border: 0 !important;
  outline: none !important;
  box-shadow: none !important;
  background: transparent;
  color: #263a2d;
  font-size: 12px;
}

.auth-input-wrap input::placeholder {
  color: #a1ada4;
}

.auth-toggle {
  flex-shrink: 0;
  padding: 6px 0 6px 6px;
  border: 0;
  background: transparent;
  color: #347d53;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
}

.auth-error {
  margin: 0 0 15px;
  padding: 11px 12px;
  border: 1px solid #f2caca;
  border-radius: 9px;
  background: #fff3f3;
  color: #bd3131;
  font-size: 12px;
  line-height: 1.6;
}

.auth-submit {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  min-height: 50px;
  margin-top: 27px;
  padding: 12px 18px;
  border: 0;
  border-radius: 10px;
  background: linear-gradient(120deg, #28794e, #185938);
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 7px 16px rgba(28, 99, 62, 0.17);
  transition: transform 0.2s, box-shadow 0.2s;
}

.auth-submit:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 10px 21px rgba(28, 99, 62, 0.25);
}

.auth-submit:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.auth-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: auth-spin 0.7s linear infinite;
}

@keyframes auth-spin {
  to {
    transform: rotate(360deg);
  }
}

.auth-separator {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 27px 0 22px;
}

.auth-separator span {
  flex: 1;
  height: 1px;
  background: #e7eee8;
}

.auth-separator small {
  color: #9aa79d;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 1px;
}

.auth-back {
  display: block;
  color: #347d53;
  font-size: 12px;
  font-weight: 600;
  text-align: center;
  text-decoration: none;
}

.auth-back:hover {
  color: #16492e;
}

.auth-copyright {
  margin: 30px 0 0;
  color: #a1ada4;
  font-size: 10px;
  text-align: center;
}
/* Font hỗ trợ tiếng Việt cho toàn bộ trang đăng nhập */
.auth-page,
.auth-page * {
  font-family: "Segoe UI", Tahoma, Arial, sans-serif !important;
}

/* Responsive */

@media (max-width: 850px) {
  .auth-page {
    padding: 16px;
  }

  .auth-card {
    grid-template-columns: 1fr;
    max-width: 460px;
    min-height: auto;
    border-radius: 20px;
  }

  .auth-showcase {
    display: none;
  }

  .auth-form-panel {
    padding: 38px 27px 28px;
  }

  .auth-mobile-brand {
    display: block;
  }

  .auth-heading h2 {
    font-size: 30px;
  }
}

@media (max-width: 380px) {
  .auth-page {
    padding: 10px;
  }

  .auth-form-panel {
    padding: 28px 18px 24px;
  }
}
</style>
