import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import WebTorrent from 'webtorrent';
import { buildManifest, getStremioStreams } from './stremio_addon.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = parseInt(process.env.PORT || '8889', 10);
const HOST = process.env.HOST || '0.0.0.0';

// Carpeta de almacenamiento temporal (compatible con entornos locales y cloud / Render / Docker)
function getCacheDir() {
    if (process.env.CACHE_DIR) return process.env.CACHE_DIR;
    const localData = path.resolve(__dirname, '../../data');
    if (fs.existsSync(localData)) {
        return path.resolve(localData, 'torrent_cache');
    }
    const tmpBase = process.env.TMPDIR || process.env.TEMP || '/tmp';
    return path.resolve(tmpBase, 'torrent_cache');
}

const CACHE_DIR = getCacheDir();

function cleanCacheDirectory() {
    try {
        if (fs.existsSync(CACHE_DIR)) {
            fs.rmSync(CACHE_DIR, { recursive: true, force: true });
        }
        fs.mkdirSync(CACHE_DIR, { recursive: true });
    } catch (e) {
        console.warn('[Streamer] Aviso limpiando CACHE_DIR:', e.message);
    }
}

cleanCacheDirectory();

const DEFAULT_TRACKERS = [
    'http://nyaa.tracker.wf:7777/announce',
    'http://tracker.opentrackr.org:1337/announce',
    'https://tracker.tamersunion.org:443/announce',
    'https://tracker.gbitt.info:443/announce',
    'http://open.acgnxtracker.com:80/announce',
    'https://tr.burnabyhighstar.com:443/announce',
    'https://tracker.lilithraws.org:443/announce',
    'wss://tracker.openwebtorrent.com',
    'wss://tracker.btorrent.xyz',
    'udp://tracker.opentrackr.org:1337/announce',
    'udp://open.stealth.si:80/announce',
    'udp://tracker.torrent.eu.org:451/announce',
    'udp://exodus.desync.com:6969/announce',
    'udp://open.demonii.com:1337/announce'
];

// Configuración optimizada para Render Free Tier (0.1 vCPU / 512 MB RAM):
// - maxConns: 18 (evita saturar el event loop y memoria con decenas de sockets simultáneos)
// - utp: false (usa TCP nativo del kernel Linux en lugar de uTP por UDP en JS, ahorrando ~40% de CPU)
// - downloadLimit: 2.8 MB/s (~22.4 Mbps, más del doble de lo que requiere 1080p, evitando que el cálculo SHA-1 de piezas sature el 0.1 CPU)
// - uploadLimit: 64 KB/s (mínimo gasto en subida)
const MAX_DOWNLOAD_RATE = Math.floor(2.8 * 1024 * 1024); // 2936012 bytes/s (entero exacto requerido por speed-limiter)
const MAX_UPLOAD_RATE = 64 * 1024; // 65536 bytes/s

const client = new WebTorrent({
    maxConns: 18,
    utp: false,
    downloadLimit: MAX_DOWNLOAD_RATE,
    uploadLimit: MAX_UPLOAD_RATE,
    dht: true
});

try {
    if (typeof client.throttleDownload === 'function') client.throttleDownload(MAX_DOWNLOAD_RATE);
    if (typeof client.throttleUpload === 'function') client.throttleUpload(MAX_UPLOAD_RATE);
} catch (e) {}

const activeTorrents = new Map();
const recentlyStopped = new Map(); // infoHash -> timestamp para no auto-rehidratar un torrent recién detenido manualmente

function getPublicBaseUrl(req) {
    if (process.env.PUBLIC_URL) return process.env.PUBLIC_URL.replace(/\/$/, '');
    const proto = req.headers['x-forwarded-proto'] || 'http';
    const host = req.headers['x-forwarded-host'] || req.headers.host;
    if (host && !host.includes('0.0.0.0')) {
        return `${proto}://${host}`;
    }
    return `http://${HOST}:${PORT}`;
}

function getMimeType(filename) {
    const ext = path.extname(filename).toLowerCase();
    switch (ext) {
        case '.mp4':
        case '.m4v':
            return 'video/mp4';
        case '.webm':
            return 'video/webm';
        case '.mkv':
            return 'video/x-matroska';
        case '.avi':
            return 'video/x-msvideo';
        case '.mov':
            return 'video/quicktime';
        default:
            return 'video/mp4';
    }
}

