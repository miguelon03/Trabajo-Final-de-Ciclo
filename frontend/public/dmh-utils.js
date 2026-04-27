// ── DMH global utilities ──
// Cargado como script normal (no módulo) para que esté disponible en todo el sitio.

var _toastTimer = null;
var _confirmEl = null;

window.showToast = function (message, type) {
  type = type || 'info';
  var toast = document.getElementById('dmhToast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'dmhToast';
    document.body.appendChild(toast);
  }

  var icons = {
    success: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    error:   '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    info:    '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
  };

  toast.className = 'dmh-toast dmh-toast--' + type;
  toast.innerHTML =
    '<span class="dmh-toast__icon">' + (icons[type] || icons.info) + '</span>' +
    '<span>' + message + '</span>';

  // forzar reflow para reiniciar la animación
  void toast.offsetWidth;
  toast.classList.add('is-visible');

  if (_toastTimer) clearTimeout(_toastTimer);
  _toastTimer = setTimeout(function () {
    toast.classList.remove('is-visible');
  }, 3000);
};

window.showConfirmToast = function (message, onConfirm) {
  if (!_confirmEl) {
    _confirmEl = document.createElement('div');
    _confirmEl.id = 'dmhConfirm';
    // Posicionarlo un poco más arriba que el toast normal para no solaparse
    _confirmEl.style.bottom = '2rem';
    _confirmEl.style.right = '2rem';
    _confirmEl.style.position = 'fixed';
    document.body.appendChild(_confirmEl);
  }

  var warnIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';

  _confirmEl.className = 'dmh-toast dmh-toast--info dmh-toast--confirm';
  _confirmEl.innerHTML =
    '<span class="dmh-toast__icon">' + warnIcon + '</span>' +
    '<div class="dmh-toast__body">' +
      '<span>' + message + '</span>' +
      '<div class="dmh-toast__actions">' +
        '<button class="dmh-toast__btn dmh-toast__btn--confirm">Sí, vaciar</button>' +
        '<button class="dmh-toast__btn dmh-toast__btn--cancel">Cancelar</button>' +
      '</div>' +
    '</div>';

  void _confirmEl.offsetWidth;
  _confirmEl.classList.add('is-visible');

  _confirmEl.querySelector('.dmh-toast__btn--confirm').onclick = function () {
    _confirmEl.classList.remove('is-visible');
    onConfirm();
  };
  _confirmEl.querySelector('.dmh-toast__btn--cancel').onclick = function () {
    _confirmEl.classList.remove('is-visible');
  };
};

// Config global de API para frontend.
window.DMH_CONFIG = window.DMH_CONFIG || {
  API_BASE_URL: 'http://tfc.local/backend/api',
};

window.dmhApiUrl = function (endpoint) {
  var base = (window.DMH_CONFIG && window.DMH_CONFIG.API_BASE_URL) || '';
  var cleanBase = base.replace(/\/+$/, '');
  var cleanEndpoint = String(endpoint || '').replace(/^\/+/, '');
  return cleanBase + '/' + cleanEndpoint;
};

window.dmhFetchJson = async function (endpoint, options) {
  try {
    var response = await fetch(window.dmhApiUrl(endpoint), options || {});
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
  } catch (_error) {
    return {
      ok: false,
      status: 0,
      data: null,
      error: 'No se pudo conectar con el servidor. Revisa backend y CORS.',
    };
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
