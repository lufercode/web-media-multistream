import crypto from 'crypto';

const TMDB_API_KEY = process.env.TMDB_API_KEY || 'aaccf8f89c76c5a7ac753e3a8402de56';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

export const ALL_STREMIO_PROVIDERS = [
    { id: 'movieplus', name: 'Movie+ (Cloud CDN / Yandex / Mega)', category: 'streaming', default: true },
    { id: 'lamovie', name: 'LaMovie / AllCalidad (HLS Directo)', category: 'streaming', default: true },
    { id: 'latanime', name: 'LatAnime (Anime Español Latino)', category: 'streaming', default: true },
    { id: 'jkanime', name: 'JKAnime (Anime Latino/Sub — HLS Directo)', category: 'streaming', default: true },
    { id: 'animeflv', name: 'AnimeFLV (Anime Latino/Sub — HLS Directo)', category: 'streaming', default: true },
    { id: 'serieskao', name: 'SeriesKao (Embed69 HLS Directo)', category: 'streaming', default: true },
    { id: 'pelisplus', name: 'PelisPlusHD (Películas, Series y Anime)', category: 'streaming', default: true },
    { id: 'cuevana', name: 'Cuevana 3 (Multi-Servidor HLS)', category: 'streaming', default: true },
    { id: 'poseidonhd', name: 'PoseidonHD (Películas y Series)', category: 'streaming', default: true },
    { id: 'cinecalidad', name: 'Cinecalidad (Latino Dual 1080p/4K)', category: 'mixed', default: true },
    { id: 'local_cdn', name: 'CDN Propio (Rakun Cloud Directo)', category: 'direct', default: true },
    { id: 'torrentio', name: 'Torrentio Multi-Indexer (Latino/Multi)', category: 'torrent', default: true },
    { id: 'tpb', name: 'ThePirateBay (Torrents P2P)', category: 'torrent', default: true },
    { id: 'yts', name: 'YTS (Películas 1080p / 4K P2P)', category: 'torrent', default: true },
    { id: 'nyaa', name: 'Nyaa.si (Anime P2P)', category: 'torrent', default: true }
];

const STREMIO_TRACKER_SOURCES = [
    'tracker:udp://tracker.opentrackr.org:1337/announce',
    'tracker:udp://open.stealth.si:80/announce',
    'tracker:udp://tracker.torrent.eu.org:451/announce',
    'tracker:udp://exodus.desync.com:6969/announce',
    'tracker:udp://open.demonii.com:1337/announce',
    'tracker:http://nyaa.tracker.wf:7777/announce',
    'tracker:http://tracker.opentrackr.org:1337/announce',
    'dht'
];

// Caché en memoria ligera para respuestas TMDB y streams (evita repetir scraping en pocos minutos)
const tmdbCache = new Map();
const streamCache = new Map();

function getCached(map, key, ttlMs) {
    const entry = map.get(key);
    if (!entry) return null;
    if (Date.now() - entry.ts > ttlMs) {
        map.delete(key);
        return null;
    }
    return entry.val;
}

function setCached(map, key, val, maxEntries = 200) {
    if (map.size >= maxEntries) {
        const firstKey = map.keys().next().value;
        if (firstKey) map.delete(firstKey);
    }
    map.set(key, { ts: Date.now(), val });
}

/**
 * Parsea la configuración de scrapers e idiomas desde la URL del addon:
 * Formatos soportados:
 * - "default" o vacío -> todos los scrapers e idiomas activos
 * - "p-lamovie.latanime.torrentio_l-latino.castellano" (formato seguro para enlaces stremio://)
 * - "providers=lamovie,latanime|lang=latino,castellano"
 */
export function parseAddonConfig(rawConfig = '') {
    const defaultProviders = ALL_STREMIO_PROVIDERS.map(p => p.id);
    const defaultLangs = ['latino', 'castellano', 'multi', 'sub'];
    const validProvIds = new Set(defaultProviders);
    const validLangIds = new Set(defaultLangs);

    if (!rawConfig || rawConfig === 'default' || rawConfig === 'all') {
        return { providers: new Set(defaultProviders), langs: new Set(defaultLangs), raw: 'default' };
    }

    let decoded = rawConfig;
    try {
        decoded = decodeURIComponent(rawConfig);
    } catch (e) {}

    const providers = new Set();
    const langs = new Set();

    const addProv = (raw) => {
        const clean = (raw === 'anime') ? 'jkanime' : raw;
        if (validProvIds.has(clean)) providers.add(clean);
    };
    const addLang = (raw) => {
        if (validLangIds.has(raw)) langs.add(raw);
    };

    // Formato seguro: p-lamovie.latanime.torrentio_l-latino.multi
    // (Usar delimitador no codicioso antes de _l- / _p- para no mezclar el último scraper con _l-...)
    const safeProvMatch = decoded.match(/(?:^|[_|&])p-([a-z0-9._-]+?)(?=[_|&]l-|$)/i);
    const safeLangMatch = decoded.match(/(?:^|[_|&])l-([a-z0-9._-]+?)(?=[_|&]p-|$)/i);

    if (safeProvMatch) {
        safeProvMatch[1].split('.').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(addProv);
    }
    if (safeLangMatch) {
        safeLangMatch[1].split('.').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(addLang);
    }

    // Formato clásico: providers=a,b|lang=x,y
    const kvProvMatch = decoded.match(/providers=([a-z0-9,_-]+)/i);
    const kvLangMatch = decoded.match(/langs?=([a-z0-9,_-]+)/i);

    if (kvProvMatch) {
        kvProvMatch[1].split(',').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(addProv);
    }
    if (kvLangMatch) {
        kvLangMatch[1].split(',').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(addLang);
    }

    // Si solo pasaron lista separada por comas o puntos
    if (providers.size === 0 && !safeProvMatch && !kvProvMatch) {
        const parts = decoded.split(/[,.]/).map(s => s.trim().toLowerCase()).filter(Boolean);
        parts.forEach(addProv);
    }

    return {
        providers: providers.size > 0 ? providers : new Set(defaultProviders),
        langs: langs.size > 0 ? langs : new Set(defaultLangs),
        raw: rawConfig
    };
}

export function buildManifest(configStr = '', baseUrl = '') {
    const cfg = parseAddonConfig(configStr);
    const activeNames = ALL_STREMIO_PROVIDERS
        .filter(p => cfg.providers.has(p.id))
        .map(p => p.name.split(' (')[0]);

    const langLabels = [];
    if (cfg.langs.has('latino')) langLabels.push('🇲🇽 Español Latino');
    if (cfg.langs.has('castellano')) langLabels.push('🇪🇸 Castellano');
    if (cfg.langs.has('multi')) langLabels.push('🌐 Multi-Audio');
    if (cfg.langs.has('sub')) langLabels.push('🇯🇵/🇺🇸 Subtitulado');

    return {
        id: 'org.streammedia.multistream.latino',
        version: '2.7.0',
        name: 'StreamMedia Latino & Multi-Scraper',
        description: `Addon Multi-Fuente (${activeNames.join(', ')}). Idiomas: ${langLabels.join(', ')}. Combina Streaming HLS Directo (.m3u8) y Torrents P2P nativos para Películas, Series y Anime.`,
        logo: 'https://cdn-icons-png.flaticon.com/512/2503/2503508.png',
        background: 'https://images.unsplash.com/photo-1574375927938-d5a98e8ffe85?auto=format&fit=crop&w=1400&q=80',
        resources: ['catalog', 'stream'],
        types: ['movie', 'series', 'anime'],
        idPrefixes: ['tt', 'tmdb:'],
        catalogs: [
            {
                id: 'streammedia_trending_movies',
                type: 'movie',
                name: '🇲🇽 Películas en Español (Trending)',
                extra: [{ name: 'skip', isRequired: false }]
            },
            {
                id: 'streammedia_trending_series',
                type: 'series',
                name: '🇲🇽 Series en Español (Trending)',
                extra: [{ name: 'skip', isRequired: false }]
            },
            {
                id: 'streammedia_anime_latino',
                type: 'anime',
                name: '🐉 Anime en Español Latino (Trending)',
                extra: [{ name: 'skip', isRequired: false }]
            }
        ],
        behaviorHints: {
            configurable: true,
            configurationRequired: false
        }
    };
}


