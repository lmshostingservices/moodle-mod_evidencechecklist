// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Media helpers: in-browser photo compression, audio recording and uploads.
 *
 * @module     mod_evidencechecklist/media
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Resize and re-encode a photo in the browser before upload.
 *
 * @param {File} file
 * @param {Number} maxpx longest edge
 * @returns {Promise<Blob>}
 */
export const compressImage = async(file, maxpx) => {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') {
        return file;
    }
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'});
    } catch (e) {
        return file;
    }
    const scale = Math.min(1, maxpx / Math.max(bitmap.width, bitmap.height));
    const w = Math.round(bitmap.width * scale);
    const h = Math.round(bitmap.height * scale);
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);
    const toBlob = (type, q) => new Promise(resolve => canvas.toBlob(resolve, type, q));
    // JPEG plays everywhere (Moodle, PDFs, every browser) and compresses photos well.
    const blob = await toBlob('image/jpeg', 0.82);
    if (!blob || blob.size >= file.size) {
        return file;
    }
    return blob;
};

/**
 * Upload a file to the plugin endpoint.
 *
 * @param {String} url
 * @param {Object} fields
 * @param {Blob} blob
 * @param {String} filename
 * @param {Function} onprogress
 * @returns {Promise<Object>}
 */
export const upload = (url, fields, blob, filename, onprogress) => new Promise((resolve, reject) => {
    const fd = new FormData();
    Object.keys(fields).forEach(k => fd.append(k, fields[k]));
    fd.append('file', blob, filename);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.upload.onprogress = e => {
        if (e.lengthComputable && onprogress) {
            onprogress(Math.round(e.loaded / e.total * 100));
        }
    };
    xhr.onload = () => {
        let data = null;
        try {
            data = JSON.parse(xhr.responseText);
        } catch (e) {
            reject(new Error(xhr.statusText || 'Upload failed'));
            return;
        }
        if (data && data.ok) {
            resolve(data);
        } else {
            reject(new Error((data && (data.error || data.message)) || 'Upload failed'));
        }
    };
    xhr.onerror = () => reject(new Error('Network error'));
    xhr.send(fd);
});

/**
 * Simple microphone recorder.
 */
export class Recorder {
    /**
     * Start recording.
     *
     * @returns {Promise<void>}
     */
    async start() {
        this.stream = await navigator.mediaDevices.getUserMedia({audio: true});
        const types = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg'];
        const type = types.find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
        this.recorder = new MediaRecorder(this.stream, type ? {mimeType: type} : {});
        this.chunks = [];
        this.recorder.ondataavailable = e => {
            if (e.data && e.data.size) {
                this.chunks.push(e.data);
            }
        };
        this.started = Date.now();
        this.recorder.start(1000);
    }

    /**
     * Stop and return the recording.
     *
     * @returns {Promise<Blob>}
     */
    stop() {
        return new Promise(resolve => {
            this.recorder.onstop = () => {
                this.stream.getTracks().forEach(t => t.stop());
                const type = this.recorder.mimeType || 'audio/webm';
                resolve(new Blob(this.chunks, {type}));
            };
            this.recorder.stop();
        });
    }

    /**
     * Seconds recorded so far.
     *
     * @returns {Number}
     */
    elapsed() {
        return Math.floor((Date.now() - this.started) / 1000);
    }
}

/**
 * Is recording supported?
 *
 * @returns {Boolean}
 */
export const canRecord = () => !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
