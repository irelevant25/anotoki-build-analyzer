<?php

namespace App\Web;

use Anotoki\Lib\Support\ClientAddress as Rule;
use Anotoki\Lib\Support\Keys;
use App\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Which address a request comes from, for the limits that count per address - by the anotoki family's one rule
 * (the library's Anotoki\Lib\Support\ClientAddress, the owner's of 2026-10-11), over this site's proxies: the
 * same reading the library's own admin tools make of a request (the feedback's limits, the error log's client
 * hash: App\Admin\AdminTools::context() hands them the same proxies).
 *
 * REMOTE_ADDR, unless the connection comes from a proxy config/app.php trusts (client_address.trusted_proxies:
 * Cloudflare's published ranges, overridable in config/client_address.local.php): then the address Cloudflare
 * names in CF-Connecting-IP, when that is one address. That header and no other, whatever a setting says (the
 * owner's rule): Cloudflare writes it whole over whatever the visitor sent, while a header such as X-Real-IP or
 * True-Client-IP passes through it as the visitor wrote it, and X-Forwarded-For starts with what they wrote -
 * believed, any of them would let a visitor pick a new address for every request and walk past every limit. A
 * header that is not one address is not believed either: the proxy's own address is counted then (stricter, never
 * looser). An IPv4 address written as IPv6 (::ffff:198.51.100.7, from a server listening on [::] for both) is the
 * IPv4 address it is, before it is judged.
 *
 * One address, for counting: an IPv4 address as it is, an IPv6 address by its /64 - one household or one server
 * is handed a whole /64 and picks from it at will, so counting single IPv6 addresses would count nothing.
 */
final class ClientAddress
{
    /**
     * The proxies whose CF-Connecting-IP is believed (CIDR ranges, v4 and v6).
     *
     * @return list<string>
     */
    public static function trustedProxies(Config $config): array
    {
        return array_values(array_filter((array) $config->get('client_address.trusted_proxies', []), 'is_string'));
    }

    /** What the limits count: the address, or for IPv6 its /64 ("2001:db8:1:2::/64"); "?" for none at all. */
    public static function counted(ServerRequestInterface $request, Config $config): string
    {
        $address = Rule::of($request, self::trustedProxies($config), Rule::CLOUDFLARE_HEADER);

        return $address === null ? '?' : Rule::bucket($address);
    }

    /**
     * What the limits count, as the counters keep it (the cache table's keys - the throttles', the new visitors'
     * limit): an HMAC of counted() under this installation's key (the anotoki library's Keys: made once in
     * storage/anotoki-lib.key, derived for this one purpose), never the address. The counters' rows live until
     * something prunes them, and every backup of the database carries the table: neither holds an address as
     * such - but the hash is a pseudonym, the same for one address for as long as the key lasts, and whoever
     * holds the key (storage/anotoki-lib.key) could try every IPv4 address against it.
     */
    public static function countedKey(ServerRequestInterface $request, Config $config): string
    {
        return substr(hash_hmac('sha256', self::counted($request, $config), self::key($config)), 0, 32);
    }

    /** @var array<string, string> the counters' key, by the storage folder it is kept in */
    private static array $keys = [];

    private static function key(Config $config): string
    {
        $storage = $config->storage();

        return self::$keys[$storage] ??= Keys::derive(Keys::installation($storage), 'address-counters');
    }
}
