// T3-1: the camera scanner on /barista/scan. Loaded only on that page (see scanner-boot.js).
// Reads frames from the rear camera, decodes them with jsQR, and opens a valid pickup link on
// this site (pickupTarget). Other codes are ignored with a message; without a camera the page
// tells the barista to use Picked up on the board.

import jsQR from 'jsqr';
import { pickupTarget } from './pickup-url';

const FRAME_INTERVAL_MS = 150;
const MAX_FRAME_SIDE = 640; // downscale big camera frames: faster to decode, still plenty for a QR
const FOREIGN_MESSAGE_MS = 2500;

/**
 * Starts the camera and scanning inside root ([data-qr-scanner]).
 *
 * @param {HTMLElement} root
 * @returns {() => void} stops scanning and releases the camera
 */
export function startScanner(root) {
    const video = root.querySelector('[data-qr-video]');
    const prefix = root.dataset.pickupPath.replace(/[^/]+$/, '');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    let stream = null;
    let timer = null;
    let foreignTimer = null;
    let stopped = false;

    const show = (name) => {
        root.querySelectorAll('[data-qr-message]').forEach((element) => {
            element.classList.toggle('hidden', element.dataset.qrMessage !== name);
        });
    };

    const stop = () => {
        stopped = true;
        clearTimeout(timer);
        clearTimeout(foreignTimer);
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
    };

    const tick = () => {
        if (stopped) {
            return;
        }

        if (video.readyState >= video.HAVE_CURRENT_DATA && video.videoWidth > 0) {
            const scale = Math.min(1, MAX_FRAME_SIDE / Math.max(video.videoWidth, video.videoHeight));
            const width = Math.round(video.videoWidth * scale);
            const height = Math.round(video.videoHeight * scale);

            canvas.width = width;
            canvas.height = height;
            context.drawImage(video, 0, 0, width, height);

            const code = jsQR(context.getImageData(0, 0, width, height).data, width, height, {
                inversionAttempts: 'dontInvert', // our QR is black on white
            });

            if (code?.data) {
                const target = pickupTarget(code.data, prefix, window.location.origin);

                if (target) {
                    show('found');
                    stop();
                    window.location.assign(window.location.origin + target);

                    return;
                }

                show('foreign');
                clearTimeout(foreignTimer);
                foreignTimer = setTimeout(() => stopped || show('scanning'), FOREIGN_MESSAGE_MS);
            }
        }

        timer = setTimeout(tick, FRAME_INTERVAL_MS);
    };

    const start = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            show('unavailable');

            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
        } catch (error) {
            show(error?.name === 'NotAllowedError' || error?.name === 'SecurityError' ? 'blocked' : 'unavailable');

            return;
        }

        // Left the page while the permission prompt was open.
        if (stopped) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;

            return;
        }

        video.srcObject = stream;
        await video.play().catch(() => {});
        show('scanning');
        tick();
    };

    start();

    return stop;
}
