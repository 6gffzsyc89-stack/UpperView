// Upper View — site behavior. Config and translations live in i18n.js.
import { CONFIG, LANGS, I18N } from './i18n.js?v=nb4';

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
  ended: false,       // playlist finished; hold the final frame
  awayFromHero: false, // visitor scrolled away since it finished
  isMobile: window.matchMedia('(orientation: portrait)').matches, // portrait window -> portrait clips
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
  if (window.uvBkRefresh) window.uvBkRefresh();
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
    const gst = $('#uv-gst').value;
    if (BK.ci) msg += ' Arrival: ' + BK.ci + '.';
    if (BK.co) msg += ' Departure: ' + BK.co + '.';
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
  state.ended = false;
  state.awayFromHero = false;
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
    if (!v.duration || state.switching || state.ended) return;
    if (state.active === which && v.currentTime > v.duration - 0.9) {
      if (state.vidIdx >= heroList().length - 1) {
        // played through once: hold the last frame; replay when the visitor
        // scrolls away and comes back to the top
        state.ended = true;
        return;
      }
      state.switching = true;
      advance();
    }
  };
}
vA.addEventListener('timeupdate', onTime('a'));
vB.addEventListener('timeupdate', onTime('b'));

new IntersectionObserver((entries) => {
  entries.forEach((en) => {
    if (!en.isIntersecting) {
      state.awayFromHero = true;
    } else if (state.ended && state.awayFromHero) {
      initHero();
    }
  });
}, { threshold: 0.15 }).observe(document.getElementById('top'));

const mq = window.matchMedia('(orientation: portrait)');
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
// Room galleries loop endlessly: the photo set is duplicated once, and when
// the scroll position crosses into the duplicate set it is silently shifted
// back by one set width — identical pixels, so the jump is invisible.
function setupLoop(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const n = el.children.length;
  [...el.children].forEach((s) => el.appendChild(s.cloneNode(true)));
  el._setWidth = () => el.children[n].offsetLeft - el.children[0].offsetLeft;
  el.addEventListener(
    'scroll',
    () => {
      const b = el._setWidth();
      if (b <= 0) return;
      const sign = el.scrollLeft < 0 ? -1 : 1;
      const pos = Math.abs(el.scrollLeft);
      if (pos >= b) el.scrollLeft = sign * (pos - b);
    },
    { passive: true }
  );
  const count = el.parentElement.querySelector('.uv-count');
  if (count) {
    const update = () => {
      const step = el._setWidth() / n;
      if (step > 0) count.textContent = ((Math.round(Math.abs(el.scrollLeft) / step) % n) + 1) + ' / ' + n;
    };
    el.addEventListener('scroll', update, { passive: true });
    update();
  }
}
setupLoop('vgal');
setupLoop('sgal');
setupLoop('ggal');
setupLoop('pgal');

