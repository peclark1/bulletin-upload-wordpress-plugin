/* Church Site Controls: progressive desktop mega menu for Kadence.
 * Reuses existing links and hierarchy; never writes menus to WordPress.
 * Mobile continues to use Kadence's native hamburger/off-canvas navigation.
 */
(() => {
  'use strict';
  const nav = document.querySelector('#site-navigation .primary-menu-container');
  if (!nav) return;
  const list = nav.querySelector(':scope > ul.menu');
  if (!list) return;
  const topItems = Array.from(list.children).filter(li => li.matches('li.menu-item-has-children'));
  if (!topItems.length) return;

  const panel = document.createElement('section');
  panel.className = 'csc-mega-panel';
  panel.id = 'csc-mega-panel';
  panel.setAttribute('aria-label', 'Expanded parish navigation');
  panel.hidden = true;
  const content = document.createElement('div');
  content.className = 'csc-mega-content';
  panel.appendChild(content);
  document.body.appendChild(panel);

  const desktop = () => window.matchMedia('(min-width: 1025px)').matches && !!nav.getClientRects().length;
  let active = null;
  let closeTimer = null;
  const cancelClose = () => { window.clearTimeout(closeTimer); closeTimer = null; };
  const linkFrom = (anchor, className) => {
    const el = document.createElement('a');
    el.className = className;
    el.textContent = anchor.textContent.trim();
    el.href = anchor.href;
    if (anchor.target) el.target = anchor.target;
    if (anchor.rel) el.rel = anchor.rel;
    return el;
  };
  const direct = (li) => Array.from(li.children).find(el => el.matches('a')) || null;
  const submenu = (li) => Array.from(li.children).find(el => el.matches('ul.sub-menu')) || null;
  const children = (li) => submenu(li) ? Array.from(submenu(li).children).filter(el => el.matches('li')) : [];

  function makeColumn(li, asHeading) {
    const column = document.createElement('div');
    column.className = 'csc-mega-column';
    const a = direct(li);
    if (a) {
      const name = document.createElement(asHeading ? 'h3' : 'div');
      name.className = asHeading ? 'csc-mega-heading' : 'csc-mega-plain';
      name.appendChild(linkFrom(a, asHeading ? 'csc-mega-heading-link' : 'csc-mega-link'));
      column.appendChild(name);
    }
    const ul = document.createElement('ul');
    ul.className = 'csc-mega-links';
    function collect(items, level) {
      for (const child of items) {
        const anchor = direct(child);
        if (anchor) {
          const li = document.createElement('li');
          if (level > 0) li.className = 'csc-mega-indented';
          li.appendChild(linkFrom(anchor, 'csc-mega-link'));
          ul.appendChild(li);
        }
        if (level < 4) collect(children(child), level + 1);
      }
    }
    collect(children(li), 0);
    if (ul.children.length) column.appendChild(ul);
    return column;
  }
  function render(item) {
    content.replaceChildren();
    const subs = children(item);
    const hasGroups = subs.some(li => children(li).length > 0);
    if (hasGroups) {
      // Grouped submenu entries become columns, without losing direct links.
      for (const child of subs) content.appendChild(makeColumn(child, children(child).length > 0));
    } else {
      const column = document.createElement('div');
      column.className = 'csc-mega-column';
      const ul = document.createElement('ul');
      ul.className = 'csc-mega-links';
      for (const child of subs) {
        const a = direct(child);
        if (!a) continue;
        const li = document.createElement('li');
        li.appendChild(linkFrom(a, 'csc-mega-link'));
        ul.appendChild(li);
      }
      column.appendChild(ul);
      content.appendChild(column);
    }
  }
  function position() {
    if (!active) return;
    const header = document.querySelector('#masthead') || document.querySelector('.site-header') || nav;
    const rect = header.getBoundingClientRect();
    // Position at bottom of visible header (sticky and non-sticky).
    const bottom = Math.min(window.innerHeight - 60, Math.max(0, rect.bottom));
    panel.style.top = bottom + 'px';
    panel.style.setProperty('--csc-mega-room', Math.max(170, window.innerHeight - bottom - 12) + 'px');
  }
  function close() {
    cancelClose();
    active = null;
    panel.hidden = true;
    panel.classList.remove('is-open');
    for (const li of topItems) {
      li.classList.remove('csc-mega-active');
      const a = direct(li);
      if (a) a.setAttribute('aria-expanded', 'false');
    }
  }
  function open(item) {
    cancelClose();
    if (!desktop()) { close(); return; }
    if (active !== item) {
      active = item;
      render(item);
    }
    for (const li of topItems) {
      li.classList.toggle('csc-mega-active', li === item);
      const a = direct(li);
      if (a) a.setAttribute('aria-expanded', li === item ? 'true' : 'false');
    }
    position();
    panel.hidden = false;
    panel.classList.add('is-open');
  }
  function queueClose() {
    cancelClose();
    closeTimer = window.setTimeout(close, 260);
  }

  for (const item of topItems) {
    const a = direct(item);
    if (!a) continue;
    a.setAttribute('aria-controls', panel.id);
    a.setAttribute('aria-expanded', 'false');
    item.addEventListener('pointerenter', event => {
      if (event.pointerType === 'mouse') open(item);
    });
    item.addEventListener('pointerleave', queueClose);
    a.addEventListener('click', event => {
      if (!desktop()) return;
      // Parent link toggles the full submenu; its child links retain navigation.
      event.preventDefault();
      active === item ? close() : open(item);
    });
    a.addEventListener('keydown', event => {
      if (!desktop()) return;
      if (event.key === 'ArrowDown' || event.key === ' ') {
        event.preventDefault();
        open(item);
        const first = panel.querySelector('a');
        if (first) first.focus();
      }
    });
  }

  panel.addEventListener('pointerenter', cancelClose);
  panel.addEventListener('pointerleave', queueClose);
  panel.addEventListener('focusin', cancelClose);
  panel.addEventListener('focusout', event => {
    if (!panel.contains(event.relatedTarget) && !nav.contains(event.relatedTarget)) queueClose();
  });
  nav.addEventListener('focusin', event => {
    const top = event.target.closest('li.menu-item-has-children');
    if (top && topItems.includes(top) && desktop()) open(top);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && active) {
      const a = direct(active);
      close();
      if (a) a.focus();
    }
  });
  document.addEventListener('pointerdown', event => {
    if (active && !panel.contains(event.target) && !nav.contains(event.target)) close();
  });
  window.addEventListener('scroll', position, { passive: true });
  window.addEventListener('resize', () => { if (!desktop()) close(); else position(); });
  nav.classList.add('csc-mega-ready');
  document.body.classList.add('csc-mega-enhanced');
})();
