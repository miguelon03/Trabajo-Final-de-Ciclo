const pageSize = 6;
let currentPage = 1;

const filterInputs = Array.from(document.querySelectorAll(".filter-input")).filter(
  (input) => input instanceof HTMLInputElement
);
const clearFiltersBtn = document.getElementById("clearFilters");
const productsGrid = document.getElementById("productsGrid");
const productCards = Array.from(document.querySelectorAll(".product-card")).filter(
  (card) => card instanceof HTMLElement
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
let currentFavorites = window.dmhFavorites?.get?.() || [];

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
  const { authorized, slugs, error } = await fetchWishlist();

  if (error && authorized === false) {
    window.showToast?.(error, "error");
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

      if (!authorized) {
        window.showToast?.("Inicia sesión para guardar favoritos", "info");
        setTimeout(() => {
          window.location.href = "/login";
        }, 500);
        return;
      }

      const isFavorite = favButton.textContent === "♥";
      const result = isFavorite ? await removeFavorite(slug) : await addFavorite(slug);

      if (!result.ok) {
        if (result.status === 401) {
          window.showToast?.("Tu sesión ha caducado. Inicia sesión de nuevo", "info");
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

function setupAccessoryQuickAdd() {
  productCards.forEach((card) => {
    const type = card.getAttribute("data-type");
    if (type !== "accesorio") {
      return;
    }

    const footer = card.querySelector(".product-card__footer");
    if (!(footer instanceof HTMLElement) || footer.querySelector(".quick-add-btn")) {
      return;
    }

    const quickAddBtn = document.createElement("button");
    quickAddBtn.type = "button";
    quickAddBtn.className = "quick-add-btn";
    quickAddBtn.innerHTML = quickAddIcon;
    quickAddBtn.setAttribute("aria-label", "Añadir accesorio al carrito");

    quickAddBtn.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();

      const id = Number(card.getAttribute("data-product-id") || 0);
      const slug = card.getAttribute("data-product-slug") || "";
      const title = card.getAttribute("data-product-title") || "Producto";
      const image = card.getAttribute("data-product-image") || "";
      const color = card.getAttribute("data-color") || "";
      const price = Number(card.getAttribute("data-price") || 0);

      if (!id || !slug || !price) {
        return;
      }

      const cart = JSON.parse(localStorage.getItem("cart") || "[]");
      const sku = `DMH-${id}-UNICA`;
      const existingIndex = cart.findIndex(
        (item) => item.slug === slug && item.size === "Única",
      );

      if (existingIndex >= 0) {
        cart[existingIndex].quantity += 1;
      } else {
        cart.push({
          id,
          slug,
          title,
          price,
          image,
          color,
          size: "Única",
          sku,
          quantity: 1,
        });
      }

      localStorage.setItem("cart", JSON.stringify(cart));
      window.dispatchEvent(new Event("cart-updated"));
      window.showToast?.("Accesorio añadido al carrito", "success");
    });

    footer.appendChild(quickAddBtn);
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
      (input) => input.dataset.filterType === "type" && input.checked
    )
    .map((input) => input.value);

  activeFilters.color = filterInputs
    .filter(
      (input) => input.dataset.filterType === "color" && input.checked
    )
    .map((input) => input.value);

  activeFilters.price = filterInputs
    .filter(
      (input) => input.dataset.filterType === "price" && input.checked
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
  // Actualizar indicador de página
  if (pageIndicator) {
    pageIndicator.textContent = `Página nº ${currentPage}`;
  }

  // Mostrar/ocultar flechas según la página actual
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
  const totalPages = Math.max(1, Math.ceil(sortedCards.length / pageSize));

  if (currentPage > totalPages) {
    currentPage = totalPages;
  }

  const start = (currentPage - 1) * pageSize;
  const end = start + pageSize;

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

setupCardNavigation();
setupWishlist();
setupAccessoryQuickAdd();
applyFiltersAndPagination();
