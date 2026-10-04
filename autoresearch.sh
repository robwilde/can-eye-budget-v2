#!/usr/bin/env bash
# Transaction state x kind consistency matrix.
#
# Seeds one scenario per (state, kind) into an in-memory sqlite DB with a frozen clock and a
# fixed faker seed, then asks every surface that totals or classifies money (calendar, pay-cycle
# calendar, report aggregator, dashboard, balance projection, needed-until-payday) what it saw.
# A case fails when a surface disagrees with the documented classification:
#   expense -> spend, income -> income, tracked<->tracked transfer -> not counted,
#   tracked->untracked transfer -> spend, untracked->tracked transfer -> income.
# Primary metric is the number of disagreeing cases (lower is better).
set -euo pipefail

cd "$(dirname "$0")"

junit="$(mktemp --suffix=.xml)"
output="$(mktemp)"
trap 'rm -f "$junit" "$output"' EXIT

start=$(date +%s.%N)

set +e
php vendor/bin/pest tests/Autoresearch --no-coverage --colors=never --log-junit "$junit" >"$output" 2>&1
set -e

end=$(date +%s.%N)

if [[ ! -s "$junit" ]]; then
    echo "pest produced no junit report" >&2
    cat "$output" >&2
    exit 1
fi

php -r '
$xml = simplexml_load_file($argv[1]);
if ($xml === false) { fwrite(STDERR, "unreadable junit\n"); exit(1); }

$cases = $failed = $errored = $entered = $planned = 0;
$bySurface = [];

foreach ($xml->xpath("//testcase") as $case) {
    $cases++;
    $name = (string) $case["name"];
    $isPlanned = str_contains($name, "planned transaction is classified");
    $hasError = isset($case->error);
    $hasFailure = isset($case->failure);

    if ($hasError) { $errored++; fwrite(STDERR, "ERROR: {$name}\n" . trim((string) $case->error) . "\n"); }

    if ($hasFailure) {
        $failed++;
        $isPlanned ? $planned++ : $entered++;
        if (preg_match("/data set \"\(\x27([^\x27]+)\x27\)/", $name, $m)) { $bySurface[$m[1]] = ($bySurface[$m[1]] ?? 0) + 1; }
        fwrite(STDERR, "DISAGREE: {$name}\n");
    }
}

if ($cases === 0 || $errored > 0) { fwrite(STDERR, "harness invalid: cases={$cases} errors={$errored}\n"); exit(1); }

echo "METRIC matrix_failures={$failed}\n";
echo "METRIC matrix_cases={$cases}\n";
echo "METRIC matrix_passed=" . ($cases - $failed) . "\n";
echo "METRIC entered_failures={$entered}\n";
echo "METRIC planned_failures={$planned}\n";
ksort($bySurface);
foreach ($bySurface as $surface => $count) {
    echo "METRIC surface_" . preg_replace("/[^a-z0-9]+/", "_", strtolower($surface)) . "_failures={$count}\n";
}
' "$junit"

printf 'METRIC duration_s=%.2f\n' "$(echo "$end - $start" | bc)"
