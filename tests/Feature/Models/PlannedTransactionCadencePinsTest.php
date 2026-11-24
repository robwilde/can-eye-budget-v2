<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Models\PlannedTransaction;
use Carbon\CarbonImmutable;

dataset('cadence pins', [
    '2026-01-31 dont-repeat' => ['2026-01-31', RecurrenceFrequency::from('dont-repeat'), ['dates' => ['2026-01-31']], null],
    '2026-01-31 everyday' => ['2026-01-31', RecurrenceFrequency::from('everyday'), ['count' => 366, 'first' => ['2026-01-31', '2026-02-01', '2026-02-02'], 'last' => '2027-01-31', 'sha1' => '4934b30324d4454d3c8cd2d69907f6686e847834'], '2026-03-12'],
    '2026-01-31 every-week' => ['2026-01-31', RecurrenceFrequency::from('every-week'), ['count' => 53, 'first' => ['2026-01-31', '2026-02-07', '2026-02-14'], 'last' => '2027-01-30', 'sha1' => '140af9dc0d409cc4c26414dafe84f3eac346b8f2'], '2026-03-14'],
    '2026-01-31 every-month' => ['2026-01-31', RecurrenceFrequency::from('every-month'), ['dates' => ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31']], '2026-03-31'],
    '2026-01-31 every-3-months' => ['2026-01-31', RecurrenceFrequency::from('every-3-months'), ['dates' => ['2026-01-31', '2026-04-30', '2026-07-31', '2026-10-31', '2027-01-31']], '2026-04-30'],
    '2026-01-31 every-6-months' => ['2026-01-31', RecurrenceFrequency::from('every-6-months'), ['dates' => ['2026-01-31', '2026-07-31', '2027-01-31']], '2026-07-31'],
    '2026-01-31 every-year' => ['2026-01-31', RecurrenceFrequency::from('every-year'), ['dates' => ['2026-01-31', '2027-01-31']], '2027-01-31'],
    '2026-01-31 every-workday' => ['2026-01-31', RecurrenceFrequency::from('every-workday'), ['count' => 261, 'first' => ['2026-01-31', '2026-02-02', '2026-02-03'], 'last' => '2027-01-29', 'sha1' => '8138d76cd29280e6098479db351e63d119af6d0d'], '2026-03-12'],
    '2026-01-31 every-weekend' => ['2026-01-31', RecurrenceFrequency::from('every-weekend'), ['count' => 106, 'first' => ['2026-01-31', '2026-02-01', '2026-02-07'], 'last' => '2027-01-31', 'sha1' => '11e65621cfe25558b5b31f2dca92edba9e7b812b'], '2026-03-14'],
    '2026-01-31 two-on-two-off' => ['2026-01-31', RecurrenceFrequency::from('two-on-two-off'), ['count' => 92, 'first' => ['2026-01-31', '2026-02-04', '2026-02-08'], 'last' => '2027-01-30', 'sha1' => 'bb0bd7b0fafe2ab17d7573e3ca616eb781dc45a9'], '2026-03-12'],
    '2026-01-31 every-2-days' => ['2026-01-31', RecurrenceFrequency::from('every-2-days'), ['count' => 183, 'first' => ['2026-01-31', '2026-02-02', '2026-02-04'], 'last' => '2027-01-30', 'sha1' => 'cd2f32c7f5944442fcb0263785625f94a5d509b3'], '2026-03-12'],
    '2026-01-31 every-3-days' => ['2026-01-31', RecurrenceFrequency::from('every-3-days'), ['count' => 122, 'first' => ['2026-01-31', '2026-02-03', '2026-02-06'], 'last' => '2027-01-29', 'sha1' => '8fb10f6564e1f878c02a8679ce3ed80481ea3731'], '2026-03-14'],
    '2026-01-31 every-4-days' => ['2026-01-31', RecurrenceFrequency::from('every-4-days'), ['count' => 92, 'first' => ['2026-01-31', '2026-02-04', '2026-02-08'], 'last' => '2027-01-30', 'sha1' => 'bb0bd7b0fafe2ab17d7573e3ca616eb781dc45a9'], '2026-03-12'],
    '2026-01-31 every-5-days' => ['2026-01-31', RecurrenceFrequency::from('every-5-days'), ['count' => 74, 'first' => ['2026-01-31', '2026-02-05', '2026-02-10'], 'last' => '2027-01-31', 'sha1' => '424864c2cb6098adfab7a85dc0747f358b648749'], '2026-03-12'],
    '2026-01-31 every-6-days' => ['2026-01-31', RecurrenceFrequency::from('every-6-days'), ['count' => 61, 'first' => ['2026-01-31', '2026-02-06', '2026-02-12'], 'last' => '2027-01-26', 'sha1' => '730f44e1c0b31d0d4cc7b13252abd97595dc4786'], '2026-03-14'],
    '2026-01-31 every-2-weeks' => ['2026-01-31', RecurrenceFrequency::from('every-2-weeks'), ['count' => 27, 'first' => ['2026-01-31', '2026-02-14', '2026-02-28'], 'last' => '2027-01-30', 'sha1' => '953b6b667d3534f851bdae11914d7aeb30622989'], '2026-03-14'],
    '2026-01-31 every-3-weeks' => ['2026-01-31', RecurrenceFrequency::from('every-3-weeks'), ['count' => 18, 'first' => ['2026-01-31', '2026-02-21', '2026-03-14'], 'last' => '2027-01-23', 'sha1' => 'a24023408fef9de8af5796b0efefad5c01ed9918'], '2026-03-14'],
    '2026-01-31 every-4-weeks' => ['2026-01-31', RecurrenceFrequency::from('every-4-weeks'), ['dates' => ['2026-01-31', '2026-02-28', '2026-03-28', '2026-04-25', '2026-05-23', '2026-06-20', '2026-07-18', '2026-08-15', '2026-09-12', '2026-10-10', '2026-11-07', '2026-12-05', '2027-01-02', '2027-01-30']], '2026-03-28'],
    '2028-02-29 dont-repeat' => ['2028-02-29', RecurrenceFrequency::from('dont-repeat'), ['dates' => ['2028-02-29']], null],
    '2028-02-29 everyday' => ['2028-02-29', RecurrenceFrequency::from('everyday'), ['count' => 367, 'first' => ['2028-02-29', '2028-03-01', '2028-03-02'], 'last' => '2029-03-01', 'sha1' => 'c1101ee678f2079309b79cf580a5179ac2d1c6b4'], '2028-04-09'],
    '2028-02-29 every-week' => ['2028-02-29', RecurrenceFrequency::from('every-week'), ['count' => 53, 'first' => ['2028-02-29', '2028-03-07', '2028-03-14'], 'last' => '2029-02-27', 'sha1' => 'ff17ad97449677adc1157b7a92f1bc1fa6dbe211'], '2028-04-11'],
    '2028-02-29 every-month' => ['2028-02-29', RecurrenceFrequency::from('every-month'), ['dates' => ['2028-02-29', '2028-03-29', '2028-04-29', '2028-05-29', '2028-06-29', '2028-07-29', '2028-08-29', '2028-09-29', '2028-10-29', '2028-11-29', '2028-12-29', '2029-01-29', '2029-02-28']], '2028-04-29'],
    '2028-02-29 every-3-months' => ['2028-02-29', RecurrenceFrequency::from('every-3-months'), ['dates' => ['2028-02-29', '2028-05-29', '2028-08-29', '2028-11-29', '2029-02-28']], '2028-05-29'],
    '2028-02-29 every-6-months' => ['2028-02-29', RecurrenceFrequency::from('every-6-months'), ['dates' => ['2028-02-29', '2028-08-29', '2029-02-28']], '2028-08-29'],
    '2028-02-29 every-year' => ['2028-02-29', RecurrenceFrequency::from('every-year'), ['dates' => ['2028-02-29', '2029-02-28']], '2029-02-28'],
    '2028-02-29 every-workday' => ['2028-02-29', RecurrenceFrequency::from('every-workday'), ['count' => 263, 'first' => ['2028-02-29', '2028-03-01', '2028-03-02'], 'last' => '2029-03-01', 'sha1' => '65ab7474801e67163a2a72e6a5a77dbd4b4db8cf'], '2028-04-10'],
    '2028-02-29 every-weekend' => ['2028-02-29', RecurrenceFrequency::from('every-weekend'), ['count' => 105, 'first' => ['2028-02-29', '2028-03-04', '2028-03-05'], 'last' => '2029-02-25', 'sha1' => '244388f71395f6f9d8f813b81f9a0621e5ffa5d5'], '2028-04-09'],
    '2028-02-29 two-on-two-off' => ['2028-02-29', RecurrenceFrequency::from('two-on-two-off'), ['count' => 92, 'first' => ['2028-02-29', '2028-03-04', '2028-03-08'], 'last' => '2029-02-27', 'sha1' => '39acb312a7654186b6d33b00c591ef2a7c254245'], '2028-04-09'],
    '2028-02-29 every-2-days' => ['2028-02-29', RecurrenceFrequency::from('every-2-days'), ['count' => 184, 'first' => ['2028-02-29', '2028-03-02', '2028-03-04'], 'last' => '2029-03-01', 'sha1' => '8500e2aae9776e288f387df0fd5676b5fceab28b'], '2028-04-09'],
    '2028-02-29 every-3-days' => ['2028-02-29', RecurrenceFrequency::from('every-3-days'), ['count' => 123, 'first' => ['2028-02-29', '2028-03-03', '2028-03-06'], 'last' => '2029-03-01', 'sha1' => '32b8e1f89dcacc038c4fdf49efc569910ec35e91'], '2028-04-11'],
    '2028-02-29 every-4-days' => ['2028-02-29', RecurrenceFrequency::from('every-4-days'), ['count' => 92, 'first' => ['2028-02-29', '2028-03-04', '2028-03-08'], 'last' => '2029-02-27', 'sha1' => '39acb312a7654186b6d33b00c591ef2a7c254245'], '2028-04-09'],
    '2028-02-29 every-5-days' => ['2028-02-29', RecurrenceFrequency::from('every-5-days'), ['count' => 74, 'first' => ['2028-02-29', '2028-03-05', '2028-03-10'], 'last' => '2029-02-28', 'sha1' => '2bbc9c9cf7851b7bcbaae7d41c0e471e4076bca6'], '2028-04-09'],
    '2028-02-29 every-6-days' => ['2028-02-29', RecurrenceFrequency::from('every-6-days'), ['count' => 62, 'first' => ['2028-02-29', '2028-03-06', '2028-03-12'], 'last' => '2029-03-01', 'sha1' => '500374e088882ead5aea811a1701df9ffeee9443'], '2028-04-11'],
    '2028-02-29 every-2-weeks' => ['2028-02-29', RecurrenceFrequency::from('every-2-weeks'), ['count' => 27, 'first' => ['2028-02-29', '2028-03-14', '2028-03-28'], 'last' => '2029-02-27', 'sha1' => '7943f1b285e03864b7d136f4599970f75e99f0b6'], '2028-04-11'],
    '2028-02-29 every-3-weeks' => ['2028-02-29', RecurrenceFrequency::from('every-3-weeks'), ['count' => 18, 'first' => ['2028-02-29', '2028-03-21', '2028-04-11'], 'last' => '2029-02-20', 'sha1' => '414b595d748ae4351728e4e63a6fa9daf2288065'], '2028-04-11'],
    '2028-02-29 every-4-weeks' => ['2028-02-29', RecurrenceFrequency::from('every-4-weeks'), ['dates' => ['2028-02-29', '2028-03-28', '2028-04-25', '2028-05-23', '2028-06-20', '2028-07-18', '2028-08-15', '2028-09-12', '2028-10-10', '2028-11-07', '2028-12-05', '2029-01-02', '2029-01-30', '2029-02-27']], '2028-04-25'],
    '2026-01-03 dont-repeat' => ['2026-01-03', RecurrenceFrequency::from('dont-repeat'), ['dates' => ['2026-01-03']], null],
    '2026-01-03 everyday' => ['2026-01-03', RecurrenceFrequency::from('everyday'), ['count' => 366, 'first' => ['2026-01-03', '2026-01-04', '2026-01-05'], 'last' => '2027-01-03', 'sha1' => '15f529e6f70dcb9b8878f05884608757acbdc137'], '2026-02-12'],
    '2026-01-03 every-week' => ['2026-01-03', RecurrenceFrequency::from('every-week'), ['count' => 53, 'first' => ['2026-01-03', '2026-01-10', '2026-01-17'], 'last' => '2027-01-02', 'sha1' => '9844301bedade876f089d7e59c3f90d25f8d8acb'], '2026-02-14'],
    '2026-01-03 every-month' => ['2026-01-03', RecurrenceFrequency::from('every-month'), ['dates' => ['2026-01-03', '2026-02-03', '2026-03-03', '2026-04-03', '2026-05-03', '2026-06-03', '2026-07-03', '2026-08-03', '2026-09-03', '2026-10-03', '2026-11-03', '2026-12-03', '2027-01-03']], '2026-03-03'],
    '2026-01-03 every-3-months' => ['2026-01-03', RecurrenceFrequency::from('every-3-months'), ['dates' => ['2026-01-03', '2026-04-03', '2026-07-03', '2026-10-03', '2027-01-03']], '2026-04-03'],
    '2026-01-03 every-6-months' => ['2026-01-03', RecurrenceFrequency::from('every-6-months'), ['dates' => ['2026-01-03', '2026-07-03', '2027-01-03']], '2026-07-03'],
    '2026-01-03 every-year' => ['2026-01-03', RecurrenceFrequency::from('every-year'), ['dates' => ['2026-01-03', '2027-01-03']], '2027-01-03'],
    '2026-01-03 every-workday' => ['2026-01-03', RecurrenceFrequency::from('every-workday'), ['count' => 261, 'first' => ['2026-01-03', '2026-01-05', '2026-01-06'], 'last' => '2027-01-01', 'sha1' => '0610ce881ca2e871d2fc032f3a71568d7f283a57'], '2026-02-12'],
    '2026-01-03 every-weekend' => ['2026-01-03', RecurrenceFrequency::from('every-weekend'), ['count' => 106, 'first' => ['2026-01-03', '2026-01-04', '2026-01-10'], 'last' => '2027-01-03', 'sha1' => '87e47c5e02f545c21404b162007727b59bbca689'], '2026-02-14'],
    '2026-01-03 two-on-two-off' => ['2026-01-03', RecurrenceFrequency::from('two-on-two-off'), ['count' => 92, 'first' => ['2026-01-03', '2026-01-07', '2026-01-11'], 'last' => '2027-01-02', 'sha1' => '7c87a564acf1b2d5827b2ee283dc966b4d762cd1'], '2026-02-12'],
    '2026-01-03 every-2-days' => ['2026-01-03', RecurrenceFrequency::from('every-2-days'), ['count' => 183, 'first' => ['2026-01-03', '2026-01-05', '2026-01-07'], 'last' => '2027-01-02', 'sha1' => '2f8c4a44d38f5af01af2067a52b0a0aed49ea42b'], '2026-02-12'],
    '2026-01-03 every-3-days' => ['2026-01-03', RecurrenceFrequency::from('every-3-days'), ['count' => 122, 'first' => ['2026-01-03', '2026-01-06', '2026-01-09'], 'last' => '2027-01-01', 'sha1' => '19df31c8a62626dd8bcd6ab50b56208288ba23cc'], '2026-02-14'],
    '2026-01-03 every-4-days' => ['2026-01-03', RecurrenceFrequency::from('every-4-days'), ['count' => 92, 'first' => ['2026-01-03', '2026-01-07', '2026-01-11'], 'last' => '2027-01-02', 'sha1' => '7c87a564acf1b2d5827b2ee283dc966b4d762cd1'], '2026-02-12'],
    '2026-01-03 every-5-days' => ['2026-01-03', RecurrenceFrequency::from('every-5-days'), ['count' => 74, 'first' => ['2026-01-03', '2026-01-08', '2026-01-13'], 'last' => '2027-01-03', 'sha1' => 'c19cca86d0d352110df1cae356015f4831e3f1a9'], '2026-02-12'],
    '2026-01-03 every-6-days' => ['2026-01-03', RecurrenceFrequency::from('every-6-days'), ['count' => 61, 'first' => ['2026-01-03', '2026-01-09', '2026-01-15'], 'last' => '2026-12-29', 'sha1' => '900b6cf1beddd92fc4a05a3f27f2726877c0b4a9'], '2026-02-14'],
    '2026-01-03 every-2-weeks' => ['2026-01-03', RecurrenceFrequency::from('every-2-weeks'), ['count' => 27, 'first' => ['2026-01-03', '2026-01-17', '2026-01-31'], 'last' => '2027-01-02', 'sha1' => '23c3b63ba5e868faff9cfcd892601946191bd7f4'], '2026-02-14'],
    '2026-01-03 every-3-weeks' => ['2026-01-03', RecurrenceFrequency::from('every-3-weeks'), ['count' => 18, 'first' => ['2026-01-03', '2026-01-24', '2026-02-14'], 'last' => '2026-12-26', 'sha1' => 'ac4a8c3108f643973898a985269805383ff52cee'], '2026-02-14'],
    '2026-01-03 every-4-weeks' => ['2026-01-03', RecurrenceFrequency::from('every-4-weeks'), ['dates' => ['2026-01-03', '2026-01-31', '2026-02-28', '2026-03-28', '2026-04-25', '2026-05-23', '2026-06-20', '2026-07-18', '2026-08-15', '2026-09-12', '2026-10-10', '2026-11-07', '2026-12-05', '2027-01-02']], '2026-02-28'],
]);

it('computes each frequency over a year from its start date', function (string $start, RecurrenceFrequency $frequency, array $pin, ?string $next) {
    $plan = new PlannedTransaction(['start_date' => $start, 'frequency' => $frequency, 'status' => PlannedTransactionStatus::Active]);
    $from = CarbonImmutable::parse($start);
    $dates = $plan->occurrencesBetween($from, $from->addYear())->map(fn (CarbonImmutable $date): string => $date->toDateString())->all();

    if (isset($pin['dates'])) {
        expect($dates)->toBe($pin['dates']);
    } else {
        expect($dates)->toHaveCount($pin['count'])
            ->and(array_slice($dates, 0, 3))->toBe($pin['first'])
            ->and(end($dates))->toBe($pin['last'])
            ->and(sha1(implode(',', $dates)))->toBe($pin['sha1']);
    }

    expect($plan->nextOccurrenceOnOrAfter($from->addDays(40))?->toDateString())->toBe($next);
})->with('cadence pins');
