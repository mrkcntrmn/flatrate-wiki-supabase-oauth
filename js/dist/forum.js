/*! FlatRate Wiki forum authentication redirect bundle. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-direct-login', function () {
        var productLoginUrl = 'https://flatrate.wiki/login';
        var redirectStarted = false;

        function currentForumReturnTo() {
            var value = window.location.pathname + window.location.search + window.location.hash;
            return value.indexOf('/') === 0 && value.indexOf('//') !== 0 ? value : '/';
        }

        function loginDestination() {
            var next = '/community?returnTo=' + encodeURIComponent(currentForumReturnTo());
            var destination = new URL(productLoginUrl);
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

        var root = document.documentElement;
        if (!root || typeof MutationObserver === 'undefined') {
            return;
        }

        var observer = new MutationObserver(function () {
            if (redirectLoginModal()) {
                observer.disconnect();
            }
        });

        observer.observe(root, { childList: true, subtree: true });
    });

    module.exports = {};
})();
