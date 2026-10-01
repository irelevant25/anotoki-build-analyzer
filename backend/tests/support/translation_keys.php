<?php

declare(strict_types=1);

/**
 * Which keys the frontend asks for - for translations.php (the command line) and the tests. Functions only;
 * tests/ is for computers with a shell and is never uploaded.
 *
 * The strings live in the database so they can be reworded in the browser, which means nothing stops a
 * template asking for a key no migration ever wrote: the page shows the key itself, and the mistake is
 * released. The scan is what catches it - and the other way round, a key left in the database that no page
 * asks for any more.
 *
 * How a key is recognised in frontend/src/app (every .ts and .html, but the specs, the vendored sign-in
 * SDK in core/iam/, the test helper core/i18n/testing.ts and the compiled English under core/i18n/en/ -
 * which lists every key and so says nothing about which are used):
 *
 *   - Its shape: a namespace ([a-z][A-Za-z0-9]*), then one or more segments
 *     ([A-Za-z0-9][A-Za-z0-9_-]*), joined by dots: dashboard.title, import.error.file_not_json.
 *   - A quoted literal of that shape whose namespace the database has is a key the code uses. The
 *     namespace is what tells 'dashboard.title' from 'changelog.json' or a file's name. Comments do not
 *     count: they explain keys at least as often as they use them.
 *   - In a template, a double-quoted string right after = is an attribute's value - an expression,
 *     [text]="error.message" - and not a literal; the single-quoted literals inside it are
 *     ('common.close' | translate). A template literal with nothing put into it (`dashboard.title`) is a
 *     literal like the quoted ones.
 *   - A literal L is answered by the key L, or by the plural family L.one, L.few, L.other (the code asks
 *     for the family by its name).
 *   - Where the literal stands right beside what asks for it, how it is asked for must fit what the
 *     database has: 'L' | translatePlural and .plural('L') need the family, 'L' | translate, .t('L'),
 *     .around('L') and .has('L') the one key - the other way round the page shows the key though both
 *     "exist". A key that reaches the pipe or the call through a variable or a table has nothing beside
 *     it to read, and is not checked this way.
 *   - Keys put together at run time cannot be seen in the source. They are declared in
 *     core/i18n/dynamic-keys.ts, prefix -> every tail; each must exist, and a key under a declared prefix
 *     that is not listed is unused like any other. What the source does show is the putting together: a
 *     literal that ends in a dot ('import.error.' + code) or a template literal that goes on with ${...}.
 *     Its prefix must be one that is declared, or the scan fails - otherwise nothing would miss those
 *     keys. A frontend without that file declares nothing.
 *   - A template that gives innerHTML a translated string fails the scan, on one line or over several: a
 *     string is text and is never drawn as HTML.
 */

/** A key: namespace.segment[.segment...]. */
const TRANSLATION_KEY_SHAPE = '[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+';

/** What keys are put together from: namespace.[segment.]* - a key's beginning, up to and with a dot. */
const TRANSLATION_KEY_PREFIX_SHAPE = '[a-z][A-Za-z0-9]*\.(?:[A-Za-z0-9][A-Za-z0-9_-]*\.)*';

/** The forms of a plural family, as Intl.PluralRules names the ones English and Slovak have. */
const TRANSLATION_PLURAL_FORMS = ['one', 'few', 'other'];

/** Where the keys put together at run time are declared, under the frontend's src/app. */
const TRANSLATION_DYNAMIC_KEYS_FILE = 'core/i18n/dynamic-keys.ts';

/** The frontend's sources, seen from backend/tests/support/. */
function translationKeysFrontendRoot(): string
{
    return str_replace('\\', '/', dirname(__DIR__, 3)).'/frontend/src/app';
}

function translationKeyShapeOk(string $key): bool
{
    return preg_match('/^'.TRANSLATION_KEY_SHAPE.'$/D', $key) === 1;
}

/** A key's namespace: what stands before its first dot. */
function translationKeyNamespace(string $key): string
{
    return explode('.', $key, 2)[0];
}

/**
 * The files the scan reads, relative to $root (forward slashes), sorted.
 *
 * @return list<string>
 */
