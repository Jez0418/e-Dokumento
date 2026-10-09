<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_text_required(): void
    {
        $v = new Validator(['name' => '']);
        $this->assertNull($v->text('name', 'Name'));
        $this->assertSame(['name' => 'Name is required.'], $v->errors());
    }

    public function test_text_rejects_angle_brackets(): void
    {
        $v = new Validator(['name' => '<b>x']);
        $v->text('name', 'Name');
        $this->assertSame(['name' => 'Name cannot contain < or >.'], $v->errors());
    }

    public function test_text_max_length(): void
    {
        $v = new Validator(['name' => 'abcdef']);
        $v->text('name', 'Name', true, 0, 5);
        $this->assertSame(['name' => 'Name must be 5 characters or fewer.'], $v->errors());
    }

    public function test_name_accepts_letters_periods_apostrophes_hyphens(): void
    {
        $v = new Validator(['first' => "Ma. O'Neil-Cruz", 'second' => 'Ñoño']);
        $this->assertSame("Ma. O'Neil-Cruz", $v->name('first', 'First name'));
        $this->assertSame('Ñoño', $v->name('second', 'First name'));
        $this->assertSame([], $v->errors());
    }

    public function test_name_rejects_digits(): void
    {
        $v = new Validator(['first' => 'J0hn']);
        $v->name('first', 'First name');
        $this->assertSame(['first' => 'First name can only contain letters, spaces, periods, apostrophes and hyphens.'], $v->errors());
    }

    public function test_email_trims_and_lowercases(): void
    {
        $v = new Validator(['email' => ' A@B.CO ']);
        $this->assertSame('a@b.co', $v->email('email'));
        $this->assertSame([], $v->errors());
    }

    public function test_email_rejects_invalid(): void
    {
        $v = new Validator(['email' => 'nope']);
        $v->email('email');
        $this->assertSame(['email' => 'Enter a valid email address.'], $v->errors());
    }

    public function test_phone_strips_spaces_and_hyphens(): void
    {
        $v = new Validator(['m' => '0917-123 4567', 'n' => '+639171234567']);
        $this->assertSame('09171234567', $v->phone('m'));
        $this->assertSame('+639171234567', $v->phone('n'));
        $this->assertSame([], $v->errors());
    }

    public function test_phone_rejects_wrong_prefix(): void
    {
        $v = new Validator(['m' => '08171234567']);
        $v->phone('m');
        $this->assertSame(['m' => 'Mobile number must look like 09171234567 or +639171234567.'], $v->errors());
    }

    public function test_integer_rejects_non_number(): void
    {
        $v = new Validator(['c' => '12a']);
        $this->assertNull($v->integer('c', 'Copies', true, 1, 3));
        $this->assertSame(['c' => 'Copies must be a whole number.'], $v->errors());
    }

    public function test_integer_range(): void
    {
        $v = new Validator(['c' => '5']);
        $this->assertSame(5, $v->integer('c', 'Copies', true, 1, 3));
        $this->assertSame(['c' => 'Copies must be between 1 and 3.'], $v->errors());
    }

    public function test_decimal_strips_commas_and_pads(): void
    {
        $v = new Validator(['a' => '1,250.5']);
        $this->assertSame('1250.50', $v->decimal('a', 'Amount'));
        $this->assertSame([], $v->errors());
    }

    public function test_decimal_rejects_three_places(): void
    {
        $v = new Validator(['a' => '50.123']);
        $this->assertNull($v->decimal('a', 'Amount'));
        $this->assertSame(['a' => 'Amount must be an amount like 50 or 50.00.'], $v->errors());
    }

    public function test_date_rejects_impossible_date(): void
    {
        $v = new Validator(['b' => '2024-02-30']);
        $this->assertNull($v->date('b', 'Birth date'));
        $this->assertSame(['b' => 'Birth date must be a valid date.'], $v->errors());
    }

    public function test_date_not_after(): void
    {
        $v = new Validator(['b' => '2999-01-01']);
        $v->date('b', 'Birth date', true, '2026-10-06');
        $this->assertSame(['b' => 'Birth date cannot be after Oct 6, 2026.'], $v->errors());
    }

    public function test_in_required(): void
    {
        $v = new Validator(['s' => '']);
        $this->assertNull($v->in('s', 'sex', ['male', 'female']));
        $this->assertSame(['s' => 'Choose sex.'], $v->errors());
    }

    public function test_in_rejects_unknown_value(): void
    {
        $v = new Validator(['s' => 'x']);
        $this->assertNull($v->in('s', 'sex', ['male', 'female']));
        $this->assertSame(['s' => 'Choose a valid sex.'], $v->errors());
    }

    public function test_uuid_lowercases(): void
    {
        $v = new Validator(['id' => 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D']);
        $this->assertSame('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', $v->uuid('id', 'Request'));
        $this->assertSame([], $v->errors());
    }

    public function test_password_length(): void
    {
        $v = new Validator(['p' => 'short', 'p2' => 'short']);
        $v->password('p', 'p2');
        $this->assertSame(['p' => 'Password must be 8 to 72 characters.'], $v->errors());
    }

    public function test_password_character_classes(): void
    {
        $v = new Validator(['p' => 'alllowercase1', 'p2' => 'alllowercase1']);
        $v->password('p', 'p2');
        $this->assertSame(['p' => 'Password needs an uppercase letter, a lowercase letter and a number.'], $v->errors());
    }

    public function test_password_confirmation_mismatch(): void
    {
        $v = new Validator(['p' => 'Abcdefg1', 'p2' => 'Abcdefg2']);
        $v->password('p', 'p2');
        $this->assertSame(['p2' => 'The passwords do not match.'], $v->errors());
    }

    public function test_first_error_wins(): void
    {
        $v = new Validator([]);
        $v->error('f', 'one');
        $v->error('f', 'two');
        $this->assertSame(['f' => 'one'], $v->errors());
    }
}
