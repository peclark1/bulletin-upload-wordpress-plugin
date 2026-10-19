/* Staff graphic chooser: uses the existing WordPress Media Library. */
(() => {
  'use strict';
  if (!window.wp || !wp.media) return;
  const button = document.getElementById('csc-image-choose');
  const clear = document.getElementById('csc-image-clear');
  const field = document.getElementById('csc-image-id');
  const preview = document.getElementById('csc-image-preview');
  if (!button || !clear || !field || !preview) return;
  let frame;
  const cardImage = document.getElementById('csc-card-preview-image');
  const infoLabel = document.getElementById('csc-info-label');
  const infoUrl = document.getElementById('csc-info-url');
  const signupLabel = document.getElementById('csc-signup-label');
  const signupUrl = document.getElementById('csc-signup-url');
  const infoButton = document.getElementById('csc-card-info');
  const signupButton = document.getElementById('csc-card-signup');

  function updateCard() {
    if (cardImage) {
      const img = preview.querySelector('img');
      if (img) cardImage.replaceChildren(img.cloneNode(true));
      else {
        const placeholder = document.createElement('span');
        placeholder.textContent = 'Choose a graphic to preview the promotion.';
        cardImage.replaceChildren(placeholder);
      }
    }
    if (infoButton && infoLabel && infoUrl) {
      infoButton.textContent = infoLabel.value.trim() || 'More Information';
      infoButton.hidden = !infoUrl.value.trim();
    }
    if (signupButton && signupLabel && signupUrl) {
      signupButton.textContent = signupLabel.value.trim() || 'Sign Up';
      signupButton.hidden = !signupUrl.value.trim();
    }
  }
  [infoLabel, infoUrl, signupLabel, signupUrl].filter(Boolean).forEach(input => {
    input.addEventListener('input', updateCard);
    input.addEventListener('change', updateCard);
  });

  button.addEventListener('click', event => {
    event.preventDefault();
    if (!frame) {
      frame = wp.media({
        title: 'Choose a parish promotion graphic',
        button: { text: 'Use this graphic' },
        library: { type: 'image' },
        multiple: false
      });
      frame.on('select', () => {
        const item = frame.state().get('selection').first();
        if (!item) return;
        const image = item.toJSON();
        field.value = String(image.id);
        const img = document.createElement('img');
        img.src = image.sizes?.medium?.url || image.url;
        img.alt = image.alt || image.title || 'Selected promotion graphic';
        preview.replaceChildren(img);
        updateCard();
        const alt = document.getElementById('csc-alt');
        if (alt && !alt.value.trim() && image.alt) alt.value = image.alt;
      });
    }
    frame.open();
  });

  clear.addEventListener('click', event => {
    event.preventDefault();
    field.value = '';
    const label = document.createElement('span');
    label.textContent = 'No graphic selected';
    preview.replaceChildren(label);
    updateCard();
  });

  document.querySelectorAll('.csc-page-choose').forEach(select => {
    select.addEventListener('change', () => {
      const input = document.getElementById(select.dataset.target);
      if (!input || !select.value) return;
      input.value = select.value;
      updateCard();
    });
  });

  const start = document.getElementById('csc-start');
  const end = document.getElementById('csc-end');
  if (start && end) {
    start.addEventListener('change', () => { end.min = start.value; });
    end.min = start.value;
  }
  updateCard();
})();
