# Academic Similarity — Known Limitations (MVP)

## No Optical Character Recognition (OCR)

The module processes only documents from which text can be extracted natively: DOCX, PDF (text-layer only), and plain TXT. Scanned documents and image-based PDFs are rejected because they contain no extractable text stream. OCR support is planned for a future release and would require integration with an external OCR service.

## Semantic Matching Is Optional and Disabled by Default

The primary matching engine uses deterministic exact and near-exact textual comparison only. Semantic similarity (paraphrase detection, concept-level matching) is available as a capability handler (`academic_similarity.semantic.compare@1`) at reduced priority (100), but it is disabled by default and requires an external ML model service. Institutions that need semantic detection must enable it in settings and provision a compatible model profile.

## No Cross-Tenant Source Libraries

Sources are strictly scoped to the current tenant. An institution cannot search against another institution's document corpus or subscribe to a shared repository. Cross-tenant collection sharing would require changes to the tenant isolation model and is not on the MVP roadmap.

## Internet-Assisted Checking Is Bounded

Internet-assisted checking is opt-in and disabled by default. When enabled, AISS can generate bounded query seeds, call a configured discovery capability or curated seed URLs, retrieve limited public-source text, store provenance, and compare against the retrieved source segments. This does not mean AISS searches the entire internet. Reports must disclose provider, query/source limits, retrieval status, and partial failures.

Groq or another AI provider can help with query/ranking/semantic comparison only after source evidence exists. AI output alone is not treated as plagiarism proof.

## No Learning Management System (LMS) Integration

The module exposes its own submission API and admin UI but does not integrate with external LMS platforms such as Moodle, Canvas, or Blackboard. Submissions must be uploaded manually through the admin interface or via direct API calls. LMS webhook or LTI 1.3 integration is under consideration for a subsequent milestone.

## Additional Constraints

- File size is limited to 20 MB (configurable). Larger documents must be split before submission.
- Word count is bounded between 20 and 50,000 words. Very short or very long documents are not processed.
- Only the configured file extensions (default: docx, pdf, txt) are accepted at the validation layer.
