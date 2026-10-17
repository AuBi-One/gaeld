<?php

namespace Plugins\Offers\Support;

/**
 * Which elements the From box (organisation) and the To box (client or
 * contact person) of the offer document show. Names are always shown.
 */
final class Layout
{
    public const DEFAULT = [
        'from' => ['logo' => true, 'address' => true, 'email' => true, 'phone' => true],
        'to' => ['address' => true, 'email' => false, 'phone' => false],
    ];

    /**
     * @param  array<string, mixed>|null  $layout
     * @return array{from: array{logo: bool, address: bool, email: bool, phone: bool}, to: array{address: bool, email: bool, phone: bool}}
     */
    public static function normalize(?array $layout): array
    {
        $result = self::DEFAULT;
        foreach (self::DEFAULT as $box => $elements) {
            foreach (array_keys($elements) as $element) {
                if (isset($layout[$box][$element])) {
                    $result[$box][$element] = (bool) $layout[$box][$element];
                }
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'layout' => ['nullable', 'array'],
            'layout.from.logo' => ['boolean'],
            'layout.from.address' => ['boolean'],
            'layout.from.email' => ['boolean'],
            'layout.from.phone' => ['boolean'],
            'layout.to.address' => ['boolean'],
            'layout.to.email' => ['boolean'],
            'layout.to.phone' => ['boolean'],
        ];
    }
}
