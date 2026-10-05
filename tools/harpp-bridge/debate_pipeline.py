"""Pure policy helpers for the chair-led architecture debate pipeline."""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import re


VALID_CHAIR_ACTIONS = frozenset({"converge", "another_round", "abort"})
SOL_MODEL = "openai-codex/gpt-5.6-sol"


@dataclass(frozen=True)
class ChairDecision:
    """An auditable decision made by the debate chair."""

    action: str
    reason: str


def parse_chair_decision(text: str | None) -> ChairDecision | None:
    """Parse the explicit decision block; surrounding prose grants no authority."""
    if not isinstance(text, str) or not text.strip():
        return None
    marker = re.search(r"(?im)^\s*CHAIR_DECISION\s*:\s*$", text)
    if not marker:
        return None
    block = text[marker.end():]
    decision_match = re.search(r"(?im)^\s*decision\s*:\s*([^\r\n]+)\s*$", block)
    reason_match = re.search(r"(?im)^\s*reason\s*:\s*([^\r\n]+)\s*$", block)
    if not decision_match or not reason_match:
        return None
    action = decision_match.group(1).strip().lower()
    reason = reason_match.group(1).strip()
    if action not in VALID_CHAIR_ACTIONS or not reason:
        return None
    return ChairDecision(action=action, reason=reason)


def plan_is_delegatable(plan_text: str | None) -> tuple[bool, str]:
    """Require the minimum executable acceptance contract used by a lane."""
    if not isinstance(plan_text, str) or not plan_text.strip():
        return False, "plan is empty"
    if re.search(r"(?i)\b(?:TBD|TODO|TBC|FIXME)\b|<[^>\n]+>", plan_text):
        return False, "plan contains a placeholder"

    command = re.search(
        r"(?im)^\s*(?:acceptance[ _-]*command|command)\s*:\s*(\S.*)$", plan_text
    )
    if not command or not command.group(1).strip():
        return False, "plan is missing an acceptance command"

    passing = re.search(
        r"(?im)^\s*(?:pass(?:es)?[ _-]*looks?[ _-]*like|what[ _-]*pass(?:ing)?[ _-]*looks?[ _-]*like)\s*:\s*(\S.*)$",
        plan_text,
    )
    if not passing or not passing.group(1).strip():
        return False, "plan is missing what acceptance pass looks like"
    return True, "plan has an acceptance command and states what pass looks like"


def _model_chain() -> list[str]:
    chain_path = Path(__file__).resolve().parent.parent / "model-chain.txt"
    try:
        lines = chain_path.read_text(encoding="utf-8").splitlines()
    except OSError:
        return [SOL_MODEL]
    models = [line.strip() for line in lines if line.strip() and not line.lstrip().startswith("#")]
    return models or [SOL_MODEL]


def choose_implementer(availability: dict[str, bool]) -> tuple[str | None, str]:
    """Choose Sol, then the canonical model chain, always explaining the result."""
    availability = availability if isinstance(availability, dict) else {}
    ordered = [SOL_MODEL]
    ordered.extend(model for model in _model_chain() if model not in ordered)

    if availability.get(SOL_MODEL, False):
        return SOL_MODEL, "Sol is available and is the preferred implementation model"
    for model in ordered[1:]:
        if availability.get(model, False):
            return model, f"Sol is unavailable; explicitly falling back to {model} in model-chain order"
    return None, "No implementer is available: Sol and every model-chain fallback are unavailable"
