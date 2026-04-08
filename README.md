# Transparent Edge CDN for Magento 2

Plugin oficial de integración CDN de [Transparent Edge Services](https://www.transparentedge.eu/) para Magento 2. Acelera tu tienda con invalidación quirúrgica de caché, optimización de imágenes, y mejoras de rendimiento web.

## Características

- **Surrogate-Keys** — Invalidación quirúrgica por producto, categoría, página CMS o bloque, sin afectar al resto del caché
- **TTLs desde origen** — Gestión de cabeceras `Cache-Control` desde Magento (s-maxage, stale-while-revalidate, stale-if-error)
- **i3 Image Optimization** — Conversión automática a WebP/AVIF con calidad configurable
- **Warm-up automático** — Precalentamiento de caché tras cada invalidación (homepage, categorías, sitemap)
- **Auto-flush** — Limpieza automática de las cachés internas de Magento tras cada cambio
- **WPO** — Preload de LCP/CSS/fonts, lazy load de iframes/vídeos, DNS prefetch/preconnect
- **Redis Manager** — Auto-detección y configuración de Redis con backup y rollback automático
- **Generador VCL** — Configuración VCL lista para copiar en el Dashboard de Transparent Edge

## Requisitos

| Componente | Versión mínima | Notas |
|---|---|---|
| Magento | 2.4.5+ | Probado en 2.4.7 |
| PHP | 8.1+ | Requiere typed properties |
| Cuenta TE | Activa | Company ID, Client ID y Secret |
| Redis (opcional) | 6.0+ | Para Object Cache, FPC y sesiones |

## Instalación

```bash
# 1. Copiar los archivos del plugin
mkdir -p app/code/TransparentEdge/CDN
cp -r magento-transparentedge-cache/* app/code/TransparentEdge/CDN/
chown -R www-data:www-data app/code/TransparentEdge/

# 2. Activar el módulo
bin/magento module:enable TransparentEdge_CDN
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
chown -R www-data:www-data generated/ var/
```

## Configuración

Tras la instalación, accede al admin de Magento y navega a **Transparent Edge → Setup Wizard**. El wizard te guiará en 4 pasos:

1. **Credenciales API** — Company ID, Client ID y Client Secret
2. **Preset de caché** — Ecommerce (recomendado), Estándar o Agresivo
3. **Features** — Warm-up, i3, Admin Bypass
4. **Activación** — Resumen y activación

> **Importante:** Es necesario desplegar el VCL generado por el plugin en el [Dashboard de Transparent Edge](https://dashboard.transparentcdn.com) para que las invalidaciones por Surrogate-Key (bans quirúrgicos) funcionen correctamente.

## Comandos CLI

```bash
bin/magento transparentedge:purge    # Full ban + warm-up
bin/magento transparentedge:warmup   # Precalentamiento manual
bin/magento transparentedge:status   # Estado del plugin y conexión API
```

## Invalidación de caché

El plugin intercepta los eventos de Magento y envía invalidaciones quirúrgicas a la CDN:

| Acción | Invalidación |
|---|---|
| Guardar producto | product-ID + categorías |
| Guardar categoría | category-ID + padres |
| Guardar página/bloque CMS | page-ID / block-ID |
| Acción masiva | Batch de product-IDs (100 tags/request) |
| Cambio de tema/config | Ban total (te-all) |

## Documentación

La guía completa de instalación, configuración y uso está disponible en [`doc/TransparentEdge-CDN-Magento2-Guia-v1.0.0.pdf`](doc/TransparentEdge-CDN-Magento2-Guia-v1.0.0.pdf).

## Soporte

- **Email:** help+cdn@transparentedge.eu
- **Dashboard:** [dashboard.transparentcdn.com](https://dashboard.transparentcdn.com)
- **Documentación CDN:** [docs.transparentedge.eu](https://docs.transparentedge.eu)

## Licencia

MIT — Ver [LICENSE](LICENSE) para más detalles.

---

**Transparent Edge Services** — CDN europeo de alto rendimiento con procesamiento de imágenes, seguridad integrada y soporte técnico especializado.
