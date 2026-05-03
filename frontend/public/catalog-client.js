const pageSize = 6;
const LOW_STOCK_THRESHOLD = 3;
let currentPage = 1;

const filterInputs = Array.from(document.querySelectorAll(".filter-input")).filter(
  (input) => input instanceof HTMLInputElement,
);
const clearFiltersBtn = document.getElementById("clearFilters");
const productsGrid = document.getElementById("productsGrid");
const productCards = Array.from(document.querySelectorAll(".product-card")).filter(
  (card) => card instanceof HTMLElement,
);
const noResults = document.getElementById("noResults");
const productCount = document.getElementById("productCount");
const sortSelect = document.getElementById("sortSelect");
const prevPageBtn = document.getElementById("prevPage");
const nextPageBtn = document.getElementById("nextPage");
const pageIndicator = document.getElementById("pageIndicator");
const toggleFiltersBtn = document.getElementById("toggleFilters");
const sidebar = document.getElementById("sidebar");
const quickAddIcon = productsGrid?.getAttribute("data-quick-add-icon") || "";
const hasPaginationControls = Boolean(prevPageBtn || nextPageBtn || pageIndicator);
let currentFavorites = window.dmhFavorites?.get?.() || [];

function getCart() {
  return JSON.parse(localStorage.getItem("cart") || "[]");
}

function saveCart(cart) {
  localStorage.setItem("cart", JSON.stringify(cart));
  window.dispatchEvent(new Event("cart-updated"));
}

function getTotalStock(card) {
  return Number(card.getAttribute("data-product-total-stock") || 0);
}

function setTotalStock(card, stock) {
  card.setAttribute("data-product-total-stock", String(Math.max(0, stock)));
}

function getSizeOptions(card) {
  const raw = card.getAttribute("data-size-options") || "[]";
  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return [];
    }

    return parsed.map((item) => ({
      size: String(item?.size || ""),
      stock: Number(item?.stock || 0),
      sku: String(item?.sku || ""),
    })).filter((item) => item.size !== "");
  } catch (_) {
    return [];
  }
}

function setSizeOptions(card, options) {
  card.setAttribute("data-size-options", JSON.stringify(options));
}

function decrementSizeStock(card, targetSize, amount) {
  const current = getSizeOptions(card);
  const next = current.map((option) => {
    if (option.size !== targetSize) {
      return option;
    }
    return {
      size: option.size,
      stock: Math.max(0, option.stock - amount),
      sku: option.sku,
    };
  });
  setSizeOptions(card, next);
}

function addStockChip(card, totalStock) {
  const footer = card.querySelector(".product-card__footer");
  if (!(footer instanceof HTMLElement)) {
    return;
  }

  let chip = footer.querySelector(".stock-chip");
  if (!(chip instanceof HTMLElement)) {
    chip = document.createElement("span");
    chip.className = "stock-chip";
    footer.appendChild(chip);
  }

  chip.className = "stock-chip";
  if (totalStock <= 0) {
    chip.classList.add("stock-chip--out");
    chip.textContent = "Agotado";
    return;
  }

  if (totalStock <= LOW_STOCK_THRESHOLD) {
    chip.classList.add("stock-chip--low");
    chip.textContent = "Ultimas unidades";
    return;
  }

  chip.textContent = `${totalStock} uds.`;
}

function pushItemToCart(card, payload, maxStock) {
  const cart = getCart();
  const existingIndex = cart.findIndex(
    (item) => item.slug === payload.slug && item.size === payload.size,
  );
  const existingQty = existingIndex >= 0 ? Number(cart[existingIndex].quantity || 0) : 0;

  if (existingQty + payload.quantity > maxStock) {
    window.showToast?.("No hay suficiente stock para esa combinación.", "error");
    return false;
  }

  if (existingIndex >= 0) {
    cart[existingIndex].quantity += payload.quantity;
  } else {
    cart.push(payload);
  }

  saveCart(cart);
  return true;
}