async function httpGet(url, options = {}) {
    const timeoutMs = options.timeout || 5000;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const headers = {
            'User-Agent': UA,
            'Accept': options.accept || 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json,*/*;q=0.8',
            'Accept-Language': 'es-MX,es;q=0.9,en-US;q=0.8,en;q=0.7',
            ...(options.headers || {})
        };
        const res = await fetch(url, {
            method: options.method || 'GET',
            headers,
            signal: controller.signal,
            redirect: 'follow'
        });
        if (!res.ok) return null;
        if (options.headOnly) return true;
        if (options.json) return await res.json();
        return await res.text();
    } catch (e) {
        return null;
    } finally {
        clearTimeout(timer);
    }
}

function normalizeText(str = '') {
    return str
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function isTitleMatch(queryTitle, candTitle, queryYear = null, candYear = null) {
    const q = normalizeText(queryTitle);
    const c = normalizeText(candTitle);
    if (!q || !c) return false;
    if (queryYear && candYear && Math.abs(parseInt(queryYear, 10) - parseInt(candYear, 10)) > 1) {
        return false;
    }
    if (q === c) return true;
    if (c.includes(q) || q.includes(c)) {
        const qWords = q.split(' ').filter(Boolean);
        const cWords = c.split(' ').filter(Boolean);
        const minRatio = Math.min(qWords.length, cWords.length) / Math.max(qWords.length, cWords.length);
        if (minRatio >= 0.5 || qWords[0] === cWords[0]) return true;
    }
    return false;
}

/**
 * Desempaquetador de scripts Dean Edwards Packer: eval(function(p,a,c,k,e,d)...)
 * Usado por Vimeos (LaMovie), StreamWish, VidHide y Filemoon.
 */
function unpackDeanEdwards(script) {
    const m = script.match(/\}\s*\(\s*['"](.*)['"]\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*['"]([^'"]+)['"]\.split\(\s*['"]\|['"]\s*\)/s);
    if (!m) return script;

    const payload = m[1];
    const base = parseInt(m[2], 10);
    const words = m[4].split('|');
    const chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    return payload.replace(/\b\w+\b/g, (word) => {
        let val = 0;
        for (let i = 0; i < word.length; i++) {
            const pos = chars.indexOf(word[i]);
            if (pos === -1 || pos >= base) return word;
            val = val * base + pos;
        }
        return (words[val] !== undefined && words[val] !== '') ? words[val] : word;
    });
}

/**
 * Decodifica el payload cifrado de Voe (<script type="application/json">):
 * ROT13 -> limpieza de delimitadores -> Base64 -> Caesar(-3) -> Reverse -> Base64 -> JSON
 */
function decodeVoeJsonPayload(rawJson) {
    try {
        const arr = JSON.parse(rawJson);
        if (!Array.isArray(arr) || typeof arr[0] !== 'string') return null;
        let s = arr[0];
        s = s.replace(/[a-zA-Z]/g, c => {
            const base = c <= 'Z' ? 65 : 97;
            return String.fromCharCode(((c.charCodeAt(0) - base + 13) % 26) + base);
        });
        s = s.replace(/@\$|\^\^|~@|%\?|\*~|!!|#&/g, '');
        const dec1 = Buffer.from(s, 'base64').toString('utf8');
        let shifted = '';
        for (let i = 0; i < dec1.length; i++) {
            shifted += String.fromCharCode(dec1.charCodeAt(i) - 3);
        }
        const rev = shifted.split('').reverse().join('');
        const finalStr = Buffer.from(rev, 'base64').toString('utf8');
        return JSON.parse(finalStr);
    } catch (e) {
        return null;
    }
}

/**
 * Resuelve un embed de Zilla / Vimeos / StreamWish / VidHide / Voe / MP4Upload / Goodstream en un enlace directo .m3u8 o .mp4
 * para que el reproductor nativo de Stremio lo reproduzca directamente sin pasar por Render.
 */
async function resolveEmbedToHls(embedUrl, customReferer = '') {
    if (!embedUrl || !embedUrl.startsWith('http')) return null;
    const lower = embedUrl.toLowerCase();

    try {
        // 0. Zilla Networks (usado por AnimeFLV / AnimeAV1 como servidor HLS primario, resolución instantánea 0ms)
        const zillaMatch = embedUrl.match(/player\.zilla-networks\.com\/play\/([a-f0-9]{32})/i);
        if (zillaMatch) {
            return {
                url: `https://player.zilla-networks.com/m3u8/${zillaMatch[1]}`,
                referer: 'https://player.zilla-networks.com/'
            };
        }

        const parsed = new URL(embedUrl);
        const host = `${parsed.protocol}//${parsed.host}`;

        // 1. Vimeos / StreamWish / VidHide / Goodstream / Fastream
        if (/(?:vimeos|streamwish|hlswish|sfastwish|wishfast|strwish|swhoi|wishembed|embedwish|hglink|dwish|awish|mwish|flaswish|obeywish|cdnwish|asnwish|luluvdoo|vide0|vidhide|vidhidevip|vidguard|filelions|streamhide|morencius|kinotv|dramiyos|earnvids|smoothpre|dhtpre|peytonepre|ryderjet|goodstream|fastream)/i.test(lower)) {
            let targetUrl = embedUrl;
            let effectiveHost = host;
            let referer = customReferer || `${host}/`;
            const isVidHide = /(?:vidhide|vidhidevip|vidguard|filelions|streamhide|morencius|kinotv|dramiyos|earnvids|smoothpre|dhtpre|peytonepre)/i.test(lower);
            const isStreamWish = /(?:streamwish|hlswish|sfastwish|wishfast|strwish|swhoi|wishembed|embedwish|hglink|dwish|awish|mwish|flaswish|obeywish|cdnwish|asnwish)/i.test(lower);

            if (lower.includes('vimeos')) {
                const vm = embedUrl.match(/(?:embed-|\/d\/|\/e\/|\/)([a-zA-Z0-9]{8,20})(?:_[a-z])?(?:\.html)?/i);
                if (vm) targetUrl = `${host}/embed-${vm[1]}.html`;
                referer = 'https://lamovie.org/';
            } else if (isVidHide) {
                const segs = parsed.pathname.split('/').filter(Boolean);
                let id = segs[segs.length - 1];
                if (!id || id.length < 5 || ['embed', 'v', 'd', 'e', 'watch'].includes(id.toLowerCase())) {
                    const mId = embedUrl.match(/(?:embed|v|d|e)\/([a-zA-Z0-9]+)/i);
                    if (mId) id = mId[1];
                }
                if (id) targetUrl = `${host}/v/${id}`;
                referer = targetUrl;
            } else if (isStreamWish) {
                const segs = parsed.pathname.split('/').filter(Boolean);
                let id = segs[segs.length - 1]?.replace(/\.html$/i, '');
                if (!id || id.length < 5 || ['embed', 'v', 'd', 'e', 'f'].includes(id.toLowerCase())) {
                    const mId = embedUrl.match(/(?:embed-|embed\/|v\/|d\/|e\/|f\/)([a-zA-Z0-9]+)/i);
                    if (mId) id = mId[1];
                }
                if (id) {
                    effectiveHost = 'https://sfastwish.com';
                    targetUrl = `${effectiveHost}/e/${id}`;
                    referer = `${effectiveHost}/`;
                }
            } else if (!lower.includes('/e/') && !lower.includes('embed-')) {
                const segs = parsed.pathname.split('/').filter(Boolean);
                const id = segs[segs.length - 1];
                if (id && id.length >= 5) targetUrl = `${host}/e/${id}`;
                referer = targetUrl;
            }

            const html = await httpGet(targetUrl, {
                timeout: 4500,
                headers: { Referer: referer }
            });
            if (!html) return null;

            const directM3u8 = html.match(/["'](https?:\/\/[^"']+\.m3u8[^"']*)["']/i);
            if (directM3u8) {
                return { url: directM3u8[1], referer: `${effectiveHost}/` };
            }

            const packedBlocks = html.match(/eval\(function\(p,a,c,k,e,[rd]\).*?\.split\(['"]\|['"]\)\)\)/gs) || [];
            for (const block of packedBlocks) {
                const unpacked = unpackDeanEdwards(block);
                const m3 = unpacked.match(/(https?:\/\/[^\s"'<>]+\.m3u8[^\s"'<>]*)/i);
                if (m3) {
                    return { url: m3[1], referer: `${effectiveHost}/` };
                }
                const relM3 = unpacked.match(/["'](\/stream\/[^"']+\.m3u8[^"']*)["']/i);
                if (relM3) {
                    return { url: `${effectiveHost}${relM3[1]}`, referer: `${effectiveHost}/` };
                }
            }
        }

        // 2. Voe.sx y espejos dinámicos (sigue redirección JS window.location.href y decodifica application/json)
        if (/(?:voe\.sx|voesx|jilliandescribecompany|christopheruntilpoint|walterprettytheir|catherineupdated|megankiddifference)/i.test(lower)) {
            let voeUrl = embedUrl;
            let voeHost = host;
            let html = await httpGet(voeUrl, { timeout: 3800, headers: { Referer: customReferer || `${host}/` } });
            if (!html) return null;

            const jsRedir = html.match(/window\.location\.href\s*=\s*['"](https?:\/\/[^'"]+)['"]/i);
            if (jsRedir) {
                voeUrl = jsRedir[1];
                try {
                    const p2 = new URL(voeUrl);
                    voeHost = `${p2.protocol}//${p2.host}`;
                } catch (e) {}
                html = await httpGet(voeUrl, { timeout: 3800, headers: { Referer: embedUrl } });
                if (!html) return null;
            }

            const jsonScript = html.match(/<script[^>]*type=["']application\/json["'][^>]*>(.*?)<\/script>/is);
            if (jsonScript) {
                const decoded = decodeVoeJsonPayload(jsonScript[1]);
                if (decoded?.source && decoded.source.startsWith('http')) {
                    return { url: decoded.source, referer: `${voeHost}/` };
                }
                if (decoded?.direct_access_url && decoded.direct_access_url.startsWith('http')) {
                    return { url: decoded.direct_access_url, referer: `${voeHost}/` };
                }
            }

            const b64Match = html.match(/['"]hls['"]\s*:\s*['"]([a-zA-Z0-9+/=]+)['"]/i);
            if (b64Match) {
                const dec = Buffer.from(b64Match[1], 'base64').toString('utf8');
                if (dec.startsWith('http')) return { url: dec, referer: `${voeHost}/` };
            }
            const hlsMatch = html.match(/['"]hls['"]\s*:\s*['"](https?:\/\/[^'"]+)['"]/i) ||
                             html.match(/(https?:\/\/[^\s"'<>]+\.m3u8[^\s"'<>]*)/i);
            if (hlsMatch && !hlsMatch[1].includes('test-videos.co.uk')) {
                return { url: hlsMatch[1], referer: `${voeHost}/` };
            }
        }

        // 3. MP4Upload (extracción directa de .mp4)
        if (lower.includes('mp4upload.com')) {
            const html = await httpGet(embedUrl, {
                timeout: 4000,
                headers: { Referer: 'https://www.mp4upload.com/' }
            });
            if (!html) return null;
            const mp4Match = html.match(/src:\s*["'](https?:\/\/[^"']+\.mp4[^"']*)["']/i) ||
                             html.match(/["'](https?:\/\/[^"']*mp4upload\.com[^"']*\/video\.mp4[^"']*)["']/i);
            if (mp4Match) {
                return { url: mp4Match[1], referer: 'https://www.mp4upload.com/' };
            }
        }
    } catch (e) {}

    return null;
}

/**
 * Resuelve metadatos en TMDB a partir del ID de Stremio (ej. "tt6263850" o "tt9679542:4:15" o "tmdb:12345:1:2")
 */
export async function resolveTmdbMetadata(type, rawId) {
    const cacheKey = `${type}:${rawId}`;
    const cached = getCached(tmdbCache, cacheKey, 30 * 60 * 1000);
    if (cached) return cached;

    const parts = rawId.replace(/\.json$/i, '').split(':');
    let imdbId = null;
    let tmdbId = null;
    let season = 1;
    let episode = 1;

    if (parts[0] === 'tmdb') {
        tmdbId = parseInt(parts[1], 10);
        season = parts[2] ? parseInt(parts[2], 10) : 1;
        episode = parts[3] ? parseInt(parts[3], 10) : 1;
    } else {
        imdbId = parts[0];
        season = parts[1] ? parseInt(parts[1], 10) : 1;
        episode = parts[2] ? parseInt(parts[2], 10) : 1;
    }

    const isMovie = (type === 'movie');
    let titleEs = '';
    let titleOrig = '';
    let titleEn = '';
    let year = '';
    let isAnime = false;
    let absoluteEpisode = null;

    if (imdbId && imdbId.startsWith('tt')) {
        const [findEs, findEn] = await Promise.all([
            httpGet(`https://api.themoviedb.org/3/find/${imdbId}?api_key=${TMDB_API_KEY}&external_source=imdb_id&language=es-MX`, { json: true, timeout: 4000 }),
            httpGet(`https://api.themoviedb.org/3/find/${imdbId}?api_key=${TMDB_API_KEY}&external_source=imdb_id&language=en-US`, { json: true, timeout: 4000 })
        ]);

        const itemEs = isMovie
            ? (findEs?.movie_results?.[0] || findEs?.tv_results?.[0])
            : (findEs?.tv_results?.[0] || findEs?.movie_results?.[0]);
        const itemEn = isMovie
            ? (findEn?.movie_results?.[0] || findEn?.tv_results?.[0])
            : (findEn?.tv_results?.[0] || findEn?.movie_results?.[0]);

        if (itemEs) {
            tmdbId = itemEs.id;
            titleEs = itemEs.title || itemEs.name || '';
            titleOrig = itemEs.original_title || itemEs.original_name || '';
            const dateStr = itemEs.release_date || itemEs.first_air_date || '';
            year = dateStr ? dateStr.substring(0, 4) : '';
            const genres = itemEs.genre_ids || [];
            if (genres.includes(16)) isAnime = true;
        }
        if (itemEn) {
            titleEn = itemEn.title || itemEn.name || '';
            if (!tmdbId) tmdbId = itemEn.id;
            if (!titleEs) titleEs = titleEn;
        }
    }

    if (tmdbId && (!titleEs || !isMovie)) {
        const endpoint = isMovie ? 'movie' : 'tv';
        const detailsEs = await httpGet(`https://api.themoviedb.org/3/${endpoint}/${tmdbId}?api_key=${TMDB_API_KEY}&language=es-MX`, { json: true, timeout: 4000 });
        if (detailsEs) {
            if (!titleEs) titleEs = detailsEs.title || detailsEs.name || '';
            if (!titleOrig) titleOrig = detailsEs.original_title || detailsEs.original_name || '';
            const dateStr = detailsEs.release_date || detailsEs.first_air_date || '';
            if (!year && dateStr) year = dateStr.substring(0, 4);
            if ((detailsEs.genres || []).some(g => g.id === 16)) isAnime = true;

            if (!isMovie && !imdbId) {
                const extIds = await httpGet(`https://api.themoviedb.org/3/tv/${tmdbId}/external_ids?api_key=${TMDB_API_KEY}`, { json: true, timeout: 3500 });
                if (extIds?.imdb_id) imdbId = extIds.imdb_id;
            }

            // Calcular episodio absoluto si es serie/anime en temporada >= 2
            if (!isMovie && season >= 2) {
                const seasons = detailsEs.seasons || [];
                const validSeasons = seasons.filter(s => s.season_number > 0 && s.season_number < season);
                if (validSeasons.length > 0) {
                    const prevCount = validSeasons.reduce((acc, s) => acc + (s.episode_count || 0), 0);
                    if (prevCount > 0) absoluteEpisode = prevCount + episode;
                } else if (isAnime) {
                    // Animes con 1 sola temporada en TMDB pero divididos en Episode Groups (ej. Dr. Stone)
                    const egList = await httpGet(`https://api.themoviedb.org/3/tv/${tmdbId}/episode_groups?api_key=${TMDB_API_KEY}`, { json: true, timeout: 3500 });
                    const results = egList?.results || [];
                    const bestGroup = results.find(g => g.type === 6) || results[0];
                    if (bestGroup?.id) {
                        const egDetail = await httpGet(`https://api.themoviedb.org/3/tv/episode_group/${bestGroup.id}?api_key=${TMDB_API_KEY}`, { json: true, timeout: 3500 });
                        const groups = (egDetail?.groups || []).filter(g => g.order > 0 && !/special|especial/i.test(g.name || ''));
                        groups.sort((a, b) => a.order - b.order);
                        const targetGroup = groups[season - 1];
                        const epObj = targetGroup?.episodes?.[episode - 1];
                        if (epObj?.episode_number) {
                            absoluteEpisode = epObj.episode_number;
                        }
                    }
                }
            }
        }
    }

    const meta = {
        type: isMovie ? 'movie' : 'series',
        imdbId,
        tmdbId,
        title: titleEs || titleEn || titleOrig,
        originalTitle: titleOrig || titleEn || titleEs,
        englishTitle: titleEn || titleOrig || titleEs,
        year,
        season,
        episode,
        absoluteEpisode,
        isAnime
    };

    if (meta.tmdbId || meta.imdbId) {
        setCached(tmdbCache, cacheKey, meta);
    }
    return meta;
}

/**
 * Clasifica etiquetas de idioma de servidores de streaming (ej. "Japonés - Subtítulos Latino", "SUB", "JAP", "Latino")
 * evitando confundir "Subtítulos Latino" con "Audio Español Latino".
 */
function classifyStreamingLangTag(rawLang = 'LAT', isAnime = false) {
    const r = String(rawLang || 'LAT').trim();
    if (/(?:sub|vose|vost|japon|jap|ingl|eng|original|^2$|^3$)/i.test(r)) {
        const hasLatSub = /lat|esp/i.test(r);
        const isJap = Boolean(isAnime || /(?:japon|jap|^3$)/i.test(r));
        const origFlag = isJap ? '🇯🇵' : '🇺🇸';
        const origAudio = isJap ? '🇯🇵 Audio Japonés' : '🇺🇸 Audio Original';
        return {
            langKey: 'sub',
            flag: origFlag,
            langBadge: hasLatSub ? `${origAudio} + 🇲🇽 Sub Latino` : `${origFlag} Subtitulado`
        };
    }
    if ((/(?:castellano|cast|^esp$|^1$)/i.test(r) || (/espa[ñn]ol/i.test(r) && !/lat/i.test(r)))) {
        return {
            langKey: 'castellano',
            flag: '🇪🇸',
            langBadge: '🇪🇸 Audio Castellano'
        };
    }
    return {
        langKey: 'latino',
        flag: '🇲🇽',
        langBadge: '🇲🇽 Audio Español Latino'
    };
}

/**
 * 1. Scraper: LaMovie / AllCalidad (https://lamovie.org/wp-api/v1 + Fallback v2)
 */
async function scrapeLaMovie(meta) {
    const fastApi = 'https://lamovie.org/wp-api/v1';
    const headers = { Referer: 'https://lamovie.org/', Origin: 'https://lamovie.org', Accept: 'application/json' };
    const candidateEmbeds = [];
    const streams = [];

    const labelTitle = meta.type === 'movie'
        ? `${meta.title} (${meta.year || 'HD'})`
        : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;

    // A. Intentar API oficial actual de LaMovie (wp-api/v1)
    const queries = Array.from(new Set([meta.title, meta.originalTitle].filter(Boolean)));
    let matchedPost = null;

    for (const q of queries.slice(0, 2)) {
        const searchData = await httpGet(`${fastApi}/search?filter=%5B%5D&postType=any&q=${encodeURIComponent(q)}&postsPerPage=12`, { json: true, headers, timeout: 4200 });
        const posts = searchData?.data?.posts || [];
        for (const p of posts) {
            const pType = p.type || '';
            const isMoviePost = pType === 'movies';
            if (meta.type === 'movie' && !isMoviePost) continue;
            if (meta.type !== 'movie' && isMoviePost) continue;

            const cleanCand = String(p.title || '').replace(/\s*\((?:19|20)\d{2}\).*/, '').trim();
            const candYear = String(p.title || '').match(/\((19\d{2}|20\d{2})\)/)?.[1] || null;
            if (isTitleMatch(meta.title, cleanCand, meta.year, candYear) || isTitleMatch(meta.originalTitle, cleanCand, meta.year, candYear)) {
                matchedPost = p;
                break;
            }
        }
        if (matchedPost) break;
    }

    if (matchedPost?._id) {
        let targetPostId = null;
        if (meta.type === 'movie') {
            targetPostId = matchedPost._id;
        } else {
            let epListData = await httpGet(`${fastApi}/single/episodes/list?_id=${matchedPost._id}&season=${meta.season}&page=1&postsPerPage=50`, { json: true, headers, timeout: 4000 });
            let epPosts = epListData?.data?.posts || [];
            let epObj = epPosts.find(e => Number(e.episode_number) === Number(meta.episode));

            if (!epObj && meta.season >= 2 && meta.absoluteEpisode) {
                epListData = await httpGet(`${fastApi}/single/episodes/list?_id=${matchedPost._id}&season=1&page=1&postsPerPage=100`, { json: true, headers, timeout: 4000 });
                epPosts = epListData?.data?.posts || [];
                epObj = epPosts.find(e => Number(e.episode_number) === Number(meta.absoluteEpisode));
            }
            if (epObj?._id) targetPostId = epObj._id;
        }

        if (targetPostId) {
            const playerData = await httpGet(`${fastApi}/player?postId=${targetPostId}&demo=0`, { json: true, headers, timeout: 4200 });
            const embeds = playerData?.data?.embeds || [];
            const fallbackRawLang = embeds.find(e => e?.lang && String(e.lang).trim() !== '')?.lang || 'Latino';
            for (const emb of embeds) {
                if (!emb.url || !emb.url.startsWith('http') || emb.url.includes('videoapp.zip')) continue;
                const langInfo = classifyStreamingLangTag(emb.lang || fallbackRawLang, meta.isAnime);
                let srvName = 'HLS';
                if (/vimeos/i.test(emb.url)) srvName = 'Vimeos';
                else if (/goodstream/i.test(emb.url)) srvName = 'Goodstream';
                else if (/wish/i.test(emb.url)) srvName = 'StreamWish';
                else if (/voe/i.test(emb.url)) srvName = 'Voe';
                else if (/vidhide|filelions/i.test(emb.url)) srvName = 'VidHide';
                candidateEmbeds.push({
                    url: emb.url,
                    server: srvName,
                    langKey: langInfo.langKey,
                    flag: langInfo.flag,
                    langBadge: langInfo.langBadge,
                    quality: emb.quality || '1080p HD'
                });
            }

            // Si LaMovie incluye torrents en downloads, inspeccionar también el nombre dentro del magnet y la calidad (ej. "Dual 1080p" / "1080P-Dual-Lat")
            for (const dl of (playerData?.data?.downloads || [])) {
                const rawDlUrl = String(dl.url || '');
                const magM = rawDlUrl.match(/magnet:\?xt=urn:btih:([a-fA-F0-9]{40})/i);
                if (magM) {
                    let decMag = rawDlUrl;
                    try { decMag = decodeURIComponent(rawDlUrl); } catch (e) {}
                    const torrentCheck = classifyTorrentLanguage(`${decMag} ${dl.quality || ''}`, meta.isAnime);
                    const langInfo = (torrentCheck.langKey === 'latino' || torrentCheck.langKey === 'castellano')
                        ? { langKey: torrentCheck.langKey, flag: torrentCheck.flag, langBadge: torrentCheck.badge }
                        : classifyStreamingLangTag(dl.lang || fallbackRawLang, meta.isAnime);
                    const dnMatch = decMag.match(/[?&]dn=([^&]+)/i);
                    const flagLine = (langInfo.langKey === 'castellano') ? '🇪🇸 Spanish' : 'Dual Audio / 🇲🇽';
                    const formattedTitle = formatTorrentioTitle({
                        releaseTitle: releaseName,
                        seeders: 45,
                        size: '2.05 GB',
                        tracker: 'LaMovie P2P',
                        flagLine
                    });
                    streams.push({
                        name: `${langInfo.flag} LaMovie P2P\n${dl.quality || '1080p'}`,
                        title: formattedTitle,
                        infoHash: magM[1].toLowerCase(),
                        sources: STREMIO_TRACKER_SOURCES,
                        _lang: langInfo.langKey,
                        _prov: 'lamovie_p2p',
                        _seeders: 45
                    });
                }
            }
        }
    }

    // B. Respaldo API tmdb.allcalidad.re/v1 si aún no hay candidatos
    if (candidateEmbeds.length === 0 && meta.tmdbId) {
        const apiBase = 'https://tmdb.allcalidad.re/v1';
        const itemsToProcess = [];
        if (meta.type === 'movie') {
            const data = await httpGet(`${apiBase}/items/movie/${meta.tmdbId}`, { json: true, headers, timeout: 3500 });
            if (data?.item) itemsToProcess.push(data.item);
        } else {
            const kinds = meta.isAnime ? ['anime', 'tvshow'] : ['tvshow', 'anime'];
            for (const kind of kinds) {
                let epData = await httpGet(`${apiBase}/items/${kind}/${meta.tmdbId}/seasons/${meta.season}/episodes/${meta.episode}`, { json: true, headers, timeout: 3500 });
                if (!epData?.episode && meta.season >= 2 && meta.absoluteEpisode) {
                    epData = await httpGet(`${apiBase}/items/${kind}/${meta.tmdbId}/seasons/1/episodes/${meta.absoluteEpisode}`, { json: true, headers, timeout: 3500 });
                }
                if (epData?.episode) {
                    itemsToProcess.push(epData.episode);
                    break;
                }
            }
        }
        for (const item of itemsToProcess) {
            const code = (item.code || '').trim();
            if (code) {
                const langInfo = classifyStreamingLangTag(item.lang || 'Latino', meta.isAnime);
                candidateEmbeds.push({
                    url: `https://vimeos.net/embed-${code}.html`,
                    server: 'Vimeos',
                    langKey: langInfo.langKey,
                    flag: langInfo.flag,
                    langBadge: langInfo.langBadge,
                    quality: '1080p HD'
                });
            }
        }
    }

    const resolvable = candidateEmbeds.filter(e => /(?:vimeos|streamwish|hlswish|wish|voe|vidhide|goodstream)/i.test(e.url)).slice(0, 5);
    const resolvedList = await Promise.all(resolvable.map(async (emb) => {
        const resolved = await resolveEmbedToHls(emb.url, 'https://lamovie.org/');
        if (!resolved?.url) return null;
        return {
            name: `${emb.flag} LaMovie\n1080p HD`,
            title: `${labelTitle}\n⚡ ${emb.server || 'HLS'} Directo • ${emb.langBadge}`,
            url: resolved.url,
            _lang: emb.langKey,
            behaviorHints: {
                notWebReady: false,
                bingeGroup: `streammedia-lamovie-${emb.langKey}`,
                proxyHeaders: {
                    request: {
                        'Referer': resolved.referer || 'https://lamovie.org/',
                        'User-Agent': UA
                    }
                }
            }
        };
    }));

    streams.push(...resolvedList.filter(Boolean));
    return streams;
}

/**
 * Descifra el desafío Proof-of-Work SHA-256 + AES-256-CBC de Embed69 / Vidurl (usado por SeriesKao y PelisPlusHD)
 */
function decryptEmbed69DataLink(html, isAnime = false) {
    const mC = html.match(/POW_CHALLENGE\s*=\s*'([^']+)'/);
    const mD = html.match(/POW_DIFFICULTY\s*=\s*(\d+)/);
    const mS = html.match(/POW_SALT\s*=\s*'([^']+)'/);
    const mDl = html.match(/dataLink\s*=\s*(\[.*?\]);/s);
    if (!mC || !mD || !mS || !mDl) return [];

    const challenge = mC[1];
    const difficulty = parseInt(mD[1], 10);
    const salt = mS[1];
    const prefix = '0'.repeat(difficulty);

    let aesKey = null;
    for (let nonce = 0; nonce <= 500000; nonce++) {
        const hashHex = crypto.createHash('sha256').update(challenge + nonce).digest('hex');
        if (hashHex.startsWith(prefix)) {
            aesKey = crypto.createHash('sha256').update(challenge + nonce + salt).digest();
            break;
        }
    }
    if (!aesKey) return [];

    let dataLink = [];
    try {
        dataLink = JSON.parse(mDl[1]);
    } catch (e) {
        return [];
    }

    const extracted = [];
    for (const group of dataLink) {
        const langInfo = classifyStreamingLangTag(group.video_language || 'LAT', isAnime);

        for (const emb of (group.sortedEmbeds || [])) {
            if (!emb.link) continue;
            try {
                const rawBuf = Buffer.from(emb.link, 'base64');
                if (rawBuf.length < 17) continue;
                const iv = rawBuf.subarray(0, 16);
                const ciphertext = rawBuf.subarray(16);
                const decipher = crypto.createDecipheriv('aes-256-cbc', aesKey, iv);
                const decrypted = Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8').trim();
                if (decrypted.startsWith('http')) {
                    extracted.push({
                        url: decrypted,
                        server: emb.servername || 'Server',
                        langKey: langInfo.langKey,
                        flag: langInfo.flag,
                        langBadge: langInfo.langBadge
                    });
                }
            } catch (e) {}
        }
    }
    return extracted;
}

/**
 * 2. Scraper: SeriesKao & PelisPlusHD (con soporte de Películas, Series y Anime + Embed69 PoW)
 */
async function scrapeKaoOrPelisPlus(meta, providerId = 'serieskao') {
    const host = providerId === 'pelisplus' ? 'https://pelisplushd.bz' : 'https://serieskao.top';
    const providerLabel = providerId === 'pelisplus' ? 'PelisPlus' : 'SeriesKao';

    const queries = Array.from(new Set([meta.title, meta.originalTitle].filter(Boolean)));
    let matchedUrl = null;

    for (const q of queries) {
        const searchUrl = `${host}/search?s=${encodeURIComponent(q)}`;
        const html = await httpGet(searchUrl, { headers: { Referer: `${host}/` }, timeout: 4500 });
        if (!html || html.length < 500) continue;

        const cards = html.match(/<article class="card".*?<\/article>/gs) || [];
        for (const card of cards) {
            const mUrl = card.match(/href="([^"]+)"/);
            const mTitle = card.match(/<h2 class="card__title">([^<]+)<\/h2>/);
            if (!mUrl || !mTitle) continue;

            const href = mUrl[1];
            const isMovieCard = href.includes('/pelicula/') || href.includes('/movie/');
            const isSeriesCard = href.includes('/serie/') || href.includes('/series/') || href.includes('/anime/') || href.includes('/animes/');

            if (meta.type === 'movie' && !isMovieCard) continue;
            if (meta.type !== 'movie' && !isSeriesCard) continue;

            const cleanCand = mTitle[1].replace(/\s*\((?:19|20)\d{2}\).*/, '').replace(/\[.*?\]/g, '').trim();
            if (isTitleMatch(meta.title, cleanCand) || isTitleMatch(meta.originalTitle, cleanCand)) {
                matchedUrl = href.startsWith('http') ? href : `${host}/${href.replace(/^\//, '')}`;
                break;
            }
        }
        if (matchedUrl) break;
    }

    if (!matchedUrl) return [];

    let targetPageUrl = matchedUrl.replace(/\/$/, '');
    let pageHtml = null;

    if (meta.type === 'movie') {
        pageHtml = await httpGet(targetPageUrl, { headers: { Referer: `${host}/` }, timeout: 4500 });
    } else {
        const epUrl1 = `${targetPageUrl}/temporada/${meta.season}/capitulo/${meta.episode}`;
        pageHtml = await httpGet(epUrl1, { headers: { Referer: targetPageUrl }, timeout: 4500 });
        if ((!pageHtml || pageHtml.length < 1000) && meta.season >= 2 && meta.absoluteEpisode) {
            const epUrl2 = `${targetPageUrl}/temporada/1/capitulo/${meta.absoluteEpisode}`;
            pageHtml = await httpGet(epUrl2, { headers: { Referer: targetPageUrl }, timeout: 4500 });
        }
    }

    if (!pageHtml) return [];

    const candidateEmbeds = [];

    // Extraer iframe Embed69 / Vidurl
    const mIframe = pageHtml.match(/<iframe[^>]*id="player-iframe"[^>]*src="([^"]+)"/i) ||
                    pageHtml.match(/<iframe[^>]*src="([^"]+(?:vidurl|embed69)[^"]+)"/i);
    if (mIframe) {
        let iframeUrl = mIframe[1];
        if (iframeUrl.startsWith('//')) iframeUrl = `https:${iframeUrl}`;
        else if (!iframeUrl.startsWith('http')) iframeUrl = `${host}${iframeUrl}`;

        const vidHtml = await httpGet(iframeUrl, { headers: { Referer: targetPageUrl }, timeout: 4500 });
        if (vidHtml) {
            const decryptedList = decryptEmbed69DataLink(vidHtml, meta.isAnime);
            candidateEmbeds.push(...decryptedList);
        }
    }

    // Extraer botones go_to_player base64
    const goMatches = pageHtml.matchAll(/<li[^>]*onclick="go_to_player\s*\(\s*['"]([^'"]+)['"][^>]*>/gi);
    for (const m of goMatches) {
        try {
            const dec = Buffer.from(m[1], 'base64').toString('utf8').trim();
            if (dec.startsWith('http')) {
                const rawLi = m[0];
                const langM = rawLi.match(/data-lang="([^"]+)"/i);
                const langInfo = classifyStreamingLangTag(langM ? langM[1] : 'LAT', meta.isAnime);
                candidateEmbeds.push({ url: dec, server: 'Server', langKey: langInfo.langKey, flag: langInfo.flag, langBadge: langInfo.langBadge });
            }
        } catch (e) {}
    }

    // Resolver hasta 4 embeds en paralelo a .m3u8 directo
    const resolvable = candidateEmbeds.filter(e => /(?:vidhide|morencius|streamwish|hglink|wish|voe|vimeos|goodstream|mp4upload)/i.test(e.url)).slice(0, 4);
    const resolvedStreams = await Promise.all(resolvable.map(async (emb) => {
        const res = await resolveEmbedToHls(emb.url, targetPageUrl);
        if (!res?.url) return null;
        const flag = emb.flag || (emb.langKey === 'castellano' ? '🇪🇸' : (emb.langKey === 'sub' ? (meta.isAnime ? '🇯🇵' : '🇺🇸') : '🇲🇽'));
        const labelTitle = meta.type === 'movie'
            ? `${meta.title} (${meta.year || 'HD'})`
            : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;
        return {
            name: `${flag} ${providerLabel}\n1080p HD`,
            title: `${labelTitle}\n⚡ HLS Directo • ${emb.langBadge}`,
            url: res.url,
            _lang: emb.langKey,
            behaviorHints: {
                notWebReady: false,
                proxyHeaders: {
                    request: {
                        'Referer': res.referer || `${host}/`,
                        'User-Agent': UA
                    }
                }
            }
        };
    }));

    return resolvedStreams.filter(Boolean);
}

/**
 * 3. Scraper: LatAnime (https://latanime.org) - Especializado en Anime en Español Latino y Castellano
 */
async function scrapeLatAnime(meta) {
    if (!meta.isAnime && meta.type === 'movie') return [];
    const host = 'https://latanime.org';
    const baseQueries = Array.from(new Set([meta.title, meta.originalTitle].filter(Boolean)));
    const searchQueries = [];
    for (const q of baseQueries) {
        if (meta.season >= 2) searchQueries.push(`${q} ${meta.season}`);
        searchQueries.push(q);
    }

    const streams = [];
    for (const q of searchQueries.slice(0, 3)) {
        const html = await httpGet(`${host}/buscar?q=${encodeURIComponent(q)}`, { headers: { Referer: `${host}/` }, timeout: 4500 });
        if (!html || html.length < 500) continue;

        const cardLinks = [...html.matchAll(/<a[^>]+href="(https?:\/\/latanime\.org\/anime\/[^"]+)"[^>]*>(.*?)<\/a>/gis)];
        for (const m of cardLinks) {
            const animeUrl = m[1];
            const inner = m[2];
            const h3 = inner.match(/<h3[^>]*>([^<]+)<\/h3>/i);
            if (!h3) continue;
            const rawTitle = h3[1].trim();
            const baseCand = rawTitle.replace(/\b(?:latino|castellano|subtitulado|s\d+|temporada\s*\d+|ova|pelicula)\b.*/i, '').trim() || rawTitle;

            if (!isTitleMatch(meta.title, baseCand) && !isTitleMatch(meta.originalTitle, baseCand)) continue;

            const isLatino = /latino/i.test(rawTitle) || /latino/i.test(animeUrl);
            const isCastellano = /castellano/i.test(rawTitle) || /castellano/i.test(animeUrl);
            const langKey = isCastellano ? 'castellano' : (isLatino ? 'latino' : 'sub');
            const langBadge = isCastellano ? '🇪🇸 Castellano' : (isLatino ? '🇲🇽 Español Latino' : '🇯🇵 Subtitulado');
            const flag = isCastellano ? '🇪🇸' : (isLatino ? '🇲🇽' : '🇯🇵');

            const slug = animeUrl.split('/anime/')[1]?.replace(/\/$/, '');
            if (!slug) continue;

            // Si pide temporada >= 2 y la tarjeta es de otra temporada específica distinta, saltar
            const sMatch = slug.match(/(?:temporada-|s)(\d+)/i);
            if (meta.season >= 2 && sMatch && parseInt(sMatch[1], 10) !== meta.season) continue;
            if (meta.season === 1 && sMatch && parseInt(sMatch[1], 10) > 1) continue;

            const epCandidates = [meta.episode];
            if (meta.season >= 2 && meta.absoluteEpisode && meta.absoluteEpisode !== meta.episode) {
                epCandidates.unshift(meta.absoluteEpisode);
            }

            for (const epNum of epCandidates) {
                const epUrl = `${host}/ver/${slug}-episodio-${epNum}`;
                const epHtml = await httpGet(epUrl, { headers: { Referer: animeUrl }, timeout: 4200 });
                if (!epHtml || epHtml.length < 1500) continue;

                const b64Players = [...epHtml.matchAll(/data-player="([^"]+)"[^>]*>(.*?)<\/a>/gis)];
                const toResolve = [];
                for (const pm of b64Players) {
                    try {
                        const decUrl = Buffer.from(pm[1], 'base64').toString('utf8').trim();
                        const srvLabel = pm[2].replace(/<[^>]+>/g, '').trim() || 'HLS';
                        if (decUrl.startsWith('http') && /(?:voe|wish|hglink|vidhide|morencius|vimeos|luluvdoo)/i.test(decUrl)) {
                            toResolve.push({ url: decUrl, serverName: srvLabel });
                        }
                    } catch (e) {}
                }

                const resolvedList = await Promise.all(toResolve.slice(0, 3).map(async (item) => {
                    const r = await resolveEmbedToHls(item.url, epUrl);
                    if (!r?.url) return null;
                    return {
                        name: `${flag} LatAnime\n1080p HD`,
                        title: `${rawTitle} - Ep. ${epNum}\n⚡ ${item.serverName} HLS • ${langBadge}`,
                        url: r.url,
                        _lang: langKey,
                        behaviorHints: {
                            notWebReady: false,
                            proxyHeaders: {
                                request: {
                                    'Referer': r.referer || `${host}/`,
                                    'User-Agent': UA
                                }
                            }
                        }
                    };
                }));

                streams.push(...resolvedList.filter(Boolean));
                if (streams.length > 0) break;
            }
        }
        if (streams.length > 0) break;
    }

    return streams;
}

/**
 * 4. Scraper: Cuevana 3 / PoseidonHD (__NEXT_DATA__ JSON)
 */
async function scrapeCuevanaOrPoseidon(meta, providerId = 'cuevana') {
    const host = providerId === 'poseidonhd' ? 'https://poseidonhd2.co' : 'https://www.cuevana-3.mx';
    const providerLabel = providerId === 'poseidonhd' ? 'PoseidonHD' : 'Cuevana 3';

    const queries = Array.from(new Set([meta.title, meta.originalTitle].filter(Boolean)));
    let matchedItem = null;

    for (const q of queries.slice(0, 2)) {
        const searchUrl = `${host}/search?q=${encodeURIComponent(q)}`;
        const html = await httpGet(searchUrl, { timeout: 4500 });
        if (!html) continue;

        const mNext = html.match(/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s);
        if (!mNext) continue;

        let nextData = null;
        try {
            nextData = JSON.parse(mNext[1]);
        } catch (e) {
            continue;
        }

        const items = [
            ...(nextData?.props?.pageProps?.movies || []),
            ...(nextData?.props?.pageProps?.series || [])
        ];

        for (const item of items) {
            const rawSlug = item?.url?.slug || '';
            const isMovieItem = rawSlug.startsWith('movies/') || rawSlug.startsWith('pelicula/');
            if (meta.type === 'movie' && !isMovieItem) continue;
            if (meta.type !== 'movie' && isMovieItem) continue;

            if (meta.tmdbId && Number(item.TMDbId) === Number(meta.tmdbId)) {
                matchedItem = item;
                break;
            }
            const candTitle = item?.titles?.name || '';
            if (isTitleMatch(meta.title, candTitle) || isTitleMatch(meta.originalTitle, candTitle)) {
                matchedItem = item;
                break;
            }
        }
        if (matchedItem) break;
    }

    if (!matchedItem) return [];

    const rawSlug = matchedItem.url.slug;
    let detailUrl = '';
    if (meta.type === 'movie') {
        detailUrl = `${host}/${rawSlug.replace(/^movies\//, 'pelicula/')}`;
    } else {
        const serieBase = rawSlug.replace(/^series\//, 'serie/');
        detailUrl = `${host}/${serieBase}/temporada/${meta.season}/episodio/${meta.episode}`;
    }

    const detHtml = await httpGet(detailUrl, { timeout: 4500 });
    if (!detHtml) return [];

    const mDet = detHtml.match(/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s);
    if (!mDet) return [];

    let detJson = null;
    try {
        detJson = JSON.parse(mDet[1]);
    } catch (e) {
        return [];
    }

    const props = detJson?.props?.pageProps || {};
    const videos = props?.thisMovie?.videos || props?.episode?.videos || {};
    const candidateServers = [];

    for (const [langGroup, list] of Object.entries(videos)) {
        if (!Array.isArray(list) || list.length === 0) continue;
        const langKey = langGroup === 'latino' ? 'latino' : (langGroup === 'spanish' ? 'castellano' : 'sub');
        const langBadge = langKey === 'latino'
            ? '🇲🇽 Audio Español Latino'
            : (langKey === 'castellano'
                ? '🇪🇸 Audio Castellano'
                : (meta.isAnime ? '🇯🇵 Audio Japonés + 🇲🇽 Sub Latino' : '🇺🇸 Audio Original + 🇲🇽 Sub Latino'));

        // Seleccionar hasta 2 servidores resolubles (VidHide, StreamWish, Voe) por cada idioma disponible
        let addedForLang = 0;
        for (const srv of list) {
            if (addedForLang >= 2) break;
            const target = srv.result || srv.url;
            if (target && /(?:streamwish|wish|hglink|vidhide|morencius|voe)/i.test(target + ' ' + (srv.cyberlocker || ''))) {
                candidateServers.push({ url: target, cyberlocker: srv.cyberlocker || 'HLS', langKey, langBadge });
                addedForLang++;
            }
        }
    }

    const resolved = await Promise.all(candidateServers.slice(0, 6).map(async (srv) => {
        // Algunos enlaces de Cuevana/Poseidon pasan por un redirector intermedio (player.cuevana-3.mx / player.poseidonhd2.co)
        let embedTarget = srv.url;
        if (!/(?:streamwish|wish|hglink|vidhide|morencius|voe)/i.test(embedTarget)) {
            const interHtml = await httpGet(embedTarget, { headers: { Referer: detailUrl }, timeout: 3500 });
            const mRedir = interHtml?.match(/var\s+url\s*=\s*['"](https?:\/\/[^'"]+)['"]/i) ||
                           interHtml?.match(/location\.href\s*=\s*['"](https?:\/\/[^'"]+)['"]/i);
            if (mRedir) embedTarget = mRedir[1];
        }

        const r = await resolveEmbedToHls(embedTarget, detailUrl);
        if (!r?.url) return null;
        const flag = srv.langKey === 'latino' ? '🇲🇽' : (srv.langKey === 'castellano' ? '🇪🇸' : (meta.isAnime ? '🇯🇵' : '🇺🇸'));
        const labelTitle = meta.type === 'movie'
            ? `${meta.title} (${meta.year || 'HD'})`
            : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;
        return {
            name: `${flag} ${providerLabel}\n1080p HD`,
            title: `${labelTitle}\n⚡ ${srv.cyberlocker} HLS • ${srv.langBadge}`,
            url: r.url,
            _lang: srv.langKey,
            behaviorHints: {
                notWebReady: false,
                proxyHeaders: {
                    request: {
                        'Referer': r.referer || `${host}/`,
                        'User-Agent': UA
                    }
                }
            }
        };
    }));

    return resolved.filter(Boolean);
}

/**
 * 5. Scraper: CDN Propio (Rakun Cloud Directo)
 */
async function scrapeLocalCdn(meta) {
    if (!meta.tmdbId) return [];
    const base = 'https://cdn.rakun.site/file/rakun-cloud';
    const candidates = meta.type === 'movie'
        ? [`${base}/movies/${meta.tmdbId}/movie.mp4`, `${base}/movies/${meta.tmdbId}/movie.mkv`]
        : [
            `${base}/tv/${meta.tmdbId}/s${meta.season}/e${meta.episode}.mp4`,
            `${base}/tv/${meta.tmdbId}/s${meta.season}/e${meta.episode}.mkv`
        ];

    for (const url of candidates) {
        const exists = await httpGet(url, { method: 'HEAD', headOnly: true, timeout: 2500 });
        if (exists) {
            return [{
                name: `🇲🇽 CDN Propio\n1080p Directo`,
                title: `${meta.title}\n⚡ Rakun Cloud Directo • 🇲🇽 Español Latino`,
                url,
                _lang: 'latino',
                behaviorHints: { notWebReady: false }
            }];
        }
    }
    return [];
}

// =========================================================================
// MOVIE+ CLOUD CDN INTEGRATION (AES-256 Decryption + Yandex CDN + Firestore)
// =========================================================================

const MOVIEPLUS_AES_GCM_KEY = Buffer.from([0x47, 0x40, 0x68, 0x66, 0x4d, 0x74, 0x5e, 0x65, 0x73, 0x65, 0x5e, 0x79, 0x6d, 0x43, 0x6e, 0x78, 0x5c, 0x59, 0x69, 0x5b, 0x5d, 0x6d, 0x62, 0x74, 0x59, 0x74, 0x7f, 0x69, 0x51, 0x2e, 0x2b, 0x08]);
const MOVIEPLUS_AES_ECB_KEY = Buffer.from('android_idfkvn8 w4y*(NC$G*(G($*G', 'utf8');

function decryptMoviePlusEcb(b64) {
    try {
        const raw = Buffer.from(b64, 'base64');
        if (raw.length < 16) return b64;
        const decipher = crypto.createDecipheriv('aes-256-ecb', MOVIEPLUS_AES_ECB_KEY, null);
        decipher.setAutoPadding(true);
        let dec = decipher.update(raw, null, 'utf8');
        dec += decipher.final('utf8');
        return dec.trim();
    } catch {
        return b64;
    }
}

function decryptMoviePlusGcm(b64) {
    try {
        const raw = Buffer.from(b64, 'base64');
        if (raw.length < 28) return b64;
        const iv = raw.subarray(0, 12);
        const tag = raw.subarray(raw.length - 16);
        const ct = raw.subarray(12, raw.length - 16);
        const decipher = crypto.createDecipheriv('aes-256-gcm', MOVIEPLUS_AES_GCM_KEY, iv);
        decipher.setAuthTag(tag);
        let dec = decipher.update(ct, null, 'utf8');
        dec += decipher.final('utf8');
        return dec.trim();
    } catch {
        return b64;
    }
}

function decryptMoviePlusUrl(cipher) {
    if (!cipher || typeof cipher !== 'string') return '';
    if (cipher.startsWith('http://') || cipher.startsWith('https://')) return cipher;
    if (cipher.startsWith('Movp!::')) {
        const gcm = decryptMoviePlusGcm(cipher.slice(7));
        if (gcm.startsWith('http')) return gcm;
    }
    const dec = decryptMoviePlusEcb(cipher);
    if (dec.startsWith('Movp!::')) {
        const gcm = decryptMoviePlusGcm(dec.slice(7));
        if (gcm.startsWith('http')) return gcm;
    }
    if (dec.length > 20 && dec.endsWith('=') && !dec.startsWith('http')) {
        const second = decryptMoviePlusEcb(dec);
        if (second.startsWith('http')) return second;
    }
    return dec;
}

function normalizeTitleForMoviePlus(str) {
    if (!str) return '';
    return str.toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function parseMoviePlusLanguage(rawStr) {
    const u = String(rawStr || '').toUpperCase();
    if (/\b(DUALC)\b/.test(u)) return { langKey: 'castellano', flag: '🇪🇸', badge: 'Audio Dual (Castellano/Inglés)' };
    if (/\b(TRIAL)\b/.test(u)) return { langKey: 'multi', flag: '🌐', badge: 'Triple Audio (Latino + Castellano + Inglés)' };
    if (/\b(DUAL)\b/.test(u)) return { langKey: 'latino', flag: '🇲🇽', badge: 'Audio Dual (Latino + Inglés)' };
    if (/\b(CASTELLANO|CAST|ESP)\b/.test(u)) return { langKey: 'castellano', flag: '🇪🇸', badge: 'Español Castellano' };
    if (/\b(SUBTITULADO|SUB|VOSE)\b/.test(u)) return { langKey: 'sub', flag: '💬', badge: 'Subtitulado al Español' };
    if (/\b(LATINO|LAT)\b/.test(u)) return { langKey: 'latino', flag: '🇲🇽', badge: 'Español Latino' };
    if (/\b(INGLES|ENG|ENGLISH)\b/.test(u)) return { langKey: 'sub', flag: '🇬🇧', badge: 'Audio Inglés (Original)' };
    if (/\b(JAPONES|JAPON|JPN|JAP)\b/.test(u)) return { langKey: 'sub', flag: '🇯🇵', badge: 'Audio Japonés (Original)' };
    return { langKey: 'latino', flag: '🇲🇽', badge: 'Español Latino' };
}

function cleanMoviePlusQuality(raw) {
    const cleaned = String(raw || '')
        .replace(/\b(DUALC|TRIAL|DUAL|CASTELLANO|CAST|ESP|SUBTITULADO|SUB|VOSE|LATINO|LAT|INGLES|ENG|JAPONES|JPN|JAP)\b/gi, '')
        .replace(/\s+/g, ' ')
        .trim();
    return cleaned || 'HD';
}

// Caché de resolución de enlaces Yandex Disk
const yandexDownloadCache = new Map();

async function resolveYandexDownloadUrl(yandexUrl) {
    if (!yandexUrl || !yandexUrl.includes('yadi.sk')) return null;
    const cached = getCached(yandexDownloadCache, yandexUrl, 30 * 60 * 1000);
    if (cached) return cached;

    try {
        const apiUrl = `https://cloud-api.yandex.net/v1/disk/public/resources/download?public_key=${encodeURIComponent(yandexUrl)}`;
        const res = await httpGet(apiUrl, { json: true, timeout: 4500 });
        if (res?.href) {
            setCached(yandexDownloadCache, yandexUrl, res.href);
            return res.href;
        }
    } catch {
        // Silencioso
    }
    return null;
}

// Carga en memoria del catálogo local de Movie+ (<40 MB heap, inicialización asíncrona)
let moviePlusCatalogLoaded = false;
let moviePlusLoadingPromise = null;
const moviePlusMoviesById = new Map();
const moviePlusMoviesByTitle = new Map();

async function initMoviePlusCatalog() {
    if (moviePlusCatalogLoaded) return;
    if (moviePlusLoadingPromise) return moviePlusLoadingPromise;

    moviePlusLoadingPromise = (async () => {
        try {
            const { fileURLToPath } = await import('url');
            const { dirname, resolve } = await import('path');
            const fs = await import('fs');
            const zlib = await import('zlib');

            const here = dirname(fileURLToPath(import.meta.url));
            const candidatePaths = [
                resolve(here, 'catalog_cache.json.gz'),
                resolve(here, '../../data/movieplus/catalog_cache.json.gz'),
                resolve(process.cwd(), 'data/movieplus/catalog_cache.json.gz'),
                resolve(process.cwd(), 'catalog_cache.json.gz')
            ];

            let gzBuffer = null;
            for (const p of candidatePaths) {
                if (fs.existsSync(p)) {
                    try {
                        gzBuffer = fs.readFileSync(p);
                        break;
                    } catch {}
                }
            }

            // Descarga de respaldo si no existe archivo local
            if (!gzBuffer) {
                const downloadUrls = [
                    'https://raw.githubusercontent.com/dev-mplus01/mplus01T/main/db/list/items_up_r1792047394384.json.gz',
                    'https://raw.githubusercontent.com/dev-mplus01/mplus01T/main/db/items_up.json.gz'
                ];
                for (const dUrl of downloadUrls) {
                    try {
                        const r = await fetch(dUrl, { headers: { 'User-Agent': UA } });
                        if (r.ok) {
                            gzBuffer = Buffer.from(await r.arrayBuffer());
                            break;
                        }
                    } catch {}
                }
            }

            if (gzBuffer) {
                const decompressed = zlib.gunzipSync(gzBuffer);
                const catalog = JSON.parse(decompressed);
                if (Array.isArray(catalog?.movies)) {
                    for (const m of catalog.movies) {
                        if (!m?.id) continue;
                        const idNum = Number(m.id);
                        const normTitle = normalizeTitleForMoviePlus((m.nameFile || '') + ' ' + (m.othersNames || ''));
                        const itemObj = {
                            id: idNum,
                            title: m.nameFile || '',
                            date: m.date || '',
                            urls: Array.isArray(m.urls) ? m.urls : []
                        };
                        moviePlusMoviesById.set(idNum, itemObj);
                        if (normTitle && !moviePlusMoviesByTitle.has(normTitle)) {
                            moviePlusMoviesByTitle.set(normTitle, itemObj);
                        }
                    }
                }
                moviePlusCatalogLoaded = true;
                console.log(`[Movie+ Addon] Catálogo en memoria listo: ${moviePlusMoviesById.size} películas indexadas.`);
            }
        } catch (err) {
            console.warn('[Movie+ Addon] Advertencia al cargar catálogo en memoria:', err.message);
        } finally {
            moviePlusLoadingPromise = null;
        }
    })();

    return moviePlusLoadingPromise;
}

// Scraper principal de Movie+ para Stremio
async function scrapeMoviePlus(meta, baseUrl = '') {
    if (!meta.tmdbId && !meta.title) return [];
    const streams = [];
    const effectiveBaseUrl = (baseUrl || '').replace(/\/+$/, '');

    try {
        if (meta.type === 'movie') {
            await initMoviePlusCatalog();
            let movie = null;
            if (meta.tmdbId) {
                movie = moviePlusMoviesById.get(Number(meta.tmdbId));
            }
            if (!movie && meta.title) {
                const norm = normalizeTitleForMoviePlus(meta.title);
                movie = moviePlusMoviesByTitle.get(norm);
                if (!movie && meta.originalTitle) {
                    movie = moviePlusMoviesByTitle.get(normalizeTitleForMoviePlus(meta.originalTitle));
                }
            }

            if (!movie || !Array.isArray(movie.urls) || movie.urls.length === 0) {
                return [];
            }

            const rawUrls = movie.urls.slice(0, 6);
            for (const entry of rawUrls) {
                if (typeof entry !== 'string') continue;
                const parts = entry.split(',_,');
                let rawQuality = '1080p HD';
                let sizeStr = '';
                let cipher = entry;

                if (parts.length >= 3) {
                    rawQuality = parts[0].trim();
                    sizeStr = parts[1].trim() && parts[1].trim() !== 'N/A' ? ` • 💾 ${parts[1].trim()}` : '';
                    cipher = parts[2].trim();
                }

                const decryptedUrl = decryptMoviePlusUrl(cipher);
                if (!decryptedUrl || !decryptedUrl.startsWith('http')) continue;

                let directDownloadUrl = decryptedUrl;
                let serverLabel = 'Directo';

                if (decryptedUrl.includes('yadi.sk')) {
                    serverLabel = 'Yandex CDN';
                    const resolved = await resolveYandexDownloadUrl(decryptedUrl);
                    if (resolved) {
                        directDownloadUrl = resolved;
                    }
                } else if (decryptedUrl.includes('mega.nz')) {
                    serverLabel = 'Mega.nz';
                }

                const langInfo = parseMoviePlusLanguage(rawQuality);
                const cleanQ = cleanMoviePlusQuality(rawQuality);

                const streamUrl = effectiveBaseUrl
                    ? `${effectiveBaseUrl}/movieplus-stream?url=${encodeURIComponent(directDownloadUrl)}`
                    : directDownloadUrl;

                streams.push({
                    name: `${langInfo.flag} Movie+\n${cleanQ}`,
                    title: `${meta.title} (${meta.year || 'HD'})\n⚡ Cloud CDN (${serverLabel}) • ${langInfo.badge}${sizeStr}`,
                    url: streamUrl,
                    _lang: langInfo.langKey,
                    _prov: 'movieplus',
                    behaviorHints: {
                        notWebReady: false,
                        bingeGroup: `streammedia-movieplus-${langInfo.langKey}`,
                        proxyHeaders: {
                            request: {
                                'User-Agent': UA,
                                'Accept': '*/*'
                            }
                        }
                    }
                });
            }
        } else if (meta.type === 'series') {
            if (!meta.tmdbId) return [];

            const apiKey = 'AIzaSyBgn47se6RS2ezDtTf4Jz58W2f8PhYO_4g';
            const firestoreUrl = `https://firestore.googleapis.com/v1/projects/dev-movieplus/databases/(default)/documents/tv/${meta.tmdbId}?key=${apiKey}`;
            const firestoreDoc = await httpGet(firestoreUrl, { json: true, timeout: 4500 });
            const fields = firestoreDoc?.fields;
            if (!fields) return [];

            const seasonRegex = new RegExp(`\\b(?:Temporada|Season)\\s*0?${meta.season}\\b`, 'i');
            const targetSeasonKeys = Object.keys(fields).filter(k => seasonRegex.test(k));
            if (targetSeasonKeys.length === 0) return [];

            const epRegex = new RegExp(`(?:S\\d{1,2}E0?${meta.episode}|E0?${meta.episode}|Cap[ií]tulo\\s*0?${meta.episode})\\b`, 'i');

            for (const sKey of targetSeasonKeys) {
                const values = fields[sKey]?.arrayValue?.values || [];
                for (const val of values) {
                    const str = val?.stringValue;
                    if (!str || !epRegex.test(str)) continue;

                    const cleanStr = str.replace(/^["'\s]+|["'\s]+$/g, '');
                    const parts = cleanStr.split('_SPLIT_');
                    if (parts.length < 2) continue;

                    const epTitle = parts[0].trim();
                    const cipher = parts[1].trim();
                    const bytes = parts[2] ? parseFloat(parts[2]) : 0;
                    let sizeStr = '';
                    if (bytes > 0) {
                        const gb = bytes / (1024 * 1024 * 1024);
                        sizeStr = gb >= 1 ? ` • 💾 ${gb.toFixed(2)} GB` : ` • 💾 ${(bytes / (1024 * 1024)).toFixed(0)} MB`;
                    }

                    const decryptedUrl = decryptMoviePlusUrl(cipher);
                    if (!decryptedUrl || !decryptedUrl.startsWith('http')) continue;

                    let directDownloadUrl = decryptedUrl;
                    let serverLabel = 'Directo';

                    if (decryptedUrl.includes('yadi.sk')) {
                        serverLabel = 'Yandex CDN';
                        const resolved = await resolveYandexDownloadUrl(decryptedUrl);
                        if (resolved) {
                            directDownloadUrl = resolved;
                        }
                    }

                    const langInfo = parseMoviePlusLanguage(epTitle);
                    const streamUrl = effectiveBaseUrl
                        ? `${effectiveBaseUrl}/movieplus-stream?url=${encodeURIComponent(directDownloadUrl)}`
                        : directDownloadUrl;

                    streams.push({
                        name: `${langInfo.flag} Movie+\n1080p HD`,
                        title: `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}\n⚡ Cloud CDN (${serverLabel}) • ${langInfo.badge}${sizeStr}`,
                        url: streamUrl,
                        _lang: langInfo.langKey,
                        _prov: 'movieplus',
                        behaviorHints: {
                            notWebReady: false,
                            bingeGroup: `streammedia-movieplus-${langInfo.langKey}`,
                            proxyHeaders: {
                                request: {
                                    'User-Agent': UA,
                                    'Accept': '*/*'
                                }
                            }
                        }
                    });
                }
            }
        }
    } catch (err) {
        console.warn('[Movie+ Addon] Error scrapeMoviePlus:', err.message);
    }

    return streams;
}

/**
 * Clasifica el idioma real de un torrent de Torrentio/TPB/Nyaa evitando confundir
 * banderas o etiquetas de SUBTÍTULOS (Multi-Subs / Sub Latino / Sub Español) con el AUDIO.
 */
function classifyTorrentLanguage(rawTitle = '', isAnime = false) {
    const fullText = String(rawTitle || '');
    const firstLine = fullText.split('\n')[0] || fullText;

    // 1. Detectar primero releases de grupos franceses donde MULTi = Francés + VO (NO incluye Español)
    const isFrenchRelease = /\b(?:vf|vff|vfi|vfq|vf2|vostfr|french|truefrench|subfrench|tsundere[\s._-]*raws|tenmaland|t3kashi|kaf)\b/i.test(fullText);
    const hasExplicitLatToken = /\b(?:audio[\s._-]*lat(?:ino)?|doblaje[\s._-]*lat(?:ino)?|dual[\s._-]*lat(?:ino)?|latam|es[\s._-]*mx|es[\s._-]*419|cinecalidad)\b/i.test(fullText);

    if (isFrenchRelease && !hasExplicitLatToken) {
        const isVfAudio = /\b(?:multi|vf|vff|vfi|french|truefrench)\b/i.test(fullText);
        return {
            langKey: 'sub',
            flag: '🇫🇷',
            badge: isVfAudio ? '🇫🇷 Audio Francés / VO (VF)' : '🇫🇷 Sub Francés (VOSTFR)'
        };
    }

    const hasMxFlag = fullText.includes('🇲🇽');
    const hasEsFlag = fullText.includes('🇪🇸');
    const hasJpFlag = fullText.includes('🇯🇵');
    const isJapanese = Boolean(isAnime || hasJpFlag || /\b(?:jap|japanese|jpn|nyaa|crunchyroll)\b/i.test(fullText));
    const origFlag = isJapanese ? '🇯🇵' : '🇺🇸';

    const isMultiSubs = /\b(?:multi[\s._-]*subs?|multisub|sub(?:s|titles?|titulos?)?[\s._-]*multi(?:ple)?)\b/i.test(fullText);
    const isMultiAudio = /\b(?:multi[\s._-]*audio|multi[\s._-]*dub|cr[\s._-]*web-?dl[\s._-]*multi|multi[\s._-]+(?:\d{3,4}p[\s._-]+)?cr[\s._-]*web-?dl)\b/i.test(fullText);
    const isDualAudio = /\b(?:dual[\s._-]*audio|cr[\s._-]*web-?dl[\s._-]*dual|\bdual\b)\b/i.test(fullText);
    const isDubbed = /\b(?:dubbed|doblado)\b/i.test(fullText);

    // Detectar si menciona explícitamente Subtítulos en Español / Latino
    const hasExplicitSpanishSubs = /\b(?:sub(?:s|titulos?|titulado|titles?)?[\s._-]*(?:esp(?:a[ñn]ol)?[\s._-]*)?(?:lat(?:ino|am)?|es[\s._-]*mx|es[\s._-]*419|cast(?:ellano)?)|(?:lat(?:ino|am)?|esp(?:a[ñn]ol)?|spanish)[\s._-]*sub(?:s|titulos?|titulado|titles?)?|sub[\s._-]*(?:esp|spa|es))\b/i.test(fullText);

    // Neutralizar todas las menciones a subtítulos antes de buscar palabras de AUDIO ("latino", "español", "castellano")
    const audioCleanText = fullText
        .replace(/\b(?:multi[\s._-]*subs?|multisub|sub(?:s|titles?|titulos?)?[\s._-]*multi(?:ple)?)\b/gi, ' ')
        .replace(/\bsub(?:s|titulos?|titulado|titles?)?[\s._-]*(?:en[\s._-]*)?(?:esp(?:a[ñn]ol)?[\s._-]*)?(?:lat(?:ino|am)?|es[\s._-]*mx|es[\s._-]*419|cast(?:ellano)?)\b/gi, ' ')
        .replace(/\b(?:lat(?:ino|am)?|esp(?:a[ñn]ol)?|spanish|cast(?:ellano)?)[\s._-]*sub(?:s|titulos?|titulado|titles?)?\b/gi, ' ')
        .replace(/\bsub(?:s|titulos?|titulado|titles?)?[\s._-]*(?:esp(?:a[ñn]ol)?|spa|spanish|es)\b/gi, ' ');

    const explicitLatinoAudio = /\b(?:latino|latam|audio[\s._-]*lat(?:ino)?|doblaje[\s._-]*lat(?:ino)?|dual[\s._-]*lat(?:ino)?|es[\s._-]*mx|es[\s._-]*419|esp[\s._-]*lat(?:ino)?|spa[\s._-]*lat(?:ino)?|cinecalidad)\b/i.test(audioCleanText);
    const explicitCastellanoAudio = /\b(?:castellano|audio[\s._-]*esp(?:a[ñn]ol)?|es[\s._-]*es|mejortorrent|wolfmax4k)\b/i.test(audioCleanText) ||
                                    (/\b(?:espa[ñn]ol|spanish|spa)\b/i.test(audioCleanText) && !explicitLatinoAudio);

    // CASO 1: Audio Español Latino confirmado
    if (
        explicitLatinoAudio ||
        (isMultiAudio && (hasMxFlag || /\b(?:cr[\s._-]*web-?dl[\s._-]*multi|multi[\s._-]+(?:\d{3,4}p[\s._-]+)?cr[\s._-]*web-?dl)\b/i.test(firstLine))) ||
        (!isMultiSubs && !hasExplicitSpanishSubs && hasMxFlag && (isDualAudio || isDubbed || !/\b(?:sub|subs|subbed|vose)\b/i.test(firstLine)))
    ) {
        const suffix = isMultiAudio ? ' (Multi-Audio)' : (isDualAudio ? ' (Dual Audio)' : '');
        const flagLine = isMultiAudio ? 'Multi-Audio / 🇲🇽' : (isDualAudio ? 'Dual Audio / 🇲🇽' : '🇲🇽 Spanish');
        return { langKey: 'latino', flag: '🇲🇽', badge: `🇲🇽 Audio Español Latino${suffix}`, flagLine };
    }

    // CASO 2: Audio Español Castellano confirmado
    if (
        explicitCastellanoAudio ||
        (isMultiAudio && hasEsFlag && !hasMxFlag) ||
        (!isMultiSubs && !hasExplicitSpanishSubs && hasEsFlag && (isDualAudio || isDubbed))
    ) {
        const suffix = isMultiAudio ? ' (Multi-Audio)' : '';
        const flagLine = (isMultiAudio || isDualAudio) ? '🇬🇧 / 🇪🇸' : '🇪🇸 Spanish';
        return { langKey: 'castellano', flag: '🇪🇸', badge: `🇪🇸 Audio Español Castellano${suffix}`, flagLine };
    }

    // CASO 3: Dual Audio con Subtítulos en Español Latino
    if (isDualAudio && (isMultiSubs || hasExplicitSpanishSubs) && (hasMxFlag || hasEsFlag || hasExplicitSpanishSubs)) {
        const flagLine = isJapanese ? '🇯🇵 / 🇬🇧 • 🇲🇽 Sub' : '🇺🇸 English • 🇲🇽 Sub';
        return {
            langKey: 'sub',
            flag: origFlag,
            badge: isJapanese ? '🇬🇧/🇯🇵 Dual Audio (Ing/Jap) + 🇲🇽 Sub Latino' : '🇺🇸 Dual Audio (Original) + 🇲🇽 Sub Latino',
            flagLine
        };
    }

    // CASO 4: Audio Original (Inglés o Japonés) con Subtítulos en Español Latino
    if ((isMultiSubs && (hasMxFlag || hasEsFlag)) || hasExplicitSpanishSubs) {
        const flagLine = isJapanese ? '🇯🇵 Japanese • 🇲🇽 Sub' : '🇺🇸 English • 🇲🇽 Sub';
        return {
            langKey: 'sub',
            flag: origFlag,
            badge: isJapanese ? '🇯🇵 Audio Japonés + 🇲🇽 Sub Latino' : '🇺🇸 Audio Original + 🇲🇽 Sub Latino',
            flagLine
        };
    }

    // CASO 5: Multi-Audio general
    if (isMultiAudio) {
        return { langKey: 'multi', flag: '🌐', badge: '🌐 Multi-Audio', flagLine: 'Multi-Audio / 🌐' };
    }

    // CASO 6: Dual Audio sin subtítulos latinos confirmados
    if (isDualAudio) {
        const flagLine = isJapanese ? '🇯🇵 / 🇬🇧 Dual' : '🇺🇸 Dual Audio';
        return {
            langKey: 'sub',
            flag: '🇬🇧',
            badge: isJapanese ? '🇬🇧/🇯🇵 Dual Audio (Inglés/Japonés)' : '🇬🇧 Dual Audio (Inglés/Original)',
            flagLine
        };
    }

    const defaultFlagLine = isJapanese ? '🇯🇵 Japanese' : '🇺🇸 English';
    return {
        langKey: 'sub',
        flag: origFlag,
        badge: isJapanese ? '🇯🇵 Audio Japonés / Sub' : '🇺🇸 Audio Original / Sub',
        flagLine: defaultFlagLine
    };
}

/**
 * Formatea el título de cualquier torrent P2P siguiendo el estándar visual exacto de Torrentio:
 * Línea 1: Nombre de la release / archivo
 * Línea 2: 👤 <seeds> 💾 <size> ⚙️ <fuente>
 * Línea 3: Banderitas e idioma estilo Torrentio (ej. "Dual Audio / 🇲🇽", "🇬🇧 / 🇪🇸", "🇲🇽 Spanish", "🇺🇸 English")
 */
function formatTorrentioTitle({ releaseTitle, seeders = 0, size = '', tracker = 'P2P', flagLine = '' }) {
    const lines = [];
    lines.push(String(releaseTitle || '').trim());

    const metaParts = [`👤 ${seeders || 0}`];
    if (size) metaParts.push(`💾 ${size}`);
    if (tracker) metaParts.push(`⚙️ ${tracker}`);
    lines.push(metaParts.join(' '));

    if (flagLine) {
        lines.push(String(flagLine).trim());
    }
    return lines.join('\n');
}

/**
 * 6. Scraper: Torrentio Multi-Indexer (Stremio ejecutará la descarga P2P localmente usando infoHash)
 */
async function scrapeTorrentio(meta) {
    if (!meta.imdbId) return [];
    const stremioType = meta.type === 'movie' ? 'movie' : 'series';

    const queries = [];
    if (stremioType === 'movie') {
        queries.push(meta.imdbId);
    } else {
        queries.push(`${meta.imdbId}:${meta.season}:${meta.episode}`);
        if (meta.isAnime && meta.season >= 2 && meta.absoluteEpisode) {
            queries.push(`${meta.imdbId}:1:${meta.absoluteEpisode}`);
        }
    }

    const allowedProviders = 'yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl,horriblesubs,nyaasi,tokyotosho,anidex,mejortorrent,wolfmax4k,cinecalidad';
    const endpoints = [];
    for (const qId of queries) {
        endpoints.push(`https://torrentio.strem.fun/providers=${allowedProviders}|language=latino,spanish/stream/${stremioType}/${qId}.json`);
        endpoints.push(`https://torrentio.strem.fun/providers=${allowedProviders}|sort=seeders/stream/${stremioType}/${qId}.json`);
    }

    const responses = await Promise.all(endpoints.map(u => httpGet(u, { json: true, timeout: 4800 })));
    const seen = new Set();
    const results = [];

    for (const data of responses) {
        if (!data?.streams || !Array.isArray(data.streams)) continue;
        for (const s of data.streams) {
            const hash = (s.infoHash || '').toLowerCase();
            if (!hash) continue;
            const fileIdx = typeof s.fileIdx === 'number' ? s.fileIdx : undefined;
            const key = `${hash}:${fileIdx ?? -1}`;
            if (seen.has(key)) continue;
            seen.add(key);

            const rawTitle = s.title || '';
            const rawName = (s.name || 'Torrentio').replace(/\s+/g, ' ').trim();
            const langInfo = classifyTorrentLanguage(rawTitle, meta.isAnime);

            // Extraer calidad
            const qMatch = (rawName + ' ' + rawTitle).match(/\b(2160p|4k|1080p|720p|480p)\b/i);
            const quality = qMatch ? qMatch[1].toUpperCase() : 'HD';

            // Extraer seeders
            const seedMatch = rawTitle.match(/👤\s*(\d+)/u);
            const seeders = seedMatch ? parseInt(seedMatch[1], 10) : 0;

            // Extraer tamaño
            const sizeMatch = rawTitle.match(/💾\s*([0-9.,]+\s*(?:GB|MB|TB|KB))/iu);
            const size = sizeMatch ? sizeMatch[1].trim() : '';

            // Extraer tracker de origen (ej. Cinecalidad, MejorTorrent, 1337x, TorrentGalaxy, ThePirateBay, YTS)
            const trackerMatch = rawTitle.match(/⚙️\s*([^\s\n\r•]+)/u);
            const trackerRaw = trackerMatch ? trackerMatch[1].trim() : 'Torrentio';
            const trackerShort = trackerRaw
                .replace(/^ThePirateBay$/i, 'PirateBay')
                .replace(/^TorrentGalaxy$/i, 'TGx')
                .replace(/^KickassTorrents$/i, 'KAT');

            // Formatear líneas idéntico a Torrentio: Release en L1, Peers + Size + Fuente en L2, Banderitas en L3
            const rawLines = rawTitle.split('\n').map(l => l.trim()).filter(Boolean);
            const metaLineIdx = rawLines.findIndex(l => /👤|💾|⚙️/u.test(l));
            let releaseLine = rawLines[0] || meta.title;
            if (metaLineIdx > 1) {
                releaseLine = `${rawLines[0]} — ${rawLines[1]}`;
            }

            let flagLine = '';
            if (metaLineIdx !== -1 && metaLineIdx < rawLines.length - 1) {
                const cand = rawLines.slice(metaLineIdx + 1).find(l => !/👤|💾|⚙️/u.test(l) && /(?:🇲🇽|🇪🇸|🇬🇧|🇺🇸|🇯🇵|🇫🇷|🇩🇪|🇮🇹|🇷🇺|🇵🇱|🌐|spanish|latino|castellano|english|audio|dual|multi)/iu.test(l));
                if (cand) flagLine = cand;
            }
            if (!flagLine) {
                flagLine = langInfo.flagLine;
            }

            const formattedTitle = formatTorrentioTitle({
                releaseTitle: releaseLine,
                seeders,
                size,
                tracker: trackerRaw,
                flagLine
            });

            const mergedSources = Array.from(new Set([
                ...(Array.isArray(s.sources) ? s.sources : []),
                ...STREMIO_TRACKER_SOURCES
            ]));

            const streamObj = {
                name: `${langInfo.flag} ${trackerShort}\n${quality}`,
                title: formattedTitle,
                infoHash: hash,
                sources: mergedSources,
                _lang: langInfo.langKey,
                _prov: 'torrentio',
                _seeders: seeders
            };
            if (fileIdx !== undefined) streamObj.fileIdx = fileIdx;
            if (s.behaviorHints) streamObj.behaviorHints = s.behaviorHints;

            results.push(streamObj);
        }
    }

    // Ordenar dando prioridad a Español Latino -> Castellano -> Multi-Audio -> Seeders
    const langPriority = { latino: 4, castellano: 3, multi: 2, sub: 1 };
    results.sort((a, b) => {
        const pa = langPriority[a._lang] || 1;
        const pb = langPriority[b._lang] || 1;
        if (pa !== pb) return pb - pa;
        return (b._seeders || 0) - (a._seeders || 0);
    });

    return results.slice(0, 18);
}

/**
 * 7. Scraper: YTS (Películas 1080p / 4K nativas por infoHash — Espejos activos yts.lt / accel.li / yts.mx + fallback Torrentio YTS)
 */
async function scrapeYts(meta) {
    if (meta.type !== 'movie') return [];
    const hosts = ['https://yts.lt', 'https://movies-api.accel.li', 'https://yts.mx'];
    const queries = Array.from(new Set([meta.imdbId, meta.originalTitle, meta.title].filter(Boolean)));

    let movie = null;
    for (const host of hosts) {
        for (const q of queries.slice(0, 2)) {
            const data = await httpGet(`${host}/api/v2/list_movies.json?query_term=${encodeURIComponent(q)}&limit=5`, { json: true, timeout: 3800 });
            const movies = data?.data?.movies;
            if (Array.isArray(movies) && movies.length > 0) {
                movie = movies.find(m => (meta.imdbId && m.imdb_code === meta.imdbId) || isTitleMatch(meta.originalTitle || meta.title, m.title || '', meta.year, m.year)) || movies[0];
                if (movie?.torrents) break;
            }
        }
        if (movie?.torrents) break;
    }

    const results = [];
    const seen = new Set();

    if (movie?.torrents) {
        for (const t of movie.torrents) {
            const hash = (t.hash || '').toLowerCase();
            if (!hash || seen.has(hash)) continue;
            seen.add(hash);
            const qual = (t.quality || '1080p').toUpperCase();
            const vType = (t.type || 'BLURAY').toUpperCase();
            const releaseTitle = `${movie.title_long || meta.title} [${qual} ${vType}]`;
            const formattedTitle = formatTorrentioTitle({
                releaseTitle,
                seeders: t.seeds || 0,
                size: t.size || '',
                tracker: 'YTS',
                flagLine: '🇺🇸 English'
            });

            results.push({
                name: `🇺🇸 YTS\n${qual}`,
                title: formattedTitle,
                infoHash: hash,
                sources: STREMIO_TRACKER_SOURCES,
                _lang: 'sub',
                _prov: 'yts',
                _seeders: t.seeds || 0
            });
        }
    }

    // Fallback en caso de bloqueo de IP en la nube (Render) hacia la API de YTS
    if (results.length === 0 && meta.imdbId) {
        const ytsFallback = await httpGet(`https://torrentio.strem.fun/providers=yts/stream/movie/${meta.imdbId}.json`, { json: true, timeout: 4000 });
        if (Array.isArray(ytsFallback?.streams)) {
            for (const s of ytsFallback.streams) {
                const hash = (s.infoHash || '').toLowerCase();
                if (!hash || seen.has(hash)) continue;
                seen.add(hash);
                const rawTitle = s.title || '';
                const qMatch = ((s.name || '') + ' ' + rawTitle).match(/\b(2160p|4k|1080p|720p)\b/i);
                const qual = qMatch ? qMatch[1].toUpperCase() : '1080P';
                const seedMatch = rawTitle.match(/👤\s*(\d+)/u);
                const seeders = seedMatch ? parseInt(seedMatch[1], 10) : 0;
                const sizeMatch = rawTitle.match(/💾\s*([0-9.,]+\s*(?:GB|MB|TB|KB))/iu);
                const size = sizeMatch ? sizeMatch[1].trim() : '';
                const rawLines = rawTitle.split('\n').map(l => l.trim()).filter(Boolean);
                const releaseTitle = rawLines[0] || `${meta.title} [${qual}]`;

                const formattedTitle = formatTorrentioTitle({
                    releaseTitle,
                    seeders,
                    size,
                    tracker: 'YTS',
                    flagLine: '🇺🇸 English'
                });

                results.push({
                    name: `🇺🇸 YTS\n${qual}`,
                    title: formattedTitle,
                    infoHash: hash,
                    sources: STREMIO_TRACKER_SOURCES,
                    _lang: 'sub',
                    _prov: 'yts',
                    _seeders: seeders
                });
            }
        }
    }

    results.sort((a, b) => (b._seeders || 0) - (a._seeders || 0));
    return results;
}

/**
 * 8. Scraper: Nyaa.si (Anime P2P por infoHash)
 */
async function scrapeNyaa(meta) {
    if (!meta.isAnime) return [];
    const epStr = String(meta.episode).padStart(2, '0');
    const sStr = String(meta.season).padStart(2, '0');
    const q = meta.type === 'movie'
        ? `${meta.originalTitle || meta.title} 1080p`
        : `${meta.originalTitle || meta.title} S${sStr}E${epStr}`;

    const xml = await httpGet(`https://nyaa.si/?page=rss&q=${encodeURIComponent(q)}&c=1_0&f=0`, { timeout: 4200 });
    if (!xml) return [];

    const items = [...xml.matchAll(/<item>(.*?)<\/item>/gs)].slice(0, 8);
    const streams = [];

    for (const m of items) {
        const block = m[1];
        const title = block.match(/<title><!\[CDATA\[(.*?)\]\]><\/title>/)?.[1] || block.match(/<title>(.*?)<\/title>/)?.[1] || '';
        const hash = block.match(/<nyaa:infoHash>([a-fA-F0-9]{40})<\/nyaa:infoHash>/)?.[1]?.toLowerCase();
        const seeders = parseInt(block.match(/<nyaa:seeders>(\d+)<\/nyaa:seeders>/)?.[1] || '0', 10);
        const size = block.match(/<nyaa:size>(.*?)<\/nyaa:size>/)?.[1] || '';
        if (!hash || seeders < 1) continue;

        const langInfo = classifyTorrentLanguage(title, true);
        const qMatch = title.match(/\b(2160p|1080p|720p)\b/i);
        const quality = qMatch ? qMatch[1] : '1080p';

        const formattedTitle = formatTorrentioTitle({
            releaseTitle: title,
            seeders,
            size,
            tracker: 'Nyaa.si',
            flagLine: langInfo.flagLine
        });

        streams.push({
            name: `${langInfo.flag} Nyaa\n${quality}`,
            title: formattedTitle,
            infoHash: hash,
            sources: STREMIO_TRACKER_SOURCES,
            _lang: langInfo.langKey,
            _prov: 'nyaa',
            _seeders: seeders
        });
    }
    return streams;
}

/**
 * 9. Scraper: ThePirateBay (apibay.org + espejo de indexación TPB para IPs Cloud/Render bloqueadas por Cloudflare)
 */
async function scrapeTpb(meta) {
    const sStr = String(meta.season).padStart(2, '0');
    const eStr = String(meta.episode).padStart(2, '0');
    const cleanEs = String(meta.title || '').replace(/[^\w\s]/gu, ' ').replace(/\s+/g, ' ').trim();
    const cleanOrig = String(meta.originalTitle || meta.title || '').replace(/[^\w\s]/gu, ' ').replace(/\s+/g, ' ').trim();

    const rawQueries = meta.type === 'movie'
        ? [
            `${cleanOrig} ${meta.year} latino`,
            `${cleanEs} ${meta.year} latino`,
            `${cleanOrig} ${meta.year} dual`,
            `${cleanOrig} latino`,
            `${cleanOrig} ${meta.year}`
        ]
        : [
            `${cleanOrig} S${sStr}E${eStr} latino`,
            `${cleanEs} S${sStr}E${eStr} latino`,
            `${cleanOrig} S${sStr}E${eStr} multi`,
            `${cleanOrig} S${sStr}E${eStr}`
        ];
    const queries = Array.from(new Set(rawQueries.filter(Boolean)));

    const stremioType = meta.type === 'movie' ? 'movie' : 'series';
    const tpbMirrorUrl = meta.imdbId
        ? (stremioType === 'movie'
            ? `https://torrentio.strem.fun/providers=thepiratebay/stream/movie/${meta.imdbId}.json`
            : `https://torrentio.strem.fun/providers=thepiratebay/stream/series/${meta.imdbId}:${meta.season}:${meta.episode}.json`)
        : null;

    const [apiBayResponses, tpbMirrorData] = await Promise.all([
        Promise.all(queries.map(q => httpGet(`https://apibay.org/q.php?q=${encodeURIComponent(q)}&cat=200`, { json: true, timeout: 3800 }))),
        tpbMirrorUrl ? httpGet(tpbMirrorUrl, { json: true, timeout: 4200 }) : Promise.resolve(null)
    ]);

    const seen = new Set();
    const streams = [];

    for (const list of apiBayResponses) {
        if (!Array.isArray(list)) continue;
        for (const item of list.slice(0, 8)) {
            const hash = (item.info_hash || '').toLowerCase();
            if (!hash || hash === '0000000000000000000000000000000000000000' || seen.has(hash)) continue;
            const seeders = parseInt(item.seeders || '0', 10);
            if (seeders < 1) continue;

            const name = item.name || meta.title;
            if (meta.type === 'movie' && /(?:s\d{1,2}e\d{1,2}|\bseason\s*\d+|\btemporada\s*\d+)/i.test(name)) continue;

            const cleanItemTitle = name.replace(/\b(19\d{2}|20\d{2})\b.*/, '').replace(/[._-]+/g, ' ').trim();
            if (!isTitleMatch(meta.title, cleanItemTitle) && !isTitleMatch(meta.originalTitle, cleanItemTitle)) continue;

            seen.add(hash);

            const langInfo = classifyTorrentLanguage(name, meta.isAnime);
            const sizeGb = ((parseInt(item.size || '0', 10)) / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
            const qMatch = name.match(/\b(2160p|4k|1080p|720p)\b/i);
            const quality = qMatch ? qMatch[1].toUpperCase() : '1080P';

            const formattedTitle = formatTorrentioTitle({
                releaseTitle: name,
                seeders,
                size: sizeGb,
                tracker: 'ThePirateBay',
                flagLine: langInfo.flagLine
            });

            streams.push({
                name: `${langInfo.flag} PirateBay\n${quality}`,
                title: formattedTitle,
                infoHash: hash,
                sources: STREMIO_TRACKER_SOURCES,
                _lang: langInfo.langKey,
                _prov: 'tpb',
                _seeders: seeders
            });
        }
    }

    // Integrar resultados del índice de ThePirateBay (garantiza disponibilidad en Render cuando Cloudflare bloquea apibay.org)
    if (Array.isArray(tpbMirrorData?.streams)) {
        for (const s of tpbMirrorData.streams) {
            const hash = (s.infoHash || '').toLowerCase();
            if (!hash || seen.has(hash)) continue;
            seen.add(hash);

            const rawTitle = s.title || '';
            const rawName = (s.name || '').replace(/\s+/g, ' ').trim();
            const langInfo = classifyTorrentLanguage(rawTitle, meta.isAnime);
            const qMatch = (rawName + ' ' + rawTitle).match(/\b(2160p|4k|1080p|720p|480p|360p)\b/i);
            const quality = qMatch ? qMatch[1].toUpperCase() : '1080P';
            const seedMatch = rawTitle.match(/👤\s*(\d+)/u);
            const seeders = seedMatch ? parseInt(seedMatch[1], 10) : 0;
            const sizeMatch = rawTitle.match(/💾\s*([0-9.,]+\s*(?:GB|MB|TB|KB))/iu);
            const size = sizeMatch ? sizeMatch[1].trim() : '';

            const rawLines = rawTitle.split('\n').map(l => l.trim()).filter(Boolean);
            const metaLineIdx = rawLines.findIndex(l => /👤|💾|⚙️/u.test(l));
            let releaseTitle = rawLines[0] || meta.title;
            if (metaLineIdx > 1) {
                releaseTitle = `${rawLines[0]} — ${rawLines[1]}`;
            }

            let flagLine = '';
            if (metaLineIdx !== -1 && metaLineIdx < rawLines.length - 1) {
                const cand = rawLines.slice(metaLineIdx + 1).find(l => !/👤|💾|⚙️/u.test(l) && /(?:🇲🇽|🇪🇸|🇬🇧|🇺🇸|🇯🇵|🇫🇷|🇩🇪|🇮🇹|🇷🇺|🇵🇱|🌐|spanish|latino|castellano|english|audio|dual|multi)/iu.test(l));
                if (cand) flagLine = cand;
            }
            if (!flagLine) {
                flagLine = langInfo.flagLine;
            }

            const formattedTitle = formatTorrentioTitle({
                releaseTitle,
                seeders,
                size,
                tracker: 'ThePirateBay',
                flagLine
            });

            const streamObj = {
                name: `${langInfo.flag} PirateBay\n${quality}`,
                title: formattedTitle,
                infoHash: hash,
                sources: Array.from(new Set([...(Array.isArray(s.sources) ? s.sources : []), ...STREMIO_TRACKER_SOURCES])),
                _lang: langInfo.langKey,
                _prov: 'tpb',
                _seeders: seeders
            };
            if (typeof s.fileIdx === 'number') streamObj.fileIdx = s.fileIdx;
            if (s.behaviorHints) streamObj.behaviorHints = s.behaviorHints;

            streams.push(streamObj);
        }
    }

    const langPriority = { latino: 4, castellano: 3, multi: 2, sub: 1 };
    streams.sort((a, b) => {
        const pa = langPriority[a._lang] || 1;
        const pb = langPriority[b._lang] || 1;
        if (pa !== pb) return pb - pa;
        return (b._seeders || 0) - (a._seeders || 0);
    });

    return streams.slice(0, 8);
}

/**
 * 10. Scraper: Cinecalidad (https://www.cinecalidad.ro) — Torrents Dual Latino (/protect/ver.php + data-url) y Embeds HLS
 */
async function scrapeCinecalidad(meta) {
    const host = 'https://www.cinecalidad.ro';
    const searchTerms = Array.from(new Set([
        meta.title,
        meta.originalTitle,
        String(meta.title || '').replace(/^(?:the|los|las|el|la)\s+/i, '')
    ].map(s => String(s || '').replace(/[^\w\s]/gu, ' ').replace(/\s+/g, ' ').trim()).filter(Boolean)));

    let detailUrl = null;

    for (const term of searchTerms.slice(0, 2)) {
        const html = await httpGet(`${host}/?s=${encodeURIComponent(term)}`, { timeout: 4500 });
        if (!html || html.length < 300) continue;

        if (meta.type === 'movie') {
            // Cinecalidad minifica HTML sin comillas en atributos (href=https://...)
            const linkMatches = [...html.matchAll(/<a[^>]+href=(["']?)(https?:\/\/[^"'\s>]*(?:pelicula|movies)\/[^"'\s>]+)\1[^>]*>(.*?)<\/a>/gis)];
            for (const m of linkMatches) {
                const href = m[2];
                const inner = m[3];
                const altM = inner.match(/alt=(["']?)([^"'>]+)\1/i);
                const srM = inner.match(/<span[^>]*class=["']?sr-only["']?[^>]*>(.*?)<\/span>/is);
                const rawTitle = (altM ? altM[2] : (srM ? srM[1] : inner.replace(/<[^>]+>/g, '')))
                    .replace(/\s*\((?:19|20)\d{2}\).*/, '')
                    .replace(/\[.*?\]/g, '')
                    .trim();
                const candYear = (rawTitle + ' ' + href).match(/\b(19\d{2}|20\d{2})\b/)?.[1] || null;
                if (rawTitle && (isTitleMatch(meta.title, rawTitle, meta.year, candYear) || isTitleMatch(meta.originalTitle, rawTitle, meta.year, candYear))) {
                    detailUrl = href;
                    break;
                }
            }
        } else {
            const articles = [...html.matchAll(/<article[^>]*>(.*?)<\/article>/gis)];
            for (const artM of articles) {
                const art = artM[1];
                const hm = art.match(/href=(["']?)(https?:\/\/[^"'\s>]*\/(?:serie|series|tv)\/[^"'\s>]+)\1/i);
                if (!hm) continue;
                const seriesUrl = hm[2];
                const sm = art.match(/<span[^>]*class=["']?sr-only["']?[^>]*>(.*?)<\/span>/is) || art.match(/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is);
                const rawTitle = (sm ? sm[1].replace(/<[^>]+>/g, '') : '').replace(/\s*\((?:19|20)\d{2}\).*/, '').trim();
                if (rawTitle && (isTitleMatch(meta.title, rawTitle) || isTitleMatch(meta.originalTitle, rawTitle))) {
                    const sHtml = await httpGet(seriesUrl, { timeout: 4500 });
                    if (sHtml) {
                        const epRe = new RegExp(`href=(["']?)(https?:\\/\\/[^"'\\s>]*\\/(?:episodio|episodes)\\/[^"'\\s>]*-${meta.season}x${meta.episode}\\/?)\\1`, 'i');
                        const epM = sHtml.match(epRe);
                        if (epM) detailUrl = epM[2];
                    }
                    break;
                }
            }
        }
        if (detailUrl) break;
    }

    if (!detailUrl) return [];

    const detHtml = await httpGet(detailUrl, { timeout: 4500 });
    if (!detHtml) return [];

    const streams = [];
    const seenHashes = new Set();
    const labelTitle = meta.type === 'movie'
        ? `${meta.title} (${meta.year || 'HD'})`
        : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;

    const pushCinecalidadTorrent = (btih, rawMagnetOrBadge = '') => {
        const hash = btih.toLowerCase();
        if (!hash || seenHashes.has(hash)) return;
        seenHashes.add(hash);
        let dec = rawMagnetOrBadge;
        try { dec = decodeURIComponent(rawMagnetOrBadge); } catch (e) {}
        const dnM = dec.match(/[?&]dn=([^&"'\s>]+)/i);
        const relTitle = dnM ? dnM[1].replace(/\+/g, ' ') : labelTitle;
        const is4k = /(?:4k|2160p)/i.test(dec);
        const quality = is4k ? '4K UHD' : '1080p';
        const formattedTitle = formatTorrentioTitle({
            releaseTitle: relTitle,
            seeders: 65,
            size: is4k ? '4.85 GB' : '2.15 GB',
            tracker: 'Cinecalidad',
            flagLine: 'Dual Audio / 🇲🇽'
        });
        streams.push({
            name: `🇲🇽 Cinecalidad\n${quality}`,
            title: formattedTitle,
            infoHash: hash,
            sources: STREMIO_TRACKER_SOURCES,
            _lang: 'latino',
            _prov: 'cinecalidad',
            _seeders: 65
        });
    };

    // A. Extraer torrents en Base64 desde data-url (con o sin comillas)
    const dataUrlMatches = [...detHtml.matchAll(/data-url=(["']?)([^"'\s>]+)\1[^>]*>(.*?)<\/a>(?:.*?<span[^>]*>(.*?)<\/span>)?/gis)];
    for (const dm of dataUrlMatches) {
        try {
            const dec1 = Buffer.from(dm[2], 'base64').toString('utf8').trim();
            let finalUrl = dec1.startsWith('magnet:?') ? dec1 : null;
            if (!finalUrl) {
                const lm = dec1.match(/\/links\/([A-Za-z0-9+/=]+)/);
                if (lm) finalUrl = Buffer.from(lm[1], 'base64').toString('utf8').trim();
            }
            const btih = finalUrl?.match(/xt=urn:btih:([a-fA-F0-9]{40})/i)?.[1];
            if (btih) pushCinecalidadTorrent(btih, `${dm[4] || ''} ${finalUrl || ''}`);
        } catch (e) {}
    }

    // B. Extraer torrents protegidos vía /protect/ver.php (ej. ouo.io/qs/...?s=https://www.cinecalidad.ro/protect/ver.php?i=...)
    const allLinks = [...detHtml.matchAll(/<a[^>]+href=(["']?)([^"'\s>]+)\1[^>]*>(.*?)<\/a>/gis)];
    const protectUrls = [];
    for (const lm of allLinks) {
        const href = lm[2];
        const text = lm[3].replace(/<[^>]+>/g, '').trim();
        if (/(?:torrent|bittorrent|protect\/ver\.php)/i.test(text + ' ' + href)) {
            let pUrl = null;
            const sMatch = href.match(/[?&]s=(https?:\/\/[^&"'\s>]+)/i);
            if (sMatch) {
                try { pUrl = decodeURIComponent(sMatch[1]); } catch (e) { pUrl = sMatch[1]; }
            } else if (href.includes('/protect/ver.php')) {
                pUrl = href.startsWith('http') ? href : `${host}${href}`;
            }
            if (pUrl && !protectUrls.includes(pUrl)) protectUrls.push(pUrl);
        }
    }

    if (protectUrls.length > 0) {
        const protPages = await Promise.all(protectUrls.slice(0, 3).map(u => httpGet(u, { timeout: 4000 })));
        for (const pHtml of protPages) {
            if (!pHtml) continue;
            const mMag = pHtml.match(/(magnet:\?xt=urn:btih:([a-fA-F0-9]{40})[^"'\s<>]*)/i);
            if (mMag) pushCinecalidadTorrent(mMag[2], mMag[1]);
        }
    }

    // C. Extraer magnets directos en el HTML
    const magnets = [...detHtml.matchAll(/href=(["']?)(magnet:\?xt=urn:btih:([a-fA-F0-9]{40})[^"'\s>]*)\1/gi)];
    for (const mm of magnets) {
        pushCinecalidadTorrent(mm[3], mm[2]);
    }

    // D. Extraer embeds de streaming en Base64 desde data-src (con o sin comillas)
    const dataSrcMatches = [...detHtml.matchAll(/data-src=(["']?)([A-Za-z0-9+/=]{16,})\1/gi)].map(x => x[2]);
    const embedCandidates = [];
    for (const b64 of dataSrcMatches) {
        try {
            const dec = Buffer.from(b64, 'base64').toString('utf8').trim();
            if (dec.startsWith('http') && /(?:vimeos|voe|wish|hlswish|vidhide|goodstream)/i.test(dec)) {
                embedCandidates.push(dec);
            }
        } catch (e) {}
    }
    const resolvedEmbeds = await Promise.all(embedCandidates.slice(0, 2).map(async (u) => {
        const r = await resolveEmbedToHls(u, detailUrl);
        if (!r?.url) return null;
        return {
            name: `🇲🇽 Cinecalidad\n1080p HLS`,
            title: `${labelTitle}\n⚡ HLS Directo • 🇲🇽 Audio Español Latino`,
            url: r.url,
            _lang: 'latino',
            behaviorHints: {
                notWebReady: false,
                proxyHeaders: { request: { 'Referer': r.referer || `${host}/`, 'User-Agent': UA } }
            }
        };
    }));

    streams.push(...resolvedEmbeds.filter(Boolean));
    return streams;
}

/**
 * 11. Scraper: JKAnime (https://jkanime.net) — Extrae var servers (Base64) y verifica <select id="deflang">
 * para distinguir con precisión entre Japonés Sub. Español (lang=1) y Español Latino.
 */
async function scrapeJkAnime(meta) {
    if (!meta.isAnime) return [];
    const host = 'https://jkanime.net';
    const queries = Array.from(new Set([meta.title, meta.originalTitle].filter(Boolean)));

    for (const q of queries.slice(0, 2)) {
        const html = await httpGet(`${host}/buscar/${encodeURIComponent(q)}/`, { headers: { Referer: `${host}/` }, timeout: 4500 });
        if (!html || html.length < 500) continue;

        const cardMatches = [...html.matchAll(/<div[^>]+class="[^"]*anime__item[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/gis)];
        const matchedSlugs = [];

        for (const m of cardMatches) {
            const block = m[1];
            const href = block.match(/href="(https?:\/\/jkanime\.net\/([^"\/]+)\/)"/i)?.[1];
            const slug = block.match(/href="https?:\/\/jkanime\.net\/([^"\/]+)\/"/i)?.[1];
            const h5Inner = block.match(/<h5[^>]*>(.*?)<\/h5>/is)?.[1] || '';
            const titleRaw = h5Inner.replace(/<[^>]+>/g, '').trim();
            if (!href || !slug || !titleRaw) continue;

            if (isTitleMatch(meta.title, titleRaw) || isTitleMatch(meta.originalTitle, titleRaw)) {
                matchedSlugs.push({ href, slug, titleRaw });
            }
        }

        if (!matchedSlugs.length) continue;

        // Seleccionar el slug adecuado según temporada / cour (ej. Science Future Part 2)
        let targetCandidate = matchedSlugs[0];
        let targetEp = meta.episode;

        if (meta.season >= 2 && meta.episode > 12) {
            const part2 = matchedSlugs.find(c => /part[-_ ]*2/i.test(c.slug));
            if (part2) {
                targetCandidate = part2;
                targetEp = meta.episode - 12;
            }
        }

        const epCandidates = [targetEp];
        if (meta.absoluteEpisode && meta.absoluteEpisode !== targetEp && targetCandidate === matchedSlugs[0]) {
            epCandidates.unshift(meta.absoluteEpisode);
        }

        for (const epNum of epCandidates) {
            const epUrl = `${host}/${targetCandidate.slug}/${epNum}/`;
            const epHtml = await httpGet(epUrl, { headers: { Referer: targetCandidate.href }, timeout: 4500 });
            if (!epHtml || epHtml.length < 1500) continue;

            // Mapear códigos de idioma desde <select id="deflang"> (ej. 1 -> "Japones Sub. Español", 3 -> "Español Latino")
            const langMap = new Map();
            const deflangMatch = epHtml.match(/<select[^>]*id=["']deflang["'][^>]*>(.*?)<\/select>/is);
            if (deflangMatch) {
                for (const opt of deflangMatch[1].matchAll(/<option[^>]*value=["'](\d+)["'][^>]*>(.*?)<\/option>/gis)) {
                    const code = Number(opt[1]);
                    const label = opt[2].replace(/<[^>]+>/g, '').trim();
                    langMap.set(code, classifyStreamingLangTag(label));
                }
            }
            const defaultCardLang = /latino/i.test(targetCandidate.titleRaw + ' ' + targetCandidate.slug)
                ? { langKey: 'latino', flag: '🇲🇽', langBadge: '🇲🇽 Audio Español Latino' }
                : { langKey: 'sub', flag: '🇯🇵', langBadge: '🇯🇵 Audio Japonés + 🇲🇽 Sub Español' };

            const toResolve = [];

            // 1. Extraer var servers = [{"remote":"<base64>","server":"VOE","lang":1,...}]
            const serversJsonMatch = epHtml.match(/var\s+servers\s*=\s*(\[.*?\]);/s);
            if (serversJsonMatch) {
                try {
                    const srvList = JSON.parse(serversJsonMatch[1]);
                    for (const srv of srvList) {
                        if (!srv.remote) continue;
                        const decUrl = Buffer.from(srv.remote, 'base64').toString('utf8').trim();
                        if (decUrl.startsWith('http') && /(?:voe|wish|sfastwish|hlswish|vidhide|mp4upload)/i.test(decUrl)) {
                            const lInfo = langMap.get(Number(srv.lang)) || defaultCardLang;
                            toResolve.push({ url: decUrl, serverName: srv.server || 'HLS', langInfo: lInfo });
                        }
                    }
                } catch (e) {}
            }

            // 2. Fallback a iframes externos si los hubiera
            if (toResolve.length === 0) {
                const iframeSrcs = [...epHtml.matchAll(/(?:src|data-src)=["'](https?:\/\/(?:[^"']*(?:voe|wish|streamwish|vidhide|mp4upload)[^"']*))["']/gi)].map(x => x[1]);
                for (const src of iframeSrcs) {
                    toResolve.push({ url: src, serverName: 'HLS', langInfo: defaultCardLang });
                }
            }

            if (toResolve.length === 0) continue;

            const resolved = await Promise.all(toResolve.slice(0, 4).map(async (item) => {
                const r = await resolveEmbedToHls(item.url, epUrl);
                if (!r?.url) return null;
                return {
                    name: `${item.langInfo.flag} JKAnime\n1080p HD`,
                    title: `${meta.title} - Ep. ${epNum}\n⚡ ${item.serverName} HLS • ${item.langInfo.langBadge}`,
                    url: r.url,
                    _lang: item.langInfo.langKey,
                    behaviorHints: {
                        notWebReady: false,
                        proxyHeaders: { request: { 'Referer': r.referer || `${host}/`, 'User-Agent': UA } }
                    }
                };
            }));
            const valid = resolved.filter(Boolean);
            if (valid.length > 0) return valid;
        }
    }
    return [];
}

/**
 * 12. Scraper: AnimeFLV (AnimeAV1 REST API + AnimeFLV Web Fallback)
 */
async function scrapeAnimeFLV(meta) {
    if (!meta.isAnime) return [];
    const queries = Array.from(new Set([meta.title, meta.originalTitle].filter(Boolean)));

    // 1. Intentar primero con la API REST directa (ultrarrápida y sin Cloudflare blocks)
    for (const q of queries.slice(0, 2)) {
        try {
            const apiData = await httpGet(`https://animeav1.ahmedrangel.com/api/search?query=${encodeURIComponent(q)}`, {
                json: true, timeout: 4500
            });
            const mediaList = apiData?.data?.media || [];
            if (!mediaList.length) continue;

            const candidates = mediaList.filter(item => isTitleMatch(meta.title, item.title || '') || isTitleMatch(meta.originalTitle, item.title || ''));
            const pool = candidates.length ? candidates : mediaList;

            // Arreglar casos de temporadas partidas (ej: Dr. Stone Science Future Part 2)
            let targetSlug = null;
            let targetEp = meta.episode;

            if (meta.season >= 2 && meta.episode > 12) {
                const part2 = pool.find(item => /part[-_ ]*2/i.test(item.slug || ''));
                if (part2) {
                    targetSlug = part2.slug;
                    targetEp = meta.episode - 12;
                }
            }

            if (!targetSlug) {
                const sPat = new RegExp(`(?:season[-_ ]*0*${meta.season}|s0*${meta.season}|${meta.season}(?:nd|rd|th)?[-_ ]*season)`, 'i');
                const seasonMatch = pool.find(item => sPat.test(item.slug || '') || sPat.test(item.title || ''));
                if (seasonMatch) {
                    targetSlug = seasonMatch.slug;
                } else if (meta.season === 1) {
                    const s1Match = pool.find(item => !/(?:season[-_ ]*[2-9]|s[2-9]|part[-_ ]*[2-9])/i.test(item.slug || ''));
                    if (s1Match) targetSlug = s1Match.slug;
                } else if (meta.absoluteEpisode) {
                    targetEp = meta.absoluteEpisode;
                    targetSlug = pool[0]?.slug;
                }
            }

            if (!targetSlug && pool[0]) {
                targetSlug = pool[0].slug;
            }

            if (targetSlug) {
                const epData = await httpGet(`https://animeav1.ahmedrangel.com/api/anime/${targetSlug}/${targetEp}`, {
                    json: true, timeout: 4500
                });
                const embeds = (epData?.data?.embeds || []).filter(emb => emb.url && /(?:zilla-networks|voe|mp4upload|wish|vidhide)/i.test(emb.url));
                if (embeds.length > 0) {
                    const resolved = await Promise.all(embeds.slice(0, 6).map(async (emb) => {
                        const isLatino = /^(?:DUB|LAT|LATINO)$/i.test(String(emb.type || '').trim());
                        const langKey = isLatino ? 'latino' : 'sub';
                        const langBadge = isLatino ? '🇲🇽 Audio Español Latino' : '🇯🇵 Audio Japonés + 🇲🇽 Sub Español';
                        const flag = isLatino ? '🇲🇽' : '🇯🇵';

                        const r = await resolveEmbedToHls(emb.url, `https://animeav1.com/media/${targetSlug}/${targetEp}`);
                        if (!r?.url) return null;
                        return {
                            name: `${flag} AnimeFLV\n1080p HD`,
                            title: `${meta.title} - S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}\n⚡ ${emb.name || 'HLS Directo'} • ${langBadge}`,
                            url: r.url,
                            _lang: langKey,
                            behaviorHints: {
                                notWebReady: false,
                                proxyHeaders: { request: { 'Referer': r.referer || 'https://player.zilla-networks.com/', 'User-Agent': UA } }
                            }
                        };
                    }));
                    const valid = resolved.filter(Boolean);
                    if (valid.length > 0) return valid;
                }
            }
        } catch (e) {}
    }

    // 2. Fallback a scraping web directo
    const host = 'https://www3.animeflv.net';
    for (const q of queries.slice(0, 2)) {
        const data = await httpGet(`${host}/api/animes/search?value=${encodeURIComponent(q)}`, {
            json: true, headers: { Referer: `${host}/` }, timeout: 4000
        });
        if (!Array.isArray(data) || data.length === 0) continue;

        const match = data.find(item => isTitleMatch(meta.title, item.title || '') || isTitleMatch(meta.originalTitle, item.title || '') || isTitleMatch(meta.title, item.title_english || ''));
        if (!match) continue;

        const slug = match.slug;
        if (!slug) continue;

        const epCandidates = [meta.episode];
        if (meta.absoluteEpisode && meta.absoluteEpisode !== meta.episode) epCandidates.unshift(meta.absoluteEpisode);

        for (const epNum of epCandidates) {
            const epUrl = `${host}/ver/${slug}-${epNum}`;
            const epHtml = await httpGet(epUrl, { headers: { Referer: `${host}/anime/${slug}` }, timeout: 4000 });
            if (!epHtml || epHtml.length < 1000) continue;

            const videosMatch = epHtml.match(/var\s+videos\s*=\s*(\{.*?\});\s*\n/s);
            if (!videosMatch) continue;

            let videosJson = null;
            try { videosJson = JSON.parse(videosMatch[1]); } catch (e) { continue; }

            const streams = [];
            for (const [langGroup, list] of Object.entries(videosJson)) {
                if (!Array.isArray(list)) continue;
                const isLatino = langGroup.toUpperCase() === 'LAT' || langGroup.toUpperCase() === 'LATINO';
                const langKey = isLatino ? 'latino' : 'sub';
                const langBadge = isLatino ? '🇲🇽 Audio Español Latino' : '🇯🇵 Audio Japonés + 🇲🇽 Sub Español';
                const flag = isLatino ? '🇲🇽' : '🇯🇵';

                const resolvable = list.filter(v => v.url && /(?:voe|wish|streamwish|vidhide|hglink|morencius|filemoon|fembed|mp4upload)/i.test(v.url)).slice(0, 3);
                const resolved = await Promise.all(resolvable.map(async (v) => {
                    const r = await resolveEmbedToHls(v.url, epUrl);
                    if (!r?.url) return null;
                    return {
                        name: `${flag} AnimeFLV\n1080p HD`,
                        title: `${meta.title} - Ep. ${epNum}\n⚡ ${v.title || 'HLS Directo'} • ${langBadge}`,
                        url: r.url,
                        _lang: langKey,
                        behaviorHints: {
                            notWebReady: false,
                            proxyHeaders: { request: { 'Referer': r.referer || `${host}/`, 'User-Agent': UA } }
                        }
                    };
                }));
                streams.push(...resolved.filter(Boolean));
            }
            if (streams.length > 0) return streams;
        }
    }
    return [];
}

/**
 * Obtiene el catálogo trending desde TMDB para mostrar contenido navegable en Stremio.
 * Usa solo TMDB (sin scraping, respuesta rápida) y devuelve en el formato esperado por Stremio.
 */
export async function getStremiosCatalog(catalogId, type, skip = 0) {
    const cacheKey = `catalog|${catalogId}|${skip}`;
    const cached = getCached(tmdbCache, cacheKey, 45 * 60 * 1000); // 45 min TTL para catálogos
    if (cached) return cached;

    const page = Math.floor(skip / 20) + 1;
    let url = '';

    if (catalogId === 'streammedia_trending_movies') {
        url = `https://api.themoviedb.org/3/discover/movie?api_key=${TMDB_API_KEY}&language=es-MX&sort_by=popularity.desc&with_original_language=es&region=MX&page=${page}`;
    } else if (catalogId === 'streammedia_trending_series') {
        url = `https://api.themoviedb.org/3/discover/tv?api_key=${TMDB_API_KEY}&language=es-MX&sort_by=popularity.desc&with_original_language=es&page=${page}`;
    } else if (catalogId === 'streammedia_anime_latino') {
        // Anime popular con audio disponible en español (genre 16 = Animation, origin_country = JP)
        url = `https://api.themoviedb.org/3/discover/tv?api_key=${TMDB_API_KEY}&language=es-MX&sort_by=popularity.desc&with_genres=16&with_origin_country=JP&page=${page}`;
    } else {
        return { metas: [] };
    }

    const data = await httpGet(url, { json: true, timeout: 5000 });
    if (!data?.results) return { metas: [] };

    // Para obtener los imdbIds necesitamos external_ids — lo hacemos en paralelo (máx 10 items)
    const items = (data.results || []).slice(0, 20);
    const endpoint = (type === 'movie') ? 'movie' : 'tv';

    const metas = (await Promise.all(items.map(async (item) => {
        const tmdbId = item.id;
        const title = item.title || item.name || '';
        const poster = item.poster_path ? `https://image.tmdb.org/t/p/w500${item.poster_path}` : null;
        const background = item.backdrop_path ? `https://image.tmdb.org/t/p/w1280${item.backdrop_path}` : null;
        const releaseDate = item.release_date || item.first_air_date || '';
        const year = releaseDate ? releaseDate.substring(0, 4) : '';
        const description = item.overview || '';

        // Obtener imdbId para que Stremio pueda hacer match con Cinemeta
        const extIds = await httpGet(`https://api.themoviedb.org/3/${endpoint}/${tmdbId}/external_ids?api_key=${TMDB_API_KEY}`, { json: true, timeout: 3000 }).catch(() => null);
        const imdbId = extIds?.imdb_id || null;

        if (!imdbId && !tmdbId) return null;

        return {
            id: imdbId || `tmdb:${tmdbId}`,
            type: type === 'anime' ? 'anime' : type,
            name: title,
            poster,
            background,
            description,
            releaseInfo: year,
            imdbRating: item.vote_average ? String(item.vote_average.toFixed(1)) : undefined
        };
    }))).filter(Boolean);

    const payload = { metas };
    if (metas.length > 0) setCached(tmdbCache, cacheKey, payload);
    return payload;
}

/**
 * Ejecuta todos los scrapers seleccionados en paralelo con límite de tiempo y devuelve el array `streams` de Stremio.
 */
export async function getStremioStreams(type, rawId, configStr = '', baseUrl = '') {
    const cacheKey = `${configStr || 'default'}|${type}|${rawId}`;
    const cached = getCached(streamCache, cacheKey, 5 * 60 * 1000);
    if (cached) return cached;

    const cfg = parseAddonConfig(configStr);
    const meta = await resolveTmdbMetadata(type, rawId);
    if (!meta.title && !meta.imdbId) {
        return { streams: [] };
    }

    const tasks = [];

    if (cfg.providers.has('movieplus')) tasks.push(scrapeMoviePlus(meta, baseUrl));
    if (cfg.providers.has('local_cdn')) tasks.push(scrapeLocalCdn(meta));
    if (cfg.providers.has('lamovie')) tasks.push(scrapeLaMovie(meta));
    if (cfg.providers.has('latanime')) tasks.push(scrapeLatAnime(meta));
    if (cfg.providers.has('jkanime')) tasks.push(scrapeJkAnime(meta));
    if (cfg.providers.has('animeflv')) tasks.push(scrapeAnimeFLV(meta));
    if (cfg.providers.has('serieskao')) tasks.push(scrapeKaoOrPelisPlus(meta, 'serieskao'));
    if (cfg.providers.has('pelisplus')) tasks.push(scrapeKaoOrPelisPlus(meta, 'pelisplus'));
    if (cfg.providers.has('cuevana')) tasks.push(scrapeCuevanaOrPoseidon(meta, 'cuevana'));
    if (cfg.providers.has('poseidonhd')) tasks.push(scrapeCuevanaOrPoseidon(meta, 'poseidonhd'));
    // Ejecutar primero los scrapers P2P dedicados (Cinecalidad, TPB, YTS, Nyaa) antes de Torrentio
    // para que al deduplicar infoHash prevalezca la etiqueta dedicada (PirateBay, YTS, Cinecalidad)
    if (cfg.providers.has('cinecalidad')) tasks.push(scrapeCinecalidad(meta));
    if (cfg.providers.has('tpb')) tasks.push(scrapeTpb(meta));
    if (cfg.providers.has('yts')) tasks.push(scrapeYts(meta));
    if (cfg.providers.has('nyaa')) tasks.push(scrapeNyaa(meta));
    if (cfg.providers.has('torrentio')) tasks.push(scrapeTorrentio(meta));

    const settled = await Promise.allSettled(tasks);
    let rawStreams = [];
    for (const res of settled) {
        if (res.status === 'fulfilled' && Array.isArray(res.value)) {
            rawStreams.push(...res.value);
        }
    }

    // Deduplicar torrents P2P por infoHash (conservando el primer proveedor dedicado que lo aportó: Cinecalidad, LaMovie P2P, PirateBay, YTS, Nyaa)
    const seenHashes = new Set();
    let allStreams = [];
    for (const s of rawStreams) {
        if (s.infoHash) {
            const hKey = (meta.type === 'movie' || !s.fileIdx)
                ? s.infoHash.toLowerCase()
                : `${s.infoHash.toLowerCase()}:${s.fileIdx}`;
            if (seenHashes.has(hKey)) continue;
            seenHashes.add(hKey);
        }
        allStreams.push(s);
    }

    // Filtrar por idiomas seleccionados, pero NUNCA ocultar los scrapers P2P dedicados (YTS, PirateBay, Nyaa) si el usuario los dejó activos
    const dedicatedP2p = new Set(['cinecalidad', 'lamovie_p2p', 'tpb', 'yts', 'nyaa']);
    const langFiltered = allStreams.filter(s => !s._lang || cfg.langs.has(s._lang) || dedicatedP2p.has(s._prov));
    if (langFiltered.length > 0) {
        allStreams = langFiltered;
    }

    // Ordenar streams:
    // 1° HLS/Directos agrupados por prioridad de idioma (Latino > Castellano > Multi > Sub)
    // 2° Torrents P2P en Español Latino / Castellano / Multi-Audio
    // 3° Torrents P2P de proveedores dedicados (Cinecalidad, LaMovie P2P, PirateBay, YTS, Nyaa) seguidos por Torrentio Multi-Indexer
    const langRank = { latino: 40, castellano: 30, multi: 20, sub: 10 };
    const provPriority = {
        cinecalidad: 1,
        lamovie_p2p: 2,
        tpb: 3,
        yts: 4,
        nyaa: 5,
        torrentio: 6
    };

    allStreams.sort((a, b) => {
        const aIsHls = Boolean(a.url) ? 1 : 0;
        const bIsHls = Boolean(b.url) ? 1 : 0;
        if (bIsHls !== aIsHls) return bIsHls - aIsHls;

        const aLangScore = langRank[a._lang] || 0;
        const bLangScore = langRank[b._lang] || 0;

        if (aIsHls && bIsHls) {
            return bLangScore - aLangScore;
        }

        // En P2P: Español/Multi siempre arriba; luego agrupar proveedores dedicados (PirateBay, YTS, Nyaa) antes del resto de Torrentio
        const aIsSpanishOrMulti = aLangScore >= 20 ? 1 : 0;
        const bIsSpanishOrMulti = bLangScore >= 20 ? 1 : 0;
        if (bIsSpanishOrMulti !== aIsSpanishOrMulti) return bIsSpanishOrMulti - aIsSpanishOrMulti;

        if (aIsSpanishOrMulti && bIsSpanishOrMulti && bLangScore !== aLangScore) {
            return bLangScore - aLangScore;
        }

        const aProvOrder = provPriority[a._prov] || 99;
        const bProvOrder = provPriority[b._prov] || 99;
        if (aProvOrder !== bProvOrder) return aProvOrder - bProvOrder;

        return (b._seeders || 0) - (a._seeders || 0);
    });

    // Limpiar propiedades internas antes de enviar a Stremio
    const cleanStreams = allStreams.map(s => {
        const copy = { ...s };
        delete copy._lang;
        delete copy._prov;
        delete copy._provRank;
        delete copy._seeders;
        return copy;
    });

    const payload = { streams: cleanStreams };
    if (cleanStreams.length > 0) {
        setCached(streamCache, cacheKey, payload);
    }
    return payload;
}
