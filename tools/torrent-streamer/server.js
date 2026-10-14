import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import WebTorrent from 'webtorrent';
import MemoryChunkStore from 'memory-chunk-store';
import { buildManifest, getStremioStreams, getStremiosCatalog } from './stremio_addon.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = parseInt(process.env.PORT || '8889', 10);
const HOST = process.env.HOST || '0.0.0.0';

// =========================================================================
// GESTOR DE MEMORIA RAM EN VENTANA DESLIZANTE (Zero-Disk LRU Memory Chunk Store)
// =========================================================================
// Evita el uso del disco (/tmp) en Render para no provocar ENOSPC (disco lleno)
// y mantiene la memoria dentro de un límite estricto de ~220 MB en RAM.
const MAX_RAM_BUFFER_BYTES = 220 * 1024 * 1024; // 220 MB límite máximo de RAM para fragmentos de video
const LOOKAHEAD_SECONDS = 60;                   // 60s de adelanto dinámico según bitrate
const BACKBUFFER_SECONDS = 30;                  // 30s de retención previa para rebobinado instantáneo
const INITIAL_BUFFER_SECONDS = 25;              // 25s de pre-buffer requerido antes de ready: true
const ESTIMATED_MOVIE_SECONDS = 6000;           // 100 minutos duración estimada base para cálculo de bitrate

class LruMemoryChunkStore extends MemoryChunkStore {
    constructor(chunkLength, opts = {}) {
        super(chunkLength, opts);
        this.torrent = opts.torrent || null;
        if (opts.torrent) {
            opts.torrent._lruStore = this;
        }
        this.maxBytes = opts.maxBytes || MAX_RAM_BUFFER_BYTES;
        this.currentBytes = 0;
        this.pinnedChunks = new Set();
        this.windowStart = 0;
        this.windowEnd = 0;
        this.lastUsed = new Map();
        this.chunkSizes = new Map();
    }

    pin(startPiece, endPiece) {
        for (let i = startPiece; i <= endPiece; i++) {
            this.pinnedChunks.add(i);
        }
    }

    setWindow(startPiece, endPiece) {
        this.windowStart = startPiece;
        this.windowEnd = endPiece;
    }

    put(index, buf, cb = () => {}) {
        if (this.closed) return queueMicrotask(() => cb(new Error('Storage is closed')));

        const isLastChunk = (index === this.lastChunkIndex);
        if (isLastChunk && buf.length !== this.lastChunkLength) {
            return queueMicrotask(() => cb(new Error('Last chunk length must be ' + this.lastChunkLength)));
        }
        if (!isLastChunk && buf.length !== this.chunkLength) {
            return queueMicrotask(() => cb(new Error('Chunk length must be ' + this.chunkLength)));
        }

        const prevSize = this.chunkSizes.get(index) || 0;
        this.chunks[index] = buf;
        this.chunkSizes.set(index, buf.length);
        this.lastUsed.set(index, Date.now());
        this.currentBytes += (buf.length - prevSize);

        if (this.currentBytes > this.maxBytes) {
            this._evictOldChunks();
        }

        queueMicrotask(() => cb(null));
    }

    get(index, opts, cb = () => {}) {
        if (typeof opts === 'function') return this.get(index, null, opts);
        this.lastUsed.set(index, Date.now());
        return super.get(index, opts, cb);
    }

    _evictOldChunks() {
        const candidates = [];
        for (const [idx, size] of this.chunkSizes.entries()) {
            if (this.pinnedChunks.has(idx)) continue;
            const isInsideWindow = (idx >= this.windowStart && idx <= this.windowEnd);
            const isBehindWindow = (idx < this.windowStart);
            const lastTs = this.lastUsed.get(idx) || 0;
            candidates.push({ idx, size, isInsideWindow, isBehindWindow, lastTs });
        }

        // Orden: 1) Piezas ya vistas hacia atrás, 2) Piezas fuera de ventana, 3) Más antiguas
        candidates.sort((a, b) => {
            if (a.isBehindWindow !== b.isBehindWindow) {
                return a.isBehindWindow ? -1 : 1;
            }
            if (a.isInsideWindow !== b.isInsideWindow) {
                return a.isInsideWindow ? 1 : -1;
            }
            return a.lastTs - b.lastTs;
        });

        for (const cand of candidates) {
            if (this.currentBytes <= this.maxBytes * 0.85) break;
            if (cand.isInsideWindow && this.currentBytes <= this.maxBytes) break;

            const idx = cand.idx;
            const size = cand.size;
            this.chunks[idx] = undefined;
            this.chunkSizes.delete(idx);
            this.lastUsed.delete(idx);
            this.currentBytes -= size;

            if (this.torrent) {
                try {
                    if (typeof this.torrent._markUnverified === 'function' && this.torrent.pieces) {
                        this.torrent._markUnverified(idx);
                    }
                    if (typeof this.torrent.deselect === 'function') {
                        this.torrent.deselect(idx, idx, false);
                    }
                } catch (e) {}
            }
        }
    }
}

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