function scrollGal(el, dir) {
  if (!el) return;
  const rtl = document.documentElement.dir === 'rtl' ? -1 : 1;
  const n = el._setWidth ? el.children.length / 2 : el.children.length;
  const step = (el._setWidth ? el._setWidth() : el.scrollWidth) / n;
  if (step <= 0) return;
  if (el._setWidth && dir < 0 && Math.abs(el.scrollLeft) <= 2) {
    // wrap backwards: jump to the identical duplicate set, then glide one back
    el.scrollLeft = rtl * el._setWidth();
  }
  // index-based: every click lands exactly on the next photo's snap position
  const cur = Math.round(Math.abs(el.scrollLeft) / step);
  el.scrollTo({ left: rtl * (cur + dir) * step, behavior: 'smooth' });
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
const OPENING_DATE = '2026-12-20';      // first bookable night (late December opening)
const OPENING_TAG_UNTIL = '2026-12-27'; // notice disappears once the hotel is open
const today = new Date().toISOString().slice(0, 10);
const minStay = today < OPENING_DATE ? OPENING_DATE : today;
document.querySelectorAll('.uv-opening').forEach((el) => {
  el.style.display = today < OPENING_TAG_UNTIL ? 'block' : 'none';
});
const MAR_TAG_UNTIL = '2027-04-01'; // cafe & spa notices disappear after March 2027
document.querySelectorAll('.uv-opening-mar').forEach((el) => {
  el.style.display = today < MAR_TAG_UNTIL ? 'inline-block' : 'none';
});
/* ---------- booking calendar + live availability ---------- */
const BK = {
  type: 'villa',
  ci: null,
  co: null,
  month: minStay.slice(0, 7), // 'YYYY-MM' being shown
  avail: {},                  // per type: {free:{date:n}, nightly, currency, ...}
};
const bkLocale = () =>
  state.lang === 'zh' ? 'zh-Hans' : state.lang === 'tw' ? 'zh-Hant' : state.lang;

async function bkFetch(type) {
  if (BK.avail[type]) return BK.avail[type];
  try {
    const to = new Date(Date.now() + 395 * 86400000).toISOString().slice(0, 10);
    const r = await fetch(
      'booking/api.php?action=availability&type=' + type + '&from=' + minStay + '&to=' + to
    );
    if (r.ok) BK.avail[type] = await r.json();
  } catch (e) {
    /* availability unknown: calendar stays optimistic, the server re-checks on booking */
  }
  return BK.avail[type];
}

function bkFree(d) {
  const a = BK.avail[BK.type];
  if (!a || !a.free) return 1; // unknown -> selectable; server validates
  return a.free[d] ?? 1;
}

function bkRenderCal() {
  const cal = $('#uv-cal');
  if (!cal) return;
  const [y, m] = BK.month.split('-').map(Number);
  const first = new Date(Date.UTC(y, m - 1, 1));
  const label = first.toLocaleDateString(bkLocale(), {
    month: 'long', year: 'numeric', timeZone: 'UTC',
  });
  $('#uv-calmonth').textContent = label;

  // weekday header, Monday first
  const head = $('#uv-calhead');
  head.innerHTML = '';
  for (let i = 0; i < 7; i++) {
    const d = new Date(Date.UTC(2024, 0, i + 1)); // 2024-01-01 is a Monday
    const s = document.createElement('span');
    s.textContent = d.toLocaleDateString(bkLocale(), { weekday: 'narrow', timeZone: 'UTC' });
    head.appendChild(s);
  }

  cal.innerHTML = '';
  const pad = (first.getUTCDay() + 6) % 7;
  for (let i = 0; i < pad; i++) {
    const b = document.createElement('button');
    b.className = 'pad';
    b.disabled = true;
    cal.appendChild(b);
  }
  const days = new Date(Date.UTC(y, m, 0)).getUTCDate();
  for (let day = 1; day <= days; day++) {
    const d = BK.month + '-' + String(day).padStart(2, '0');
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = String(day);
    // a date can be CHECKOUT even when its night is taken, as long as it is not before check-in
    const selectable =
      d >= minStay && (bkFree(d) > 0 || (BK.ci && !BK.co && d > BK.ci));
    b.disabled = !selectable;
    if (BK.ci && d === BK.ci) b.classList.add('edge');
    if (BK.co && d === BK.co) b.classList.add('edge');
    if (BK.ci && BK.co && d > BK.ci && d < BK.co) b.classList.add('inrange');
    b.addEventListener('click', () => bkPick(d));
    cal.appendChild(b);
  }
  // month nav bounds: current month .. +12 months
  $('#uv-calprev').disabled = BK.month <= minStay.slice(0, 7);
  const maxMonth = new Date(Date.now() + 365 * 86400000).toISOString().slice(0, 7);
  $('#uv-calnext').disabled = BK.month >= maxMonth;
  bkRenderSummary();
}

function bkNightsFree(ci, co) {
  for (let d = ci; d < co; d = nextDay(d)) if (bkFree(d) < 1) return false;
  return true;
}
const nextDay = (d) => new Date(new Date(d + 'T00:00:00Z').getTime() + 86400000)
  .toISOString().slice(0, 10);

function bkPick(d) {
  if (!BK.ci || (BK.ci && BK.co)) {
    BK.ci = d; BK.co = null;
  } else if (d > BK.ci && bkNightsFree(BK.ci, d)) {
    BK.co = d;
  } else {
    BK.ci = d; BK.co = null;
  }
  bkRenderCal();
}

function bkRenderSummary() {
  const el = $('#uv-bksum');
  if (!el) return;
  if (!BK.ci) { el.textContent = ''; return; }
  if (!BK.co) { el.textContent = BK.ci + ' → …'; return; }
  const n = Math.round((Date.parse(BK.co) - Date.parse(BK.ci)) / 86400000);
  let s = BK.ci + ' → ' + BK.co + ' · ' + n + ' ' + (t('p_nights') || 'nights');
  const a = BK.avail[BK.type];
  if (a && a.rates_confirmed && a.nightly) {
    s += ' · ' + (n * a.nightly).toLocaleString() + ' ' + a.currency;
  }
  el.textContent = s;
}

$('#uv-calprev').addEventListener('click', () => {
  const [y, m] = BK.month.split('-').map(Number);
  BK.month = new Date(Date.UTC(y, m - 2, 1)).toISOString().slice(0, 7);
  bkRenderCal();
});
$('#uv-calnext').addEventListener('click', () => {
  const [y, m] = BK.month.split('-').map(Number);
  BK.month = new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 7);
  bkRenderCal();
});
window.uvBkRefresh = () => bkRenderCal(); // re-render month/weekday names on language change
bkFetch('villa').then(bkRenderCal);

