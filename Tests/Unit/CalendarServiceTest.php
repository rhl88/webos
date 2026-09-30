<?php

namespace App\Apps\CmsproWebos\Tests\Unit;

use App\Apps\CmsproWebos\Services\CalendarService;
use App\Apps\CmsproWebos\Tests\WebosTestCase;
use InvalidArgumentException;

class CalendarServiceTest extends WebosTestCase
{
    private const SOLAR_TERM_NAMES = [
        '小寒', '大寒', '立春', '雨水', '惊蛰', '春分', '清明', '谷雨',
        '立夏', '小满', '芒种', '夏至', '小暑', '大暑', '立秋', '处暑',
        '白露', '秋分', '寒露', '霜降', '立冬', '小雪', '大雪', '冬至',
    ];

    /**
     * 期望值来源：小日历 xiaorili.com 节气表（紫金山天文台历算室标准）。
     *
     * @return array<int,array<int,string>>
     */
    public static function solarTermProvider(): array
    {
        return [
            2026 => [
                '01-05', '01-20', '02-04', '02-18', '03-05', '03-20', '04-05', '04-20',
                '05-05', '05-21', '06-05', '06-21', '07-07', '07-23', '08-07', '08-23',
                '09-07', '09-23', '10-08', '10-23', '11-07', '11-22', '12-07', '12-22',
            ],
            2027 => [
                '01-05', '01-20', '02-04', '02-19', '03-06', '03-21', '04-05', '04-20',
                '05-06', '05-21', '06-06', '06-21', '07-07', '07-23', '08-08', '08-23',
                '09-08', '09-23', '10-08', '10-23', '11-07', '11-22', '12-07', '12-22',
            ],
        ];
    }

    public function test_it_matches_authoritative_solar_terms(): void
    {
        $service = app(CalendarService::class);

        foreach (self::solarTermProvider() as $year => $expected) {
            foreach ($expected as $index => $date) {
                $month = intdiv($index, 2) + 1;
                $actual = str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-'
                    . str_pad((string) $service->solarTermDay($year, $index), 2, '0', STR_PAD_LEFT);

                $this->assertSame(
                    $date,
                    $actual,
                    $year . ' 年' . self::SOLAR_TERM_NAMES[$index] . '日期不符'
                );
            }
        }
    }

    public function test_solar_term_is_detected_on_the_term_day(): void
    {
        $service = app(CalendarService::class);

        $this->assertSame('立春', $service->dayInfo('2026-02-04')['term']);
        $this->assertSame('秋分', $service->dayInfo('2026-09-23')['term']);
        $this->assertSame('冬至', $service->dayInfo('2026-12-22')['term']);
        $this->assertSame('', $service->dayInfo('2026-09-22')['term']);
    }

    public function test_it_converts_gregorian_dates_to_lunar_dates(): void
    {
        $service = app(CalendarService::class);

        $expectations = [
            '2026-02-17' => '正月初一',
            '2026-03-03' => '正月十五',
            '2026-06-19' => '五月初五',
            '2026-09-11' => '八月初一',
            '2026-09-25' => '八月十五',
            '2026-09-29' => '八月十九',
            '2026-10-10' => '九月初一',
            '2026-10-18' => '九月初九',
        ];

        foreach ($expectations as $date => $expected) {
            $info = $service->dayInfo($date);

            $this->assertSame($expected, $info['lunar_month'] . $info['lunar_day'], $date . ' 农历不符');
        }
    }

    public function test_it_handles_leap_month(): void
    {
        $service = app(CalendarService::class);

        $this->assertSame('闰六月初一', $this->lunarLabel($service, '2025-07-25'));
        $this->assertSame('闰六月廿九', $this->lunarLabel($service, '2025-08-22'));
        $this->assertSame('七月初一', $this->lunarLabel($service, '2025-08-23'));
        $this->assertTrue($service->lunarDate('2025-07-25')['leap']);
        $this->assertFalse($service->lunarDate('2025-08-23')['leap']);
    }

    public function test_month_returns_every_day_with_lunar_label(): void
    {
        $service = app(CalendarService::class);
        $month = $service->month('2026-09');

        $this->assertSame('2026-09', $month['month']);
        $this->assertCount(30, $month['days']);
        $this->assertSame('八月初一', $month['days']['2026-09-11']['lunar_month'] . $month['days']['2026-09-11']['lunar_day']);
        $this->assertSame('秋分', $month['days']['2026-09-23']['term']);
        $this->assertSame('十九', $month['days']['2026-09-29']['lunar_day']);
    }

    public function test_it_rejects_invalid_month_or_out_of_range_date(): void
    {
        $service = app(CalendarService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->month('2026-13');
    }

    public function test_solar_term_day_rejects_invalid_index(): void
    {
        $service = app(CalendarService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->solarTermDay(2026, 24);
    }

    private function lunarLabel(CalendarService $service, string $date): string
    {
        $info = $service->dayInfo($date);

        return $info['lunar_month'] . $info['lunar_day'];
    }
}
