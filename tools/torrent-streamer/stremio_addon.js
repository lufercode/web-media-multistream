import crypto from 'crypto';

const TMDB_API_KEY = process.env.TMDB_API_KEY || 'aaccf8f89c76c5a7ac753e3a8402de56';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

export const ALL_STREMIO_PROVIDERS = [
    { id: 'lamovie', name: 'LaMovie / AllCalidad (HLS Directo)', category: 'streaming', default: true },
    { id: 'latanime', name: 'LatAnime (Anime Español Latino)', category: 'streaming', default: true },
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

    if (!rawConfig || rawConfig === 'default' || rawConfig === 'all') {
        return { providers: new Set(defaultProviders), langs: new Set(defaultLangs), raw: 'default' };
    }

    let decoded = rawConfig;
    try {
        decoded = decodeURIComponent(rawConfig);
    } catch (e) {}

    const providers = new Set();
    const langs = new Set();

    // Formato seguro: p-lamovie.latanime_l-latino.multi
    const safeProvMatch = decoded.match(/(?:^|[_|&])p-([a-z0-9._-]+)/i);
    const safeLangMatch = decoded.match(/(?:^|[_|&])l-([a-z0-9._-]+)/i);

    if (safeProvMatch) {
        safeProvMatch[1].split('.').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(p => providers.add(p));
    }
    if (safeLangMatch) {
        safeLangMatch[1].split('.').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(l => langs.add(l));
    }

    // Formato clásico: providers=a,b|lang=x,y
    const kvProvMatch = decoded.match(/providers=([a-z0-9,_-]+)/i);
    const kvLangMatch = decoded.match(/langs?=([a-z0-9,_-]+)/i);

    if (kvProvMatch) {
        kvProvMatch[1].split(',').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(p => providers.add(p));
    }
    if (kvLangMatch) {
        kvLangMatch[1].split(',').map(s => s.trim().toLowerCase()).filter(Boolean).forEach(l => langs.add(l));
    }

    // Si solo pasaron lista separada por comas o puntos
    if (providers.size === 0 && !safeProvMatch && !kvProvMatch) {
        const parts = decoded.split(/[,.]/).map(s => s.trim().toLowerCase()).filter(Boolean);
        const validIds = new Set(defaultProviders);
        parts.forEach(p => {
            if (validIds.has(p)) providers.add(p);
        });
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
        version: '2.4.0',
        name: 'StreamMedia Latino & Multi-Scraper',
        description: `Addon Multi-Fuente (${activeNames.join(', ')}). Idiomas: ${langLabels.join(', ')}. Combina Streaming HLS Directo (.m3u8) y Torrents P2P nativos para Películas, Series y Anime.`,
        logo: 'https://cdn-icons-png.flaticon.com/512/2503/2503508.png',
        background: 'https://images.unsplash.com/photo-1574375927938-d5a98e8ffe85?auto=format&fit=crop&w=1400&q=80',
        resources: ['stream'],
        types: ['movie', 'series', 'anime'],
        idPrefixes: ['tt', 'tmdb:'],
        catalogs: [],
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
 * Resuelve un embed de Vimeos / StreamWish / VidHide / Voe en un enlace directo .m3u8
 * para que el reproductor nativo de Stremio lo reproduzca directamente sin pasar por Render.
 */
async function resolveEmbedToHls(embedUrl, customReferer = '') {
    if (!embedUrl || !embedUrl.startsWith('http')) return null;
    const lower = embedUrl.toLowerCase();

    try {
        const parsed = new URL(embedUrl);
        const host = `${parsed.protocol}//${parsed.host}`;

        // 1. Vimeos / StreamWish / VidHide
        if (/(?:vimeos|streamwish|hlswish|wishfast|strwish|swhoi|wishembed|embedwish|hglink|dwish|awish|mwish|flaswish|obeywish|cdnwish|asnwish|luluvdoo|vide0|vidhide|vidguard|filelions|streamhide|morencius|kinotv|dramiyos|earnvids|smoothpre|dhtpre|peytonepre|ryderjet)/i.test(lower)) {
            let targetUrl = embedUrl;
            let referer = customReferer || `${host}/`;
            const isVidHide = /(?:vidhide|vidguard|filelions|streamhide|morencius|kinotv|dramiyos|earnvids|smoothpre|dhtpre|peytonepre)/i.test(lower);

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
                return { url: directM3u8[1], referer: `${host}/` };
            }

            const packedBlocks = html.match(/eval\(function\(p,a,c,k,e,[rd]\).*?\.split\(['"]\|['"]\)\)\)/gs) || [];
            for (const block of packedBlocks) {
                const unpacked = unpackDeanEdwards(block);
                const m3 = unpacked.match(/(https?:\/\/[^\s"'<>]+\.m3u8[^\s"'<>]*)/i);
                if (m3) {
                    return { url: m3[1], referer: `${host}/` };
                }
                const relM3 = unpacked.match(/["'](\/stream\/[^"']+\.m3u8[^"']*)["']/i);
                if (relM3) {
                    return { url: `${host}${relM3[1]}`, referer: `${host}/` };
                }
            }
        }

        // 2. Voe.sx
        if (/(?:voe\.sx|voesx|jilliandescribecompany|christopheruntilpoint|walterprettytheir|catherineupdated)/i.test(lower)) {
            const html = await httpGet(embedUrl, { timeout: 3800 });
            if (!html) return null;
            const b64Match = html.match(/['"]hls['"]\s*:\s*['"]([a-zA-Z0-9+/=]+)['"]/i);
            if (b64Match) {
                const dec = Buffer.from(b64Match[1], 'base64').toString('utf8');
                if (dec.startsWith('http')) return { url: dec, referer: `${host}/` };
            }
            const hlsMatch = html.match(/['"]hls['"]\s*:\s*['"](https?:\/\/[^'"]+)['"]/i) ||
                             html.match(/(https?:\/\/[^\s"'<>]+\.m3u8[^\s"'<>]*)/i);
            if (hlsMatch) {
                return { url: hlsMatch[1], referer: `${host}/` };
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
 * 1. Scraper: LaMovie / AllCalidad API v2 (https://tmdb.allcalidad.re/v1)
 */
async function scrapeLaMovie(meta) {
    if (!meta.tmdbId) return [];
    const apiBase = 'https://tmdb.allcalidad.re/v1';
    const headers = { Referer: 'https://lamovie.org/', Origin: 'https://lamovie.org' };
    const itemsToProcess = [];

    if (meta.type === 'movie') {
        const data = await httpGet(`${apiBase}/items/movie/${meta.tmdbId}`, { json: true, headers, timeout: 4000 });
        if (data?.item) itemsToProcess.push(data.item);
    } else {
        const kinds = meta.isAnime ? ['anime', 'tvshow'] : ['tvshow', 'anime'];
        for (const kind of kinds) {
            let epData = await httpGet(`${apiBase}/items/${kind}/${meta.tmdbId}/seasons/${meta.season}/episodes/${meta.episode}`, { json: true, headers, timeout: 3800 });
            if (!epData?.episode && meta.season >= 2 && meta.absoluteEpisode) {
                epData = await httpGet(`${apiBase}/items/${kind}/${meta.tmdbId}/seasons/1/episodes/${meta.absoluteEpisode}`, { json: true, headers, timeout: 3800 });
            }
            if (epData?.episode) {
                itemsToProcess.push(epData.episode);
                break;
            }
        }
    }

    const streams = [];
    for (const item of itemsToProcess) {
        const code = (item.code || '').trim();
        if (!code) continue;
        const embedUrl = `https://vimeos.net/embed-${code}.html`;
        const resolved = await resolveEmbedToHls(embedUrl, 'https://lamovie.org/');
        if (resolved?.url) {
            const labelTitle = meta.type === 'movie'
                ? `${meta.title} (${meta.year || 'HD'})`
                : `${meta.title} S${String(meta.season).padStart(2, '0')}E${String(meta.episode).padStart(2, '0')}`;
            streams.push({
                name: `🇲🇽 LaMovie\n1080p HD`,
                title: `${labelTitle}\n⚡ Vimeos HLS Directo • 🇲🇽 Español Latino`,
                url: resolved.url,
                _lang: 'latino',
                behaviorHints: {
                    notWebReady: false,
                    bingeGroup: `streammedia-lamovie-latino`,
                    proxyHeaders: {
                        request: {
                            'Referer': resolved.referer || 'https://vimeos.net/',
                            'User-Agent': UA
                        }
                    }
                }
            });
        }
    }
    return streams;
}

/**
 * Descifra el desafío Proof-of-Work SHA-256 + AES-256-CBC de Embed69 / Vidurl (usado por SeriesKao y PelisPlusHD)
 */
function decryptEmbed69DataLink(html) {
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
        const rawLang = String(group.video_language || 'LAT').toUpperCase();
        const langKey = (rawLang === 'ESP' || rawLang === '1') ? 'castellano' : ((rawLang === 'SUB' || rawLang === '2') ? 'sub' : 'latino');
        const langBadge = langKey === 'castellano' ? '🇪🇸 Castellano' : (langKey === 'sub' ? '🇯🇵/🇺🇸 Subtitulado' : '🇲🇽 Español Latino');

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
                        langKey,
                        langBadge
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
            const decryptedList = decryptEmbed69DataLink(vidHtml);
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
                const rawLang = (langM ? langM[1] : 'LAT').toUpperCase();
                const langKey = (rawLang === 'ESP' || rawLang === '1') ? 'castellano' : ((rawLang === 'SUB' || rawLang === '2') ? 'sub' : 'latino');
                const langBadge = langKey === 'castellano' ? '🇪🇸 Castellano' : (langKey === 'sub' ? '🇯🇵/🇺🇸 Subtitulado' : '🇲🇽 Español Latino');
                candidateEmbeds.push({ url: dec, server: 'Server', langKey, langBadge });
            }
        } catch (e) {}
    }

    // Resolver hasta 4 embeds en paralelo a .m3u8 directo
    const resolvable = candidateEmbeds.filter(e => /(?:vidhide|morencius|streamwish|hglink|wish|voe|vimeos)/i.test(e.url)).slice(0, 4);
    const resolvedStreams = await Promise.all(resolvable.map(async (emb) => {
        const res = await resolveEmbedToHls(emb.url, targetPageUrl);
        if (!res?.url) return null;
        const flag = emb.langKey === 'castellano' ? '🇪🇸' : (emb.langKey === 'sub' ? '🇯🇵' : '🇲🇽');
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

    const searchUrl = `${host}/search?q=${encodeURIComponent(meta.title)}`;
    const html = await httpGet(searchUrl, { timeout: 4500 });
    if (!html) return [];

    const mNext = html.match(/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s);
    if (!mNext) return [];

    let nextData = null;
    try {
        nextData = JSON.parse(mNext[1]);
    } catch (e) {
        return [];
    }

    const items = [
        ...(nextData?.props?.pageProps?.movies || []),
        ...(nextData?.props?.pageProps?.series || [])
    ];

    let matchedItem = null;
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
        if (isTitleMatch(meta.title, candTitle)) {
            matchedItem = item;
            break;
        }
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
        if (!Array.isArray(list)) continue;
        const langKey = langGroup === 'latino' ? 'latino' : (langGroup === 'spanish' ? 'castellano' : 'sub');
        const langBadge = langKey === 'latino' ? '🇲🇽 Español Latino' : (langKey === 'castellano' ? '🇪🇸 Castellano' : '🇺🇸 Subtitulado');
        for (const srv of list) {
            const target = srv.result || srv.url;
            if (target && /(?:streamwish|wish|hglink|vidhide|morencius|voe)/i.test(target + ' ' + (srv.cyberlocker || ''))) {
                candidateServers.push({ url: target, cyberlocker: srv.cyberlocker || 'HLS', langKey, langBadge });
            }
        }
    }

    const resolved = await Promise.all(candidateServers.slice(0, 3).map(async (srv) => {
        // Algunos enlaces de Cuevana pasan por un redirector intermedio (player.cuevana.is)
        let embedTarget = srv.url;
        if (!/(?:streamwish|wish|hglink|vidhide|morencius|voe)/i.test(embedTarget)) {
            const interHtml = await httpGet(embedTarget, { headers: { Referer: detailUrl }, timeout: 3500 });
            const mRedir = interHtml?.match(/var\s+url\s*=\s*['"](https?:\/\/[^'"]+)['"]/i) ||
                           interHtml?.match(/location\.href\s*=\s*['"](https?:\/\/[^'"]+)['"]/i);
            if (mRedir) embedTarget = mRedir[1];
        }

        const r = await resolveEmbedToHls(embedTarget, detailUrl);
        if (!r?.url) return null;
        const flag = srv.langKey === 'latino' ? '🇲🇽' : (srv.langKey === 'castellano' ? '🇪🇸' : '🇺🇸');
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

/**
 * Clasifica el idioma real de un torrent de Torrentio/TPB/Nyaa evitando falsos positivos (MultiSubs / VOSTFR)
 */
function classifyTorrentLanguage(rawTitle = '') {
    const cleanDesc = rawTitle
        .replace(/\bmulti[-\s._]?(?:subs?|subtitles?|subtitulos?)\b/gi, 'Subtitled_Only')
        .replace(/\b(?:sub|subs|subtitles|subtitulado)\s+multi(?:ple)?\b/gi, 'Subtitled_Only');

    const hasExplicitLatino = /\b(?:latino|lat|es-mx|es_mx|spa-lat|espanol[\s._-]*latino|audio[\s._-]*latino|dual[\s._-]*lat)\b/i.test(cleanDesc) || cleanDesc.includes('🇲🇽');
    const hasExplicitSpanish = /\b(?:castellano|cast|es-es|spa|spanish|espanol)\b/i.test(cleanDesc) || cleanDesc.includes('🇪🇸');
    const hasMultiAudio = /\b(?:multi[\s._-]*audio|dual[\s._-]*audio|\bmulti\b|\bdual\b)\b/i.test(cleanDesc);
    const isFrenchOnly = /\b(?:vostfr|truefrench|french|vf2|vff|vfq|multi[\s._-]*vf)\b/i.test(cleanDesc) && !hasExplicitLatino && !hasExplicitSpanish;

    if (hasExplicitLatino) {
        return { langKey: 'latino', flag: '🇲🇽', badge: '🇲🇽 Audio Español Latino' };
    }
    if (hasExplicitSpanish) {
        return { langKey: 'castellano', flag: '🇪🇸', badge: '🇪🇸 Audio Español / Castellano' };
    }
    if (hasMultiAudio && !isFrenchOnly) {
        return { langKey: 'multi', flag: '🌐', badge: '🌐 Multi-Audio / Dual' };
    }
    return { langKey: 'sub', flag: '🧲', badge: '🇯🇵/🇺🇸 Audio Original / Sub' };
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

    const endpoints = [];
    for (const qId of queries) {
        endpoints.push(`https://torrentio.strem.fun/providers=yts,eztv,rarbg,1337x,thepiratebay,kickasstorrents,torrentgalaxy,magnetdl,horriblesubs,nyaasi,tokyotosho,anidex,mejortorrent,wolfmax4k,cinecalidad|language=latino,spanish/stream/${stremioType}/${qId}.json`);
        endpoints.push(`https://torrentio.strem.fun/sort=seeders/stream/${stremioType}/${qId}.json`);
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
            const langInfo = classifyTorrentLanguage(rawTitle);

            // Extraer calidad
            const qMatch = (rawName + ' ' + rawTitle).match(/\b(2160p|4k|1080p|720p|480p)\b/i);
            const quality = qMatch ? qMatch[1].toUpperCase() : 'HD';

            // Extraer seeders
            const seedMatch = rawTitle.match(/👤\s*(\d+)/u);
            const seeders = seedMatch ? parseInt(seedMatch[1], 10) : 0;

            const streamObj = {
                name: `${langInfo.flag} Torrentio\n${quality}`,
                title: `${langInfo.badge}\n${rawTitle}`,
                infoHash: hash,
                sources: Array.isArray(s.sources) && s.sources.length > 0 ? s.sources : STREMIO_TRACKER_SOURCES,
                _lang: langInfo.langKey,
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
 * 7. Scraper: YTS (Películas 1080p / 4K nativas por infoHash)
 */
async function scrapeYts(meta) {
    if (meta.type !== 'movie' || !meta.imdbId) return [];
    const data = await httpGet(`https://yts.mx/api/v2/list_movies.json?query_term=${encodeURIComponent(meta.imdbId)}`, { json: true, timeout: 4000 });
    const movie = data?.data?.movies?.[0];
    if (!movie?.torrents) return [];

    return movie.torrents.map(t => {
        const hash = (t.hash || '').toLowerCase();
        if (!hash) return null;
        return {
            name: `🧲 YTS\n${t.quality || '1080p'}`,
            title: `${movie.title_long || meta.title} (${t.type?.toUpperCase() || 'WEB'})\n👤 ${t.seeds || 0} • 💾 ${t.size || ''} • ⚙️ YTS`,
            infoHash: hash,
            sources: STREMIO_TRACKER_SOURCES,
            _lang: 'sub',
            _seeders: t.seeds || 0
        };
    }).filter(Boolean);
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

        const langInfo = classifyTorrentLanguage(title);
        const qMatch = title.match(/\b(2160p|1080p|720p)\b/i);
        const quality = qMatch ? qMatch[1] : '1080p';

        streams.push({
            name: `${langInfo.flag} Nyaa\n${quality}`,
            title: `${title}\n${langInfo.badge} • 👤 ${seeders} • 💾 ${size} • ⚙️ Nyaa.si`,
            infoHash: hash,
            sources: STREMIO_TRACKER_SOURCES,
            _lang: langInfo.langKey,
            _seeders: seeders
        });
    }
    return streams;
}

/**
 * 9. Scraper: ThePirateBay (apibay.org)
 */
async function scrapeTpb(meta) {
    const sStr = String(meta.season).padStart(2, '0');
    const eStr = String(meta.episode).padStart(2, '0');
    const queries = meta.type === 'movie'
        ? [`${meta.title} ${meta.year} latino`, `${meta.originalTitle} ${meta.year} 1080p`]
        : [`${meta.title} S${sStr}E${eStr} latino`, `${meta.originalTitle} S${sStr}E${eStr}`];

    const responses = await Promise.all(queries.map(q => httpGet(`https://apibay.org/q.php?q=${encodeURIComponent(q)}&cat=200`, { json: true, timeout: 4000 })));
    const seen = new Set();
    const streams = [];

    for (const list of responses) {
        if (!Array.isArray(list)) continue;
        for (const item of list.slice(0, 6)) {
            const hash = (item.info_hash || '').toLowerCase();
            if (!hash || hash === '0000000000000000000000000000000000000000' || seen.has(hash)) continue;
            const seeders = parseInt(item.seeders || '0', 10);
            if (seeders < 1) continue;
            seen.add(hash);

            const name = item.name || meta.title;
            const langInfo = classifyTorrentLanguage(name);
            const sizeGb = ((parseInt(item.size || '0', 10)) / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
            const qMatch = name.match(/\b(2160p|4k|1080p|720p)\b/i);
            const quality = qMatch ? qMatch[1].toUpperCase() : 'HD';

            streams.push({
                name: `${langInfo.flag} PirateBay\n${quality}`,
                title: `${name}\n${langInfo.badge} • 👤 ${seeders} • 💾 ${sizeGb} • ⚙️ TPB`,
                infoHash: hash,
                sources: STREMIO_TRACKER_SOURCES,
                _lang: langInfo.langKey,
                _seeders: seeders
            });
        }
    }
    return streams;
}

/**
 * 10. Scraper: Cinecalidad (https://www.cinecalidad.ro)
 */
async function scrapeCinecalidad(meta) {
    if (meta.type !== 'movie') return [];
    const host = 'https://www.cinecalidad.ro';
    const html = await httpGet(`${host}/?s=${encodeURIComponent(meta.title)}`, { timeout: 4200 });
    if (!html) return [];

    const articles = [...html.matchAll(/<article[^>]*>(.*?)<\/article>/gs)];
    let detailUrl = null;
    for (const m of articles) {
        const block = m[1];
        const href = block.match(/href="(https?:\/\/[^"]+)"/)?.[1];
        const title = block.match(/<h2[^>]*>([^<]+)<\/h2>/)?.[1]?.trim() || '';
        if (href && isTitleMatch(meta.title, title)) {
            detailUrl = href;
            break;
        }
    }
    if (!detailUrl) return [];

    const detHtml = await httpGet(detailUrl, { timeout: 4200 });
    if (!detHtml) return [];

    const streams = [];
    const magnets = [...detHtml.matchAll(/href="(magnet:\?xt=urn:btih:([a-fA-F0-9]{40})[^"]*)"/gi)];
    for (const mm of magnets) {
        const hash = mm[2].toLowerCase();
        streams.push({
            name: `🇲🇽 Cinecalidad\n1080p Dual`,
            title: `${meta.title} (${meta.year || 'HD'})\n🇲🇽 Audio Español Latino Dual • ⚙️ Cinecalidad P2P`,
            infoHash: hash,
            sources: STREMIO_TRACKER_SOURCES,
            _lang: 'latino',
            _seeders: 50
        });
    }
    return streams;
}

/**
 * Ejecuta todos los scrapers seleccionados en paralelo con límite de tiempo y devuelve el array `streams` de Stremio.
 */
export async function getStremioStreams(type, rawId, configStr = '') {
    const cacheKey = `${configStr || 'default'}|${type}|${rawId}`;
    const cached = getCached(streamCache, cacheKey, 5 * 60 * 1000);
    if (cached) return cached;

    const cfg = parseAddonConfig(configStr);
    const meta = await resolveTmdbMetadata(type, rawId);
    if (!meta.title && !meta.imdbId) {
        return { streams: [] };
    }

    const tasks = [];

    if (cfg.providers.has('local_cdn')) tasks.push(scrapeLocalCdn(meta));
    if (cfg.providers.has('lamovie')) tasks.push(scrapeLaMovie(meta));
    if (cfg.providers.has('latanime')) tasks.push(scrapeLatAnime(meta));
    if (cfg.providers.has('serieskao')) tasks.push(scrapeKaoOrPelisPlus(meta, 'serieskao'));
    if (cfg.providers.has('pelisplus')) tasks.push(scrapeKaoOrPelisPlus(meta, 'pelisplus'));
    if (cfg.providers.has('cuevana')) tasks.push(scrapeCuevanaOrPoseidon(meta, 'cuevana'));
    if (cfg.providers.has('poseidonhd')) tasks.push(scrapeCuevanaOrPoseidon(meta, 'poseidonhd'));
    if (cfg.providers.has('cinecalidad')) tasks.push(scrapeCinecalidad(meta));
    if (cfg.providers.has('torrentio')) tasks.push(scrapeTorrentio(meta));
    if (cfg.providers.has('tpb')) tasks.push(scrapeTpb(meta));
    if (cfg.providers.has('yts')) tasks.push(scrapeYts(meta));
    if (cfg.providers.has('nyaa')) tasks.push(scrapeNyaa(meta));

    const settled = await Promise.allSettled(tasks);
    let allStreams = [];
    for (const res of settled) {
        if (res.status === 'fulfilled' && Array.isArray(res.value)) {
            allStreams.push(...res.value);
        }
    }

    // Filtrar por idiomas seleccionados (si la lista filtrada queda vacía, devolver todos como respaldo)
    const langFiltered = allStreams.filter(s => !s._lang || cfg.langs.has(s._lang));
    if (langFiltered.length > 0) {
        allStreams = langFiltered;
    }

    // Limpiar propiedades internas antes de enviar a Stremio
    const cleanStreams = allStreams.map(s => {
        const copy = { ...s };
        delete copy._lang;
        delete copy._seeders;
        return copy;
    });

    const payload = { streams: cleanStreams };
    if (cleanStreams.length > 0) {
        setCached(streamCache, cacheKey, payload);
    }
    return payload;
}
