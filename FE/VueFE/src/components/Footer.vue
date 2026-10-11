
<script setup lang="ts">
function handleNewsletter() {
  alert('Chức năng đăng ký email sẽ được kết nối sau.')
}
</script>
<template>
  <div>
      <!-- ===================== FOOTER ===================== -->

  <!-- Footer Emergency Strip -->


  <!-- Footer  -->
  <footer class="text-white overflow-hidden relative">
    <!-- Decorative Background Elements -->
    <div class="absolute top-0 left-0 w-[500px] h-[500px] rounded-full opacity-[0.04] pointer-events-none"
      style="background: radial-gradient(circle, #DC2626, transparent); transform: translate(-40%, -40%);"></div>
    <div class="absolute bottom-0 right-0 w-[400px] h-[400px] rounded-full opacity-[0.03] pointer-events-none"
      style="background: radial-gradient(circle, #0EA5E9, transparent); transform: translate(30%, 30%);"></div>

    <!-- ECG Decorative Line -->


    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-10">

      <!-- TOP ROW: Brand + Newsletter -->
      <div class="grid lg:grid-cols-2 gap-10 items-start mb-14 pb-14 border-b border-white/8">
        <!-- Brand Block -->
        <div>
          <div class="flex items-center gap-3 mb-5">
            <a href="#home" class="flex items-center shrink-0">
            <img
              src="/assets/logo.jpeg"
              alt="Logo Duy Chân Dung"
              style="height: 36px; width: auto; max-width: 160px; object-fit: contain; flex-shrink: 0;"
            />
          </a>
            <div>
              <div class="font-heading font-extrabold text-2xl text-white tracking-tight">Duy chân dung</div>
              <div class="text-xs text-red-400 font-semibold uppercase tracking-widest">Vẽ tranh theo yêu cầu</div>
            </div>
          </div>

          <!-- Social Icons -->
          <div class="flex items-center gap-3">
            <span class="text-slate-500 text-xs mr-1">Follow Us:</span>
            <!-- facebook -->
            <a href="https://www.facebook.com/art.duy.54"
              class="w-9 h-9 bg-[#1877F2] rounded-full flex items-center justify-center hover:opacity-90 hover:scale-110 transition-all"
              title="Facebook">
              <svg width="15" height="15" fill="white" viewBox="0 0 24 24">
                <path
                  d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
              </svg>
            </a>

            <!-- zalo -->
            <a href="#"
              class="w-9 h-9 rounded-full flex items-center justify-center hover:opacity-90 hover:scale-110 transition-all"
              title="Zalo">
              <img
  src="/assets/zalo.webp"
  alt="Zalo"
  class="w-7 h-7 object-contain"
/>
            </a>
            
            <!-- TIKTOK -->
             <a href="https://www.tiktok.com/@duy.chn.dung"
              class="w-9 h-9 rounded-full flex items-center justify-center hover:opacity-90 hover:scale-110 transition-all"
              title="Zalo">
              <img
  src="/assets/tiktok.avif"
  alt="tiktok"
  class="w-7 h-7 object-contain"
