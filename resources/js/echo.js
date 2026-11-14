import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/*
 * Host and scheme come from the page at runtime, so the same build works on
 * https://tea-alley.test (wss) and on php -S http://127.0.0.1:8123 (ws). The port comes
 * from the build (VITE_REVERB_PORT; 8080 locally): on the page's own port we'd reach
 * Herd's nginx, not Reverb. A tunnel that routes /app/ to Reverb on the same host needs a
 * build with VITE_REVERB_PORT=443 (README, Troubleshooting).
 *
 * Without a key (a build without Reverb settings) Echo isn't started, and the pages
 * simply poll every 5 seconds.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;
const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);

if (key) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: window.location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
    });
}

/** Milliseconds between refreshes while live (a safety net) and while not live (the fallback). */
const LIVE_INTERVAL = 30_000;
const POLLING_INTERVAL = 5_000;

/**
 * Live means the socket is connected AND this page's private channel subscription
 * succeeded. Connected but not subscribed (e.g. the auth request was refused) gets no
 * events, so it counts as Polling.
 */
function isLive(channel) {
    const pusher = window.Echo?.connector?.pusher;

    return pusher?.connection?.state === 'connected' && pusher.channel(channel)?.subscribed === true;
}

/*
 * x-data="realtimeRefresh('private-barista')", inside a Livewire component. Replaces
 * wire:poll, whose interval is fixed in the markup: refreshes the component every 30 s
 * while live and every 5 s otherwise. After the first connection, any change of state
 * refreshes at once, so events missed while Reverb was down show up immediately. Like
 * wire:poll, it doesn't refresh while the tab is hidden.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('realtimeRefresh', (channel) => ({
        live: false,
        wasLive: false,
        next: 0,
        timer: null,
        onVisible: null,

        init() {
            this.next = Date.now() + POLLING_INTERVAL;
            this.timer = setInterval(() => this.tick(), 1000);
            this.onVisible = () => document.hidden || this.refresh();
            document.addEventListener('visibilitychange', this.onVisible);
            this.tick();
        },

        destroy() {
            clearInterval(this.timer);
            document.removeEventListener('visibilitychange', this.onVisible);
        },

        tick() {
            const live = isLive(channel);

            if (live !== this.live) {
                this.live = live;
                // The first connection after the page loads needs no refresh: the page is fresh.
                if (this.wasLive) {
                    this.refresh();
                } else {
                    this.next = Date.now() + LIVE_INTERVAL;
                }
                this.wasLive ||= live;

                return;
            }

            if (!document.hidden && Date.now() >= this.next) {
                this.refresh();
            }
        },

        refresh() {
            this.next = Date.now() + (this.live ? LIVE_INTERVAL : POLLING_INTERVAL);
            this.$wire.$refresh();
        },
    }));
});
