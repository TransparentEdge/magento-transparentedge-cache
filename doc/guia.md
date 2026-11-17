---
titulo: Transparent Edge CDN para Magento 2
subtitulo: Guía de instalación y uso
version: 2.2.0
---

# Transparent Edge CDN para Magento 2

> **Sobre esta guía.** Es la fuente de la que se genera el PDF que se entrega a
> clientes. Si cambias algo del plugin que aparezca aquí, cámbialo aquí también
> en el mismo commit: la versión anterior de esta guía era un PDF sin fuente en
> el repositorio, no salía en ningún diff, y se quedó describiendo la v1.0.0
> durante toda la rama 2.x sin que nadie lo notara.

---

## 1. Introducción

El plugin integra tu tienda Magento 2 con la CDN de Transparent Edge Services:
aceleración web, invalidación quirúrgica de caché mediante Surrogate-Keys,
optimización automática de imágenes (i3) y mejoras de rendimiento web (WPO).

La idea de fondo, que explica casi todas las decisiones del plugin: **la CDN
respeta lo que dice el origen**. Magento decide qué es cacheable y durante
cuánto tiempo, y lo dice en las cabeceras `Cache-Control` y `Surrogate-Key`. El
VCL del edge no sustituye ese criterio, solo lo hace cumplir y rellena los
huecos. Por eso casi todo se configura en el admin de Magento y no en el VCL.

### Características principales

- **Surrogate-Keys** — invalidación por producto, categoría, página CMS o bloque, sin tocar el resto de la caché.
- **TTLs desde el origen** — `Cache-Control` gestionado desde Magento, con perfiles distintos por tipo de página.
- **i3 Image Optimization** — conversión automática a WebP/AVIF, con calidad y ancho máximo configurables.
- **Warm-up diferido** — precalentamiento encolado y ejecutado por cron, con presupuesto por hora.
- **Auto-flush** — limpia las cachés internas de Magento tras cada cambio, y con ellas el aviso amarillo de "caché invalidada".
- **Diagnóstico** — `transparentedge:status` comprueba el entorno y distingue lo correcto de lo que no ha podido mirar.
- **Cabeceras de seguridad** — HSTS, CSP, Permissions-Policy y compañía, emitidas en el edge.
- **Speculation Rules** — prerender/prefetch contextual, con detección de conflictos.
- **WPO** — preload de recursos críticos, lazy load de iframes y vídeos, DNS prefetch.
- **Redis Manager** — auto-detección y configuración, con copia de seguridad y vuelta atrás.
- **Generador VCL** — VCL listo para pegar en el Dashboard.

---

## 2. Requisitos

| Componente | Versión mínima | Notas |
|---|---|---|
| Magento | 2.4.5+ | Probado en 2.4.7 |
| PHP | 8.1+ | Requiere *typed properties* |
| Cuenta TE | Activa | Company ID, Client ID y Secret |
| Dominio | Dado de alta | Configurado en el Dashboard de TE |
| **Cron de Magento** | **Funcionando** | **Obligatorio si usas warm-up** (ver §6) |
| Redis (opcional) | 6.0+ | Object Cache, FPC y sesiones |

> **MariaDB 10.11.** Magento rechaza esta versión por una expresión regular
> demasiado estricta. Edita `app/etc/di.xml` (línea ~1862) y cambia
> `^10\.[2-6]\.` por `^10\.`. Hazlo también en
> `vendor/magento/magento2-base/app/etc/di.xml`.

---

## 3. Instalación

Este paquete **no está en Packagist**: hay que registrar el repositorio antes
de instalarlo.

### 3.1 Con Composer (recomendado, y lo único viable con CI/CD)

```bash
composer config repositories.transparentedge vcs \
  https://github.com/TransparentEdge/magento-transparentedge-cache

composer require transparentedge/magento2-cdn:^2.0

bin/magento module:enable TransparentEdge_CDN
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f es_ES en_US   # en producción
bin/magento cache:flush
```

> **En modo producción, `setup:static-content:deploy` no es opcional.**
> `setup:upgrade` vacía `pub/static`; si no vuelves a desplegar los estáticos,
> la portada responde HTTP 500 con `Unable to retrieve deployment version of
> static files`. Cambia los códigos de idioma por los de tu tienda.

### 3.2 Copia manual

Los comandos asumen un servidor web que corre como `www-data`.

```bash
mkdir -p app/code/TransparentEdge/CDN
cp -r magento-transparentedge-cache/* app/code/TransparentEdge/CDN/
chown -R www-data:www-data app/code/TransparentEdge/

bin/magento module:enable TransparentEdge_CDN
bin/magento setup:upgrade
rm -rf generated/code/* generated/metadata/*
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f es_ES en_US   # en producción
bin/magento cache:flush
chown -R www-data:www-data generated/ var/ pub/static/
```

