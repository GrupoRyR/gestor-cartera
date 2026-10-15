import type { MetadataRoute } from "next";

/**
 * Permite instalar la web como aplicacion en el celular: icono propio en la
 * pantalla de inicio y pantalla completa, sin la barra del navegador.
 *
 * Los asesores trabajan en la calle desde el telefono; entrar por un icono en
 * vez de buscar la direccion en el navegador es la diferencia entre que la
 * usen todos los dias o no.
 */
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Cartera SEGUREX",
    short_name: "Cartera",
    description: "Consulta de cartera de clientes para asesores comerciales.",
    start_url: "/",
    display: "standalone",
    orientation: "portrait",
    background_color: "#ffffff",
    theme_color: "#ff671f",
    lang: "es-CO",
    icons: [
      { src: "/icono-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icono-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/icono-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
