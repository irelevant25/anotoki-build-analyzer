<?php

namespace App\Web;

use Anotoki\Lib\Support\Keys;
use App\Config;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Which address a request comes from, for the limits that count per address - and what one address is.
 *
 * REMOTE_ADDR, unless the connection comes from a proxy config/app.php trusts (client_address.
 * trusted_proxies): then the address in its header (client_address.client_ip_header), when that is an
 * address at all. A forwarding header is one the caller writes, so believed from anybody it would let them
 * pick a new address for every request and walk past every limit; X-Forwarded-For is never read on its own.
 * The anotoki IAM decides the same way (its api/helpers.php requestIp()).
 *
 * The header should be one the proxy writes whole, overwriting whatever the visitor sent (Cloudflare's
 * CF-Connecting-IP: one address). A list such as X-Forwarded-For, which a proxy appends to, starts with
 * whatever the visitor wrote: it is read from the right - the proxies' own addresses skipped - and the
 * first address no trusted proxy has is the one counted.
 *
 * One address, for counting: an IPv4 address as it is, an IPv6 address by its /64 - one household or one
 * server is handed a whole /64 and picks from it at will, so counting single IPv6 addresses would count
 * nothing. An IPv4 address written as IPv6 (::ffff:198.51.100.7) is that IPv4 address.
 */
final class ClientAddress
{
    public static function of(ServerRequestInterface $request, Config $config): string
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $proxies = (array) $config->get('client_address.trusted_proxies', []);

        if ($ip !== '' && $proxies !== [] && self::inRanges($ip, $proxies)) {
            $header = (string) $config->get('client_address.client_ip_header', 'CF-Connecting-IP');
            $forwarded = self::rightmostUntrusted($request->getHeaderLine($header), $proxies);
            if ($forwarded !== null) {
                $ip = $forwarded;
            }
        }

        return $ip === '' ? '?' : substr($ip, 0, 45);
    }

    /**
     * The address a proxy's header names: read from the right, past the trusted proxies' own addresses; null
     * when the first other entry is no address at all, or there is none.
     *
     * @param  array<mixed>  $proxies
     */
    private static function rightmostUntrusted(string $header, array $proxies): ?string
    {
        foreach (array_reverse(explode(',', $header)) as $entry) {
            $entry = trim($entry);
            if (filter_var($entry, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            if (! self::inRanges($entry, $proxies)) {
                return $entry;
            }
        }

        return null;
    }

    /** What the limits count: the address, or for IPv6 its /64 ("2001:db8:1:2::/64"). */
    public static function counted(ServerRequestInterface $request, Config $config): string
    {
        return self::bucket(self::of($request, $config));
    }

    /**
     * What the limits count, as the counters keep it (the cache table's keys - the throttles', the new visitors'
     * limit): an HMAC of counted() under this installation's key (the anotoki library's Keys: made once in
     * storage/anotoki-lib.key, derived for this one purpose), never the address. The counters' rows live until
     * something prunes them, and every backup of the database carries the table: no address anybody could read
     * back is in either.
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

    public static function bucket(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }
        // ::ffff:a.b.c.d
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /**
     * Whether an address (v4 or v6) is inside one of the CIDR ranges; a bare address is a range of one.
     *
     * @param  array<mixed>  $ranges
     */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }

        foreach ($ranges as $range) {
            [$subnet, $bits] = array_pad(explode('/', (string) $range, 2), 2, null);
            $network = @inet_pton(trim((string) $subnet));
            if ($network === false || strlen($network) !== strlen($address)) {
                continue;
            }

            $bits = $bits === null ? strlen($address) * 8 : max(0, min(strlen($address) * 8, (int) $bits));
            $whole = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($address, 0, $whole) !== substr($network, 0, $whole)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($address[$whole]) & $mask) === (ord($network[$whole]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