function setCors(res) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Range, Content-Type, Accept, Origin');
    res.setHeader('Access-Control-Expose-Headers', 'Content-Range, Content-Length, Accept-Ranges, Content-Type');
}

function parseJsonBody(req) {
    return new Promise((resolve) => {
        let body = '';
        req.on('data', chunk => { body += chunk.toString(); });
        req.on('end', () => {
            try {
                resolve(body ? JSON.parse(body) : {});
            } catch (e) {
                resolve({});
            }
        });
    });
}

function formatBytes(bytes) {
    if (!bytes || bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length - 1) {
        bytes /= 1024;
        i++;
    }
    return bytes.toFixed(i >= 3 ? 2 : 1) + ' ' + units[i];
}

// Limpia absolutamente todas las selecciones de piezas del torrent (incluyendo el array interno _selections de WebTorrent)
function clearAllSelections(torrent) {
    if (!torrent) return;
    try {
        if (torrent.pieces && torrent.pieces.length > 0) {
            torrent.deselect(0, torrent.pieces.length - 1, false);
        }
    } catch (e) {}
    if (Array.isArray(torrent.files)) {
        torrent.files.forEach(f => {
            try { f.deselect(); } catch (e) {}
        });
    }
    if (Array.isArray(torrent._selections)) {
        torrent._selections.length = 0;
    }
}

// Ventana deslizante inteligente (Sliding Window):
// En lugar de descargar los 1.8 GB - 50 GB enteros de golpe (lo que llena los 512 MB de /tmp en Render y satura el 0.1 CPU),
// selecciona únicamente:
// 1) Cabecera inicial (10 MB) + Cola final (4 MB para índice MKV Cues / MP4 moov)
// 2) Una ventana deslizante de 45 MB por delante del punto de lectura actual del reproductor.
// Cuando esa ventana de 45 MB se llena, WebTorrent pausa la descarga (0 KB/s y ~1% CPU) hasta que el reproductor avanza.
function updateStreamingWindow(entry, byteOffset = 0) {
    if (!entry || !entry.torrent || !entry.file) return;
    const torrent = entry.torrent;
    const file = entry.file;
    const pieceLength = torrent.pieceLength || (512 * 1024);

    if (typeof file._startPiece !== 'number' || typeof file._endPiece !== 'number') {
        try { file.select(); } catch (e) {}
        return;
    }

    const startPiece = file._startPiece;
    const endPiece = file._endPiece;
    const currentPiece = Math.min(
        endPiece,
        Math.max(startPiece, startPiece + Math.floor(Math.max(0, byteOffset) / pieceLength))
    );

    // No reconstruir selecciones si el cursor no se ha movido al menos 2 piezas desde la última actualización
    if (entry.lastWindowPiece === currentPiece) {
        return;
    }
    entry.lastWindowPiece = currentPiece;

    const headPieces = Math.max(4, Math.ceil((10 * 1024 * 1024) / pieceLength));   // ~10 MB iniciales
    const tailPieces = Math.max(2, Math.ceil((4 * 1024 * 1024) / pieceLength));    // ~4 MB finales (índice MKV/MP4)
    const lookaheadPieces = Math.max(10, Math.ceil((45 * 1024 * 1024) / pieceLength)); // ~45 MB por delante del reproductor
    const criticalPieces = Math.max(3, Math.ceil((6 * 1024 * 1024) / pieceLength));    // ~6 MB urgentes inmediatos

    clearAllSelections(torrent);

    try {
        // 1. Mantener cabecera e índice final seleccionados
        torrent.select(startPiece, Math.min(endPiece, startPiece + headPieces), false);
        torrent.select(Math.max(startPiece, endPiece - tailPieces), endPiece, false);

        // 2. Seleccionar ventana deslizante de 45 MB desde la posición actual del video
        const winEnd = Math.min(endPiece, currentPiece + lookaheadPieces);
        torrent.select(currentPiece, winEnd, 1);

        // 3. Marcar como críticas las piezas inmediatas que el reproductor necesita ya mismo
        const critEnd = Math.min(endPiece, currentPiece + criticalPieces);
        torrent.critical(currentPiece, critEnd);
    } catch (e) {
        try { file.select(); } catch (e2) {}
    }
}

