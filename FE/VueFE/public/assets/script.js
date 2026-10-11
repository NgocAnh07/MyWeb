// Init AOS
AOS.init({ duration: 900, once: true, easing: 'ease-in-out', offset: 60 });

// Init Lucide Icons
lucide.createIcons();



// ---- NAVBAR SCROLL ----
const navbar = document.getElementById('navbar');
const logoText = document.getElementById('logo-text');
const navLinks = document.querySelectorAll('.nav-link');

window.addEventListener('scroll', () => {
  if (window.scrollY > 60) {
    navbar.classList.add('scrolled');
    navbar.querySelectorAll('.nav-link').forEach(l => {
      l.style.color = document.documentElement.classList.contains('dark') ? '#CBD5E1' : '#0F172A';
    });
  } else {
    navbar.classList.remove('scrolled');
    navbar.querySelectorAll('.nav-link').forEach(l => { l.style.color = ''; });
  }
});

// ---- MOBILE MENU ----
const mobileMenuBtn = document.getElementById('mobile-menu-btn');
const mobileMenu = document.getElementById('mobile-menu');
const menuOpen = document.getElementById('menu-open');
const menuClose = document.getElementById('menu-close');

mobileMenuBtn.addEventListener('click', () => {
  const isOpen = !mobileMenu.classList.contains('hidden');
  mobileMenu.classList.toggle('hidden');
  menuOpen.classList.toggle('hidden', !isOpen);
  menuClose.classList.toggle('hidden', isOpen);
});

mobileMenu.querySelectorAll('a').forEach(link => {
  link.addEventListener('click', () => {
    mobileMenu.classList.add('hidden');
    menuOpen.classList.remove('hidden');
    menuClose.classList.add('hidden');
  });
});


// ---- SCROLL TOP ----
const scrollBtn = document.getElementById('scroll-top');
window.addEventListener('scroll', () => {
  scrollBtn.classList.toggle('visible', window.scrollY > 400);
});
scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

// ---- FAQ ACCORDION ----
function toggleFAQ(el) {
  const answer = el.nextElementSibling;
  const isOpen = answer.classList.contains('open');

  document.querySelectorAll('.faq-answer.open').forEach(a => a.classList.remove('open'));
  document.querySelectorAll('.faq-question.active').forEach(q => q.classList.remove('active'));

  if (!isOpen) {
    answer.classList.add('open');
    el.classList.add('active');
  }
  lucide.createIcons();
}

// ---- ANIMATED COUNTERS ----
function animateCounter(el, target, duration = 2000) {
  let start = 0;
  const step = (timestamp) => {
    if (!start) start = timestamp;
    const progress = Math.min((timestamp - start) / duration, 1);
    const current = Math.floor(progress * target);
    el.textContent = target > 999 ? current.toLocaleString() + '+' : current + (el.dataset.suffix || '');
    if (progress < 1) requestAnimationFrame(step);
    else el.textContent = target > 999 ? target.toLocaleString() + '+' : target + (el.dataset.suffix || '');
  };
  requestAnimationFrame(step);
}

const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const el = entry.target;
      const target = parseInt(el.dataset.count);
      animateCounter(el, target);
      counterObserver.unobserve(el);
    }
  });
}, { threshold: 0.5 });

document.querySelectorAll('[data-count]').forEach(el => counterObserver.observe(el));

// ---- TESTIMONIAL SLIDER ----
const track = document.getElementById('testimonial-track');
if (track) {

  const slides = track.querySelectorAll('.slide');
  const dotsContainer = document.getElementById('slider-dots');
  let currentSlide = 0;
  let slidesPerView = window.innerWidth >= 1024 ? 3 : window.innerWidth >= 768 ? 2 : 1;

  function updateSlider() {
    slidesPerView = window.innerWidth >= 1024 ? 3 : window.innerWidth >= 768 ? 2 : 1;
    const offset = currentSlide * (100 / slidesPerView);
    track.style.transform = `translateX(-${offset}%)`;
    dotsContainer.querySelectorAll('button').forEach((d, i) => {
      d.classList.toggle('bg-red-600', i === currentSlide);
      d.classList.toggle('bg-gray-300', i !== currentSlide);
      d.classList.toggle('w-6', i === currentSlide);
      d.classList.toggle('w-2.5', i !== currentSlide);
    });
  }

  const totalSlides = Math.ceil(slides.length / (window.innerWidth >= 1024 ? 3 : window.innerWidth >= 768 ? 2 : 1));
  for (let i = 0; i < slides.length; i++) {
    const dot = document.createElement('button');
    dot.className = `h-2.5 rounded-full transition-all ${i === 0 ? 'bg-red-600 w-6' : 'bg-gray-300 dark:bg-slate-600 w-2.5'}`;
    dot.addEventListener('click', () => { currentSlide = i; updateSlider(); });
    dotsContainer.appendChild(dot);
  }

  document.getElementById('slide-prev').addEventListener('click', () => {
    currentSlide = Math.max(0, currentSlide - 1);
    updateSlider();
  });
  document.getElementById('slide-next').addEventListener('click', () => {
    currentSlide = Math.min(slides.length - 1, currentSlide + 1);
    updateSlider();
  });

  window.addEventListener('resize', updateSlider);

  // Auto-play slider
  setInterval(() => {
    currentSlide = currentSlide >= slides.length - slidesPerView ? 0 : currentSlide + 1;
    updateSlider();
  }, 5000);
}


// ---- FORM SUBMISSION ----
document.getElementById('appt-form').addEventListener('submit', (e) => {
  e.preventDefault();
  showToast('Appointment request submitted! We\'ll confirm within 2 hours.');
  e.target.reset();
});

function handleNewsletter(e) {
  e.preventDefault();
  showToast('Successfully subscribed to health updates!');
  e.target.reset();
}

function showToast(msg) {
  const toast = document.getElementById('toast');
  document.getElementById('toast-msg').textContent = msg;
  toast.classList.remove('translate-x-full', 'opacity-0');
  toast.classList.add('translate-x-0', 'opacity-100');
  setTimeout(() => {
    toast.classList.add('translate-x-full', 'opacity-0');
    toast.classList.remove('translate-x-0', 'opacity-100');
  }, 3500);
  lucide.createIcons();
}

// Re-init icons after dynamic content
lucide.createIcons();