> **Borra `generated/` antes de compilar.** `setup:di:compile` **no falla** si
> te lo saltas: compila contra el código antiguo tan tranquilo, y acabas
> ejecutando una versión que no es la que crees. Si has cambiado la firma de un
> constructor, el error aparecerá después, en una petición cualquiera. Para
> confirmar qué código está vivo, compara la huella (§9.2).

### 3.3 Actualizar

```bash
composer update transparentedge/magento2-cdn
bin/magento setup:upgrade
rm -rf generated/code/* generated/metadata/*
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f es_ES en_US
bin/magento cache:flush
```

> **Después de actualizar, regenera y vuelve a desplegar el VCL.** El VCL vive
> en el Dashboard, no en el código: actualizar el plugin no lo cambia. Un VCL
> generado antes de la v2.1 **no elimina el `Set-Cookie` de las páginas
> cacheables, y con él no se cachea absolutamente nada** (ver §12).

Si `setup:di:compile` falla con `Directory not empty` al vaciar
`generated/code`, es que los workers de PHP-FPM están regenerando
interceptores mientras el compilador borra. Lánzalo entre
`bin/magento maintenance:enable` y `bin/magento maintenance:disable`.

---

## 4. Configuración inicial (Setup Wizard)

**Transparent Edge → Setup Wizard**, cuatro pasos:

1. **Credenciales API** — Company ID, Client ID y Client Secret, del Dashboard.
2. **Preset de caché** — Ecommerce (recomendado), Estándar o Agresivo.
3. **Features** — Warm-up, i3, Admin Bypass.
4. **Activación** — resumen y activación.

Al terminar, **despliega el VCL generado** en el Dashboard (§8). Sin ese paso
las invalidaciones por Surrogate-Key no funcionan.

---

## 5. Panel de configuración

**Stores → Configuration → Transparent Edge CDN**.

### 5.1 General
Activación del plugin y credenciales. El botón **Test Connection** pide un
token OAuth2 real contra la API; si falla, el mensaje dice por qué.

### 5.2 Cache Settings
TTL de CDN y de navegador para HTML y estáticos, `stale-while-revalidate`, y
los dos interruptores de invalidación:

- **Soft Purge** — el contenido invalidado se sigue sirviendo mientras se
  regenera, en vez de dejar un hueco de MISS contra el origen.
- **Refetch** — la plataforma vuelve a pedir el objeto al origen al invalidarlo.

### 5.3 Cache Profiles by Page Type
Un TTL distinto por tipo de página, porque no todas envejecen igual. Valores
por defecto:

| Tipo | TTL de CDN | stale-while-revalidate |
|---|---|---|
| Home | 5 min | 1 día |
| Categoría | 1 hora | 1 día |
| Producto | 1 día | 1 día |
| CMS | 2 días | 2 días |

La portada cambia a menudo y se mira mucho; una ficha de producto cambia poco.
Darles el mismo TTL obliga a elegir entre frescura y eficiencia para las dos.

### 5.4 Cache Warm-up
Qué precalentar (homepage, categorías, sitemap, URLs propias) y el techo de
peticiones. Cómo funciona en detalle, en §6.

### 5.5 i3 Image Optimization
Conversión automática a WebP/AVIF, calidad (85 por defecto) y ancho máximo.
Se aplica a `/media/catalog/`, `/media/wysiwyg/` y `/media/captcha/`.

La negociación ocurre **en el edge**: cada transformación es ya un objeto de
caché distinto. Por eso el plugin **no** emite `Vary: Accept`, que fragmentaría
la caché por la cadena `Accept` completa de cada visitante sin ganar nada.

### 5.6 Web Performance (WPO)
Optimizaciones de HTML que se aplican en el origen antes de cachear, así que
benefician a todos los visitantes: preload de LCP, CSS y fuentes; lazy load de
iframes y vídeos; DNS prefetch y preconnect.

### 5.7 Security Headers
Ver §7.

### 5.8 Speculation Rules
Ver §10.

### 5.9 Redis
Auto-detección y configuración de Redis para Object Cache, FPC y sesiones, con
copia de seguridad de `app/etc/env.php` y vuelta atrás.

### 5.10 Advanced
Admin Bypass (los administradores identificados se saltan la caché) y modo
depuración (`X-TE-Debug` y `X-TE-Page-Type` en las respuestas).