/>
            </a>
          </div>
        </div>

      
        
      </div>

      <!-- MAIN LINKS GRID: 5 columns -->
      <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-8 mb-14 pb-14 border-b border-white/8">

 
        <!-- Working Hours -->
        <div>
          <h4 class="font-heading font-semibold text-sm text-white mb-5 flex items-center gap-2">
            <span class="w-1 h-4 bg-yellow-500 rounded-full inline-block"></span>
            Giờ làm việc
          </h4>
          <ul class="space-y-3 text-sm">
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Thứ 2</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Thứ 3</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Thứ 4</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Thứ 5</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Thứ 6</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Thứ 7</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
            <li class="flex justify-between gap-4">
              <span class="text-slate-400">Chủ Nhật</span>
              <span class="text-slate-300 font-medium text-xs">8AM – 8PM</span>
            </li>
          </ul>
          <div class="mt-4 bg-green-600/15 border border-green-500/25 rounded-lg px-3 py-2 flex items-center gap-2">
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse flex-shrink-0"></span>
            <span class="text-green-400 text-xs font-semibold">Luôn hoạt động</span>
          </div>
        </div>

        <!-- Contact Info -->
        <div>
          <h4 class="font-heading font-semibold text-sm text-white mb-5 flex items-center gap-2">
            <span class="w-1 h-4 bg-red-500 rounded-full inline-block"></span>
            Contact Info
          </h4>
          <ul class="space-y-4">
            <li class="flex items-start gap-3">
              <div class="w-8 h-8 bg-red-600/20 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5">
                <i data-lucide="map-pin" class="w-4 h-4 text-red-400"></i>
              </div>
              <div>
                <div class="text-slate-300 text-xs font-semibold mb-0.5">Địa chỉ</div>
                <div class="text-slate-500 text-xs leading-relaxed">Ng. 55 Đ. Trần Phú</div>
              </div>
            </li>
            <li class="flex items-start gap-3">
              <div class="w-8 h-8 bg-red-600/20 rounded-lg flex items-center justify-center flex-shrink-0">
                <i data-lucide="phone" class="w-4 h-4 text-red-400"></i>
              </div>
              <div>
                <div class="text-slate-300 text-xs font-semibold mb-0.5">SDT</div>
                <a href="#" class="text-slate-500 text-xs hover:text-red-400 transition-colors"> 034 969 1818</a><br />
              </div>
            </li>
            <li class="flex items-start gap-3">
              <div class="w-8 h-8 bg-red-600/20 rounded-lg flex items-center justify-center flex-shrink-0">
                <i data-lucide="mail" class="w-4 h-4 text-red-400"></i>
              </div>
              <div>
                <div class="text-slate-300 text-xs font-semibold mb-0.5">Email</div>
                <a href="mailto:tranhveduyart190gmail"
                  class="text-slate-500 text-xs hover:text-red-400 transition-colors">tranhveduyart190gmail</a><br />
              </div>
            </li>
          </ul>
        </div>
      </div>

      <!-- BOTTOM BAR -->
      <div class="flex flex-col lg:flex-row items-center justify-between gap-5">
        <!-- Copyright -->
        <div class="text-center lg:text-left">
          <p class="text-slate-400 text-sm">© 2026 <span class="text-slate-300 font-semibold">Duy chân dung</span>. All
            rights reserved by <span class="text-slate-300 font-semibold">NgocAnh07</span>. Designed for oil painting &bull; Distributed by <span class="text-slate-300 font-semibold">NgocAnh07</span>.</p>
          <p class="text-slate-400 text-xs mt-1">This website is for informational purposes only and does not constitute
</p>
        </div>

        <!-- Legal Links -->
        <div class="flex flex-wrap justify-center gap-x-6 gap-y-2">
          <a href="#" class="text-slate-400 text-xs hover:text-red-400 transition-colors">Privacy Policy</a>
          <a href="#" class="text-slate-400 text-xs hover:text-red-400 transition-colors">Terms of Service</a>
          <a href="#" class="text-slate-400 text-xs hover:text-red-400 transition-colors">FAQs</a>
          <a href="#" class="text-slate-400 text-xs hover:text-red-400 transition-colors">Accessibility</a>
          <a href="#" class="text-slate-400 text-xs hover:text-red-400 transition-colors">Cookie Policy</a>
          <a href="#" class="text-slate-400 text-xs hover:text-red-400 transition-colors">Sitemap</a>
        </div>


      </div>
    </div>
  </footer>

  <!-- ===================== MOBILE STICKY CTA ===================== -->
  <div class="mobile-cta dark:bg-slate-900 dark:border-t dark:border-slate-700">
    <a href="#" class="btn-primary w-full justify-center py-3.5 text-base">
      <i data-lucide="calendar-plus" class="w-5 h-5"></i>
       034 969 1818
    </a>
  </div>

  <!-- ===================== SCROLL TO TOP ===================== -->
  <button id="scroll-top" aria-label="Scroll to top">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"
      stroke-linejoin="round">
      <path
        d="M12 21.593c-5.63-5.539-11-10.297-11-14.402 0-3.791 3.068-5.191 5.281-5.191 1.312 0 4.151.501 5.719 4.457 1.59-3.968 4.464-4.447 5.726-4.447 2.54 0 5.274 1.621 5.274 5.181 0 4.069-5.136 8.625-11 14.402z"
        fill="white" stroke="none" />
    </svg>
  </button>

  <!-- Success Toast -->
  
  <!-- ===================== SCRIPTS ===================== -->
  </div>
</template>