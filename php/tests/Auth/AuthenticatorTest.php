<?php

declare(strict_types=1);

namespace Fium\Tests\Auth;

use Fium\Auth\Authenticator;
use Fium\Auth\NullUserStore;
use Fium\Auth\User;
use Fium\Session\Session;
use Fium\Tests\Fixtures\InMemoryUserStore;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Authenticator $auth;
    private InMemoryUserStore $store;

    protected function setUp(): void
    {
        // Hash once per suite-equivalent; bcrypt is intentionally slow.
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        assert(is_string($hash));

        $this->store = new InMemoryUserStore();
        $this->store->seed([
            'id' => 1,
            'email' => 'ada@example.com',
            'password_hash' => $hash,
            'role' => 'admin',
            'email_verified_at' => null,
            'token_version' => 0,
        ]);

        $this->auth = Authenticator::fromStore($this->store);
    }

    public function test_validate_credentials_success_and_failure(): void
    {
        $user = $this->auth->validateCredentials(['email' => 'Ada@Example.com', 'password' => self::PASSWORD]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('ada@example.com', $user->email());
        self::assertSame('admin', $user->role());

        // Wrong password
        self::assertNull($this->auth->validateCredentials(['email' => 'ada@example.com', 'password' => 'nope']));
        // Unknown user
        self::assertNull($this->auth->validateCredentials(['email' => 'ghost@example.com', 'password' => self::PASSWORD]));
        // Missing fields
        self::assertNull($this->auth->validateCredentials(['email' => '', 'password' => '']));
    }

    public function test_attempt_sets_session_user_and_regenerates(): void
    {
        $session = new Session('original-id', [], false, false);

        $user = $this->auth->attempt(
            ['email' => 'ada@example.com', 'password' => self::PASSWORD],
            $session,
            true,
        );

        self::assertInstanceOf(User::class, $user);
        self::assertSame($user->id(), $session->get('auth_user_id'));
        self::assertTrue($session->needsRegeneration());
        self::assertTrue($session->remembers());
    }

    public function test_attempt_returns_null_without_mutating_session(): void
    {
        $session = new Session('sid', [], false, false);

        $user = $this->auth->attempt(['email' => 'ada@example.com', 'password' => 'wrong'], $session);

        self::assertNull($user);
        self::assertNull($session->get('auth_user_id'));
    }

    public function test_register_creates_user_and_optionally_logs_in(): void
    {
        $session = new Session('sid', [], false, false);

        $user = $this->auth->register(
            ['email' => 'new@example.com', 'password' => 'a-strong-secret'],
            $session,
        );

        self::assertInstanceOf(User::class, $user);
        self::assertSame(2, $user->id());
        self::assertSame($user->id(), $session->get('auth_user_id'));

        // Duplicate registration returns null.
        self::assertNull($this->auth->register(['email' => 'NEW@example.com', 'password' => 'x']));
        // Missing fields return null.
        self::assertNull($this->auth->register(['email' => '', 'password' => '']));
    }

    public function test_user_by_id_and_email(): void
    {
        self::assertInstanceOf(User::class, $this->auth->userById(1));
        self::assertNull($this->auth->userById(99));
        self::assertInstanceOf(User::class, $this->auth->userByEmail('ada@example.com'));
    }

    public function test_user_from_session_resolves_user(): void
    {
        $session = new Session('sid', ['auth_user_id' => 1], false, false);
        self::assertInstanceOf(User::class, $this->auth->userFromSession($session));

        $empty = new Session('sid', [], false, false);
        self::assertNull($this->auth->userFromSession($empty));
    }

    public function test_logout_clears_session(): void
    {
        $session = new Session('sid', ['auth_user_id' => 1], false, false);
        $this->auth->logout($session);

        self::assertNull($session->get('auth_user_id'));
        self::assertTrue($session->needsRegeneration());
    }

    public function test_update_password_revokes_tokens(): void
    {
        $user = $this->auth->userById(1);
        assert($user !== null);

        $updated = $this->auth->updatePassword($user, 'brand-new-password');

        self::assertNotNull($updated);
        self::assertSame(1, $updated->tokenVersion(), 'token version bumped to revoke issued tokens');
    }

    public function test_mark_email_verified_is_idempotent(): void
    {
        $user = $this->auth->userById(1);
        assert($user !== null);

        $verified = $this->auth->markEmailVerified($user);
        self::assertTrue($verified->hasVerifiedEmail());

        $again = $this->auth->markEmailVerified($verified);
        self::assertSame($verified->emailVerifiedAt(), $again->emailVerifiedAt());
    }

    public function test_revoke_tokens_bumps_version(): void
    {
        $user = $this->auth->userById(1);
        assert($user !== null);

        self::assertSame(1, $this->auth->revokeTokens($user)->tokenVersion());
    }

    public function test_capabilities_depend_on_store_mutability(): void
    {
        // Mutable store -> all capabilities true.
        self::assertTrue($this->auth->canRegister());
        self::assertTrue($this->auth->canResetPasswords());
        self::assertTrue($this->auth->canVerifyEmail());
        self::assertTrue($this->auth->canRevokeTokens());

        // Immutable (null) store -> all capabilities false.
        $readonly = Authenticator::empty();
        self::assertFalse($readonly->canRegister());
        self::assertFalse($readonly->canResetPasswords());
        self::assertFalse($readonly->canVerifyEmail());
        self::assertFalse($readonly->canRevokeTokens());
    }

    public function test_register_returns_null_with_immutable_store(): void
    {
        $readonly = Authenticator::empty();
        self::assertNull($readonly->register(['email' => 'x@y.com', 'password' => 'z']));
    }
}
