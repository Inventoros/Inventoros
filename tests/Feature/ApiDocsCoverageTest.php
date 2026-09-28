<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\WebhookService;
use Tests\TestCase;

/**
 * The REST guides enumerate webhook events and GraphQL operations by hand.
 * Hold both copies (docs/api/README.md and the site's rest-api.md section)
 * to what the app actually registers, so new surfaces are not left out.
 */
class ApiDocsCoverageTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function guides(): array
    {
        return [
            'docs/api/README.md' => ['docs/api/README.md'],
            'rest-api.md' => ['docs/site/sections/rest-api.md'],
        ];
    }

    private function section(string $markdown, string $heading): string
    {
        $parts = preg_split('/^#{2,3} '.preg_quote($heading, '/').'\s*$/m', $markdown);
        $this->assertCount(2, $parts, "Expected one \"{$heading}\" heading");

        return preg_split('/^#{2,3} /m', $parts[1])[0];
    }

    /**
     * @return array<int, string>
     */
    private function backticked(string $text): array
    {
        preg_match_all('/`([A-Za-z_.]+)`/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guides')]
    public function test_the_webhook_table_lists_every_event(string $path): void
    {
        $markdown = (string) file_get_contents(base_path($path));
        $section = $this->section($markdown, str_contains($path, 'rest-api') ? 'Webhook events' : 'Webhooks');

        $table = implode("\n", array_filter(explode("\n", $section), fn ($line) => str_starts_with($line, '|')));
        $documented = array_values(array_filter($this->backticked($table), fn ($name) => str_contains($name, '.')));

        $missing = array_values(array_diff(WebhookService::availableEvents(), $documented));
        $unknown = array_values(array_diff($documented, WebhookService::availableEvents()));

        $this->assertSame([], $missing, "{$path} is missing webhook events");
        $this->assertSame([], $unknown, "{$path} lists events the app does not fire");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guides')]
    public function test_the_graphql_section_lists_every_query_and_mutation(string $path): void
    {
        $markdown = (string) file_get_contents(base_path($path));
        $section = $this->section($markdown, 'GraphQL');

        preg_match('/^Queries: (.+)$/m', $section, $q);
        preg_match('/^Mutations: (.+)$/m', $section, $mu);
        $this->assertNotEmpty($q, 'No "Queries:" line');
        $this->assertNotEmpty($mu, 'No "Mutations:" line');

        $schema = config('graphql.schemas.default');

        $queries = array_keys($schema['query']);
        $mutations = array_keys($schema['mutation']);
        sort($queries);
        sort($mutations);

        $documentedQueries = $this->backticked($q[1]);
        $documentedMutations = $this->backticked($mu[1]);
        sort($documentedQueries);
        sort($documentedMutations);

        $this->assertSame($queries, $documentedQueries, "{$path}: GraphQL queries");
        $this->assertSame($mutations, $documentedMutations, "{$path}: GraphQL mutations");
    }
}
