export type AdminPedido = {
  id: number;
  referencia: string;
  cliente_nombre: string;
  cliente_email: string;
  total: number;
  estado: string;
  fecha: string;
  articulos: number;
};

export type PedidoDetalle = {
  id: number;
  referencia: string;
  cliente_nombre: string;
  cliente_email: string;
  direccion_envio: string;
  estado: string;
  fecha: string;
  items: {
    item_pedido_id?: number;
    slug: string;
    nombre: string;
    talla: string;
    color: string;
    sku: string;
    cantidad: number;
    cantidad_devuelta?: number;
    devolucion_estado?: string;
    devolucion_slug?: string;
    precio: number;
    subtotal: number;
  }[];
  total: number;
  mostrando_devoluciones?: boolean;
};

type PedidosListResponse = {
  ok: boolean;
  pedidos?: AdminPedido[];
  error?: string;
};

type PedidoDetalleResponse = {
  ok: boolean;
  detalle?: PedidoDetalle;
  error?: string;
};

type ApiResponse = {
  ok: boolean;
  mensaje?: string;
  error?: string;
};

const SERVER_BASE = "http://localhost/backend/api/admin";
const CLIENT_BASE = "/backend/api/admin";

function getBase() {
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

export async function fetchAdminPedidos(
  cookieHeader?: string,
): Promise<AdminPedido[]> {
  const res = await fetch(`${getBase()}/pedidos-list.php`, {
    headers: cookieHeader
      ? {
          cookie: cookieHeader,
        }
      : {},
  });

  const data = await parseApiResponse<PedidosListResponse>(
    res,
    "No se pudieron cargar los pedidos",
  );

  return data.pedidos ?? [];
}

export async function fetchPedidoDetalle(id: number): Promise<PedidoDetalle> {
  const res = await fetch(`${getBase()}/pedido-detalle.php?id=${id}`, {
    credentials: "include",
  });

  const data = await parseApiResponse<PedidoDetalleResponse>(
    res,
    "No se pudo cargar el detalle del pedido",
  );

  if (!data.detalle) {
    throw new Error("El pedido no tiene detalle disponible");
  }

  return data.detalle;
}

export async function updatePedidoEstado(
  id: number,
  estado: string,
): Promise<void> {
  const res = await fetch(`${getBase()}/pedido-estado-update.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ id, estado }),
  });

  await parseApiResponse<ApiResponse>(res, "No se pudo actualizar el pedido");
}