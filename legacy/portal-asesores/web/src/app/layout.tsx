import type { Metadata, Viewport } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Cartera SEGUREX",
  description: "Consulta de cartera de clientes para asesores comerciales.",
  // iPhone no lee el manifest para el icono de la pantalla de inicio: usa
  // este. Sin el, guardaria una miniatura de la pantalla.
  appleWebApp: { capable: true, title: "Cartera", statusBarStyle: "default" },
  icons: { apple: "/icono-180.png" },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#ff671f",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="es">
      <body className="antialiased">{children}</body>
    </html>
  );
}
