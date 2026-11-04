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
    // A hidden checkout step has no usable width. Wait for it to open rather
    // than drawing Google's default button and immediately replacing it.
    if (!element.getClientRects().length || !element.clientWidth) return 0;
    // The content box: padding and borders are not room for the button.
    var style = window.getComputedStyle(element);
    var width = Math.floor(element.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0));

    return width > 0 ? Math.max(200, Math.min(400, width)) : 0;
  }

  // Google first inserts temporary markup, then replaces it with its iframe.
  // Reveal only the loaded, sized iframe, after fonts settle; keep its space.
  function waitForButton(element) {
    var originalOpacity = element.style.opacity;
    var originalInert = element.inert;
    var loadedFrame = null;
    var fontsReady = !document.fonts;
    var cancelled = false;
    var timer;
    var visibilityObserver;
    // Keep the iframe in layout: Google defers sizing visibility:hidden frames.
    element.style.opacity = '0';
    element.inert = true;
    delete element.dataset.sglReady;

    function cleanup() {
      cancelled = true;
      clearTimeout(timer);
      if (visibilityObserver) visibilityObserver.disconnect();
      watcher.disconnect();
      element.removeEventListener('load', loaded, true);
      element.style.opacity = originalOpacity;
      element.inert = originalInert;
    }
    function check() {
      if (cancelled || !fontsReady || !loadedFrame || !element.contains(loadedFrame)) return;
      var box = loadedFrame.getBoundingClientRect();
      // Google's temporary HTML button is removed when its iframe takes over.
      // Smaller buttons legitimately have extra clickable space around the iframe.
      if (!box.width || !box.height || element.querySelector('[role="button"]')) return;
      requestAnimationFrame(function () {
        if (cancelled || !element.contains(loadedFrame)) return;
        element.dataset.sglReady = 'true';
        cleanup();
      });
    }
    function loaded(event) {
      if (event.target.tagName !== 'IFRAME') return;
      loadedFrame = event.target;
      check();
    }
    var watcher = new MutationObserver(check);
    watcher.observe(element, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'width', 'height'] });
    element.addEventListener('load', loaded, true);
    if (document.fonts) {
      document.fonts.ready.then(function () { fontsReady = true; check(); });
    }
    function timeoutWhenVisible(visible) {
      clearTimeout(timer);
      if (!visible || cancelled) return;
      timer = setTimeout(function () {
        cleanup();
        // Only report a failure when Google drew nothing at all. A container that already holds an
        // iframe or a rendered button is left alone and shown as it is.
        if (element.querySelector('iframe, [role="button"]')) {
          element.dataset.sglReady = 'true';
          return;
        }
        element.innerHTML = '';
        showError(window.SGL.blocked, element);
      }, 10000);
    }
    // Offscreen buttons may be lazy-loaded by Google; only time out on screen.
    if (window.IntersectionObserver) {
      visibilityObserver = new IntersectionObserver(function (entries) {
        timeoutWhenVisible(entries[0].isIntersecting);
      });
      visibilityObserver.observe(element);
    } else {
      timeoutWhenVisible(true);
    }
    return cleanup;
  }

  function draw(element) {
    var width = buttonWidth(element);
    if (!width) return;

    if (element.sglCancelReveal) element.sglCancelReveal();
    clearError(element);
    element.sglCancelReveal = waitForButton(element);

    element.dataset.sglWidth = String(width);
    // Google's button is at most 400 px wide: in a wider form it sits in the middle of its container.
    element.style.display = 'flex';
    element.style.justifyContent = 'center';
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

      // A closed step keeps its already drawn button; its next opening may resize it.
      if (!width) return;

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
