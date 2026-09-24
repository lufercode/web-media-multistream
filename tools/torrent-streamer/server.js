import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import WebTorrent from 'webtorrent';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const PORT = parseInt(process.env.PORT || '8889', 10);
const HOST = '127.0.0.1';

// Carpeta de almacenamiento temporal
const CACHE_DIR = path.resolve(__dirname, '../../data/torrent_cache');
if (!fs.existsSync(CACHE_DIR)) {
    try {
        fs.mkdirSync(CACHE_DIR, { recursive: true });
    } catch (e) {
        console.error('Error creando CACHE_DIR:', e);
    }
}

const client = new WebTorrent({
    maxConns: 55,
    dht: true
});

const activeTorrents = new Map();

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
                streamUrl: `http://${HOST}:${PORT}/stream/${entry.torrent.infoHash}`
            }));
            return;
        }

        try {
            console.log(`[Streamer] Cargando magnet: ${magnet.substring(0, 60)}...`);
            const torrent = client.add(magnet, { path: CACHE_DIR });
            const resolvedHash = (torrent.infoHash || infoHash).toLowerCase();

            const entry = {
                torrent,
                file: null,
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
                    // Priorizar el archivo de video seleccionado
                    mainVideo.select();
                    console.log(`[Streamer] Video principal seleccionado: ${mainVideo.name} (${formatBytes(mainVideo.length)})`);
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
                streamUrl: `http://${HOST}:${PORT}/stream/${resolvedHash}`
            }));
        } catch (err) {
            console.error('[Streamer] Error al añadir torrent:', err);
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: err.message }));
        }
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

        // Calcular si el buffer inicial está listo (los primeros 5 MB o 1%)
        const downloadedBytes = t.downloaded || 0;
        const totalBytes = f ? f.length : (t.length || 1);
        const initialBufferNeeded = Math.min(8 * 1024 * 1024, totalBytes * 0.05); // 8 MB o 5%
        const isReadyToPlay = (entry.file !== null) && (downloadedBytes >= initialBufferNeeded || t.progress > 0.02 || t.numPeers > 0);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
            status: 'success',
            infoHash: t.infoHash,
            name: f ? f.name : t.name,
            totalSize: formatBytes(totalBytes),
            downloadedSize: formatBytes(downloadedBytes),
            progressPct: Math.round(t.progress * 1000) / 10,
            initialBufferPct: Math.min(100, Math.round((downloadedBytes / initialBufferNeeded) * 100)),
            downloadSpeed: formatBytes(t.downloadSpeed) + '/s',
            uploadSpeed: formatBytes(t.uploadSpeed) + '/s',
            peers: t.numPeers,
            ready: isReadyToPlay,
            streamUrl: `http://${HOST}:${PORT}/stream/${t.infoHash}`
        }));
        return;
    }

    // GET /stream/:infoHash
    if (pathname.startsWith('/stream/')) {
        const infoHash = pathname.replace('/stream/', '').trim().toLowerCase();
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

            const start = parseInt(partialStart, 10);
            const end = partialEnd ? parseInt(partialEnd, 10) : total - 1;
            const chunkSize = (end - start) + 1;

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
                entry.torrent.destroy();
            } catch (e) {}
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

// Limpieza automática cada 15 minutos de torrents inactivos sin uso por más de 30 minutos
setInterval(() => {
    const now = Date.now();
    for (const [hash, entry] of activeTorrents.entries()) {
        if (now - entry.lastAccess > 30 * 60 * 1000) {
            console.log(`[Streamer] Limpiando torrent inactivo por timeout: ${hash}`);
            try {
                entry.torrent.destroy();
            } catch (e) {}
            activeTorrents.delete(hash);
        }
    }
}, 15 * 60 * 1000);

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