function bkError(msg, which) {
  const el = $(which === 2 ? '#uv-bkerr2' : '#uv-bkerr');
  if (!el) return;
  el.textContent = msg;
  el.style.display = msg ? 'block' : 'none';
}

/* Nobu-style flow: dates -> available rooms (with photo sliders) -> details */
const ROOM_IMGS = {
  // villa set = exactly the Mini Villas gallery slider (vgal) on the site
  villa: ['photos/upper-view-092.jpg', 'photos/upper-view-072.jpg', 'photos/upper-view-077.jpg', 'photos/upper-view-074.jpg', 'photos/upper-view-097.jpg', 'photos/upper-view-084.jpg', 'photos/upper-view-079.jpg', 'photos/upper-view-095.jpg', 'photos/upper-view-089.jpg', 'photos/upper-view-106.jpg', 'photos/upper-view-133.jpg'],
  // suites: per-room groups from the studio gallery's photo pool (Andy to confirm)
  superior: ['photos/suite-superior-01.jpg', 'photos/suite-superior-02.jpg', 'photos/suite-superior-03.jpg', 'photos/suite-superior-04.jpg', 'photos/suite-superior-05.jpg', 'photos/suite-superior-06.jpg'],
  deluxe: ['photos/suite-deluxe-01.jpg', 'photos/suite-deluxe-02.jpg', 'photos/suite-deluxe-03.jpg', 'photos/suite-deluxe-04.jpg', 'photos/suite-deluxe-05.jpg', 'photos/suite-deluxe-06.jpg'],
  king: ['photos/upper-view-038.jpg', 'photos/upper-view-060.jpg', 'photos/upper-view-056.jpg', 'photos/upper-view-057.jpg', 'photos/upper-view-055.jpg', 'photos/upper-view-047.jpg'],
};
const ROOM_SIZE = { villa: '40 m²', superior: '50 m²', deluxe: '40 m²', king: '60 m²' };

function bkShowStep(step) {
  $('#uv-step1').style.display = step === 1 ? 'contents' : 'none';
  $('#uv-results').style.display = step === 2 ? 'flex' : 'none';
  $('#uv-details').style.display = step === 3 ? 'flex' : 'none';
  $('#uv-panel').scrollTo({ top: 0 });
}

function bkFmt(n) { return Number(n).toLocaleString(); }

