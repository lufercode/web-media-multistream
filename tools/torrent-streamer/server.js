import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import WebTorrent from 'webtorrent';

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
if (!fs.existsSync(CACHE_DIR)) {
    try {
        fs.mkdirSync(CACHE_DIR, { recursive: true });
    } catch (e) {
        console.error('Error creando CACHE_DIR:', e);
    }
}

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
    'udp://tracker.torrent.eu.org:451/announce'
];

const client = new WebTorrent({
    maxConns: 55,
    dht: true
});

const activeTorrents = new Map();

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

const server = http.createServer(async (req, res) => {
    setCors(res);

    if (req.method === 'OPTIONS') {
        res.writeHead(204);
        res.end();
        return;
    }

    const parsedUrl = new URL(req.url, `http://${req.headers.host}`);
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
            clientUploadSpeed: client.uploadSpeed
        }));
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

        // Extraer infoHash preliminar
        let infoHash = '';
        const match = magnet.match(/xt=urn:btih:([a-zA-Z0-9]+)/i);
        if (match) {
            infoHash = match[1].toLowerCase();
        }

        if (infoHash && activeTorrents.has(infoHash)) {
            const entry = activeTorrents.get(infoHash);
            entry.lastAccess = Date.now();
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                status: 'success',
                infoHash: entry.torrent.infoHash,
                name: entry.file ? entry.file.name : entry.torrent.name,
                size: entry.file ? formatBytes(entry.file.length) : formatBytes(entry.torrent.length),
                ready: !!entry.file,
                streamUrl: `${baseUrl}/stream/${entry.torrent.infoHash}`
            }));
            return;
        }

        try {
            // Limitar estrictamente a máximo 2 torrents simultáneos para no saturar RAM ni ancho de banda en Render
            const MAX_CONCURRENT_TORRENTS = 2;
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
                    console.log(`[Streamer] Límite de ${MAX_CONCURRENT_TORRENTS} torrents alcanzado. Deteniendo el más inactivo: ${oldestHash}`);
                    const oldEntry = activeTorrents.get(oldestHash);
                    try {
                        oldEntry.torrent.destroy({ destroyStore: true });
                    } catch (e) {
                        try { oldEntry.torrent.destroy(); } catch (e2) {}
                    }
                    activeTorrents.delete(oldestHash);
                }
            }

            console.log(`[Streamer] Cargando magnet: ${magnet.substring(0, 60)}...`);
            const torrent = client.add(magnet, {
                path: CACHE_DIR,
                announce: DEFAULT_TRACKERS
            });
            const resolvedHash = (torrent.infoHash || infoHash).toLowerCase();

            const entry = {
                torrent,
                file: null,
                subtitles: [],
                addedAt: Date.now(),
                lastAccess: Date.now()
            };
            activeTorrents.set(resolvedHash, entry);

            const onTorrentReady = () => {
                if (entry.file) return;

                // Deseleccionar todos los archivos por defecto para ahorrar ancho de banda
                torrent.deselect(0, torrent.pieces.length - 1, false);

                // Encontrar el archivo de video más grande (.mp4, .mkv, .avi, etc.)
                let mainVideo = null;
                const videoRegex = /\.(mp4|mkv|webm|avi|mov|m4v|ts)$/i;
                const videoFiles = torrent.files.filter(f => videoRegex.test(f.name));

                if (videoFiles.length > 0) {
                    mainVideo = videoFiles.reduce((prev, curr) => (prev.length > curr.length ? prev : curr));
                } else if (torrent.files.length > 0) {
                    mainVideo = torrent.files.reduce((prev, curr) => (prev.length > curr.length ? prev : curr));
                }

                if (mainVideo) {
                    entry.file = mainVideo;
                    mainVideo.select();
                    // Priorizar secuencialmente las primeras 25 piezas (cabecera) y las últimas 4 piezas (índice/Cues/moov)
                    if (typeof mainVideo._startPiece === 'number' && typeof mainVideo._endPiece === 'number') {
                        const headEnd = Math.min(mainVideo._endPiece, mainVideo._startPiece + 25);
                        const tailStart = Math.max(mainVideo._startPiece, mainVideo._endPiece - 4);
                        try {
                            torrent.critical(mainVideo._startPiece, headEnd);
                            torrent.critical(tailStart, mainVideo._endPiece);
                        } catch (e) {}
                    }
                    console.log(`[Streamer] Video principal seleccionado: ${mainVideo.name} (${formatBytes(mainVideo.length)})`);
                }

                // Detectar archivos de subtítulos en el torrent (.srt, .vtt)
                const subRegex = /\.(srt|vtt)$/i;
                const subFiles = torrent.files.filter(f => subRegex.test(f.name));
                entry.subtitles = subFiles.map(sub => {
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
                    // Seleccionar para que descargue de inmediato (archivos diminutos de 50-100 KB)
                    try { sub.select(); } catch (e) {}
                    return {
                        name: label,
                        fileName: sub.name,
                        index: torrent.files.indexOf(sub),
                        size: formatBytes(sub.length)
                    };
                });
                if (entry.subtitles.length > 0) {
                    console.log(`[Streamer] Subtítulos detectados en torrent: ${entry.subtitles.length}`);
                }
            };

            if (torrent.ready) {
                onTorrentReady();
            } else {
                torrent.once('ready', onTorrentReady);
            }

            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                status: 'success',
                infoHash: resolvedHash,
                ready: !!entry.file,
                streamUrl: `${baseUrl}/stream/${resolvedHash}`
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
        const entry = activeTorrents.get(infoHash);

        if (!entry) {
            res.writeHead(404, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: 'Torrent no encontrado o detenido' }));
            return;
        }

        entry.lastAccess = Date.now();
        const t = entry.torrent;
        const f = entry.file;

        // Colchón inicial óptimo y ligero (2.0 a 3.5 MB, ~0.2% - 0.5% del archivo):
        // Permite arranque casi instantáneo en 3-5 segundos sin esperar minutos
        const downloadedBytes = t.downloaded || 0;
        const totalBytes = f ? f.length : (t.length || 1);
        const initialBufferNeeded = Math.min(3.5 * 1024 * 1024, Math.max(1.5 * 1024 * 1024, totalBytes * 0.003));

        // Condición de arranque rápido:
        // Arranca con 2.5 MB, o con solo 1.2 MB si la velocidad de descarga supera los 200 KB/s
        const isReadyToPlay = (entry.file !== null) && (
            downloadedBytes >= initialBufferNeeded ||
            (downloadedBytes >= 1.2 * 1024 * 1024 && t.downloadSpeed > 200 * 1024)
        );

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'success',
            infoHash: t.infoHash,
            name: f ? f.name : t.name,
            totalSize: formatBytes(totalBytes),
            downloadedSize: formatBytes(downloadedBytes),
            bufferTargetSize: formatBytes(initialBufferNeeded),
            progressPct: Math.round(t.progress * 1000) / 10,
            initialBufferPct: Math.min(100, Math.round((downloadedBytes / initialBufferNeeded) * 100)),
            downloadSpeed: formatBytes(t.downloadSpeed) + '/s',
            uploadSpeed: formatBytes(t.uploadSpeed) + '/s',
            peers: t.numPeers,
            ready: isReadyToPlay,
            streamUrl: `${baseUrl}/stream/${t.infoHash}`,
            subtitles: (entry.subtitles || []).map(s => ({
                name: s.name,
                fileName: s.fileName,
                index: s.index,
                size: s.size,
                url: `${baseUrl}/subtitles/${t.infoHash}/${s.index}`
            }))
        }));
        return;
    }

    // GET /stream/:infoHash (o /stream/:infoHash/:filename)
    if (pathname.startsWith('/stream/')) {
        const rawStreamPath = pathname.replace('/stream/', '').trim();
        const infoHash = (rawStreamPath.split('/')[0] || '').toLowerCase();
        const entry = activeTorrents.get(infoHash);

        if (!entry || !entry.torrent) {
            res.writeHead(404, { 'Content-Type': 'text/plain' });
            res.end('Torrent no encontrado o expirado');
            return;
        }

        entry.lastAccess = Date.now();
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

            // Priorizar inmediatamente las piezas solicitadas por el navegador o VLC (saltos / índice final)
            if (entry.torrent.pieceLength && typeof file._startPiece === 'number' && typeof file._endPiece === 'number') {
                const reqPieceStart = Math.min(file._endPiece, file._startPiece + Math.floor(start / entry.torrent.pieceLength));
                const reqPieceEnd = Math.min(file._endPiece, reqPieceStart + 4);
                try {
                    entry.torrent.critical(reqPieceStart, reqPieceEnd);
                } catch (e) {}
            }

            res.writeHead(206, {
                'Content-Range': `bytes ${start}-${end}/${total}`,
                'Accept-Ranges': 'bytes',
                'Content-Length': chunkSize,
                'Content-Type': mimeType
            });

            const stream = file.createReadStream({ start, end });
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
            res.writeHead(200, {
                'Content-Length': total,
                'Accept-Ranges': 'bytes',
                'Content-Type': mimeType
            });

            const stream = file.createReadStream();
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

        if (entry) {
            console.log(`[Streamer] Deteniendo torrent: ${infoHash}`);
            try {
                entry.torrent.destroy({ destroyStore: true });
            } catch (e) {
                try { entry.torrent.destroy(); } catch (e2) {}
            }
            activeTorrents.delete(infoHash);
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

// Limpieza automática cada 5 minutos de torrents inactivos sin solicitudes por más de 15 minutos
setInterval(() => {
    const now = Date.now();
    for (const [hash, entry] of activeTorrents.entries()) {
        if (now - entry.lastAccess > 15 * 60 * 1000) {
            console.log(`[Streamer] Limpiando torrent inactivo por timeout: ${hash}`);
            try {
                entry.torrent.destroy({ destroyStore: true });
            } catch (e) {
                try { entry.torrent.destroy(); } catch (e2) {}
            }
            activeTorrents.delete(hash);
        }
    }
}, 5 * 60 * 1000);

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

