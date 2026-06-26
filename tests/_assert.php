<?php
function ok(bool $cond, string $msg): void {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "PASS: $msg\n";
}
function eqv($got, $want, string $msg): void {
    ok($got == $want, "$msg (got " . json_encode($got) . ", want " . json_encode($want) . ")");
}