function bkRenderResults(q) {
  const box = $('#uv-results');
  box.innerHTML = '';
  const back = document.createElement('button');
  back.type = 'button';
  back.className = 'uv-backlink';
  back.textContent = '‹ ' + (t('p_back') || 'Change dates');
  back.addEventListener('click', () => bkShowStep(1));
  box.appendChild(back);
  const sum = document.createElement('p');
  sum.style.cssText = 'margin:0;font-size:13px;letter-spacing:.06em;color:#E0D9CE';
  sum.textContent = q.from + ' → ' + q.to + ' · ' + q.nights + ' ' + (t('p_nights') || 'nights')
    + ' · ' + $('#uv-gst').value + ' ' + (t('p_gst') || 'Guests');
  box.appendChild(sum);

  q.rooms.forEach((room) => {
    const card = document.createElement('div');
    card.className = 'uv-roomcard' + (room.available ? '' : ' uv-rc-soldout');
    // identical component to every other gallery on the site: same arrows,
    // same uv-count chip, same 3:2 slides with the next-photo peek, looping
    const imgs = ROOM_IMGS[room.type] || [];
    const wrap = document.createElement('div');
    wrap.style.position = 'relative';
    const galId = 'rcgal-' + room.type;
    const gal = document.createElement('div');
    gal.id = galId;
    gal.setAttribute('data-gal', '');
    gal.style.cssText = 'display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none';
    imgs.forEach((src) => {
      const img = document.createElement('img');
      img.src = src; img.alt = room.name; img.loading = 'lazy';
      img.style.cssText = 'flex:0 0 min(440px,84%);aspect-ratio:3/2;object-fit:cover;scroll-snap-align:start';
      gal.appendChild(img);
    });
    wrap.appendChild(gal);
    if (imgs.length > 1) {
      const mkArrow = (dir) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', dir < 0 ? 'Previous' : 'Next');
        b.innerHTML = dir < 0 ? '&lsaquo;' : '&rsaquo;';
        b.style.cssText = 'position:absolute;' + (dir < 0 ? 'left' : 'right')
          + ':10px;top:50%;transform:translateY(-50%);z-index:2;width:44px;height:44px;border:none;'
          + 'background:rgba(17,17,17,.45);color:#F7F0E9;font-size:22px;cursor:pointer;display:flex;'
          + 'align-items:center;justify-content:center;padding:0;transition:background .25s';
        b.addEventListener('click', () => scrollGal(gal, dir));
        b.addEventListener('mouseenter', () => (b.style.background = 'rgba(17,17,17,.7)'));
        b.addEventListener('mouseleave', () => (b.style.background = 'rgba(17,17,17,.45)'));
        return b;
      };
      const count = document.createElement('span');
      count.className = 'uv-count';
      count.setAttribute('aria-hidden', 'true');
      count.textContent = '1 / ' + imgs.length;
      wrap.appendChild(mkArrow(-1));
      wrap.appendChild(mkArrow(1));
      wrap.appendChild(count);
    }
    card.appendChild(wrap);
    card._galId = imgs.length > 1 ? galId : null;
    const body = document.createElement('div');
    body.className = 'uv-rc-body';
    const h = document.createElement('h3');
    h.textContent = t(bkTypeKey(room.type)) || room.name;
    body.appendChild(h);
    const facts = document.createElement('p');
    facts.className = 'uv-rc-facts';
    facts.textContent = (ROOM_SIZE[room.type] || '') + ' · 2 ' + (t('p_gst') || 'Guests');
    body.appendChild(facts);
    const row = document.createElement('div');
    row.className = 'uv-rc-row';
    const price = document.createElement('div');
    price.className = 'uv-rc-price';
    if (room.available && room.nightly) {
      price.innerHTML = '฿' + bkFmt(room.nightly) + ' <small>'
        + (t('p_pernight') || 'per night') + ' · ฿' + bkFmt(room.total)
        + ' ' + (t('p_total') || 'total') + '</small>';
    } else if (room.available) {
      price.innerHTML = '<small>' + (t('p_note') || '') + '</small>';
    } else {
      price.innerHTML = '<small>' + (t('p_full') || 'Unavailable') + '</small>';
    }
    row.appendChild(price);
    if (room.available) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'uv-rc-book';
      btn.textContent = t('p_book') || 'Book now';
      btn.addEventListener('click', async () => {
        BK.type = room.type; BK.sel = room;
        // Nobu flow: go straight to the checkout page; guest details and the
        // card are collected there. Falls back to the inline details step
        // while no payment provider is configured.
        btn.disabled = true; btn.style.opacity = '.6';
        try {
          const r = await fetch('booking/api.php?action=book', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              type: room.type, checkin: BK.ci, checkout: BK.co,
              guests: Number($('#uv-gst').value),
            }),
          });
          const j = await r.json();
          if (j.mode === 'pay' && j.url) {
            if (window.matchMedia('(min-width: 880px)').matches) {
              const w = window.open(j.url, '_blank');
              if (!w) window.location.href = j.url;
            } else {
              window.location.href = j.url;
            }
            return;
          }
        } catch (e) { /* fall through to inline details */ }
        finally { btn.disabled = false; btn.style.opacity = '1'; }
        $('#uv-selsum').textContent = (t(bkTypeKey(room.type)) || room.name) + ' · '
          + q.from + ' → ' + q.to + ' · ' + q.nights + ' ' + (t('p_nights') || 'nights')
          + (room.total ? ' · ฿' + bkFmt(room.total) : '');
        bkShowStep(3);
      });
      row.appendChild(btn);
    }
    body.appendChild(row);
    card.appendChild(body);
    box.appendChild(card);
    if (card._galId) setupLoop(card._galId); // element must be in the DOM first
  });
}

