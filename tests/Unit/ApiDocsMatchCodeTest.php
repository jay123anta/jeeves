<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Jayanta\Jeeves\Engine\ErrorCode;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The API reference, checked against the code it describes.
 *
 * Rule 1 says documentation stays in sync with code, and the error table in
 * docs/API.md is the part most likely to drift: it is the one piece of
 * documentation a client branches on. A front end that retries on the strength
 * of a table saying `provider_error` is retryable, when the code says
 * otherwise, has been misled by us rather than by its own reading.
 *
 * Prose cannot be tested. A table of codes, statuses and retry flags can, so
 * that part is not left to whoever remembers.
 */
class ApiDocsMatchCodeTest extends TestCase
{
    private const DOC = __DIR__ . '/../../docs/API.md';

    /**
     * @return array<string, array{status: int, retryable: bool}>
     */
    private function documentedCodes(): array
    {
        $this->assertFileExists(self::DOC, 'the API reference is part of the package');

        $found = [];

        foreach (file(self::DOC) as $line) {
            if (preg_match('/^\| `([a-z_]+)` \| (\d{3}) \| (\*\*yes\*\*|yes|no) \|/', $line, $m)) {
                $found[$m[1]] = [
                    'status' => (int) $m[2],
                    'retryable' => $m[3] !== 'no',
                ];
            }
        }

        $this->assertNotEmpty(
            $found,
            'no error rows parsed from the API reference -  the table format changed and this test '
                . 'would silently pass on an empty set'
        );

        return $found;
    }

    #[Test]
    public function every_error_code_is_documented_with_the_status_it_actually_returns()
    {
        $documented = $this->documentedCodes();

        foreach (ErrorCode::HTTP_STATUS as $code => $status) {
            $this->assertArrayHasKey($code, $documented, "{$code} is missing from docs/API.md");
            $this->assertSame(
                $status,
                $documented[$code]['status'],
                "docs/API.md gives {$code} the wrong HTTP status"
            );
            $this->assertSame(
                ErrorCode::isRetryable($code),
                $documented[$code]['retryable'],
                "docs/API.md disagrees with the code about whether {$code} is worth retrying"
            );
        }
    }

    #[Test]
    public function the_reference_documents_no_code_the_package_cannot_return()
    {
        $invented = array_diff(array_keys($this->documentedCodes()), array_keys(ErrorCode::HTTP_STATUS));

        $this->assertSame([], array_values($invented), 'documented error codes that no longer exist');
    }

    /**
     * A route with no mention in the reference is a route nobody can use.
     */
    #[Test]
    public function every_route_appears_in_the_reference()
    {
        $doc = (string) file_get_contents(self::DOC);
        $prefix = config('jeeves.routes.prefix', 'jeeves');
        $undocumented = [];

        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), $prefix)) {
                continue;
            }

            // "jeeves/conversation/{sessionId}" → "/conversation/", which
            // is how the reference writes it. The bare index has no path to
            // look for and is covered by the operations table.
            $path = substr($route->uri(), strlen($prefix));

            if ($path === '' || $path === '/') {
                continue;
            }

            $segment = explode('/', ltrim($path, '/'))[0];

            if (!str_contains($doc, '/' . $segment)) {
                $undocumented[] = $route->uri();
            }
        }

        $this->assertSame([], array_unique($undocumented), 'routes missing from docs/API.md');
    }
}
