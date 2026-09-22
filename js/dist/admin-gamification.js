/*! FlatRate Wiki admin-only gamification dashboard. */
(function (root) {
    'use strict';

    var POLL_MS = 45000;
    var WINDOWS = ['today', '7d', '30d', 'current_month', 'all_time'];

    function coreExport(id) {
        if (typeof flarum !== 'undefined' && flarum.reg && typeof flarum.reg.get === 'function') {
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

    function dto(user) {
        if (!user || typeof user.attribute !== 'function') {
            return null;
        }
        var value = user.attribute('flatRateOwnerDashboard');
        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return null;
        }
        if (Number(value.schema_version) !== 1 || !Array.isArray(value.sections)) {
            return null;
        }
        return value;
    }

    function hasGamification(dashboard) {
        return !!(
            dashboard &&
            Array.isArray(dashboard.sections) &&
            dashboard.sections.some(function (section) {
                return section && section.id === 'gamification';
            })
        );
    }

    function t(key, params) {
        if (!app || !app.translator || typeof app.translator.trans !== 'function') {
            return key;
        }
        return app.translator.trans('flatrate-admin-gamify.forum.' + key, params || {});
    }

    function userHref(user) {
        if (app.route && typeof app.route.user === 'function') {
            return app.route.user(user);
        }
        var slug = user && typeof user.slug === 'function' ? user.slug() : '';
        return slug ? '/u/' + slug : '/';
    }

    function gamificationHref(user) {
        return userHref(user) + '/gamification';
    }

    function formatMetric(value) {
        if (value === null || value === undefined) {
            return t('not_measured');
        }
        if (value === 'NOT_MEASURED' || value === 'NOT_IMPLEMENTED' || value === 'NOT_WIRED') {
            return String(value);
        }
        return String(value);
    }

    function sourceLabel(status) {
        if (status === 'ok') {
            return t('source_live');
        }
        if (status === 'partial') {
            return t('source_partial');
        }
        return t('source_unavailable');
    }

    root.FlatRateAdminGamification = {
        dto: dto,
        hasGamification: hasGamification,
        gamificationHref: gamificationHref,
    };

    if (typeof app === 'undefined') {
        if (typeof module !== 'undefined') {
            module.exports = {};
        }
        return;
    }

    app.initializers.add('flatrate-wiki-admin-gamification', function () {
        var extendModule = coreExport('common/extend');
        var extend = extendModule && typeof extendModule.extend === 'function' ? extendModule.extend : null;
        var UserPage = coreExport('forum/components/UserPage');
        var Page = coreExport('common/components/Page');
        var LinkButton = coreExport('common/components/LinkButton');
        var Component = coreExport('common/Component');
        if (Component && Component.default) {
            Component = Component.default;
        }

        if (UserPage && UserPage.prototype && LinkButton && extend) {
            extend(UserPage.prototype, 'navItems', function (items) {
                var dashboard = dto(this.user);
                if (!hasGamification(dashboard)) {
                    return;
                }
                if (!items || typeof items.add !== 'function') {
                    return;
                }
                items.add(
                    'flatrate-gamification',
                    m(
                        LinkButton,
                        {
                            href: gamificationHref(this.user),
                            icon: 'fas fa-chart-line',
                        },
                        t('nav')
                    ),
                    114
                );
            });
        }

        if (Page && Page.default) {
            Page = Page.default;
        }
        if (UserPage && UserPage.default) {
            UserPage = UserPage.default;
        }
        var BasePage = Page || Component;
        if (!BasePage) {
            if (typeof module !== 'undefined') {
                module.exports = {};
            }
            return;
        }

        function initAdminGamificationState(page) {
            page.window = '30d';
            page.tab = 'overview';
            page.loading = true;
            page.error = null;
            page.payload = null;
            page.pollTimer = null;
            page.lastUpdated = null;
            page.testSessionId = null;
            page.testSession = null;
            page.testShare = null;
            page.testLaunch = null;
            page.labBusy = false;
            page.labError = null;
            page.labTargetType = 'discussion';
            page.labTargetPath = '/d/1';
            page.labShareIntent = 'inform';
        }

        var LAB_TARGET_TYPES = [
            'discussion',
            'reply',
            'job_report',
            'operation',
            'brand_board',
            'public_profile',
            'knowledge_prompt',
        ];
        var LAB_SHARE_INTENTS = ['inform', 'ask_technician', 'invite_brand'];

        // Flarum 1.8 Page is an ES6 class (no Component.extend). Prototype
        // subclasses break Mithril lifecycle (initAttrs / onbeforeupdate).
        class AdminGamificationPage extends BasePage {
            oninit(vnode) {
                super.oninit(vnode);
                initAdminGamificationState(this);
                this.ensureOwnProfile();
                this.load();
                this.startPolling();
            }

            onremove(vnode) {
                this.stopPolling();
                if (typeof super.onremove === 'function') {
                    super.onremove(vnode);
                }
            }

            ensureOwnProfile() {

                        var sessionUser = app.session && app.session.user;
                        if (!sessionUser) {
                            return;
                        }
                        var routeUser =
                            this.attrs && this.attrs.user
                                ? this.attrs.user
                                : app.current && app.current.data && app.current.data.user
                                  ? app.current.data.user
                                  : null;
                        if (routeUser && Number(routeUser.id()) !== Number(sessionUser.id())) {
                            m.route.set(gamificationHref(sessionUser));
                        }
        
            }

            apiPath() {

                        if (this.tab === 'quality') {
                            return '/api/flatrate-admin/gamification/quality';
                        }
                        if (this.tab === 'sharing') {
                            return '/api/flatrate-admin/gamification/sharing';
                        }
                        if (this.tab === 'referrals') {
                            return '/api/flatrate-admin/gamification/referrals';
                        }
                        return '/api/flatrate-admin/gamification/overview';
        
            }

            load() {

                        var self = this;
                        this.loading = true;
                        this.error = null;
                        var url =
                            this.apiPath() +
                            '?window=' +
                            encodeURIComponent(this.window) +
                            '&limit=50';
                        return app
                            .request({
                                method: 'GET',
                                url: url,
                            })
                            .then(function (response) {
                                self.payload = response;
                                self.lastUpdated = new Date().toISOString();
                                self.loading = false;
                                m.redraw();
                            })
                            .catch(function (error) {
                                self.payload = null;
                                self.error = (error && error.status) || 'error';
                                self.loading = false;
                                m.redraw();
                            });
        
            }

            startPolling() {

                        var self = this;
                        this.stopPolling();
                        this.pollTimer = setInterval(function () {
                            if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
                                return;
                            }
                            self.load();
                        }, POLL_MS);
        
            }

            stopPolling() {

                        if (this.pollTimer) {
                            clearInterval(this.pollTimer);
                            this.pollTimer = null;
                        }
        
            }

            setTab(tab) {

                        this.tab = tab;
                        this.load();
        
            }

            setWindow(windowId) {

                        this.window = windowId;
                        this.load();
        
            }

            labRequest(path, body) {

                        var self = this;
                        this.labBusy = true;
                        this.labError = null;
                        return app
                            .request({
                                method: 'POST',
                                url: '/api/flatrate-admin/gamification/test-lab/' + path,
                                body: body || {},
                            })
                            .then(function (response) {
                                self.labBusy = false;
                                m.redraw();
                                return response;
                            })
                            .catch(function (error) {
                                self.labBusy = false;
                                self.labError =
                                    (error && error.response && error.response.error) ||
                                    (error && error.status) ||
                                    'error';
                                m.redraw();
                                throw error;
                            });
        
            }

            applyTestSession(response) {

                        var session =
                            response && response.test_session
                                ? response.test_session
                                : null;
                        this.testSession = session;
                        this.testSessionId =
                            session && session.test_session_id ? session.test_session_id : null;
        
            }

            startTestSession() {

                        var self = this;
                        return this.labRequest('session/start', {}).then(function (response) {
                            self.applyTestSession(response);
                            self.testShare = null;
                            self.testLaunch = null;
                            m.redraw();
                        });
        
            }

            endTestSession() {

                        var self = this;
                        if (!this.testSessionId) {
                            return Promise.resolve();
                        }
                        return this.labRequest('session/end', {
                            test_session_id: this.testSessionId,
                        }).then(function (response) {
                            self.applyTestSession(response);
                            m.redraw();
                        });
        
            }

            createTestShare() {

                        var self = this;
                        if (!this.testSessionId) {
                            this.labError = 'need_session';
                            m.redraw();
                            return Promise.resolve();
                        }
                        return this.labRequest('share/create', {
                            test_session_id: this.testSessionId,
                            target_type: this.labTargetType,
                            target_path: this.labTargetPath,
                            share_intent: this.labShareIntent,
                        }).then(function (response) {
                            self.testShare = response && response.share ? response.share : null;
                            self.testLaunch = null;
                            m.redraw();
                        });
        
            }

            openRecipientTest() {

                        var self = this;
                        if (!this.testSessionId || !this.testShare || !this.testShare.share_code) {
                            this.labError = 'need_share';
                            m.redraw();
                            return Promise.resolve();
                        }
                        return this.labRequest('launch/create', {
                            test_session_id: this.testSessionId,
                            share_code: this.testShare.share_code,
                        }).then(function (response) {
                            self.testLaunch = response || null;
                            var launchUrl = response && response.launch_url ? response.launch_url : null;
                            if (launchUrl && typeof window !== 'undefined' && window.open) {
                                window.open(launchUrl, '_blank', 'noopener,noreferrer');
                            }
                            m.redraw();
                        });
        
            }

            copySharePath() {

                        var path =
                            this.testShare && this.testShare.share_path
                                ? this.testShare.share_path
                                : '';
                        if (!path) {
                            return;
                        }
                        if (
                            typeof navigator !== 'undefined' &&
                            navigator.clipboard &&
                            typeof navigator.clipboard.writeText === 'function'
                        ) {
                            navigator.clipboard.writeText(path);
                        }
        
            }

            renderTestLab() {

                        var self = this;
                        var session = this.testSession || {};
                        var state = session.state || 'inactive';
                        var share = this.testShare;
                        var labErrorLabel =
                            this.labError === 'need_session'
                                ? t('test_lab_need_session')
                                : this.labError === 'need_share'
                                  ? t('test_lab_need_share')
                                  : this.labError
                                    ? t('test_lab_error')
                                    : null;

                        return m('div.FlatRateAdminGamify-panel', [
                            m('h3', t('test_lab')),
                            this.labBusy ? m('p.FlatRateAdminGamify-help', t('test_lab_busy')) : null,
                            labErrorLabel ? m('p.FlatRateAdminGamify-help', labErrorLabel) : null,
                            m('div.FlatRateAdminGamify-labSection', [
                                m('h4', t('test_lab_session')),
                                m('ul.FlatRateAdminGamify-metrics', [
                                    m('li', t('test_lab_state') + ': ' + state),
                                    m(
                                        'li',
                                        t('test_lab_started') +
                                            ': ' +
                                            formatMetric(session.started_at || null)
                                    ),
                                    m(
                                        'li',
                                        t('test_lab_expires') +
                                            ': ' +
                                            formatMetric(session.expires_at || null)
                                    ),
                                    m(
                                        'li',
                                        t('test_lab_session_id') +
                                            ': ' +
                                            formatMetric(session.test_session_id || null)
                                    ),
                                ]),
                                m('div.FlatRateAdminGamify-controls', [
                                    m(
                                        'button.Button.Button--primary',
                                        {
                                            type: 'button',
                                            disabled: !!self.labBusy,
                                            onclick: function () {
                                                self.startTestSession();
                                            },
                                        },
                                        t('test_lab_start')
                                    ),
                                    m(
                                        'button.Button',
                                        {
                                            type: 'button',
                                            disabled: !!self.labBusy || !self.testSessionId,
                                            onclick: function () {
                                                self.endTestSession();
                                            },
                                        },
                                        t('test_lab_end')
                                    ),
                                ]),
                            ]),
                            m('div.FlatRateAdminGamify-labSection', [
                                m('h4', t('test_lab_create_share')),
                                m('div.FlatRateAdminGamify-controls', [
                                    m('label', [
                                        t('test_lab_target_type'),
                                        m(
                                            'select',
                                            {
                                                value: self.labTargetType,
                                                onchange: function (event) {
                                                    self.labTargetType = event.target.value;
                                                },
                                            },
                                            LAB_TARGET_TYPES.map(function (type) {
                                                return m('option', { value: type }, type);
                                            })
                                        ),
                                    ]),
                                    m('label', [
                                        t('test_lab_target_path'),
                                        m('input.FormControl', {
                                            type: 'text',
                                            value: self.labTargetPath,
                                            oninput: function (event) {
                                                self.labTargetPath = event.target.value;
                                            },
                                        }),
                                    ]),
                                    m('label', [
                                        t('test_lab_share_intent'),
                                        m(
                                            'select',
                                            {
                                                value: self.labShareIntent,
                                                onchange: function (event) {
                                                    self.labShareIntent = event.target.value;
                                                },
                                            },
                                            LAB_SHARE_INTENTS.map(function (intent) {
                                                return m('option', { value: intent }, intent);
                                            })
                                        ),
                                    ]),
                                    m(
                                        'button.Button.Button--primary',
                                        {
                                            type: 'button',
                                            disabled: !!self.labBusy || !self.testSessionId,
                                            onclick: function () {
                                                self.createTestShare();
                                            },
                                        },
                                        t('test_lab_create')
                                    ),
                                ]),
                                share
                                    ? m('ul.FlatRateAdminGamify-metrics', [
                                          m(
                                              'li',
                                              t('test_lab_share_code') +
                                                  ': ' +
                                                  formatMetric(share.share_code)
                                          ),
                                          m('li', 'path: ' + formatMetric(share.share_path)),
                                          m('li', 'intent: ' + formatMetric(share.share_intent)),
                                      ])
                                    : null,
                                share
                                    ? m('div.FlatRateAdminGamify-controls', [
                                          m(
                                              'button.Button',
                                              {
                                                  type: 'button',
                                                  onclick: function () {
                                                      self.copySharePath();
                                                  },
                                              },
                                              t('test_lab_copy_path')
                                          ),
                                          m(
                                              'button.Button.Button--primary',
                                              {
                                                  type: 'button',
                                                  disabled: !!self.labBusy,
                                                  onclick: function () {
                                                      self.openRecipientTest();
                                                  },
                                              },
                                              t('test_lab_open_recipient')
                                          ),
                                      ])
                                    : null,
                            ]),
                        ]);
        
            }

            renderSources() {

                        var sources = (this.payload && this.payload.sources) || {};
                        return m('div.FlatRateAdminGamify-sources', [
                            m('span', t('votes_label') + ': ' + sourceLabel((sources.quality_signals || {}).status)),
                            m('span', t('sharing_label') + ': ' + sourceLabel((sources.sharing || {}).status)),
                            m('span', t('referrals_label') + ': ' + sourceLabel((sources.referrals || {}).status)),
                            m(
                                'span',
                                t('activity_label') +
                                    ': ' +
                                    sourceLabel((sources.activity_funnel || { status: 'partial' }).status)
                            ),
                        ]);
        
            }

            renderOverview() {

                        var data = (this.payload && this.payload.data) || {};
                        var quality = data.quality_signals || {};
                        var sharing = data.sharing && data.sharing.summary ? data.sharing.summary : {};
                        var referrals = data.referrals && data.referrals.summary ? data.referrals.summary : {};
                        var coverage = this.payload && this.payload.coverage;

                        return m('div.FlatRateAdminGamify-panel', [
                            m('h3', t('overview')),
                            m('ul.FlatRateAdminGamify-metrics', [
                                m('li', t('rated_contributors') + ': ' + formatMetric(quality.rated_contributors)),
                                m('li', t('active_ballots') + ': ' + formatMetric(quality.total_active_ballots)),
                                m('li', t('tracked_shares') + ': ' + formatMetric(sharing.share_links_created)),
                                m(
                                    'li',
                                    t('signed_out_landings') + ': ' + formatMetric(sharing.signed_out_tracked_landings)
                                ),
                                m(
                                    'li',
                                    t('verified_referrals') +
                                        ': ' +
                                        formatMetric(referrals.verified_referral_attributions)
                                ),
                                m(
                                    'li',
                                    t('signup_shadow_points') +
                                        ': ' +
                                        formatMetric(referrals.signup_shadow_points_sum)
                                ),
                            ]),
                            coverage
                                ? m('p.FlatRateAdminGamify-help', [
                                      coverage.label || t('shadow_points'),
                                      ' — ',
                                      t('score_version') + ': ' + (coverage.score_version || 'points_scoring_v1'),
                                  ])
                                : null,
                        ]);
        
            }

            renderQuality() {

                        var data = (this.payload && this.payload.data) || {};
                        var board = data.leaderboard || {};
                        var rows = board.rows || [];
                        return m('div.FlatRateAdminGamify-panel', [
                            m('h3', t('quality_signals')),
                            board.time_window_support === false
                                ? m('p.FlatRateAdminGamify-help', t('quality_window_note'))
                                : null,
                            m(
                                'table.FlatRateAdminGamify-table',
                                [
                                    m('thead', [
                                        m('tr', [
                                            m('th', t('rank')),
                                            m('th', t('technician')),
                                            m('th', t('rated_contributions')),
                                            m('th', t('positive_ballots')),
                                            m('th', t('negative_ballots')),
                                            m('th', t('distinct_voters')),
                                            m('th', t('sample_size')),
                                            m('th', t('positive_ratio')),
                                        ]),
                                    ]),
                                    m(
                                        'tbody',
                                        rows.map(function (row) {
                                            return m('tr', [
                                                m('td', String(row.rank)),
                                                m('td', row.technician_nickname || 'Member #' + row.member_number),
                                                m('td', String(row.rated_contributions)),
                                                m('td', String(row.positive_ballots)),
                                                m('td', String(row.negative_ballots)),
                                                m('td', String(row.distinct_voters)),
                                                m('td', String(row.sample_size)),
                                                m('td', row.positive_ratio == null ? '—' : String(row.positive_ratio)),
                                            ]);
                                        })
                                    ),
                                ]
                            ),
                        ]);
        
            }

            renderSharing() {

                        var data = (this.payload && this.payload.data) || {};
                        var summary = data.summary || {};
                        var rows = (data.recent && data.recent.rows) || [];
                        return m('div.FlatRateAdminGamify-panel', [
                            m('h3', t('sharing')),
                            this.payload && this.payload.sources && this.payload.sources.sharing &&
                            this.payload.sources.sharing.status === 'unavailable'
                                ? m('p.FlatRateAdminGamify-help', t('source_unavailable'))
                                : null,
                            m('ul.FlatRateAdminGamify-metrics', [
                                m('li', t('tracked_shares') + ': ' + formatMetric(summary.share_links_created)),
                                m(
                                    'li',
                                    t('signed_out_landings') + ': ' + formatMetric(summary.signed_out_tracked_landings)
                                ),
                            ]),
                            m(
                                'table.FlatRateAdminGamify-table',
                                [
                                    m('thead', [
                                        m('tr', [
                                            m('th', t('share_code')),
                                            m('th', t('technician')),
                                            m('th', t('intent')),
                                            m('th', t('target')),
                                            m('th', t('verified_referral')),
                                        ]),
                                    ]),
                                    m(
                                        'tbody',
                                        rows.map(function (row) {
                                            return m('tr', [
                                                m('td', row.share_code),
                                                m(
                                                    'td',
                                                    row.sender_identity_state === 'linked'
                                                        ? 'Member #' + row.sender_member_number
                                                        : t('unlinked')
                                                ),
                                                m('td', row.share_intent),
                                                m('td', row.target_path),
                                                m('td', row.verified_referral ? t('yes') : t('no')),
                                            ]);
                                        })
                                    ),
                                ]
                            ),
                        ]);
        
            }

            renderReferrals() {

                        var data = (this.payload && this.payload.data) || {};
                        var summary = data.summary || {};
                        var rows = (data.recent && data.recent.rows) || [];
                        return m('div.FlatRateAdminGamify-panel', [
                            m('h3', t('referrals')),
                            m('ul.FlatRateAdminGamify-metrics', [
                                m(
                                    'li',
                                    t('verified_referrals') +
                                        ': ' +
                                        formatMetric(summary.verified_referral_attributions)
                                ),
                                m('li', t('qualified_at_count') + ': ' + formatMetric(summary.qualified_at_count)),
                                m(
                                    'li',
                                    t('signup_shadow_points') +
                                        ': ' +
                                        formatMetric(summary.signup_shadow_points_sum)
                                ),
                            ]),
                            m(
                                'table.FlatRateAdminGamify-table',
                                [
                                    m('thead', [
                                        m('tr', [
                                            m('th', t('inviter')),
                                            m('th', t('invitee')),
                                            m('th', t('shadow_points')),
                                            m('th', t('score_version')),
                                        ]),
                                    ]),
                                    m(
                                        'tbody',
                                        rows.map(function (row) {
                                            return m('tr', [
                                                m(
                                                    'td',
                                                    row.inviter_identity_state === 'linked'
                                                        ? 'Member #' + row.inviter_member_number
                                                        : t('unlinked')
                                                ),
                                                m(
                                                    'td',
                                                    row.invitee_identity_state === 'linked'
                                                        ? 'Member #' + row.invitee_member_number
                                                        : t('unlinked')
                                                ),
                                                m('td', String(row.shadow_points)),
                                                m('td', row.score_version),
                                            ]);
                                        })
                                    ),
                                ]
                            ),
                        ]);
        
            }

            view() {

                        var self = this;
                        var sessionUser = app.session && app.session.user;
                        var dashboard = dto(sessionUser);
                        if (!hasGamification(dashboard)) {
                            return m('div.FlatRateAdminGamify', m('p', t('admin_only')));
                        }

                        return m('div.FlatRateAdminGamify', [
                            m('div.FlatRateAdminGamify-header', [
                                m('h2.FlatRateAdminGamify-heading', t('nav')),
                                m('p.FlatRateAdminGamify-help', t('intro')),
                                this.lastUpdated
                                    ? m('p.FlatRateAdminGamify-freshness', t('last_updated', { time: this.lastUpdated }))
                                    : null,
                                this.renderSources(),
                            ]),
                            m(
                                'div.FlatRateAdminGamify-controls',
                                [
                                    m(
                                        'select',
                                        {
                                            value: this.window,
                                            onchange: function (event) {
                                                self.setWindow(event.target.value);
                                            },
                                        },
                                        WINDOWS.map(function (windowId) {
                                            return m('option', { value: windowId }, t('window_' + windowId));
                                        })
                                    ),
                                    m(
                                        'button.Button',
                                        {
                                            type: 'button',
                                            onclick: function () {
                                                self.load();
                                            },
                                        },
                                        t('refresh')
                                    ),
                                ]
                            ),
                            m('div.FlatRateAdminGamify-tabs', [
                                ['overview', 'quality', 'sharing', 'referrals', 'lab'].map(function (tab) {
                                    return m(
                                        'button.Button' + (self.tab === tab ? '.Button--primary' : ''),
                                        {
                                            type: 'button',
                                            onclick: function () {
                                                if (tab === 'lab') {
                                                    self.tab = 'lab';
                                                    m.redraw();
                                                    return;
                                                }
                                                self.setTab(tab);
                                            },
                                        },
                                        t(tab === 'quality' ? 'quality_signals' : tab === 'lab' ? 'test_lab' : tab)
                                    );
                                }),
                            ]),
                            this.loading ? m('p', t('loading')) : null,
                            this.error ? m('p.FlatRateAdminGamify-help', t('load_error')) : null,
                            !this.loading && this.tab === 'overview' ? this.renderOverview() : null,
                            !this.loading && this.tab === 'quality' ? this.renderQuality() : null,
                            !this.loading && this.tab === 'sharing' ? this.renderSharing() : null,
                            !this.loading && this.tab === 'referrals' ? this.renderReferrals() : null,
                            this.tab === 'lab' ? this.renderTestLab() : null,
                        ]);
        
            }
        }

        if (app.routes) {
            app.routes.userFlatRateGamification = {
                path: '/u/:username/gamification',
                component: AdminGamificationPage,
            };
        }
    });

    if (typeof module !== 'undefined') {
        module.exports = {};
    }
})(typeof globalThis !== 'undefined' ? globalThis : this);
