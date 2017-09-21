<?php
/**
 * Created by PhpStorm.
 * User: chuxiaofeng
 * Date: 16/10/3
 * Time: 上午0:14
 *
 * 文档: http://crontab.org/
 * 移植自嵌入式linux实现: https://git.busybox.net/busybox/tree/miscutils/crond.c?h=1_25_stable
 */
namespace Zan\Framework\Components\JobServer;


use Zan\Framework\Foundation\Exception\ZanException;

final class Crontab
{
    private static $charlist = "1234567890,/*-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    private static $dayOfWeekNames = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];
    private static $monthNames = ["jan","feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"];

                            // http://php.net/manual/en/function.date.php
    private $seconds;       // s	Seconds, with leading zeros	                                0 through 59    intval(00 through 59)
    private $minutes;       // i	Minutes with leading zeros	                                0 to 59         intval(00 to 59)
    private $hours;         // G	24-hour format of an hour without leading zeros	            0 through 23
    private $days;          // j	Day of the month without leading zeros	                    1 to 31
    private $months;        // n	Numeric representation of a month, without leading zeros	1 through 12
    private $dayOfWeeks;    // w	Numeric representation of the day of the week	            0 (for Sunday) through 6 (for Saturday)
                            // date format : s i G j n w 

    private $crontab;

    private function __construct() { }

    public function getCrontab()
    {
        return $this->crontab;
    }

    /**
     * 检测时间戳是否在cron表达式内
     * @param int $ts Timestamp
     * @return bool
     */
    public function isInTime($ts)
    {
        list($sec, $min, $hour, $day, $mon, $dow) = array_map("intval", explode(" ", date("s i G j n w", $ts)));

        return $this->seconds[$sec] &&
            $this->minutes[$min] &&
            $this->hours[$hour] &&
            ($this->days[$day] || $this->dayOfWeeks[$dow]) &&
            $this->months[$mon];
    }

    /**
     * @param $str
     * @return static
     * @throws CrontabParseException
     *
     *       *      *       *        *        *      *
     *      sec    min    hour   day/month  month day/week
     *      0-59   0-59   0-23     1-31     1-12    0-6
     */
    public static function parse($str)
    {
        $cron = new static;

        $cron->crontab = static::parseLine($str);

        list($seconds, $minutes, $hours, $days, $months, $weeks) = $cron->crontab;

        $cron->seconds      = static::parseField($seconds, 60);
        $cron->minutes      = static::parseField($minutes, 60);
        $cron->hours        = static::parseField($hours, 24);
        $cron->days         = static::parseField($days, 31, 1);
        $cron->months       = static::parseField($months, 12, 1, static::$monthNames);
        $cron->dayOfWeeks   = static::parseField($weeks, 7, 0, static::$dayOfWeekNames);

        static::fixDayDow($cron);

        return $cron;
    }

    /**
     * 修复days和day-of-week其一为严格状态(非*)下的冲突
     * @param Crontab $cron
     */
    private static function fixDayDow(Crontab $cron)
    {
        // 这里不能用 === "*" 判断, 可能是 */1 或者 1,2,3,4,5....
        $dayRestricted = array_search(0, $cron->days, true) !== false;
        $weekRestricted = array_search(0, $cron->dayOfWeeks, true) !== false;

        if ($dayRestricted && $weekRestricted) {
            // day与week都为严格格式
        } else if ($dayRestricted) {
            $cron->dayOfWeeks = static::fillZero(7);
        } else if ($weekRestricted) {
            $cron->days = static::fillZero(31, 1);
        }
    }

    private static function parseLine($str)
    {
        $str_ = $str;
        $token = [];

        $len = strlen($str);
        $i = 0;
        while ($len > 0 && $i < 6) {
            $fieldLen = strspn($str, static::$charlist);
            $token[$i++] = substr($str, 0, $fieldLen);
            $str = strpbrk(substr($str, $fieldLen), static::$charlist);
            $len = strlen($str); // strlen(false) === 0
        }

        if ($i !== 6) {
            throw new CrontabParseException("Invalid crontab line: $str_");
        }

        return $token;
    }
    
    private static function parseField($str, $modValue, $offset = 0, array $names = [])
    {
        $str_ = $str;
        $array = static::fillZero($modValue, $offset);

        $rangeLeft = -1;
        $rangeRight = -1;

        while (true) {

            $step = 0;

            switch (true) {

                case $str[0] === "*":
                    // 全部填充
                    $rangeLeft = $offset;
                    $rangeRight = $modValue + $offset - 1;
                    $step = 1;
                    $str = substr($str, 1);
                    break;

                case ctype_digit($str[0]):
                    if ($rangeLeft < 0) {
                        $rangeLeft = static::strtol($str, $str);
                    } else {
                        $rangeRight = static::strtol($str, $str);
                    }
                    $step = 1;
                    break;

                case $names:
                    foreach ($names as $i => $name) {
                        // crontab 语法中月份与星期三个字母缩写表示
                        if (strncasecmp($str, $name, 3) === 0) {
                            if ($rangeLeft < 0) {
                                $rangeLeft = $i + $offset;
                            } else {
                                $rangeRight = $i + $offset;
                            }
                            $str = substr($str, 3);
                            $step = 1;
                            break;
                        }
                    }
                    break;
            }

            if ($step === 0) {
                throw new CrontabParseException("Invalid crontab field \"$str_\": unrecognized char \"{$str[0]}\" or unrecognized name \"$str\"");
            }

            // 处理可选的范围符号 '-'
            if (isset($str[0]) && $str[0] === "-" && $rangeRight < 0) {
                // 处理右侧范围
                $str = substr($str, 1);
                continue;
            }

            // 把单个值展开成范围
            if ($rangeRight < 0) {
                $rangeRight = $rangeLeft;
            }

            // 修正step
            if (isset($str[0]) && $str[0] === "/") {
                $step = static::strtol(substr($str, 1), $str);
            }


            // 填充数组
            $tmpStep = 1;
            $failSafe = 1024;

            --$rangeLeft;
            do {
                $rangeLeft = ($rangeLeft + 1) % ($modValue + $offset);

                if (--$tmpStep === 0) {
                    $array[$rangeLeft % ($modValue + $offset)] = 1;
                    $tmpStep = $step;
                }

                if (--$failSafe === 0) {
                    throw new CrontabParseException("Invalid crontab field \"$str_\": error field number");
                }

            } while ($rangeLeft !== $rangeRight);
            

            if (isset($str[0]) && $str[0] === ",") {
                $str = substr($str, 1);
                $rangeLeft = -1;
                $rangeRight = -1;
            } else {
                break;
            }
        }

        if ($str) {
            throw new CrontabParseException("Invalid crontab field \"$str_\": unrecognized string $str");
        }

        return $array;
    }

    private static function strtol($str, &$substr)
    {
        $long = intval($str);
        $len = strlen(strval($long));
        $substr = substr($str, $len);
        return $long;
    }

    // offset : (modValue + offset - 1)
    private static function fillZero($modValue, $offset = 0)
    {
        $array = [];
        $end = $modValue + $offset;
        for ($i = $offset; $i < $end; $i++) {
            $array[$i] = 0;
        }
        return $array;
    }
}

class CrontabParseException extends ZanException {}
