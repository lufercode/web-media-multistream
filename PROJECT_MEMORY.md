# 🧠 MEMORIA DEL PROYECTO: Web Media Multistream (web2-ftp)

> **Documento maestro de contexto, arquitectura, proveedores, microservicios y despliegue.**  
> *Última actualización:* Octubre 2026

---

## 1. 📌 Visión General del Proyecto
**Web Media Multistream** es una plataforma integral de streaming multimedia (películas, series, anime y torrents) desarrollada en **PHP 8.2+** con frontend en JavaScript vanilla de alto rendimiento, indexación ultra-rápida en **SQLite**, y un microservicio en **Node.js** para streaming P2P / Stremio.

* **Repositorio GitHub:** `https://github.com/lufercode/web-media-multistream`
* **Entorno Local (Desarrollo):** Laragon (`c:\laragon\www\web2-ftp`)
* **Hosting Web (Producción):** InfinityFree (Apache + PHP 8.2/8.3 en `/htdocs/`)
* **Microservicio Streaming/P2P (Nube):** Render.com (`https://web-media-multistream.onrender.com`)

---

## 2. 🏗️ Arquitectura del Sistema

```
[ Cliente Web / Móvil / Stremio ]
             │
     ┌───────┴──────────────────────────┐
     ▼                                  ▼
[ InfinityFree (PHP Web) ]     [ Render.com (Node.js Daemon) ]
  • Catálogo TMDB + Búsquedas    • Motor WebTorrent P2P (HTTP 206)
  • Scrapers (15+ proveedores)   • Stremio Addon (v2.7.0)
  • Movie+ SQLite Index (<0.5ms) • Proxy /movieplus-stream
  • Resolvers de video           • Decodificación AES-256 nativa
  • Progreso y Watchlist (OAuth)
```

---

## 3. 🎬 Integración Especial: Movie+ (Cloud CDN)

El proyecto cuenta con un motor optimizado para la red de **Movie+** (CDN de alta velocidad con contenido en Español Latino y Castellano):

### 3.1. Criptografía y Seguridad
* **AES-256-ECB:**
  `\x61\x6e\x64\x72\x6f\x69\x64\x5f\x69\x64\x66\x6b\x76\x6e\x38\x20\x77\x34\x79\x2a\x28\x4e\x43\x24\x47\x2a\x28\x47\x28\x24\x2a\x47`
  * Desencripta URLs y enlaces Yandex Disk de películas (`type 1`).
* **AES-256-GCM:**
  `\x47\x40\x68\x66\x4d\x74\x5e\x65\x73\x65\x5e\x79\x6d\x43\x6e\x78\x5c\x59\x69\x5b\x5d\x6d\x62\x74\x59\x74\x7f\x69\x51\x2e\x2b\x08`
  * Desencripta metadatos y tokens de series/temporadas remotas.

### 3.2. Indexación y Base de Datos SQLite
* **Archivo base:** `data/movieplus/catalog_cache.json.gz` (Snapshot comprimido ~4.6 MB).
* **Base de datos SQLite:** `data/movieplus/catalog.db` (~14.3 MB tras `VACUUM`).
  * Tablas `movies` y `series` con índices en `id`, `title_norm` y `year`.
  * Rendimiento: Búsqueda por TMDB ID en **< 0.5 milisegundos**.
  * Búsqueda difusa por título con normalización de acentos y caracteres especiales.
* **Auto-Reconstrucción:** Si `catalog.db` no existe al iniciar en el servidor, `MoviePlusService::buildIndexFromGz()` lo reconstruye automáticamente en ~1.5 segundos.
* **Actualización Automática:** Consulta periódica a `api.github.com/repos/dev-mplus01/mplus01T/contents/db/list` cada 24 horas para descargar snapshots actualizados (`items_up_r*.json.gz`).

