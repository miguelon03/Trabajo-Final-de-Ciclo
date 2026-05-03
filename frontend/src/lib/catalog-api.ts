export type CatalogProduct = {
  id: number;
  slug: string;
  title: string;
  category: string;
  price: number;
  originalPrice?: number | null;
  type: string;
  color: string;
  badge?: string | null;
  createdAt: string;
  image: string;
  images?: string[];
  description: string;
};

export type CatalogStockEntry = {
  productId: number;
  productSlug: string;
  totalStock: number;
  bySize: Record<string, { stock: number; sku: string }>;
};

export type CatalogReviewItem = {
  id: string;
  user: string;
  rating: number;
  comment: string;
  date: string;
  verifiedPurchase: boolean;
};

export type CatalogReviewEntry = {
  productId: number;
  productSlug: string;
  averageRating: number;
  totalReviews: number;
  items: CatalogReviewItem[];
};

type CatalogApiResponse = {
  ok: boolean;
  products: CatalogProduct[];
  stock: CatalogStockEntry[];
  reviews: CatalogReviewEntry[];
  error?: string;
};

function getCatalogApiUrl(): string {
  const fromEnv = import.meta.env.CATALOG_API_URL;
  if (fromEnv && typeof fromEnv === "string" && fromEnv.trim() !== "") {
    return fromEnv.trim();
  }

  return "http://tfc.local/backend/api/catalogo.php";
}

export async function fetchCatalogData(slug?: string): Promise<CatalogApiResponse> {
  const baseUrl = getCatalogApiUrl();
  const url = slug ? `${baseUrl}?slug=${encodeURIComponent(slug)}` : baseUrl;

  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`No se pudo cargar catálogo (HTTP ${res.status})`);
  }

  const data = (await res.json()) as CatalogApiResponse;
  if (!data.ok) {
    throw new Error(data.error || "Error al cargar catálogo");
  }

  return data;
}
