# Transparent Edge CDN for Magento 2

Plugin oficial de integración CDN de [Transparent Edge Services](https://www.transparentedge.eu/) para Magento 2. Acelera tu tienda con invalidación quirúrgica de caché, optimización de imágenes, y mejoras de rendimiento web.

## Características

- **Surrogate-Keys** — Invalidación quirúrgica por producto, categoría, página CMS o bloque, sin afectar al resto del caché
- **TTLs desde origen** — Gestión de cabeceras `Cache-Control` desde Magento (s-maxage, stale-while-revalidate, stale-if-error), con perfiles distintos por tipo de página
- **i3 Image Optimization** — Conversión automática a WebP/AVIF con calidad configurable
- **Warm-up diferido** — Precalentamiento tras cada invalidación, encolado y ejecutado por cron con presupuesto por hora, para no castigar al origen
- **Auto-flush** — Limpieza automática de las cachés internas de Magento tras cada cambio
- **Diagnóstico** — `transparentedge:status` comprueba el entorno y dice qué está mal, qué está bien y qué no ha podido mirar
- **Cabeceras de seguridad** — HSTS, CSP, Permissions-Policy y compañía, emitidas en el edge para que cubran también estáticos e imágenes
- **Speculation Rules** — Prerender/prefetch contextual, con detección de conflictos con otras librerías
- **WPO** — Preload de LCP/CSS/fonts, lazy load de iframes/vídeos, DNS prefetch/preconnect
- **Redis Manager** — Auto-detección y configuración de Redis con backup y rollback automático
- **Generador VCL** — Configuración VCL lista para copiar en el Dashboard de Transparent Edge

## Requisitos

| Componente | Versión mínima | Notas |
|---|---|---|
| Magento | 2.4.5+ | Probado en 2.4.7 |
| PHP | 8.1+ | Requiere typed properties |
| Cuenta TE | Activa | Company ID, Client ID y Secret |
| **Cron de Magento** | **Funcionando** | **Obligatorio para el precalentado.** Ver abajo |
| Redis (opcional) | 6.0+ | Para Object Cache, FPC y sesiones |

> **El cron no es opcional si usas warm-up.** Desde la v2.1 el precalentado no
> se ejecuta durante el guardado: se encola y lo vacía un cron cada 5 minutos.
> Sin cron, la cola crece y **no se precalienta nada, en silencio** — un
> warm-up que no ocurre no produce ningún error. Compruébalo con
> `bin/magento cron:install` y verifícalo con `bin/magento transparentedge:status`,
> que avisa si no hay rastro de que el cron haya corrido.

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
bin/magento setup:static-content:deploy -f es_ES en_US   # ver nota
bin/magento cache:flush
```

> **En modo producción, `setup:static-content:deploy` no es opcional.**
> `setup:upgrade` vacía `pub/static`, y si el despliegue de estáticos no se
> vuelve a lanzar la portada responde HTTP 500 con
> `Unable to retrieve deployment version of static files`. Sustituye los
> códigos de idioma por los de tu tienda. En modo `developer` puedes omitirlo.

Para actualizar:

```bash
composer update transparentedge/magento2-cdn
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f es_ES en_US
bin/magento cache:flush
```

> **Tras actualizar, regenera y vuelve a desplegar el VCL.** El VCL vive en el
> Dashboard, no en el código, así que actualizar el plugin no lo cambia. Un
> VCL generado con una versión anterior sigue funcionando pero puede no traer
> correcciones que importan: el de antes de la v2.1, por ejemplo, no elimina
> el `Set-Cookie` de las páginas cacheables, y con él **no se cachea nada**.

Si el despliegue de estáticos corre con tráfico en vivo, `setup:di:compile`
puede fallar con `Directory not empty` al vaciar `generated/code`: los workers
de PHP-FPM regeneran interceptores mientras el compilador borra. Lánzalo entre
`bin/magento maintenance:enable` y `bin/magento maintenance:disable`.

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
bin/magento setup:static-content:deploy -f es_ES en_US   # en modo producción
bin/magento cache:flush
chown -R www-data:www-data generated/ var/ pub/static/
```

> Si cambias ficheros del módulo, **borra `generated/` antes de compilar**:
> `rm -rf generated/code/* generated/metadata/*`. `setup:di:compile` no falla
> por saltártelo — compila contra el código viejo tan tranquilo — y acabas
> ejecutando una versión que no es la que crees. Para confirmarlo, compara la
> huella que imprime `transparentedge:status` con la del repo (ver
> [Diagnóstico](#diagnóstico)).

## Configuración

Tras la instalación, accede al admin de Magento y navega a **Transparent Edge → Setup Wizard**. El wizard te guiará en 4 pasos:

1. **Credenciales API** — Company ID, Client ID y Client Secret
2. **Preset de caché** — Ecommerce (recomendado), Estándar o Agresivo
3. **Features** — Warm-up, i3, Admin Bypass
4. **Activación** — Resumen y activación

El resto de ajustes están en **Stores → Configuration → Transparent Edge CDN**,
repartidos en: General, Cache Settings, Cache Profiles by Page Type, Cache
Warm-up, i3 Image Optimization, Web Performance (WPO), Security Headers,
Speculation Rules, Redis y Advanced.

> **Importante:** Es necesario desplegar el VCL generado por el plugin en el [Dashboard de Transparent Edge](https://dashboard.transparentcdn.com) para que las invalidaciones por Surrogate-Key (bans quirúrgicos) funcionen correctamente.

## Comandos CLI

```bash
bin/magento transparentedge:purge              # Full ban + warm-up
bin/magento transparentedge:purge --tags=...   # Invalidación por tags
bin/magento transparentedge:warmup             # Precalentamiento manual
bin/magento transparentedge:status             # Estado, diagnóstico y conexión API
bin/magento transparentedge:status --vcl       # Además, imprime el VCL a desplegar
```

## Precalentado

Cuando se invalida contenido, el plugin **encola** las URLs a precalentar y las
vacía un cron. No las lanza durante el guardado, y la diferencia no es de
estilo: con varios editores trabajando a la vez, precalentar en caliente
convierte cada guardado en decenas o cientos de peticiones al origen, justo
cuando el origen ya está ocupado regenerando. Tres editores guardando 20 veces
por hora en un catálogo de 200 categorías son 12.000 peticiones a la hora.

Cómo se contiene:

| Mecanismo | Qué hace |
|---|---|
| Cola diferida | Las URLs se acumulan; una ráfaga de ediciones se colapsa en un solo precalentado |
| Cron cada 5 min | `transparentedge_deferred_warmup` vacía lo acumulado |
| Presupuesto por hora | Techo configurable de URLs/hora (300 por defecto). Lo que no cabe espera |
| Candado | Dos vaciados no se solapan nunca, aunque el cron se acumule |
| Solo dominios propios | Una URL de otro host no se precalienta jamás |
| Barrido completo | `transparentedge_scheduled_warmup`, cada 6 h, **apagado salvo que lo enciendas** |

> El precalentado real cuesta aproximadamente una petición al origen por URL.
> El `refetch` de la plataforma agrupa por objeto entre nodos, no entre
> objetos: precalentar 200 URLs son ~200 renderizados en el origen, no uno.
> Por eso hay presupuesto.

## Diagnóstico

`bin/magento transparentedge:status` abre con el estado del entorno. Cada
comprobación sale con una marca:

| Marca | Significa |
|---|---|
| `✓` | Comprobado y correcto |
| `!` | Hay un problema, con la explicación y el comando para arreglarlo |
| `?` | **No se ha podido mirar todavía** — no es lo mismo que "está bien" |

Esa tercera marca existe a propósito. Algunas lecturas se toman mientras
Magento renderiza una página cacheable; si no se ha renderizado ninguna desde
el último `cache:flush`, no hay dato. Decir "correcto" ahí sería mentir, y
callarse se lee como que todo va bien.

Justo debajo de la versión, el comando imprime la **huella del código
instalado**:

```
  Transparent Edge CDN — Magento 2 Plugin v2.1.0
  Código desplegado: 493e93db47d2
```

`Config::VERSION` solo cambia al publicar, así que dos despliegues distintos
de la misma versión son indistinguibles por ahí. La huella no: resume el
código que realmente se está ejecutando. Para compararla con el repo:

```bash
php -r 'require "Model/Fingerprint.php"; echo TransparentEdge\CDN\Model\Fingerprint::of("."), "\n";'
```

Si las dos cadenas no coinciden, lo desplegado no es lo que crees. No cubre
`Test/` ni `.github/`, a propósito: así una instalación por `rsync` y una por
Composer dan la misma huella.

## Cabeceras de seguridad

En **Security Headers** se activan las cabeceras que emite el VCL generado.
Van en el edge y no en PHP para que cubran también estáticos, imágenes y
páginas de error, que no pasan por Magento.

| Cabecera | Por defecto |
|---|---|
| `X-Content-Type-Options: nosniff` | Encendida |
| `X-Frame-Options` | Encendida (`SAMEORIGIN`) |
| `Referrer-Policy` | Encendida (`strict-origin-when-cross-origin`) |
| `Permissions-Policy` | Apagada |
| `Cross-Origin-Opener-Policy` | Apagada (`same-origin-allow-popups` al encender) |
| `Strict-Transport-Security` (HSTS) | Apagada |
| `Content-Security-Policy` | Apagada |

Las cuatro últimas vienen apagadas porque pueden romper la tienda y la
decisión tiene que ser de alguien, no del instalador:

- **HSTS** obliga a HTTPS durante `max-age` **en el navegador del visitante**.
  Si luego hay que servir algo por HTTP, no hay forma de revertirlo desde el
  servidor. `preload` solo se emite junto a `includeSubDomains`, porque sin él
  el proceso de alta lo rechaza y quedarías creyéndote protegido sin estarlo.
- **CSP** se emite como `Content-Security-Policy-Report-Only` salvo que marques
  *Enforce*. Una política ligeramente mal deja la tienda sin JavaScript; en
  modo informe solo llena un log. Endurécela después de leer los informes.
- Una CSP o una Permissions-Policy **encendidas pero sin valor no se emiten**:
  una política vacía no protege de nada.

> Cambiar cualquier ajuste de esta sección **no cambia el VCL desplegado**.
> Hay que regenerarlo (`transparentedge:status --vcl`) y volver a subirlo al
> Dashboard. Y si tu VCL ya pone alguna de estas cabeceras a mano, quita la
> copia escrita a mano: tenerla dos veces no rompe nada, pero las dos copias
> se separan con el tiempo y nadie sabe cuál manda.

## Speculation Rules

El plugin puede inyectar reglas de *prerender*/*prefetch* contextuales según
el tipo de página. Antes de inyectar comprueba si **otra** librería ya está
haciendo prefetch (otro bloque `speculationrules`, quicklink, instant.page,
flying-pages): el navegador **fusiona** todos los conjuntos de reglas que
encuentra en lugar de elegir uno, así que añadir los nuestros encima de los de
otro no los sustituye, hace que el visitante se descargue las dos listas.

Si detecta un conflicto, el plugin **se aparta** y lo deja anotado;
`transparentedge:status` lo reporta.

## Invalidación de caché

El plugin intercepta los eventos de Magento y envía invalidaciones quirúrgicas a la CDN:

| Acción | Invalidación |
|---|---|
| Guardar producto | product-ID + categorías |
| Guardar categoría | category-ID + padres |
| Guardar página/bloque CMS | page-ID / block-ID |
| Acción masiva | Batch de product-IDs (100 tags/request) |
| Cambio de tema/config | Ban total (te-all) |

Con *Soft Purge* activado, el contenido invalidado se sigue sirviendo mientras
se regenera, en vez de dejar un hueco de MISS contra el origen.

## Desarrollo

Las pruebas no necesitan Magento ni PHPUnit: son scripts de PHP plano que se
ejecutan solos, y es lo que corre CI en PHP 8.1, 8.2 y 8.3.

```bash
php Test/Unit/packaging.php
php Test/Unit/header-and-tags.php
php Test/Unit/vcl-structure.php
php Test/Unit/diagnostics.php
php Test/Unit/cache-profile.php
php Test/Unit/api-payload.php
php Test/Unit/warmup.php
php Test/Unit/security-headers.php
php Test/Unit/speculation-conflict.php
php Test/Unit/fingerprint.php
php Test/Unit/safe-logger.php
```

## Documentación

La guía completa está en [`doc/guia.md`](doc/guia.md), y el PDF que se
entrega a clientes se genera de ahí (ver [`doc/pdf/LEEME.md`](doc/pdf/LEEME.md)).
**Si cambias algo del plugin que aparezca en la guía, actualízala en el mismo commit.**

> `doc/` está marcada como `export-ignore`, por lo que **no viaja en el paquete que descarga Composer**. Consúltala en GitHub.

## Soporte

- **Email:** help+cdn@transparentedge.eu
- **Dashboard:** [dashboard.transparentcdn.com](https://dashboard.transparentcdn.com)
- **Documentación CDN:** [docs.transparentedge.eu](https://docs.transparentedge.eu)

## Licencia

MIT — Ver [LICENSE](LICENSE) para más detalles.

---

**Transparent Edge Services** — CDN europeo de alto rendimiento con procesamiento de imágenes, seguridad integrada y soporte técnico especializado.
