<?php

/**
 * FORUM-UI-REG-002B — Flarum 1.8.19 Conditional handoff for legacy desktop nav.
 *
 * Instantiates the pinned Flarum 1.8.19 Conditional fixture with a stub
 * ExtensionManager so registration is proven as enabled/disabled behavior,
 * not only a source grep.
 */

namespace Illuminate\Contracts\Container {
    interface Container
    {
        public function call($callback, array $parameters = []);
    }
}

namespace Flarum\Extension {
    class Extension
    {
    }

    class ExtensionManager
    {
        public function __construct(private array $enabledIds)
        {
        }

        public function isEnabled(string $extensionId): bool
        {
            return in_array($extensionId, $this->enabledIds, true);
        }
    }
}

namespace Flarum\Extend {
    interface ExtenderInterface
    {
        public function extend(\Illuminate\Contracts\Container\Container $container, ?\Flarum\Extension\Extension $extension = null);
    }
}

namespace {
    require dirname(__DIR__).'/test/fixtures/flarum-1.8.19-Extend-Conditional.php';

    class RecordingFrontend implements \Flarum\Extend\ExtenderInterface
    {
        public function __construct(private string $path)
        {
        }

        public function extend(\Illuminate\Contracts\Container\Container $container, ?\Flarum\Extension\Extension $extension = null): void
        {
            $GLOBALS['REGISTERED_JS'][] = $this->path;
        }
    }

    class FakeContainer implements \Illuminate\Contracts\Container\Container
    {
        public function __construct(private \Flarum\Extension\ExtensionManager $extensions)
        {
        }

        public function call($callback, array $parameters = [])
        {
            if (is_callable($callback)) {
                return $callback($this->extensions);
            }

            return $callback;
        }
    }

    $failures = 0;

    function expect(bool $ok, string $message): void
    {
        global $failures;
        if ($ok) {
            fwrite(STDERR, "[PASS] {$message}\n");
            return;
        }
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}\n");
    }

    $root = dirname(__DIR__);
    $fixture = file_get_contents($root.'/test/fixtures/flarum-1.8.19-Extend-Conditional.php');
    $extend = file_get_contents($root.'/extend.php');

    expect(str_contains($fixture, 'function whenExtensionDisabled'), 'Flarum 1.8.19 Conditional::whenExtensionDisabled exists');
    expect((bool) preg_match('/return ! \$extensions->isEnabled\(\$extensionId\)/', $fixture), 'whenExtensionDisabled is !isEnabled');
    expect(str_contains($extend, "whenExtensionDisabled(\n            'flatrate-forum-navigation'"), 'extend.php gates on flatrate-forum-navigation');
    expect(str_contains($extend, "js/dist/forum-desktop-navigation.js"), 'conditional asset is forum-desktop-navigation.js');
    expect((bool) preg_match("/->js\(__DIR__\.'\/js\/dist\/forum-navigation\.js'\)/", $extend), 'shared contract unconditional');
    expect((bool) preg_match("/->js\(__DIR__\.'\/js\/dist\/forum\.js'\)/", $extend), 'forum.js unconditional');
    expect((bool) preg_match("/->js\(__DIR__\.'\/js\/dist\/mobile-brand-drawer\.js'\)/", $extend), 'mobile drawer unconditional');
    expect((bool) preg_match("/->js\(__DIR__\.'\/js\/dist\/member-display\.js'\)/", $extend), 'member display unconditional');

    $forum = file_get_contents($root.'/js/dist/forum.js');
    $desktop = file_get_contents($root.'/js/dist/forum-desktop-navigation.js');
    expect(!str_contains($forum, 'flatrate-wiki-forum-navigation-sidebar'), 'forum.js no longer has desktop initializer');
    expect(str_contains($desktop, "app.initializers.add('flatrate-wiki-forum-navigation-sidebar'"), 'desktop bundle has initializer');

    function registeredFor(array $enabledIds): array
    {
        $GLOBALS['REGISTERED_JS'] = [];
        $conditional = (new \Flarum\Extend\Conditional())
            ->whenExtensionDisabled(
                'flatrate-forum-navigation',
                [
                    new RecordingFrontend('js/dist/forum-desktop-navigation.js'),
                ]
            );
        $manager = new \Flarum\Extension\ExtensionManager($enabledIds);
        $conditional->extend(new FakeContainer($manager));
        return $GLOBALS['REGISTERED_JS'];
    }

    $disabled = registeredFor([]);
    expect($disabled === ['js/dist/forum-desktop-navigation.js'], 'dedicated nav disabled => desktop bundle registered');

    $enabled = registeredFor(['flatrate-forum-navigation']);
    expect($enabled === [], 'dedicated nav enabled => desktop bundle not registered');

    if ($failures > 0) {
        fwrite(STDERR, "forum-desktop-navigation-handoff.php: {$failures} failure(s)\n");
        exit(1);
    }

    fwrite(STDERR, "forum-desktop-navigation-handoff.php: all checks passed\n");
    exit(0);
}