// Configuración optimizada para streaming y descarga rápida (Render Free Tier 512 MB RAM con Zero-Disk LRU):
// - maxConns: 24 (conecta a más semillas de alta velocidad simultáneas)
// - utp: false (usa TCP nativo del kernel Linux, ahorrando ~40% de CPU)
// - downloadLimit: 15.0 MB/s (~125 Mbps, descarga ultra-rápida aprovechando conexiones de alta velocidad como 500 Mbps)
// - uploadLimit: 128 KB/s
const MAX_DOWNLOAD_RATE = Math.floor(15.0 * 1024 * 1024); // 15728640 bytes/s (~125 Mbps)
const MAX_UPLOAD_RATE = 128 * 1024; // 131072 bytes/s

const client = new WebTorrent({
    maxConns: 24,
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
    res.setHeader('Access-Control-Expose-Headers', 'Content-Range, Content-Length, Accept-Ranges, Content-Type, Content-Disposition');
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
            if (typeof torrent._deselect === 'function') {
                torrent._deselect(0, torrent.pieces.length - 1, false);
                torrent._deselect(0, torrent.pieces.length - 1, true);
            } else if (typeof torrent.deselect === 'function') {
                torrent.deselect(0, torrent.pieces.length - 1, false);
            }
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

// Ventana deslizante dinámica (Dynamic Time-Based Streaming Window):
// - 60 segundos de adelanto (lookahead) según el bitrate estimado del video.
// - 30 segundos de retención hacia atrás (back-buffer) para rebobinado instantáneo.
// - Piezas iniciales (10 MB cabecera moov/ebml) y finales (4 MB índice cues) ancladas.
// - Fuera de este rango, las piezas quedan deseleccionadas y el almacén LRU en RAM
//   desaloja piezas antiguas para mantener el consumo total de RAM bajo 220 MB.
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

    if (entry.lastWindowPiece === currentPiece) {
        return;
    }
    entry.lastWindowPiece = currentPiece;

    // Cálculo dinámico de bitrate en base al tamaño total del archivo (~100 min)
    const estBitrate = Math.max(250 * 1024, Math.floor(file.length / ESTIMATED_MOVIE_SECONDS));

    // Lookahead dinámico de 60 segundos (mínimo 35 MB, máximo 180 MB)
    const lookaheadBytes = Math.min(180 * 1024 * 1024, Math.max(35 * 1024 * 1024, Math.round(estBitrate * LOOKAHEAD_SECONDS)));
    // Back-buffer dinámico de 30 segundos (mínimo 15 MB, máximo 50 MB)
    const backBytes = Math.min(50 * 1024 * 1024, Math.max(15 * 1024 * 1024, Math.round(estBitrate * BACKBUFFER_SECONDS)));

    const headPieces = Math.max(4, Math.ceil((10 * 1024 * 1024) / pieceLength));   // ~10 MB cabecera
    const tailPieces = Math.max(2, Math.ceil((4 * 1024 * 1024) / pieceLength));    // ~4 MB cola
    const lookaheadPieces = Math.max(6, Math.ceil(lookaheadBytes / pieceLength));  // 60s adelante
    const backPieces = Math.max(3, Math.ceil(backBytes / pieceLength));            // 30s atrás
    const criticalPieces = Math.max(3, Math.ceil((8 * 1024 * 1024) / pieceLength)); // ~8 MB urgentes

    const winStart = Math.max(startPiece, currentPiece - backPieces);
    const winEnd = Math.min(endPiece, currentPiece + lookaheadPieces);
    const critEnd = Math.min(endPiece, currentPiece + criticalPieces);

    clearAllSelections(torrent);

    try {
        // 1. Cabecera inicial de video
        const headEnd = Math.min(endPiece, startPiece + headPieces);
        torrent.select(startPiece, headEnd, false);

        // 2. Cola final (índice MKV Cues o MP4 moov)
        const tailStart = Math.max(startPiece, endPiece - tailPieces);
        torrent.select(tailStart, endPiece, false);

        // 3. Ventana activa: 30s atrás + 60s adelante
        torrent.select(winStart, winEnd, 1);

        // 4. Piezas críticas inmediatas para el reproductor
        torrent.critical(currentPiece, critEnd);

        // 5. Configurar anclajes y rango activo en el almacén de RAM
        const memStore = entry.memStore || torrent._lruStore || (torrent.store && torrent.store.store);
        if (memStore && typeof memStore.pin === 'function') {
            memStore.pin(startPiece, headEnd);
            memStore.pin(tailStart, endPiece);
            memStore.setWindow(winStart, winEnd);
        }
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
            entry.torrent.destroy();
        } catch (e) {
            try { entry.torrent.destroy(); } catch (e2) {}
        }
    }
    if (entry.memStore) {
        try { entry.memStore.destroy(); } catch (e) {}
        entry.memStore = null;
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
    console.log(`[Streamer] Inicializando torrent (RAM LRU): ${infoHash || cleanMagnet.substring(0, 50)} (ep=${requestedEpCode || '-'}, idx=${requestedFileIdx ?? '-'}, file=${requestedDn || '-'})`);

    const existingInClient = infoHash ? client.torrents.find(t => t.infoHash && t.infoHash.toLowerCase() === infoHash) : null;
    const torrent = existingInClient || client.add(cleanMagnet, {
        store: LruMemoryChunkStore,
        storeOpts: { maxBytes: MAX_RAM_BUFFER_BYTES },
        deselect: true,
        announce: DEFAULT_TRACKERS
    });

    const resolvedHash = (torrent.infoHash || infoHash).toLowerCase();
    const entry = {
        torrent,
        memStore: null,
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

        entry.memStore = torrent._lruStore || (torrent.store && torrent.store.store) || null;

        // 1. Limpiar todas las selecciones automáticas de WebTorrent para NO descargar otros episodios ni el archivo entero de golpe
        clearAllSelections(torrent);

        // 2. Seleccionar el episodio/archivo objetivo y activar ventana dinámica de 60s
        const mainVideo = pickTargetFile(torrent, entry.requestedFileIdx, entry.requestedEpCode, entry.requestedDn);
        if (mainVideo) {
            entry.file = mainVideo;
            entry.lastWindowPiece = -1;
            updateStreamingWindow(entry, 0);
            console.log(`[Streamer] Video seleccionado (Dynamic 60s Window in RAM): ${mainVideo.name} (${formatBytes(mainVideo.length)})`);
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
        const mem = process.memoryUsage();
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'ok',
            uptime: Math.round(process.uptime()),
            torrentsCount: activeTorrents.size,
            clientDownloadSpeed: client.downloadSpeed,
            clientUploadSpeed: client.uploadSpeed,
            memoryMB: Math.round(mem.rss / (1024 * 1024)),
            heapUsedMB: Math.round(mem.heapUsed / (1024 * 1024)),
            maxRamLimitMB: Math.round(MAX_RAM_BUFFER_BYTES / (1024 * 1024))
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

    // GET /stremio/:config/catalog/:type/:id.json  (o sin config)
    // Soporta skip via query param &skip=20
    const stremioCatalogMatch = pathname.match(/^(?:\/stremio)?(?:\/([^/]+))?\/catalog\/(movie|series|anime|tv)\/([^/]+)\.json$/i);
    if (stremioCatalogMatch && req.method === 'GET') {
        const configParam = stremioCatalogMatch[1] || parsedUrl.searchParams.get('config') || 'default';
        const rawType = stremioCatalogMatch[2].toLowerCase();
        const catalogId = decodeURIComponent(stremioCatalogMatch[3]);
        const skip = parseInt(parsedUrl.searchParams.get('skip') || '0', 10) || 0;
        const stremioType = (rawType === 'tv') ? 'series' : rawType;

        try {
            console.log(`[Stremio Catalog] ${catalogId} (type=${stremioType}, skip=${skip})...`);
            const result = await getStremiosCatalog(catalogId, stremioType, skip);
            res.writeHead(200, {
                'Content-Type': 'application/json; charset=utf-8',
                'Cache-Control': 'public, max-age=2700'  // 45 min, igual que el TTL interno
            });
            res.end(JSON.stringify(result));
        } catch (err) {
            console.error('[Stremio Catalog] Error:', err);
            res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify({ metas: [] }));
        }
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
        const fileProgress = (f && typeof f.progress === 'number') ? f.progress : (t.progress || 0);

        // Bitrate estimado del video en bytes/segundo (~100 minutos base)
        const estBitrate = Math.max(250 * 1024, Math.floor(totalBytes / ESTIMATED_MOVIE_SECONDS));
        // Pre-buffer de 25 segundos para reproducción fluida sin interrupciones (mínimo 6 MB, máximo 60 MB)
        const initialBufferNeeded = Math.min(60 * 1024 * 1024, Math.max(6 * 1024 * 1024, Math.round(estBitrate * INITIAL_BUFFER_SECONDS)));

        const memStore = entry.memStore || t._lruStore || (t.store && t.store.store);
        const ramUsedBytes = memStore ? memStore.currentBytes : (t.downloaded || 0);

        // Asegurar que la cabecera del contenedor (moov/ebml) esté lista
        const startPiece = f ? f._startPiece : 0;
        const headReady = (t.bitfield && typeof t.bitfield.get === 'function') ? t.bitfield.get(startPiece) : true;

        const isReadyToPlay = (entry.file !== null) && headReady && (
            downloadedBytes >= initialBufferNeeded ||
            (downloadedBytes >= initialBufferNeeded * 0.5 && t.downloadSpeed > 350 * 1024)
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
            bufferTargetSeconds: INITIAL_BUFFER_SECONDS,
            lookaheadSeconds: LOOKAHEAD_SECONDS,
            backbufferSeconds: BACKBUFFER_SECONDS,
            ramBufferMB: Math.round(ramUsedBytes / (1024 * 1024)),
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

    // GET /stream/:infoHash (o /stream/:infoHash/:filename) o /download/:infoHash
    if (pathname.startsWith('/stream/') || pathname.startsWith('/download/')) {
        const isDownloadRoute = pathname.startsWith('/download/') || parsedUrl.searchParams.get('download') === '1';
        const rawStreamPath = pathname.replace(/^\/(?:stream|download)\//, '').trim();
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
        const mimeType = isDownloadRoute ? 'application/octet-stream' : getMimeType(file.name);
        const range = req.headers.range;

        const safeFilename = path.basename(file.name || 'video.mp4').replace(/[^\w\s.-]/gi, '_');
        const encodedFilename = encodeURIComponent(file.name || 'video.mp4');
        const downloadHeaders = isDownloadRoute ? {
            'Content-Disposition': `attachment; filename="${safeFilename}"; filename*=UTF-8''${encodedFilename}`
        } : {};

        if (range) {
            const parts = range.replace(/bytes=/, '').split('-');
            const partialStart = parts[0];
            const partialEnd = parts[1];

            const start = Math.max(0, parseInt(partialStart, 10) || 0);
            const end = partialEnd ? Math.min(total - 1, parseInt(partialEnd, 10)) : total - 1;
            const chunkSize = (end - start) + 1;

            // Deslizar la ventana de descarga a la posición solicitada
            updateStreamingWindow(entry, start);

            res.writeHead(206, {
                'Content-Range': `bytes ${start}-${end}/${total}`,
                'Accept-Ranges': 'bytes',
                'Content-Length': chunkSize,
                'Content-Type': mimeType,
                ...downloadHeaders
            });

            const stream = file.createReadStream({ start, end });
            let bytesStreamed = 0;
            let lastWindowUpdateOffset = start;

            stream.on('data', (chunk) => {
                bytesStreamed += chunk.length;
                entry.lastAccess = Date.now();
                const currentPos = start + bytesStreamed;
                // Cada 8 MB transferidos, avanzar suavemente la ventana deslizante
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
                'Content-Type': mimeType,
                ...downloadHeaders
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

