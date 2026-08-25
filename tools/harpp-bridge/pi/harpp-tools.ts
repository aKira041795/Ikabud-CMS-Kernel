/**
 * HARPP — Pi extension: exposes the HARPP bridge as native Pi tools.
 *
 * Install: copy this file to ~/.pi/agent/extensions/harpp-tools.ts (global)
 *   or .pi/extensions/harpp-tools.ts (project-local), then `/reload` in Pi.
 * Test:   pi -e ./tools/harpp-bridge/pi/harpp-tools.ts
 *
 * Each tool shells out to the `harpp` CLI (tools/harpp-bridge/harpp), which
 * reads config from ~/.config/harpp/config.json or HARPP_* env vars. Run
 * `harpp config set base_url|bridge_key|tenant_id` once first.
 *
 * Locating the CLI: resolved from ctx.cwd (walking up), or $HARPP_CLI_PATH.
 */
import type { ExtensionAPI } from "@earendil-works/pi-coding-agent";
import { Type, type Static } from "typebox";
import { execFile } from "node:child_process";
import { existsSync } from "node:fs";
import * as path from "node:path";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);

function findCli(cwd: string): string {
    const fromEnv = process.env.HARPP_CLI_PATH;
    if (fromEnv) return fromEnv;
    let dir = cwd || process.cwd();
    for (let i = 0; i < 8; i++) {
        const candidate = path.join(dir, "tools", "harpp-bridge", "harpp");
        if (existsSync(candidate)) return candidate;
        const parent = path.dirname(dir);
        if (parent === dir) break;
        dir = parent;
    }
    return path.join(cwd || process.cwd(), "tools", "harpp-bridge", "harpp");
}

async function runHarpp(
    args: string[],
    cwd: string | undefined,
): Promise<{ content: { type: "text"; text: string }[]; details: Record<string, unknown> }> {
    const cli = findCli(cwd || process.cwd());
    try {
        const { stdout, stderr } = await execFileAsync("python3", [cli, ...args], {
            timeout: 60_000,
            maxBuffer: 8 * 1024 * 1024,
            env: { ...process.env },
        });
        const text = (stdout || stderr || "").trim();
        return { content: [{ type: "text", text: text || "(empty)" }], details: { cli } };
    } catch (err: unknown) {
        const e = err as { stderr?: string; message?: string };
        const detail = e?.stderr?.trim() || e?.message || String(err);
        return {
            content: [{ type: "text", text: `harpp error (cli=${cli}): ${detail}` }],
            details: { cli, error: detail },
        };
    }
}

// Priority enum shared by decision tools.
const priority = Type.Union([Type.Literal("low"), Type.Literal("normal"), Type.Literal("high"), Type.Literal("critical")]);

