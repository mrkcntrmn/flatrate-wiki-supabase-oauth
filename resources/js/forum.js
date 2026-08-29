(function () {
    'use strict';

    const productLoginUrl = 'https://flatrate.wiki/login';
    let redirectStarted = false;

    function currentForumReturnTo() {
        const value = `${window.location.pathname}${window.location.search}${window.location.hash}`;
        return value.startsWith('/') && !value.startsWith('//') ? value : '/';
    }

    function loginDestination() {
        const next = `/community?returnTo=${encodeURIComponent(currentForumReturnTo())}`;
        const destination = new URL(productLoginUrl);
        destination.searchParams.set('next', next);
        return destination.toString();
    }

    function redirectLoginModal() {
        if (redirectStarted || !document.querySelector('.LogInModal')) {
            return false;
        }

        redirectStarted = true;
        window.location.assign(loginDestination());
        return true;
    }

    if (redirectLoginModal()) {
        return;
    }

    const root = document.documentElement;
    if (!root) {
        return;
    }

    const observer = new MutationObserver(() => {
        if (redirectLoginModal()) {
            observer.disconnect();
        }
    });

    observer.observe(root, { childList: true, subtree: true });
})();
