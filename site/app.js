// Upper View — site behavior. Config and translations live in i18n.js.
import { CONFIG, LANGS, I18N } from './i18n.js';

const HERO_CLIPS_DESKTOP = [
  'video/fd143a0f.mp4', // entrance (original take)
  'video/cd8c6355.mp4', // cafe (original take)
  'video/454e0f8f.mp4', // coconut/loungers (original take)
  'video/a701c2f0.mp4', // villa bedroom (regen from real photo, no door)
  'video/c5dcdd2d.mp4', // rooftop deck (original take)
  'video/976d6cf4.mp4', // aerial (regen from real drone photo, 8 villas)
];
const HERO_CLIPS_MOBILE = [
  'video/37fc725b.mp4', // entrance (original take)
  'video/ecf30afc.mp4', // cafe (original take)
  'video/64a52140.mp4', // coconut/loungers (original take)
  'video/dcac0ba3.mp4', // villa bedroom (regen from real photo, no door)
  'video/85f4fddb.mp4', // rooftop deck (original take)
  'video/9b230ee6.mp4', // aerial (regen from real drone photo, 8 villas)
];

const state = {
  lang: 'en',
  vidIdx: 0,
  active: 'a',
  switching: false,
  isMobile: window.matchMedia('(max-width: 768px)').matches,
};

const $ = (s) => document.querySelector(s);
const body = document.body;

/* ---------- i18n ---------- */
function t(key) {
  const d = I18N[state.lang] || I18N.en;
  return d[key] ?? I18N.en[key];
}
function applyI18n() {
  const meta = LANGS.find((l) => l.code === state.lang) || {};
  document.documentElement.lang =
    state.lang === 'zh' ? 'zh-Hans' : state.lang === 'tw' ? 'zh-Hant' : state.lang;
  document.documentElement.dir = meta.rtl ? 'rtl' : 'ltr';
  document.querySelectorAll('[data-i18n]').forEach((el) => {
    const v = t(el.dataset.i18n);
    if (v != null && el.textContent !== v) el.textContent = v;
  });
  document.querySelectorAll('.uv-dd-label').forEach((el) => {
    el.textContent = (LANGS.find((l) => l.code === state.lang) || LANGS[0]).label;
  });
  updateContactLinks();
  buildLangLists();
}

function buildLangLists() {
  document.querySelectorAll('.uv-dd-list').forEach((list) => {
    list.innerHTML = '';
    LANGS.forEach((l) => {
      const b = document.createElement('button');
      b.textContent = l.label;
      b.style.cssText =
        'display:block;width:100%;text-align:start;padding:12px 16px;border:none;cursor:pointer;font-size:11px;letter-spacing:.14em;text-transform:uppercase;transition:background .2s;' +
        (l.code === state.lang
          ? 'background:#F7F0E9;color:#3D4C36;'
          : 'background:transparent;color:rgba(247,240,233,.85);');
      b.addEventListener('mouseenter', () => {
        if (l.code !== state.lang) b.style.background = 'rgba(247,240,233,.1)';
      });
      b.addEventListener('mouseleave', () => {
        if (l.code !== state.lang) b.style.background = 'transparent';
      });
      b.addEventListener('click', () => {
        state.lang = l.code;
        closeDropdowns();
        applyI18n();
      });
      list.appendChild(b);
    });
  });
}