function pickTargetFile(torrent, reqIdx, reqEp, reqDn) {
    if (!torrent || !Array.isArray(torrent.files) || torrent.files.length === 0) return null;
    const videoRegex = /\.(mp4|mkv|webm|avi|mov|m4v|ts)$/i;
    const videoFiles = torrent.files.filter(f => videoRegex.test(f.name));

    // 1. Coincidencia exacta por nombre de archivo en dn= o ?file= (ej. Dr.STONE.S04E15...mkv)
    if (reqDn && videoFiles.length > 1) {
        const dnLower = reqDn.toLowerCase().trim();
        const exactDn = videoFiles.find(f => f.name.toLowerCase() === dnLower || f.path.toLowerCase().endsWith(dnLower));
        if (exactDn) return exactDn;
    }

    // 2. Coincidencia por código explícito SxxExx (ej. S04E15) en el nombre del archivo
    if (reqEp && videoFiles.length > 1) {
        const exactEp = videoFiles.find(f => f.name.toLowerCase().includes(reqEp.toLowerCase()));
        if (exactEp) return exactEp;
    }

    // 3. Coincidencia por índice de archivo fileIdx
    if (reqIdx !== null && reqIdx !== undefined && !isNaN(reqIdx) && torrent.files[reqIdx] && videoRegex.test(torrent.files[reqIdx].name)) {
        return torrent.files[reqIdx];
    }

    // 4. Coincidencia por número de episodio suelto (ej. E15 o - 15)
    if (reqEp && videoFiles.length > 1) {
        const epNumMatch = String(reqEp).match(/E(\d+)$/i);
        if (epNumMatch) {
            const epPadded = epNumMatch[1];
            const epRegex = new RegExp(`(?:[\\s._\\-\\[]|\\b)(?:e|ep|episode)?${epPadded}(?:v\\d+)?(?:[\\s._\\-\\]]|\\b)`, 'i');
            const byNum = videoFiles.find(f => epRegex.test(f.name));
            if (byNum) return byNum;
        }
    }

    if (videoFiles.length > 0) {
        return videoFiles.reduce((prev, curr) => (prev.length > curr.length ? prev : curr));
    }
    return torrent.files.reduce((prev, curr) => (prev.length > curr.length ? prev : curr));
}

function destroyTorrentEntry(hash, entry) {
    if (!entry) return;
    activeTorrents.delete(hash);
    if (entry.torrent) {
        try {
            entry.torrent.destroy({ destroyStore: true });
        } catch (e) {
            try { entry.torrent.destroy(); } catch (e2) {}
        }
    }
}

function buildStreamQuerySuffix(entry) {
    const params = new URLSearchParams();
    if (entry.file && entry.file.name) {
        params.set('file', entry.file.name);
    } else if (entry.requestedDn) {
        params.set('file', entry.requestedDn);
    }
    if (entry.requestedEpCode) {
        params.set('ep', entry.requestedEpCode);
    }
    if (entry.requestedFileIdx !== null && entry.requestedFileIdx !== undefined && !isNaN(entry.requestedFileIdx)) {
        params.set('fileIdx', String(entry.requestedFileIdx));
    }
    const qs = params.toString();
    return qs ? `?${qs}` : '';
}