> El modo depuración **se apaga en producción**. Las cabeceras solo existen
> mientras está encendido, y existen para leerlas desde fuera, en el edge.

---

## 6. Precalentado

### 6.1 Por qué está diferido

Cuando se invalida contenido, el plugin **encola** las URLs y las vacía un
cron. No las lanza durante el guardado, y la diferencia no es de estilo.

Precalentar en caliente convierte cada guardado en decenas o cientos de
peticiones al origen, justo cuando el origen ya está ocupado regenerando lo que
se acaba de invalidar. Con varios editores a la vez se multiplica: tres
editores guardando 20 veces por hora en un catálogo de 200 categorías son
**12.000 peticiones al origen cada hora**, generadas por el plugin que debía
aliviarlo.

### 6.2 Cómo se contiene

| Mecanismo | Qué hace |
|---|---|
| Cola diferida | Las URLs se acumulan y se deduplican: una ráfaga de ediciones se colapsa en un solo precalentado |
| Cron cada 5 min | `transparentedge_deferred_warmup` vacía lo acumulado |
| Presupuesto por hora | Techo configurable de URLs/hora (300 por defecto). Lo que no cabe espera al siguiente ciclo |
| Candado | Dos vaciados no se solapan nunca, aunque el cron se acumule |
| Solo dominios propios | Una URL que no sea de un dominio de la tienda no se precalienta jamás |
| Barrido completo | `transparentedge_scheduled_warmup`, cada 6 h, **apagado salvo que lo enciendas** |

La cola guarda como máximo 2.000 URLs y caduca a la hora.

> **El `refetch` no abarata el precalentado.** Agrupa por objeto entre nodos,
> no entre objetos: precalentar 200 URLs son ~200 renderizados en el origen, no
> uno. Por eso hay un presupuesto y no un "precalienta todo".

### 6.3 El cron es obligatorio

Sin cron de Magento, la cola crece y **no se precalienta nada**. En silencio:
un precalentado que no ocurre no produce ningún error.

```bash
bin/magento cron:install          # instalar
bin/magento cron:run --group=default   # forzar una pasada
bin/magento transparentedge:status     # verificar
```

El diagnóstico avisa si no hay rastro de que el cron haya corrido. Ese rastro
vive en la caché de Magento, así que **un `cache:flush` o un despliegue lo
borran** y el aviso aparece hasta la siguiente pasada del cron. Si sigue ahí
pasados cinco minutos, el cron no está corriendo.

---

## 7. Cabeceras de seguridad

Se configuran en **Security Headers** y las emite el VCL generado. Van en el
edge y no en PHP para que cubran también estáticos, imágenes y páginas de
error, que no pasan por Magento.

| Cabecera | Por defecto |
|---|---|
| `X-Content-Type-Options: nosniff` | Encendida |
| `X-Frame-Options` | Encendida (`SAMEORIGIN`) |
| `Referrer-Policy` | Encendida (`strict-origin-when-cross-origin`) |
| `Permissions-Policy` | Apagada |
| `Cross-Origin-Opener-Policy` | Apagada (`same-origin-allow-popups` al encender) |
| `Strict-Transport-Security` (HSTS) | Apagada |
| `Content-Security-Policy` | Apagada |

Las cuatro últimas vienen apagadas porque pueden romper la tienda, y esa
decisión tiene que tomarla alguien:

- **HSTS** obliga a HTTPS durante `max-age` **en el navegador del visitante**.
  Si luego hay que servir algo por HTTP, no se puede revertir desde el
  servidor: hay que esperar a que caduque en cada navegador. `preload` solo se
  emite junto a `includeSubDomains`, porque sin él el proceso de alta lo
  rechaza y te quedas creyéndote protegido sin estarlo.
- **CSP** se emite como `Content-Security-Policy-Report-Only` salvo que marques
  *Enforce*. Una política ligeramente mal deja la tienda sin JavaScript; en
  modo informe solo llena un registro. Endurécela después de leer los informes.
- **Cross-Origin-Opener-Policy** usa `same-origin-allow-popups` y no
  `same-origin`: la estricta corta el enlace con la ventana que abren algunas
  pasarelas de pago y logins sociales.
- Una CSP o una Permissions-Policy **encendidas pero sin valor no se emiten**.
  Una política vacía no protege de nada y confunde al leer el VCL.

> Cambiar cualquier ajuste de esta sección **no cambia el VCL ya desplegado**.
> Hay que regenerarlo y volver a subirlo (§8). Y si tu VCL pone alguna de estas
> cabeceras a mano, quita la copia escrita a mano: tenerla dos veces no rompe
> nada, pero las dos copias se separan con el tiempo y nadie sabe cuál manda.

