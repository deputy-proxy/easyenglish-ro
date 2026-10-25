<?php

namespace App\Support;

class PhoneNumber
{
    /** Normalize Romanian local and international numbers to a stable +digits representation. */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '40'.substr($digits, 1);
        } elseif (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '40'.$digits;
        }

        return $digits === '' ? '' : '+'.$digits;
    }
}