### 3.3. Series y Temporadas (Scraping Firestore)
* Las series consultan en tiempo real la API REST de Google Cloud Firestore:
  `https://firestore.googleapis.com/v1/projects/movie-plus-4545/databases/(default)/documents/items_series_r{ID}`
* Los capítulos y enlaces se desencriptan y se cachean localmente en `data/movieplus/cache/`.

### 3.4. Stream y Reproducción Directa
* **Proxy PHP:** `api/movieplus.php?action=stream&url={yadi.sk}` resuelve el enlace limpio de Yandex CDN y lo entrega con soporte para rangos HTTP 206 (Seeking/Pausa).
* **Códecs incompatibles en Navegador (MKV / AC-3 / HEVC):** Si el navegador no puede decodificar el códec nativo, se despliega un panel estilizado que ofrece:
  * Abrir en **VLC Media Player** (`vlc://...`).
  * Abrir en **Android Intent** (Reproductor MX, Nova, etc.).
  * Ver a través del **Addon de Stremio**.

---

## 4. 🧩 Addon de Stremio (v2.7.0)

Ubicado en [`tools/torrent-streamer/stremio_addon.js`](file:///c:/laragon/www/web2-ftp/tools/torrent-streamer/stremio_addon.js):
* **Manifest:** Expone catálogo y streams (`manifest.json`) en `https://web-media-multistream.onrender.com/stremio/manifest.json`.
* **Soporte Híbrido:**
  1. **Torrents:** Scrapea Torrentio, PirateBay, Nyaa, YTS y entrega streaming secuencial a través del motor WebTorrent.
  2. **Movie+ Direct Streams:** Busca por IMDb/TMDB ID en Movie+, desencripta enlaces de Yandex y los entrega a Stremio mediante el proxy `/movieplus-stream` con `Content-Type: video/mp4` y soporte HTTP Range 206.

---

## 5. 📡 Proveedores y Resolvers Integrados

### Proveedores Scraping (`lib/providers/`):
* **MoviePlusProvider:** Catálogo directo Cloud CDN (Latino y Castellano).
* **LocalCDNProvider:** Enlaces directos CDN propio (Rakun Cloud).
* **PelisPlusProvider, LaMovieProvider, SeriesKaoProvider:** Películas y series en streaming HD.
* **LatAnimeProvider, AnimeFLVProvider, JKAnimeProvider, TioAnimeProvider:** Anime subtitulado y en audio latino.
* **CuevanaProvider, CinecalidadProvider, GnulaProvider, PelisPediaProvider.**
* **HackTorrentProvider, DonTorrentProvider, EliteTorrentProvider.**
* **TorrentioProvider, PirateBayProvider, NyaaProvider, YTSProvider:** Indexación P2P.

### Resolvers de Video (`lib/resolvers/`):
* `YandexDiskResolver`, `FastreamResolver`, `StreamWishResolver`, `VidHideResolver`, `OkRuResolver`, `VoeResolver`, `RetroTVEResolver`.

---

## 6. 🌐 Estrategia de Despliegue en InfinityFree

### Restricciones y Especificaciones de InfinityFree:
* Servidor web **Apache** en entorno **Linux**.
* Límite de archivo individual: **10 MB a 20 MB** según el cargador.
* Almacenamiento total de cuenta: **5 GB**.
* **CRÍTICO:** Los archivos ZIP subidos a cPanel/Monsta FTP **deben** tener rutas con barras normales (`/`), ya que las barras invertidas de Windows (`\`) provocan rechazo del archivo.

### Empaquetador Automatizado (`package_infinityfree.ps1`):
* Utiliza `tar` nativo con estándar POSIX/Unix para asegurar compatibilidad 100% con Linux.
* Empaqueta el archivo maestro [`infinityfree-deploy.zip`](file:///c:/laragon/www/web2-ftp/infinityfree-deploy.zip) (**11.08 MB**).
* **Incluye:**
  * Código PHP, plantillas, APIs y assets web.
  * Base de datos SQLite optimizada (`catalog.db`) + comprimido (`catalog_cache.json.gz`).
  * Archivo [`.htaccess`](file:///c:/laragon/www/web2-ftp/.htaccess) con reglas MIME (`.m3u8`, `.mp4`, `.mkv`), CORS y seguridad.
  * Auto-creación de carpetas de caché y datos en [`config/config.php`](file:///c:/laragon/www/web2-ftp/config/config.php).
* **Excluye:** `.git/`, `node_modules/`, `tools/torrent-streamer/` (alojado en Render).

---

## 7. 🔑 Claves y Variables de Configuración

| Variable / Constante | Valor / Propósito | Archivo |
| :--- | :--- | :--- |
| `TMDB_API_KEY` | `aaccf8f89c76c5a7ac753e3a8402de56` | `config/config.php` |
| `TMDB_LANG` | `es-MX` (Español Latino) | `config/config.php` |
| `GOOGLE_CLIENT_ID` | `474518961918-0r1n29n2gegd6clc3etvidmp21t4c4kh...` | `config/config.php` |
| `TORRENT_STREAMER_REMOTE_URL` | `https://web-media-multistream.onrender.com` | `config/config.php` |
| `TORRENT_PLAYER_MODE` | `streamer` (Usa daemon remoto de Render) | `config/config.php` |

---

## 8. 📂 Árbol de Directorios Clave

```
web2-ftp/
├── .htaccess                 # Reglas Apache, CORS, MIME HLS, seguridad
├── index.php                 # Página principal (Trending, Cartelera, Recomendados)
├── details.php               # Reproductor ArtPlayer, selector de servidor/capítulos
├── search.php                # Búsqueda global TMDB + Movie+
├── config/
│   └── config.php            # Ajustes globales, endpoints, switches de proveedores
├── api/
│   ├── movieplus.php         # Búsquedas Movie+, Firestore seasons y stream proxy
│   ├── get_links.php         # Scraping unificado multiserver
│   ├── torrent_stream.php    # Conexión con microservicio Render
│   └── sync_watchlist.php    # Sincronización de progreso/watchlist
├── data/
│   ├── movieplus/            # catalog.db, catalog_cache.json.gz, version.json
│   ├── progress/             # Historial de visualización por usuario
│   └── watchlist/            # Listas de seguimiento guardadas
├── includes/
│   ├── bootstrap.php         # Inicializador del sistema
│   └── templates/            # Header, footer, modales de Stremio/OAuth
├── lib/
│   ├── MoviePlusService.php  # Criptografía AES, SQLite PDO, auto-updates
│   ├── ProviderManager.php   # Orquestador de proveedores
│   ├── providers/            # Scrapers individuales
│   └── resolvers/            # Decodificadores de servidores de video
├── tools/
│   └── torrent-streamer/     # Microservicio Node.js (WebTorrent + Stremio Addon)
└── infinityfree-deploy.zip   # Paquete final listo para subir a InfinityFree
```

---

## 7. Validación de Embeds, Filtro por Idioma y Prevención de Bloqueos (Actualización Reciente)

### 7.1. Filtrado de Servidores Caídos / Muertos
- **Lista Negra de Dominios Caídos:** En `lib/utils.php` (`is_dead_stream_domain`) se descartan dominios con error 500 permanente (`player.cuevana-3.mx`, `player.poseidonhd2.co`), DNS caídos (`goodstream.one`), o servicios cerrados (`fembed`, `uptobox`, `streamz`, `animeav1`).
- **Verificación Rápida de Salud (cURL Range):** `ResolverManager` y resolvers dedicados (`VoeResolver`, `StreamWishResolver`, `VidHideResolver`, `FastreamResolver`, `YandexDiskResolver`, `CuevanaResolver`) verifican encabezados y respuestas para detectar `404 Not Found` y páginas de archivo eliminado ("file was deleted").
- **API `resolve_stream.php`:** Devuelve `status: unavailable`, `available: false` para que el frontend no intente reproducir servidores muertos.
- **Auto-fallback Inteligente:** En el reproductor (`assets/js/details_loader.js`), si un servidor responde como caído, su botón se marca visualmente con tachado y badge `Caído`, y el modal salta automáticamente al siguiente servidor disponible sin que el usuario tenga que recargar la página.

### 7.2. Filtro y Selección Interactiva de Idiomas
- **Normalización de Idiomas:** En `lib/utils.php` (`normalize_stream_language`), cada fuente se clasifica con código estándar (`lat`, `cast`, `sub`, `eng`, `dual`), bandera (🇲🇽, 🇪🇸, 💬, 🇺🇸, ✨) y etiqueta clara.
- **Barra de Filtros por Idioma:** Se implementó `.stream-lang-filter-bar` sobre la lista de servidores en `details_loader.js`. El usuario puede filtrar al instante solo servidores en su idioma preferido (`🇲🇽 Latino`, `🇪🇸 Castellano`, `💬 Subtitulado`, `🌐 Todos`), recordando su selección en `localStorage`.

### 7.3. Prevención de Bloqueos en el Navegador
- **Política de Referrer:** Se añadió `referrerpolicy="no-referrer"` en `playerIframe` para evitar que servidores de video rechacen conexiones con HTTP 403 por encabezado Referer.
- **Botón "Otro Servidor" y Watchdog:** Cabecera con `#btnNextServerModal` para alternar servidores en un clic. Watchdog de 4.5s que activa `#playerBlockedBanner` con botón de salto o apertura externa si el navegador/adblocker bloquea el iframe.

### 7.4. Precisión de Temporadas y Capítulos en Anime (AnimeFLV, LatAnime, TioAnime, JKAnime)
- **Causa Raíz Diagnosticada:** Los sitios de anime guardan las series bajo sus títulos Romaji (ej: *Youjo Senki* en vez de *Saga of Tanya the Evil*, *Shingeki no Kyojin* en vez de *Attack on Titan*, *Kimetsu no Yaiba* en vez de *Demon Slayer*) y organizan las secuelas con números romanos en el slug (`youjo-senki-ii`, `overlord-iv`). Anteriormente, la regex de temporadas solo buscaba `-s2` o `season-2`, ignorando `-ii`, `-iii` y sufijos numéricos. Esto causaba que la búsqueda de la Temporada 1 tomase erróneamente la primera secuela que aparecía en la API (`youjo-senki-ii/1`).
- **Enriquecimiento con Títulos Alternativos de TMDB:** Si `$tmdb_id` está presente, los scrapers (`AnimeFLVProvider`, `LatAnimeProvider`, `TioAnimeProvider`, `JKAnimeProvider`) consultan `/tv/{id}/alternative_titles` y `original_name` para inyectar automáticamente los nombres Romaji y Japoneses oficiales en las consultas.
- **Filtro de Películas y Especiales:** Se descartan de forma estricta slugs con palabras como `movie`, `pelicula`, `ova`, `ona`, `special` para peticiones de series episódicas regulares.
- **Mapeo de Números Romanos y Temporadas:** Se introdujo detección estricta de números romanos (`ii`=2, `iii`=3, `iv`=4, `v`=5, `vi`=6, etc.) y sufijos (`-2`, `-3`, `2nd`). Temporada 1 selecciona únicamente el slug base limpio (`youjo-senki`), y Temporada 2 selecciona la secuela correspondiente (`youjo-senki-ii`).
- **Series Tradicionales (No Anime):** Proveedores como `MoviePlusProvider`, `SeriesKaoProvider`, `PelisPlusProvider` utilizan URLs canónicas `/temporada/{season}/capitulo/{episode}` y IDs numéricos de TMDB, por lo que nunca sufrieron de este problema.

---

*Este archivo sirve como brújula técnica permanente para cualquier mantenimiento, despliegue o ampliación de la plataforma.*


