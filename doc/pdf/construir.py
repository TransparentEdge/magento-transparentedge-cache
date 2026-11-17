#!/usr/bin/env python3
"""
Genera el PDF de la guia a partir de doc/guia.md.

El PDF es la pieza que se entrega al cliente; el markdown es la fuente. Este
script es el puente, y existe para que nadie tenga que mantener los dos a
mano: se regenera, no se edita.

Identidad visual segun la skill de marca de Transparent Edge:
  azul #002643, aqua #00a5a6, gris #f3f3f3, Plus Jakarta Sans 400/600.

Uso:  python3 construir.py <guia.md> <salida.pdf>
"""
import base64
import pathlib
import re
import sys

import markdown

AQUI = pathlib.Path(__file__).parent
FUENTES = AQUI / 'fuentes'
ISOTIPO = AQUI / 'isotipo-blanco.svg'

if len(sys.argv) != 3:
    sys.exit(__doc__)

origen, destino = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
texto = origen.read_text(encoding='utf-8')

# Quitar el frontmatter y la nota dirigida a quien mantiene el repo: no es
# para el cliente.
texto = re.sub(r'^---\n.*?\n---\n', '', texto, flags=re.S)
texto = re.sub(r'\n> \*\*Sobre esta guía\.\*\*.*?\n\n---\n', '\n', texto, flags=re.S)
texto = texto.lstrip('\n')
# El H1 va en la portada, no repetido en el cuerpo.
texto = re.sub(r'^# .*?\n', '', texto, count=1)

cuerpo = markdown.markdown(texto, extensions=['tables', 'fenced_code', 'toc', 'attr_list'])

iso = base64.b64encode(ISOTIPO.read_bytes()).decode() if ISOTIPO.is_file() else ''

# Indice a partir de los H2 del markdown.
indice = ''.join(
    '<li>%s</li>' % t.strip()
    for t in re.findall(r'^## (.+)$', texto, flags=re.M)
)  # ya numerados en el propio titulo

