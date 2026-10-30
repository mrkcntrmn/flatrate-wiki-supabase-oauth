<?php
/** Execute production projection/driver classes without a database. */
namespace Flarum\Database { class AbstractModel {} }
namespace Flarum\User {
    class User extends \Flarum\Database\AbstractModel {
        public function __construct(public int $id = 0, public string $username = '', public string $nickname = '', private bool $guest = false) {}
        public function isGuest(): bool { return $this->guest; }
    }
    class UserRepository {
        public array $lookups = [];
        public function __construct(public array $users) {}
        public function findOrFail($id, User $actor): User {
            $this->lookups[] = [$id, $actor];
            return $this->users[$id] ?? throw new \RuntimeException('not found');
        }
    }
}
namespace Flarum\Http {
    interface SlugDriverInterface {
        public function toSlug(\Flarum\Database\AbstractModel $instance): string;
        public function fromSlug(string $slug, \Flarum\User\User $actor): \Flarum\Database\AbstractModel;
    }
}
namespace Flarum\User\DisplayName { interface DriverInterface { public function displayName(\Flarum\User\User $user): string; } }
namespace {
    foreach (['NeutralIdentity', 'ViewerIdentityContext', 'GuestIdentityProjection', 'GuestAwareDisplayNameDriver', 'GuestAwareUserSlugDriver'] as $file) require __DIR__.'/../src/Identity/'.$file.'.php';
    $checks = 0;
    function check($ok, $name): void { global $checks; if (!$ok) throw new \RuntimeException($name); $checks++; }
    $ctx = new \FlatRate\SupabaseOAuth\Identity\ViewerIdentityContext();
    $projection = new \FlatRate\SupabaseOAuth\Identity\GuestIdentityProjection($ctx);
    $legacy = new \Flarum\User\User(72, 'LegacyRealName', 'PrivateNickname');
    $neutral = new \Flarum\User\User(73, 'tech_a84f19c2', 'OtherNickname');
    $guest = new \Flarum\User\User(0, '', '', true);
    $repo = new \Flarum\User\UserRepository([72 => $legacy, 73 => $neutral]);
    $inner = new class implements \Flarum\User\DisplayName\DriverInterface {
        public function displayName(\Flarum\User\User $u): string { return $u->nickname; }
    };
    $driver = new \FlatRate\SupabaseOAuth\Identity\GuestAwareDisplayNameDriver($inner, $ctx, $projection);
    $innerSlug = new class($repo) implements \Flarum\Http\SlugDriverInterface {
        public function __construct(private $repo) {}
        public function toSlug(\Flarum\Database\AbstractModel $u): string { return $u->username; }
        public function fromSlug(string $slug, \Flarum\User\User $actor): \Flarum\Database\AbstractModel {
            foreach ($this->repo->users as $u) if ($u->username === $slug) return $u;
            throw new \RuntimeException('not found');
        }
    };
    $slugs = new \FlatRate\SupabaseOAuth\Identity\GuestAwareUserSlugDriver($innerSlug, $projection, $repo);
    $alias = $projection->publicAlias($legacy);
    check(preg_match('/^tech_[0-9a-f]{8}$/D', $alias) === 1, 'legacy alias format');
    check($projection->publicAlias($neutral) === $neutral->username, 'existing neutral alias unchanged');
    check($driver->displayName($legacy) === 'PrivateNickname', 'no HTTP context delegates');
    check($slugs->toSlug($legacy) === 'LegacyRealName', 'no context route delegates');
    $ctx->push($legacy);
    check($driver->displayName($legacy) === 'PrivateNickname', 'member display unchanged');
    check($slugs->toSlug($legacy) === 'LegacyRealName', 'member route unchanged');
    $ctx->push($guest);
    check($driver->displayName($legacy) === $alias, 'guest display uses fallback');
    check($slugs->toSlug($legacy) === '72-'.$alias, 'guest route omits legacy username');
    check($slugs->toSlug($neutral) === $neutral->username, 'neutral route unchanged');
    check($slugs->fromSlug('72-'.$alias, $guest) === $legacy, 'fallback route resolves');
    check($repo->lookups[0][1] === $guest, 'native lookup receives exact request actor');
    check($slugs->fromSlug('LegacyRealName', $guest) === $legacy, 'old username route remains compatible');
    $denied = false;
    try { $slugs->fromSlug('72-tech_00000000', $guest); } catch (\RuntimeException) { $denied = true; }
    check($denied, 'wrong alias denied');
    $legacy->username = 'ChangedStoredName';
    check($projection->publicAlias($legacy) === $alias, 'fallback survives stored username rename');
    $legacy->nickname = 'ChangedNickname';
    check($projection->publicAlias($legacy) === $alias, 'fallback independent of nickname');
    check($projection->publicAlias(new \Flarum\User\User(74, 'LegacyRealName')) !== $alias, 'different row has different fallback fixture');
    foreach (['Tech_00', 'tech_123', 'tech_12345678suffix', 'tech_ABCDEF12', "tech_12345678\n"] as $value) {
        check($projection->publicAlias(new \Flarum\User\User(72, $value)) === $alias, 'non-neutral username fails closed');
    }
    check($projection->publicAlias(new \Flarum\User\User()) === 'tech_00000000', 'unsaved model is neutral');
    $ctx->pop();
    check($driver->displayName($legacy) === 'ChangedNickname', 'nested member restored');
    $ctx->pop();
    check($ctx->depth() === 0, 'request context cleared');
    echo "F2_GUEST_IDENTITY_UNIT=PASS CHECKS={$checks}\n";
}
