<?php

declare(strict_types=1);

namespace Wayfinder\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Wayfinder\Http\ValidationException;
use Wayfinder\Tests\Concerns\MakesRequests;
use Wayfinder\Tests\Concerns\UsesDatabase;

final class ValidationTest extends TestCase
{
    use MakesRequests;
    use UsesDatabase;

    protected function setUp(): void
    {
        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function validate(array $rules, array $data, array $messages = [], string $bag = 'default'): array
    {
        return $this->makeRequest('POST', '/', $data)->validate($rules, $messages, $bag);
    }

    /** @param array<string, mixed> $data */
    private function assertValidationFails(array $rules, array $data, string $field, string $containing = ''): void
    {
        try {
            $this->validate($rules, $data);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->errors(), "Expected error for field [{$field}].");
            if ($containing !== '') {
                $messages = implode(' ', $e->errors()[$field]);
                self::assertStringContainsStringIgnoringCase($containing, $messages);
            }
        }
    }

    // -------------------------------------------------------------------------
    // required
    // -------------------------------------------------------------------------

    public function testRequiredFailsWhenFieldMissing(): void
    {
        $this->assertValidationFails(['name' => 'required'], [], 'name');
    }

    public function testRequiredFailsOnEmptyString(): void
    {
        $this->assertValidationFails(['name' => 'required'], ['name' => ''], 'name');
    }

    public function testRequiredPassesWithValue(): void
    {
        $validated = $this->validate(['name' => 'required'], ['name' => 'Ron']);
        self::assertSame('Ron', $validated['name']);
    }

    public function testRequiredFailsOnEmptyArray(): void
    {
        $this->assertValidationFails(['items' => 'required|array'], ['items' => []], 'items');
    }

    // -------------------------------------------------------------------------
    // nullable
    // -------------------------------------------------------------------------

    public function testNullableAllowsEmptyAndStoresNull(): void
    {
        $validated = $this->validate(['bio' => 'nullable'], ['bio' => '']);
        self::assertArrayHasKey('bio', $validated);
        self::assertNull($validated['bio']);
    }

    public function testNullablePassesWithValue(): void
    {
        $validated = $this->validate(['bio' => 'nullable|string'], ['bio' => 'Hello']);
        self::assertSame('Hello', $validated['bio']);
    }

    // -------------------------------------------------------------------------
    // Type rules
    // -------------------------------------------------------------------------

    public function testStringRulePassesForString(): void
    {
        $validated = $this->validate(['name' => 'required|string'], ['name' => 'Ron']);
        self::assertSame('Ron', $validated['name']);
    }

    public function testStringRuleFailsForNonString(): void
    {
        $this->assertValidationFails(['items' => 'required|string'], ['items' => ['an', 'array']], 'items');
    }

    public function testIntegerRulePassesForInteger(): void
    {
        $validated = $this->validate(['age' => 'required|integer'], ['age' => '25']);
        self::assertSame('25', $validated['age']);
    }

    public function testIntegerRuleFailsForNonInteger(): void
    {
        $this->assertValidationFails(['age' => 'required|integer'], ['age' => 'abc'], 'age');
    }

    public function testIntegerRuleFailsForFloat(): void
    {
        $this->assertValidationFails(['age' => 'required|integer'], ['age' => '3.14'], 'age');
    }

    public function testNumericRulePassesForFloat(): void
    {
        $validated = $this->validate(['price' => 'required|numeric'], ['price' => '9.99']);
        self::assertSame('9.99', $validated['price']);
    }

    public function testNumericRuleFailsForNonNumeric(): void
    {
        $this->assertValidationFails(['price' => 'required|numeric'], ['price' => 'abc'], 'price');
    }

    public function testBooleanRulePassesForTrueFalse(): void
    {
        $validated = $this->validate(['active' => 'required|boolean'], ['active' => 'true']);
        self::assertSame('true', $validated['active']);
    }

    public function testBooleanRuleFailsForArbitraryString(): void
    {
        $this->assertValidationFails(['active' => 'required|boolean'], ['active' => 'yes-please'], 'active');
    }

    public function testArrayRulePassesForArray(): void
    {
        $validated = $this->validate(['tags' => 'required|array'], ['tags' => ['php', 'wayfinder']]);
        self::assertSame(['php', 'wayfinder'], $validated['tags']);
    }

