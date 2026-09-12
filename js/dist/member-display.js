/*! FlatRate Wiki Community member-number display settings. */
(function () {
    'use strict';

    app.initializers.add('flatrate-wiki-member-display', function () {
        var compat = typeof flarum !== 'undefined' && flarum.core && flarum.core.compat ? flarum.core.compat : {};
        var extendModule = compat['extend'] || compat['flarum/common/extend'] || compat['flarum/extend'];
        var extend = extendModule && (extendModule.extend || extendModule.default || extendModule);
        var SettingsPage = compat['components/SettingsPage'] || compat['flarum/forum/components/SettingsPage'];
        SettingsPage = SettingsPage && (SettingsPage.default || SettingsPage);

        if (typeof extend !== 'function' || !SettingsPage || typeof m !== 'function') {
            return;
        }

        function t(key, params) {
            return app.translator.trans('flatrate-identity.forum.settings.' + key, params || {});
        }

        function currentUser() {
            return app.session && app.session.user ? app.session.user : null;
        }

        function memberNumber(user) {
            var value = user && typeof user.attribute === 'function' ? user.attribute('flatRateMemberNumber') : null;
            var number = Number(value);
            return Number.isInteger(number) && number > 0 ? number : null;
        }

        function memberNickname(user, number) {
            var value = user && typeof user.attribute === 'function' ? user.attribute('flatRateMemberNickname') : null;
            return value || ('tech_#' + number);
        }

        function nicknameMode(user) {
            var value = user && typeof user.attribute === 'function' ? user.attribute('flatRateNicknameMode') : null;
            return value === 'member_number' ? 'member_number' : 'custom';
        }

        function customNickname(user) {
            if (!user || typeof user.attribute !== 'function') {
                return '';
            }
            return String(user.attribute('flatRateCustomNickname') || user.displayName() || '');
        }

        function applyAttributes(user, attributes) {
            if (!user || !attributes) {
                return;
            }
            if (typeof user.pushAttributes === 'function') {
                user.pushAttributes(attributes);
                return;
            }
            Object.keys(attributes).forEach(function (key) {
                if (typeof user.pushData === 'function') {
                    user.pushData({ attributes: attributes });
                }
            });
        }

        function saveDisplay(payload) {
            return app.request({
                method: 'PATCH',
                url: app.forum.attribute('apiUrl') + '/flatrate/member-display',
                body: payload,
            });
        }

        extend(SettingsPage.prototype, 'oninit', function () {
            this.flatRateMemberBusy = false;
            this.flatRateMemberError = false;
            this.flatRateCustomDraft = '';
            var user = currentUser();
            if (user) {
                this.flatRateCustomDraft = customNickname(user);
            }
        });

        extend(SettingsPage.prototype, 'settingsItems', function (items) {
            var user = currentUser();
            var number = memberNumber(user);
            if (!user || !number) {
                return;
            }

            var self = this;
            var mode = nicknameMode(user);
            var reserved = memberNickname(user, number);
            var retained = customNickname(user);
            if (!self.flatRateCustomDraft && retained) {
                self.flatRateCustomDraft = retained;
            }

            items.add(
                'flatrate-member-display',
                m('div.FlatRateMemberDisplay', [
                    m('h3.FlatRateMemberDisplay-heading', t('heading')),
                    m('p.FlatRateMemberDisplay-number', t('member_number', { number: number })),
                    m('p.FlatRateMemberDisplay-help', t('member_number_help')),
                    m('p.FlatRateMemberDisplay-choose', t('choose_appearance')),
                    m(
                        'label.FlatRateMemberDisplay-option',
                        {
                            className: mode === 'member_number' ? 'FlatRateMemberDisplay-option--active' : '',
                        },
                        [
                            m('input', {
                                type: 'radio',
                                name: 'flatrate-member-display-mode',
                                checked: mode === 'member_number',
                                disabled: !!self.flatRateMemberBusy,
                                onchange: function () {
                                    self.flatRateMemberBusy = true;
                                    self.flatRateMemberError = false;
                                    saveDisplay({ mode: 'member_number' })
                                        .then(function (response) {
                                            applyAttributes(user, response && response.data && response.data.attributes);
                                            self.flatRateMemberBusy = false;
                                            if (typeof m.redraw === 'function') {
                                                m.redraw();
                                            }
                                        })
                                        .catch(function () {
                                            self.flatRateMemberBusy = false;
                                            self.flatRateMemberError = true;
                                            if (typeof m.redraw === 'function') {
                                                m.redraw();
                                            }
                                        });
                                },
                            }),
                            m('span.FlatRateMemberDisplay-optionLabel', [
                                m('strong', reserved),
                                m('span.FlatRateMemberDisplay-optionHelp', t('member_identity')),
                            ]),
                        ]
                    ),
                    m(
                        'label.FlatRateMemberDisplay-option',
                        {
                            className: mode === 'custom' ? 'FlatRateMemberDisplay-option--active' : '',
                        },
                        [
                            m('input', {
                                type: 'radio',
                                name: 'flatrate-member-display-mode',
                                checked: mode === 'custom',
                                disabled: !!self.flatRateMemberBusy || !retained,
                                onchange: function () {
                                    self.flatRateMemberBusy = true;
                                    self.flatRateMemberError = false;
                                    saveDisplay({ mode: 'custom' })
                                        .then(function (response) {
                                            applyAttributes(user, response && response.data && response.data.attributes);
                                            self.flatRateMemberBusy = false;
                                            if (typeof m.redraw === 'function') {
                                                m.redraw();
                                            }
                                        })
                                        .catch(function () {
                                            self.flatRateMemberBusy = false;
                                            self.flatRateMemberError = true;
                                            if (typeof m.redraw === 'function') {
                                                m.redraw();
                                            }
                                        });
                                },
                            }),
                            m('span.FlatRateMemberDisplay-optionLabel', [
                                m('strong', t('custom_nickname')),
                                m('span.FlatRateMemberDisplay-optionHelp', retained || ''),
                            ]),
                        ]
                    ),
                    m('div.FlatRateMemberDisplay-customEditor', [
                        m('input.FormControl', {
                            type: 'text',
                            value: self.flatRateCustomDraft,
                            placeholder: t('custom_nickname_placeholder'),
                            disabled: !!self.flatRateMemberBusy,
                            oninput: function (event) {
                                self.flatRateCustomDraft = event.target.value;
                            },
                        }),
                        m(
                            'button.Button.Button--primary',
                            {
                                type: 'button',
                                disabled: !!self.flatRateMemberBusy || !String(self.flatRateCustomDraft || '').trim(),
                                onclick: function () {
                                    self.flatRateMemberBusy = true;
                                    self.flatRateMemberError = false;
                                    saveDisplay({
                                        mode: 'custom',
                                        nickname: String(self.flatRateCustomDraft || '').trim(),
                                    })
                                        .then(function (response) {
                                            applyAttributes(user, response && response.data && response.data.attributes);
                                            self.flatRateCustomDraft = customNickname(user);
                                            self.flatRateMemberBusy = false;
                                            if (typeof m.redraw === 'function') {
                                                m.redraw();
                                            }
                                        })
                                        .catch(function () {
                                            self.flatRateMemberBusy = false;
                                            self.flatRateMemberError = true;
                                            if (typeof m.redraw === 'function') {
                                                m.redraw();
                                            }
                                        });
                                },
                            },
                            t('save')
                        ),
                    ]),
                    m('p.FlatRateMemberDisplay-permanence', t('permanence')),
                    self.flatRateMemberError ? m('p.FlatRateMemberDisplay-error', t('error')) : null,
                ]),
                85
            );
        });
    });
})();
