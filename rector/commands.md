`php -d memory_limit=20G vendor/bin/rector process --memory-limit=20G --dry-run --output-format=json > rector/benchmarks.json`

`php -d memory_limit=20G vendor/bin/rector process --memory-limit=20G --output-format=json > rector/benchmarks.json`

`cat rector/benchmarks.json  | jq '.file_diffs[].applied_rectors' | grep Rector | sort -t: -u -k1,1`