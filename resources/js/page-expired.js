// T3-3: what a Livewire request does on 419 (session expired, or a tampered/locked property in
// production). Livewire's own handler asks in English only; this asks in the page's language
// (<html lang>), then reloads if the visitor agrees. Asked once, like Livewire does.
// The Filament admin doesn't load this file: it stays English, with Livewire's default.

const messages = {
    en: 'This page has expired.\nRefresh the page to continue?',
    zh: '頁面已過期。\n要重新整理頁面嗎？',
};

let asked = false;

function message() {
    const lang = (document.documentElement.lang || 'en').toLowerCase();

    return lang.startsWith('zh') ? messages.zh : messages.en;
}

function register() {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status !== 419) {
                return;
            }

            preventDefault();

            if (asked) {
                return;
            }

            asked = true;

            if (window.confirm(message())) {
                window.location.reload();
            }
        });
    });
}

if (window.Livewire) {
    register();
} else {
    document.addEventListener('livewire:init', register);
}
