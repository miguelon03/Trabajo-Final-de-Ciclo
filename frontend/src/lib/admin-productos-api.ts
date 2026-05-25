export type AdminProducto = {
  id: number;
  nombre: string;
  slug: string;
  descripcion: string | null;
  precio_base: number;
  precio_original: number | null;
  tipo: string | null;
  color: string | null;
  badge: string | null;
  imagen: string | null;
  fecha_catalogo: string | null;
  estado: string;
  creado_en: string;
  categoria: string;
  stock_total: number;
};

type ProductosListResponse = {
  ok: boolean;
  productos?: AdminProducto[];
  error?: string;
};

type ApiResponse = {
  ok: boolean;
  mensaje?: string;
  error?: string;
};

const SERVER_BASE = "http://localhost/backend/api/admin";
const CLIENT_BASE = "/backend/api/admin";

function getAdminProductosBase(): string {
  return typeof window === "undefined" ? SERVER_BASE : CLIENT_BASE;
}

async function parseApiResponse<T extends ApiResponse>(
  res: Response,
  fallbackMessage: string,
): Promise<T> {
  let data: T | null = null;

  try {
    data = (await res.json()) as T;
  } catch {
    throw new Error(`${fallbackMessage} (respuesta no JSON, HTTP ${res.status})`);
  }

  if (!res.ok || !data.ok) {
    throw new Error(data.error || `${fallbackMessage} (HTTP ${res.status})`);
  }

  return data;
}

export async function fetchAdminProductos(
  cookieHeader?: string,
): Promise<AdminProducto[]> {
  const res = await fetch(`${getAdminProductosBase()}/productos-list.php`, {
    headers: cookieHeader
      ? {
          cookie: cookieHeader,
        }
      : {},
  });

  const data = await parseApiResponse<ProductosListResponse>(
    res,
    "No se pudo cargar productos",
  );

  return data.productos ?? [];
}

export async function createAdminProducto(
  payload: Record<string, unknown>,
): Promise<void> {
  const res = await fetch(`${getAdminProductosBase()}/producto-create.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  await parseApiResponse<ApiResponse>(res, "Error al crear producto");
}

export async function updateAdminProducto(
  payload: Record<string, unknown>,
): Promise<void> {
  const res = await fetch(`${getAdminProductosBase()}/producto-update.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  await parseApiResponse<ApiResponse>(res, "Error al actualizar producto");
}

export async function updateAdminProductosBadge(
  ids: number[],
  badge: string,
): Promise<void> {
  const res = await fetch(
    `${getAdminProductosBase()}/producto-badge-bulk-update.php`,
    {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ ids, badge }),
    },
  );

  await parseApiResponse<ApiResponse>(res, "Error al actualizar badges");
}

export async function deleteAdminProducto(id: number): Promise<void> {
  const res = await fetch(`${getAdminProductosBase()}/producto-delete.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ id }),
  });

  await parseApiResponse<ApiResponse>(res, "Error al eliminar producto");
}