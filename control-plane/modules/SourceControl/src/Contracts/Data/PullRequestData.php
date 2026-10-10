<?php

namespace Falak\SourceControl\Contracts\Data;

/**
 * A pull request (GitHub, Bitbucket) or merge request (GitLab) as a webhook delivered it.
 */
final readonly class PullRequestData
{
    public function __construct(
        /** The base repository (the one the connection reaches and the webhook belongs to) */
        public string $repository,
        public int $number,
        public string $title,
        public ?string $url,
        public string $headBranch,
        /** Bitbucket sends 12-character hashes */
        public string $headSha,
        public string $baseBranch,
        /** The author's login at the provider */
        public ?string $author,
        /** The head lives in another repository (a fork): its code is untrusted */
        public bool $isFork,
        /** The head's repository (the fork's, or the base repository) */
        public ?string $sourceRepository,
    ) {}

    public function shortSha(): string
    {
        return substr($this->headSha, 0, 7);
    }
}
