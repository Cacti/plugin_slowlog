# Slowlog pipeline profiling

`profile_pipeline.php` measures PHP elapsed time and peak allocated memory while running the real import, statistics, or table extraction helpers. Database adapters generate deterministic input and discard writes. These results exclude database latency, browser rendering, concurrent imports and full application overhead.

Run from the plugin checkout, selecting PHP through mise:

```sh
mise exec php@8.4.25 -- php tests/benchmarks/profile_pipeline.php --case=stats --entries=100000 --groups=64
mise exec php@8.4.25 -- php tests/benchmarks/profile_pipeline.php --case=ingest --entries=100000
mise exec php@8.4.25 -- php tests/benchmarks/profile_pipeline.php --case=tables --entries=10000
```

Use `--root=/path/to/baseline` to run the same driver against another checkout. Each measurement must run in a fresh PHP process. `profile-results.json` records five runs per case for base `c81fe12a401cacb6cce31282e9198a5762221919` and the refactor, on the same host with PHP 8.4.25 and seed 12345.

| Case | Base median | Refactor median | Base peak | Refactor peak |
| --- | ---: | ---: | ---: | ---: |
| Statistics, 100,000 rows, 64 groups | 0.498 s | 0.459 s | 30 MiB | 14 MiB |
| Ingestion, 100,000 entries | 0.429 s | 0.391 s | 2 MiB | 2 MiB |
| Table extraction, 10,000 statements | 0.236 s | 0.237 s | 2 MiB | 2 MiB |

Statistics used 53% less peak memory and took 8% less elapsed time in this fixture. Ingestion took about 9% less time; table extraction was essentially unchanged. Repeat on representative production input before extrapolating these numbers.

An Xdebug call profile of 10,000 statistics rows identified the accumulator and repeated sample-limit calculation as the largest PHP costs. Reusing the last pure sample-limit calculation avoids repeated logarithms while retaining only two scalar values. Profile timings include instrumentation overhead and are not the benchmark timings above.

The statistics budget retains at most 200,000 sampled metric values per scope across up to 10,000 groups, capped at 20,000 per group and metric. A new group tier uniformly shrinks existing reservoirs. Counts, sums, minima and maxima remain exact; percentiles can be estimated. Method and table scopes are written and released separately. Exceeding the group limit fails the import instead of exhausting memory or publishing incomplete statistics. The budget bounds sample storage, not total process memory: query text, database result pages and PHP array overhead still contribute.

For an Xdebug call profile, enable the extension installed for the selected PHP version and use `XDEBUG_MODE=profile`, `xdebug.start_with_request=yes`, and a temporary `xdebug.output_dir`. Keep generated callgrind files outside the repository.
