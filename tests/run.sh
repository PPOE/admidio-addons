#!/bin/sh
# SPDX-License-Identifier: GPL-2.0-only
# Runs the tests in Docker: ./tests/run.sh (exit status 0 = all passed).
set -eu
cd "$(dirname "$0")"
project="addons-tests-$$"
trap 'docker compose -p "$project" down -v --remove-orphans >/dev/null 2>&1' EXIT
docker compose -p "$project" build -q
docker compose -p "$project" run --rm php
