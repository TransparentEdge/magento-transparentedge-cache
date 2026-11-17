# Generación del PDF de la guía

La fuente de la guía es `doc/guia.md`. El PDF que se entrega al cliente se
**genera** de ahí, no se edita a mano.

```bash
cd doc/pdf
pip install markdown weasyprint
python3 construir.py ../guia.md ../TransparentEdge-CDN-Magento2-Guia-v2.2.0.pdf
```

## Por qué está así

La versión anterior de la guía era un PDF sin fuente en el repositorio. Un PDF
no aparece en un diff, nadie lo revisa en un *pull request* y CI no puede
comprobarlo: se quedó describiendo la v1.0.0 durante toda la rama 2.x sin que
nadie lo notara. Con el markdown como fuente, un cambio en la guía se revisa
como cualquier otro cambio de código.

**Si tocas algo del plugin que aparezca en la guía, actualiza `guia.md` en el
mismo commit.**

## Qué hay aquí

| Fichero | Qué es |
|---|---|
| `construir.py` | Markdown → HTML → PDF (WeasyPrint) |
| `isotipo-blanco.svg` | Isotipo corporativo para la portada, en blanco sobre el azul |
| `fuentes/` | Plus Jakarta Sans 400/600 (SIL Open Font License) |

Identidad visual aplicada: azul `#002643`, aqua `#00a5a6`, gris `#f3f3f3` y
Plus Jakarta Sans, según el manual de marca de Transparent Edge.

> **El logotipo completo no está incluido.** La portada lleva el isotipo y el
> nombre compuesto en la tipografía corporativa. Si quieres el logotipo oficial,
> deja `TE_Logotipo_Principal_Negativo.svg` en esta carpeta y añádelo a la
> portada en `construir.py`: no se dibuja ni se aproxima un logotipo.
