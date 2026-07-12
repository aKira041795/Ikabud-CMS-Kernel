<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Application Profile Provider — Kernel OS governed application profile contract.
 *
 * An application profile defines the visual and interaction contracts for
 * operational modules (PAL, Attendance, Guidance, WMS, EHR). It is distinct
 * from a public CMS theme — it governs application-shell behavior, not
 * public-facing website presentation.
 *
 * Architecture rules:
 *   1. Provider MUST NOT query the database, check auth, or resolve tenants
 *   2. Provider MUST NOT generate HTML directly — return template paths instead
 *   3. Provider MUST be stateless (no constructor dependencies on DB/services)
 *   4. Provider MUST NOT depend on CMS or any CMS-owned contracts
 *   5. Provider SHOULD be purely declarative when possible
 *
 * @package Ikabud\Kernel\Contracts
 */
interface ApplicationProfileProvider
{
    /**
     * The profile machine identifier (e.g., "ark.workbench").
     * Must match the profile directory identifier.
     */
    public function id(): string;

    /**
     * The profile semantic version.
     */
    public function version(): string;

    /**
     * DiSyL component namespaces registered by this profile.
     *
     * @return array<string, string> namespace => directory (relative to profile root)
     *   e.g., ['workbench' => 'components/']
     */
    public function componentNamespaces(): array;

    /**
     * Layout templates provided by this profile.
     *
     * @return array<string, string> layout_id => template path (relative to profile root)
     *   e.g., ['app-shell' => 'layouts/app-shell.disyl']
     */
    public function layouts(): array;

    /**
     * Asset declarations for this profile.
     *
     * @return array{
     *   core?: array{styles?: string[], scripts?: string[]},
     *   components?: array<string, string[]>
     * }
     */
    public function assets(): array;

    /**
     * Design policy — configurable vs locked properties.
     *
     * @return array{
     *   configurable: string[],
     *   locked: string[],
     *   tone_contract?: array<string, array{allowed_families: string[], min_contrast_text?: float}>,
     *   resolution_precedence?: string[]
     * }
     */
    public function designPolicy(): array;
}