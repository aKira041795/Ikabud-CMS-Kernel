<?php
declare(strict_types=1);

/**
 * AISS — Evidence Taxonomy
 *
 * Canonical classification enums for evidence relationships.
 *
 * This taxonomy governs how matches are classified, displayed, and reviewed.
 * It separates machine-generated classifications from reviewer overrides.
 *
 * @see docs/scoring.md for scoring methodology
 * @see docs/architecture.md for system architecture
 */

class AcademicSimilarityEvidenceTaxonomy
{
    /**
     * Evidence type — what kind of match was detected.
     * These are the primary classification of how two passages relate textually.
     */
    public const EVIDENCE_TYPE_EXACT       = 'exact';
    public const EVIDENCE_TYPE_NEAR_EXACT  = 'near_exact';
    public const EVIDENCE_TYPE_QUOTATION   = 'quotation';
    public const EVIDENCE_TYPE_TEMPLATE    = 'template';
    public const EVIDENCE_TYPE_BIBLIOGRAPHY = 'bibliography';
    public const EVIDENCE_TYPE_SEMANTIC    = 'semantic';
    public const EVIDENCE_TYPE_SELF_OVERLAP = 'self_overlap';
    public const EVIDENCE_TYPE_INTERNET    = 'internet_reference';

    public const EVIDENCE_TYPES = [
        self::EVIDENCE_TYPE_EXACT,
        self::EVIDENCE_TYPE_NEAR_EXACT,
        self::EVIDENCE_TYPE_QUOTATION,
        self::EVIDENCE_TYPE_TEMPLATE,
        self::EVIDENCE_TYPE_BIBLIOGRAPHY,
        self::EVIDENCE_TYPE_SEMANTIC,
        self::EVIDENCE_TYPE_SELF_OVERLAP,
        self::EVIDENCE_TYPE_INTERNET,
    ];

    /**
     * Context relationship — the conceptual relationship between passages.
     * Used by the context analysis service (Phase 2).
     */
    public const CONTEXT_SAME_CLAIM       = 'same_claim';
    public const CONTEXT_SAME_ARGUMENT    = 'same_argument';
    public const CONTEXT_SAME_METHOD      = 'same_method';
    public const CONTEXT_SAME_FRAMEWORK   = 'same_framework';
    public const CONTEXT_SAME_EVIDENCE    = 'same_evidence';
    public const CONTEXT_SAME_CONCLUSION  = 'same_conclusion';
    public const CONTEXT_TOPIC_ONLY       = 'topic_only';
    public const CONTEXT_UNCERTAIN        = 'uncertain';

    public const CONTEXT_RELATIONSHIPS = [
        self::CONTEXT_SAME_CLAIM,
        self::CONTEXT_SAME_ARGUMENT,
        self::CONTEXT_SAME_METHOD,
        self::CONTEXT_SAME_FRAMEWORK,
        self::CONTEXT_SAME_EVIDENCE,
        self::CONTEXT_SAME_CONCLUSION,
        self::CONTEXT_TOPIC_ONLY,
        self::CONTEXT_UNCERTAIN,
    ];

    /**
     * Scholarly relationship — how the passage relates to scholarly norms.
     *
     * NOTE: Several values require reviewer confirmation:
     *   - standard_method_confirmed_by_reviewer: requires reviewer or policy
     *   - shared_topic_only: replaces independent_agreement (the system
     *     cannot establish independence without provenance evidence)
     *   - shared_method_description: machine-detectable; not the same as
     *     "standard method" which requires disciplinary knowledge
     */
    public const SCHOLARLY_ATTRIBUTED_USE       = 'attributed_use';
    public const SCHOLARLY_COMMON_KNOWLEDGE     = 'common_knowledge';
    public const SCHOLARLY_STANDARD_METHOD      = 'standard_method';
    public const SCHOLARLY_STANDARD_METHOD_CONFIRMED = 'standard_method_confirmed_by_reviewer';
    public const SCHOLARLY_SHARED_METHOD_DESCRIPTION = 'shared_method_description';
    public const SCHOLARLY_PARAPHRASE           = 'paraphrase';
    public const SCHOLARLY_SYNTHESIS            = 'synthesis';
    public const SCHOLARLY_REPLICATION          = 'replication';
    public const SCHOLARLY_EXTENSION            = 'extension';
    public const SCHOLARLY_REFINEMENT           = 'refinement';
    public const SCHOLARLY_TRANSLATION          = 'translation';
    public const SCHOLARLY_CRITIQUE             = 'critique';
    public const SCHOLARLY_INDEPENDENT_AGREEMENT = 'independent_agreement'; // Deprecated — use shared_topic_only
    public const SCHOLARLY_SHARED_TOPIC_ONLY     = 'shared_topic_only';
    public const SCHOLARLY_INSUFFICIENT_ATTRIBUTION = 'insufficient_attribution';
    public const SCHOLARLY_POSSIBLE_UNATTRIBUTED_REUSE = 'possible_unattributed_reuse';
    public const SCHOLARLY_UNCERTAIN            = 'uncertain';