export default function harppExtension(pi: ExtensionAPI): void {
    pi.registerTool({
        name: "harpp_submit_decision",
        label: "HARPP Submit Decision",
        description:
            "Raise a decision-request to the HARPP owner (creates a decision + notification + Web Push). " +
            "Use when the harness needs a human decision, e.g. BLOCKED or ARCHITECTURE_DECISION_REQUIRED.",
        parameters: Type.Object({
            title: Type.String({ description: "Short decision title" }),
            body: Type.String({ description: "Full request body" }),
            context: Type.Optional(Type.String({ description: "Optional context/situation" })),
            requested_decision: Type.Optional(Type.String({ description: "What decision is requested" })),
            priority: Type.Optional(priority),
            source: Type.Optional(Type.String()),
            workbench_state: Type.Optional(Type.String({ description: "e.g. ARCHITECTURE_DECISION_REQUIRED" })),
            decision_key: Type.Optional(Type.String({ description: "Optional idempotency key" })),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                [
                    "decision", "submit",
                    "--title", params.title,
                    "--body", params.body,
                    ...(params.context ? ["--context", params.context] : []),
                    ...(params.requested_decision ? ["--requested", params.requested_decision] : []),
                    ...(params.priority ? ["--priority", params.priority] : []),
                    ...(params.source ? ["--source", params.source] : []),
                    ...(params.workbench_state ? ["--workbench-state", params.workbench_state] : []),
                    ...(params.decision_key ? ["--decision-key", params.decision_key] : []),
                ],
                ctx?.cwd,
            );
        },
    });

    pi.registerTool({
        name: "harpp_list_decisions",
        label: "HARPP List Decisions",
        description: "List HARPP decisions (e.g. poll for the owner's decisions), filterable by state/priority.",
        parameters: Type.Object({
            state: Type.Optional(Type.String({ description: "Filter by lifecycle state, e.g. PENDING, DECIDED" })),
            priority: Type.Optional(priority),
            workbench_state: Type.Optional(Type.String()),
            limit: Type.Optional(Type.Integer({ description: "1..100, default 25" })),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                [
                    "decision", "list",
                    ...(params.state ? ["--state", params.state] : []),
                    ...(params.priority ? ["--priority", params.priority] : []),
                    ...(params.workbench_state ? ["--workbench-state", params.workbench_state] : []),
                    ...(params.limit ? ["--limit", String(params.limit)] : []),
                ],
                ctx?.cwd,
            );
        },
    });

    pi.registerTool({
        name: "harpp_acknowledge_decision",
        label: "HARPP Acknowledge Decision",
        description: "Mark a decision as acknowledged by the harness (after reading the owner's decision).",
        parameters: Type.Object({
            id: Type.Integer({ description: "Decision id" }),
            rationale: Type.Optional(Type.String()),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                ["decision", "ack", String(params.id), ...(params.rationale ? ["--rationale", params.rationale] : [])],
                ctx?.cwd,
            );
        },
    });

    pi.registerTool({
        name: "harpp_apply_decision",
        label: "HARPP Apply Decision",
        description: "Report that the harness applied the owner's decision (ACKNOWLEDGED -> APPLIED -> CLOSED).",
        parameters: Type.Object({
            id: Type.Integer({ description: "Decision id" }),
            rationale: Type.Optional(Type.String()),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                ["decision", "apply", String(params.id), ...(params.rationale ? ["--rationale", params.rationale] : [])],
                ctx?.cwd,
            );
        },
    });

    pi.registerTool({
        name: "harpp_send_message",
        label: "HARPP Send Message",
        description: "Send a message into a HARPP conversation (owner sees it in the HARPP messenger).",
        parameters: Type.Object({
            body: Type.String({ description: "Message text" }),
            conversation_id: Type.Optional(Type.Integer({ description: "Existing conversation id (optional; auto-creates)" })),
            title: Type.Optional(Type.String({ description: "Conversation title when auto-creating" })),
            harness_session_id: Type.Optional(Type.String()),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                [
                    "msg", "send",
                    "--body", params.body,
                    ...(params.conversation_id ? ["--conversation-id", String(params.conversation_id)] : []),
                    ...(params.title ? ["--title", params.title] : []),
                    ...(params.harness_session_id ? ["--harness-session-id", params.harness_session_id] : []),
                ],
                ctx?.cwd,
            );
        },
    });

    pi.registerTool({
        name: "harpp_poll_messages",
        label: "HARPP Poll Messages",
        description: "Poll for new owner messages since a cursor (message id).",
        parameters: Type.Object({
            conversation_id: Type.Optional(Type.Integer()),
            after: Type.Optional(Type.Integer({ description: "Message id cursor (default 0)" })),
            limit: Type.Optional(Type.Integer({ default: 25 })),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                [
                    "msg", "poll",
                    ...(params.conversation_id ? ["--conversation-id", String(params.conversation_id)] : []),
                    ...(params.after ? ["--after", String(params.after)] : []),
                    ...(params.limit ? ["--limit", String(params.limit)] : []),
                ],
                ctx?.cwd,
            );
        },
    });

    pi.registerTool({
        name: "harpp_post_status",
        label: "HARPP Post Status",
        description: "Post a harness status/heartbeat update (creates a notification for the owner).",
        parameters: Type.Object({
            message: Type.String({ description: "Status message" }),
            status: Type.Optional(Type.String({ description: "e.g. running, blocked, done" })),
            workbench_state: Type.Optional(Type.String()),
            harness_session_id: Type.Optional(Type.String()),
        }),
        async execute(_toolCallId, params, _signal, _onUpdate, ctx) {
            return runHarpp(
                [
                    "status",
                    "--message", params.message,
                    ...(params.status ? ["--status", params.status] : []),
                    ...(params.workbench_state ? ["--workbench-state", params.workbench_state] : []),
                    ...(params.harness_session_id ? ["--harness-session-id", params.harness_session_id] : []),
                ],
                ctx?.cwd,
            );
        },
    });
}