    public function testArrayRuleFailsForString(): void
    {
        $this->assertValidationFails(['tags' => 'required|array'], ['tags' => 'php'], 'tags');
    }

    // -------------------------------------------------------------------------
    // Format rules
    // -------------------------------------------------------------------------

    public function testEmailRulePassesForValidEmail(): void
    {
        $validated = $this->validate(['email' => 'required|email'], ['email' => 'ron@example.com']);
        self::assertSame('ron@example.com', $validated['email']);
    }

    public function testEmailRuleFailsForInvalidEmail(): void
    {
        $this->assertValidationFails(['email' => 'required|email'], ['email' => 'not-an-email'], 'email');
    }

    public function testUrlRulePassesForValidUrl(): void
    {
        $validated = $this->validate(['site' => 'required|url'], ['site' => 'https://example.com']);
        self::assertSame('https://example.com', $validated['site']);
    }

    public function testUrlRuleFailsForInvalidUrl(): void
    {
        $this->assertValidationFails(['site' => 'required|url'], ['site' => 'not a url'], 'site');
    }

    public function testDateRulePassesForValidDate(): void
    {
        $validated = $this->validate(['dob' => 'required|date'], ['dob' => '1990-01-15']);
        self::assertSame('1990-01-15', $validated['dob']);
    }

    public function testDateRuleFailsForInvalidDate(): void
    {
        $this->assertValidationFails(['dob' => 'required|date'], ['dob' => 'not-a-date'], 'dob');
    }

    // -------------------------------------------------------------------------
    // min / max
    // -------------------------------------------------------------------------

    public function testMinRuleForStringLength(): void
    {
        $this->assertValidationFails(['name' => 'required|min:5'], ['name' => 'Ron'], 'name');
    }

    public function testMinRulePassesForStringLength(): void
    {
        $validated = $this->validate(['name' => 'required|min:3'], ['name' => 'Ron']);
        self::assertSame('Ron', $validated['name']);
    }

    public function testMinRuleForNumericValue(): void
    {
        $this->assertValidationFails(['age' => 'required|numeric|min:18'], ['age' => '16'], 'age');
    }

    public function testMinRulePassesForNumericValue(): void
    {
        $validated = $this->validate(['age' => 'required|numeric|min:18'], ['age' => '18']);
        self::assertSame('18', $validated['age']);
    }

    public function testMinRuleForArrayCount(): void
    {
        $this->assertValidationFails(['items' => 'required|array|min:3'], ['items' => ['a', 'b']], 'items');
    }

    public function testMaxRuleForStringLength(): void
    {
        $this->assertValidationFails(['name' => 'required|max:3'], ['name' => 'Alexander'], 'name');
    }

    public function testMaxRulePassesForStringLength(): void
    {
        $validated = $this->validate(['name' => 'required|max:10'], ['name' => 'Ron']);
        self::assertSame('Ron', $validated['name']);
    }

    public function testMaxRuleUsesStringLengthWhenStringRuleIsPresentOnNumericLookingValue(): void
    {
        $validated = $this->validate(['postal_code' => 'required|string|max:20'], ['postal_code' => '90210']);
        self::assertSame('90210', $validated['postal_code']);
    }

    public function testMaxRuleFailsOnStringLengthWhenStringRuleIsPresentOnNumericLookingValue(): void
    {
        $this->assertValidationFails(
            ['postal_code' => 'required|string|max:5'],
            ['postal_code' => '123456'],
            'postal_code',
        );
    }

    public function testMaxRuleForNumericValue(): void
    {
        $this->assertValidationFails(['score' => 'required|numeric|max:100'], ['score' => '101'], 'score');
    }

    public function testMaxRuleForArrayCount(): void
    {
        $this->assertValidationFails(['tags' => 'required|array|max:2'], ['tags' => ['a', 'b', 'c']], 'tags');
    }

    // -------------------------------------------------------------------------
    // confirmed / same
    // -------------------------------------------------------------------------

    public function testConfirmedRulePassesWhenConfirmationMatches(): void
    {
        $validated = $this->validate(
            ['password' => 'required|confirmed'],
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
        );
        self::assertSame('secret123', $validated['password']);
    }

    public function testConfirmedRuleFailsWhenConfirmationMismatch(): void
    {
        $this->assertValidationFails(
            ['password' => 'required|confirmed'],
            ['password' => 'secret123', 'password_confirmation' => 'different'],
            'password',
        );
    }

