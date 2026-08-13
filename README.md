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

> Este paquete **no está publicado en Packagist**. Para instalarlo con Composer hay
> que registrar antes este repositorio en el proyecto; sin ese paso
> `composer require` no lo encuentra.

### Opción A — Composer (recomendada, y la única viable con CI/CD)

```bash
# 1. Registrar el repositorio en el composer.json del proyecto
composer config repositories.transparentedge vcs \
  https://github.com/TransparentEdge/magento-transparentedge-cache

# 2. Instalar
composer require transparentedge/magento2-cdn:^2.0

# 3. Activar
bin/magento module:enable TransparentEdge_CDN
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Para actualizar:

```bash
composer update transparentedge/magento2-cdn
bin/magento setup:di:compile && bin/magento cache:flush
```

Si Composer resuelve una versión antigua, vacía su caché con
`composer clear-cache` y comprueba lo instalado con
`composer show transparentedge/magento2-cdn`.

### Opción B — Copia manual

Los comandos asumen un servidor web que corre como `www-data`; adáptalos si tu
entorno usa otro usuario o gestiona los permisos por pipeline.

```bash
mkdir -p app/code/TransparentEdge/CDN
cp -r magento-transparentedge-cache/* app/code/TransparentEdge/CDN/
chown -R www-data:www-data app/code/TransparentEdge/

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

La guía completa está en la carpeta [`doc/`](https://github.com/TransparentEdge/magento-transparentedge-cache/tree/main/doc) de este repositorio.

> `doc/` está marcada como `export-ignore`, por lo que **no viaja en el paquete que descarga Composer**. Consúltala en GitHub.

## Soporte

- **Email:** help+cdn@transparentedge.eu
- **Dashboard:** [dashboard.transparentcdn.com](https://dashboard.transparentcdn.com)
- **Documentación CDN:** [docs.transparentedge.eu](https://docs.transparentedge.eu)

## Licencia

MIT — Ver [LICENSE](LICENSE) para más detalles.

---

**Transparent Edge Services** — CDN europeo de alto rendimiento con procesamiento de imágenes, seguridad integrada y soporte técnico especializado.