---

## 8. El VCL generado

```bash
bin/magento transparentedge:status --vcl
```

Produce tres bloques para el host de la tienda:

1. **`vcl_recv` — política de caché.** Bypass para administradores
   identificados y para rutas personalizadas (`/checkout`, `/customer`,
   `/wishlist`, `/multishipping`); variación por `X-Magento-Vary`; limpieza de
   parámetros de campaña (`utm_*`, `gclid`, `fbclid`); i3.
2. **`vcl_backend_response` — respuesta del origen.** TTL para estáticos y HTML
   **solo si el origen no dijo nada**, y eliminación del `Set-Cookie` en
   respuestas cacheables.
3. **`vcl_deliver` — entrega.** Oculta cabeceras internas y emite las de
   seguridad.

Dos detalles que parecen pequeños y no lo son:

> **Por qué se elimina el `Set-Cookie`.** Magento entrega un `PHPSESSID` en
> *todas* las respuestas HTML, también a los visitantes anónimos, y la
> plataforma nunca almacena una respuesta que trae un `Set-Cookie`. Sin esa
> línea no se cachea nada: medido en un sitio real, todas las peticiones
> respondían `tp-cache=pass` con `tcdn-hits=0`.

> **Por qué solo en respuestas cacheables.** Una respuesta que va a *pass*
> (carrito, checkout, un cliente identificado) lleva la cookie de sesión que el
> visitante necesita. Si se elimina ahí, el navegador nunca recibe sesión y el
> carrito deja de conservarse entre páginas.

### 8.1 Desplegarlo

El VCL se despliega **completo**, no por fragmentos. Si tu company ya tiene VCL
de otros sitios, sustituye solo los bloques del plugin y deja el resto intacto.
Al pegarlo, el Dashboard muestra un diff: **debería tocar únicamente los
bloques del plugin**. Si toca cualquier otra cosa, no lo despliegues.

---

## 9. Diagnóstico y verificación

### 9.1 Comprobaciones del entorno

`bin/magento transparentedge:status` abre con el estado del entorno:

| Marca | Significa |
|---|---|
| `✓` | Comprobado y correcto |
| `!` | Hay un problema, con su explicación y el comando para arreglarlo |
| `?` | **No se ha podido comprobar todavía** |

La tercera marca existe a propósito. Algunas lecturas se toman mientras Magento
renderiza una página cacheable; si no se ha renderizado ninguna desde el último
`cache:flush`, no hay dato. Decir "correcto" ahí sería mentir, y callar se lee
como que todo va bien. Pide cualquier página que no esté ya cacheada en el edge
y la comprobación se rellena.

### 9.2 La huella del código desplegado

Justo debajo de la versión:

```
  Transparent Edge CDN — Magento 2 Plugin v2.2.0
  Código desplegado: 493e93db47d2
```

`Config::VERSION` solo cambia al publicar, así que dos despliegues distintos de
la misma versión son indistinguibles por ahí. La huella resume el código que
realmente se está ejecutando. Para compararla con el repositorio:

```bash
php -r 'require "Model/Fingerprint.php"; echo TransparentEdge\CDN\Model\Fingerprint::of("."), "\n";'
```

Si las dos cadenas no coinciden, lo desplegado no es lo que crees. No cubre
`Test/` ni `.github/`, a propósito: así una instalación por `rsync` y una por
Composer dan la misma huella.

### 9.3 Verificar en el edge

```bash
curl -sI https://tu-dominio/ | grep -iE 'tp-cache|tcdn-hits|age|cache-control|surrogate'
```

| Cabecera | Qué mirar |
|---|---|
| `tp-cache` | `hit` / `miss` / `pass`. Un `pass` constante en páginas públicas es un problema |
| `tcdn-hits` | Cuántas veces se ha servido ese objeto desde la caché |
| `age` | Segundos que lleva el objeto almacenado |
| `cache-control` | El `s-maxage` debe coincidir con el perfil del tipo de página |

---

## 10. Speculation Rules

El navegador puede ir cargando por adelantado la página que el visitante
probablemente pida a continuación. El plugin genera esas reglas según el tipo
de página.

### 10.1 prefetch y prerender

- **prefetch** descarga el documento, sin ejecutarlo. Barato; ahorra la ida y
  vuelta a la red.
- **prerender** lo renderiza entero en segundo plano, JavaScript incluido. La
  navegación es instantánea, pero cuesta memoria y CPU al visitante, y dispara
  la analítica de una página que quizá no visite.

### 10.2 Modos

