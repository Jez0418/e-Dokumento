<?php
declare(strict_types=1);

/**
 * Number formats for the accepted ID types. Types are matched by name (the list is editable
 * under Reference), and anything unrecognised falls back to a plain letters-and-digits rule.
 * Input is compared with spaces and dashes removed, upper-cased; groups re-insert dashes.
 */
final class IdFormat
{
    /** @return list<array{key:string,match:string,re:string,groups:?list<int>,example:string,hint:string}> */
    private static function rules(): array
    {
        return [
            ['key' => 'philsys',  'match' => 'philsys|national id', 're' => '^\d{16}$',                'groups' => [4, 4, 4, 4], 'example' => '1234-5678-9012-3456', 'hint' => '16 digits (PhilSys Card Number), like 1234-5678-9012-3456.'],
            ['key' => 'driver',   'match' => 'driver',              're' => '^[A-Z]\d{10}$',           'groups' => [3, 2, 6],    'example' => 'N01-23-456789',       'hint' => 'One letter then 10 digits, like N01-23-456789.'],
            ['key' => 'passport', 'match' => 'passport',            're' => '^[A-Z]{1,2}\d{7}[A-Z]?$', 'groups' => null,         'example' => 'P1234567A',           'hint' => 'Letter(s), 7 digits, then an optional letter, like P1234567A.'],
            ['key' => 'umid',     'match' => 'umid',                're' => '^\d{12}$',                'groups' => [4, 7, 1],    'example' => '0028-1234567-8',      'hint' => '12 digits (CRN), like 0028-1234567-8.'],
            ['key' => 'sss',      'match' => 'sss',                 're' => '^\d{10}$',                'groups' => [2, 7, 1],    'example' => '34-1234567-8',        'hint' => '10 digits, like 34-1234567-8.'],
            ['key' => 'postal',   'match' => 'postal',              're' => '^[A-Z0-9]{12}$',          'groups' => null,         'example' => 'ABC123456789',        'hint' => '12 letters or digits (the PRN printed on the card).'],
            ['key' => 'voter',    'match' => 'voter',               're' => '^[A-Z0-9]{8,20}$',        'groups' => null,         'example' => '1234-5678-9012-3456', 'hint' => '8 to 20 letters or digits, as printed on the ID or certification.'],
            ['key' => 'prc',      'match' => 'prc',                 're' => '^\d{7}$',                 'groups' => null,         'example' => '0123456',             'hint' => '7 digits, like 0123456.'],
            ['key' => 'pwd',      'match' => 'pwd',                 're' => '^\d{16}$',                'groups' => [2, 4, 3, 7], 'example' => '13-7404-000-0000001', 'hint' => '16 digits, like 13-7404-000-0000001.'],
            ['key' => 'senior',   'match' => 'senior',              're' => '^[A-Z0-9]{4,20}$',        'groups' => null,         'example' => '2024-00123',          'hint' => '4 to 20 letters or digits, as printed on the ID.'],
            ['key' => 'school',   'match' => 'school',              're' => '^[A-Z0-9]{3,20}$',        'groups' => null,         'example' => '2021-12345',          'hint' => '3 to 20 letters or digits, as printed on the school ID.'],
        ];
    }

    /** @return array{key:string,re:string,groups:?list<int>,example:string,hint:string} */
    public static function forName(string $typeName): array
    {
        foreach (self::rules() as $r) {
            if (preg_match('/' . $r['match'] . '/i', $typeName) === 1) {
                return $r;
            }
        }
        return ['key' => 'other', 'match' => '', 're' => '^[A-Z0-9]{3,40}$', 'groups' => null, 'example' => '', 'hint' => 'Letters and digits only, 3 to 40 characters.'];
    }

    public static function compact(string $number): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $number));
    }

    /** @return array{0:?string,1:?string} [normalised number, error] */
    public static function check(string $typeName, string $number): array
    {
        $rule = self::forName($typeName);
        $c = self::compact($number);
        if ($c === '' || preg_match('/' . $rule['re'] . '/', $c) !== 1) {
            return [null, 'ID number does not match the format of ' . $typeName . '. ' . $rule['hint']];
        }
        if ($rule['groups']) {
            $parts = [];
            $at = 0;
            foreach ($rule['groups'] as $len) {
                $parts[] = substr($c, $at, $len);
                $at += $len;
            }
            return [implode('-', $parts), null];
        }
        return [$c, null];
    }

    /** Rules for the browser, keyed by id type id: re (JS-safe), hint, example. */
    public static function forBrowser(array $idTypes): array
    {
        $out = [];
        foreach ($idTypes as $t) {
            $r = self::forName((string) $t['name']);
            $out[(string) $t['id']] = ['re' => $r['re'], 'hint' => $r['hint'], 'example' => $r['example']];
        }
        return $out;
    }
}
