<?php

declare(strict_types=1);

namespace Fium\Tests;

use Fium\Runtime\Request;
use Fium\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_required_passes_and_fails(): void
    {
        $v = Validator::validate(['name' => 'Ada'], ['name' => 'required|string']);
        self::assertTrue($v->passes());
        self::assertSame(['name' => 'Ada'], $v->validated());
        self::assertSame([], $v->errors());

        $failing = Validator::validate(['name' => ''], ['name' => 'required']);
        self::assertTrue($failing->fails());
        self::assertArrayHasKey('name', $failing->errors());
    }

    public function test_required_fails_for_null_and_empty_array(): void
    {
        self::assertTrue(Validator::validate(['x' => null], ['x' => 'required'])->fails());
        self::assertTrue(Validator::validate(['x' => []], ['x' => 'required'])->fails());
        self::assertTrue(Validator::validate([], ['x' => 'required'])->fails());
    }

    public function test_nullable_allows_null(): void
    {
        $v = Validator::validate(['x' => null], ['x' => 'nullable|string']);
        self::assertTrue($v->passes());
        self::assertNull($v->validated()['x']);
    }

    public function test_nullable_with_required_still_requires(): void
    {
        self::assertTrue(Validator::validate(['x' => null], ['x' => 'nullable|required'])->fails());
    }

    public function test_string_rule(): void
    {
        self::assertTrue(Validator::validate(['x' => 42], ['x' => 'string'])->fails());
        self::assertTrue(Validator::validate(['x' => 'ok'], ['x' => 'string'])->passes());
        // null is allowed through unless required
        self::assertTrue(Validator::validate(['x' => null], ['x' => 'string'])->passes());
    }

    public function test_integer_rule_accepts_int_and_numeric_string(): void
    {
        self::assertTrue(Validator::validate(['x' => 5], ['x' => 'integer'])->passes());
        self::assertTrue(Validator::validate(['x' => '5'], ['x' => 'integer'])->passes());
        self::assertTrue(Validator::validate(['x' => 'nope'], ['x' => 'integer'])->fails());
    }

    public function test_numeric_rule(): void
    {
        self::assertTrue(Validator::validate(['x' => '3.14'], ['x' => 'numeric'])->passes());
        self::assertTrue(Validator::validate(['x' => 'nope'], ['x' => 'numeric'])->fails());
    }

    public function test_email_rule(): void
    {
        self::assertTrue(Validator::validate(['x' => 'a@b.com'], ['x' => 'email'])->passes());
        self::assertTrue(Validator::validate(['x' => 'nope'], ['x' => 'email'])->fails());
        // null passes unless required
        self::assertTrue(Validator::validate(['x' => null], ['x' => 'email'])->passes());
    }

    public function test_min_rule_for_strings_numbers_and_arrays(): void
    {
        self::assertTrue(Validator::validate(['x' => 'ab'], ['x' => 'min:3'])->fails());
        self::assertTrue(Validator::validate(['x' => 'abcd'], ['x' => 'min:3'])->passes());

        self::assertTrue(Validator::validate(['x' => 2], ['x' => 'min:3'])->fails());
        self::assertTrue(Validator::validate(['x' => 5], ['x' => 'min:3'])->passes());

        self::assertTrue(Validator::validate(['x' => [1, 2]], ['x' => 'array|min:3'])->fails());
        self::assertTrue(Validator::validate(['x' => [1, 2, 3, 4]], ['x' => 'array|min:3'])->passes());
    }

    public function test_max_rule(): void
    {
        self::assertTrue(Validator::validate(['x' => 'abcd'], ['x' => 'max:3'])->fails());
        self::assertTrue(Validator::validate(['x' => 'ab'], ['x' => 'max:3'])->passes());
    }

    public function test_in_rule(): void
    {
        self::assertTrue(Validator::validate(['x' => 'b'], ['x' => 'in:a,b,c'])->passes());
        self::assertTrue(Validator::validate(['x' => 'z'], ['x' => 'in:a,b,c'])->fails());
        // null passes unless required
        self::assertTrue(Validator::validate(['x' => null], ['x' => 'in:a,b,c'])->passes());
    }

    public function test_boolean_rule(): void
    {
        foreach ([true, false, 0, 1, '0', '1'] as $ok) {
            self::assertTrue(Validator::validate(['x' => $ok], ['x' => 'boolean'])->passes(), "boolean accepts {$ok}");
        }
        self::assertTrue(Validator::validate(['x' => 'maybe'], ['x' => 'boolean'])->fails());
    }

    public function test_array_rule(): void
    {
        self::assertTrue(Validator::validate(['x' => [1, 2]], ['x' => 'array'])->passes());
        self::assertTrue(Validator::validate(['x' => 'nope'], ['x' => 'array'])->fails());
    }

    public function test_validated_omits_failed_fields(): void
    {
        $v = Validator::validate(
            ['email' => 'a@b.com', 'name' => ''],
            ['email' => 'required|email', 'name' => 'required|string'],
        );

        self::assertArrayHasKey('name', $v->errors());
        self::assertArrayNotHasKey('name', $v->validated());
        self::assertArrayHasKey('email', $v->validated());
    }

    public function test_make_pulls_input_from_request(): void
    {
        $request = Request::fromWorkerPayload([
            'method' => 'POST',
            'headers' => ['content-type' => ['application/json']],
            'body' => '{"email":"a@b.com","age":"30"}',
        ]);

        $v = Validator::make($request, ['email' => 'required|email', 'age' => 'integer']);

        self::assertTrue($v->passes());
        self::assertSame('a@b.com', $v->validated()['email']);
    }

    public function test_unknown_rule_is_ignored(): void
    {
        // Unknown rule names return null (no error), so validation passes.
        self::assertTrue(Validator::validate(['x' => 'anything'], ['x' => 'mystery_rule'])->passes());
    }
}