function ensureTorrentLoaded(infoHashOrMagnet, opts = {}) {
    let infoHash = '';
    let magnet = '';

    if (infoHashOrMagnet.startsWith('magnet:?')) {
        magnet = infoHashOrMagnet;
        const match = magnet.match(/xt=urn:btih:([a-zA-Z0-9]+)/i);
        if (match) infoHash = match[1].toLowerCase();
    } else {
        infoHash = String(infoHashOrMagnet).trim().toLowerCase();
        magnet = `magnet:?xt=urn:btih:${infoHash}`;
    }

    const requestedFileIdx = opts.requestedFileIdx !== undefined ? opts.requestedFileIdx : null;
    const requestedEpCode = opts.requestedEpCode || null;
    const requestedDn = opts.requestedDn || null;

    if (infoHash) {
        recentlyStopped.delete(infoHash);
    }

    if (infoHash && activeTorrents.has(infoHash)) {
        const entry = activeTorrents.get(infoHash);
        entry.lastAccess = Date.now();
        if (requestedFileIdx !== null) entry.requestedFileIdx = requestedFileIdx;
        if (requestedEpCode) entry.requestedEpCode = requestedEpCode;
        if (requestedDn) entry.requestedDn = requestedDn;

        if (entry.torrent && entry.torrent.files && entry.torrent.files.length > 1 && (requestedFileIdx !== null || requestedEpCode !== null || requestedDn !== null)) {
            const newTarget = pickTargetFile(entry.torrent, entry.requestedFileIdx, entry.requestedEpCode, entry.requestedDn);
            if (newTarget && (!entry.file || entry.file.name !== newTarget.name)) {
                entry.file = newTarget;
                entry.lastWindowPiece = -1;
                updateStreamingWindow(entry, 0);
                console.log(`[Streamer] Cambiado episodio en pack a: ${newTarget.name}`);
            }
        }
        return entry;
    }

    // Limitar a 1 torrent activo simultáneo para dedicar el 100% de los 512 MB RAM y 0.1 CPU de Render al stream actual
    const MAX_CONCURRENT_TORRENTS = 1;
    if (activeTorrents.size >= MAX_CONCURRENT_TORRENTS) {
        let oldestHash = null;
        let oldestAccess = Infinity;
        for (const [hash, item] of activeTorrents.entries()) {
            if (item.lastAccess < oldestAccess) {
                oldestAccess = item.lastAccess;
                oldestHash = hash;
            }
        }
        if (oldestHash) {
            console.log(`[Streamer] Liberando torrent previo para ahorrar RAM/CPU: ${oldestHash}`);
            destroyTorrentEntry(oldestHash, activeTorrents.get(oldestHash));
        }
    }

    const cleanMagnet = magnet.replace(/&(?:fileIdx|so|ep)=[^&]*/gi, '');
    console.log(`[Streamer] Inicializando torrent: ${infoHash || cleanMagnet.substring(0, 50)} (ep=${requestedEpCode || '-'}, idx=${requestedFileIdx ?? '-'}, file=${requestedDn || '-'})`);

    const existingInClient = infoHash ? client.torrents.find(t => t.infoHash && t.infoHash.toLowerCase() === infoHash) : null;
    const torrent = existingInClient || client.add(cleanMagnet, {
        path: CACHE_DIR,
        announce: DEFAULT_TRACKERS
    });

    const resolvedHash = (torrent.infoHash || infoHash).toLowerCase();
    const entry = {
        torrent,
        file: null,
        subtitles: [],
        requestedFileIdx,
        requestedEpCode,
        requestedDn,
        lastWindowPiece: -1,
        addedAt: Date.now(),
        lastAccess: Date.now()
    };
    activeTorrents.set(resolvedHash, entry);

    const onTorrentReady = () => {
        if (entry.file) return;

        // 1. Limpiar todas las selecciones automáticas de WebTorrent para NO descargar otros episodios ni el archivo entero de golpe
        clearAllSelections(torrent);

        // 2. Seleccionar el episodio/archivo objetivo y activar ventana deslizante ligera (45 MB)
        const mainVideo = pickTargetFile(torrent, entry.requestedFileIdx, entry.requestedEpCode, entry.requestedDn);
        if (mainVideo) {
            entry.file = mainVideo;
            entry.lastWindowPiece = -1;
            updateStreamingWindow(entry, 0);
            console.log(`[Streamer] Video seleccionado (Sliding Window 45MB): ${mainVideo.name} (${formatBytes(mainVideo.length)})`);
        }

        // 3. Indexar subtítulos (.srt, .vtt) SIN seleccionarlos todavía (se seleccionan bajo demanda solo si el usuario los pide)
        const subRegex = /\.(srt|vtt)$/i;
        let subFiles = torrent.files.filter(f => subRegex.test(f.name));
        if (entry.requestedEpCode && subFiles.length > 4) {
            const epFiltered = subFiles.filter(f => f.name.toLowerCase().includes(entry.requestedEpCode.toLowerCase()));
            if (epFiltered.length > 0) subFiles = epFiltered;
        }
        entry.subtitles = subFiles.slice(0, 12).map(sub => {
            let label = 'Subtítulo';
            const lower = sub.name.toLowerCase();
            if (lower.includes('lat') || lower.includes('latino') || lower.includes('mx')) {
                label = 'Español Latino';
            } else if (lower.includes('spa') || lower.includes('esp') || lower.includes('cast')) {
                label = 'Español (Castellano)';
            } else if (lower.includes('eng') || lower.includes('en') || lower.includes('ing')) {
                label = 'Inglés';
            } else {
                label = path.basename(sub.name, path.extname(sub.name));
            }
            return {
                name: label,
                fileName: sub.name,
                index: torrent.files.indexOf(sub),
                size: formatBytes(sub.length)
            };
        });
    };

    if (torrent.ready) {
        onTorrentReady();
    } else {
        torrent.once('ready', onTorrentReady);
    }

    torrent.on('error', (err) => {
        console.warn(`[Streamer] Aviso en torrent ${resolvedHash}:`, err.message);
    });

    return entry;
}

