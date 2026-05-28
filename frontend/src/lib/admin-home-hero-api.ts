export type AdminHomeHeroConfig = {
  eyebrow: string;
  title: string;
  description: string;
  primary_label: string;
  primary_href: string;
  secondary_label: string;
  secondary_href: string;
  product_image: string;
  product_image_scale: number;
  product_image_pos_x: number;
  product_image_pos_y: number;
  background_image: string;
  background_image_scale: number;
  background_image_pos_x: number;
  background_image_pos_y: number;
  updated_at: string | null;
};

type HeroResponse = {
  ok: boolean;
  hero?: AdminHomeHeroConfig;
  error?: string;
};

const SERVER_BASE = "http://localhost/backend/api/admin";
const CLIENT_BASE = "/backend/api/admin";

function getAdminHeroBase(): string {
  return typeof window === "undefined" ? SERVER_BASE : CLIENT_BASE;
}

async function parseHeroResponse(
  response: Response,
  fallbackMessage: string,
): Promise<HeroResponse> {
  let data: HeroResponse;

  try {
    data = (await response.json()) as HeroResponse;
  } catch {
    throw new Error(`${fallbackMessage} (respuesta no JSON, HTTP ${response.status})`);
  }

  if (!response.ok || !data.ok) {
    throw new Error(data.error || `${fallbackMessage} (HTTP ${response.status})`);
  }

  return data;
}

export async function fetchAdminHomeHero(
  cookieHeader?: string,
): Promise<AdminHomeHeroConfig> {
  const response = await fetch(`${getAdminHeroBase()}/home-hero.php`, {
    headers: cookieHeader
      ? {
          cookie: cookieHeader,
        }
      : {},
  });

  const data = await parseHeroResponse(response, "No se pudo cargar la personalización del home");

  if (!data.hero) {
    throw new Error("No se recibió configuración del hero");
  }

  return data.hero;
}

export async function saveAdminHomeHero(
  payload: Record<string, unknown>,
): Promise<AdminHomeHeroConfig> {
  const response = await fetch(`${getAdminHeroBase()}/home-hero.php`, {
    method: "POST",
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });

  const data = await parseHeroResponse(response, "No se pudo guardar la personalización del home");

  if (!data.hero) {
    throw new Error("No se recibió configuración del hero tras guardar");
  }

  return data.hero;
}
