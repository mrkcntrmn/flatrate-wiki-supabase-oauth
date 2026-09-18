<?php

namespace FlatRate\SupabaseOAuth\Presence;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Read FlatRate-owned coarse geography headers written by Cloudflare.
 *
 * TRUSTED_STATE_SOURCE (H.0B):
 *   Cloudflare Request Header Transform Rule overwrites:
 *     X-FlatRate-Country     <- ip.src.country
 *     X-FlatRate-Region-Code <- ip.src.region_code
 *   only for POST /api/flatrate/community-presence/touch on forum.flatrate.wiki.
 *
 * Client-supplied values of these headers are not trusted; Cloudflare must
 * overwrite them. Direct origin bypass must remain blocked.
 */
final class TrustedCoarseRegion
{
    public const COUNTRY_HEADER = 'X-FlatRate-Country';

    public const REGION_HEADER = 'X-FlatRate-Region-Code';

    /**
     * @return array{
     *     country_header_present: bool,
     *     region_header_present: bool,
     *     country: ?string,
     *     region_code: ?string,
     *     accepted: bool
     * }
     */
    public static function fromRequest(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = $values;
        }

        return self::fromHeaderMap($headers, $request->getServerParams());
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $server
     * @return array{
     *     country_header_present: bool,
     *     region_header_present: bool,
     *     country: ?string,
     *     region_code: ?string,
     *     accepted: bool
     * }
     */
    public static function fromHeaderMap(array $headers, array $server = []): array
    {
        $countryRaw = self::mapHeader($headers, self::COUNTRY_HEADER);
        $regionRaw = self::mapHeader($headers, self::REGION_HEADER);

        if ($countryRaw === null) {
            $countryRaw = self::cgiHeader($server, self::COUNTRY_HEADER);
        }
        if ($regionRaw === null) {
            $regionRaw = self::cgiHeader($server, self::REGION_HEADER);
        }

        $countryHeaderPresent = $countryRaw !== null;
        $regionHeaderPresent = $regionRaw !== null;

        $country = $countryHeaderPresent ? strtoupper(trim($countryRaw)) : null;
        $region = $regionHeaderPresent ? strtoupper(trim($regionRaw)) : null;

        if ($country === '') {
            $country = null;
        }
        if ($region === '') {
            $region = null;
        }

        $accepted = $country === 'US'
            && is_string($region)
            && UsStateAllowlist::contains($region);

        return [
            'country_header_present' => $countryHeaderPresent,
            'region_header_present' => $regionHeaderPresent,
            'country' => $country,
            'region_code' => $accepted ? $region : null,
            'accepted' => $accepted,
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function mapHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                $line = implode(', ', array_map('strval', $value));
            } else {
                $line = (string) $value;
            }

            return $line === '' ? null : $line;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function cgiHeader(array $server, string $name): ?string
    {
        $cgi = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
        if (isset($server[$cgi]) && is_string($server[$cgi]) && $server[$cgi] !== '') {
            return $server[$cgi];
        }

        return null;
    }
}