    public function testConfirmedRuleFailsWhenConfirmationMissing(): void
    {
        $this->assertValidationFails(
            ['password' => 'required|confirmed'],
            ['password' => 'secret123'],
            'password',
        );
    }

    public function testSameRulePassesWhenFieldsMatch(): void
    {
        $validated = $this->validate(
            ['email' => 'required', 'email_check' => 'required|same:email'],
            ['email' => 'ron@example.com', 'email_check' => 'ron@example.com'],
        );
        self::assertSame('ron@example.com', $validated['email_check']);
    }

    public function testSameRuleFailsWhenFieldsDiffer(): void
    {
        $this->assertValidationFails(
            ['email' => 'required', 'email_check' => 'required|same:email'],
            ['email' => 'ron@example.com', 'email_check' => 'other@example.com'],
            'email_check',
        );
    }

    // -------------------------------------------------------------------------
    // exists / unique (require DB)
    // -------------------------------------------------------------------------

    public function testExistsRulePassesWhenValueInDb(): void
    {
        $this->db->statement("INSERT INTO users (email) VALUES ('ron@example.com')");

        $validated = $this->validate(
            ['email' => 'required|exists:users,email'],
            ['email' => 'ron@example.com'],
        );
        self::assertSame('ron@example.com', $validated['email']);
    }

    public function testExistsRuleFailsWhenValueNotInDb(): void
    {
        $this->assertValidationFails(
            ['email' => 'required|exists:users,email'],
            ['email' => 'ghost@example.com'],
            'email',
        );
    }

    public function testUniqueRulePassesWhenValueNotInDb(): void
    {
        $validated = $this->validate(
            ['email' => 'required|unique:users,email'],
            ['email' => 'newuser@example.com'],
        );
        self::assertSame('newuser@example.com', $validated['email']);
    }

    public function testUniqueRuleFailsWhenValueAlreadyInDb(): void
    {
        $this->db->statement("INSERT INTO users (email) VALUES ('taken@example.com')");

        $this->assertValidationFails(
            ['email' => 'required|unique:users,email'],
            ['email' => 'taken@example.com'],
            'email',
        );
    }

    // -------------------------------------------------------------------------
    // Custom messages
    // -------------------------------------------------------------------------

    public function testCustomRequiredMessage(): void
    {
        try {
            $this->validate(['name' => 'required'], [], ['name.required' => 'Please enter your name.']);
            $this->fail('Expected exception.');
        } catch (ValidationException $e) {
            self::assertSame(['Please enter your name.'], $e->errors()['name']);
        }
    }

    public function testCustomMinMessage(): void
    {
        try {
            $this->validate(
                ['password' => 'required|min:8'],
                ['password' => 'short'],
                ['password.min' => 'Password must be at least 8 characters.'],
            );
            $this->fail('Expected exception.');
        } catch (ValidationException $e) {
            self::assertSame(['Password must be at least 8 characters.'], $e->errors()['password']);
        }
    }

    // -------------------------------------------------------------------------
    // Named error bags
    // -------------------------------------------------------------------------

    public function testNamedBagIsSetOnException(): void
    {
        try {
            $this->makeRequest('POST', '/', ['email' => ''])->validate(
                ['email' => 'required'],
                [],
                'login',
            );
            $this->fail('Expected exception.');
        } catch (ValidationException $e) {
            self::assertSame('login', $e->bag());
        }
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testMultipleRuleFailuresCollected(): void
    {
        try {
            $this->validate(
                ['email' => 'required|email', 'name' => 'required|min:3'],
                ['email' => 'bad', 'name' => 'X'],
            );
            $this->fail('Expected exception.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    public function testArrayRulesSyntaxEquivalentToPipeSyntax(): void
    {
        $pipe = $this->validate(['name' => 'required|string|min:2'], ['name' => 'Ron']);
        $array = $this->validate(['name' => ['required', 'string', 'min:2']], ['name' => 'Ron']);

        self::assertSame($pipe, $array);
    }

    public function testUnknownFieldsNotIncludedInValidated(): void
    {
        $validated = $this->validate(
            ['name' => 'required'],
            ['name' => 'Ron', 'extra' => 'injected'],
        );

        self::assertArrayNotHasKey('extra', $validated);
    }
}
