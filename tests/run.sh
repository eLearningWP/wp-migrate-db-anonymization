#!/usr/bin/env bash
# Runs the integration test in the wp-agent-sandbox environment (see wp-agent-sandbox.config.json).
# Each step runs in its own WP-CLI process, as WP Migrate ends requests with exit.

set -u

cd "$(dirname "$0")/.."

TEST=wp-content/plugins/wpmdb-pull-anonymizer/tests/integration-test.php
FAILED=0

step() {
	echo "=== $1"
	output=$(wp-agent-sandbox wp -- eval-file "$TEST" "$1" 2>&1 | grep -E '^(PASS|FAIL|Success|Error|Warning|Anonymized|finalize_migration|\{)')
	echo "$output"
	if echo "$output" | grep -qE '^(FAIL|Error)'; then
		FAILED=1
	fi
}

step setup
step finalize
step assert

step setup
step finalize-fail
step assert-fail

step not-run

echo "=== push"
output=$(wp-agent-sandbox wp -- eval-file "$TEST" push 2>&1)
if echo "$output" | grep -q 'Pushing from this site is blocked'; then
	echo 'PASS push from this site blocked'
else
	echo "FAIL push from this site blocked -> $output"
	FAILED=1
fi

if [ "$FAILED" -ne 0 ]; then
	echo 'Some checks failed.'
	exit 1
fi

echo 'All checks passed.'