function getProductPayloadBase(card) {
  return {
    id: Number(card.getAttribute("data-product-id") || 0),
    slug: card.getAttribute("data-product-slug") || "",
    title: card.getAttribute("data-product-title") || "Producto",
    price: Number(card.getAttribute("data-price") || 0),
    image: card.getAttribute("data-product-image") || "",
    color: card.getAttribute("data-color") || "",
  };
}

function addAccessoryToCart(card) {
  const base = getProductPayloadBase(card);
  const totalStock = getTotalStock(card);
  if (!base.id || !base.slug || !base.price) {
    return;
  }

  if (totalStock <= 0) {
    window.showToast?.("Este accesorio está agotado.", "error");
    return;
  }

  const ok = pushItemToCart(card, {
    ...base,
    size: "Única",
    sku: `DMH-${base.id}-UNICA`,
    quantity: 1,
  }, totalStock);

  if (!ok) {
    return;
  }

  setTotalStock(card, totalStock - 1);
  addStockChip(card, getTotalStock(card));
  renderCardActions(card);
  window.showToast?.("Accesorio añadido al carrito", "success");
}

let sizeModal = null;

function ensureEnhancementStyles() {
  if (document.getElementById("dmhCatalogEnhancementsStyles")) {
    return;
  }

  const style = document.createElement("style");
  style.id = "dmhCatalogEnhancementsStyles";
  style.textContent = `
    .size-add-btn {
      border: 1px solid rgba(255,255,255,.15);
      background: rgba(255,255,255,.06);
      color: #f5f5f5;
      border-radius: 999px;
      height: 34px;
      padding: 0 .85rem;
      font-size: .78rem;
      font-weight: 700;
      cursor: pointer;
      text-transform: uppercase;
      letter-spacing: .03em;
    }
    .size-add-btn:hover { background: rgba(255,255,255,.12); }
    .size-add-btn.is-disabled { opacity: .5; cursor: not-allowed; }
    .stock-chip {
      border-radius: 999px;
      padding: .28rem .7rem;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .02em;
      text-transform: uppercase;
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.06);
      color: rgba(255,255,255,.76);
    }
    .stock-chip--low {
      border-color: rgba(255,209,116,.34);
      background: rgba(255,209,116,.1);
      color: #ffd174;
    }
    .stock-chip--out {
      border-color: rgba(255,132,132,.34);
      background: rgba(255,132,132,.1);
      color: #ff8484;
    }
    .dmh-size-modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(8,8,8,.62);
      z-index: 9500;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .dmh-size-modal-overlay.is-open { display: flex; }
    .dmh-size-modal {
      width: 100%;
      max-width: 560px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.12);
      background: #171717;
      color: #fff;
      padding: 1.1rem;
      display: grid;
      gap: .9rem;
    }
    .dmh-size-modal__head {
      display: grid;
      grid-template-columns: 74px 1fr auto;
      gap: .8rem;
      align-items: center;
    }
    .dmh-size-modal__img {
      width: 74px;
      height: 74px;
      border-radius: 12px;
      overflow: hidden;
      background: rgba(255,255,255,.04);
      display: grid;
      place-items: center;
    }
    .dmh-size-modal__img img { width: 100%; height: 100%; object-fit: cover; }
    .dmh-size-modal__close {
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.04);
      color: rgba(255,255,255,.72);
      border-radius: 8px;
      width: 34px;
      height: 34px;
      cursor: pointer;
    }
    .dmh-size-modal__grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: .55rem;
    }
    .dmh-size-opt {
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.04);
      color: #f5f5f5;
      border-radius: 10px;
      padding: .55rem .6rem;
      text-align: left;
      cursor: pointer;
      display: grid;
      gap: .2rem;
    }
    .dmh-size-opt small { color: rgba(255,255,255,.64); font-size: .72rem; }
    .dmh-size-opt.is-selected {
      border-color: rgba(200,255,0,.56);
      background: rgba(200,255,0,.12);
      color: #ebff9c;
    }
    .dmh-size-opt.is-out {
      border-color: rgba(255,132,132,.28);
      color: #ffb3b3;
      background: rgba(255,132,132,.08);
    }
    .dmh-size-modal__hint { margin: 0; color: rgba(255,255,255,.7); font-size: .84rem; min-height: 20px; }
    .dmh-size-modal__actions { display: flex; justify-content: flex-end; }
    .dmh-size-modal__confirm {
      border: none;
      background: #c8ff00;
      color: #111;
      border-radius: 10px;
      font-weight: 800;
      padding: .65rem 1.05rem;
      cursor: pointer;
    }
    .dmh-size-modal__confirm:disabled { opacity: .5; cursor: not-allowed; }
  `;

  document.head.appendChild(style);
}

