<?php

namespace App\Services\Builds;

/**
 * A character as listed on a build website.
 */
final readonly class CharacterRef
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $url,
        /** False for announced / speculative characters that have no build yet. */
        public bool $released = true,
        public array $meta = [],
    ) {}

    /**
     * As stored in a sync run's state, which works through the list over several requests.
     *
     * @return array{slug: string, name: string, url: string, released: bool, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'name' => $this->name, 'url' => $this->url, 'released' => $this->released, 'meta' => $this->meta];
    }

    /**
     * @param  array{slug: string, name: string, url: string, released?: bool, meta?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['slug'], $data['name'], $data['url'], $data['released'] ?? true, $data['meta'] ?? []);
    }
}
