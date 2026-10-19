import { createApp } from 'vue'
import axios from 'axios'

import './assets/main.css'
import '../public/assets/style.css'
import '../public/assets/output.css'
import '../public/assets/aos.css'

import router from './router'
import App from './App.vue'
import '@fortawesome/fontawesome-free/css/all.css'

// Tự xử lý khi API báo token không còn hợp lệ.
axios.interceptors.response.use(
  response => response,

  error => {
    const status = error.response?.status
    const token = localStorage.getItem('auth_token')

    // Chỉ xử lý khi có token nhưng API trả về 401.
    if (status === 401 && token) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')

      const currentRoute = router.currentRoute.value

      // Không chuyển hướng lặp khi đang ở trang Login.
      if (currentRoute.name !== 'login') {
        void router.replace({
          name: 'login',
          query: {
            redirect: currentRoute.fullPath,
            expired: '1',
          },
        })
      }
    }

    return Promise.reject(error)
  },
)

createApp(App)
  .use(router)
  .mount('#app')