<?php

declare(strict_types=1);

namespace Fium\Tests\Session;

use Fium\Session\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_put_and_get_mark_dirty(): void
    {
        $session = new Session('sid', [], false, true);

        self::assertFalse($session->isDirty());
        self::assertNull($session->get('missing'));

        $session->put('name', 'Ada');
        self::assertTrue($session->isDirty());
        self::assertSame('Ada', $session->get('name'));
        self::assertSame('default', $session->get('missing', 'default'));
    }

    public function test_forget_clears_key(): void
    {
        $session = new Session('sid', ['name' => 'Ada'], false, false);
        $session->forget('name');

        self::assertNull($session->get('name'));
        self::assertTrue($session->isDirty());
        self::assertArrayNotHasKey('name', $session->all());
    }

    public function test_remember_and_forget_remember(): void
    {
        $session = new Session('sid', [], false, false);

        self::assertFalse($session->remembers());
        $session->remember();
        self::assertTrue($session->remembers());

        $session->forgetRemember();
        self::assertFalse($session->remembers());

        // Forgetting when not set is a no-op (no dirty flag flip from this alone beyond constructor state).
        $fresh = new Session('sid', [], false, false);
        $fresh->forgetRemember();
        self::assertFalse($fresh->isDirty());
    }

    public function test_regenerate_sets_flag(): void
    {
        $session = new Session('sid', [], false, false);
        self::assertFalse($session->needsRegeneration());
        $session->regenerate();
        self::assertTrue($session->needsRegeneration());
    }

    public function test_rotate_to_changes_id_and_tracks_previous(): void
    {
        $session = new Session('old-id', [], false, false);

        $session->rotateTo('new-id');

        self::assertSame('new-id', $session->id());
        self::assertSame('old-id', $session->previousId());
        self::assertTrue($session->isNew());
        self::assertFalse($session->needsRegeneration());
        self::assertTrue($session->isDirty());

        $session->clearPreviousId();
        self::assertNull($session->previousId());
    }

    public function test_rotate_to_same_id_clears_regeneration_flag_only(): void
    {
        $session = new Session('same-id', [], false, false);
        $session->regenerate();
        $session->rotateTo('same-id');

        self::assertSame('same-id', $session->id());
        self::assertNull($session->previousId());
        self::assertFalse($session->needsRegeneration());
    }

    public function test_flash_data_survives_one_aging_then_is_removed(): void
    {
        // Flash lifecycle: flash in request N -> still present after the first
        // ageFlashData (moves new->old) -> removed on the second ageFlashData.
        $session = new Session('sid', [], false, false);

        $session->flash('status', 'saved');
        self::assertSame('saved', $session->get('status'));

        $session->ageFlashData();
        self::assertSame('saved', $session->get('status'), 'flash still visible after first aging');

        $session->ageFlashData();
        self::assertNull($session->get('status'), 'flash removed after second aging');
    }

    public function test_flash_persists_across_requests_then_expires(): void
    {
        // Request N: flash a value and persist it.
        $session = new Session('sid', [], false, false);
        $session->flash('status', 'saved');

        // Request N+1: reload from persisted payload, still visible after aging.
        $restored = new Session('sid', $session->all(), false, false);
        self::assertSame('saved', $restored->get('status'));
        $restored->ageFlashData();
        self::assertSame('saved', $restored->get('status'));

        // Request N+2: reload and age -> expired.
        $expired = new Session('sid', $restored->all(), false, false);
        $expired->ageFlashData();
        self::assertNull($expired->get('status'));
    }
}