| Modo | Comportamiento |
|---|---|
| Conservador | Solo al pulsar |
| Moderado | Al pasar el ratón por encima (recomendado) |
| Agresivo | En cuanto el enlace es visible |

### 10.3 Exclusiones

Nunca se especula sobre `/checkout`, `/customer`, `/admin`, `/rest/` ni
`/graphql`: precargar una URL que cambia estado puede vaciar un carrito o
cerrar una sesión sin que nadie haya pulsado nada.

### 10.4 Detección de conflictos

Antes de inyectar nada, el plugin comprueba si **otra** librería ya está
haciendo prefetch: otro bloque `speculationrules`, quicklink, instant.page o
flying-pages.

Esto importa porque el navegador **fusiona** todos los conjuntos de reglas que
encuentra en una página, no elige uno. Añadir los nuestros encima de los de
otro no los sustituye: hace que el visitante se descargue las dos listas y
ejecute las dos estrategias a la vez.

Si detecta un conflicto, el plugin **se aparta** y lo deja anotado;
`transparentedge:status` lo reporta.

---

## 11. Comandos CLI

| Comando | Qué hace |
|---|---|
| `transparentedge:purge` | Ban completo de la CDN y precalentado de las páginas principales |
| `transparentedge:purge --tags=product-123` | Invalidación quirúrgica por tags |
| `transparentedge:warmup` | Precalentado manual |
| `transparentedge:status` | Estado, diagnóstico del entorno, huella y prueba de conexión |
| `transparentedge:status --vcl` | Lo anterior, más el VCL a desplegar |

---

## 12. Resolución de problemas

### No se cachea nada: todo responde `pass`

Lo primero que hay que mirar es el VCL desplegado. Un VCL generado **antes de
la v2.1** no elimina el `Set-Cookie`, y como Magento entrega un `PHPSESSID` en
todas las respuestas HTML, la plataforma no almacena ninguna. Regenera el VCL
(§8) y compáralo con el que hay en el Dashboard.

```bash
curl -sI https://tu-dominio/ | grep -iE 'tp-cache|set-cookie'
```

Si ves `tp-cache: pass` junto a un `set-cookie`, es esto.

### El warm-up no precalienta nada

El cron de Magento no está corriendo (§6.3). No da ningún error: un
precalentado que no ocurre es silencioso. Compruébalo con
`transparentedge:status`.

### Los cambios no se reflejan, o el código no es el que creo

Compara la huella con la del repositorio (§9.2). Si no coinciden, el despliegue
no trajo lo que pensabas — y `setup:di:compile` no te habría avisado, porque el
código antiguo compila igual de bien.

### Las cabeceras Surrogate-Key no aparecen

Verifica que el módulo está activo (`bin/magento module:status`) y que la CDN
está habilitada en la configuración.

```bash
curl -sI https://tu-dominio/ | grep -i surrogate-key
```

Si no aparecen pero el resto funciona, revisa que el bloque `vcl_deliver`
desplegado no las esté eliminando antes de tiempo.

### `Cache-Control` muestra `no-cache, no-store`

Es normal con el FPC propio de Magento. El plugin detecta
`X-Magento-Cache-Control` y lo sustituye por los TTLs configurados. Si no lo
hace, el módulo no está compilado: borra `generated/` y recompila (§3.2).

### Las cabeceras de seguridad no aparecen

Cambiarlas en el admin no cambia el VCL ya desplegado. Regenéralo y vuelve a
subirlo (§7, §8).

### Error de permisos al compilar o vaciar caché

```bash
chown -R www-data:www-data generated/ var/ pub/static/
```

Y lanza los comandos de Magento como el usuario del servidor web
(`sudo -u www-data bin/magento ...`): si compilas como root, `generated/` queda
con ficheros de root y el servidor web deja de poder escribirlos más adelante
—un fallo que no aparece ese día.

### Redis: *Redis not found*

Comprueba que Redis está corriendo (`systemctl status redis`). Si requiere
autenticación, introduce la contraseña en el campo **Password** y pulsa
**Detectar** de nuevo.

### MariaDB 10.11: error de expresión regular

Ver §2.

---

## 13. Soporte

| Canal | Contacto |
|---|---|
| Email | help+cdn@transparentedge.eu |
| Dashboard | dashboard.transparentcdn.com |
| Documentación CDN | docs.transparentedge.eu |
| Repositorio | github.com/TransparentEdge/magento-transparentedge-cache |

---

**Transparent Edge Services** — CDN europeo de alto rendimiento con
procesamiento de imágenes, seguridad integrada y soporte técnico especializado.
