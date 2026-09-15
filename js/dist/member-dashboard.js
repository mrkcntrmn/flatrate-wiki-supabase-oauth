/*! FlatRate Wiki Community owner/public member dashboard shell. */
(function (root) {
    'use strict';

    var FlatRateOwnerDashboard = {
        ACCOUNT_URL: 'https://flatrate.wiki/account',
        SETTINGS_PATH: '/settings',
        PUBLIC_KEYS: ['flatRateMemberNumber', 'flatRateMemberNickname'],
        dto: function (user) {
            if (!user || typeof user.attribute !== 'function') {
                return null;
            }
            var value = user.attribute('flatRateOwnerDashboard');
            if (!value || typeof value !== 'object' || Array.isArray(value)) {
                return null;
            }
            if (Number(value.schema_version) !== 1) {
                return null;
            }
            if (!Array.isArray(value.sections)) {
                return null;
            }
            return value;
        },
        hasSection: function (dashboard, id) {
            if (!dashboard || !Array.isArray(dashboard.sections)) {
                return false;
            }
            return dashboard.sections.some(function (section) {
                return section && section.id === id;
            });
        },
        memberNumber: function (user) {
            var value = user && typeof user.attribute === 'function' ? user.attribute('flatRateMemberNumber') : null;
            var number = Number(value);
            return Number.isInteger(number) && number > 0 ? number : null;
        },
        accountUrl: function (dashboard) {
            var value = dashboard && dashboard.account_url ? String(dashboard.account_url) : '';
            return value.indexOf('https://flatrate.wiki/account') === 0 ? value : this.ACCOUNT_URL;
        },
        settingsPath: function (dashboard) {
            var value = dashboard && dashboard.settings_path ? String(dashboard.settings_path) : '';
            return value.indexOf('/settings') === 0 ? value : this.SETTINGS_PATH;
        },
    };

    root.FlatRateOwnerDashboard = FlatRateOwnerDashboard;

    if (typeof app === 'undefined') {
        if (typeof module !== 'undefined') {
            module.exports = {};
        }
        return;
    }

    app.initializers.add('flatrate-wiki-member-dashboard', function () {
        function coreExport(id) {
            if (
                typeof flarum !== 'undefined' &&
                flarum.reg &&
                typeof flarum.reg.get === 'function'
            ) {
                var registered = flarum.reg.get('core', id);
                if (registered) {
                    return registered;
                }
            }
            var compat =
                typeof flarum !== 'undefined' && flarum.core && flarum.core.compat
                    ? flarum.core.compat
                    : null;
            return compat && compat[id] ? compat[id] : null;
        }

        var extendModule = coreExport('common/extend');
        var extend =
            extendModule && typeof extendModule.extend === 'function'
                ? extendModule.extend
                : null;
        var override =
            extendModule && typeof extendModule.override === 'function'
                ? extendModule.override
                : null;

        var userPageModule = coreExport('forum/components/UserPage');
        var UserPage = userPageModule && (userPageModule.default || userPageModule);
        var postsPageModule = coreExport('forum/components/PostsUserPage');
        var PostsUserPage = postsPageModule && (postsPageModule.default || postsPageModule);
        var userCardModule = coreExport('forum/components/UserCard');
        var UserCard = userCardModule && (userCardModule.default || userCardModule);
        var linkButtonModule = coreExport('common/components/LinkButton');
        var LinkButton = linkButtonModule && (linkButtonModule.default || linkButtonModule);

        if (typeof extend !== 'function' || typeof m !== 'function') {
            return;
        }

        function t(key, params) {
            return app.translator.trans('flatrate-dashboard.forum.' + key, params || {});
        }

        function sectionIds(dashboard) {
            return dashboard.sections
                .map(function (section) {
                    return section && section.id ? String(section.id) : '';
                })
                .filter(Boolean);
        }

        function userHref(user) {
            if (app.route && typeof app.route.user === 'function') {
                return app.route.user(user);
            }
            var slug = user && typeof user.slug === 'function' ? user.slug() : '';
            return slug ? '/u/' + slug : '/';
        }

        function discussionsHref(user) {
            if (app.route && typeof app.route === 'function') {
                try {
                    return app.route('user.discussions', {
                        username: typeof user.slug === 'function' ? user.slug() : user.username(),
                    });
                } catch (error) {
                    return userHref(user) + '/discussions';
                }
            }
            return userHref(user) + '/discussions';
        }

        function addNavItem(items, key, vnode, priority) {
            if (!items || typeof items.add !== 'function' || !vnode) {
                return;
            }
            items.add(key, vnode, priority);
        }

        if (UserCard && UserCard.prototype) {
            extend(UserCard.prototype, 'infoItems', function (items) {
                var user = this.attrs && this.attrs.user;
                var number = FlatRateOwnerDashboard.memberNumber(user);
                if (!number || !items || typeof items.add !== 'function') {
                    return;
                }
                items.add(
                    'flatrate-member-number',
                    m('span.FlatRateMemberNumber', t('member_number', { number: number })),
                    110
                );
            });
        }

        if (UserPage && UserPage.prototype && LinkButton) {
            extend(UserPage.prototype, 'navItems', function (items) {
                var dashboard = FlatRateOwnerDashboard.dto(this.user);
                if (!dashboard) {
                    return;
                }

                var ids = sectionIds(dashboard);
                var settingsPath = FlatRateOwnerDashboard.settingsPath(dashboard);
                var accountUrl = FlatRateOwnerDashboard.accountUrl(dashboard);

                if (ids.indexOf('overview') !== -1) {
                    addNavItem(
                        items,
                        'flatrate-overview',
                        m(
                            LinkButton,
                            {
                                href: userHref(this.user),
                                icon: 'fas fa-th-large',
                            },
                            t('overview')
                        ),
                        115
                    );
                }

                if (ids.indexOf('identity') !== -1) {
                    addNavItem(
                        items,
                        'flatrate-identity',
                        m(
                            LinkButton,
                            {
                                href: settingsPath,
                                icon: 'fas fa-id-badge',
                            },
                            t('identity')
                        ),
                        112
                    );
                }

                if (ids.indexOf('account_security') !== -1) {
                    addNavItem(
                        items,
                        'flatrate-account-security',
                        m(
                            'a.Button.LinkButton.hasIcon',
                            {
                                href: accountUrl,
                                rel: 'noopener noreferrer',
                            },
                            [m('i.icon.fas.fa-user-shield.Button-icon'), m('span.Button-label', t('account_security'))]
                        ),
                        20
                    );
                }

                if (ids.indexOf('notifications') !== -1) {
                    addNavItem(
                        items,
                        'flatrate-notifications',
                        m(
                            LinkButton,
                            {
                                href: settingsPath,
                                icon: 'fas fa-bell',
                            },
                            t('notifications')
                        ),
                        15
                    );
                }
            });
        }

        if (PostsUserPage && PostsUserPage.prototype && typeof override === 'function') {
            override(PostsUserPage.prototype, 'content', function (original) {
                var vnode = typeof original === 'function' ? original() : original;
                var dashboard = FlatRateOwnerDashboard.dto(this.user);
                if (!dashboard) {
                    return vnode;
                }

                var number = FlatRateOwnerDashboard.memberNumber(this.user);
                var displayName =
                    this.user && typeof this.user.displayName === 'function' ? this.user.displayName() : '';
                var settingsPath = FlatRateOwnerDashboard.settingsPath(dashboard);
                var accountUrl = FlatRateOwnerDashboard.accountUrl(dashboard);
                var ids = sectionIds(dashboard);
                var cards = [];

                if (ids.indexOf('identity') !== -1) {
                    cards.push(
                        m('section.FlatRateOwnerDashboard-card', [
                            m('h3.FlatRateOwnerDashboard-cardTitle', t('identity')),
                            number ? m('p.FlatRateOwnerDashboard-memberNumber', t('member_number', { number: number })) : null,
                            displayName ? m('p.FlatRateOwnerDashboard-displayName', displayName) : null,
                            m('p.FlatRateOwnerDashboard-help', t('identity_help')),
                            m('a.Button', { href: settingsPath }, t('manage_identity')),
                        ])
                    );
                }

                if (ids.indexOf('contributions') !== -1) {
                    cards.push(
                        m('section.FlatRateOwnerDashboard-card', [
                            m('h3.FlatRateOwnerDashboard-cardTitle', t('contributions')),
                            m('p.FlatRateOwnerDashboard-help', t('contributions_help')),
                            m(
                                'a.Button',
                                { href: discussionsHref(this.user) },
                                t('view_discussions')
                            ),
                        ])
                    );
                }

                if (ids.indexOf('account_security') !== -1) {
                    cards.push(
                        m('section.FlatRateOwnerDashboard-card', [
                            m('h3.FlatRateOwnerDashboard-cardTitle', t('account_security')),
                            m('p.FlatRateOwnerDashboard-help', t('account_help')),
                            m(
                                'a.Button.Button--primary',
                                { href: accountUrl, rel: 'noopener noreferrer' },
                                t('manage_account')
                            ),
                        ])
                    );
                }

                if (ids.indexOf('notifications') !== -1) {
                    cards.push(
                        m('section.FlatRateOwnerDashboard-card', [
                            m('h3.FlatRateOwnerDashboard-cardTitle', t('notifications')),
                            m('p.FlatRateOwnerDashboard-help', t('notifications_help')),
                            m('a.Button', { href: settingsPath }, t('manage_notifications')),
                        ])
                    );
                }

                return m('div.FlatRateOwnerDashboard', [
                    ids.indexOf('overview') !== -1
                        ? m('section.FlatRateOwnerDashboard-overview', [
                              m('h2.FlatRateOwnerDashboard-heading', t('overview')),
                              m('p.FlatRateOwnerDashboard-help', t('overview_intro')),
                              m('div.FlatRateOwnerDashboard-cards', cards),
                          ])
                        : null,
                    ids.indexOf('contributions') !== -1
                        ? m('section.FlatRateOwnerDashboard-contributions', [
                              m('h2.FlatRateOwnerDashboard-heading', t('contributions')),
                              vnode,
                          ])
                        : vnode,
                ]);
            });
        }
    });

    if (typeof module !== 'undefined') {
        module.exports = {};
    }
})(typeof globalThis !== 'undefined' ? globalThis : this);
