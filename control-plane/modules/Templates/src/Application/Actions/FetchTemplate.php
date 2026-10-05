<?php

namespace Falak\Templates\Application\Actions;

use Falak\Templates\Application\Import\FetchFailed;
use Falak\Templates\Application\Import\RemoteFetcher;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Import from URL: fetch a template bundle (template with `compose:`), or a template.yaml whose compose.yaml sits
 * next to it (e.g. a raw GitHub URL of a catalog directory). Returns the files for the import form to preview.
 */
final class FetchTemplate
{
    public function __construct(private readonly RemoteFetcher $fetcher) {}

    /**
     * @return array{template_yaml: string, compose_yaml: ?string}
     *
     * @throws ValidationException
     */
    public function __invoke(string $url): array
    {
        try {
            $template = $this->fetcher->fetch($url);
            $compose = null;

            try {
                $doc = Yaml::parse($template);
            } catch (ParseException) {
                $doc = null;
            }

            if (is_array($doc) && ! array_key_exists('compose', $doc) && isset($doc['services']) && ! isset($doc['slug'])) {
                throw ValidationException::withMessages(['url' => 'That is a compose file; import the template.yaml (or a bundle) instead.']);
            }

            if (is_array($doc) && ! array_key_exists('compose', $doc)) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $sibling = substr($url, 0, (int) strrpos($url, $path)).substr($path, 0, (int) strrpos($path, '/') + 1).'compose.yaml';
                $query = parse_url($url, PHP_URL_QUERY);
                $compose = $this->fetcher->fetch($sibling.($query ? "?{$query}" : ''));
            }
        } catch (FetchFailed $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        return ['template_yaml' => $template, 'compose_yaml' => $compose];
    }
}
