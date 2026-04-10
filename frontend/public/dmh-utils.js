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