HTML = """<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<link rel="stylesheet" href="fuentes/jakarta.css">
<style>
:root {
  --te-azul:#002643; --te-aqua:#00a5a6; --te-amarillo:#ffd80f;
  --te-gris:#f3f3f3; --te-blanco:#fff;
  --te-gris-medio:#959697; --te-aqua-claro:#9cdbd7; --te-azul-profundo:#001d32;
}
@page {
  size: A4; margin: 20mm 18mm 18mm 18mm;
  @bottom-left {
    content: "Transparent Edge CDN para Magento 2 — Guía de instalación y uso";
    font-family:'Plus Jakarta Sans', Arial, sans-serif;
    font-size:7.5pt; color:#959697; vertical-align:top; padding-top:4mm;
  }
  @bottom-right {
    content: counter(page);
    font-family:'Plus Jakarta Sans', Arial, sans-serif;
    font-size:7.5pt; color:#959697; vertical-align:top; padding-top:4mm;
  }
}
@page :first {
  margin: 0;
  @bottom-left { content: none; }
  @bottom-right { content: none; }
}
* { box-sizing: border-box; }
body {
  font-family:'Plus Jakarta Sans', Arial, sans-serif; font-weight:400;
  color:var(--te-azul); background:#fff; margin:0;
  font-size:10.2pt; line-height:1.62; text-align:left;
}
h1,h2,h3,h4 { font-weight:600; color:var(--te-azul); }

/* ── Portada ───────────────────────────────────────────── */
.portada {
  height:297mm; counter-reset: page 0; background:var(--te-azul); color:#fff;
  padding:28mm 22mm; page-break-after:always;
  display:flex; flex-direction:column; justify-content:space-between;
}
.portada .iso { width:28mm; height:26.8mm; opacity:.97; }
.portada .marca {
  font-weight:600; font-size:13pt; letter-spacing:.14em;
  text-transform:uppercase; color:var(--te-aqua-claro); margin-top:9mm;
}
.portada h1 { color:#fff; font-size:32pt; line-height:1.14; margin:0 0 5mm; }
.portada .sub { font-size:14pt; color:var(--te-aqua-claro); margin:0; }
.portada .pie { font-size:9.5pt; color:var(--te-gris-suave,#c1c3c5); }
.portada .pastilla {
  display:inline-block; padding:.35em 1.1em; border-radius:999px;
  background:var(--te-aqua); color:#fff; font-weight:600; font-size:10pt;
}

/* ── Indice ────────────────────────────────────────────── */
.indice { page-break-after:always; }
.indice h2 { font-size:16pt; margin:0 0 6mm; }
.indice ul.sinpunto { list-style:none; padding-left:0; }
.indice ul.sinpunto li { border-bottom:1px solid #e8ebec; padding:1.6mm 0; }
.indice li { margin:1.6mm 0; }

/* ── Cuerpo ────────────────────────────────────────────── */
h2 {
  font-size:16pt; margin:11mm 0 3mm; padding-bottom:2mm;
  border-bottom:2px solid var(--te-aqua); page-break-after:avoid;
}
h3 { font-size:11.8pt; margin:7mm 0 2mm; page-break-after:avoid; }
p { margin:0 0 3mm; }
ul,ol { margin:0 0 3mm; padding-left:6mm; }
li { margin:.9mm 0; }
strong { font-weight:600; }
code {
  font-family:"DejaVu Sans Mono",monospace; font-size:8.9pt;
  background:var(--te-gris); padding:.12em .36em; border-radius:3px;
}
pre {
  background:var(--te-azul-profundo); color:#eef4f7; padding:4mm 5mm;
  border-radius:4px; overflow:visible; page-break-inside:avoid; margin:0 0 4mm;
}
pre code { background:none; color:inherit; font-size:8.5pt; padding:0; }
table {
  border-collapse:collapse; width:100%; margin:0 0 4mm;
  font-size:9.3pt; page-break-inside:avoid;
}
th {
  background:var(--te-azul); color:#fff; font-weight:600;
  text-align:left; padding:2mm 2.6mm;
}
td { padding:2mm 2.6mm; border-bottom:1px solid #e3e6e8; vertical-align:top; }
tr:nth-child(even) td { background:#fafbfb; }
blockquote {
  margin:0 0 4mm; padding:3mm 4mm; background:var(--te-gris);
  border-left:3px solid var(--te-aqua); page-break-inside:avoid;
}
blockquote p:last-child { margin-bottom:0; }
hr { border:none; border-top:1px solid #dfe3e5; margin:7mm 0; }
a { color:var(--te-aqua); text-decoration:none; }
</style></head><body>

<section class="portada">
  <div>
    %%ISO%%
    <div class="marca">Transparent Edge</div>
  </div>
  <div>
    <h1>Transparent Edge CDN<br>para Magento&nbsp;2</h1>
    <p class="sub">Guía de instalación y uso</p>
    <p style="margin-top:7mm"><span class="pastilla">Versión 2.2.0</span></p>
  </div>
  <div class="pie">
    Transparent Edge Services<br>
    help+cdn@transparentedge.eu · dashboard.transparentcdn.com
  </div>
</section>

<section class="indice">
  <h2>Índice</h2>
  <ul class="sinpunto">%%INDICE%%</ul>
</section>

%%CUERPO%%
</body></html>"""

iso_html = ('<img class="iso" src="data:image/svg+xml;base64,%s" alt="">' % iso) if iso else ''
HTML = (HTML.replace('%%ISO%%', iso_html)
            .replace('%%INDICE%%', indice)
            .replace('%%CUERPO%%', cuerpo))

ruta_html = AQUI / 'guia.html'
ruta_html.write_text(HTML, encoding='utf-8')

from weasyprint import HTML as WeasyHTML

WeasyHTML(filename=str(ruta_html)).write_pdf(str(destino))

print('PDF escrito en %s (%d KB)' % (destino, destino.stat().st_size // 1024))
if not iso:
    print('AVISO: no se encontro el isotipo oficial; la portada va sin marca grafica.')
