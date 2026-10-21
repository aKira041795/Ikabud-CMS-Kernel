This PHP function is supposed to validate a MySQL identifier (table/column name). It is wrong.

---------------- CURRENT CODE ----------------
{{INPUT}}
----------------------------------------------

Fix it. Requirements:
- Signature must stay `function validateIdentifier(string $name): bool`.
- Accept only identifiers that MySQL accepts unquoted: first character a letter or underscore,
  remaining characters letters, digits or underscore, maximum 64 characters total.
- Reject everything else, including empty strings.

Reply with ONE ```php code block containing only the corrected function and nothing else.
No explanation, no tests, no example usage.
