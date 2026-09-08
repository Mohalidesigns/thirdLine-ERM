#!/usr/bin/env bash
#
# Fails when a random number generator appears in code that can produce a
# user-facing figure.
#
# Why this exists: the AI Intelligence screens once generated risk trends,
# escalation probabilities, peer benchmarks and "model accuracy" with
# mt_rand(). Every one of those was a number a Nigerian bank could have put in
# front of a regulator. A grep in CI is cheap insurance against that class of
# defect returning.
#
# Allowlist: simulation engines legitimately need an RNG. They must use a
# SEEDABLE generator (Random\Randomizer with an explicit engine) so a capital
# figure can be reproduced months later — see MonteCarloService.
#
# Usage: scripts/check-no-rng.sh
# Exit:  0 clean, 1 a banned call was found.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

# Directories whose output reaches a user.
SEARCH_PATHS=(
    "app/Http/Controllers"
    "app/Services"
    "app/Jobs"
    "app/View"
    "app/Livewire"
    "resources/views"
)

# Files permitted to call an RNG, with the reason recorded here rather than in
# someone's memory. Paths are relative to the repository root.
ALLOWLIST=(
    # Poisson and Box-Muller variates for the loss distribution. Seeded via
    # Random\Randomizer(Mt19937) and the seed is persisted on simulation_runs.
    "app/Services/MonteCarloService.php"
    # The vendor portal's emailed sign-in code. Allowed for the OPPOSITE reason
    # to MonteCarloService: that one is seeded so a figure is reproducible, this
    # one must never be. A credential is not a figure.
    "app/Services/Tprm/Portal/PortalAuthService.php"
)

# mt_rand, rand, random_int, shuffle, str_shuffle, array_rand, uniqid.
# random_int is included deliberately: cryptographic quality does not make an
# invented business figure any less invented.
PATTERN='\b(mt_rand|rand|random_int|shuffle|str_shuffle|array_rand|uniqid)[[:space:]]*\('

existing_paths=()
for path in "${SEARCH_PATHS[@]}"; do
    [[ -d "$path" ]] && existing_paths+=("$path")
done

if [[ ${#existing_paths[@]} -eq 0 ]]; then
    echo "check-no-rng: none of the search paths exist; nothing to check."
    exit 0
fi

matches=$(grep -rEn --include='*.php' "$PATTERN" "${existing_paths[@]}" 2>/dev/null || true)

# Drop allowlisted files and comment lines. A comment mentioning mt_rand — such
# as the one explaining why MonteCarloService no longer uses it — is not a call.
filtered=""
while IFS= read -r line; do
    [[ -z "$line" ]] && continue

    file="${line%%:*}"

    skip=false
    for allowed in "${ALLOWLIST[@]}"; do
        if [[ "$file" == "$allowed" ]]; then
            skip=true
            break
        fi
    done
    $skip && continue

    # Strip "path:lineno:" to get the source text, then ignore // * # comments.
    code="${line#*:}"
    code="${code#*:}"
    trimmed="$(printf '%s' "$code" | sed -e 's/^[[:space:]]*//')"
    case "$trimmed" in
        //*|\**|\#*|/\**) continue ;;
    esac

    filtered+="$line"$'\n'
done <<< "$matches"

if [[ -n "${filtered// /}" && -n "$(printf '%s' "$filtered" | tr -d '[:space:]')" ]]; then
    echo "──────────────────────────────────────────────────────────────────────"
    echo " Random number generator found in user-facing code."
    echo "──────────────────────────────────────────────────────────────────────"
    printf '%s' "$filtered"
    echo "──────────────────────────────────────────────────────────────────────"
    echo " If a number is not computed from real data, it does not ship."
    echo ""
    echo " A simulation engine that genuinely needs an RNG must:"
    echo "   1. use a SEEDABLE generator (Random\\Randomizer + explicit engine),"
    echo "   2. persist the seed with its results, and"
    echo "   3. be added to ALLOWLIST in scripts/check-no-rng.sh with a reason."
    echo "──────────────────────────────────────────────────────────────────────"
    exit 1
fi

echo "check-no-rng: clean — no RNG in user-facing code."
exit 0
