<?php

namespace Kiln\SourceControl\Contracts\Data;

use DateTimeImmutable;

final readonly class CommitData
{
    public function __construct(
        public string $sha,
        public string $message,
        public ?string $authorName,
        public ?string $authorEmail,
        public ?DateTimeImmutable $committedAt = null,
        public ?string $url = null,
    ) {}

    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }

    /** First line of the commit message. */
    public function title(): string
    {
        return strtok($this->message, "\n") ?: '';
    }

    /**
     * @return array{sha: string, message: string, author_name: ?string, author_email: ?string, committed_at: ?string, url: ?string}
     */
    public function toArray(): array
    {
        return [
            'sha' => $this->sha,
            'message' => $this->message,
            'author_name' => $this->authorName,
            'author_email' => $this->authorEmail,
            'committed_at' => $this->committedAt?->format(DATE_ATOM),
            'url' => $this->url,
        ];
    }
}
