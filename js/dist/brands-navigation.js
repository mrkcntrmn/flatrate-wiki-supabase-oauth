/*! FlatRate Wiki shared Brands navigation presentation contract. */
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

    var BRAND_NAVIGATION_TREE = deepFreeze([
        { name: 'Acura', slug: 'acura' },
        { name: 'Alfa Romeo', slug: 'alpha-romeo' },
        { name: 'Audi', slug: 'audi' },
        { name: 'Bentley', slug: 'bentley' },
        { name: 'BMW', slug: 'bmw' },
        {
            name: 'CDJR',
            slug: 'cdjr',
            children: [
                { name: 'Chrysler', slug: 'chrysler' },
                { name: 'Dodge', slug: 'dodge' },
                { name: 'Jeep', slug: 'jeep' },
                { name: 'Ram', slug: 'ram' }
            ]
        },
        { name: 'Ferrari', slug: 'ferrari' },
        { name: 'Ford', slug: 'ford' },
        { name: 'Genesis', slug: 'genisis' },
        {
            name: 'GM',
            slug: 'gm',
            children: [
                { name: 'Buick', slug: 'buick' },
                { name: 'Cadillac', slug: 'cadillac' },
                { name: 'Chevrolet', slug: 'chevrolet' },
                { name: 'GMC', slug: 'gmc' }
            ]
        },
        { name: 'Honda', slug: 'honda' },
        { name: 'Hyundai', slug: 'hyundai' },
        { name: 'Infiniti', slug: 'infiniti' },
        { name: 'Jaguar', slug: 'jaguar' },
        { name: 'Kia', slug: 'kia' },
        { name: 'Lamborghini', slug: 'lamborghini' },
        { name: 'Lexus', slug: 'lexus' },
        { name: 'Lincoln', slug: 'lincoln' },
        { name: 'Maserati', slug: 'maserati' },
        { name: 'Mazda', slug: 'mazda' },
        { name: 'McLaren', slug: 'mclaren' },
        { name: 'Mercedes-Benz', slug: 'mercedes-benz' },
        { name: 'MINI', slug: 'mini' },
        { name: 'Mitsubishi', slug: 'mitsubishi' },
        { name: 'Nissan', slug: 'nissan' },
        { name: 'Other Makes', slug: 'other-makes' },
        { name: 'Porsche', slug: 'porsche' },
        { name: 'Rivian', slug: 'rivian' },
        { name: 'Subaru', slug: 'subaru' },
        { name: 'Tesla', slug: 'tesla' },
        { name: 'Toyota', slug: 'toyota' },
        { name: 'Volkswagen', slug: 'volkswagen' },
        { name: 'Volvo', slug: 'volvo' }
    ]);

    function tagBySlug(tags) {
        var map = {};
        tags.forEach(function (tag) {
            if (tag && typeof tag.slug === 'function') {
                map[String(tag.slug()).toLowerCase()] = tag;
            }
        });
        return map;
    }

    function resolve(app) {
        if (!app || !app.store || typeof app.store.all !== 'function') {
            return [];
        }

        var tags = app.store.all('tags');
        if (!Array.isArray(tags)) {
            return [];
        }

        var bySlug = tagBySlug(tags);

        function resolveNode(node) {
            var tag = bySlug[node.slug];
            if (!tag) {
                return null;
            }
            var children = (node.children || []).map(resolveNode);
            if (children.some(function (child) { return !child; })) {
                return null;
            }
            return { name: node.name, slug: node.slug, tag: tag, children: children };
        }

        var resolved = BRAND_NAVIGATION_TREE.map(resolveNode);
        return resolved.some(function (node) { return !node; }) ? [] : resolved;
    }

    var api = deepFreeze({
        tree: BRAND_NAVIGATION_TREE,
        resolve: resolve
    });
    var root = typeof globalThis !== 'undefined' ? globalThis : window;
    root.FlatRateBrandsNavigation = api;

    if (typeof module !== 'undefined') {
        module.exports = api;
    }
})();
