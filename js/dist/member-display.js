/*! FlatRate Wiki Community member-number display settings. */
(function (root) {
    'use strict';

    var FlatRateMemberDisplayState = {
        retainedCustomNickname: function (user) {
            if (!user || typeof user.attribute !== 'function') {
                return '';
            }
            var value = user.attribute('flatRateCustomNickname');
            if (value == null || value === '') {
                return '';
            }
            return String(value);
        },
        nicknameMode: function (user) {
            var value = user && typeof user.attribute === 'function' ? user.attribute('flatRateNicknameMode') : null;
            return value === 'custom' ? 'custom' : 'member_number';
        },
        customRadioEnabled: function (user, busy) {
            return !busy && this.retainedCustomNickname(user) !== '';
        },
        initialCustomDraft: function (user) {
            return this.retainedCustomNickname(user);
        },
    };

    root.FlatRateMemberDisplayState = FlatRateMemberDisplayState;

    if (typeof app === 'undefined') {
        return;
    }

    app.initializers.add('flatrate-wiki-member-display', function initializeMemberDisplay(loadedSettingsPage) {
        // Flarum 1.8.19 webpack externals resolve through flarum.core.compat
        // using keys such as 'common/extend'. flarum.reg.get exists only on
        // later runtimes. Prefer the registry when present, then the 1.8 map.
        // Never dereference .extend without a module check.
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

        // Initializer callbacks receive the application, whereas the registry
        // callback receives the route module. Only accept an actual class here.
        var settingsModule = typeof loadedSettingsPage === 'function' ||
            (loadedSettingsPage && typeof loadedSettingsPage.default === 'function')
            ? loadedSettingsPage : coreExport('forum/components/SettingsPage');
        var SettingsPage = settingsModule && (settingsModule.default || settingsModule);

        // Flarum 2 loads SettingsPage as a route chunk. Register before its
        // first instance is constructed instead of silently skipping the UI.
        if (!SettingsPage && typeof flarum !== 'undefined' && flarum.reg && typeof flarum.reg.onLoad === 'function') {
            flarum.reg.onLoad('core', 'forum/components/SettingsPage', function (loaded) {
                initializeMemberDisplay(loaded);
            });
            return;
        }

        // Flarum 1.8 extend() mutates an object method. It does not accept a
        // module-path string (that API is not present in core 1.8.19).
        if (typeof extend !== 'function' || !SettingsPage || !SettingsPage.prototype || typeof m !== 'function') {
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

        function applyAttributes(user, attributes) {
            if (!user || !attributes) {
                return;
            }
            if (typeof user.pushAttributes === 'function') {
                user.pushAttributes(attributes);
                return;
            }
            if (typeof user.pushData === 'function') {
                user.pushData({ attributes: attributes });
            }
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
            this.flatRateCustomDraft = FlatRateMemberDisplayState.initialCustomDraft(currentUser());
        });

        extend(SettingsPage.prototype, 'settingsItems', function (items) {
            var user = currentUser();
            var number = memberNumber(user);
            if (!user || !number) {
                return;
            }

            var self = this;
            var mode = FlatRateMemberDisplayState.nicknameMode(user);
            var reserved = memberNickname(user, number);
            var retained = FlatRateMemberDisplayState.retainedCustomNickname(user);
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
                                disabled: !FlatRateMemberDisplayState.customRadioEnabled(user, !!self.flatRateMemberBusy),
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
                                            self.flatRateCustomDraft = FlatRateMemberDisplayState.retainedCustomNickname(user);
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

    // Flarum 1.8 Frontend::js() wraps each file as `var module={}` and then
    // assigns flarum.extensions[id]=module.exports. The last registered file
    // wins. Leaving exports undefined crashes Application.tsx:344.
    if (typeof module !== 'undefined') {
        module.exports = {};
    }
})(typeof globalThis !== 'undefined' ? globalThis : this);