/* ---------- contact links ---------- */
function waHref(withForm) {
  const num = String(CONFIG.whatsapp).replace(/\D/g, '');
  let msg = t('wa_msg') || "Hello Upper View! I'd like to inquire about a booking.";
  if (withForm) {
    const arr = $('#uv-arr').value, dep = $('#uv-dep').value, gst = $('#uv-gst').value;
    if (arr) msg += ' Arrival: ' + arr + '.';
    if (dep) msg += ' Departure: ' + dep + '.';
    if (gst) msg += ' Guests: ' + gst + '.';
  }
  return 'https://wa.me/' + num + '?text=' + encodeURIComponent(msg);
}
function updateContactLinks() {
  const mail =
    'mailto:' + CONFIG.email + '?subject=' + encodeURIComponent('Booking inquiry — Upper View');
  document.querySelectorAll('.uv-mail').forEach((a) => (a.href = mail));
  document.querySelectorAll('.uv-wa').forEach((a) => (a.href = waHref(false)));
  document.querySelectorAll('.uv-map').forEach((a) => (a.href = CONFIG.mapsUrl));
  const fm = $('#uv-footmail');
  if (fm) fm.textContent = CONFIG.email;
  $('#uv-addr-en').textContent = CONFIG.addressEn;
  $('#uv-addr-th').textContent = CONFIG.addressTh;
  const note = $('#uv-addr-note');
  note.textContent = CONFIG.addressNote || '';
  note.style.display = CONFIG.addressNote ? 'block' : 'none';
}

/* ---------- hero video playlist (crossfade) ---------- */
const vA = $('#uv-vidA'), vB = $('#uv-vidB');
function heroList() {
  return state.isMobile && HERO_CLIPS_MOBILE.length ? HERO_CLIPS_MOBILE : HERO_CLIPS_DESKTOP;
}
function initHero() {
  const list = heroList();
  state.vidIdx = 0;
  state.active = 'a';
  state.switching = false;
  vA.src = list[0];
  vB.src = list[1] || '';
  vA.style.opacity = '1';
  vB.style.opacity = '0';
  vA.loop = list.length < 2;
  vA.muted = vB.muted = true;
  const p = vA.play();
  if (p) p.catch(() => {});
}
function advance() {
  const list = heroList();
  if (list.length < 2) return;
  const next = (state.vidIdx + 1) % list.length;
  const toB = state.active === 'a';
  const vid = toB ? vB : vA;
  try {
    vid.currentTime = 0;
    const p = vid.play();
    if (p) p.catch(() => {});
  } catch (e) {}
  vA.style.opacity = toB ? '0' : '1';
  vB.style.opacity = toB ? '1' : '0';
  state.active = toB ? 'b' : 'a';
  state.vidIdx = next;
  setTimeout(() => {
    const nn = (next + 1) % list.length;
    if (toB) vA.src = list[nn];
    else vB.src = list[nn];
    state.switching = false;
  }, 1100);
}
function onTime(which) {
  return (e) => {
    const v = e.target;
    if (!v.duration || state.switching) return;
    if (state.active === which && v.currentTime > v.duration - 0.9) {
      state.switching = true;
      advance();
    }
  };
}
vA.addEventListener('timeupdate', onTime('a'));
vB.addEventListener('timeupdate', onTime('b'));

const mq = window.matchMedia('(max-width: 768px)');
mq.addEventListener('change', (e) => {
  state.isMobile = e.matches;
  initHero();
});

/* ---------- header scroll state ---------- */
window.addEventListener(
  'scroll',
  () => body.classList.toggle('scrolled', window.scrollY > 40),
  { passive: true }
);

/* ---------- menu / reserve / help ---------- */
$('#uv-menubtn').addEventListener('click', () => {
  body.classList.toggle('menu-open');
  body.classList.remove('help-open');
});
document.querySelectorAll('.uv-open-reserve').forEach((b) =>
  b.addEventListener('click', () => {
    body.classList.add('reserve-open');
    body.classList.remove('menu-open', 'help-open');
  })
);
$('#uv-close').addEventListener('click', () => body.classList.remove('reserve-open'));
$('#uv-overlay').addEventListener('click', () => body.classList.remove('reserve-open'));
$('#uv-helptab').addEventListener('click', () => body.classList.toggle('help-open'));
$('#uv-helpclose').addEventListener('click', () => body.classList.remove('help-open'));
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') body.classList.remove('help-open', 'reserve-open', 'menu-open');
});

