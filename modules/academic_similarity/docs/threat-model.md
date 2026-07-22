# Academic Similarity — Security Threat Model

## Tenant Isolation

**Threat.** Tenant A accesses or matches against Tenant B's submissions and sources.

**Mitigation.** Every table in the module carries a `tenant_id` column. Repositories are constructed with a tenant identifier and apply it as a mandatory filter in every SELECT, INSERT, UPDATE, and DELETE query. The pipeline derives the tenant from the authenticated request context via `app()->tenant()->current()`; there is no mechanism to override it from user-supplied input. The migration schema enforces a composite index on `(tenant_id, ...)` for all primary access paths.

## Insecure Direct Object Reference (IDOR)

**Threat.** An authenticated admin from Tenant A accesses a submission, source, or report belonging to Tenant B by manipulating an ID parameter.

**Mitigation.** All repository methods scope queries by the tenant-bound identifier. A `findById($id)` call is internally `SELECT ... WHERE id = :id AND tenant_id = :tid`. Even if a user supplies a valid record ID from another tenant, the query returns no rows. API handlers return 404 for unknown resources regardless of whether the ID exists in another tenant.

## Cross-Site Scripting (XSS) in Extracted Text

**Threat.** Malicious HTML or JavaScript embedded in a submitted document renders unsanitized in the similarity report or admin UI.

**Mitigation.** The text extraction layer (`AcademicSimilarityTextExtractor`) extracts plain text only — it strips all markup from DOCX and PDF inputs before storage. Extracted text is stored as raw text in `ac_similarity_text_versions`. The report generator and admin templates apply output encoding appropriate to the rendering context. No extracted content is ever served with a MIME type that permits script execution.

## Path Traversal

**Threat.** An attacker provides a crafted filename or subpath to access files outside the designated storage directory.

**Mitigation.** The `academic_similarity_storage_path()` helper resolves all paths relative to the hard-coded `storage/academic_similarity/` base directory. It does not accept user-supplied path components. Uploaded files are stored under a UUID-based naming scheme within this directory; original filenames are stored only in the database as metadata, never used as filesystem paths.

## Upload Validation

**Threat.** An attacker uploads an oversized file, a file with a dangerous extension, or a file that bypasses text extraction to exhaust resources.

**Mitigation.** The `AcademicSimilarityFileValidator` enforces three checks before any file is persisted: allowed extension (configurable, default `docx,pdf,txt`), maximum file size (configurable, default 20 MB), and minimum/maximum word count bounds (configurable, default 20–50,000 words). Files that fail any check are rejected with a 422 response before any storage or processing occurs.

## Cross-Site Request Forgery (CSRF)

**Threat.** An authenticated admin is tricked into submitting a request that creates, processes, or deletes a submission or source.

**Mitigation.** Every state-mutating API route calls `app()->csrfEnforce()` before executing any business logic. The kernel's CSRF middleware validates the token from the request body or header. Both admin page forms and API POST endpoints are protected.
