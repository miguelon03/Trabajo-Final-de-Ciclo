// ── DMH global utilities ──
// Cargado como script normal (no módulo) para que esté disponible en todo el sitio.

var _toastStackEl = null;
var _toastSeq = 0;

function ensureToastStack() {
  if (_toastStackEl) {
    return _toastStackEl;
  }

  _toastStackEl = document.getElementById('dmhToastStack');
  if (!_toastStackEl) {
    _toastStackEl = document.createElement('div');
    _toastStackEl.id = 'dmhToastStack';
    _toastStackEl.className = 'dmh-toast-stack';
    document.body.appendChild(_toastStackEl);
  }

  return _toastStackEl;
}

window.showToast = function (message, type) {
  type = type || 'info';
  var stack = ensureToastStack();
  var toast = document.createElement('div');
  toast.className = 'dmh-toast dmh-toast--' + type;
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');
  toast.dataset.toastId = String(_toastSeq += 1);

  var icons = {
    success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    error:   '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    info:    '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
  };

  toast.innerHTML =
    '<span class="dmh-toast__icon">' + (icons[type] || icons.info) + '</span>' +
    '<span>' + message + '</span>';

  stack.appendChild(toast);

  // Forzamos reflow para garantizar la transición al entrar.
  void toast.offsetWidth;
  toast.classList.add('is-visible');

  var hide = function () {
    toast.classList.remove('is-visible');
    toast.classList.add('is-leaving');

    setTimeout(function () {
      if (toast.parentNode) {
        toast.parentNode.removeChild(toast);
      }
    }, 260);
  };

  setTimeout(hide, 3000);
};

window.showConfirmToast = function (message, onConfirm, options) {
  options = options || {};
  var confirmText = options.confirmText || 'Confirmar';
  var cancelText = options.cancelText || 'Cancelar';
  var stack = ensureToastStack();
  var confirmEl = document.createElement('div');
  confirmEl.className = 'dmh-toast dmh-toast--info dmh-toast--confirm';
  confirmEl.setAttribute('role', 'dialog');
  confirmEl.setAttribute('aria-live', 'polite');
  confirmEl.dataset.toastId = String(_toastSeq += 1);

  var warnIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

  confirmEl.innerHTML =
    '<span class="dmh-toast__icon">' + warnIcon + '</span>' +
    '<div class="dmh-toast__body">' +
      '<span>' + message + '</span>' +
      '<div class="dmh-toast__actions">' +
        '<button class="dmh-toast__btn dmh-toast__btn--confirm">' + confirmText + '</button>' +
        '<button class="dmh-toast__btn dmh-toast__btn--cancel">' + cancelText + '</button>' +
      '</div>' +
    '</div>';

  stack.appendChild(confirmEl);

  function closeConfirmToast() {
    confirmEl.classList.remove('is-visible');
    confirmEl.classList.add('is-leaving');
    setTimeout(function () {
      if (confirmEl.parentNode) {
        confirmEl.parentNode.removeChild(confirmEl);
      }
    }, 260);
  }

  void confirmEl.offsetWidth;
  confirmEl.classList.add('is-visible');

  confirmEl.querySelector('.dmh-toast__btn--confirm').onclick = function () {
    closeConfirmToast();
    if (typeof onConfirm === 'function') {
      onConfirm();
    }
  };
  confirmEl.querySelector('.dmh-toast__btn--cancel').onclick = function () {
    closeConfirmToast();
  };
};

// Config global de API para frontend.
window.DMH_CONFIG = window.DMH_CONFIG || {
  API_BASE_URL: '/backend/api',
};

window.dmhApiUrl = function (endpoint) {
  var base = (window.DMH_CONFIG && window.DMH_CONFIG.API_BASE_URL) || '';
  var cleanBase = base.replace(/\/+$/, '');
  var cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
  return cleanBase + '/' + cleanEndpoint;
};

window.dmhFetchJson = async function (endpoint, options) {
  var timeoutMs = 12000;
  var controller = null;
  var timeoutId = null;

  try {
    if (typeof AbortController !== 'undefined') {
      controller = new AbortController();
      timeoutId = setTimeout(function () {
        controller.abort();
      }, timeoutMs);
    }

    var requestOptions = Object.assign({}, options || {});
    if (controller && !requestOptions.signal) {
      requestOptions.signal = controller.signal;
    }

    var response = await fetch(window.dmhApiUrl(endpoint), requestOptions);
    var data = null;

    try {
      data = await response.json();
    } catch (_ignored) {
      data = null;
    }

    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        data: data,
        error: (data && data.error) || ('Error HTTP ' + response.status),
      };
    }

    return {
      ok: true,
      status: response.status,
      data: data,
      error: null,
    };
  } catch (error) {
    var isTimeout = error && error.name === 'AbortError';
    return {
      ok: false,
      status: 0,
      data: null,
      error: isTimeout
        ? 'La petición tardó demasiado. Revisa backend/proxy y vuelve a intentarlo.'
        : 'No se pudo conectar con el servidor. Revisa backend y CORS.',
    };
  } finally {
    if (timeoutId) {
      clearTimeout(timeoutId);
    }
  }
};

(function setupFavoritesSync() {
  var FAVORITES_CACHE_KEY = 'dmh:favorites:slugs';

  function normalizeSlugs(value) {
    if (!Array.isArray(value)) {
      return [];
    }
    var seen = {};
    var result = [];

    for (var i = 0; i < value.length; i += 1) {
      var slug = String(value[i] || '').trim();
      if (!slug || seen[slug]) {
        continue;
      }
      seen[slug] = true;
      result.push(slug);
    }

    return result;
  }

  function readCache() {
    try {
      var raw = localStorage.getItem(FAVORITES_CACHE_KEY);
      return normalizeSlugs(raw ? JSON.parse(raw) : []);
    } catch (_error) {
      return [];
    }
  }

  function writeCache(slugs) {
    var next = normalizeSlugs(slugs);
    localStorage.setItem(FAVORITES_CACHE_KEY, JSON.stringify(next));
    return next;
  }

  function emit(slugs, source) {
    var detail = {
      slugs: normalizeSlugs(slugs),
      source: source || 'unknown',
    };

    window.dispatchEvent(new CustomEvent('wishlist-updated', { detail: detail }));
  }

  window.dmhFavorites = {
    get: function () {
      return readCache();
    },
    set: function (slugs, source) {
      var next = writeCache(slugs);
      emit(next, source || 'set');
      return next;
    },
    toggleOne: function (slug, shouldInclude, source) {
      var current = readCache();
      var next = current.filter(function (item) {
        return item !== slug;
      });

      if (shouldInclude) {
        next.push(slug);
      }

      var normalized = writeCache(next);
      emit(normalized, source || 'toggle');
      return normalized;
    },
  };

  window.addEventListener('storage', function (event) {
    if (event.key !== FAVORITES_CACHE_KEY) {
      return;
    }

    emit(readCache(), 'storage');
  });
})();
