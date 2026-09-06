/*! FlatRate Wiki shared forum navigation presentation contract. */
(function () {
    'use strict';

    function deepFreeze(value) {
        if (!value || typeof value !== 'object' || Object.isFrozen(value)) {
            return value;
        }

        Object.freeze(value);
        Object.keys(value).forEach(function (key) {
            deepFreeze(value[key]);
        });
        return value;
    }

    function board(boardKey, displayName, slug, children) {
        return {
            boardKey: boardKey,
            displayName: displayName,
            slug: slug || boardKey,
            children: children || []
        };
    }

    var BRAND_CHILDREN = [
        board('acura', 'Acura'),
        board('alfa-romeo', 'Alfa Romeo', 'alpha-romeo'),
        board('audi', 'Audi'),
        board('bentley', 'Bentley'),
        board('bmw', 'BMW'),
        board('cdjr', 'CDJR', 'cdjr', [
            board('chrysler', 'Chrysler'),
            board('dodge', 'Dodge'),
            board('jeep', 'Jeep'),
            board('ram', 'Ram')
        ]),
        board('ferrari', 'Ferrari'),
        board('ford', 'Ford'),
        board('genesis', 'Genesis', 'genisis'),
        board('gm', 'GM', 'gm', [
            board('buick', 'Buick'),
            board('cadillac', 'Cadillac'),
            board('chevrolet', 'Chevrolet'),
            board('gmc', 'GMC')
        ]),
        board('honda', 'Honda'),
        board('hyundai', 'Hyundai'),
        board('infiniti', 'Infiniti'),
        board('jaguar', 'Jaguar'),
        board('kia', 'Kia'),
        board('lamborghini', 'Lamborghini'),
        board('lexus', 'Lexus'),
        board('lincoln', 'Lincoln'),
        board('maserati', 'Maserati'),
        board('mazda', 'Mazda'),
        board('mclaren', 'McLaren'),
        board('mercedes-benz', 'Mercedes-Benz'),
        board('mini', 'MINI'),
        board('mitsubishi', 'Mitsubishi'),
        board('nissan', 'Nissan'),
        board('other-makes', 'Other Makes'),
        board('porsche', 'Porsche'),
        board('rivian', 'Rivian'),
        board('subaru', 'Subaru'),
        board('tesla', 'Tesla'),
        board('toyota', 'Toyota'),
        board('volkswagen', 'Volkswagen'),
        board('volvo', 'Volvo')
    ];

    var GROUPS = deepFreeze([
        {
            id: 'community',
            label: 'Community',
            order: 0,
            emptyPolicy: 'show',
            children: [
                board('start-here', 'Start Here'),
                board('general-shop-discussion', 'General Shop Discussion')
            ]
        },
        {
            id: 'technician-topics',
            label: 'Technician Topics',
            order: 1,
            emptyPolicy: 'hide-until-nonempty',
            children: []
        },
        {
            id: 'brands',
            label: 'Brands',
            order: 2,
            emptyPolicy: 'show',
            children: BRAND_CHILDREN
        }
    ]);

    function cloneNode(node) {
        return {
            boardKey: node.boardKey,
            displayName: node.displayName,
            slug: node.slug,
            children: (node.children || []).map(cloneNode)
        };
    }

    function cloneGroup(group) {
        return {
            id: group.id,
            label: group.label,
            order: group.order,
            emptyPolicy: group.emptyPolicy,
            children: (group.children || []).map(cloneNode)
        };
    }

    function tagBySlug(tags) {
        var map = {};
        tags.forEach(function (tag) {
            if (tag && typeof tag.slug === 'function') {
                map[String(tag.slug()).toLowerCase()] = tag;
            }
        });
        return map;
    }

    function resolveNode(bySlug, node) {
        var tag = bySlug[String(node.slug).toLowerCase()];
        if (!tag) {
            return null;
        }

        var children = (node.children || [])
            .map(function (child) {
                return resolveNode(bySlug, child);
            })
            .filter(Boolean);

        return {
            boardKey: node.boardKey,
            displayName: node.displayName,
            slug: node.slug,
            tag: tag,
            route: '/t/' + node.slug,
            children: children
        };
    }

    function shouldRenderGroup(group, children) {
        if (group.emptyPolicy === 'hide-until-nonempty') {
            return children.length > 0;
        }

        // emptyPolicy "show": still omit empty placeholder headings.
        return children.length > 0;
    }

    function resolveGroups(app, groups) {
        if (!app || !app.store || typeof app.store.all !== 'function') {
            return [];
        }

        var tags = app.store.all('tags');
        if (!Array.isArray(tags)) {
            return [];
        }

        var bySlug = tagBySlug(tags);
        var source = Array.isArray(groups) ? groups : GROUPS;

        return source
            .slice()
            .sort(function (left, right) {
                return Number(left.order) - Number(right.order);
            })
            .map(function (group) {
                var children = (group.children || [])
                    .map(function (node) {
                        return resolveNode(bySlug, node);
                    })
                    .filter(Boolean);

                if (!shouldRenderGroup(group, children)) {
                    return null;
                }

                return {
                    id: group.id,
                    label: group.label,
                    order: group.order,
                    emptyPolicy: group.emptyPolicy,
                    children: children
                };
            })
            .filter(Boolean);
    }

    function resolve(app, groups) {
        return resolveGroups(app, groups);
    }

    function getGroup(id) {
        for (var index = 0; index < GROUPS.length; index += 1) {
            if (GROUPS[index].id === id) {
                return GROUPS[index];
            }
        }
        return null;
    }

    var api = deepFreeze({
        groups: GROUPS,
        getGroup: getGroup,
        cloneGroup: cloneGroup,
        resolve: resolve,
        resolveGroups: resolveGroups
    });

    var root = typeof globalThis !== 'undefined' ? globalThis : window;
    root.FlatRateForumNavigation = api;

    if (typeof module !== 'undefined') {
        module.exports = api;
    }
})();