/* ---------- smooth nav ---------- */
document.querySelectorAll('.uv-nav').forEach((a) =>
  a.addEventListener('click', (e) => {
    e.preventDefault();
    body.classList.remove('menu-open');
    const el = document.querySelector(a.getAttribute('href'));
    setTimeout(() => {
      if (el)
        window.scrollTo({
          top: el.getBoundingClientRect().top + window.scrollY - 72,
          behavior: 'smooth',
        });
    }, 80);
  })
);
$('#uv-logolink').addEventListener('click', (e) => {
  e.preventDefault();
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

/* ---------- language dropdowns ---------- */
function closeDropdowns() {
  document.querySelectorAll('.uv-dd').forEach((d) => d.classList.remove('open'));
}
document.querySelectorAll('.uv-dd .uv-dd-btn').forEach((btn) =>
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    const dd = btn.closest('.uv-dd');
    const wasOpen = dd.classList.contains('open');
    closeDropdowns();
    if (!wasOpen) dd.classList.add('open');
  })
);
document.addEventListener('click', (e) => {
  if (!e.target.closest('[data-langdd]')) closeDropdowns();
});

/* ---------- galleries ---------- */
function scrollGal(el, dir) {
  if (!el) return;
  const rtl = document.documentElement.dir === 'rtl' ? -1 : 1;
  // room galleries page one full photo; the property gallery keeps peek-ahead on desktop
  const full = el.id !== 'ggal' || window.innerWidth <= 768;
  el.scrollBy({ left: dir * rtl * Math.round(el.clientWidth * (full ? 1 : 0.75)), behavior: 'smooth' });
}
document.querySelectorAll('[data-galprev]').forEach((b) =>
  b.addEventListener('click', () => scrollGal(document.getElementById(b.dataset.galprev), -1))
);
document.querySelectorAll('[data-galnext]').forEach((b) =>
  b.addEventListener('click', () => scrollGal(document.getElementById(b.dataset.galnext), 1))
);
document
  .querySelectorAll('[data-galprev], [data-galnext]')
  .forEach((b) => {
    b.addEventListener('mouseenter', () => (b.style.background = 'rgba(17,17,17,.7)'));
    b.addEventListener('mouseleave', () => (b.style.background = 'rgba(17,17,17,.45)'));
  });

/* ---------- booking ---------- */
const today = new Date().toISOString().slice(0, 10);
$('#uv-arr').min = today;
$('#uv-dep').min = today;
$('#uv-arr').addEventListener('change', () => {
  $('#uv-dep').min = $('#uv-arr').value || today;
});
$('#uv-check').addEventListener('click', () => {
  const url = CONFIG.bookingUrl;
  if (url) {
    const q = new URLSearchParams({
      checkin: $('#uv-arr').value,
      checkout: $('#uv-dep').value,
      guests: $('#uv-gst').value,
      promo: $('#uv-promo').value,
    });
    window.open(url + (url.includes('?') ? '&' : '?') + q.toString(), '_blank');
  } else {
    window.open(waHref(true), '_blank');
  }
});

/* ---------- scroll reveal ---------- */
const io = new IntersectionObserver(
  (entries) => {
    entries.forEach((en) => {
      if (en.isIntersecting) {
        en.target.style.opacity = '1';
        en.target.style.transform = 'none';
        io.unobserve(en.target);
      }
    });
  },
  { threshold: 0.12, rootMargin: '0px 0px -50px 0px' }
);
document.querySelectorAll('[data-reveal]').forEach((el) => {
  el.style.opacity = '0';
  el.style.transform = el.dataset.reveal === 'img' ? 'scale(1.06)' : 'translateY(28px)';
  el.style.transition =
    'opacity 1.05s cubic-bezier(.22,1,.36,1), transform 1.15s cubic-bezier(.22,1,.36,1)';
  el.style.transitionDelay = (el.dataset.delay || 0) + 'ms';
  io.observe(el);
});

/* ---------- boot ---------- */
initHero();
applyI18n();
