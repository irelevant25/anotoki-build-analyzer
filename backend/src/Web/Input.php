<?php

namespace App\Web;

use App\Enums\Game;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads and checks request input, collecting a message per field the way the first version's validation
 * worded them, and throws one ValidationError with all of them at the end (valid()).
 *
 *     $input = Input::of($request);
 *     $game = $input->game('game', required: true);
 *     $input->valid();
 */
final class Input
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data) {}

    public static function of(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        return new self(is_array($body) ? $body : []);
    }

    /** @param array<string, mixed> $query */
    public static function query(ServerRequestInterface $request): self
    {
        return new self($request->getQueryParams());
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    /** Present and not null or an empty string. */
    public function filled(string $field): bool
    {
        return isset($this->data[$field]) && $this->data[$field] !== '';
    }

    public function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function failed(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /** @throws ValidationError */
    public function valid(): void
    {
        if ($this->errors !== []) {
            throw new ValidationError($this->errors);
        }
    }

    public function string(string $field, bool $required = false, ?int $max = null, ?string $regex = null, ?string $regexMessage = null): ?string
    {
        $label = self::label($field);
        if (! $this->filled($field)) {
            if ($required) {
                $this->fail($field, "The {$label} field is required.");
            }

            return null;
        }

        $value = $this->data[$field];
        if (! is_string($value)) {
            $this->fail($field, "The {$label} field must be a string.");

            return null;
        }
        if ($max !== null && mb_strlen($value) > $max) {
            $this->fail($field, "The {$label} field must not be greater than {$max} characters.");

            return null;
        }
        if ($regex !== null && ! preg_match($regex, $value)) {
            $this->fail($field, $regexMessage ?? "The {$label} field format is invalid.");

            return null;
        }

        return $value;
    }

    /** A whole number (a numeric string counts); null when absent or empty. */
    public function integer(string $field, bool $required = false): ?int
    {
        $label = self::label($field);
        if (! $this->filled($field)) {
            if ($required) {
                $this->fail($field, "The {$label} field is required.");
            }

            return null;
        }

        $value = $this->data[$field];
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value))) {
            return (int) $value;
        }
        $this->fail($field, "The {$label} field must be an integer.");

        return null;
    }

    /** true, false, 1, 0, "1", "0", "true", "false". */
    public function boolean(string $field, bool $required = false): ?bool
    {
        $label = self::label($field);
        if (! $this->has($field) || $this->data[$field] === null || $this->data[$field] === '') {
            if ($required) {
                $this->fail($field, "The {$label} field is required.");
            }

            return null;
        }

        $value = $this->data[$field];
        if (in_array($value, [true, 1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0', 'false'], true)) {
            return false;
        }
        $this->fail($field, "The {$label} field must be true or false.");

        return null;
    }

    public function game(string $field, bool $required = false): ?Game
    {
        if (! $this->filled($field)) {
            if ($required) {
                $this->fail($field, 'The '.self::label($field).' field is required.');
            }

            return null;
        }

        $game = is_string($this->data[$field]) ? Game::tryFrom($this->data[$field]) : null;
        if ($game === null) {
            $this->fail($field, 'The selected '.self::label($field).' is invalid.');
        }

        return $game;
    }

    /**
     * A list of games ("games": ["genshin", "hsr"]); null when absent.
     *
     * @return list<Game>|null
     */
    public function games(string $field): ?array
    {
        if (! $this->has($field)) {
            return null;
        }
        if (! is_array($this->data[$field])) {
            $this->fail($field, 'The '.self::label($field).' field must be an array.');

            return null;
        }

        $games = [];
        foreach (array_values($this->data[$field]) as $index => $value) {
            $game = is_string($value) ? Game::tryFrom($value) : null;
            if ($game === null) {
                $this->fail("{$field}.{$index}", "The selected {$field}.{$index} is invalid.");
            } else {
                $games[] = $game;
            }
        }

        return $games;
    }

    /** "account_id" is "account id" in a message. */
    private static function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }
}
