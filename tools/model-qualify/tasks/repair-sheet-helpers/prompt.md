A helper file fails its acceptance run. Repair it.

{{INPUT}}

SPECIFICATION (authoritative - the failing output above is a sample, not the whole contract)
--------------------------------------------------------------------------------------------
slugify(string $title): string
  - lowercase the input;
  - replace every run of one or more non-alphanumeric characters with a single "-";
  - strip leading and trailing "-".

paginate(int $total, int $perPage, int $page): array
  - returns ['offset' => int, 'limit' => int, 'pages' => int];
  - $page below 1 is treated as 1;
  - offset = (page - 1) * $perPage, never negative;
  - limit = $perPage;
  - pages = ceil($total / $perPage), or 0 when $perPage is 0 or negative.

formatMoney(float $amount, string $currency = 'PHP'): string
  - returns the currency code, ONE space, then the amount to 2 decimals with thousands separators;
  - example: formatMoney(1234.5) === 'PHP 1,234.50'.
--------------------------------------------------------------------------------------------

Reply with ONE ```php code block containing the complete corrected file: the three function
definitions and nothing else. No explanation, no tests, no example usage, no <?php tag required.
