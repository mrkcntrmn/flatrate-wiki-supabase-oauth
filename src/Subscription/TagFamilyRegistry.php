<?php

namespace FlatRate\SupabaseOAuth\Subscription;

/**
 * Pure slug-based GM/CDJR family map.
 *
 * TAG_INHERITANCE=NO — does not inspect Flarum parent_id.
 * NOTIFICATION_INHERITANCE=YES — used only for notification state resolution.
 */
final class TagFamilyRegistry
{
    private const FAMILIES = [
        'gm' => ['buick', 'cadillac', 'chevrolet', 'gmc'],
        'cdjr' => ['chrysler', 'dodge', 'jeep', 'ram'],
    ];

    /** @var array<string, string> childSlug => rootSlug */
    private array $childToRoot;

    public function __construct()
    {
        $map = [];
        foreach (self::FAMILIES as $root => $children) {
            foreach ($children as $child) {
                $map[$child] = $root;
            }
        }
        $this->childToRoot = $map;
    }

    public function familyRootFor(string $slug): ?string
    {
        $slug = strtolower($slug);
        if (isset(self::FAMILIES[$slug])) {
            return $slug;
        }

        return $this->childToRoot[$slug] ?? null;
    }

    /**
     * @return list<string>
     */
    public function childrenForRoot(string $rootSlug): array
    {
        $rootSlug = strtolower($rootSlug);

        return self::FAMILIES[$rootSlug] ?? [];
    }

    /**
     * Root plus children.
     *
     * @return list<string>
     */
    public function membersForRoot(string $rootSlug): array
    {
        $rootSlug = strtolower($rootSlug);
        if (!isset(self::FAMILIES[$rootSlug])) {
            return [];
        }

        return array_merge([$rootSlug], self::FAMILIES[$rootSlug]);
    }

    public function isFamilyRoot(string $slug): bool
    {
        return isset(self::FAMILIES[strtolower($slug)]);
    }

    public function isFamilyChild(string $slug): bool
    {
        return isset($this->childToRoot[strtolower($slug)]);
    }

    public function isFamilyMember(string $slug): bool
    {
        return $this->familyRootFor($slug) !== null;
    }

    public function familyRootCount(): int
    {
        return count(self::FAMILIES);
    }

    public function familyChildCount(): int
    {
        return count($this->childToRoot);
    }

    public function familyMemberCount(): int
    {
        return $this->familyRootCount() + $this->familyChildCount();
    }

    public function familyOverlapCount(): int
    {
        $seen = [];
        $overlap = 0;
        foreach ($this->childToRoot as $child => $root) {
            if (isset($seen[$child])) {
                $overlap++;
            }
            $seen[$child] = $root;
        }
        foreach (array_keys(self::FAMILIES) as $root) {
            if (isset($this->childToRoot[$root])) {
                $overlap++;
            }
        }

        return $overlap;
    }
}
