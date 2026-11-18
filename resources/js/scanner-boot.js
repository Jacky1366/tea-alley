// T3-1: starts the QR scanner when a page has [data-qr-scanner] (/barista/scan), and releases
// the camera whenever that page is left: a full page load (pagehide) or Livewire's
// wire:navigate (livewire:navigating). The scanner and jsQR are a separate chunk, loaded only
// on that page, so other pages don't download them.

let stopScanner = null;

async function mount() {
    const root = document.querySelector('[data-qr-scanner]');

    if (!root || root.dataset.qrStarted) {
        return;
    }

    root.dataset.qrStarted = '1';

    const { startScanner } = await import('./qr-scanner');

    // The page may have been left while the chunk was loading.
    if (document.contains(root)) {
        stopScanner = startScanner(root);
    }
}

function unmount() {
    stopScanner?.();
    stopScanner = null;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
} else {
    mount();
}

document.addEventListener('livewire:navigated', mount);
document.addEventListener('livewire:navigating', unmount);
window.addEventListener('pagehide', unmount);