function ensureSizeModal() {
  if (sizeModal) {
    return sizeModal;
  }

  const overlay = document.createElement("div");
  overlay.className = "dmh-size-modal-overlay";
  overlay.innerHTML = `
    <div class="dmh-size-modal" role="dialog" aria-modal="true" aria-label="Seleccionar talla">
      <div class="dmh-size-modal__head">
        <div class="dmh-size-modal__img" id="dmhSizeModalImg"></div>
        <div>
          <strong id="dmhSizeModalTitle"></strong>
          <p class="dmh-size-modal__hint" id="dmhSizeModalStockHint"></p>
        </div>
        <button class="dmh-size-modal__close" type="button" id="dmhSizeModalClose" aria-label="Cerrar">×</button>
      </div>
      <div class="dmh-size-modal__grid" id="dmhSizeModalGrid"></div>
      <p class="dmh-size-modal__hint" id="dmhSizeModalSelectionHint">Selecciona una talla para añadir al carrito.</p>
      <div class="dmh-size-modal__actions">
        <button class="dmh-size-modal__confirm" type="button" id="dmhSizeModalConfirm" disabled>Añadir al carrito</button>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);

  const state = {
    overlay,
    grid: overlay.querySelector("#dmhSizeModalGrid"),
    title: overlay.querySelector("#dmhSizeModalTitle"),
    image: overlay.querySelector("#dmhSizeModalImg"),
    stockHint: overlay.querySelector("#dmhSizeModalStockHint"),
    selectionHint: overlay.querySelector("#dmhSizeModalSelectionHint"),
    confirm: overlay.querySelector("#dmhSizeModalConfirm"),
    close: overlay.querySelector("#dmhSizeModalClose"),
    card: null,
    selected: null,
  };

  function closeModal() {
    state.overlay.classList.remove("is-open");
    state.card = null;
    state.selected = null;
  }

  state.close?.addEventListener("click", closeModal);
  state.overlay.addEventListener("click", (event) => {
    if (event.target === state.overlay) {
      closeModal();
    }
  });

  state.confirm?.addEventListener("click", () => {
    if (!state.card || !state.selected) {
      return;
    }

    if (state.selected.stock <= 0) {
      state.selectionHint.textContent = "Esa talla está agotada. Elige otra talla disponible.";
      state.confirm.disabled = true;
      return;
    }

    const base = getProductPayloadBase(state.card);
    if (!base.id || !base.slug || !base.price) {
      return;
    }

    const ok = pushItemToCart(state.card, {
      ...base,
      size: state.selected.size,
      sku: state.selected.sku || `DMH-${base.id}-${state.selected.size}`,
      quantity: 1,
    }, state.selected.stock);

    if (!ok) {
      return;
    }

    decrementSizeStock(state.card, state.selected.size, 1);
    setTotalStock(state.card, getTotalStock(state.card) - 1);
    addStockChip(state.card, getTotalStock(state.card));
    renderCardActions(state.card);
    window.showToast?.(`Añadido al carrito: talla ${state.selected.size}`, "success");
    closeModal();
  });

  sizeModal = state;
  return sizeModal;
}

function openSizeModal(card) {
  const modal = ensureSizeModal();
  const options = getSizeOptions(card);
  if (!options.length) {
    const slug = card.getAttribute("data-product-slug") || "";
    if (slug) {
      window.location.href = `/producto/${slug}?from=${encodeURIComponent(window.location.pathname.replace(/^\/+|\/+$/g, "") || "catalogo")}`;
    }
    return;
  }

  modal.card = card;
  modal.selected = null;

  const title = card.getAttribute("data-product-title") || "Producto";
  const image = card.getAttribute("data-product-image") || "";
  const totalStock = getTotalStock(card);

  if (modal.title) {
    modal.title.textContent = title;
  }
  if (modal.stockHint) {
    modal.stockHint.textContent = `Stock total: ${totalStock} uds.`;
  }

  if (modal.image) {
    modal.image.innerHTML = image && (image.startsWith("/") || image.startsWith("http"))
      ? `<img src="${image}" alt="${title}" />`
      : "<span>IMG</span>";
  }

  if (modal.grid) {
    modal.grid.innerHTML = "";

    options.forEach((option) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = `dmh-size-opt ${option.stock <= 0 ? "is-out" : ""}`;
      button.innerHTML = `<strong>${option.size}</strong><small>${option.stock <= 0 ? "Agotada" : `${option.stock} uds.`}</small>`;

      button.addEventListener("click", () => {
        modal.selected = option;

        Array.from(modal.grid.querySelectorAll(".dmh-size-opt")).forEach((el) => {
          el.classList.remove("is-selected");
        });
        button.classList.add("is-selected");

        if (modal.selectionHint) {
          modal.selectionHint.textContent = option.stock > 0
            ? `Talla ${option.size} disponible.`
            : `Talla ${option.size} agotada. Selecciona otra talla.`;
        }
        if (modal.confirm) {
          modal.confirm.disabled = option.stock <= 0;
        }
      });

      modal.grid.appendChild(button);
    });
  }

  if (modal.selectionHint) {
    modal.selectionHint.textContent = "Selecciona una talla para añadir al carrito.";
  }
  if (modal.confirm) {
    modal.confirm.disabled = true;
  }

  modal.overlay.classList.add("is-open");
}

function renderCardActions(card) {
  const type = card.getAttribute("data-type");
  const footer = card.querySelector(".product-card__footer");
  if (!(footer instanceof HTMLElement)) {
    return;
  }

  const totalStock = getTotalStock(card);
  addStockChip(card, totalStock);

  if (type === "accesorio") {
    let button = footer.querySelector(".quick-add-btn");
    if (!(button instanceof HTMLButtonElement)) {
      button = document.createElement("button");
      button.type = "button";
      button.className = "quick-add-btn";
      button.innerHTML = quickAddIcon || "+";
      button.setAttribute("aria-label", "Añadir accesorio al carrito");
      button.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();
        addAccessoryToCart(card);
      });
      footer.appendChild(button);
    }

    if (totalStock <= 0) {
      button.disabled = true;
      button.classList.add("is-disabled");
      button.setAttribute("aria-label", "Accesorio agotado");
    } else {
      button.disabled = false;
      button.classList.remove("is-disabled");
      button.setAttribute("aria-label", "Añadir accesorio al carrito");
    }
    return;
  }

  // Para productos con tallas, la selección se hace en la ficha de producto.
  // Si existía un botón antiguo de talla, lo eliminamos para evitar confusión.
  const legacySizeBtn = footer.querySelector(".size-add-btn");
  if (legacySizeBtn instanceof HTMLElement) {
    legacySizeBtn.remove();
  }
}

function setupCardActions() {
  productCards.forEach((card) => {
    renderCardActions(card);
  });
}

async function fetchWishlist() {
  const result = await window.dmhFetchJson("favoritos.php", {
    credentials: "include",
  });

  if (!result.ok) {
    if (result.status === 401) {
      return { authorized: false, slugs: [], error: "Debes iniciar sesión" };
    }
    return {
      authorized: false,
      slugs: currentFavorites,
      error: result.error || "No se pudo cargar favoritos",
    };
  }

  const slugs = result.data?.favoritos || [];
  currentFavorites = window.dmhFavorites?.set?.(slugs, "catalog-fetch") || slugs;
  return { authorized: true, slugs: currentFavorites, error: null };
}

async function addFavorite(slug) {
  const result = await window.dmhFetchJson("favoritos.php", {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ slug }),
  });

  if (result.ok) {
    currentFavorites = window.dmhFavorites?.toggleOne?.(slug, true, "catalog-add") || currentFavorites;
  }

  return result;
}

async function removeFavorite(slug) {
  const result = await window.dmhFetchJson("favoritos.php", {
    method: "DELETE",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ slug }),
  });

  if (result.ok) {
    currentFavorites = window.dmhFavorites?.toggleOne?.(slug, false, "catalog-remove") || currentFavorites;
  }

  return result;
}

function updateWishlistButtonState(card, isFavorite) {
  const favButton = card.querySelector(".icon-fav");
  if (!(favButton instanceof HTMLButtonElement)) {
    return;
  }

  favButton.textContent = isFavorite ? "♥" : "♡";
  favButton.setAttribute(
    "aria-label",
    isFavorite ? "Quitar de favoritos" : "Añadir a favoritos",
  );
}

async function setupWishlist() {
  const { authorized, slugs } = await fetchWishlist();

  // Para invitado ocultamos los corazones: no hay wishlist sin sesión.
  if (!authorized) {
    productCards.forEach((card) => {
      const favButton = card.querySelector(".icon-fav");
      if (favButton instanceof HTMLElement) {
        favButton.remove();
      }
    });
    return;
  }

  productCards.forEach((card) => {
    const slug = card.getAttribute("data-product-slug");
    const favButton = card.querySelector(".icon-fav");

    if (!slug || !(favButton instanceof HTMLButtonElement)) {
      return;
    }

    updateWishlistButtonState(card, slugs.includes(slug));

    favButton.addEventListener("click", async (event) => {
      event.preventDefault();
      event.stopPropagation();

      const isFavorite = favButton.textContent === "♥";
      const result = isFavorite ? await removeFavorite(slug) : await addFavorite(slug);

      if (!result.ok) {
        if (result.status === 401) {
          setTimeout(() => {
            window.location.href = "/login";
          }, 500);
          return;
        }

        window.showToast?.(result.error || "No se pudo actualizar favoritos", "error");
        return;
      }

      updateWishlistButtonState(card, !isFavorite);
      window.showToast?.(
        isFavorite ? "Producto eliminado de favoritos" : "Producto añadido a favoritos",
        "success",
      );
    });
  });

  window.addEventListener("wishlist-updated", (event) => {
    const detailSlugs = event?.detail?.slugs;
    if (Array.isArray(detailSlugs)) {
      currentFavorites = detailSlugs;
    }

    const syncSlugs = currentFavorites;
    productCards.forEach((card) => {
      const slug = card.getAttribute("data-product-slug");
      if (!slug) {
        return;
      }
      updateWishlistButtonState(card, syncSlugs.includes(slug));
    });
  });
}

function setupCardNavigation() {
  const sourceSlug = window.location.pathname.replace(/^\/+|\/+$/g, "") || "catalogo";
  const buildProductUrl = (slug) =>
    `/producto/${slug}?from=${encodeURIComponent(sourceSlug)}`;

  productCards.forEach((card) => {
    const slug = card.getAttribute("data-product-slug");
    if (!slug) {
      return;
    }

    card.addEventListener("click", (event) => {
      const target = event.target;
      if (
        target instanceof Element &&
        (target.closest(".icon-fav") || target.closest(".quick-add-btn"))
      ) {
        return;
      }
      window.location.href = buildProductUrl(slug);
    });

    card.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        window.location.href = buildProductUrl(slug);
      }
    });
  });
}

const activeFilters = {
  type: [],
  color: [],
  price: [],
};

function updateActiveFilters() {
  activeFilters.type = filterInputs
    .filter(
      (input) => input.dataset.filterType === "type" && input.checked,
    )
    .map((input) => input.value);

  activeFilters.color = filterInputs
    .filter(
      (input) => input.dataset.filterType === "color" && input.checked,
    )
    .map((input) => input.value);

  activeFilters.price = filterInputs
    .filter(
      (input) => input.dataset.filterType === "price" && input.checked,
    )
    .map((input) => input.value);
}

function passesFilter(card) {
  const type = card.getAttribute("data-type") || "";
  const color = card.getAttribute("data-color") || "";
  const price = parseFloat(card.getAttribute("data-price") || "0");

  const matchesType = activeFilters.type.length === 0 || activeFilters.type.includes(type);
  const matchesColor = activeFilters.color.length === 0 || activeFilters.color.includes(color);
  const matchesPrice =
    activeFilters.price.length === 0 ||
    activeFilters.price.some((range) => {
      const [min, max] = range.split("-").map(Number);
      return price >= min && price <= max;
    });

  return matchesType && matchesColor && matchesPrice;
}

function sortCards(cards) {
  const currentSort = sortSelect?.value || "newest";

  return cards.sort((a, b) => {
    if (currentSort === "price-asc") {
      return parseFloat(a.getAttribute("data-price") || "0") - parseFloat(b.getAttribute("data-price") || "0");
    }
    if (currentSort === "price-desc") {
      return parseFloat(b.getAttribute("data-price") || "0") - parseFloat(a.getAttribute("data-price") || "0");
    }
    if (currentSort === "name") {
      const titleA = (a.querySelector("h3")?.textContent || "").trim();
      const titleB = (b.querySelector("h3")?.textContent || "").trim();
      return titleA.localeCompare(titleB);
    }
    const dateA = new Date(a.getAttribute("data-created-at") || "1970-01-01").getTime();
    const dateB = new Date(b.getAttribute("data-created-at") || "1970-01-01").getTime();
    return dateB - dateA;
  });
}

function updatePagination(totalPages) {
  if (!hasPaginationControls) {
    return;
  }

  if (pageIndicator) {
    pageIndicator.textContent = `Página nº ${currentPage}`;
  }

  if (prevPageBtn) {
    if (currentPage === 1) {
      prevPageBtn.style.visibility = "hidden";
    } else {
      prevPageBtn.style.visibility = "visible";
      prevPageBtn.onclick = () => {
        if (currentPage > 1) {
          currentPage -= 1;
          applyFiltersAndPagination();
        }
      };
    }
  }

  if (nextPageBtn) {
    if (currentPage === totalPages) {
      nextPageBtn.style.visibility = "hidden";
    } else {
      nextPageBtn.style.visibility = "visible";
      nextPageBtn.onclick = () => {
        if (currentPage < totalPages) {
          currentPage += 1;
          applyFiltersAndPagination();
        }
      };
    }
  }
}

function applyFiltersAndPagination() {
  updateActiveFilters();

  const filteredCards = productCards.filter((card) => passesFilter(card));
  const sortedCards = sortCards(filteredCards.slice());
  const pageSizeToUse = hasPaginationControls ? pageSize : Math.max(1, sortedCards.length || 1);
  const totalPages = Math.max(1, Math.ceil(sortedCards.length / pageSizeToUse));

  if (currentPage > totalPages) {
    currentPage = totalPages;
  }

  const start = (currentPage - 1) * pageSizeToUse;
  const end = start + pageSizeToUse;

  if (productsGrid) {
    sortedCards.forEach((card) => productsGrid.appendChild(card));
  }

  productCards.forEach((card) => {
    if (!passesFilter(card)) {
      card.style.display = "none";
    } else {
      const index = sortedCards.indexOf(card);
      card.style.display = index >= start && index < end ? "grid" : "none";
    }
  });

  if (noResults) {
    noResults.style.display = sortedCards.length === 0 ? "block" : "none";
  }

  if (productCount) {
    productCount.textContent = String(sortedCards.length);
  }

  updatePagination(totalPages);
}

filterInputs.forEach((input) => {
  input.addEventListener("change", () => {
    applyFiltersAndPagination();
  });
});

if (clearFiltersBtn) {
  clearFiltersBtn.addEventListener("click", () => {
    filterInputs.forEach((input) => {
      input.checked = false;
    });
    currentPage = 1;
    applyFiltersAndPagination();
  });
}

if (sortSelect) {
  sortSelect.addEventListener("change", () => {
    currentPage = 1;
    applyFiltersAndPagination();
  });
}

if (toggleFiltersBtn && sidebar) {
  toggleFiltersBtn.addEventListener("click", () => {
    sidebar.style.display = sidebar.style.display === "none" ? "flex" : "none";
  });
}

ensureEnhancementStyles();
setupCardActions();
setupCardNavigation();
setupWishlist();
applyFiltersAndPagination();
