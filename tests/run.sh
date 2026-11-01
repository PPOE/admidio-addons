#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-only
# Runs the tests in Docker: ./tests/run.sh (exit status 0 = all passed).
# Each tests/*-test.php runs in its own PHP container against one database;
# each test resets the data it uses.
set -eu
cd "$(dirname "$0")"
project="addons-tests-$$"
trap 'docker compose -p "$project" down -v --remove-orphans >/dev/null 2>&1' EXIT
docker compose -p "$project" build -q
status=0
for test in *-test.php; do
    echo "== tests/$test"
    docker compose -p "$project" run --rm php php "tests/$test" || status=1
done
exit "$status"
