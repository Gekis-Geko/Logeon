<?php

declare(strict_types=1);

namespace Core;

class Filter
{
    public static function html($str)
    {
        return $str = htmlentities(trim($str), ENT_QUOTES, 'utf-8');
    }

    public static function plainString($str)
    {
        return trim(strip_tags(self::string($str)));
    }

    public static function string($str)
    {
        return trim(self::encodeToUTF8($str));
    }

    public static function number($str)
    {
        return str_replace(',', '.', (self::plainString($str)));
    }

    public static function url($str, $full = false)
    {
        return $str = (true == $full) ? urlencode($str) : filter_var(str_replace(' ', '%20', $str), FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED);
    }

    public static function hash($str)
    {
        return md5($str);
    }

    public static function htmlEntities($str)
    {
        $notAllowed = [
            "#(<script.*?>.*?(<\/script>)?)#is" => '<div class="alert alert-warning">Script non consentiti</div>',
            "#(<iframe.*?\/?>.*?(<\/iframe>)?)#is" => '<div class="alert alert-warning">iFrame non consentiti</div>',
            "#(<object.*?>.*?(<\/object>)?)#is" => '<div class="alert alert-warning">Contenuti multimediali non consentiti</div>',
            "#(<embed.*?\/?>.*?(<\/embed>)?)#is" => '<div class="alert alert-warning">Contenuti multimediali non consentiti</div>',
            "#( on[a-zA-Z]+=\"?'?[^\s\"']+'?\"?)#is" => '',
            "#(javascript:[^\s\"']+)#is" => '',
            "#(<img.*?\/?>)#is" => 'Immagini non consentite',
            "#(url\(.*?\))#is" => 'none',
        ];

        return preg_replace(array_keys($notAllowed), array_values($notAllowed), $str);
    }

    public static function encodeToUTF8($str)
    {
        $encoding_ordered_list = [
            'UTF-8',
            'ISO-8859-1',
            'Windows-1251',

            'ISO-8859-16',
            'ISO-8859-15',
            'ISO-8859-14',
            'ISO-8859-13',
            'ISO-8859-12',
            'ISO-8859-11',
            'ISO-8859-10',
            'ISO-8859-9',
            'ISO-8859-8',
            'ISO-8859-7',
            'ISO-8859-6',
            'ISO-8859-5',
            'ISO-8859-4',
            'ISO-8859-3',
            'ISO-8859-2',

            'Windows-1254',
            'Windows-1252',

            'UTF-7',
            'UTF7-IMAP',
            'UTF-16',
            'UTF-16BE',
            'UTF-16LE',
            'UTF-32',
            'UTF-32BE',
            'UTF-32LE',

            'ASCII',
        ];
        $encoding_list = array_intersect($encoding_ordered_list, mb_list_encodings());

        $encoding = mb_detect_encoding($str, $encoding_list, true);
        if (!$encoding) {
            return iconv('UTF-8', 'UTF-8//IGNORE', $str);
        }
        return mb_convert_encoding($str, 'UTF-8', $encoding);
    }

    public static function checkNumber($str)
    {
        $apex = '';
        if (!is_int($str)) {
            $apex = "'";
        }

        return $apex;
    }
}
