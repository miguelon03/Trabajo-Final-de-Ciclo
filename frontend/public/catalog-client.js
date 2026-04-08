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

applyFiltersAndPagination();