function bkTypeKey(type) {
  return { villa: 'p_villa', superior: 'p_sup', deluxe: 'p_dlx', king: 'p_king' }[type];
}

$('#uv-check').addEventListener('click', async () => {
  bkError('');
  if (!BK.ci || !BK.co) {
    bkError(t('p_err_dates') || 'Please select your dates.');
    return;
  }
  const btn = $('#uv-check');
  btn.disabled = true; btn.style.opacity = '.6';
  try {
    const r = await fetch('booking/api.php?action=quote&from=' + BK.ci + '&to=' + BK.co);
    const q = await r.json();
    if (!q.rooms) throw new Error(q.error || 'quote failed');
    BK.quote = q;
    bkRenderResults(q);
    bkShowStep(2);
  } catch (e) {
    bkError('Network error — please try again.');
  } finally {
    btn.disabled = false; btn.style.opacity = '1';
  }
});

$('#uv-confirm').addEventListener('click', async () => {
  bkError('', 2);
  const name = $('#uv-name').value.trim();
  const email = $('#uv-email').value.trim();
  if (!name || !/.+@.+\..+/.test(email)) {
    bkError(t('p_err') || 'Please fill in your name and email.', 2);
    return;
  }
  const btn = $('#uv-confirm');
  btn.disabled = true; btn.style.opacity = '.6';
  try {
    const r = await fetch('booking/api.php?action=book', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        type: BK.type, checkin: BK.ci, checkout: BK.co,
        guests: Number($('#uv-gst').value), name, email,
        phone: $('#uv-phone').value.trim(),
      }),
    });
    const j = await r.json();
    if (j.mode === 'pay' && j.url) { window.location.href = j.url; return; }
    if (j.mode === 'inquiry') {
      $('#uv-details').style.display = 'none';
      const msg = $('#uv-bkmsg');
      msg.textContent = (t('p_sent') || 'Request received.') + ' · ' + j.code;
      msg.style.display = 'block';
      BK.avail = {}; BK.ci = null; BK.co = null;
      bkRenderCal();
      return;
    }
    bkError(j.error === 'no availability' ? (t('p_full') || 'Unavailable') : (j.error || 'Error'), 2);
  } catch (e) {
    bkError('Network error — please try again.', 2);
  } finally {
    btn.disabled = false; btn.style.opacity = '1';
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
