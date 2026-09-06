<?php

namespace Jayanta\Jeeves\Tests\Unit;

use Jayanta\Jeeves\Security\SqlValidator;
use Jayanta\Jeeves\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SqlValidatorTest extends TestCase
{
    private SqlValidator $validator;

    private array $allowedTables = ['public.orders', 'public.customers'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SqlValidator;
    }

    #[Test]
    public function it_passes_valid_select_queries()
    {
        $queries = [
            'SELECT * FROM public.orders LIMIT 10',
            'SELECT customer_name, SUM(amount) FROM public.orders GROUP BY customer_name ORDER BY SUM(amount) DESC LIMIT 10',
            "SELECT * FROM public.orders WHERE customer_name = 'John' LIMIT 1",
        ];

        foreach ($queries as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertTrue($result['valid'], "Should pass: {$sql}. Reason: " . ($result['reason'] ?? 'none'));
        }
    }

    #[Test]
    public function it_blocks_non_select_queries()
    {
        $queries = [
            'INSERT INTO public.orders VALUES(1)',
            'UPDATE public.orders SET amount = 0',
            'DELETE FROM public.orders',
            'DROP TABLE public.orders',
            'TRUNCATE TABLE public.orders',
        ];

        foreach ($queries as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertFalse($result['valid'], "Should block: {$sql}");
        }
    }

    #[Test]
    public function it_blocks_unauthorized_tables()
    {
        $sql = 'SELECT * FROM secret_data LIMIT 10';
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unauthorized table', $result['reason']);
    }

    #[Test]
    public function it_blocks_unauthorized_tables_alongside_allowed()
    {
        $sql = 'SELECT * FROM public.orders, secret_data LIMIT 10';
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertFalse($result['valid']);
    }

    #[Test]
    public function it_blocks_unauthorized_tables_in_cte()
    {
        $sql = 'WITH cte AS (SELECT * FROM secret_table) SELECT * FROM cte LIMIT 10';
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertFalse($result['valid']);
    }

    #[Test]
    public function it_allows_cte_with_authorized_tables()
    {
        $sql = "WITH latest AS (SELECT * FROM public.orders WHERE status = 'active') SELECT * FROM latest LIMIT 10";
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertTrue($result['valid'], 'CTE with allowed table should pass. Reason: ' . ($result['reason'] ?? 'none'));
    }

    #[Test]
    public function it_allows_bare_reference_matching_qualified_whitelist_entry()
    {
        // 'orders' resolves via search_path to whitelisted 'public.orders'
        $sql = 'SELECT * FROM orders LIMIT 10';
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertTrue($result['valid'], 'Bare reference should match schema-qualified whitelist entry. Reason: ' . ($result['reason'] ?? 'none'));
    }

    #[Test]
    public function it_blocks_cross_schema_reference_against_bare_whitelist_entry()
    {
        // Regression: qualified 'other_schema.users' must NOT satisfy a bare
        // whitelist entry 'users' -  that would expose a same-named table in a
        // different schema (cross-schema whitelist bypass).
        $sql = 'SELECT * FROM other_schema.users LIMIT 10';
        $result = $this->validator->validate($sql, ['users']);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unauthorized table', $result['reason']);
    }

    #[Test]
    public function it_blocks_qualified_reference_to_wrong_schema()
    {
        // Whitelist holds 'public.orders'; 'private.orders' must not match
        $sql = 'SELECT * FROM private.orders LIMIT 10';
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unauthorized table', $result['reason']);
    }

    #[Test]
    public function it_blocks_sql_injection_patterns()
    {
        $patterns = [
            'SELECT * FROM public.orders WHERE id = 1 OR 1=1 LIMIT 10',
            'SELECT * FROM public.orders; -- LIMIT 10',
            "SELECT * FROM public.orders WHERE name = '' OR '' = '' LIMIT 10",
        ];

        foreach ($patterns as $sql) {
            $result = $this->validator->validate($sql, $this->allowedTables);
            $this->assertFalse($result['valid'], "Should block injection: {$sql}");
        }
    }

    #[Test]
    public function it_blocks_plpgsql()
    {
        $sql = "DO $$ BEGIN RAISE NOTICE 'hello'; END $$";
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertFalse($result['valid']);
    }

    #[Test]
    public function it_enforces_limit()
    {
        $sql = 'SELECT * FROM public.orders';
        $result = $this->validator->validate($sql, $this->allowedTables, ['require_limit' => true]);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('LIMIT', $result['reason']);
    }

    #[Test]
    public function it_enforces_max_limit()
    {
        $sql = 'SELECT * FROM public.orders LIMIT 1000';
        $result = $this->validator->validate($sql, $this->allowedTables, ['max_limit' => 100]);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('LIMIT cannot exceed', $result['reason']);
    }

    #[Test]
    public function it_blocks_stacked_queries()
    {
        $sql = 'SELECT * FROM public.orders LIMIT 10; SELECT * FROM secret';
        $result = $this->validator->validate($sql, $this->allowedTables);
        $this->assertFalse($result['valid']);
    }
}
