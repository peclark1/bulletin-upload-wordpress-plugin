/* Parish Promotions visual prototype: no changes to WordPress menu or event data. */
(() => {
  'use strict';
  const root = document.querySelector('.csc-promotions-preview');
  if (!root) return;
  const slides = Array.from(root.querySelectorAll('.csc-promo-slide'));
  if (slides.length < 2) return;

  const dots = Array.from(root.querySelectorAll('.csc-promo-dot'));
  const status = root.querySelector('.csc-promo-status');
  const previous = root.querySelector('.csc-promo-prev');
  const next = root.querySelector('.csc-promo-next');
  const pauseButton = root.querySelector('.csc-promo-pause');
  const stage = root.querySelector('.csc-promo-stage');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const smallScreen = window.matchMedia('(max-width: 760px)');
  const interval = Math.max(5000, Number(root.dataset.interval) || 7000);
  let active = 0;
  let pausedByUser = false;
  let hovering = false;
  let focused = false;
  let inView = false;
  let timer = null;
  let touchStartX = null;

  function stopTimer() {
    if (timer !== null) {
      window.clearInterval(timer);
      timer = null;
    }
  }

  function shouldPlay() {
    return !pausedByUser && !hovering && !focused && inView &&
      !reducedMotion.matches && !document.hidden;
  }

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
    dots.forEach((dot, i) => {
      dot.setAttribute('aria-current', i === active ? 'true' : 'false');
    });
    if (status) {
      status.setAttribute('aria-live', manual ? 'polite' : 'off');
      status.textContent = slides[active].dataset.title + ', ' +
        (active + 1) + ' of ' + slides.length;
    }
    if (pauseButton) {
      pauseButton.disabled = reducedMotion.matches;
      pauseButton.textContent = reducedMotion.matches
        ? 'Autoplay off'
        : (pausedByUser ? 'Play slideshow' : 'Pause slideshow');
      pauseButton.setAttribute('aria-pressed', pausedByUser ? 'true' : 'false');
    }
  }

  function schedule() {
    stopTimer();
    if (!shouldPlay()) return;
    timer = window.setInterval(() => {
      active = (active + 1) % slides.length;
      sync(false);
    }, interval);
  }

  function goTo(index) {
    active = (index + slides.length) % slides.length;
    sync(true);
    schedule();
  }

  previous.addEventListener('click', () => goTo(active - 1));
  next.addEventListener('click', () => goTo(active + 1));
  dots.forEach((dot, i) => dot.addEventListener('click', () => goTo(i)));
  slides.forEach((slide, i) => {
    const select = slide.querySelector('.csc-promo-select');
    if (select) select.addEventListener('click', () => goTo(i));
  });
  pauseButton.addEventListener('click', () => {
    if (reducedMotion.matches) return;
    pausedByUser = !pausedByUser;
    sync(true);
    schedule();
  });

  root.addEventListener('mouseenter', () => { hovering = true; schedule(); });
  root.addEventListener('mouseleave', () => { hovering = false; schedule(); });
  root.addEventListener('focusin', () => { focused = true; schedule(); });
  root.addEventListener('focusout', event => {
    if (!root.contains(event.relatedTarget)) {
      focused = false;
      schedule();
    }
  });
  root.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(active - 1); }
    if (event.key === 'ArrowRight') { event.preventDefault(); goTo(active + 1); }
  });

  stage.addEventListener('touchstart', event => {
    touchStartX = event.touches.length === 1 ? event.touches[0].clientX : null;
  }, { passive: true });
  stage.addEventListener('touchend', event => {
    if (touchStartX === null || !event.changedTouches.length) return;
    const delta = event.changedTouches[0].clientX - touchStartX;
    touchStartX = null;
    if (Math.abs(delta) > 45) goTo(active + (delta < 0 ? 1 : -1));
  }, { passive: true });

  document.addEventListener('visibilitychange', schedule);
  window.addEventListener('resize', () => { sync(false); schedule(); });
  if (typeof reducedMotion.addEventListener === 'function') {
    reducedMotion.addEventListener('change', () => { sync(false); schedule(); });
  }

  sync();
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      inView = entries.some(entry => entry.isIntersecting);
      schedule();
    }, { threshold: 0.15 });
    observer.observe(root);
  } else {
    inView = true;
    schedule();
  }
})();