function translationKeySourceFiles(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        if (! preg_match('/\.(ts|html)$/D', $relative)
            || str_ends_with($relative, '.spec.ts')
            || str_starts_with($relative, 'core/iam/')
            || str_starts_with($relative, 'core/i18n/en/')
            || $relative === 'core/i18n/en.ts'
            || $relative === 'core/i18n/testing.ts') {
            continue;
        }
        $files[] = $relative;
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * A source file without its comments, line for line (what is taken out leaves its line breaks, so a place
 * is still the line it was on). Line endings become \n. In TypeScript a string is passed over first, so
 * the // of 'http://...' and the /* of 'image/*' stay what they are.
 */
function translationKeySourceText(string $source, bool $html): string
{
    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $blank = static fn (string $text): string => str_repeat("\n", substr_count($text, "\n"));

    if ($html) {
        $text = preg_replace_callback('/<!--.*?-->/s', static fn (array $m): string => $blank($m[0]), $source);
    } else {
        $text = preg_replace_callback(
            '~("[^"\\\\\n]*(?:\\\\.[^"\\\\\n]*)*"|\'[^\'\\\\\n]*(?:\\\\.[^\'\\\\\n]*)*\'|`[^`\\\\]*(?:\\\\.[^`\\\\]*)*`)|//[^\n]*|/\*[^*]*\*+(?:[^/*][^*]*\*+)*/~s',
            static fn (array $m): string => ($m[1] ?? '') !== '' ? $m[0] : $blank($m[0]),
            $source
        );
    }
    if ($text === null) {
        throw new RuntimeException('The key scan could not read a source file: '.preg_last_error_msg());
    }

    return $text;
}

/** The line of a source a place in it is on. */
function translationKeyLine(string $text, int $offset): int
{
    return substr_count($text, "\n", 0, $offset) + 1;
}

/**
 * The literals of a source (as translationKeySourceText() leaves it) that have a key's shape - quoted, or
 * a template literal with nothing put into it - each with the lines it stands on. Whether one is a key is
 * for the caller, who knows the database's namespaces.
 *
 * @return array<string, list<int>>
 */
function translationKeyLiterals(string $text, bool $html): array
{
    $found = [];
    preg_match_all('~([\'"`])('.TRANSLATION_KEY_SHAPE.')\1~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        [$quote, $offset] = $match[1];
        // A template's attribute value: an expression, not a literal.
        if ($html && $quote === '"' && $offset > 0 && $text[$offset - 1] === '=') {
            continue;
        }
        $found[$match[2][0]][] = translationKeyLine($text, $offset);
    }

    return $found;
}

/**
 * The literals that stand right beside what asks for them, so that how each is asked for can be read: in
 * a template 'L' | translate and 'L' | translatePlural, in TypeScript .t('L'), .around('L'), .has('L') and
 * .plural('L'). A formatter may write the literal of an interpolation with double quotes
 * ({{ "dashboard.accounts" | translatePlural: n }}), so every quote counts.
 *
 * @return list<array{literal: string, plural: bool, line: int}> in the order of the source
 */
function translationKeyAsks(string $text, bool $html): array
{
    $pattern = $html
        ? '~([\'"`])('.TRANSLATION_KEY_SHAPE.')\1\s*\|\s*(translatePlural|translate)\b~'
        : '~\.(t|around|has|plural)\(\s*([\'"`])('.TRANSLATION_KEY_SHAPE.')\2~';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $asks = [];
    foreach ($matches as $match) {
        $asks[] = [
            'literal' => $match[$html ? 2 : 3][0],
            'plural' => in_array($match[$html ? 3 : 1][0], ['translatePlural', 'plural'], true),
            'line' => translationKeyLine($text, $match[0][1]),
        ];
    }

    return $asks;
}

/**
 * Where a source puts a key together at run time, as far as that can be seen: what stands before the part
 * that is added - a literal ending in a dot ('import.error.' + code), or the beginning of a template
 * literal that goes on with ${...} (`import.error.${code}`) - with its line. Whether it is a key's
 * beginning at all is for the caller, who knows the database's namespaces and the prefixes that are
 * declared.
 *
 * @return list<array{prefix: string, line: int}> in the order of the source
 */
function translationKeyBuilt(string $text, bool $html): array
{
    $built = [];
    preg_match_all('~([\'"`])('.TRANSLATION_KEY_PREFIX_SHAPE.')\1~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        [$quote, $offset] = $match[1];
        if ($html && $quote === '"' && $offset > 0 && $text[$offset - 1] === '=') {
            continue;
        }
        $built[$offset] = ['prefix' => $match[2][0], 'line' => translationKeyLine($text, $offset)];
    }
    // `import.error.${code}`, and `import.error${suffix}` just as much: whatever of a key stands before the ${.
    preg_match_all('~`([a-z][A-Za-z0-9]*\.[A-Za-z0-9._-]*)\$\{~', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        $built[$match[0][1]] = ['prefix' => $match[1][0], 'line' => translationKeyLine($text, $match[0][1])];
    }
    ksort($built);

    return array_values($built);
}

/**
 * The keys declared in dynamic-keys.ts: an object of 'prefix.': ['tail', ...]. It is read as text, so the
 * tails must be written out - a list made by code (a spread, a map over a constant) cannot be read, and is
 * a problem.
 *
 * @return array{prefixes: array<string, list<string>>, keys: list<string>, problems: list<string>}
 */
function translationKeysDynamic(string $source): array
{
    $text = translationKeySourceText($source, false);
    $prefixes = [];
    $keys = [];
    $problems = [];

    preg_match_all('~([\'"])('.TRANSLATION_KEY_PREFIX_SHAPE.')\1\s*:\s*(\[[^\]]*\])?~', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $prefix = $match[2];
        $list = $match[3] ?? '';
        preg_match_all('~\'([^\'\\\\\n]*)\'|"([^"\\\\\n]*)"~', $list, $tails, PREG_SET_ORDER);
        if ($list === '' || ! preg_match('/^\[[\s,]*\]$/D', (string) preg_replace('~\'[^\'\\\\\n]*\'|"[^"\\\\\n]*"~', '', $list))) {
            $problems[] = "the tails of '{$prefix}' are not written out as a list of strings, so they cannot be read";

            continue;
        }
        $prefixes[$prefix] = [];
        foreach ($tails as $tail) {
            $tail = ($tail[1] ?? '') !== '' ? $tail[1] : ($tail[2] ?? '');
            if (! translationKeyShapeOk($prefix.$tail)) {
                $problems[] = "'{$prefix}' + '{$tail}' is not a key";

                continue;
            }
            $prefixes[$prefix][] = $tail;
            $keys[] = $prefix.$tail;
        }
    }
    if ($matches === []) {
        $problems[] = 'no \'prefix.\': [tails] could be read from it';
    }

    return ['prefixes' => $prefixes, 'keys' => array_values(array_unique($keys)), 'problems' => $problems];
}

/**
 * What a frontend declares in its dynamic-keys.ts - nothing at all when it has no such file.
 *
 * @return array{prefixes: array<string, list<string>>, keys: list<string>, problems: list<string>}
 */
function translationKeysDeclared(string $root): array
{
    $file = rtrim(str_replace('\\', '/', $root), '/').'/'.TRANSLATION_DYNAMIC_KEYS_FILE;

    return is_file($file)
        ? translationKeysDynamic((string) file_get_contents($file))
        : ['prefixes' => [], 'keys' => [], 'problems' => []];
}

/**
 * The frontend's keys against the database's.
 *
 *   used      database keys the frontend asks for, each with where (file:line)
 *   missing   keys it asks for that the database does not have - the page would show the key
 *   unused    database keys nothing asks for
 *   problems  what else fails the scan, each with its place: a key asked for the wrong way (a plural
 *             family as one string, one string as a family), a key put together from a prefix that is
 *             not declared, innerHTML given a translated string, a list of declared tails that cannot
 *             be read
 *
 * @param  string  $root  the frontend's src/app
 * @param  list<string>  $databaseKeys  every key the database has
 * @return array{used: array<string, list<string>>, missing: array<string, list<string>>, unused: list<string>, problems: list<string>}
 */
function translationKeysScan(string $root, array $databaseKeys): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $has = array_fill_keys($databaseKeys, true);
    $namespaces = [];
    foreach ($databaseKeys as $key) {
        $namespaces[translationKeyNamespace((string) $key)] = true;
    }

    // Has the database any form of the plural family a literal names?
    $hasFamily = static function (string $literal) use ($has): bool {
        foreach (TRANSLATION_PLURAL_FORMS as $form) {
            if (isset($has["{$literal}.{$form}"])) {
                return true;
            }
        }

        return false;
    };

    // The keys put together at run time, first: the files are held to the prefixes declared here.
    $dynamic = translationKeysDeclared($root);

    $asked = [];
    $problems = [];
    foreach (translationKeySourceFiles($root) as $relative) {
        $html = str_ends_with($relative, '.html');
        $text = translationKeySourceText((string) file_get_contents("{$root}/{$relative}"), $html);

        if ($html) {
            // The attribute's whole value, however many lines it is written on - and only the value: a
            // translated string elsewhere on the line is drawn as text like any other.
            preg_match_all('~\[?innerHTML\]?\s*=\s*(["\'])(.*?)\1~is', $text, $bindings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($bindings as $binding) {
                if (preg_match('~translate|\.(?:t|plural|around)\(~', $binding[2][0]) === 1) {
                    $problems[] = "{$relative}:".translationKeyLine($text, $binding[0][1]).': innerHTML is given a translated string - a string is text, and is never drawn as HTML';
                }
            }
        }
        foreach (translationKeyLiterals($text, $html) as $literal => $lines) {
            if (isset($namespaces[translationKeyNamespace((string) $literal)])) {
                $asked[$literal][] = "{$relative}:".$lines[0];
            }
        }
        // Asked for in the way the database has it? The other way round both "exist" and the page still
        // shows the key: t() looks for the key itself, plural() for key.one, key.few or key.other.
        foreach (translationKeyAsks($text, $html) as $ask) {
            $literal = $ask['literal'];
            if (! isset($namespaces[translationKeyNamespace($literal)])) {
                continue;
            }
            if ($ask['plural'] && ! $hasFamily($literal)) {
                $problems[] = "{$relative}:{$ask['line']}: '{$literal}' is asked for as a plural, and there is no {$literal}.one, .few or .other - the page would show the key";
            } elseif (! $ask['plural'] && ! isset($has[$literal]) && $hasFamily($literal)) {
                $problems[] = "{$relative}:{$ask['line']}: '{$literal}' is a plural family and is asked for as one string - the page would show the key";
            }
        }
        // A key put together here, from a prefix nobody declared: its keys could be missing from the
        // database and nothing would say so.
        if ($relative !== TRANSLATION_DYNAMIC_KEYS_FILE) {
            foreach (translationKeyBuilt($text, $html) as $built) {
                if (isset($namespaces[translationKeyNamespace($built['prefix'])]) && ! isset($dynamic['prefixes'][$built['prefix']])) {
                    $problems[] = "{$relative}:{$built['line']}: a key is put together from '{$built['prefix']}', which ".TRANSLATION_DYNAMIC_KEYS_FILE.' does not declare';
                }
            }
        }
    }

    foreach ($dynamic['problems'] as $problem) {
        $problems[] = TRANSLATION_DYNAMIC_KEYS_FILE.": {$problem}";
    }
    foreach ($dynamic['keys'] as $key) {
        $asked[$key][] = TRANSLATION_DYNAMIC_KEYS_FILE;
    }

    $used = [];
    $missing = [];
    foreach ($asked as $literal => $where) {
        $literal = (string) $literal;
        $family = array_map(static fn (string $form): string => "{$literal}.{$form}", TRANSLATION_PLURAL_FORMS);
        $forms = array_values(array_filter($family, static fn (string $key): bool => isset($has[$key])));

        if (isset($has[$literal])) {
            $used[$literal] = array_merge($used[$literal] ?? [], $where);
        }
        if ($forms !== []) {
            // Asked for by the family's name: every form of it is used - and one that is not there is
            // missing, unless the name itself is a key.
            foreach ($family as $key) {
                if (isset($has[$key])) {
                    $used[$key] = array_merge($used[$key] ?? [], $where);
                } elseif (! isset($has[$literal])) {
                    $missing[$key] = array_merge($missing[$key] ?? [], $where);
                }
            }
        } elseif (! isset($has[$literal])) {
            $missing[$literal] = array_merge($missing[$literal] ?? [], $where);
        }
    }

    ksort($used, SORT_STRING);
    ksort($missing, SORT_STRING);
    $unused = array_values(array_filter(array_map('strval', $databaseKeys), static fn (string $key): bool => ! isset($used[$key])));
    sort($unused, SORT_STRING);

    return ['used' => $used, 'missing' => $missing, 'unused' => $unused, 'problems' => $problems];
}
