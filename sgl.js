(function () {
  'use strict';

  var requestInFlight = false;
  var activeButton = null;
  var observer = null;
  var ready = false;

  function closestButton(node) {
    if (!node) return null;
    if (node.nodeType === 1 && node.matches('.sgl-btn')) return node;
    return node.parentElement ? node.parentElement.closest('.sgl-btn') : null;
  }

  function currentButton() {
    var focusedButton = closestButton(document.activeElement);
    return focusedButton || activeButton || document.querySelector('.sgl-btn');
  }

  // A WooCommerce-style notice under the button, read out by screen readers. No alert().
  function showError(message, button) {
    var target = button || currentButton();
    if (!target) return;
    var wrap = target.closest('.sgl-wrap') || target.parentNode;
    var box = wrap.querySelector('.sgl-error');
    if (!box) {
      box = document.createElement('div');
      box.className = 'sgl-error woocommerce-error';
      box.setAttribute('role', 'alert');
      box.style.marginTop = '8px';
      wrap.appendChild(box);
    }
    box.textContent = message || window.SGL.genericError;
  }

  function clearError(button) {
    var wrap = button && (button.closest('.sgl-wrap') || button.parentNode);
    var box = wrap && wrap.querySelector('.sgl-error');
    if (box) box.remove();
  }

  // Initialized once per page: Google's button keeps its first configuration.
  function initializeGoogle() {
    window.google.accounts.id.initialize({
      client_id: window.SGL.clientId,
      auto_select: false,
      callback: handleCredential
    });
    return Promise.resolve();
  }

  function handleCredential(response) {
    if (requestInFlight || !response || !response.credential) return;

    requestInFlight = true;
    var button = currentButton();
    var redirect = button ? button.dataset.redirect || '' : '';
    clearError(button);

    // A normal form post, like WooCommerce's own login form: the login finishes as a page load, so a
    // two-factor step (WP 2FA) shows its code page in the browser instead of being lost in a background call.
    var form = document.createElement('form');
    form.method = 'post';
    form.action = window.location.href.split('#')[0];
    form.style.display = 'none';
    [[window.SGL.field || 'sgl_credential', response.credential], ['redirect_to', redirect]].forEach(function (pair) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = pair[0];
      input.value = pair[1];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function rememberButton(event) {
    activeButton = event.currentTarget;
  }

  // As wide as its container, within what Google allows (200 to 400 px), so the button lines up
  // with the form fields around it instead of sitting at Google's narrower default.
  function buttonWidth(element) {
    // The content box: padding and borders are not room for the button.
    var style = window.getComputedStyle(element);
    var width = Math.floor(element.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0));

    return width > 0 ? Math.max(200, Math.min(400, width)) : 0;
  }

  function draw(element) {
    var width = buttonWidth(element);

    element.dataset.sglWidth = String(width);
    // Keep the current height while Google draws the new button, so nothing below it moves.
    if (element.offsetHeight) {
      element.style.minHeight = element.offsetHeight + 'px';
    }
    element.innerHTML = '';

    window.google.accounts.id.renderButton(element, {
      type: 'standard',
      width: width ? String(width) : undefined,
      theme: element.dataset.theme || 'outline',
      size: element.dataset.size || 'large',
      text: element.dataset.text || 'continue_with',
      shape: 'rectangular',
      locale: window.SGL.locale || ''
    });
  }

  // The layout can settle after the first draw (fonts, a checkout step opening, a phone turning):
  // draw again when the container's width really changed.
  var resizer = window.ResizeObserver ? new ResizeObserver(function (entries) {
    entries.forEach(function (entry) {
      var element = entry.target;
      var width;

      // Replaced by the page (a checkout step re-rendered): stop watching the old node.
      if (!element.isConnected) {
        resizer.unobserve(element);
        return;
      }

      width = buttonWidth(element);

      // 0 while the old button is cleared out of a shrink-to-fit container: not a real size.
      if (!width && Number(element.dataset.sglWidth || 0) > 0) {
        return;
      }

      if (Math.abs(width - Number(element.dataset.sglWidth || 0)) > 1) {
        draw(element);
      }
    });
  }) : null;

  function renderButtons() {
    document.querySelectorAll('.sgl-btn:not([data-sgl-rendered])').forEach(function (element) {
      element.dataset.sglRendered = 'true';
      element.addEventListener('pointerenter', rememberButton);
      element.addEventListener('pointerdown', rememberButton, true);
      element.addEventListener('focusin', rememberButton);

      draw(element);

      if (resizer) {
        resizer.observe(element);
      }
    });
  }

  function start() {
    if (!document.querySelector('.sgl-btn') || !window.SGL || !window.SGL.clientId) return;

    if (!window.google || !window.google.accounts || !window.google.accounts.id) {
      // Blocked by the browser or an extension: say so instead of showing nothing.
      document.querySelectorAll('.sgl-btn').forEach(function (b) { showError(window.SGL.blocked, b); });
      return;
    }

    initializeGoogle()
      .then(function () {
        ready = true;
        renderButtons();

        if (!observer && window.MutationObserver) {
          observer = new MutationObserver(function () {
            if (ready) renderButtons();
          });
          observer.observe(document.body, { childList: true, subtree: true });
        }
      })
      .catch(function () {
        document.querySelectorAll('.sgl-btn').forEach(function (b) { showError(null, b); });
      });
  }

  if (document.readyState === 'complete') {
    start();
  } else {
    window.addEventListener('load', start, { once: true });
  }
}());
