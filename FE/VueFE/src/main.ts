
import { createApp } from 'vue'
import './assets/main.css'
import '../public/assets/style.css'
import '../public/assets/output.css'
import '../public/assets/aos.css'
import router from './router'
import App from './App.vue'
import '@fortawesome/fontawesome-free/css/all.css'

createApp(App)
  .use(router)
  .mount('#app')