const server = http.createServer(async (req, res) => {
    setCors(res);

    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }

    const parsedUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
    const pathname = parsedUrl.pathname;
    const baseUrl = getPublicBaseUrl(req);

    // GET /health o /ping
    if (pathname === '/health' || pathname === '/ping') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            uptime: Math.round(process.uptime()),
            torrentsCount: activeTorrents.size,
            clientDownloadSpeed: client.downloadSpeed,
            clientUploadSpeed: client.uploadSpeed,
            memoryMB: Math.round(process.memoryUsage().rss / (1024 * 1024))
        }));
        return;
    }

    // =========================================================================
    // STREMIO ADDON PROTOCOL ENDPOINTS (0% carga de video en Render)
    // Soporta:
    //   GET /manifest.json
    //   GET /stremio/manifest.json
    //   GET /stremio/:config/manifest.json
    //   GET /stream/:type/:id.json
    //   GET /stremio/stream/:type/:id.json
    //   GET /stremio/:config/stream/:type/:id.json
    // =========================================================================
    const manifestMatch = pathname.match(/^(?:\/stremio)?(?:\/([^/]+))?\/manifest\.json$/i);
    if (manifestMatch && req.method === 'GET') {
        const configParam = manifestMatch[1] || parsedUrl.searchParams.get('config') || 'default';
        const manifest = buildManifest(configParam, baseUrl);
        res.writeHead(200, {
            'Content-Type': 'application/json; charset=utf-8',
            'Cache-Control': 'public, max-age=3600'
        });
        res.end(JSON.stringify(manifest));
        return;
    }

    const stremioStreamMatch = pathname.match(/^(?:\/stremio)?(?:\/([^/]+))?\/stream\/(movie|series|anime|tv)\/([^/]+)\.json$/i);
    if (stremioStreamMatch && req.method === 'GET') {
        const configParam = stremioStreamMatch[1] || parsedUrl.searchParams.get('config') || 'default';
        const rawType = stremioStreamMatch[2].toLowerCase();
        const stremioType = (rawType === 'tv' || rawType === 'anime') ? 'series' : rawType;
        const stremioId = decodeURIComponent(stremioStreamMatch[3]);

        try {
            console.log(`[Stremio Addon] Buscando streams para ${stremioType}/${stremioId} (config=${configParam})...`);
            const result = await getStremioStreams(stremioType, stremioId, configParam);
            res.writeHead(200, {
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'public, max-age=300'
            });
            res.end(JSON.stringify(result));
        } catch (err) {
            console.error('[Stremio Addon] Error:', err);
            res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify({ streams: [] }));
        }
        return;
    }

    // POST /load { magnet: "magnet:?..." }
    if (pathname === '/load' && req.method === 'POST') {
        const data = await parseJsonBody(req);
        const magnet = data.magnet;

        if (!magnet || typeof magnet !== 'string' || !magnet.startsWith('magnet:?')) {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: 'Magnet link no proporcionado o inválido' }));
            return;
        }

        const fileIdxMatch = magnet.match(/[?&](?:fileIdx|so)=(\d+)/i);
        const requestedFileIdx = fileIdxMatch ? parseInt(fileIdxMatch[1], 10) : null;
        const epMatch = magnet.match(/[?&]ep=([^&]+)/i);
        const requestedEpCode = epMatch ? decodeURIComponent(epMatch[1]) : null;
        const dnMatch = magnet.match(/[?&]dn=([^&]+)/i);
        const requestedDn = dnMatch ? decodeURIComponent(dnMatch[1].replace(/\+/g, ' ')) : null;

        try {
            const entry = ensureTorrentLoaded(magnet, {
                requestedFileIdx,
                requestedEpCode,
                requestedDn
            });
            const resolvedHash = (entry.torrent.infoHash || '').toLowerCase();
            const suffix = buildStreamQuerySuffix(entry);

            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                status: 'success',
                infoHash: resolvedHash,
                name: entry.file ? entry.file.name : (entry.requestedDn || entry.torrent.name),
                size: entry.file ? formatBytes(entry.file.length) : formatBytes(entry.torrent.length),
                ready: !!entry.file,
                streamUrl: `${baseUrl}/stream/${resolvedHash}${suffix}`
            }));
        } catch (err) {
            console.error('[Streamer] Error al añadir torrent:', err);
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: err.message }));
        }
        return;
    }

    // GET /subtitles/:infoHash/:fileIndex
    if (pathname.startsWith('/subtitles/')) {
        const parts = pathname.replace('/subtitles/', '').split('/');
        const infoHash = (parts[0] || '').toLowerCase();
        const fileIdx = parseInt(parts[1], 10);
        const entry = activeTorrents.get(infoHash);

        if (!entry || !entry.torrent || isNaN(fileIdx)) {
            res.writeHead(404, { 'Content-Type': 'text/plain' });
            res.end('Subtítulo no encontrado');
            return;
        }

        const file = entry.torrent.files && entry.torrent.files[fileIdx];
        if (!file) {
            res.writeHead(404, { 'Content-Type': 'text/plain' });
            res.end('Archivo de subtítulo no existe');
            return;
        }

        entry.lastAccess = Date.now();
        try { file.select(); } catch (e) {}

        const stream = file.createReadStream();
        const chunks = [];
        stream.on('data', chunk => chunks.push(chunk));
        stream.on('end', () => {
            const rawContent = Buffer.concat(chunks).toString('utf-8');
            let vttContent = rawContent;
            if (file.name.toLowerCase().endsWith('.srt')) {
                vttContent = 'WEBVTT - ' + file.name + '\n\n' + rawContent
                    .replace(/\r\n|\r/g, '\n')
                    .replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g, '$1.$2');
            }
            res.writeHead(200, {
                'Content-Type': 'text/vtt; charset=utf-8',
                'Content-Length': Buffer.byteLength(vttContent, 'utf-8'),
                'Access-Control-Allow-Origin': '*'
            });
            res.end(vttContent);
        });
        stream.on('error', (err) => {
            if (!res.headersSent) {
                res.writeHead(500, { 'Content-Type': 'text/plain' });
                res.end('Error al leer subtítulo: ' + err.message);
            }
        });
        return;
    }

    // GET /status/:infoHash
    if (pathname.startsWith('/status/')) {
        const infoHash = pathname.replace('/status/', '').trim().toLowerCase();
        let entry = activeTorrents.get(infoHash);

        // Auto-recuperación transparente si Render se reinició o despertó de suspensión mientras el usuario veía el video
        if (!entry && /^[a-f0-9]{40}$/i.test(infoHash)) {
            const stoppedAt = recentlyStopped.get(infoHash) || 0;
            if (Date.now() - stoppedAt > 60 * 1000) {
                const qFile = parsedUrl.searchParams.get('file') || null;
                const qEp = parsedUrl.searchParams.get('ep') || null;
                const qIdxRaw = parsedUrl.searchParams.get('fileIdx');
                const qIdx = qIdxRaw !== null ? parseInt(qIdxRaw, 10) : null;
                console.log(`[Streamer] Auto-recuperando torrent en /status/${infoHash} tras reinicio...`);
                entry = ensureTorrentLoaded(infoHash, {
                    requestedDn: qFile,
                    requestedEpCode: qEp,
                    requestedFileIdx: qIdx
                });
            }
        }

        if (!entry) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: 'Torrent no encontrado o detenido' }));
            return;
        }

        entry.lastAccess = Date.now();
        const t = entry.torrent;
        const f = entry.file;

        const downloadedBytes = (f && typeof f.downloaded === 'number') ? f.downloaded : (t.downloaded || 0);
        const totalBytes = f ? f.length : (t.length || 1);
        const initialBufferNeeded = Math.min(3.5 * 1024 * 1024, Math.max(1.5 * 1024 * 1024, totalBytes * 0.003));
        const fileProgress = (f && typeof f.progress === 'number') ? f.progress : (t.progress || 0);

        const isReadyToPlay = (entry.file !== null) && (
            downloadedBytes >= initialBufferNeeded ||
            (downloadedBytes >= 1.2 * 1024 * 1024 && t.downloadSpeed > 200 * 1024)
        );

        const suffix = buildStreamQuerySuffix(entry);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'success',
            infoHash: t.infoHash || infoHash,
            name: f ? f.name : (entry.requestedDn || t.name || 'Conectando metadatos...'),
            totalSize: formatBytes(totalBytes),
            downloadedSize: formatBytes(downloadedBytes),
            bufferTargetSize: formatBytes(initialBufferNeeded),
            progressPct: Math.round(fileProgress * 1000) / 10,
            initialBufferPct: Math.min(100, Math.round((downloadedBytes / initialBufferNeeded) * 100)),
            downloadSpeed: formatBytes(t.downloadSpeed) + '/s',
            uploadSpeed: formatBytes(t.uploadSpeed) + '/s',
            peers: t.numPeers,
            ready: isReadyToPlay,
            streamUrl: `${baseUrl}/stream/${t.infoHash || infoHash}${suffix}`,
            subtitles: (entry.subtitles || []).map(s => ({
                name: s.name,
                fileName: s.fileName,
                index: s.index,
                size: s.size,
                url: `${baseUrl}/subtitles/${t.infoHash || infoHash}/${s.index}`
            }))
        }));
        return;
    }

    // GET /stream/:infoHash (o /stream/:infoHash/:filename)
    if (pathname.startsWith('/stream/')) {
        const rawStreamPath = pathname.replace('/stream/', '').trim();
        const infoHash = (rawStreamPath.split('/')[0] || '').toLowerCase();
        const qFile = parsedUrl.searchParams.get('file') || null;
        const qEp = parsedUrl.searchParams.get('ep') || null;
        const qIdxRaw = parsedUrl.searchParams.get('fileIdx');
        const qIdx = qIdxRaw !== null ? parseInt(qIdxRaw, 10) : null;

        let entry = activeTorrents.get(infoHash);

        // Si Render se reinició o el usuario abrió el enlace en VLC minutos después, auto-recuperar el torrent al vuelo
        if (!entry && /^[a-f0-9]{40}$/i.test(infoHash)) {
            console.log(`[Streamer] Auto-recuperando torrent en /stream/${infoHash} (file=${qFile || '-'}, ep=${qEp || '-'})...`);
            entry = ensureTorrentLoaded(infoHash, {
                requestedDn: qFile,
                requestedEpCode: qEp,
                requestedFileIdx: qIdx
            });
        }

        if (!entry || !entry.torrent) {
            res.writeHead(404, { 'Content-Type': 'text/plain' });
            res.end('Torrent no encontrado o expirado');
            return;
        }

        entry.lastAccess = Date.now();

        // Si los metadatos aún están descargándose (ej. tras auto-recuperación o apertura directa en VLC), esperar hasta 22s
        if (!entry.file && !entry.torrent.ready) {
            await new Promise((resolve) => {
                const timer = setTimeout(resolve, 22000);
                entry.torrent.once('ready', () => {
                    clearTimeout(timer);
                    resolve();
                });
            });
        }

        // Si se pasa ?file= o ?ep= en la URL de stream y el torrent es un pack, asegurar que apunte a ese archivo exacto
        if (entry.torrent.files && entry.torrent.files.length > 1 && (qFile || qEp || qIdx !== null)) {
            const matchedFile = pickTargetFile(entry.torrent, qIdx ?? entry.requestedFileIdx, qEp || entry.requestedEpCode, qFile || entry.requestedDn);
            if (matchedFile && (!entry.file || entry.file.name !== matchedFile.name)) {
                entry.file = matchedFile;
                entry.lastWindowPiece = -1;
                updateStreamingWindow(entry, 0);
            }
        }

        const file = entry.file || (entry.torrent.files && entry.torrent.files[0]);
        if (!file) {
            res.writeHead(503, { 'Content-Type': 'text/plain' });
            res.end('Metadatos del torrent aún cargando, reintente en unos segundos...');
            return;
        }

        const total = file.length;
        const mimeType = getMimeType(file.name);
        const range = req.headers.range;

        if (range) {
            const parts = range.replace(/bytes=/, '').split('-');
            const partialStart = parts[0];
            const partialEnd = parts[1];

            const start = Math.max(0, parseInt(partialStart, 10) || 0);
            const end = partialEnd ? Math.min(total - 1, parseInt(partialEnd, 10)) : total - 1;
            const chunkSize = (end - start) + 1;

            // Deslizar la ventana de descarga de 45 MB a la posición solicitada
            updateStreamingWindow(entry, start);

            res.writeHead(206, {
                'Content-Range': `bytes ${start}-${end}/${total}`,
                'Accept-Ranges': 'bytes',
                'Content-Length': chunkSize,
                'Content-Type': mimeType
            });

            const stream = file.createReadStream({ start, end });
            let bytesStreamed = 0;
            let lastWindowUpdateOffset = start;

            stream.on('data', (chunk) => {
                bytesStreamed += chunk.length;
                entry.lastAccess = Date.now();
                const currentPos = start + bytesStreamed;
                // Cada 8 MB reproducidos, avanzar suavemente la ventana deslizante de 45 MB
                if (currentPos - lastWindowUpdateOffset >= 8 * 1024 * 1024) {
                    lastWindowUpdateOffset = currentPos;
                    updateStreamingWindow(entry, currentPos);
                }
            });

            stream.on('error', (err) => {
                if (err.code !== 'PREMATURE_CLOSE' && err.code !== 'ERR_STREAM_PREMATURE_CLOSE') {
                    console.warn('[Streamer] Stream chunk error:', err.message);
                }
            });
            res.on('error', () => {
                stream.destroy();
            });
            stream.pipe(res);

            req.on('close', () => {
                stream.destroy();
            });
        } else {
            updateStreamingWindow(entry, 0);

            res.writeHead(200, {
                'Content-Length': total,
                'Accept-Ranges': 'bytes',
                'Content-Type': mimeType
            });

            const stream = file.createReadStream();
            let bytesStreamed = 0;
            let lastWindowUpdateOffset = 0;

            stream.on('data', (chunk) => {
                bytesStreamed += chunk.length;
                entry.lastAccess = Date.now();
                if (bytesStreamed - lastWindowUpdateOffset >= 8 * 1024 * 1024) {
                    lastWindowUpdateOffset = bytesStreamed;
                    updateStreamingWindow(entry, bytesStreamed);
                }
            });

            stream.on('error', (err) => {
                if (err.code !== 'PREMATURE_CLOSE' && err.code !== 'ERR_STREAM_PREMATURE_CLOSE') {
                    console.warn('[Streamer] Stream error:', err.message);
                }
            });
            res.on('error', () => {
                stream.destroy();
            });
            stream.pipe(res);

            req.on('close', () => {
                stream.destroy();
            });
        }
        return;
    }

    // POST /stop/:infoHash
    if (pathname.startsWith('/stop/')) {
        const infoHash = pathname.replace('/stop/', '').trim().toLowerCase();
        const entry = activeTorrents.get(infoHash);

        recentlyStopped.set(infoHash, Date.now());
        if (entry) {
            console.log(`[Streamer] Deteniendo torrent: ${infoHash}`);
            destroyTorrentEntry(infoHash, entry);
        }

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'stopped', infoHash }));
        return;
    }

    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Ruta no encontrada');
});

