/* Parish Promotions carousel v0.4.0.
 * Progressive sample rendering; cache-safe REST hydration of staff promotions.
 * No event text or links are inserted using innerHTML.
 */
(() => {
  'use strict';
  const root = document.querySelector('.csc-promotions-preview');
  if (!root) return;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const smallScreen = window.matchMedia('(max-width: 760px)');
  const interval = Math.max(5000, Number(root.dataset.interval) || 7000);
  const sectionGroup = root.closest('.csc-promotions-section');
  let dispose = () => {};

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function buildFeed(feed) {
    const demo = feed.mode === 'demo';
    root.dataset.mode = feed.mode;
    root.replaceChildren();
    const intro = el('div', 'csc-promo-intro');
    intro.appendChild(el('p', 'csc-promo-eyebrow', 'Parish life'));
    const heading = el('h2', '', 'Featured at Our Parishes');
    heading.id = 'csc-promo-heading';
    intro.appendChild(heading);
    if (demo) {
      intro.appendChild(el('p', 'csc-promo-demo-note',
        'Design preview using archived parish posters — these are not current event announcements or active sign-ups.'));
    }
    root.appendChild(intro);

    const stage = el('div', 'csc-promo-stage');
    stage.setAttribute('aria-label', 'Promotional posters');
    for (const [index, item] of feed.items.entries()) {
      const slide = el('article', 'csc-promo-slide ' + (index === 0 ? 'is-current' : 'is-off'));
      slide.dataset.index = String(index);
      slide.dataset.title = item.title;
      slide.setAttribute('aria-roledescription', 'slide');
      slide.setAttribute('aria-label', item.title + ', ' + (index + 1) + ' of ' + feed.items.length);
      if (index !== 0) { slide.setAttribute('aria-hidden', 'true'); slide.inert = true; }
      const artwork = el('div', 'csc-promo-artwork');
      const img = el('img', 'csc-promo-poster');
      img.src = item.image.src;
      if (item.image.srcset) img.srcset = item.image.srcset;
      img.sizes = '(max-width: 760px) 92vw, 650px';
      img.alt = item.image.alt || item.title;
      img.loading = index === 0 ? 'eager' : 'lazy';
      artwork.appendChild(img);
      slide.appendChild(artwork);

      const actions = el('div', 'csc-promo-actions');
      for (const [key, label, primary] of [
        ['info_url', item.info_label || 'More Information', false],
        ['signup_url', item.signup_label || 'Sign Up', true]
      ]) {
        if (item[key]) {
          const link = el('a', 'csc-promo-action' + (primary ? ' is-primary' : ''), label);
          link.href = item[key]; // WordPress URL validation is enforced on save.
          actions.appendChild(link);
        } else if (demo) {
          const placeholder = el('span', 'csc-promo-action is-demo' + (primary ? ' is-primary' : ''), label);
          placeholder.setAttribute('aria-disabled', 'true');
          actions.appendChild(placeholder);
        }
      }
      if (!actions.childElementCount) actions.classList.add('is-empty');
      slide.appendChild(actions);
      const select = el('button', 'csc-promo-select');
      select.type = 'button';
      select.tabIndex = -1;
      select.setAttribute('aria-label', 'Show ' + item.title + ' in the center');
      slide.appendChild(select);
      stage.appendChild(slide);
    }
    root.appendChild(stage);

    if (feed.items.length > 1) {
      const controls = el('div', 'csc-promo-controls');
      controls.setAttribute('aria-label', 'Slideshow controls');
      const prev = el('button', 'csc-promo-prev', '\u2190');
      prev.type = 'button'; prev.setAttribute('aria-label', 'Previous promotion');
      controls.appendChild(prev);
      const dots = el('div', 'csc-promo-dots');
      dots.setAttribute('role', 'group');
      dots.setAttribute('aria-label', 'Choose promotion');
      for (const [index, item] of feed.items.entries()) {
        const dot = el('button', 'csc-promo-dot');
        dot.type = 'button'; dot.dataset.index = String(index);
        dot.setAttribute('aria-label', 'Show ' + item.title);
        dot.setAttribute('aria-current', index === 0 ? 'true' : 'false');
        dots.appendChild(dot);
      }
      controls.appendChild(dots);
      const next = el('button', 'csc-promo-next', '\u2192');
      next.type = 'button'; next.setAttribute('aria-label', 'Next promotion');
      controls.appendChild(next);
      const pause = el('button', 'csc-promo-pause', 'Pause slideshow');
      pause.type = 'button'; pause.setAttribute('aria-pressed', 'false');
      controls.appendChild(pause);
      root.appendChild(controls);
      const status = el('p', 'csc-promo-status screen-reader-text', feed.items[0].title + ', 1 of ' + feed.items.length);
      status.setAttribute('aria-live', 'polite');
      status.setAttribute('aria-atomic', 'true');
      root.appendChild(status);
    }
  }

  function attachCarousel() {
    const slides = Array.from(root.querySelectorAll('.csc-promo-slide'));
    if (slides.length < 2) return () => {};
    const dots = Array.from(root.querySelectorAll('.csc-promo-dot'));
    const status = root.querySelector('.csc-promo-status');
    const previous = root.querySelector('.csc-promo-prev');
    const next = root.querySelector('.csc-promo-next');
    const pauseButton = root.querySelector('.csc-promo-pause');
    const stage = root.querySelector('.csc-promo-stage');
    if (!previous || !next || !pauseButton || !stage) return () => {};

    const events = new AbortController();
    const signal = events.signal;
    let active = 0;
    let pausedByUser = false;
    let hovering = false;
    let focused = false;
    let inView = false;
    let timer = null;
    let touchStartX = null;
    let observer = null;

    const stop = () => {
      if (timer !== null) { window.clearInterval(timer); timer = null; }
    };
    const shouldPlay = () => !pausedByUser && !hovering && !focused && inView &&
      !reducedMotion.matches && !document.hidden;
    function sync(manual = false) {
      const prevIndex = (active - 1 + slides.length) % slides.length;
      const nextIndex = (active + 1) % slides.length;
      slides.forEach((slide, i) => {
        const isCurrent = i === active;
        const isPrev = slides.length > 2 && i === prevIndex;
        const isNext = i === nextIndex;
        const visible = isCurrent || isPrev || isNext;
        slide.classList.toggle('is-current', isCurrent);
        slide.classList.toggle('is-prev', isPrev);
        slide.classList.toggle('is-next', isNext);
        slide.classList.toggle('is-off', !visible);
        slide.inert = !visible;
        slide.setAttribute('aria-hidden', visible ? 'false' : 'true');
        const select = slide.querySelector('.csc-promo-select');
        if (select) {
          select.tabIndex = !smallScreen.matches && (isPrev || isNext) ? 0 : -1;
          select.setAttribute('aria-hidden', isCurrent || !visible || smallScreen.matches ? 'true' : 'false');
        }
      });
      dots.forEach((dot, i) => dot.setAttribute('aria-current', i === active ? 'true' : 'false'));
      if (status) {
        status.setAttribute('aria-live', manual ? 'polite' : 'off');
        status.textContent = slides[active].dataset.title + ', ' + (active + 1) + ' of ' + slides.length;
      }
      pauseButton.disabled = reducedMotion.matches;
      pauseButton.textContent = reducedMotion.matches ? 'Autoplay off' :
        (pausedByUser ? 'Play slideshow' : 'Pause slideshow');
      pauseButton.setAttribute('aria-pressed', pausedByUser ? 'true' : 'false');
    }
    function schedule() {
      stop();
      if (!shouldPlay()) return;
      timer = window.setInterval(() => { active = (active + 1) % slides.length; sync(false); }, interval);
    }
    function goTo(index) { active = (index + slides.length) % slides.length; sync(true); schedule(); }
    previous.addEventListener('click', () => goTo(active - 1), {signal});
    next.addEventListener('click', () => goTo(active + 1), {signal});
    dots.forEach((dot, i) => dot.addEventListener('click', () => goTo(i), {signal}));
    slides.forEach((slide, i) => {
      const select = slide.querySelector('.csc-promo-select');
      if (select) select.addEventListener('click', () => goTo(i), {signal});
    });
    pauseButton.addEventListener('click', () => {
      if (reducedMotion.matches) return;
      pausedByUser = !pausedByUser; sync(true); schedule();
    }, {signal});
    root.addEventListener('mouseenter', () => { hovering = true; schedule(); }, {signal});
    root.addEventListener('mouseleave', () => { hovering = false; schedule(); }, {signal});
    root.addEventListener('focusin', () => { focused = true; schedule(); }, {signal});
    root.addEventListener('focusout', event => {
      if (!root.contains(event.relatedTarget)) { focused = false; schedule(); }
    }, {signal});
    root.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(active - 1); }
      if (event.key === 'ArrowRight') { event.preventDefault(); goTo(active + 1); }
    }, {signal});
    stage.addEventListener('touchstart', event => {
      touchStartX = event.touches.length === 1 ? event.touches[0].clientX : null;
    }, {passive:true, signal});
    stage.addEventListener('touchend', event => {
      if (touchStartX === null || !event.changedTouches.length) return;
      const delta = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(delta) > 45) goTo(active + (delta < 0 ? 1 : -1));
    }, {passive:true, signal});
    document.addEventListener('visibilitychange', schedule, {signal});
    window.addEventListener('resize', () => { sync(); schedule(); }, {signal});
    if (typeof reducedMotion.addEventListener === 'function') {
      reducedMotion.addEventListener('change', () => { sync(); schedule(); }, {signal});
    }
    sync();
    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver(entries => {
        inView = entries.some(entry => entry.isIntersecting); schedule();
      }, {threshold: 0.15});
      observer.observe(root);
    } else {
      inView = true; schedule();
    }
    return () => { stop(); events.abort(); if (observer) observer.disconnect(); };
  }

  // Display the existing approved static samples immediately while retrieving
  // authoritative data. The private page cache must not decide visibility.
  dispose = attachCarousel();
  const endpoint = root.dataset.feedUrl;
  if (!endpoint) return;
  const url = new URL(endpoint, window.location.href);
  url.searchParams.set('_', String(Date.now())); // protect date-based expiry against CDN caches
  fetch(url.toString(), {cache: 'no-store', credentials: 'same-origin'})
    .then(response => {
      if (!response.ok) throw new Error('Promotion feed unavailable');
      return response.json();
    })
    .then(feed => {
      if (!feed || !['demo', 'managed'].includes(feed.mode) || !Array.isArray(feed.items)) {
        throw new Error('Invalid promotion feed');
      }
      if (!feed.items.length) {
        dispose();
        root.hidden = true;
        if (sectionGroup) sectionGroup.hidden = true;
        return;
      }
      if (feed.mode === 'demo' && root.dataset.mode === 'demo') return;
      dispose();
      buildFeed(feed);
      dispose = attachCarousel();
    })
    .catch(() => {
      // Fail closed for live mode rather than leaving stale/expired promotions.
      if (root.dataset.mode === 'managed') {
        dispose();
        root.hidden = true;
        if (sectionGroup) sectionGroup.hidden = true;
      }
    });
})();