    public const SCHOLARLY_RELATIONSHIPS = [
        self::SCHOLARLY_ATTRIBUTED_USE,
        self::SCHOLARLY_COMMON_KNOWLEDGE,
        self::SCHOLARLY_STANDARD_METHOD,
        self::SCHOLARLY_STANDARD_METHOD_CONFIRMED,
        self::SCHOLARLY_SHARED_METHOD_DESCRIPTION,
        self::SCHOLARLY_PARAPHRASE,
        self::SCHOLARLY_SYNTHESIS,
        self::SCHOLARLY_REPLICATION,
        self::SCHOLARLY_EXTENSION,
        self::SCHOLARLY_REFINEMENT,
        self::SCHOLARLY_TRANSLATION,
        self::SCHOLARLY_CRITIQUE,
        self::SCHOLARLY_INDEPENDENT_AGREEMENT,
        self::SCHOLARLY_SHARED_TOPIC_ONLY,
        self::SCHOLARLY_INSUFFICIENT_ATTRIBUTION,
        self::SCHOLARLY_POSSIBLE_UNATTRIBUTED_REUSE,
        self::SCHOLARLY_UNCERTAIN,
    ];

    /**
     * Attribution status — observed citation signals around a passage.
     *
     * NOTE: These are cautious observation statuses. The system does NOT
     * assert that attribution is "present and supported" — that requires
     * linking the citation to the specific source and verifying the source
     * supports the claim, which AISS does not yet perform.
     *
     * Use these instead of the former PRESENT_SUPPORTED/MISSING which
     * implied more certainty than the evidence supports.
     */
    public const ATTRIBUTION_NOT_REQUIRED         = 'not_required';
    public const ATTRIBUTION_PRESENT_SUPPORTED    = 'present_and_supported'; // Deprecated — requires citation-to-source verification
    public const ATTRIBUTION_PRESENT_INCOMPLETE   = 'present_but_incomplete';
    public const ATTRIBUTION_PRESENT_MISMATCHED   = 'present_but_mismatched';
    public const ATTRIBUTION_MISSING              = 'missing'; // Deprecated — use citation_not_detected
    public const ATTRIBUTION_UNABLE_TO_DETERMINE  = 'unable_to_determine';

    // Cautious attribution observation statuses (preferred)
    public const ATTR_CITATION_DETECTED           = 'citation_detected';
    public const ATTR_CITATION_NOT_DETECTED       = 'citation_not_detected';
    public const ATTR_CITATION_AND_QUOTATION      = 'citation_and_quotation_detected';
    public const ATTR_CITATION_UNRESOLVED         = 'citation_detected_source_unresolved';
    public const ATTR_ATTRIBUTION_NEEDS_REVIEW    = 'attribution_needs_review';

    public const ATTRIBUTION_STATUSES = [
        self::ATTRIBUTION_NOT_REQUIRED,
        self::ATTRIBUTION_PRESENT_SUPPORTED,
        self::ATTRIBUTION_PRESENT_INCOMPLETE,
        self::ATTRIBUTION_PRESENT_MISMATCHED,
        self::ATTRIBUTION_MISSING,
        self::ATTRIBUTION_UNABLE_TO_DETERMINE,
        self::ATTR_CITATION_DETECTED,
        self::ATTR_CITATION_NOT_DETECTED,
        self::ATTR_CITATION_AND_QUOTATION,
        self::ATTR_CITATION_UNRESOLVED,
        self::ATTR_ATTRIBUTION_NEEDS_REVIEW,
    ];

    /**
     * Evidence family — which score family a match type belongs to.
     */
    public const FAMILY_TEXTUAL    = 'textual';
    public const FAMILY_CONTEXTUAL = 'contextual';
    public const FAMILY_SCHOLARLY  = 'scholarly';

    /**
     * Get the evidence family for a given evidence type.
     */
    public static function getFamily(string $evidenceType): string
    {
        return match ($evidenceType) {
            self::EVIDENCE_TYPE_EXACT,
            self::EVIDENCE_TYPE_NEAR_EXACT,
            self::EVIDENCE_TYPE_QUOTATION,
            self::EVIDENCE_TYPE_TEMPLATE,
            self::EVIDENCE_TYPE_BIBLIOGRAPHY,
            self::EVIDENCE_TYPE_SELF_OVERLAP => self::FAMILY_TEXTUAL,

            self::EVIDENCE_TYPE_SEMANTIC,
            self::EVIDENCE_TYPE_INTERNET => self::FAMILY_CONTEXTUAL,

            default => self::FAMILY_SCHOLARLY,
        };
    }

    /**
     * Validate an evidence type value.
     */
    public static function isValidEvidenceType(string $type): bool
    {
        return in_array($type, self::EVIDENCE_TYPES, true);
    }

    /**
     * Validate a context relationship value.
     */
    public static function isValidContextRelationship(string $rel): bool
    {
        return in_array($rel, self::CONTEXT_RELATIONSHIPS, true);
    }

    /**
     * Validate a scholarly relationship value.
     */
    public static function isValidScholarlyRelationship(string $rel): bool
    {
        return in_array($rel, self::SCHOLARLY_RELATIONSHIPS, true);
    }

    /**
     * Validate an attribution status value.
     */
    public static function isValidAttributionStatus(string $status): bool
    {
        return in_array($status, self::ATTRIBUTION_STATUSES, true);
    }
}