server.listen(PORT, HOST, () => {
    console.log(`🚀 [Torrent Streamer Daemon] Escuchando en http://${HOST}:${PORT}`);
});

// Limpieza automática cada 3 minutos de torrents sin actividad por más de 12 minutos
setInterval(() => {
    const now = Date.now();
    for (const [hash, ts] of recentlyStopped.entries()) {
        if (now - ts > 5 * 60 * 1000) recentlyStopped.delete(hash);
    }
    for (const [hash, entry] of activeTorrents.entries()) {
        if (now - entry.lastAccess > 12 * 60 * 1000) {
            console.log(`[Streamer] Limpiando torrent inactivo por timeout: ${hash}`);
            destroyTorrentEntry(hash, entry);
        }
    }
}, 3 * 60 * 1000);

process.on('uncaughtException', (err) => {
    if (err.code === 'PREMATURE_CLOSE' || err.code === 'ERR_STREAM_PREMATURE_CLOSE' || err.code === 'ECONNRESET' || (err.message && err.message.includes('Writable stream closed'))) {
        return;
    }
    console.error('[Streamer] Uncaught Exception:', err);
});

process.on('unhandledRejection', (reason) => {
    console.error('[Streamer] Unhandled Rejection:', reason);
});

process.on('SIGINT', () => {
    console.log('[Streamer] Apagando daemon...');
    client.destroy(() => {
        process.exit(0);
    });
});

