<?php

declare(strict_types=1);

namespace Core;

class Dates
{
    public static function date($date_in)
    {
        return date('d/m/Y', strtotime($date_in));
    }

    public static function time($time_in)
    {
        return date('H:i', strtotime($time_in));
    }

    public static function datetime($datetime_in)
    {
        return date('d/m/Y H:i', strtotime($datetime_in));
    }

    public static function datetimeCat($datetime_in)
    {
        return date('Ymd_Hi', strtotime($datetime_in));
    }

    public static function datetimeHuman($datetime_in)
    {
        $d = explode(' ', $datetime_in);
        $date = explode('-', $d[0]);
        $time = explode(':', $d[1]);

        return $date[2] . '/' . $date[1] . '/' . $date[0] . ' <small> alle: ' . $time[0] . ':' . $time[1] . '</small>';
    }

    public static function datetimeHumanShort($datetime_in)
    {
        $d = explode(' ', $datetime_in);
        $date = explode('-', $d[0]);
        $time = explode(':', $d[1]);

        return $date[2] . '/' . $date[1] . '/' . $date[0] . ' <small> ' . $time[0] . ':' . $time[1] . '</small>';
    }
}
