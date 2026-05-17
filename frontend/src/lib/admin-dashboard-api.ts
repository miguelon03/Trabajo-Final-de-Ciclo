export type AdminDashboardStats = {
  productos: number;
  pedidos: number;
  usuarios: number;
  ingresosMes: number;
};

type AdminDashboardResponse = {
  ok: boolean;
  stats?: AdminDashboardStats;
  error?: string;
};

function getAdminDashboardUrl(): string {
  return "http://tfc.local/backend/api/admin/dashboard.php";
}

export async function fetchAdminDashboard(cookieHeader?: string): Promise<AdminDashboardStats> {
  const response = await fetch(getAdminDashboardUrl(), {
    headers: cookieHeader
      ? {
          cookie: cookieHeader,
        }
      : {},
  });

  if (!response.ok) {
    throw new Error(`No se pudo cargar dashboard (HTTP ${response.status})`);
  }

  const data = (await response.json()) as AdminDashboardResponse;

  if (!data.ok || !data.stats) {
    throw new Error(data.error || "Error al cargar dashboard");
  }

  return data.stats;
}