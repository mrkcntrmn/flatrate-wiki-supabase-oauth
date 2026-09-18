# FORUM-MEMBER-DASHBOARD-001H.0B — trusted state ingress

Document class: **IMPLEMENTATION_NOTE**  
Tranche: **FORUM-MEMBER-DASHBOARD-001H-0B-TRUSTED-STATE-INGRESS-R1**  
Status: **SOURCE PROBE IMPLEMENTED / PRODUCTION PROOF PENDING**  
`PRODUCTION_MUTATION` for this note: edge rule + probe deploy authorized; presence storage not authorized.

## Approved edge contract

```text
MECHANISM=NARROW_REQUEST_HEADER_TRANSFORM
BROAD_VISITOR_LOCATION_MANAGED_TRANSFORM=REJECTED

RULE_NAME=FlatRate forum presence coarse region
RULE_SCOPE_HOST=forum.flatrate.wiki
RULE_SCOPE_METHOD=POST
RULE_SCOPE_PATH=/api/flatrate/community-presence/touch

COUNTRY_SOURCE=ip.src.country
REGION_SOURCE=ip.src.region_code
ORIGIN_COUNTRY_HEADER=X-FlatRate-Country
ORIGIN_REGION_HEADER=X-FlatRate-Region-Code
OPERATION=set/overwrite (not preserve client value)
```

Do not set `CF-*` custom headers via Transform Rules.

## Probe route (H.0B)

```text
POST /api/flatrate/community-presence/touch
AUTH=administrator-only
STORAGE=false
ACTIVITY=false
RESPONSE=probe geography echo + Cache-Control: no-store
```

Source:

- `src/Presence/UsStateAllowlist.php`
- `src/Presence/TrustedCoarseRegion.php`
- `src/Api/CommunityPresenceTouchProbeController.php`
- `extend.php` route registration
- `test/community-presence-touch-probe.php`

## Production proof checklist

Record evidence under a scratch receipt after Cloudflare rule + probe pin are live:

```text
CLOUDFLARE_REQUEST_HEADER_TRANSFORM=
PHP_COUNTRY_HEADER_PROOF=
PHP_REGION_HEADER_PROOF=
CLIENT_COUNTRY_HEADER_OVERWRITE=
CLIENT_REGION_HEADER_OVERWRITE=
DIRECT_ORIGIN_BYPASS=
CITY_HEADER_ADDED=false
POSTAL_HEADER_ADDED=false
LATITUDE_HEADER_ADDED=false
LONGITUDE_HEADER_ADDED=false
FORUM_HEALTH=
SSO=
CHECK_FORUM=
TRUSTED_STATE_SOURCE_IDENTIFIED=
RESULT=
```

H.1 must not start until `RESULT=PASS` and `TRUSTED_STATE_SOURCE_IDENTIFIED=true`.
