import crypto from 'crypto';

const TMDB_API_KEY = process.env.TMDB_API_KEY || 'aaccf8f89c76c5a7ac753e3a8402de56';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

export const ALL_STREMIO_PROVIDERS = [
    { id: 'movieplus', name: 'Movie+ (Cloud CDN / Yandex / Mega)', category: 'streaming', default: true },
    { id: 'local_cdn', name: 'CDN Propio (Rakun Cloud Directo)', category: 'direct', default: true },
    { id: 'lamovie', name: 'LaMovie / AllCalidad (HLS Directo)', category: 'streaming', default: true },
    { id: 'pelisforte', name: 'PelisForte (Películas y Series HD)', category: 'streaming', default: true },
    { id: 'pelispedia', name: 'PelisPedia (Películas y Series HD)', category: 'streaming', default: true },
    { id: 'serieskao', name: 'SeriesKao (Embed69 HLS Directo)', category: 'streaming', default: true },
    { id: 'pelisplus', name: 'PelisPlusHD (Películas, Series y Anime)', category: 'streaming', default: true },
    { id: 'cuevana', name: 'Cuevana 3 (Multi-Servidor HLS)', category: 'streaming', default: true },
    { id: 'poseidonhd', name: 'PoseidonHD (Películas y Series)', category: 'streaming', default: true },
    { id: 'cinecalidad', name: 'Cinecalidad (Latino Dual 1080p/4K)', category: 'mixed', default: true },
    { id: 'latanime', name: 'LatAnime (Anime Español Latino)', category: 'streaming', default: true },
    { id: 'jkanime', name: 'JKAnime (Anime Latino/Sub — HLS Directo)', category: 'streaming', default: true },
    { id: 'animeflv', name: 'AnimeFLV (Anime Latino/Sub — HLS Directo)', category: 'streaming', default: true },
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

function cleanTitleForComparison(title = '') {
    if (!title || typeof title !== 'string') return '';
    let t = title.toLowerCase();

    // Eliminar sufijo de grupo de release al final
    t = t.replace(/-[a-z0-9_]+$/i, '');

    // Eliminar años de 4 dígitos (1900-2035) delimitados por espacios, puntos, etc.
    t = t.replace(/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/g, ' ');

    // Eliminar contenido entre corchetes [ ... ]
    t = t.replace(/\[.*?\]/g, ' ');

    // Eliminar palabras de ruido técnico (calidades, codecs, audios, etc.)
    const noiseWords = [
        '1080p', '720p', '480p', '2160p', '4k', 'uhd', 'hd', 'fullhd', 'full hd',
        'bluray', 'bdrip', 'brrip', 'dvdrip', 'web-dl', 'webdl', 'web-rip', 'webrip', 'hdtv', 'microhd', 'cam', 'screener', 'ts', 'telesync', 'hdcam',
        'amzn', 'dsnp', 'atvp', 'nflx', 'hmax', 'max', 'paramount', 'disney',
        'h264', 'h.264', 'x264', 'h265', 'h.265', 'x265', 'hevc', 'avc', '10bit', 'hdr', 'hdr10', 'hdr10plus', 'dv', 'dovi', 'sdr',
        'ddp5.1', 'ddp51', 'atmos', 'dts', 'truehd', 'aac', 'ac3', 'eac3', 'mp3',
        'latino', 'lat', 'castellano', 'espanol', 'español', 'spanish', 'subtitulado', 'sub', 'subs', 'subbed', 'vose', 'vost',
        'dual', 'ingles', 'english', 'eng', 'audio', 'multi', 'temporada', 'temp', 'mkv', 'mp4', 'avi'
    ];
    for (const w of noiseWords) {
        const re = new RegExp(`\\b${w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`, 'gi');
        t = t.replace(re, ' ');
    }

    // Normalizar acentos y conjunciones
    t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    t = t.replace(/&/g, 'y');

    // Conservar solo alfanuméricos
    return t.replace(/[^a-z0-9]/g, '').trim();
}

function getSequelTokens(str = '') {
    const s = ` ${String(str || '').toLowerCase()} `.replace(/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/g, ' ');
    const tokens = new Set();

    const numM = s.match(/\b([2-9]|10)\b/g);
    if (numM) numM.forEach(t => tokens.add(t));

    const romM = s.match(/\b(ii|iii|iv|v|vi|vii|viii|ix|x)\b/g);
    if (romM) romM.forEach(t => tokens.add(t));

    const wordM = s.match(/\b(dos|tres|cuatro|cinco|seis|two|three|four|five|six)\b/g);
    if (wordM) wordM.forEach(t => tokens.add(t));

    const partM = s.match(/\b(capitulo|capítulo|chapter|parte|part)\b/g);
    if (partM) partM.forEach(t => tokens.add(t));

    return tokens;
}

function stringSimilarity(s1, s2) {
    if (s1 === s2) return 1.0;
    if (s1.length < 2 || s2.length < 2) return 0.0;
    const bigrams1 = new Map();
    for (let i = 0; i < s1.length - 1; i++) {
        const bg = s1.slice(i, i + 2);
        bigrams1.set(bg, (bigrams1.get(bg) || 0) + 1);
    }
    let intersection = 0;
    for (let i = 0; i < s2.length - 1; i++) {
        const bg = s2.slice(i, i + 2);
        const count = bigrams1.get(bg) || 0;
        if (count > 0) {
            bigrams1.set(bg, count - 1);
            intersection++;
        }
    }
    return (2.0 * intersection) / ((s1.length - 1) + (s2.length - 1));
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

/**
 * Comparación estricta de títulos y años para evitar entregar contenido erróneo
 * (ej: no entregar Dragon Ball Super ante Dragon Ball Super: Beerus, ni The Thing 2011 ante The Thing 1982).
 */
function isTitleMatch(queryTitle, candTitle, queryYear = null, candYear = null) {
    if (!queryTitle || !candTitle) return false;

    // 1. Auto-extraer año de los títulos si no vino provisto explícitamente
    let effectiveCandYear = candYear;
    if (!effectiveCandYear) {
        const m = candTitle.match(/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/);
        if (m) effectiveCandYear = m[1];
    }
    let effectiveQueryYear = queryYear;
    if (!effectiveQueryYear) {
        const m = queryTitle.match(/[\(\[\s._\-]\b(19\d{2}|20[0-2]\d|203[0-5])\b[\)\]\s._\-]/);
        if (m) effectiveQueryYear = m[1];
    }

    // 2. Validación estricta de AÑO si ambos están presentes y son válidos
    if (effectiveQueryYear && effectiveCandYear) {
        const qY = parseInt(String(effectiveQueryYear).replace(/\D/g, ''), 10);
        const cY = parseInt(String(effectiveCandYear).replace(/\D/g, ''), 10);
        if (qY > 1900 && cY > 1900 && Math.abs(qY - cY) > 1) {
            return false;
        }
    }

    // 3. Detección estricta de secuelas y números romanos o palabras numéricas
    const qTokens = getSequelTokens(queryTitle);
    const cTokens = getSequelTokens(candTitle);
    for (const t of cTokens) {
        if (!qTokens.has(t)) return false;
    }
    for (const t of qTokens) {
        if (!cTokens.has(t)) return false;
    }

    const cleanQuery = cleanTitleForComparison(queryTitle);
    const cleanCand = cleanTitleForComparison(candTitle);

    if (!cleanQuery || !cleanCand) return false;

    // 4. Coincidencia exacta tras limpieza técnica
    if (cleanQuery === cleanCand) return true;

    const allowedNoise = new Set([
        'subs', 'sub', 'integrados', 'integrado', 'vose', 'vost',
        'extended', 'extendida', 'unrated', 'directors', 'director', 'cut',
        'remastered', 'remasterizada', 'imax', 'version', 'edicion', 'edition',
        'pelicula', 'movie', 'amzn', 'dsnp', 'nflx', 'webdl', 'webrip', 'bluray',
        'h264', 'x264', 'h265', 'x265', 'hevc', 'ddp51', 'ddp', 'atmos', 'dts',
        'aac', 'ac3', 'latino', 'lat', 'castellano', 'dual', 'multi', 'mp4', 'mkv'
    ]);

    // 5. Si el candidato empieza por la búsqueda: verificar que el remanente sea únicamente ruido técnico
    if (cleanCand.startsWith(cleanQuery)) {
        let extra = cleanCand.slice(cleanQuery.length).replace(/(?:19\d{2}|20\d{2})/g, '');
        for (const n of allowedNoise) {
            extra = extra.replaceAll(n, '');
        }
        if (extra.trim() === '') return true;
        return false;
    }

    // 6. Si la búsqueda empieza por el candidato: verificar que el remanente sea únicamente ruido técnico
    if (cleanQuery.startsWith(cleanCand)) {
        let extra = cleanQuery.slice(cleanCand.length).replace(/(?:19\d{2}|20\d{2})/g, '');
        for (const n of allowedNoise) {
            extra = extra.replaceAll(n, '');
        }
        if (extra.trim() === '') return true;
        return false;
    }

    // 7. Similitud textual alta (mínimo 90%) para tolerar pequeñas erratas tipográficas
    const sim = stringSimilarity(cleanQuery, cleanCand);
    return sim >= 0.90;
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
export async function resolveEmbedToHls(embedUrl, customReferer = '') {
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

        // 0.1 Vidara (as9u7bgh9.com / vidara.to / vidara.so / vidara.cc / vidara.net)
        if (/(?:as9u7bgh9\.com|vidara\.(?:to|so|cc|net))/i.test(lower)) {
            const m = embedUrl.match(/\/(?:e|v)\/([a-zA-Z0-9]+)/i);
            const filecode = m ? m[1] : '';
            if (filecode) {
                const vidaraHosts = Array.from(new Set([host, 'https://vidara.to', 'https://as9u7bgh9.com']));
                for (const vHost of vidaraHosts) {
                    try {
                        const vRes = await fetch(`${vHost}/api/stream`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Referer': `${vHost}/e/${filecode}`,
                                'User-Agent': UA
                            },
                            body: JSON.stringify({ filecode, device: 'desktop', codecs: ['h264', 'aac'] }),
                            signal: AbortSignal.timeout(3500)
                        });
                        if (vRes.ok) {
                            const vData = await vRes.json();
                            if (vData?.streaming_url && vData.streaming_url.startsWith('http')) {
                                return {
                                    url: vData.streaming_url,
                                    referer: `${vHost}/`
                                };
                            }
                        }
                    } catch (e) {}
                }
            }
        }

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

        // 2. Voe.sx y espejos dinámicos (sigue redirección JS window.location.href y decodifica application/json según Balandro)
        if (/(?:voe\.sx|voesx|voe-|voeunblock|un-block-voe|v-o-e-unblock|edwardarriveoften|reputationsheriffkennethsand|fittingcentermondaysunday|tinycat-voe-fashion|scatch176duplicities|yodelswartlike|nectareousoverelate|kathleenmemberhistory|apinchcaseation|strawberriesporail|cigarlessarefy|crownmakermacaronicism|generatesnitrosate|figeterpiazine|timberwoodanotia|tubelessceliolymph|wolfdyslectic|metagnathtuggers|chromotypic|gamoneinterrupted|rationalityaloelike|valeronevijao|availedsmallest|prefulfilloverdoor|jayservicestuff|brookethoughi|jilliandescribecompany|christopheruntilpoint|walterprettytheir|catherineupdated|megankiddifference)/i.test(lower)) {
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

        // 4. Streamtape (reconstrucción extractor Kodi Balandro)
        if (lower.includes('streamtape.com')) {
            const stUrl = embedUrl.replace('/e/', '/v/');
            const html = await httpGet(stUrl, { timeout: 4000, headers: { Referer: 'https://streamtape.com/' } });
            if (html) {
                const robotMatch = html.match(/id=["']robotlink["'][^>]*>([^<]+)/i) || html.match(/id=robotlink[^>]*>([^<]+)/i);
                if (robotMatch) {
                    let streamPath = robotMatch[1].trim();
                    const tokenMatch = html.match(/&token=([a-zA-Z0-9_-]+)/);
                    if (tokenMatch && !streamPath.includes('&token=')) {
                        streamPath += `&token=${tokenMatch[1]}`;
                    }
                    const fullStream = streamPath.startsWith('http') ? streamPath : `https:${streamPath}`;
                    return { url: fullStream, referer: 'https://streamtape.com/' };
                }
            }
        }

        // 5. YourUpload (reconstrucción extractor Kodi Balandro)
        if (lower.includes('yourupload.com')) {
            const yuUrl = embedUrl.replace('/watch/', '/embed/');
            const html = await httpGet(yuUrl, { timeout: 4000, headers: { Referer: 'https://www.yourupload.com/' } });
            if (html) {
                const vm = html.match(/<video[^>]+src=["']([^"']+)["']/i) || html.match(/file\s*:\s*["']([^"']+\.mp4[^"']*)["']/i);
                if (vm) {
                    const direct = vm[1].startsWith('http') ? vm[1] : `https://www.yourupload.com${vm[1]}`;
                    return { url: direct, referer: 'https://www.yourupload.com/' };
                }
            }
        }

        // 6. OkRu (reconstrucción extractor Kodi Balandro)
        if (/(?:ok\.ru|odnoklassniki\.ru)/i.test(lower)) {
            const html = await httpGet(embedUrl, { timeout: 4000, headers: { Referer: 'https://ok.ru/' } });
            if (html) {
                const optMatch = html.match(/data-options="([^"]+)"/i);
                if (optMatch) {
                    try {
                        const unesc = optMatch[1].replace(/&quot;/g, '"');
                        const opts = JSON.parse(unesc);
                        const metadata = JSON.parse(opts.flashvars?.metadata || '{}');
                        const videos = metadata.videos || [];
                        const best = videos.find(v => v.name === 'hd') || videos.find(v => v.name === 'sd') || videos[0];
                        if (best?.url) {
                            return { url: best.url, referer: 'https://ok.ru/' };
                        }
                        if (metadata.hlsManifestUrl) {
                            return { url: metadata.hlsManifestUrl, referer: 'https://ok.ru/' };
                        }
                    } catch (e) {}
                }
            }
        }

        // 7. MixDrop (reconstrucción extractor Kodi Balandro)
        if (/(?:mixdrop\.(?:co|to|ch|bz|ms))/i.test(lower)) {
            const mdUrl = embedUrl.replace('/f/', '/e/');
            const html = await httpGet(mdUrl, { timeout: 4000, headers: { Referer: 'https://mixdrop.co/' } });
            if (html) {
                const packedBlocks = html.match(/eval\(function\(p,a,c,k,e,[rd]\).*?\.split\(['"]\|['"]\)\)\)/gs) || [];
                for (const block of packedBlocks) {
                    const unpacked = unpackDeanEdwards(block);
                    const wm = unpacked.match(/wurl\s*=\s*["']([^"']+)["']/i);
                    if (wm) {
                        const direct = wm[1].startsWith('//') ? `https:${wm[1]}` : (wm[1].startsWith('http') ? wm[1] : `https:${wm[1]}`);
                        return { url: direct, referer: 'https://mixdrop.co/' };
                    }
                }
            }
        }
    } catch (e) {}

    return null;
}

/**
 * Devuelve la URL directa de video para reproducción nativa en Stremio sin pasar por proxies.
 * Stremio cuenta con su propio reproductor nativo (ExoPlayer en Android TV/móvil, MPV/VLC en PC)
 * que reproduce HLS (.m3u8), MP4 y MKV directamente pasando los encabezados de behaviorHints.proxyHeaders.
 */
export function makeStreamUrl(rawUrl, referer = '', baseUrl = '') {
    if (!rawUrl) return '';
    return rawUrl;
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

    let alternativeTitles = [];
    if (tmdbId && isAnime) {
        try {
            const altData = await httpGet(`https://api.themoviedb.org/3/tv/${tmdbId}/alternative_titles?api_key=${TMDB_API_KEY}`, { json: true, timeout: 3000 });
            for (const item of (altData?.results || [])) {
                if (item.title && typeof item.title === 'string') {
                    alternativeTitles.push(item.title.trim());
                }
            }
        } catch (e) {}
    }

    const meta = {
        type: isMovie ? 'movie' : 'series',
        imdbId,
        tmdbId,
        title: titleEs || titleEn || titleOrig,
        originalTitle: titleOrig || titleEn || titleEs,
        englishTitle: titleEn || titleOrig || titleEs,
        alternativeTitles: Array.from(new Set(alternativeTitles)),
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
async function scrapeLaMovie(meta, baseUrl = '') {
    const fastApi = 'https://lamovie.org/wp-api/v1';
    const headers = { Referer: 'https://lamovie.org/', Origin: 'https://lamovie.org', Accept: 'application/json' };
    const candidateEmbeds = [];
    const streams = [];

    const labelTitle = meta.type === 'movie'
        ? `${meta.title} (${meta.year || 'HD'})`
        : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;

    // A. Intentar API oficial actual de LaMovie (wp-api/v1)
    const queries = Array.from(new Set([meta.title, meta.originalTitle, ...(meta.alternativeTitles || [])].filter(Boolean)));
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

            // Si LaMovie incluye torrents en downloads, extraer magnet e infoHash con metadatos reales
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
                    const releaseName = dnMatch ? decodeURIComponent(dnMatch[1].replace(/\+/g, ' ')) : (meta.title || 'Movie');
                    const flagLine = (langInfo.langKey === 'castellano') ? '🇪🇸 Spanish' : 'Dual Audio / 🇲🇽';

                    let size = '';
                    const xlM = decMag.match(/[?&]xl=(\d+)/i);
                    if (xlM) {
                        size = formatBytes(xlM[1]);
                    } else {
                        size = parseTorrentSize(decMag);
                    }

                    const formattedTitle = formatTorrentioTitle({
                        releaseTitle: releaseName,
                        seeders: 0,
                        size: size,
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
                        _seeders: 0,
                        _size: size,
                        _releaseTitle: releaseName,
                        _tracker: 'LaMovie P2P',
                        _flagLine: flagLine
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
            url: makeStreamUrl(resolved.url, resolved.referer || 'https://lamovie.org/', baseUrl),
            _lang: emb.langKey,
            _prov: 'lamovie',
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
async function scrapeKaoOrPelisPlus(meta, providerId = 'serieskao', baseUrl = '') {
    const host = providerId === 'pelisplus' ? 'https://pelisplushd.bz' : 'https://serieskao.top';
    const providerLabel = providerId === 'pelisplus' ? 'PelisPlus' : 'SeriesKao';

    const queries = Array.from(new Set([meta.title, meta.originalTitle, ...(meta.alternativeTitles || [])].filter(Boolean)));
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
            url: makeStreamUrl(res.url, res.referer || `${host}/`, baseUrl),
            _lang: emb.langKey,
            _prov: providerId,
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
async function scrapeLatAnime(meta, baseUrl = '') {
    if (!meta.isAnime && meta.type === 'movie') return [];
    const host = 'https://latanime.org';
    const baseQueries = Array.from(new Set([meta.title, meta.originalTitle, ...(meta.alternativeTitles || [])].filter(Boolean)));
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
                        url: makeStreamUrl(r.url, r.referer || `${host}/`, baseUrl),
                        _lang: langKey,
                        _prov: 'latanime',
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
 * 4. Scraper: Cuevana 3 / PoseidonHD (__NEXT_DATA__ JSON — Soporte espejos actualizados Balandro)
 */
async function scrapeCuevanaOrPoseidon(meta, providerId = 'cuevana', baseUrl = '') {
    const candidateHosts = providerId === 'poseidonhd'
        ? ['https://poseidonhd2.co']
        : ['https://cuevanapro.org', 'https://ver.cuevana3.one', 'https://www.cuevana-3.mx'];
    const providerLabel = providerId === 'poseidonhd' ? 'PoseidonHD' : 'Cuevana 3';

    const queries = Array.from(new Set([meta.title, meta.originalTitle, ...(meta.alternativeTitles || [])].filter(Boolean)));
    let matchedItem = null;
    let host = candidateHosts[0];

    for (const candHost of candidateHosts) {
        for (const q of queries.slice(0, 2)) {
            const searchUrl = `${candHost}/search?q=${encodeURIComponent(q)}`;
            const html = await httpGet(searchUrl, { timeout: 3800 });
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
                    host = candHost;
                    break;
                }
                const candTitle = item?.titles?.name || '';
                if (isTitleMatch(meta.title, candTitle) || isTitleMatch(meta.originalTitle, candTitle)) {
                    matchedItem = item;
                    host = candHost;
                    break;
                }
            }
            if (matchedItem) break;
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
            url: makeStreamUrl(r.url, r.referer || `${host}/`, baseUrl),
            _lang: srv.langKey,
            _prov: providerId,
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
 * 5. Scraper: CDN Propio (Rakun Cloud & Bunny CDN VIP)
 */
export async function scrapeLocalCdn(meta, baseUrl = '') {
    const streams = [];
    const headers = {
        'User-Agent': 'RakunTv/2.0 (Android TV; OkHttp/4.9.2)',
        'Accept': 'application/json'
    };

    // Mapeo directo de TMDB IDs a IDs de Rakun para títulos populares
    const tmdbToRakun = {
        // Películas
        1318413: 'c_3d8l7ewcnq', 1128204: 'c_xq65wtzxir', 390061: 'c_dwqx1ssbjq',
        354912: 'c_g01x2i8tvk',  404368: 'c_l9y3k4s9ly',  82690: 'c_9ioyoi9kdv',
        49519: 'c_oxjod1jgnj',   38055: 'c_5w1gnqdgxw',   269149: 'c_bg3v7djvr1',
        93456: 'c_gmo95z7uaq',
        // Series
        1399: 'c_fzp45tihaf',    66732: 'c_bf1wb9cv1f',   200875: 'c_v872k399g2',
        94605: 's_arcane_s2',    218896: 'c_4x3ngzq8gl',  213713: 'c_5hi0qhdfia',
        258903: 'c_d6j9dg52lx',  263622: 'c_ftt65kqn4t'
    };

    try {
        let contentData = null;

        if (meta.type === 'movie') {
            let targetId = meta.tmdbId ? tmdbToRakun[meta.tmdbId] : null;

            // 1. Probar consulta directa por TMDB ID (mv_{tmdbId})
            if (!targetId && meta.tmdbId) {
                const directCheck = await httpGet(`https://api.rakun.lat/api/content/mv_${meta.tmdbId}`, { json: true, headers, timeout: 3500 });
                if (directCheck?.id && (directCheck.streamLinks?.length || directCheck.streamUrl)) {
                    contentData = directCheck;
                }
            }

            // 2. Si no se resolvió directo, buscar por títulos (español, original, inglés)
            if (!contentData && !targetId) {
                const searchQueries = Array.from(new Set([meta.title, meta.originalTitle, meta.englishTitle].filter(Boolean)));
                for (const q of searchQueries.slice(0, 2)) {
                    const searchRes = await httpGet(`https://api.rakun.lat/api/content?search=${encodeURIComponent(q)}`, { json: true, headers, timeout: 4000 });
                    if (Array.isArray(searchRes) && searchRes.length > 0) {
                        const match = searchRes.find(i => {
                            if (i.category !== 'movies' && i.category !== 'kids') return false;
                            const itemTmdb = Number(i.tmdbId || i.tmdb_id || 0);
                            if (meta.tmdbId && itemTmdb > 0) {
                                return itemTmdb === Number(meta.tmdbId);
                            }
                            const candYear = i.year || (i.release_date ? String(i.release_date).slice(0, 4) : null);
                            return isTitleMatch(meta.title, i.title || '', meta.year, candYear) ||
                                   (meta.originalTitle && isTitleMatch(meta.originalTitle, i.title || '', meta.year, candYear)) ||
                                   (meta.englishTitle && isTitleMatch(meta.englishTitle, i.title || '', meta.year, candYear));
                        });
                        if (match?.id) {
                            targetId = match.id;
                            break;
                        }
                    }
                }
            }

            // 3. OBTENER SIEMPRE EL DETALLE FRESCO POR ID (content/{id}) PARA OBTENER ENLACES CON TOKENS REALES VÁLIDOS
            if (!contentData && targetId) {
                contentData = await httpGet(`https://api.rakun.lat/api/content/${targetId}`, { json: true, headers, timeout: 4500 });
            }
        } else if (meta.type === 'series') {
            let targetId = meta.tmdbId ? tmdbToRakun[meta.tmdbId] : null;
            let seriesDetail = null;

            // 1. Probar consulta directa por TMDB ID (sr_{tmdbId})
            if (!targetId && meta.tmdbId) {
                const directCheck = await httpGet(`https://api.rakun.lat/api/content/sr_${meta.tmdbId}`, { json: true, headers, timeout: 3500 });
                if (directCheck?.id && Array.isArray(directCheck.seasons) && directCheck.seasons.length > 0) {
                    seriesDetail = directCheck;
                }
            }

            // 2. Si no se resolvió directo, buscar por títulos
            if (!seriesDetail && !targetId) {
                const searchQueries = Array.from(new Set([meta.title, meta.originalTitle, meta.englishTitle].filter(Boolean)));
                for (const q of searchQueries.slice(0, 2)) {
                    const searchRes = await httpGet(`https://api.rakun.lat/api/content?search=${encodeURIComponent(q)}`, { json: true, headers, timeout: 4000 });
                    if (Array.isArray(searchRes) && searchRes.length > 0) {
                        const match = searchRes.find(i => {
                            if (i.category !== 'series' && i.category !== 'anime' && !i.seasons) return false;
                            const itemTmdb = Number(i.tmdbId || i.tmdb_id || 0);
                            if (meta.tmdbId && itemTmdb > 0) {
                                return itemTmdb === Number(meta.tmdbId);
                            }
                            const candYear = i.year || (i.first_air_date ? String(i.first_air_date).slice(0, 4) : null);
                            return isTitleMatch(meta.title, i.title || '', meta.year, candYear) ||
                                   (meta.originalTitle && isTitleMatch(meta.originalTitle, i.title || '', meta.year, candYear)) ||
                                   (meta.englishTitle && isTitleMatch(meta.englishTitle, i.title || '', meta.year, candYear));
                        });
                        if (match?.id) {
                            targetId = match.id;
                            break;
                        }
                    }
                }
            }

            // 3. OBTENER SIEMPRE EL DETALLE FRESCO POR ID (content/{id}) PARA OBTENER TODAS LAS TEMPORADAS CON TOKENS VÁLIDOS
            if (!seriesDetail && targetId) {
                seriesDetail = await httpGet(`https://api.rakun.lat/api/content/${targetId}`, { json: true, headers, timeout: 4500 });
            }

            if (seriesDetail?.seasons && Array.isArray(seriesDetail.seasons)) {
                const seasonObj = seriesDetail.seasons.find(s => Number(s.season_number || s.number) === Number(meta.season));
                if (seasonObj?.episodes && Array.isArray(seasonObj.episodes)) {
                    contentData = seasonObj.episodes.find(e => Number(e.episode || e.episode_number) === Number(meta.episode));
                }
            }
        }

        if (!contentData) return [];

        const rawLinks = [];
        if (Array.isArray(contentData.streamLinks) && contentData.streamLinks.length > 0) {
            rawLinks.push(...contentData.streamLinks);
        } else if (contentData.streamUrl) {
            rawLinks.push({ url: contentData.streamUrl, type: 'free', quality: 'HD' });
        }

        const seenUrls = new Set();
        const effectiveBaseUrl = (baseUrl || '').replace(/\/+$/, '');

        for (const lnk of rawLinks) {
            let directUrl = lnk.url || '';
            if (!directUrl) continue;

            const cleanBase = directUrl.split('?')[0];
            if (seenUrls.has(cleanBase)) continue;
            seenUrls.add(cleanBase);

            const isVip = directUrl.includes('b-cdn.net') || lnk.type === 'premium';
            const serverLabel = isVip ? 'Rakun VIP (Bunny CDN)' : 'Rakun Cloud CDN';
            const labelQuality = (lnk.quality && lnk.quality !== 'SD') ? `${lnk.quality.toUpperCase()} HD` : '1080p FHD';
            const flag = '🇲🇽';

            // Entregar la URL directa firmada por el CDN para máxima velocidad de reproducción nativa en Stremio
            const streamUrl = directUrl;

            const labelTitle = meta.type === 'movie'
                ? `${meta.title} (${meta.year || 'HD'})`
                : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;

            streams.push({
                name: `${flag} ${isVip ? 'Rakun VIP' : 'Rakun CDN'}\n${labelQuality}`,
                title: `${labelTitle}\n⚡ Cloud CDN (${serverLabel}) • 🇲🇽 Español Latino`,
                url: streamUrl,
                _lang: 'latino',
                _prov: 'local_cdn',
                behaviorHints: {
                    notWebReady: false,
                    bingeGroup: `streammedia-rakun-latino`,
                    proxyHeaders: {
                        request: {
                            'User-Agent': 'RakunTv/2.0 (Android TV; OkHttp/4.9.2)',
                            'Accept': '*/*'
                        }
                    }
                }
            });
        }
    } catch (e) {
        // Silently continue
    }

    return streams;
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

                if (decryptedUrl.includes('yadi.sk') || decryptedUrl.includes('disk.yandex')) {
                    serverLabel = 'Yandex CDN';
                    const cached = getCached(yandexDownloadCache, decryptedUrl, 30 * 60 * 1000);
                    if (cached) {
                        directDownloadUrl = cached;
                    } else if (effectiveBaseUrl) {
                        directDownloadUrl = `${effectiveBaseUrl}/movieplus-stream?url=${encodeURIComponent(decryptedUrl)}`;
                    } else {
                        const resolved = await resolveYandexDownloadUrl(decryptedUrl);
                        if (resolved) {
                            directDownloadUrl = resolved;
                        } else {
                            continue;
                        }
                    }
                } else if (decryptedUrl.includes('mega.nz')) {
                    continue; // Stremio no reproduce enlaces de página web de mega.nz
                }

                const langInfo = parseMoviePlusLanguage(rawQuality);
                const cleanQ = cleanMoviePlusQuality(rawQuality);

                // Entrega de URL directa del CDN (ej: Yandex CDN) para reproducción nativa sin sobrecargar el servidor
                const streamUrl = directDownloadUrl;

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

                    if (decryptedUrl.includes('yadi.sk') || decryptedUrl.includes('disk.yandex')) {
                        serverLabel = 'Yandex CDN';
                        const cached = getCached(yandexDownloadCache, decryptedUrl, 30 * 60 * 1000);
                        if (cached) {
                            directDownloadUrl = cached;
                        } else if (effectiveBaseUrl) {
                            directDownloadUrl = `${effectiveBaseUrl}/movieplus-stream?url=${encodeURIComponent(decryptedUrl)}`;
                        } else {
                            const resolved = await resolveYandexDownloadUrl(decryptedUrl);
                            if (resolved) {
                                directDownloadUrl = resolved;
                            } else {
                                continue;
                            }
                        }
                    } else if (decryptedUrl.includes('mega.nz')) {
                        continue; // Stremio no reproduce enlaces de página web de mega.nz
                    }

                    const langInfo = parseMoviePlusLanguage(epTitle);
                    // Entrega de URL directa del CDN (ej: Yandex CDN) para reproducción nativa sin sobrecargar el servidor
                    const streamUrl = directDownloadUrl;

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
 * Analiza y extrae seeders de forma robusta soportando separadores de miles y sufijos (ej: "1,250", "2.1k", "👤 80")
 */
function parseSeeders(text) {
    if (!text) return 0;
    const str = String(text);
    const m = str.match(/(?:👤|seeds?|seeders?|peers?|s:)\s*([0-9]+(?:[.,][0-9]+)*\s*k?)/iu);
    if (!m) {
        const directNum = str.match(/\b([0-9]+)\s*seeds?\b/i);
        if (directNum) return parseInt(directNum[1], 10) || 0;
        return 0;
    }
    let val = m[1].trim().toLowerCase();
    if (val.endsWith('k')) {
        return Math.round(parseFloat(val.replace('k', '').replace(',', '.')) * 1000) || 0;
    }
    val = val.replace(/[,.]/g, '');
    const num = parseInt(val, 10);
    return isNaN(num) ? 0 : num;
}

/**
 * Normaliza unidades de tamaño binarias a decimales estándar (GiB -> GB, MiB -> MB) para consistencia en Stremio
 */
function normalizeSizeUnit(sizeStr) {
    if (!sizeStr) return '';
    return String(sizeStr).trim()
        .replace(/GiB/i, 'GB')
        .replace(/MiB/i, 'MB')
        .replace(/TiB/i, 'TB')
        .replace(/KiB/i, 'KB');
}

/**
 * Analiza y extrae el tamaño de un torrent soportando GB, MB, TB, GiB, MiB, etc.
 */
function parseTorrentSize(text) {
    if (!text) return '';
    const str = String(text);
    // 1. Con emoji 💾
    const mEmoji = str.match(/💾\s*([0-9]+(?:[.,][0-9]+)?\s*(?:[TGMK]i?B|bytes?))/iu);
    if (mEmoji) {
        return normalizeSizeUnit(mEmoji[1].trim());
    }
    // 2. Con delimitadores o corchetes: [2.4 GB] o (1.5 GiB) o • 2.4 GB
    const mDelim = str.match(/(?:\[|\(|\•|\b)([0-9]+(?:[.,][0-9]+)?\s*(?:GB|MB|TB|KB|GiB|MiB|TiB|KiB))\b/i);
    if (mDelim) {
        return normalizeSizeUnit(mDelim[1].trim());
    }
    return '';
}

/**
 * Convierte bytes numéricos a cadena legible (MB, GB, TB)
 */
function formatBytes(bytes) {
    const num = Number(bytes);
    if (!num || isNaN(num) || num <= 0) return '';
    if (num >= 1024 * 1024 * 1024 * 1024) return (num / (1024 * 1024 * 1024 * 1024)).toFixed(2) + ' TB';
    if (num >= 1024 * 1024 * 1024) return (num / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    if (num >= 1024 * 1024) return (num / (1024 * 1024)).toFixed(1) + ' MB';
    if (num >= 1024) return (num / 1024).toFixed(0) + ' KB';
    return num + ' B';
}

/**
 * Scraper en tiempo real de trackers públicos para infoHashes sin seeders
 */
async function scrapeLiveSeeders(infoHash) {
    if (!infoHash || infoHash.length !== 40) return 0;
    try {
        let binary = '';
        for (let i = 0; i < infoHash.length; i += 2) {
            binary += '%' + infoHash.substr(i, 2);
        }
        const trackerUrls = [
            `http://tracker.opentrackr.org:1337/scrape?info_hash=${binary}`,
            `http://open.stealth.si:80/scrape?info_hash=${binary}`
        ];
        for (const trUrl of trackerUrls) {
            try {
                const res = await httpGet(trUrl, { timeout: 1500 });
                if (res) {
                    const m = String(res).match(/completei(\d+)e/);
                    if (m) {
                        const seeds = parseInt(m[1], 10);
                        if (seeds > 0) return seeds;
                    }
                }
            } catch (e) {}
        }
    } catch (e) {}
    return 0;
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

            // Extraer seeders y tamaño de forma robusta
            const seeders = parseSeeders(rawTitle);
            const size = parseTorrentSize(rawTitle);

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
                _seeders: seeders,
                _size: size,
                _releaseTitle: releaseLine,
                _tracker: trackerShort,
                _flagLine: flagLine
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
            const ytsSize = t.size || formatBytes(t.size_bytes);
            const formattedTitle = formatTorrentioTitle({
                releaseTitle,
                seeders: t.seeds || 0,
                size: ytsSize,
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
                _seeders: t.seeds || 0,
                _size: ytsSize,
                _releaseTitle: releaseTitle,
                _tracker: 'YTS',
                _flagLine: '🇺🇸 English'
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
                const seeders = parseSeeders(rawTitle);
                const size = parseTorrentSize(rawTitle);
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
                    _seeders: seeders,
                    _size: size,
                    _releaseTitle: releaseTitle,
                    _tracker: 'YTS',
                    _flagLine: '🇺🇸 English'
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
        const rawSize = block.match(/<nyaa:size>(.*?)<\/nyaa:size>/)?.[1] || '';
        const size = normalizeSizeUnit(rawSize);
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
            _seeders: seeders,
            _size: size,
            _releaseTitle: title,
            _tracker: 'Nyaa.si',
            _flagLine: langInfo.flagLine
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
            const sizeStr = formatBytes(item.size);
            const qMatch = name.match(/\b(2160p|4k|1080p|720p)\b/i);
            const quality = qMatch ? qMatch[1].toUpperCase() : '1080P';

            const formattedTitle = formatTorrentioTitle({
                releaseTitle: name,
                seeders,
                size: sizeStr,
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
                _seeders: seeders,
                _size: sizeStr,
                _releaseTitle: name,
                _tracker: 'ThePirateBay',
                _flagLine: langInfo.flagLine
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
            const seeders = parseSeeders(rawTitle);
            const size = parseTorrentSize(rawTitle);

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
                _seeders: seeders,
                _size: size,
                _releaseTitle: releaseTitle,
                _tracker: 'ThePirateBay',
                _flagLine: flagLine
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
 * 9.1 Scraper: PelisForte (Películas y Series HD)
 * Busca en https://www2.pelisforte.se y https://pelisforte.se
 * Resuelve Vidara (as9u7bgh9.com / vidara.to) y otros embeds vía resolveEmbedToHls
 */
async function scrapePelisForte(meta, baseUrl = '') {
    if (!meta.title) return [];
    const streams = [];
    const candidateHosts = ['https://www2.pelisforte.se', 'https://pelisforte.se'];
    const searchTerms = Array.from(new Set([
        meta.title,
        meta.originalTitle,
        String(meta.title || '').replace(/^(?:the|los|las|el|la)\s+/i, '')
    ].filter(Boolean)));

    let detailUrl = null;
    let successfulHost = candidateHosts[0];

    for (const host of candidateHosts) {
        for (const term of searchTerms.slice(0, 2)) {
            const searchUrl = `${host}/?s=${encodeURIComponent(term)}`;
            const html = await httpGet(searchUrl, { timeout: 3500 });
            if (!html || html.length < 500) continue;

            const articles = [...html.matchAll(/<article[^>]*>([\s\S]*?)<\/article>/gi)];
            for (const art of articles) {
                const u = art[1].match(/<a[^>]+href="([^"]+)"/i)?.[1];
                const t = art[1].match(/class="entry-title">([^<]+)<\//i)?.[1]?.trim();
                const y = art[1].match(/<span class="Year">([^<]+)<\/span>/i)?.[1]?.trim();

                if (u && t && isTitleMatch(meta.title, t, meta.year, y)) {
                    detailUrl = u;
                    successfulHost = host;
                    break;
                }
            }
            if (detailUrl) break;
        }
        if (detailUrl) break;
    }

    if (!detailUrl) return [];

    const detHtml = await httpGet(detailUrl, { timeout: 4000 });
    if (!detHtml) return [];

    const optMatches = [...detHtml.matchAll(/href="#options-(\d+)">.*?<span class="server">([^<]+)<\/span>/gis)];
    const embedTasks = [];

    for (const om of optMatches) {
        const optId = om[1];
        const rawServerInfo = om[2].trim();

        let lang = 'latino';
        let flag = '🇲🇽';
        let langLabel = 'Español Latino';
        if (/castellano/i.test(rawServerInfo)) {
            lang = 'castellano';
            flag = '🇪🇸';
            langLabel = 'Español Castellano';
        } else if (/subtitulado|vose/i.test(rawServerInfo)) {
            lang = 'sub';
            flag = '💬';
            langLabel = 'Subtitulado (VOSE)';
        }

        const srcRe = new RegExp(`<div[^>]+id="options-${optId}"[^>]*>.*?src="([^"]+)"`, 'is');
        const srcM = detHtml.match(srcRe);
        if (!srcM) continue;

        const rawUrl = srcM[1].trim();
        embedTasks.push((async () => {
            try {
                let targetEmbed = rawUrl;
                if (targetEmbed.includes('/mp4.nu/')) {
                    const rUrl = targetEmbed.replace('/mp4.nu/?h=', '/mp4.nu/r.php?h=');
                    try {
                        const headRes = await fetch(rUrl, {
                            method: 'HEAD',
                            headers: { 'User-Agent': UA, 'Referer': `${successfulHost}/` },
                            redirect: 'manual',
                            signal: AbortSignal.timeout(3000)
                        });
                        const loc = headRes.headers.get('location');
                        if (loc) targetEmbed = loc;
                    } catch {}
                }

                const resolved = await resolveEmbedToHls(targetEmbed, `${successfulHost}/`);
                if (resolved?.url) {
                    let sName = 'Vidara';
                    if (targetEmbed.includes('wish')) sName = 'StreamWish';
                    else if (targetEmbed.includes('voe')) sName = 'Voe';
                    else if (targetEmbed.includes('vidhide')) sName = 'VidHide';
                    else if (targetEmbed.includes('fastream')) sName = 'Fastream';

                    return {
                        name: `${flag} PelisForte\n1080p HD`,
                        title: `${meta.title} (${meta.year || 'HD'})\n⚡ ${sName} HLS • ${langLabel}`,
                        url: resolved.url,
                        _lang: lang,
                        _prov: 'pelisforte',
                        behaviorHints: {
                            notWebReady: false,
                            proxyHeaders: { request: { 'Referer': resolved.referer || `${successfulHost}/`, 'User-Agent': UA } }
                        }
                    };
                }
            } catch (e) {}
            return null;
        })());
    }

    const settled = await Promise.all(embedTasks);
    streams.push(...settled.filter(Boolean));
    return streams;
}

/**
 * 9.2 Scraper: PelisPedia (Películas y Series HD)
 * Busca en https://pelispedia.is
 * Extrae iframes trembed y resuelve Fastream / StreamWish / Voe vía resolveEmbedToHls
 */
async function scrapePelisPedia(meta, baseUrl = '') {
    if (!meta.title) return [];
    const streams = [];
    const host = 'https://pelispedia.is';
    const searchTerms = Array.from(new Set([
        meta.title,
        meta.originalTitle,
        String(meta.title || '').replace(/^(?:the|los|las|el|la)\s+/i, '')
    ].filter(Boolean)));

    let detailUrl = null;

    if (meta.type === 'movie') {
        for (const term of searchTerms.slice(0, 2)) {
            const html = await httpGet(`${host}/?s=${encodeURIComponent(term)}`, { timeout: 3500 });
            if (!html || html.length < 500) continue;

            const movieLinks = [...html.matchAll(/<a[^>]+href="(https:\/\/pelispedia\.is\/pelicula\/[^"]+)"/gi)].map(m => m[1]);
            for (const link of Array.from(new Set(movieLinks)).slice(0, 4)) {
                const slug = link.replace(/\/+$/, '').split('/').pop();
                const candTitle = slug.replace(/-/g, ' ');
                const ym = slug.match(/\b(19\d{2}|20\d{2})\b/);
                const candYear = ym ? ym[1] : null;

                if (isTitleMatch(meta.title, candTitle, meta.year, candYear)) {
                    detailUrl = link;
                    break;
                }
            }
            if (detailUrl) break;
        }
    } else if (meta.type === 'series') {
        for (const term of searchTerms.slice(0, 2)) {
            const html = await httpGet(`${host}/?s=${encodeURIComponent(term)}`, { timeout: 3500 });
            if (!html || html.length < 500) continue;

            const articles = [...html.matchAll(/<article[^>]*>([\s\S]*?)<\/article>/gi)];
            for (const art of articles) {
                const sm = art[1].match(/href="(https:\/\/pelispedia\.is\/(?:serie|series|anime)\/([^"\/]+)\/?)"/i);
                if (!sm) continue;
                const slug = sm[2];
                const candTitle = slug.replace(/-/g, ' ');
                if (isTitleMatch(meta.title, candTitle)) {
                    detailUrl = `${host}/capitulo/${slug}-temporada-${meta.season}-capitulo-${meta.episode}/`;
                    break;
                }
            }
            if (detailUrl) break;
        }
    }

    if (!detailUrl) return [];

    const detHtml = await httpGet(detailUrl, { timeout: 4000 });
    if (!detHtml) return [];

    const trembeds = [...detHtml.matchAll(/<iframe[^>]+src="([^"]*trembed=[^"]*)"/gi)].map(m => m[1]);
    const embedTasks = [];

    for (const rawTrembed of Array.from(new Set(trembeds)).slice(0, 5)) {
        const cleanTrembed = rawTrembed.replace(/&#038;|&amp;/g, '&');
        embedTasks.push((async () => {
            try {
                const ifrHtml = await httpGet(cleanTrembed, {
                    timeout: 3500,
                    headers: { Referer: detailUrl }
                });
                if (!ifrHtml) return null;

                const srcM = ifrHtml.match(/<iframe[^>]+src="([^"]+)"/i);
                if (!srcM) return null;

                const embedUrl = srcM[1];
                const resolved = await resolveEmbedToHls(embedUrl, detailUrl);
                if (resolved?.url) {
                    let sName = 'Fastream';
                    if (embedUrl.includes('wish')) sName = 'StreamWish';
                    else if (embedUrl.includes('voe')) sName = 'Voe';
                    else if (embedUrl.includes('vidhide')) sName = 'VidHide';

                    return {
                        name: `🇲🇽 PelisPedia\n1080p HD`,
                        title: `${meta.title} (${meta.year || 'HD'})\n⚡ ${sName} HLS • 🇲🇽 Audio Español Latino`,
                        url: resolved.url,
                        _lang: 'latino',
                        _prov: 'pelispedia',
                        behaviorHints: {
                            notWebReady: false,
                            proxyHeaders: { request: { 'Referer': resolved.referer || 'https://pelispedia.is/', 'User-Agent': UA } }
                        }
                    };
                }
            } catch (e) {}
            return null;
        })());
    }

    const settled = await Promise.all(embedTasks);
    streams.push(...settled.filter(Boolean));
    return streams;
}

/**
 * 10. Scraper: Cinecalidad (Torrents Dual Latino y Embeds HLS — Soporte multi-espejo según Balandro)
 */
async function scrapeCinecalidad(meta) {
    const candidateHosts = ['https://www.cinecalidad.vg', 'https://cine-calidad.mx', 'https://www.cinecalidad.am', 'https://www.cinecalidad.ro'];
    const searchTerms = Array.from(new Set([
        meta.title,
        meta.originalTitle,
        String(meta.title || '').replace(/^(?:the|los|las|el|la)\s+/i, '')
    ].map(s => String(s || '').replace(/[^\w\s]/gu, ' ').replace(/\s+/g, ' ').trim()).filter(Boolean)));

    let detailUrl = null;
    let successfulHost = candidateHosts[0];

    for (const host of candidateHosts) {
        for (const term of searchTerms.slice(0, 2)) {
            const html = await httpGet(`${host}/?s=${encodeURIComponent(term)}`, { timeout: 3500 });
            if (!html || html.length < 300) continue;

            if (meta.type === 'movie') {
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
                        successfulHost = host;
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
                        const sHtml = await httpGet(seriesUrl, { timeout: 3500 });
                        if (sHtml) {
                            const epRe = new RegExp(`href=(["']?)(https?:\\/\\/[^"'\\s>]*\\/(?:episodio|episodes)\\/[^"'\\s>]*-${meta.season}x${meta.episode}\\/?)\\1`, 'i');
                            const epM = sHtml.match(epRe);
                            if (epM) {
                                detailUrl = epM[2];
                                successfulHost = host;
                            }
                        }
                        break;
                    }
                }
            }
            if (detailUrl) break;
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

        let size = '';
        const xlM = dec.match(/[?&]xl=(\d+)/i);
        if (xlM) {
            size = formatBytes(xlM[1]);
        } else {
            size = parseTorrentSize(dec);
        }

        const formattedTitle = formatTorrentioTitle({
            releaseTitle: relTitle,
            seeders: 0, // Será enriquecido por Torrentio o live tracker si está activo
            size: size,
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
            _seeders: 0,
            _size: size,
            _releaseTitle: relTitle,
            _tracker: 'Cinecalidad',
            _flagLine: 'Dual Audio / 🇲🇽'
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
            _prov: 'cinecalidad',
            behaviorHints: {
                notWebReady: false,
                proxyHeaders: { request: { 'Referer': r.referer || `${successfulHost}/`, 'User-Agent': UA } }
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
                    _prov: 'jkanime',
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
                            _prov: 'animeflv',
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
                        _prov: 'animeflv',
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
    if (cfg.providers.has('local_cdn')) tasks.push(scrapeLocalCdn(meta, baseUrl));
    if (cfg.providers.has('lamovie')) tasks.push(scrapeLaMovie(meta));
    if (cfg.providers.has('pelisforte')) tasks.push(scrapePelisForte(meta, baseUrl));
    if (cfg.providers.has('pelispedia')) tasks.push(scrapePelisPedia(meta, baseUrl));
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

    // Deduplicar torrents P2P por infoHash fusionando los mejores metadatos (seeders y tamaño verificados)
    const hashIndex = new Map();
    let allStreams = [];

    for (const s of rawStreams) {
        if (!s.infoHash) {
            allStreams.push(s);
            continue;
        }

        const hKey = (meta.type === 'movie' || s.fileIdx === undefined)
            ? s.infoHash.toLowerCase()
            : `${s.infoHash.toLowerCase()}:${s.fileIdx}`;

        if (!hashIndex.has(hKey)) {
            hashIndex.set(hKey, s);
            allStreams.push(s);
        } else {
            const existing = hashIndex.get(hKey);

            // Fusionar seeders y tamaño reales
            const sSeeds = s._seeders || 0;
            const exSeeds = existing._seeders || 0;
            const bestSeeds = Math.max(sSeeds, exSeeds);

            const sSize = s._size || '';
            const exSize = existing._size || '';
            const bestSize = (exSize && exSize !== 'Desconocido') ? exSize : sSize;

            if (bestSeeds > exSeeds || (!exSize && bestSize)) {
                existing._seeders = bestSeeds;
                existing._size = bestSize;
                existing.title = formatTorrentioTitle({
                    releaseTitle: existing._releaseTitle || s._releaseTitle || existing.title.split('\n')[0],
                    seeders: bestSeeds,
                    size: bestSize,
                    tracker: existing._tracker || s._tracker || 'P2P',
                    flagLine: existing._flagLine || s._flagLine || ''
                });
            }

            if (s.sources && Array.isArray(s.sources)) {
                existing.sources = Array.from(new Set([...(existing.sources || []), ...s.sources]));
            }
        }
    }

    // Para torrents que aún tengan 0 seeders (ej. enlaces exclusivos de Cinecalidad o LaMovie):
    // consultar el tracker público opentrackr en paralelo
    const zeroSeedTorrents = allStreams.filter(s => s.infoHash && (!s._seeders || s._seeders === 0)).slice(0, 5);
    if (zeroSeedTorrents.length > 0) {
        await Promise.all(zeroSeedTorrents.map(async (tStream) => {
            const liveSeeds = await scrapeLiveSeeders(tStream.infoHash);
            if (liveSeeds > 0) {
                tStream._seeders = liveSeeds;
                tStream.title = formatTorrentioTitle({
                    releaseTitle: tStream._releaseTitle || tStream.title.split('\n')[0],
                    seeders: liveSeeds,
                    size: tStream._size || '',
                    tracker: tStream._tracker || 'P2P',
                    flagLine: tStream._flagLine || ''
                });
            }
        }));
    }

    // Filtrar por idiomas seleccionados, pero NUNCA ocultar los scrapers P2P dedicados (YTS, PirateBay, Nyaa) si el usuario los dejó activos
    const dedicatedP2p = new Set(['cinecalidad', 'lamovie_p2p', 'tpb', 'yts', 'nyaa']);
    const langFiltered = allStreams.filter(s => !s._lang || cfg.langs.has(s._lang) || dedicatedP2p.has(s._prov));
    if (langFiltered.length > 0) {
        allStreams = langFiltered;
    }

    // Jerarquía de ordenación estricta:
    // 1° Grupo de Idioma: Cualquier opción que contenga Español Latino (Latino o Multi con Latino) PRIMERO.
    //    Los que no contengan Latino (Castellano, Subtitulado, Audio Original) van al FINAL.
    // 2° Tipo de Servidor (Tier):
    //    Tier 1: HLS Directo (.m3u8 desde servidores web / embeds: SeriesKao, PelisForte, PelisPedia, Cinecalidad, LaMovie, AnimeFLV, etc.)
    //    Tier 2: Servidores CDN / Yandex / Archivos directos (Rakun Bunny VIP, Rakun Cloud CDN, Movie+ Cloud/Yandex CDN)
    //    Tier 3: Torrents P2P (ordenados por mayor número de seeders reales)
    // 3° Para igual tier e idioma:
    //    - Calidad: 4K UHD / HDR (2160p) > 1080p FHD > 720p HD
    //    - Torrents: mayor cantidad de seeders reales
    const provPriority = {
        cinecalidad: 1,
        lamovie_p2p: 2,
        tpb: 3,
        yts: 4,
        nyaa: 5,
        torrentio: 6
    };

    const isLatinoStream = (s) => {
        if (s._lang === 'sub' || s._lang === 'castellano') return false;
        if (s._lang === 'latino' || s._lang === 'multi') return true;

        const nameText = String(s.name || '');
        const titleText = String(s.title || '');

        if (/subtitulado|vose|sub latino|\bsub\b|castellano|españa|🇪🇸|💬/i.test(nameText)) return false;
        if (/audio original\s*\+\s*.*sub/i.test(titleText)) return false;

        return /\b(?:latino|audio latino|dual audio|triple audio|trial|dual|multi)\b/i.test(`${nameText} ${titleText}`) ||
               nameText.includes('🇲🇽') ||
               nameText.includes('🌐');
    };

    const getStreamTier = (s) => {
        // Tier 3: Torrents P2P al final
        if (s.infoHash) return 3;

        // Tier 2: Archivos en Servidor Propio, Cloud CDN y Yandex (Rakun CDN, Movie+ Cloud CDN)
        const isCloudOrLocal = s._prov === 'movieplus' ||
            s._prov === 'local_cdn' ||
            (s.name && /Movie\+|CDN Propio|Yandex|Rakun/i.test(s.name)) ||
            (s.title && /Cloud CDN|Rakun Cloud|Yandex CDN/i.test(s.title)) ||
            (s.url && /movieplus-stream|rakun|b-cdn\.net|cdn\.rakun/i.test(s.url));
        if (isCloudOrLocal) return 2;

        // Tier 1: Reproducibles HLS directo (.m3u8) desde servidores web / embeds
        return 1;
    };

    const getQualityRank = (s) => {
        const text = `${s.name || ''} ${s.title || ''}`;
        if (/\b(?:4k|2160p|uhd)\b/i.test(text)) return 40;
        if (/\b(?:1080p|fhd|full[\s-]?hd)\b/i.test(text)) return 30;
        if (/\b(?:720p|hd)\b/i.test(text)) return 20;
        return 25;
    };

    allStreams.sort((a, b) => {
        // 1. Grupo de Idioma: Latino / Multi primero (0), otros idiomas al final (1)
        const aLat = isLatinoStream(a) ? 0 : 1;
        const bLat = isLatinoStream(b) ? 0 : 1;
        if (aLat !== bLat) {
            return aLat - bLat;
        }

        // 2. Jerarquía de tipo (Tier 1: HLS directo -> Tier 2: CDN/Yandex -> Tier 3: Torrents)
        const aTier = getStreamTier(a);
        const bTier = getStreamTier(b);
        if (aTier !== bTier) {
            return aTier - bTier;
        }

        // 3. Diferenciación de idioma dentro del mismo grupo:
        if (aLat === 0) {
            // En el grupo Latino: Puro Latino (latino) preferido ligeramente sobre Multi (latino+castellano+inglés)
            if (a._lang === 'latino' && b._lang === 'multi') return -1;
            if (b._lang === 'latino' && a._lang === 'multi') return 1;
        } else {
            // En el grupo de Otros Idiomas: Castellano (30) > Subtitulado / VO (10)
            const otherScore = { castellano: 30, sub: 10 };
            const aScore = otherScore[a._lang] || 5;
            const bScore = otherScore[b._lang] || 5;
            if (bScore !== aScore) {
                return bScore - aScore;
            }
        }

        // 4. Ordenación interna dentro del mismo Tier:
        if (aTier === 3) {
            // Torrents: mayor cantidad de seeders reales (descendente)
            const aSeeds = Number(a._seeders) || 0;
            const bSeeds = Number(b._seeders) || 0;
            if (bSeeds !== aSeeds) {
                return bSeeds - aSeeds;
            }
            // A igualdad de seeders, proveedores dedicados antes de Torrentio
            const aProvOrder = provPriority[a._prov] || 99;
            const bProvOrder = provPriority[b._prov] || 99;
            return aProvOrder - bProvOrder;
        } else {
            // HLS o CDN: resolución y calidad (4K HDR > 1080p > 720p)
            const aQ = getQualityRank(a);
            const bQ = getQualityRank(b);
            if (bQ !== aQ) {
                return bQ - aQ;
            }
        }

        return 0;
    });

    // Limpiar propiedades internas antes de enviar a Stremio
    const cleanStreams = allStreams.map(s => {
        const copy = { ...s };
        delete copy._lang;
        delete copy._prov;
        delete copy._provRank;
        delete copy._seeders;
        delete copy._size;
        delete copy._releaseTitle;
        delete copy._tracker;
        delete copy._flagLine;
        return copy;
    });

    const payload = { streams: cleanStreams };
    if (cleanStreams.length > 0) {
        setCached(streamCache, cacheKey, payload);
    }
    return payload;
}

/**
 * Ejecuta el scraping de un único proveedor bajo demanda (usado como respaldo excepcional por la web)
 */
export async function scrapeSingleProvider(providerId, meta) {
    if (!providerId || !meta) return [];
    const p = providerId.toLowerCase();
    try {
        if (p === 'poseidonhd' || p === 'cuevana') return await scrapeCuevanaOrPoseidon(meta, p);
        if (p === 'serieskao' || p === 'pelisplus') return await scrapeKaoOrPelisPlus(meta, p);
        if (p === 'lamovie') return await scrapeLaMovie(meta);
        if (p === 'pelisforte') return await scrapePelisForte(meta);
        if (p === 'pelispedia') return await scrapePelisPedia(meta);
        if (p === 'latanime') return await scrapeLatAnime(meta);
        if (p === 'animeflv') return await scrapeAnimeFLV(meta);
        if (p === 'jkanime') return await scrapeJkAnime(meta);
        if (p === 'local_cdn' || p === 'rakun') return await scrapeLocalCdn(meta);
        if (p === 'movieplus') return await scrapeMoviePlus(meta);
        if (p === 'cinecalidad') return await scrapeCinecalidad(meta);
        if (p === 'tpb' || p === 'thepiratebay') return await scrapeTpb(meta);
        if (p === 'yts') return await scrapeYts(meta);
        if (p === 'nyaa') return await scrapeNyaa(meta);
        if (p === 'torrentio') return await scrapeTorrentio(meta);
    } catch (e) {
        console.warn(`[Fallback Scraper] Error scraping ${p}:`, e.message);
    }
    return [];
}

/**
 * Extrae los enlaces en formato web para respaldo excepcional de proveedores
 */
export async function getWebProviderFallback(providerId, meta) {
    if (!providerId || !meta) return { direct: [], streaming: [], torrent: [] };
    const p = providerId.toLowerCase();
    const links = { direct: [], streaming: [], torrent: [] };

    try {
        if (p === 'poseidonhd' || p === 'cuevana') {
            const candHosts = p === 'poseidonhd'
                ? ['https://poseidonhd2.co']
                : ['https://cuevanapro.org', 'https://ver.cuevana3.one', 'https://www.cuevana-3.mx'];
            const queries = Array.from(new Set([meta.title, meta.originalTitle, ...(meta.alternativeTitles || [])].filter(Boolean)));
            
            for (const candHost of candHosts) {
                for (const q of queries.slice(0, 2)) {
                    const searchUrl = `${candHost}/search?q=${encodeURIComponent(q)}`;
                    const html = await httpGet(searchUrl, { timeout: 3800, headers: { Referer: `${candHost}/` } });
                    if (!html) continue;

                    const mNext = html.match(/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s);
                    if (!mNext) continue;

                    let nextData = null;
                    try { nextData = JSON.parse(mNext[1]); } catch (e) { continue; }

                    const items = [
                        ...(nextData?.props?.pageProps?.movies || []),
                        ...(nextData?.props?.pageProps?.series || [])
                    ];

                    let matched = null;
                    for (const item of items) {
                        const rawSlug = item?.url?.slug || '';
                        const isMovieItem = rawSlug.startsWith('movies/') || rawSlug.startsWith('pelicula/');
                        if (meta.type === 'movie' && !isMovieItem) continue;
                        if (meta.type !== 'movie' && isMovieItem) continue;

                        if (meta.tmdbId && Number(item.TMDbId) === Number(meta.tmdbId)) {
                            matched = item;
                            break;
                        }
                        const candTitle = item?.titles?.name || '';
                        if (isTitleMatch(meta.title, candTitle) || isTitleMatch(meta.originalTitle, candTitle)) {
                            matched = item;
                            break;
                        }
                    }

                    if (matched) {
                        const rawSlug = matched.url.slug;
                        let detailUrl = '';
                        if (meta.type === 'movie') {
                            detailUrl = `${candHost}/${rawSlug.replace(/^movies\//, 'pelicula/')}`;
                        } else {
                            const serieBase = rawSlug.replace(/^series\//, 'serie/');
                            detailUrl = `${candHost}/${serieBase}/temporada/${meta.season}/episodio/${meta.episode}`;
                        }

                        const detHtml = await httpGet(detailUrl, { timeout: 4000, headers: { Referer: searchUrl } });
                        if (detHtml) {
                            const mDet = detHtml.match(/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s);
                            if (mDet) {
                                try {
                                    const detJson = JSON.parse(mDet[1]);
                                    const props = detJson?.props?.pageProps || {};
                                    const videos = props?.thisMovie?.videos || props?.episode?.videos || props?.thisEpisode?.videos || {};
                                    const langMap = {
                                        latino: ['Español Latino', 'lat'],
                                        spanish: ['Castellano', 'cast'],
                                        english: ['Subtitulado', 'sub'],
                                        japanese: ['Japonés (Sub)', 'sub']
                                    };

                                    for (const [langKey, serverList] of Object.entries(videos)) {
                                        if (!Array.isArray(serverList)) continue;
                                        const langInfo = langMap[langKey.toLowerCase()] || ['Español Latino', 'lat'];

                                        for (const sItem of serverList) {
                                            const playerUrl = sItem.result || sItem.url || '';
                                            if (!playerUrl) continue;

                                            const rawCyber = (sItem.cyberlocker || 'Online').toLowerCase();
                                            let sName = sItem.cyberlocker || 'Online Stream';
                                            if (rawCyber.includes('vidhide')) sName = 'VidHide';
                                            else if (rawCyber.includes('streamwish')) sName = 'StreamWish';
                                            else if (rawCyber.includes('voe')) sName = 'Voe';
                                            else if (rawCyber.includes('dood')) sName = 'Doodstream';
                                            else if (rawCyber.includes('streamtape')) sName = 'Streamtape';
                                            else if (rawCyber.includes('filemoon')) sName = 'Filemoon';
                                            else if (rawCyber.includes('fastream')) sName = 'Fastream';
                                            else if (rawCyber.includes('netu')) sName = 'Netu';

                                            links.streaming.push({
                                                provider: p,
                                                provider_name: p === 'poseidonhd' ? 'PoseidonHD' : 'Cuevana',
                                                title: meta.title,
                                                server: sName,
                                                url: playerUrl,
                                                stream_url: playerUrl,
                                                language: langInfo[0],
                                                lang: langInfo[1],
                                                quality: sItem.quality ? sItem.quality.toUpperCase() : '1080p Full HD',
                                                type: 'streaming',
                                                size: null
                                            });
                                        }
                                    }
                                } catch (e) {}
                            }
                        }
                        break;
                    }
                }
                if (links.streaming.length > 0) break;
            }
            return links;
        }

        // Para otros proveedores, usar scrapeSingleProvider
        const rawStreams = await scrapeSingleProvider(p, meta);
        for (const s of rawStreams) {
            if (s.infoHash) {
                links.torrent.push({
                    provider: p,
                    title: s.title || s.name || meta.title,
                    magnet: `magnet:?xt=urn:btih:${s.infoHash}`,
                    infoHash: s.infoHash,
                    seeders: s._seeders || 0,
                    size: s._size || 'N/A',
                    type: 'torrent'
                });
            } else if (s.url) {
                const serverMatch = s.title?.match(/⚡\s*([^•\n]+)/);
                const serverName = serverMatch ? serverMatch[1].trim() : (s.name ? s.name.split('\n')[0].replace(/^[^\w]+/, '').trim() : 'Online');
                links.streaming.push({
                    provider: p,
                    provider_name: p,
                    title: s.title || meta.title,
                    server: serverName,
                    url: s.url,
                    stream_url: s.url,
                    language: s._lang === 'latino' ? 'Español Latino' : (s._lang === 'castellano' ? 'Castellano' : 'Subtitulado'),
                    lang: s._lang || 'lat',
                    quality: '1080p Full HD',
                    type: 'streaming'
                });
            }
        }
    } catch (err) {
        console.warn(`[getWebProviderFallback] Error for ${p}:`, err.message);
    }

    return links;
}
